<?php
// /var/www/sites/trywebwiz/public/api/places_search.php
// Server-side proxy for Google Places API (New) — Text Search.
// Frontend hits this with ?q=... ; we forward to Google with the secret API key
// and return a trimmed list of matches. Admin auth via session cookie.

declare(strict_types=1);
require '/var/www/sites/trywebwiz/private/webwiz_lib.php';

session_start([
    'cookie_secure'   => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

header('Content-Type: application/json');

// Auth: must be a logged-in admin or team_member
if (empty($_SESSION['uid'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'not authenticated']));
}
$me = ww_user_by_id((int)$_SESSION['uid']);
if (!$me) {
    http_response_code(401);
    exit(json_encode(['error' => 'bad session']));
}

$secrets = ww_secrets();
$key = $secrets['GOOGLE_PLACES_API_KEY'] ?? '';
if (!$key) {
    http_response_code(503);
    exit(json_encode(['error' => 'GOOGLE_PLACES_API_KEY not configured in secrets.php']));
}

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    exit(json_encode(['results' => []]));
}
if (mb_strlen($q) > 200) $q = mb_substr($q, 0, 200);

// Places API (New) — Text Search: https://places.googleapis.com/v1/places:searchText
$body = json_encode([
    'textQuery' => $q,
    'pageSize'  => 8,
]);

$ch = curl_init('https://places.googleapis.com/v1/places:searchText');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Goog-Api-Key: ' . $key,
        // Limit returned fields to control billing (Places API New is field-masked)
        'X-Goog-FieldMask: places.id,places.displayName,places.formattedAddress,places.websiteUri,places.nationalPhoneNumber,places.internationalPhoneNumber,places.types,places.primaryTypeDisplayName,places.googleMapsUri',
    ],
    CURLOPT_TIMEOUT        => 8,
]);
$raw  = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false || $http >= 400) {
    http_response_code(502);
    $err = ['error' => 'Google Places error', 'http' => $http];
    if (is_string($raw) && $raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j) && isset($j['error']['message'])) $err['detail'] = $j['error']['message'];
    }
    exit(json_encode($err));
}

$j = json_decode($raw, true) ?: [];
$out = [];
foreach (($j['places'] ?? []) as $p) {
    $out[] = [
        'place_id'      => $p['id'] ?? '',
        'name'          => $p['displayName']['text'] ?? '',
        'address'       => $p['formattedAddress'] ?? '',
        'website'       => $p['websiteUri'] ?? '',
        'phone'         => $p['nationalPhoneNumber'] ?? ($p['internationalPhoneNumber'] ?? ''),
        'primary_type'  => $p['primaryTypeDisplayName']['text'] ?? '',
        'types'         => $p['types'] ?? [],
        'maps_url'      => $p['googleMapsUri'] ?? '',
    ];
}

echo json_encode(['results' => $out]);
