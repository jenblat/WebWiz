<?php
declare(strict_types=1);
// Image proxy with disk cache + downscale. Falls back to a branded SVG placeholder.
$url   = $_GET['u'] ?? '';
$label = trim((string)($_GET['l'] ?? ''));

const IMG_CACHE_DIR = '/var/www/sites/trywebwiz/data/imgcache';
const IMG_MAX_W     = 1600;

function svg_placeholder(string $label = ''): void {
    $palette = [['#FFF8E7','#12184A'],['#F8EFD3','#12184A'],['#3FCFA8','#12184A'],['#F7C84A','#12184A']];
    $p = $palette[abs(crc32($label)) % count($palette)];
    $bg = $p[0]; $fg = $p[1];
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
    echo '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="' . $bg . '"/><stop offset="100%" stop-color="' . $fg . '" stop-opacity="0.18"/></linearGradient></defs>';
    echo '<rect width="800" height="600" fill="url(#g)"/>';
    echo '<circle cx="160" cy="120" r="60" fill="' . $fg . '" opacity="0.06"/>';
    echo '<circle cx="680" cy="500" r="100" fill="' . $fg . '" opacity="0.05"/>';
    echo '<text x="50%" y="52%" text-anchor="middle" font-family="-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif" font-size="180" font-weight="900" fill="' . $fg . '" opacity="0.4">' . htmlspecialchars($initials) . '</text>';
    echo '</svg>';
    exit;
}

function serve_file(string $path, string $ctype): void {
    header('Content-Type: ' . $ctype);
    header('Cache-Control: public, max-age=604800, immutable');
    header('Content-Length: ' . (string)filesize($path));
    readfile($path);
    exit;
}

if (!$url || !preg_match('~^https?://~i', $url)) { svg_placeholder($label); }

// ---- cache hit? ----
$key = sha1($url);
if (!is_dir(IMG_CACHE_DIR)) @mkdir(IMG_CACHE_DIR, 0775, true);
foreach (['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'] as $ext => $ct) {
    $cf = IMG_CACHE_DIR . '/' . $key . '.' . $ext;
    if (is_file($cf) && filesize($cf) > 100) serve_file($cf, $ct);
}

// ---- fetch original ----
$content_type = '';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; WebWizImageProxy/1.0)',
    CURLOPT_HEADERFUNCTION => function ($_c, $hdr) use (&$content_type) {
        if (stripos($hdr, 'content-type:') === 0) $content_type = trim(substr($hdr, 13));
        return strlen($hdr);
    },
]);
$body = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($body === false || $http >= 400 || !preg_match('~^image/~i', $content_type) || strlen($body) < 200) {
    svg_placeholder($label);
}

// ---- non-raster (svg/gif/webp): cache + serve as-is ----
$ctl = strtolower($content_type);
if (strpos($ctl, 'svg') !== false || strpos($ctl, 'gif') !== false || strpos($ctl, 'webp') !== false) {
    $ext = strpos($ctl,'svg')!==false ? 'svg' : (strpos($ctl,'gif')!==false ? 'gif' : 'webp');
    @file_put_contents(IMG_CACHE_DIR . '/' . $key . '.' . $ext, $body);
    header('Content-Type: ' . $content_type);
    header('Cache-Control: public, max-age=604800, immutable');
    echo $body; exit;
}

// ---- raster: decode, downscale, pick JPEG (opaque) or PNG (alpha) ----
$im = @imagecreatefromstring($body);
if (!$im) {
    @file_put_contents(IMG_CACHE_DIR . '/' . $key . '.jpg', $body);
    header('Content-Type: ' . $content_type);
    header('Cache-Control: public, max-age=604800, immutable');
    echo $body; exit;
}
$w = imagesx($im); $h = imagesy($im);
if ($w > IMG_MAX_W) {
    $nw = IMG_MAX_W; $nh = max(1, (int)round($h * IMG_MAX_W / $w));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false); imagesavealpha($dst, true);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($im); $im = $dst; $w = $nw; $h = $nh;
}
$has_alpha = false;
for ($sx = 0; $sx < $w && !$has_alpha; $sx += max(1, (int)($w / 40))) {
    for ($sy = 0; $sy < $h; $sy += max(1, (int)($h / 40))) {
        if (((imagecolorat($im, $sx, $sy) >> 24) & 0x7F) > 10) { $has_alpha = true; break; }
    }
}
if ($has_alpha) {
    imagealphablending($im, false); imagesavealpha($im, true);
    $cf = IMG_CACHE_DIR . '/' . $key . '.png';
    imagepng($im, $cf, 6);
    imagedestroy($im);
    serve_file($cf, 'image/png');
} else {
    $cf = IMG_CACHE_DIR . '/' . $key . '.jpg';
    imagejpeg($im, $cf, 84);
    imagedestroy($im);
    serve_file($cf, 'image/jpeg');
}
