<?php
// Public "magic link" real-time generator.
// Accepts GET (existing cold-email links) OR POST (new /try ad-funnel page).
// Inputs: website (required), name (optional), email (optional), description (optional —
//         steers the prompt for the ad-funnel flow), v / variants (1-3, default from settings).
// Scrapes the site, generates v variants synchronously, writes preview files,
// returns JSON { ok, token, url, business }.
declare(strict_types=1);
// Wall-clock cap so a stuck Anthropic call / cURL hang can't zombie an lsphp
// worker and hold the SQLite writer lock for minutes (which is what happened
// to Omar — a 16-minute stuck request blocked every subsequent gen).
@set_time_limit(180);
ignore_user_abort(false);
header('Content-Type: application/json');

require_once '/var/www/sites/trywebwiz/private/worker.php'; // provides generation functions (queue loop is CLI-only)

// ---- Merge POST body into $_GET so all existing $_GET reads work for both transports.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw !== '') {
        $d = json_decode($raw, true);
        if (is_array($d)) {
            foreach ($d as $k => $v) { if (!isset($_GET[$k])) $_GET[$k] = $v; }
        }
    }
    foreach ($_POST as $k => $v) { if (!isset($_GET[$k])) $_GET[$k] = $v; }
}

$db = ww_db();
function ml_sget(PDO $db, string $k, string $d = ''): string { $s = $db->prepare("SELECT value FROM settings WHERE key=?"); $s->execute([$k]); $r = $s->fetchColumn(); return $r === false ? $d : (string)$r; }
function ml_fail(string $m, int $code = 400) { http_response_code($code); echo json_encode(['error' => $m]); exit; }

if (ml_sget($db, 'magic_link_enabled', '1') !== '1') ml_fail('Instant preview is currently turned off.', 403);

$website     = trim((string)($_GET['website'] ?? $_GET['url'] ?? ''));
$name        = trim((string)($_GET['name'] ?? ''));
$email       = trim((string)($_GET['email'] ?? ''));
$description = trim((string)($_GET['description'] ?? '')); // NEW — ad-funnel form notes
$v           = (int)($_GET['v'] ?? $_GET['variants'] ?? ml_sget($db, 'magic_default_variants', '1'));
$v = max(1, min(3, $v ?: 1));

if ($website === '') ml_fail('Add your website so Wizzy has something to start from.');
if (!preg_match('~^https?://~i', $website)) $website = 'https://' . $website;
if (!filter_var($website, FILTER_VALIDATE_URL)) ml_fail('That website URL looks invalid.');

// magic_hits table (rate-limit log)
$db->exec("CREATE TABLE IF NOT EXISTS magic_hits (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT, token TEXT, created_at TEXT DEFAULT (datetime('now')))");

// ---- rate limiting (per-IP/hour + daily global cap) ----
$ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
$ip = trim(explode(',', $ip)[0]);

// ---- Per-IP in-flight gate ----
// Without this, a user clicking 'Try again' while their first request is
// still cooking will stack N parallel Sonnet calls. All N then race to
// UPDATE api_keys.last_used_at + INSERT INTO api_calls, exhausting the
// SQLite writer lock and surfacing the 'database is busy' error.
$ip_lock_path = '/tmp/wwmagic_' . substr(sha1($ip), 0, 16) . '.lock';
$ip_lock_fp = @fopen($ip_lock_path, 'c');
if (!$ip_lock_fp || !flock($ip_lock_fp, LOCK_EX | LOCK_NB)) {
    // Already in flight. Honor it if it's recent (<180s); else assume the
    // prior request crashed and steal the lock.
    $age = is_file($ip_lock_path) ? (time() - filemtime($ip_lock_path)) : 999;
    if ($age >= 0 && $age < 180) {
        ml_fail('Wizzy is still working on your last request. Hang tight — refreshing the page will not help.', 429);
    }
    if ($ip_lock_fp) { @fclose($ip_lock_fp); }
    @unlink($ip_lock_path);
    $ip_lock_fp = @fopen($ip_lock_path, 'c');
    if ($ip_lock_fp) { flock($ip_lock_fp, LOCK_EX | LOCK_NB); }
}
// Release the lock when the request ends, whether by success, exception, or
// user abort.
register_shutdown_function(function () use (&$ip_lock_fp, $ip_lock_path) {
    if ($ip_lock_fp) { @flock($ip_lock_fp, LOCK_UN); @fclose($ip_lock_fp); }
    @unlink($ip_lock_path);
});

