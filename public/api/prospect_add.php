<?php
// /var/www/sites/trywebwiz/public/api/prospect_add.php
// Manually add a single prospect from admin UI and queue a generation job (runs immediately).

declare(strict_types=1);
require '/var/www/sites/trywebwiz/private/webwiz_lib.php';

require_once '/var/www/sites/trywebwiz/public/api/_session.php'; ww_session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'POST only']));
}

if (empty($_SESSION['uid'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'not authenticated']));
}
$me = ww_user_by_id((int)$_SESSION['uid']);
if (!$me) {
    http_response_code(401);
    exit(json_encode(['error' => 'bad session']));
}

$raw  = file_get_contents('php://input') ?: '{}';
$data = json_decode($raw, true) ?: [];

$biz     = trim((string)($data['business_name'] ?? ''));
$contact = trim((string)($data['contact_name']  ?? ''));
$email   = strtolower(trim((string)($data['email'] ?? '')));
$url     = trim((string)($data['current_url'] ?? ''));
$phone   = trim((string)($data['phone'] ?? ''));
$address = trim((string)($data['address'] ?? ''));
$industry= trim((string)($data['industry'] ?? ''));
$place_id= trim((string)($data['place_id'] ?? ''));

// Validation
if (!$biz)  exit(json_encode(['error' => 'business_name required']));
if (!$url)  exit(json_encode(['error' => 'current_url required']));
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit(json_encode(['error' => 'invalid email']));
}
if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;

// Dedup: skip if this URL is already in the system
$url_key = function($u) {
    $u = strtolower(trim((string)$u));
    $u = preg_replace('~^https?://~', '', $u);
    $u = preg_replace('~^www\.~', '', $u);
    return rtrim($u, '/');
};
$incoming_key = $url_key($url);
if ($incoming_key) {
    $allp = ww_db()->query("SELECT current_url FROM prospects");
    foreach ($allp as $er) {
        if ($url_key((string)$er['current_url']) === $incoming_key) {
            http_response_code(409);
            exit(json_encode(['error' => 'That site is already in the system — skipped to avoid a duplicate.']));
        }
    }
}

// Pack apollo_data-style extras for later context
$extras = [
    'phone'    => $phone,
    'address'  => $address,
    'place_id' => $place_id,
    'source_detail' => 'manual-add by ' . $me['email'],
];

try {
    $db = ww_db();
    $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO prospects (email, name, business_name, current_url, industry, source, apollo_data) VALUES (?, ?, ?, ?, ?, 'manual', ?)");
    $stmt->execute([$email, $contact, $biz, $url, $industry, json_encode($extras)]);
    $pid = (int)$db->lastInsertId();

    $token = bin2hex(random_bytes(12));
    $jstmt = $db->prepare("INSERT INTO jobs (type, prospect_id, customer_email, business_name, status, scheduled_for, token) VALUES ('outbound', ?, ?, ?, 'queued', datetime('now'), ?)");
    $jstmt->execute([$pid, $email, $biz, $token]);
    $jid = (int)$db->lastInsertId();

    $db->commit();
    echo json_encode(['ok' => true, 'prospect_id' => $pid, 'job_id' => $jid, 'token' => $token]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
