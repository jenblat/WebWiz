<?php
// /api/gen_status.php — lightweight poll endpoint for the async /try generation.
// GET ?t=<token> -> {status:'ready'|'building'|'failed', preview_url?, error?}
// Ready is signalled by the preview file existing on disk; failed by a status.json marker.
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');

$t = (string)($_GET['t'] ?? '');
if (!preg_match('~^[a-f0-9]{6,32}$~', $t)) { echo json_encode(['status' => 'error', 'error' => 'bad token']); exit; }

$dir   = '/var/www/sites/trywebwiz/public/preview/' . $t;
$index = $dir . '/v1/index.html';

// Ready: the preview file is written (and non-trivial).
if (is_file($index) && (int)@filesize($index) > 500) {
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

// Otherwise still building.
echo json_encode(['status' => 'building']);
