<?php
// /api/track.php — Open pixel + click-redirect for nurture emails.
//   ?e=<send_id>&t=o&s=<hmac>           -> log open, return 1x1 GIF
//   ?e=<send_id>&t=c&s=<hmac>&u=<url>   -> log click, 302 to url
declare(strict_types=1);

require_once __DIR__ . '/_nurture.php';
require_once __DIR__ . '/../../private/webwiz_lib.php';

$db = ww_db();
ww_nurture_init_schema($db);

$send_id = (int)($_GET['e'] ?? 0);
$type    = (string)($_GET['t'] ?? '');
$sig     = trim((string)($_GET['s'] ?? ''));
$secret  = ww_nurture_hmac_secret($db);

function gif_pixel(): void {
    header('Content-Type: image/gif');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Content-Length: 43');
    // 1x1 transparent GIF
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

// Validate
if ($send_id <= 0 || !in_array($type, ['o', 'c'], true) || !ww_nurture_verify_track_sig($send_id, $type, $sig, $secret)) {
    if ($type === 'c') {
        $u = (string)($_GET['u'] ?? '');
        if (preg_match('~^https?://~i', $u)) { header('Location: ' . $u, true, 302); exit; }
        header('Location: https://trywebwiz.com/', true, 302); exit;
    }
    gif_pixel();
}

// Look up the send + contact
$row = $db->prepare("SELECT contact_id FROM nurture_sends WHERE id = ? LIMIT 1");
$row->execute([$send_id]);
$contact_id = (int)$row->fetchColumn();
if (!$contact_id) {
    if ($type === 'c') {
        $u = (string)($_GET['u'] ?? '');
        if (preg_match('~^https?://~i', $u)) { header('Location: ' . $u, true, 302); exit; }
    }
    gif_pixel();
}

$ip = trim(explode(',', (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);

// Many email clients prefetch images (Gmail image proxy, Apple Mail Privacy
// Protection, security scanners). We still log the event but dedupe per (send,
// ip) within a short window so the engagement counts aren't wildly inflated.
$now = gmdate('Y-m-d H:i:s');

if ($type === 'o') {
    // Have we already logged an open from this IP for this send in the last 5 min?
    $dup = $db->prepare("SELECT COUNT(*) FROM nurture_events
                          WHERE send_id = ? AND type = 'open' AND ip = ?
                            AND occurred_at > datetime('now', '-5 minutes')");
    $dup->execute([$send_id, $ip]);
    if ((int)$dup->fetchColumn() === 0) {
        $db->prepare("INSERT INTO nurture_events (contact_id, send_id, type, ip, user_agent, occurred_at) VALUES (?, ?, 'open', ?, ?, ?)")
           ->execute([$contact_id, $send_id, $ip, $ua, $now]);
        $db->prepare("UPDATE nurture_sends
                         SET open_count = open_count + 1,
                             opened_at = ?,
                             first_opened_at = COALESCE(first_opened_at, ?)
                       WHERE id = ?")
           ->execute([$now, $now, $send_id]);
    }
    gif_pixel();
}

// type == 'c' — click redirect
$target = (string)($_GET['u'] ?? '');
if (!preg_match('~^https?://~i', $target)) {
    $target = 'https://trywebwiz.com/';
}
$db->prepare("INSERT INTO nurture_events (contact_id, send_id, type, target, ip, user_agent, occurred_at) VALUES (?, ?, 'click', ?, ?, ?, ?)")
   ->execute([$contact_id, $send_id, $target, $ip, $ua, $now]);
$db->prepare("UPDATE nurture_sends SET click_count = click_count + 1, last_clicked_at = ? WHERE id = ?")
   ->execute([$now, $send_id]);

header('Location: ' . $target, true, 302);
exit;
