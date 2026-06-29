<?php
// /api/cron_nurture.php — Hourly nurture sender. Idempotent, safe to over-run.
// Hit via cron OR via CLI. Examples:
//   curl https://trywebwiz.com/api/cron_nurture.php?key=<NURTURE_CRON_KEY>
//   php /var/www/sites/trywebwiz/public/api/cron_nurture.php
//
// Auth: in HTTP mode, requires ?key=<NURTURE_CRON_KEY from settings>. CLI is open
// (only reachable on-box). Without a key in settings on first run, a random one
// is generated and stored; check settings.nurture_cron_key to retrieve.
declare(strict_types=1);
@set_time_limit(120);
ignore_user_abort(true);

require_once __DIR__ . '/_nurture.php';
require_once __DIR__ . '/../../private/webwiz_lib.php';

$is_cli = (PHP_SAPI === 'cli');
$db = ww_db();
ww_nurture_init_schema($db);

// Auth (HTTP only)
if (!$is_cli) {
    header('Content-Type: text/plain; charset=utf-8');
    $st = $db->prepare("SELECT value FROM settings WHERE key='nurture_cron_key'");
    $st->execute();
    $stored = (string)$st->fetchColumn();
    if ($stored === '') {
        $stored = bin2hex(random_bytes(16));
        $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('nurture_cron_key', ?)")->execute([$stored]);
    }
    $supplied = (string)($_GET['key'] ?? '');
    if (!hash_equals($stored, $supplied)) {
        http_response_code(403);
        echo "forbidden — call with ?key=<settings.nurture_cron_key>\n";
        exit;
    }
}

// Load secrets
$secrets_path = '/var/www/sites/trywebwiz/secrets.php';
$secrets = is_file($secrets_path) ? (require $secrets_path) : [];
$brevo_key = (string)($secrets['BREVO_API_KEY'] ?? '');
if ($brevo_key === '') {
    echo "ERROR: BREVO_API_KEY missing from secrets.php\n";
    exit(1);
}

// CAN-SPAM mailing address is required to send. Set via settings.nurture_mailing_address.
$mailing = ww_nurture_mailing_address($db);
if ($mailing === '') {
    echo "ERROR: settings.nurture_mailing_address not configured. Run:\n"
       . "  INSERT OR REPLACE INTO settings (key, value) VALUES ('nurture_mailing_address', '<address>');\n"
       . "Will NOT send until set (CAN-SPAM).\n";
    exit(1);
}

$hmac_secret = ww_nurture_hmac_secret($db);
$max_per_run = (int)($_GET['limit'] ?? 50);
if ($max_per_run < 1 || $max_per_run > 500) $max_per_run = 50;

$due = ww_nurture_due_contacts($db, $max_per_run);
$started = microtime(true);

$sent = 0; $failed = 0;
foreach ($due as $c) {
    // Re-check status under serialized read so admin pause/unsubscribe between
    // SELECT and SEND wins.
    $check = $db->prepare("SELECT status, pause_until FROM nurture_contacts WHERE id = ? LIMIT 1");
    $check->execute([(int)$c['id']]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['status'] !== 'active') continue;
    if (!empty($row['pause_until']) && $row['pause_until'] > gmdate('Y-m-d H:i:s')) continue;

    $r = ww_nurture_send_one($db, $c, $brevo_key, $hmac_secret, $mailing);
    if ($r['ok']) { $sent++; }
    else {
        $failed++;
        @file_put_contents('/tmp/nurture.log',
            gmdate('c') . " FAIL cid={$c['id']} step=" . ((int)$c['current_step']+1) . " err=" . substr((string)$r['error'], 0, 200) . "\n",
            FILE_APPEND);
    }
    // Light backoff to avoid hammering Brevo.
    usleep(150000);
}

$elapsed = round(microtime(true) - $started, 2);
@file_put_contents('/tmp/nurture.log',
    gmdate('c') . " run due=" . count($due) . " sent=$sent failed=$failed elapsed={$elapsed}s\n",
    FILE_APPEND);
echo "Nurture run: due=" . count($due) . " sent=$sent failed=$failed elapsed={$elapsed}s\n";
