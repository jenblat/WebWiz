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
$db = ww_db();

// --- auth (HTTP only) ---
if (!$is_cli) {
    header('Content-Type: application/json');
    $st = $db->prepare("SELECT value FROM settings WHERE key='health_check_key'");
    $st->execute();
    $stored = (string)$st->fetchColumn();
    if ($stored === '') { $stored = bin2hex(random_bytes(12)); $db->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES ('health_check_key',?)")->execute([$stored]); }
    if (!hash_equals($stored, (string)($_GET['key'] ?? ''))) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
}

function hc_setting(PDO $db, string $k, string $d=''): string { $s=$db->prepare("SELECT value FROM settings WHERE key=?"); $s->execute([$k]); $v=$s->fetchColumn(); return $v===false?$d:(string)$v; }

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
$df = @disk_free_space('/var/www'); $dt = @disk_total_space('/var/www');
$disk_free_pct = ($df && $dt) ? round(100*$df/$dt, 1) : null;
$s = ww_secrets();
$has_anthropic = !empty($s['ANTHROPIC_API_KEY']);
$has_brevo     = !empty($s['BREVO_API_KEY']);
// DB write test (also records last run)
$db_ok = true;
try { $db->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES ('health_last_run',?)")->execute([gmdate('Y-m-d H:i:s')]); }
catch (Throwable $e) { $db_ok = false; }
// magic link master switch
$magic_enabled = hc_setting($db, 'magic_link_enabled', '1') === '1';

// --- verdict ---
$reasons = [];
if ($m['hard_fail'] >= 1)      $reasons[] = $m['hard_fail']." generation FAILURE(s) in last 15 min";
if ($m['lock_fallback'] >= 3)  $reasons[] = $m['lock_fallback']." DB-lock persist fallbacks (write contention)";
if ($pending >= 10)            $reasons[] = "pending_magic backlog = $pending (drainer/DB stuck)";
if ($disk_free_pct !== null && $disk_free_pct < 10) $reasons[] = "disk free {$disk_free_pct}%";
if (!$has_anthropic)           $reasons[] = "ANTHROPIC_API_KEY missing";
if (!$has_brevo)               $reasons[] = "BREVO_API_KEY missing";
if (!$db_ok)                   $reasons[] = "DB write test FAILED";
if (!$magic_enabled)           $reasons[] = "magic_link_enabled is OFF (generation disabled)";

$status = $reasons ? 'RED' : 'OK';
$force  = (($_GET['test'] ?? '') === '1' || ($_GET['force'] ?? '') === '1');

// --- alert (throttled) ---
$alerted = false;
$THROTTLE = 1800; // 30 min
$last_alert = (int)hc_setting($db, 'health_alert_ts', '0');
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
    if ($sent) { $db->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES ('health_alert_ts',?)")->execute([(string)$now]); $alerted = true; }
}

$out = ['status'=>$status,'reasons'=>$reasons,'window_min'=>15,'metrics'=>$m,'pending_backlog'=>$pending,'disk_free_pct'=>$disk_free_pct,'db_writable'=>$db_ok,'anthropic_key'=>$has_anthropic,'brevo_key'=>$has_brevo,'generation_enabled'=>$magic_enabled,'alert_sent'=>$alerted,'checked_at'=>gmdate('Y-m-d H:i:s').' UTC'];
if ($is_cli) { echo json_encode($out)."\n"; } else { echo json_encode($out, JSON_PRETTY_PRINT); }
