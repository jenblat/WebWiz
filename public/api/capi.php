<?php
// /api/capi.php — Client-side event mirror. Receives a JSON POST from
// window.wwMetaTrack() and forwards to the Meta CAPI with the SAME event_id
// the Pixel used, so Meta dedupes the pair.

declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/_meta.php';

$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in) || empty($in['event_name']) || empty($in['event_id'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'event_name and event_id required']);
    exit;
}

$allowed = ['ViewContent', 'Lead', 'InitiateCheckout', 'Purchase', 'CompleteRegistration', 'Subscribe', 'AddToCart'];
if (!in_array($in['event_name'], $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unsupported event_name']);
    exit;
}

$cookies = ww_meta_cookies();

$user_data = [
    'email'             => $in['email']      ?? null,
    'phone'             => $in['phone']      ?? null,
    'first_name'        => $in['first_name'] ?? null,
    'last_name'         => $in['last_name']  ?? null,
    'fbp'               => $cookies['fbp'],
    'fbc'               => $cookies['fbc'],
    'client_ip_address' => ww_meta_client_ip(),
    'client_user_agent' => ww_meta_user_agent(),
];

$custom = [];
foreach (['value', 'currency', 'content_name', 'content_ids', 'content_type', 'content_category', 'num_items'] as $k) {
    if (isset($in[$k])) $custom[$k] = $in[$k];
}
if (isset($custom['value']))    $custom['value']    = (float)$custom['value'];
if (!isset($custom['currency']) && isset($custom['value'])) $custom['currency'] = 'USD';

$ok = ww_meta_send_event(
    (string)$in['event_name'],
    (string)$in['event_id'],
    $user_data,
    $custom,
    (string)($in['event_source_url'] ?? ($_SERVER['HTTP_REFERER'] ?? '')),
    'website'
);

echo json_encode(['ok' => $ok]);
