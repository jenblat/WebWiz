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
    header('Cache-Control: public, max-age=86400');
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
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => GENIMG_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_HTTPHEADER     => ['content-type: application/json'],
]);
$t0 = microtime(true);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$elapsed = round(microtime(true) - $t0, 2);

@file_put_contents('/tmp/genimg.log',
    gmdate('c') . " key=" . substr($key, 0, 10) . " http=$http elapsed={$elapsed}s ar=$ar promptlen=" . strlen($prompt) . "\n",
    FILE_APPEND
);

if ($resp === false || $http >= 300) {
    error_log('[genimg] http=' . $http . ' resp=' . substr((string)$resp, 0, 300));
    genimg_placeholder($label ?: $prompt);
}

$j = json_decode((string)$resp, true);
$b64 = $j['predictions'][0]['bytesBase64Encoded'] ?? '';
if (!is_string($b64) || $b64 === '') {
    error_log('[genimg] no image in response: ' . substr((string)$resp, 0, 300));
    genimg_placeholder($label ?: $prompt);
}

$bytes = base64_decode($b64, true);
if (!is_string($bytes) || strlen($bytes) < 2000) {
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
