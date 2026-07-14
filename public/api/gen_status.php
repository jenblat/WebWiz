<?php
// /api/gen_status.php — lightweight poll endpoint for the async /try generation.
// GET ?t=<token> -> {status:'ready'|'building'|'failed', preview_url?, error?}
// Ready = preview file written AND images pre-warmed (a 'ready' marker file).
// A time-based fallback still flips to ready if the marker never lands, so the
// poller can never hang. Failed = a status.json marker with status:failed.
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');

$t = (string)($_GET['t'] ?? '');
if (!preg_match('~^[a-f0-9]{6,32}$~', $t)) { echo json_encode(['status' => 'error', 'error' => 'bad token']); exit; }

$dir   = '/var/www/sites/trywebwiz/public/preview/' . $t;
$index = $dir . '/v1/index.html';

$idx_ok = is_file($index) && (int)@filesize($index) > 500;
// Pre-warm finished marker (async writes this once images are cached).
$warmed = is_file($dir . '/ready');
// Safety net: if the marker never lands (rare failure), still go ready once the
// preview file has been on disk long enough that pre-warm has certainly run/died.
$settled = $idx_ok && (time() - (int)@filemtime($index) > 25);

if ($idx_ok && ($warmed || $settled)) {
    echo json_encode(['status' => 'ready', 'preview_url' => '/preview/' . $t . '/v1/index.html', 'url' => '/preview/' . $t . '/']);
    exit;
}

// Failed: async generation wrote a failure marker.
$sf = $dir . '/status.json';
if (is_file($sf)) {
    $s = json_decode((string)@file_get_contents($sf), true);
    if (is_array($s) && ($s['status'] ?? '') === 'failed') {
        echo json_encode(['status' => 'failed', 'error' => (string)($s['error'] ?? 'Generation failed')]);
        exit;
    }
}

// Otherwise still building (includes the brief window where the HTML exists but
// images are still being pre-warmed).
echo json_encode(['status' => 'building']);