$perIp = (int)ml_sget($db, 'magic_rl_per_ip_hour', '3');
$daily = (int)ml_sget($db, 'magic_rl_daily_cap', '100');
if ($perIp > 0) { $c = $db->prepare("SELECT COUNT(*) FROM magic_hits WHERE ip=? AND created_at > datetime('now','-1 hour')"); $c->execute([$ip]); if ((int)$c->fetchColumn() >= $perIp) ml_fail('You have reached the limit for now. Please try again a bit later.', 429); }
if ($daily > 0) { if ((int)$db->query("SELECT COUNT(*) FROM magic_hits WHERE created_at > datetime('now','start of day')")->fetchColumn() >= $daily) ml_fail('We have hit today\'s capacity for instant previews. Please try again tomorrow.', 429); }

try {
    $scrape = scrape_multi($website);
    $biz = trim((string)($scrape['business_name'] ?? ''));
    if ($biz === '') { $biz = trim((string)($scrape['h1'][0] ?? '')); }
    if ($biz === '') { $t = trim((string)($scrape['title'] ?? '')); if ($t !== '') $biz = trim((string)preg_split('~[|\-\x{2013}\x{2014}:]~u', $t)[0]); }
    if ($biz === '') { $biz = preg_replace('~^www\.~', '', (string)(parse_url($website, PHP_URL_HOST) ?: 'Your Business')); }
    $industry = '';
    $usable = array_values(array_filter($scrape['images'] ?? [], fn($i) => empty($i['is_logo']) && empty($i['is_thumb']) && empty($i['is_team_card'])));
    $system = build_system_prompt($industry, count($usable));

    // If the user typed a description in the /try ad-funnel form, weave it into
    // each variant's user prompt as authoritative business context. We append it
    // as an extra block on the prompt so it lands in the message body without
    // disturbing the existing build_user_prompt() format.
    $desc_block = '';
    if ($description !== '') {
        $clean = preg_replace('/[\x00-\x1F]+/', ' ', $description);
        $clean = mb_substr($clean, 0, 1500); // cap to avoid prompt bloat
        $desc_block = "\n\n<business_notes_from_owner>\n" . $clean . "\n</business_notes_from_owner>\n"
                    . "Treat the notes above as the most authoritative description of the business. "
                    . "If the scraped content disagrees, prefer what the owner wrote. Reflect the tone "
                    . "and specifics (years in business, neighborhoods, signature offerings) in the hero and copy.";
    }

    $reqs = [];
    for ($i = 1; $i <= $v; $i++) {
        $user_content = build_user_prompt($scrape, $biz, $industry, $i) . $desc_block;
        $reqs[$i] = ['system' => $system, 'messages' => [['role' => 'user', 'content' => $user_content]]];
    }
    // Sonnet only — Haiku output looked too rough in live testing (broken hero
    // images, gray placeholders even when source images existed). The cost is
    // longer wall time (~45-90s for the model call) but quality is what the
    // ad-funnel needs. Front-end progress bar is tuned for that window.
    $res = anthropic_multi('claude-sonnet-4-6', $reqs, 14000, 0.7, null, ['</html>']);
    $htmls = []; $cost = 0.0;
    foreach ($reqs as $i => $_) { $cost += (float)($res[$i]['cost_usd'] ?? 0); $cand = finalize_html($res[$i]['text'] ?? ''); if ($cand && quality_gate($cand)['ok']) $htmls[$i] = $cand; }
    if (!$htmls) throw new Exception('generation produced no usable site');
    ksort($htmls);

    // 1) write preview files first (filesystem, no DB lock)
    $token = bin2hex(random_bytes(12));
    $dir = '/var/www/sites/trywebwiz/public/preview/' . $token;
    foreach ($htmls as $i => $html) { $html = ww_apply_upscale($html, null); $htmls[$i] = $html; $d = $dir . '/v' . $i; if (!is_dir($d)) @mkdir($d, 0755, true); file_put_contents($d . '/index.html', $html); }
    if (!is_file($dir . '/index.php')) file_put_contents($dir . '/index.php', "<?php\n\$_GET['t'] = basename(__DIR__);\nrequire __DIR__ . '/../index.php';\n");

    // 2) persist to DB inside one transaction, retrying if SQLite is briefly locked by the cron worker
    // Per-row retry helper. Each INSERT is its own atomic WAL write — no explicit
    // transaction, so the writer lock is held for ~10ms per row instead of 1-2s
    // for the whole batch. Eliminates the "database is locked" errors we saw
    // when concurrent magic-link gens piled up on a single big transaction.
    $exec_retry = function (string $sql, array $params) use ($db): void {
        // Re-prepare per attempt — once a PDOStatement.execute() throws, the
        // handle can land in a state where the next execute() raises SQLite
        // MISUSE ("21 bad parameter or other API misuse"). Preparing fresh
        // each iteration is cheap (~microseconds) and avoids that edge case.
        $delay_us = 100000;
        for ($try = 0; $try < 40; $try++) {
            try { $st = $db->prepare($sql); $st->execute($params); return; }
            catch (Throwable $e) {
                $msg = strtolower($e->getMessage());
                if (strpos($msg, 'lock') === false && strpos($msg, 'busy') === false) throw $e;
                usleep($delay_us);
                $delay_us = min(800000, (int)($delay_us * 1.2));
            }
        }
        throw new Exception('Our database is busy right now. Please try again in a few seconds.');
    };

    $persist = function () use ($db, $email, $name, $biz, $website, $cost, $htmls, $token, $ip, $exec_retry) {
        $exec_retry("INSERT INTO prospects (email, name, business_name, current_url, source) VALUES (?, ?, ?, ?, 'magic')", [$email, $name, $biz, $website]);
        $pid = (int)$db->lastInsertId();
        $exec_retry("INSERT INTO jobs (type, prospect_id, customer_email, business_name, status, scheduled_for, token, generation_mode, item_status, total_cost_cents, completed_at, qa_status) VALUES ('outbound', ?, ?, ?, 'ready', datetime('now'), ?, 'magic', 'done', ?, datetime('now'), 'magic')",
            [$pid, $email, $biz, $token, (int)round($cost * 100)]);
        $jid = (int)$db->lastInsertId();
        foreach ($htmls as $i => $html) {
            $exec_retry("INSERT INTO previews (job_id, variant_n, html_path, qa_score, qa_pass, qa_issues) VALUES (?, ?, ?, NULL, NULL, NULL)",
                [$jid, $i, '/preview/' . $token . '/v' . $i . '/index.html']);
        }
        $exec_retry("INSERT INTO magic_hits (ip, token) VALUES (?, ?)", [$ip, $token]);
    };
    // Row-level retries live inside $persist(); a thrown error here is already
    // user-friendly via the helper above.
    $persist();

    echo json_encode(['ok' => true, 'token' => $token, 'url' => '/preview/' . $token . '/', 'preview_url' => '/preview/' . $token . '/v1/index.html', 'variants' => count($htmls), 'business' => $biz, 'business_name' => $biz]);
    exit;
} catch (Throwable $e) {
    ml_fail('Generation failed: ' . preg_replace('/[\x00-\x1F]+/', ' ', $e->getMessage()), 500);
}
