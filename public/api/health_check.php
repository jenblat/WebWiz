<?php
// /api/health_check.php — WebWiz generation health monitor.
// Run by cron every 5 min (CLI, no key). Also callable over HTTP with ?key=
// (settings.health_check_key). Scans the generation log + system state and
// emails NOTIFY_EMAIL when something is wrong (throttled). ?test=1 forces a
// sample alert email so you can confirm delivery.
declare(strict_types=1);
@set_time_limit(30);
require_once '/var/www/sites/trywebwiz/private/webwiz_lib.php';

$is_cli = (PHP_SAPI === 'cli');

// The database connection must NEVER be the thing that kills this script: the
// whole point of this monitor is to REPORT a database failure.
//
// Until 2026-07-31 this line was a bare `$db = ww_db();`. When /mnt/sites-data
// filled up during the nightly backup and SQLite could no longer write, ww_db()
// threw inside webwiz_lib.php and the checker died with a fatal before reaching
// any of its own alerting code. It computed a `db_writable` metric that it was
// structurally incapable of ever reporting as false, and it stayed silent
// through a 51-minute outage (Sentry WEBWIZ-2 / WEBWIZ-3, 06:09-07:00 UTC).
$db = null;
$db_err = '';
try {
    $db = ww_db();
} catch (Throwable $e) {
    $db_err = $e->getMessage();
}

// --- auth (HTTP only) ---
if (!$is_cli) {
    header('Content-Type: application/json');
    // The key lives in the DB, so with no DB we cannot authenticate the caller.
    // Report unhealthy without leaking any metrics to an unverified requester.
    if (!$db) {
        http_response_code(503);
        echo json_encode(['status'=>'RED','db_writable'=>false,'error'=>'database unavailable']);
        exit;
    }
    $st = $db->prepare("SELECT value FROM settings WHERE key='health_check_key'");
    $st->execute();
    $stored = (string)$st->fetchColumn();
    if ($stored === '') { $stored = bin2hex(random_bytes(12)); $db->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES ('health_check_key',?)")->execute([$stored]); }
    if (!hash_equals($stored, (string)($_GET['key'] ?? ''))) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
}

// Null-safe: with no usable connection every setting falls back to its default
// rather than throwing, so the checker can still run and still alert.
function hc_setting(?PDO $db, string $k, string $d=''): string {
    if (!$db) return $d;
    try { $s=$db->prepare("SELECT value FROM settings WHERE key=?"); $s->execute([$k]); $v=$s->fetchColumn(); return $v===false?$d:(string)$v; }
    catch (Throwable $e) { return $d; }
}

$WINDOW = 900; // 15 min
$now = time();
$m = ['gen_started'=>0,'gen_success'=>0,'hard_fail'=>0,'lock_fallback'=>0,'scrape_fallback'=>0,'nurture_fail'=>0];
$recent_fail_msgs = [];

// --- scan generation log ---
$log = '/tmp/wwmagic_debug.log';
if (is_file($log)) {
    $lines = @file($log, FILE_IGNORE_NEW_LINES) ?: [];
    $lines = array_slice($lines, -600);
    foreach ($lines as $ln) {
        if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $ln, $mm)) continue;
        $t = strtotime($mm[1] . ' UTC');
        if ($t === false || $now - $t > $WINDOW) continue;
        if (strpos($ln, 'START ip=') !== false) $m['gen_started']++;
        if (strpos($ln, 'RESPONSE SENT') !== false) $m['gen_success']++;
        if (strpos($ln, 'FAIL (caught)') !== false) { $m['hard_fail']++; if (count($recent_fail_msgs)<6) $recent_fail_msgs[] = trim(substr($ln, strpos($ln,']')+1)); }
        if (strpos($ln, 'PERSIST FALLBACK') !== false) $m['lock_fallback']++;
        if (strpos($ln, 'scrape FAILED') !== false) $m['scrape_fallback']++;
        if (strpos($ln, 'nurture upsert failed') !== false) $m['nurture_fail']++;
    }
}

