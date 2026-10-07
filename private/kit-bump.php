<?php
// private/kit-bump.php: point every preview that carries the WebWiz Motion Kit at the
// current kit files.
//
// Why: the kit is served with Cache-Control: max-age=31536000, immutable, and each
// page references it as /kit/webwiz-motion.css?v=<filemtime at generation time>.
// That is right for speed and wrong for updates: a page generated before a kit change
// keeps the old ?v= and every browser that already has it keeps the old file for a
// year. Run this after changing either kit file; it rewrites only the ?v= numbers
// (byte-for-byte otherwise), so nothing else on a page can change.
//
//   sudo -u www-data php /var/www/sites/trywebwiz/private/kit-bump.php          # apply
//   sudo -u www-data php /var/www/sites/trywebwiz/private/kit-bump.php --dry    # count only
//
// Run as www-data: previews are www-data owned and a root rewrite would change that.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$dry  = in_array('--dry', $argv, true);
$kit  = '/var/www/sites/trywebwiz/public/kit';
$vcss = (string)@filemtime($kit . '/webwiz-motion.css');
$vjs  = (string)@filemtime($kit . '/webwiz-motion.js');
if ($vcss === '' || $vjs === '') { fwrite(STDERR, "kit files missing under $kit\n"); exit(1); }

$files = glob('/var/www/sites/trywebwiz/public/preview/*/v*/index.html') ?: [];
$seen = 0; $changed = 0;
foreach ($files as $f) {
    $html = @file_get_contents($f);
    if ($html === false || strpos($html, '/kit/webwiz-motion.') === false) continue;
    $seen++;
    $new = preg_replace(
        ['~(/kit/webwiz-motion\.css)\?v=\d+~', '~(/kit/webwiz-motion\.js)\?v=\d+~'],
        ['$1?v=' . $vcss, '$1?v=' . $vjs],
        $html
    );
    if ($new === null || $new === $html) continue;
    $changed++;
    if (!$dry) {
        // in place, so ownership and mode are kept
        $fh = fopen($f, 'r+');
        if ($fh && flock($fh, LOCK_EX)) { ftruncate($fh, 0); fwrite($fh, $new); fflush($fh); flock($fh, LOCK_UN); }
        if ($fh) fclose($fh);
    }
}
echo ($dry ? '[dry] ' : '') . "kit v css={$vcss} js={$vjs}: {$seen} preview(s) carry the kit, {$changed} " . ($dry ? 'would be' : '') . " updated\n";
