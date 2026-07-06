<?php
// /api/drain_pending.php — recover generations whose DB persist lost the write
// lock and fell back to /data/pending_magic/*.json. Retries with backoff (the
// live persist gives up after its window; this is the safety net). Inserts the
// job/prospect/previews + enrolls nurture, mirroring magic.php's drainer.
// Run by cron every few minutes (CLI). HTTP needs ?key=<settings.health_check_key>.
declare(strict_types=1);
@set_time_limit(60);
require_once '/var/www/sites/trywebwiz/private/webwiz_lib.php';
require_once '/var/www/sites/trywebwiz/public/api/_nurture.php';

$is_cli = (PHP_SAPI === 'cli');
$db = ww_db();
if (!$is_cli) {
    header('Content-Type: application/json');
    $k = (string)$db->query("SELECT value FROM settings WHERE key='health_check_key'")->fetchColumn();
    if ($k === '' || !hash_equals($k, (string)($_GET['key'] ?? ''))) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
}

$dir = '/var/www/sites/trywebwiz/data/pending_magic';
$files = glob($dir . '/*.json') ?: [];
$done = []; $failed = [];

foreach ($files as $f) {
    $p = json_decode((string)@file_get_contents($f), true);
    if (!is_array($p) || empty($p['token'])) { @unlink($f); continue; }
    $tok = (string)$p['token'];
    // Already in DB? just remove the stale file.
    $ex = $db->prepare("SELECT id FROM jobs WHERE token = ?"); $ex->execute([$tok]);
    if ($ex->fetchColumn()) { @unlink($f); $done[] = "$tok (already in db)"; continue; }

    $ok = false; $delay = 200000;
    for ($attempt = 1; $attempt <= 10 && !$ok; $attempt++) {
        try {
            $db->exec('PRAGMA busy_timeout = 15000');
            $db->exec('BEGIN IMMEDIATE');
            $st = $db->prepare("INSERT INTO prospects (email, name, business_name, current_url, source, description) VALUES (?, ?, ?, ?, 'magic', ?)");
            $st->execute([$p['email'] ?? '', $p['name'] ?? '', $p['biz'] ?? '', (!empty($p['describe']) ? null : ($p['website'] ?? '')), (!empty($p['describe']) ? ($p['description'] ?? '') : null)]);
            $pid = (int)$db->lastInsertId();
            $st = $db->prepare("INSERT INTO jobs (type, prospect_id, customer_email, business_name, scrape_data, status, scheduled_for, token, generation_mode, item_status, total_cost_cents, completed_at, qa_status) VALUES ('outbound', ?, ?, ?, ?, 'ready', datetime('now'), ?, ?, 'done', ?, datetime('now'), 'magic')");
            $st->execute([$pid, $p['email'] ?? '', $p['biz'] ?? '', ($p['scrape_data'] ?? null), $tok, ($p['generation_mode'] ?? 'magic'), (int)round(((float)($p['cost'] ?? 0)) * 100)]);
            $jid = (int)$db->lastInsertId();
            $st = $db->prepare("INSERT INTO previews (job_id, variant_n, html_path, qa_score, qa_pass, qa_issues) VALUES (?, ?, ?, NULL, NULL, NULL)");
            foreach (($p['variants'] ?? [1]) as $vn) { $st->execute([$jid, (int)$vn, '/preview/' . $tok . '/v' . (int)$vn . '/index.html']); }
            $db->prepare("INSERT INTO magic_hits (ip, token) VALUES (?, ?)")->execute([$p['ip'] ?? '', $tok]);
            $db->exec('COMMIT');
            @unlink($f);
            $ok = true;
            // enroll nurture (email required)
            if (!empty($p['email']) && filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
                try {
                    ww_nurture_upsert_contact($db, [
                        'name' => $p['name'] ?? '', 'email' => $p['email'], 'company' => $p['biz'] ?? '',
                        'website' => $p['website'] ?? '', 'token' => $tok,
                        'preview_url' => 'https://trywebwiz.com/try/?t=' . $tok, 'source' => 'try',
                    ]);
                } catch (Throwable $ne) {}
            }
            $done[] = "$tok ({$p['biz']}) email={$p['email']} jid=$jid";
        } catch (Throwable $e) {
            try { $db->exec('ROLLBACK'); } catch (Throwable $ee) {}
            $em = strtolower($e->getMessage());
            if (strpos($em, 'lock') === false && strpos($em, 'busy') === false) { $failed[] = "$tok: " . $e->getMessage(); break; }
            usleep($delay); $delay = (int)min(3000000, $delay * 1.5);
        }
    }
    if (!$ok && empty($failed)) $failed[] = "$tok: still locked after retries";
}
echo json_encode(['drained' => $done, 'failed' => $failed, 'at' => gmdate('Y-m-d H:i:s') . ' UTC'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . "\n";
