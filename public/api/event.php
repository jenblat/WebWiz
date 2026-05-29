<?php
// /api/event.php — funnel analytics ingest for the /try ad-funnel.
// Accepts navigator.sendBeacon / fetch keepalive POSTs with
// { event, token?, payload? }. Whitelist-guarded; never breaks the UI.
declare(strict_types=1);
header('Content-Type: application/json');
// Always 200 — analytics failures must not affect the user flow.
register_shutdown_function(function(){ if (!headers_sent()) http_response_code(200); });

require_once '/var/www/sites/trywebwiz/private/webwiz_lib.php';

const ALLOWED_EVENTS = [
    'hero_view',
    'form_submit',
    'gen_started',
    'gen_completed',
    'gen_failed',
    'reveal_viewed',
    'edit_used',
    'asset_upload_opened',
    'asset_upload_completed',
    'edit_cap_hit',
    'make_it_real_clicked',
    'checkout_started',
    // 'checkout_completed' fires server-side from webhook.php
];

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) { echo json_encode(['ok' => false]); exit; }

$event = trim((string)($body['event'] ?? ''));
if ($event === '' || !in_array($event, ALLOWED_EVENTS, true)) { echo json_encode(['ok' => false, 'reason' => 'event']); exit; }

$token   = trim((string)($body['token']      ?? ''));
$session = trim((string)($body['session_id'] ?? ''));
if (!preg_match('~^[a-f0-9]{0,24}$~', $token))   $token   = '';
if (!preg_match('~^[A-Za-z0-9_-]{0,80}$~', $session)) $session = '';

$payload = $body['payload'] ?? null;
if ($payload !== null && !is_string($payload)) $payload = json_encode($payload);
if (is_string($payload) && strlen($payload) > 2000) $payload = substr($payload, 0, 2000);

$ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
$ip = trim(explode(',', $ip)[0]);
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 240);

try {
    $st = ww_db()->prepare("INSERT INTO try_events (event, token, session_id, payload, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
    $st->execute([$event, $token ?: null, $session ?: null, $payload, $ip ?: null, $ua ?: null]);
} catch (Throwable $e) {
    error_log('[try-event] insert failed: ' . $e->getMessage());
}
echo json_encode(['ok' => true]);
