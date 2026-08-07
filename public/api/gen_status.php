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

// ---- STALL DETECTION ----
// If the background generation dies WITHOUT writing a failed marker (PHP fatal, OOM,
// the set_time_limit(240) ceiling, or an fpm worker kill) then nothing above matches and
// this endpoint answered 'building' forever: the visitor watched an infinite spinner and
// no alert of any kind was raised. Past a hard ceiling the build is definitively dead, so
// say so and report it once.
//
// 420s, not 300s: a healthy build measured ~168s, and gate retries plus the visual-QA
// round can legitimately push past the 300s the client polls for. This is the "certainly
// dead" line, not the "slower than usual" line.
$started = is_file($sf) ? (int)@filemtime($sf) : (is_dir($dir) ? (int)@filemtime($dir) : 0);
if ($started > 0 && (time() - $started) > 420) {
    $flag = $dir . '/stalled';
    if (!is_file($flag)) {
        // Marker first, so concurrent polls (every 3s) report exactly once, not N times.
        @file_put_contents($flag, (string)time());
        // Loaded lazily: this endpoint is polled every 3s per visitor and is deliberately
        // dependency-free on the hot path. Only a genuine stall pays for the bootstrap.
        try {
            require_once '/var/www/sites/trywebwiz/private/webwiz_lib.php';
            if (function_exists('ww_sentry_alert')) {
                ww_sentry_alert('WebWiz generation stalled with no failure marker', [
                    'component'  => 'generation',
                    'reason'     => 'gen_stalled_no_marker',
                    'token'      => $t,
                    'stalled_s'  => time() - $started,
                    'have_html'  => $idx_ok ? 1 : 0,
                    'warmed'     => $warmed ? 1 : 0,
                ], 'error');
            }
        } catch (Throwable $e) { /* monitoring must never break the poll */ }
    }
    echo json_encode(['status' => 'failed', 'error' => 'Wizzy hit a snag building that one. Please try again.']);
    exit;
}

// Otherwise still building (includes the brief window where the HTML exists but
// images are still being pre-warmed).
echo json_encode(['status' => 'building']);
