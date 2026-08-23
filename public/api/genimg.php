<?php
// /api/genimg.php — On-demand image generation via Gemini Imagen 4.
// Sonnet uses these URLs when scraped images aren't enough for a card grid.
// Aggressive cache by sha1(prompt+ratio) so repeat hits are free + instant.
//
// Usage:
//   /api/genimg.php?prompt=<description>&ar=4:3&l=<label>
//   ar (aspect ratio): "1:1", "4:3", "3:4", "16:9", "9:16" — defaults "4:3"
//   l (alt-label for fallback placeholder)

declare(strict_types=1);


/**
 * Lazy Sentry reporter. This file has no webwiz_lib bootstrap: it is hit per generated image.
 * webwiz_lib is required only inside a failure branch, and ww_report() throttles to one
 * event per reason per 300s so a broken upstream cannot flood Sentry or stall
 * requests on its synchronous flush().
 */
function genimg_report(string $reason, string $msg, array $ctx = [], string $level = 'error'): void {
    try {
        require_once '/var/www/sites/trywebwiz/private/webwiz_lib.php';
        if (function_exists('ww_report')) ww_report('genimg', $reason, $msg, $ctx, $level, null, 300);
    } catch (Throwable $e) { /* monitoring must never break the response */ }
}

const GENIMG_CACHE_DIR = '/var/www/sites/trywebwiz/data/imgcache';
const GENIMG_MODEL     = 'imagen-4.0-fast-generate-001';
const GENIMG_TIMEOUT   = 25; // seconds — Imagen Fast usually returns in 3-6s