// --- system checks ---
$pending = count(glob('/var/www/sites/trywebwiz/data/pending_magic/*.json') ?: []);
// The SQLite database, all site content and all backups live on the
// /mnt/sites-data block device, NOT on the root volume that /var/www resolves
// to. Until 2026-07-31 this measured '/var/www' (root, ~74% used) while the
// volume that actually fills up every night is /mnt/sites-data (~93% used), so
// the "disk free below 10%" alarm was watching the wrong filesystem entirely
// and could not have fired during the outage it was meant to catch.
// Both are measured now; the data volume is the one that matters.
$WW_DATA_MOUNT = '/mnt/sites-data';
$df = @disk_free_space($WW_DATA_MOUNT); $dt = @disk_total_space($WW_DATA_MOUNT);
$disk_free_pct = ($df && $dt) ? round(100*$df/$dt, 1) : null;
$dfr = @disk_free_space('/'); $dtr = @disk_total_space('/');
$root_free_pct = ($dfr && $dtr) ? round(100*$dfr/$dtr, 1) : null;
// Free bytes matter as much as percentage here: the nightly tarball is ~12G, so
// "8% free" on a 98G volume is already too little to complete a backup.
$disk_free_gb = $df ? round($df/1073741824, 1) : null;
$s = ww_secrets();
$has_anthropic = !empty($s['ANTHROPIC_API_KEY']);
$has_brevo     = !empty($s['BREVO_API_KEY']);
// DB write test (also records last run)
$db_ok = false;
if ($db) {
    try { $db->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES ('health_last_run',?)")->execute([gmdate('Y-m-d H:i:s')]); $db_ok = true; }
    catch (Throwable $e) { $db_ok = false; if ($db_err === '') $db_err = $e->getMessage(); }
}
// magic link master switch
$magic_enabled = hc_setting($db, 'magic_link_enabled', '1') === '1';

// --- verdict ---
$reasons = [];
if ($m['hard_fail'] >= 1)      $reasons[] = $m['hard_fail']." generation FAILURE(s) in last 15 min";
if ($m['lock_fallback'] >= 3)  $reasons[] = $m['lock_fallback']." DB-lock persist fallbacks (write contention)";
if ($pending >= 10)            $reasons[] = "pending_magic backlog = $pending (drainer/DB stuck)";
if ($disk_free_pct !== null && $disk_free_pct < 10) $reasons[] = "data volume {$WW_DATA_MOUNT} free {$disk_free_pct}% ({$disk_free_gb}G)";
// The nightly site tarball is ~12G. Less than 15G free means tonight's backup
// cannot complete, which is exactly how the DB gets taken down.
if ($disk_free_gb !== null && $disk_free_gb < 15) $reasons[] = "data volume only {$disk_free_gb}G free, nightly backup needs ~12G";
if ($root_free_pct !== null && $root_free_pct < 10) $reasons[] = "root volume free {$root_free_pct}%";
if (!$has_anthropic)           $reasons[] = "ANTHROPIC_API_KEY missing";
if (!$has_brevo)               $reasons[] = "BREVO_API_KEY missing";
if (!$db)                      $reasons[] = "DATABASE UNAVAILABLE: " . substr($db_err, 0, 200);
elseif (!$db_ok)               $reasons[] = "DB write test FAILED" . ($db_err !== '' ? ': ' . substr($db_err, 0, 200) : '');
if (!$magic_enabled)           $reasons[] = "magic_link_enabled is OFF (generation disabled)";

$status = $reasons ? 'RED' : 'OK';
$force  = (($_GET['test'] ?? '') === '1' || ($_GET['force'] ?? '') === '1');

// --- alert (throttled) ---
$alerted = false;
$THROTTLE = 1800; // 30 min
// Throttle state normally lives in the DB, but during a DB outage that store is
// precisely what is unavailable. Without a fallback the checker would email
// every 5 minutes for the entire outage. Mirror the timestamp to a file and
// take whichever source is newer.
$THROTTLE_FILE = '/tmp/ww_health_alert_ts';
$last_alert = max(
    (int)hc_setting($db, 'health_alert_ts', '0'),
    (int)@file_get_contents($THROTTLE_FILE)
);
if (($status === 'RED' || $force) && ($now - $last_alert > $THROTTLE || $force) && function_exists('ww_send_email')) {
    $to = !empty($s['NOTIFY_EMAIL']) ? $s['NOTIFY_EMAIL'] : 'ultimax97@gmail.com';
    $rows = '';
    foreach ([['Generations started',$m['gen_started']],['Succeeded',$m['gen_success']],['HARD FAILURES',$m['hard_fail']],['DB-lock fallbacks',$m['lock_fallback']],['Scrape fallbacks (recovered)',$m['scrape_fallback']],['Nurture enroll fails',$m['nurture_fail']],['Pending backlog',$pending],['Disk free %',$disk_free_pct],['DB writable',$db_ok?'yes':'NO'],['Anthropic key',$has_anthropic?'ok':'MISSING'],['Brevo key',$has_brevo?'ok':'MISSING'],['Generation enabled',$magic_enabled?'yes':'NO']] as $r) {
        $rows .= '<tr><td style="padding:3px 12px 3px 0">'.$r[0].'</td><td style="padding:3px 0"><b>'.htmlspecialchars((string)$r[1]).'</b></td></tr>';
    }
    $fl = $recent_fail_msgs ? ('<p><b>Recent errors:</b></p><ul>'.implode('', array_map(fn($x)=>'<li style="font-family:monospace;font-size:12px">'.htmlspecialchars($x).'</li>', $recent_fail_msgs)).'</ul>') : '';
    $subj = $force ? 'WebWiz health check — TEST alert' : ('WebWiz health '.$status.': '.implode('; ', $reasons));
    $html = '<h2 style="color:'.($status==='RED'?'#b00':'#0a0').'">WebWiz health: '.$status.'</h2>'
          . ($reasons ? '<p><b>Why:</b> '.htmlspecialchars(implode('; ', $reasons)).'</p>' : '<p>This is a test alert — the alerting pipeline works.</p>')
          . '<table style="border-collapse:collapse;font-size:14px">'.$rows.'</table>'
          . $fl
          . '<p style="color:#666;font-size:12px;margin-top:16px">Window: last 15 min · '.gmdate('Y-m-d H:i:s').' UTC · alerts throttled to 1 per 30 min.</p>';
    $sent = ww_send_email(['email'=>$to,'name'=>'WebWiz Ops'], $subj, $html);
    if ($sent) {
        @file_put_contents($THROTTLE_FILE, (string)$now);
        if ($db) { try { $db->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES ('health_alert_ts',?)")->execute([(string)$now]); } catch (Throwable $e) {} }
        $alerted = true;
    }
}

$out = ['status'=>$status,'reasons'=>$reasons,'window_min'=>15,'metrics'=>$m,'pending_backlog'=>$pending,'disk_mount'=>$WW_DATA_MOUNT,'disk_free_pct'=>$disk_free_pct,'disk_free_gb'=>$disk_free_gb,'root_free_pct'=>$root_free_pct,'db_writable'=>$db_ok,'db_error'=>($db_err !== '' ? substr($db_err,0,200) : null),'anthropic_key'=>$has_anthropic,'brevo_key'=>$has_brevo,'generation_enabled'=>$magic_enabled,'alert_sent'=>$alerted,'checked_at'=>gmdate('Y-m-d H:i:s').' UTC'];
if ($is_cli) { echo json_encode($out)."\n"; } else { echo json_encode($out, JSON_PRETTY_PRINT); }
