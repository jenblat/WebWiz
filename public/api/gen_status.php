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
// Written once the page has passed visual QA (and pre-warm) — the reveal gate.
$warmed = is_file($dir . '/ready');
// Visual QA is still running, or it rejected the page and a repair/handoff is in
// flight. Either way the page on disk is NOT cleared for the visitor yet.
$qa_running = is_file($dir . '/qa');
$held       = is_file($dir . '/held');
// Safety net: if the marker never lands (rare failure), still go ready once the
// preview file has been on disk long enough that pre-warm has certainly run/died.
//
// This net used to be unconditional, and it silently outranked the reveal gate:
// index.html is written ~30s before QA finishes, so a page that visual QA was
// about to reject went ready anyway at the 25s mark. Any fix that only moved the
// `ready` marker later would have appeared to work and changed nothing. It is now
// suppressed while QA is actually in flight; gen_status' own 420s stall detector
// below is the real protection against a background process that has died.
$settled = $idx_ok && !$qa_running && !$held && (time() - (int)@filemtime($index) > 25);

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
// HELD: visual QA rejected this page and magic.php deliberately did not open the
// reveal. This is not a stall and must not be reported as one - the background
// process is alive and either repairing or handing off to the notify-ready email.
// Keep answering "building" so the client's own 300s ceiling shows its existing
// "drop your email and we'll send it the moment it's ready" card.
if ($held) {
    echo json_encode(['status' => 'building', 'stage' => 'finishing']);
    exit;
}

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
