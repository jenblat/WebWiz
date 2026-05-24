<?php
declare(strict_types=1);
$url = $_GET['u'] ?? '';
$label = trim((string)($_GET['l'] ?? ''));

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

if (!$url || !preg_match('~^https?://~i', $url)) { svg_placeholder($label); }

$content_type = '';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => 12,
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
header('Content-Type: ' . $content_type);
header('Cache-Control: public, max-age=604800, immutable');
echo $body;