function genimg_placeholder(string $label = ''): void {
    // Same SVG placeholder shape as img.php — keep visual consistency on failure.
    $palette = [['#FFF8E7','#12184A'],['#F8EFD3','#12184A'],['#3FCFA8','#12184A'],['#F7C84A','#12184A']];
    $p = $palette[abs(crc32($label)) % count($palette)];
    [$bg, $fg] = $p;
    $initials = '';
    if ($label !== '') {
        $parts = preg_split('/[\s_\-]+/', $label) ?: [];
        foreach (array_slice($parts, 0, 2) as $w) if ($w !== '') $initials .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    if ($initials === '') $initials = 'WW';
    header('Content-Type: image/svg+xml');
    // NEVER cache a failure. This is the monogram fallback, served when Imagen
    // 429s or times out. It used to go out as `public, max-age=86400`, which froze
    // a TRANSIENT upstream failure into the visitor's browser and any CDN for a
    // full day: the very next request would have generated a real photo, but it
    // was never made. Observed live 2026-08-05 21:15:34-42, ten parallel pre-warm
    // requests tripped Imagen's per-minute quota and returned 429; the visual QA
    // screenshot 24s later scored the page 42/100 for "flat colored placeholder
    // boxes instead of real photography" and "an empty image panel with only 'GO'
    // text" ('GO' being the two initials of that image's label). Same shape as
    // Gandona Winery on 2026-08-07: "a pixelated, low-resolution monogram graphic
    // rather than a real estate photograph", scored 42.
    header('Cache-Control: no-store, max-age=0');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600" preserveAspectRatio="xMidYMid slice">';
    echo '<rect width="800" height="600" fill="' . $bg . '"/>';
    echo '<text x="50%" y="52%" text-anchor="middle" font-family="-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif" font-size="180" font-weight="900" fill="' . $fg . '" opacity="0.4">' . htmlspecialchars($initials) . '</text>';
    echo '</svg>';
    exit;
}

function genimg_serve(string $path): void {
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=604800, immutable');
    header('Content-Length: ' . (string)filesize($path));
    readfile($path);
    exit;
}

$prompt = trim((string)($_GET['prompt'] ?? ''));
$label  = trim((string)($_GET['l'] ?? ''));
$ar     = (string)($_GET['ar'] ?? '4:3');

if ($prompt === '' || mb_strlen($prompt) < 4) genimg_placeholder($label);
if (mb_strlen($prompt) > 800) $prompt = mb_substr($prompt, 0, 800);

// Validate aspect ratio — Imagen 4 supports a fixed set.
$valid_ar = ['1:1', '4:3', '3:4', '16:9', '9:16'];
if (!in_array($ar, $valid_ar, true)) $ar = '4:3';

if (!is_dir(GENIMG_CACHE_DIR)) @mkdir(GENIMG_CACHE_DIR, 0775, true);

// Cache key: prompt + aspect ratio
$key = sha1($prompt . '|' . $ar);
$cache_path = GENIMG_CACHE_DIR . '/gen_' . $key . '.jpg';
if (is_file($cache_path) && filesize($cache_path) > 2000) {
    genimg_serve($cache_path);
}

// Per-IP soft rate limit (cheap protection against scraping the endpoint).
$ip = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
$rl_path = '/tmp/genimg_rl_' . substr(sha1($ip), 0, 16) . '.txt';
if (is_file($rl_path)) {
    $hits = (array)json_decode((string)@file_get_contents($rl_path), true);
    $hits = array_filter($hits, fn($t) => $t > time() - 60);
    if (count($hits) >= 30) {
        http_response_code(429);
        header('Content-Type: text/plain');
        echo 'rate limit';
        exit;
    }
} else { $hits = []; }
$hits[] = time();
@file_put_contents($rl_path, json_encode(array_values($hits)));

// Load Gemini key.
$secrets = require '/var/www/sites/trywebwiz/secrets.php';
$gemini_key = (string)($secrets['GEMINI_API_KEY'] ?? '');
if ($gemini_key === '') {
    genimg_report('genimg_key_missing', 'WebWiz image generation key not configured', [], 'error');
    error_log('[genimg] GEMINI_API_KEY missing');
    genimg_placeholder($label ?: $prompt);
}

// Call Imagen 4 Fast.
$body = json_encode([
    'instances'  => [['prompt' => $prompt]],
    'parameters' => [
        'sampleCount'      => 1,
        'aspectRatio'      => $ar,
        'personGeneration' => 'allow_adult',
    ],
]);
$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GENIMG_MODEL . ':predict?key=' . urlencode($gemini_key);
$t0 = microtime(true);
// Retry once on a THROTTLE/transient upstream status before giving up and
// showing a monogram. magic.php's pre-warm fires up to 10 of these in parallel
// for one page, which is exactly the shape that trips Imagen's per-minute quota:
// on 2026-08-05 at 21:15:34-42 six requests came back 429 within eight seconds
// and the page was scored 42/100 for placeholder boxes moments later. 13 of the
// 14 non-200 responses this endpoint has EVER returned were 429s, so one
// jittered retry converts most of them into a real photo. Bounded at one extra
// attempt so a genuine outage cannot stall the request.
$attempts = 0;
do {
    $attempts++;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => GENIMG_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER     => ['content-type: application/json'],
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $retryable = ($http === 429 || $http === 500 || $http === 503 || $http === 0);
    if ($retryable && $attempts < 2) {
        // Jitter so ten parallel pre-warm workers do not all retry in lockstep
        // and re-trip the same per-minute quota together.
        usleep(random_int(900, 2100) * 1000);
        continue;
    }
    break;
} while (true);
$elapsed = round(microtime(true) - $t0, 2);

@file_put_contents('/tmp/genimg.log',
    gmdate('c') . " key=" . substr($key, 0, 10) . " http=$http attempts=$attempts elapsed={$elapsed}s ar=$ar promptlen=" . strlen($prompt) . "\n",
    FILE_APPEND
);

if ($resp === false || $http >= 300) {
    genimg_report('genimg_upstream_error', 'WebWiz image generation upstream failed',
        ['http_status' => $http], 'error');
    error_log('[genimg] http=' . $http . ' resp=' . substr((string)$resp, 0, 300));
    genimg_placeholder($label ?: $prompt);
}

$j = json_decode((string)$resp, true);
$b64 = $j['predictions'][0]['bytesBase64Encoded'] ?? '';
if (!is_string($b64) || $b64 === '') {
    genimg_report('genimg_no_image_in_response', 'WebWiz image generation returned no image', [], 'error');
    error_log('[genimg] no image in response: ' . substr((string)$resp, 0, 300));
    genimg_placeholder($label ?: $prompt);
}

$bytes = base64_decode($b64, true);
if (!is_string($bytes) || strlen($bytes) < 2000) {
    genimg_report('genimg_image_too_small', 'WebWiz image generation returned a truncated image',
        ['bytes' => is_string($bytes) ? strlen($bytes) : 0], 'warning');
    error_log('[genimg] decoded image too small');
    genimg_placeholder($label ?: $prompt);
}

// Imagen returns PNG; transcode to JPEG q85 for smaller files + correct
// content-type. Saves ~70% on bytes vs serving raw PNG.
$im = @imagecreatefromstring($bytes);
if ($im) {
    imageinterlace($im, true);
    ob_start();
    imagejpeg($im, null, 85);
    $jpg = ob_get_clean();
    imagedestroy($im);
    if ($jpg && strlen($jpg) > 2000) $bytes = $jpg;
}

file_put_contents($cache_path, $bytes);
genimg_serve($cache_path);
