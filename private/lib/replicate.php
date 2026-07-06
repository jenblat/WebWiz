<?php
// /var/www/sites/trywebwiz/private/lib/replicate.php
// Replicate Real-ESRGAN upscaler for low-resolution scraped images.
// Public API:
//   ww_replicate_upscale(string $srcUrl, int $scale = 2, ?int $job_id = null): ?array
//     Downloads the source image, sends to Replicate Real-ESRGAN, saves the upscaled JPG
//     into the existing img cache under key sha1($srcUrl).'_up.jpg', logs cost to api_calls.
//     Returns ['ok'=>true,'path'=>cachePath,'cost'=>$float] on success, null on failure.
//   ww_upscale_html_images(string $html, float $budget_usd_remaining): array
//     Scans the HTML for /api/img.php?u=... URLs, upscales any whose cached image is < width
//     threshold (default 800px), rewrites those URLs to add &up=1. Stops once budget is spent.
//     Returns ['html'=>newHtml, 'upscaled'=>n, 'cost'=>$float, 'budget_left'=>$float].

declare(strict_types=1);

const WW_UPSCALE_WIDTH_THRESHOLD = 1200;   // images narrower than this are candidates
const WW_UPSCALE_DEFAULT_BUDGET  = 0.10;  // $0.10/site default cap (Real-ESRGAN ~$0.002-0.005/run)
const WW_REPLICATE_MODEL         = 'nightmareai/real-esrgan';
// Estimated price per run when we can't compute from prediction.metrics (Real-ESRGAN on T4/L40s ~$0.003)
const WW_REPLICATE_EST_PRICE_PER_RUN = 0.003;

function ww_replicate_token(): string {
    $s = ww_secrets();
    return (string)($s['REPLICATE_API_TOKEN'] ?? '');
}

/** Submit + sync-wait for a Real-ESRGAN prediction. Returns prediction object or null. */
function ww_replicate_predict(string $imageUrl, int $scale = 2): ?array {
    $tok = ww_replicate_token();
    if (!$tok) return null;
    $body = json_encode(['input' => ['image' => $imageUrl, 'scale' => max(2, min(4, $scale))]]);
    $ch = curl_init('https://api.replicate.com/v1/models/' . WW_REPLICATE_MODEL . '/predictions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $tok,
            'Content-Type: application/json',
            'Prefer: wait=60', // ask Replicate to hold the response inline up to 60s
        ],
    ]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$resp, true);
    if (!is_array($j) || empty($j['id'])) return null;
    // If the sync wait did not complete, poll the get URL until done.
    $deadline = time() + 90;
    while (in_array(($j['status'] ?? ''), ['starting', 'processing'], true) && time() < $deadline) {
        usleep(900000);
        $ch = curl_init($j['urls']['get'] ?? ('https://api.replicate.com/v1/predictions/' . $j['id']));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tok],
        ]);
        $resp = curl_exec($ch); curl_close($ch);
        $j2 = json_decode((string)$resp, true);
        if (is_array($j2)) $j = $j2;
    }
    return $j;
}

/** Upscale a single image URL. Stores result in the img cache as <sha1>_up.jpg. */
function ww_replicate_upscale(string $srcUrl, int $scale = 2, ?int $job_id = null): ?array {
    if ($srcUrl === '') return null;
    $key = sha1($srcUrl);
    $cachedUp = '/var/www/sites/trywebwiz/data/imgcache/' . $key . '_up.jpg';
    if (is_file($cachedUp) && filesize($cachedUp) > 1500) {
        return ['ok' => true, 'path' => $cachedUp, 'cost' => 0.0, 'cached' => true];
    }
    $pred = ww_replicate_predict($srcUrl, $scale);
    if (!$pred || ($pred['status'] ?? '') !== 'succeeded' || empty($pred['output'])) return null;
    $out = is_array($pred['output']) ? (string)($pred['output'][0] ?? '') : (string)$pred['output'];
    if ($out === '' || !preg_match('~^https?://~i', $out)) return null;
    // download the upscaled image
    $ch = curl_init($out);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true]);
    $bin = curl_exec($ch); curl_close($ch);
    if (!is_string($bin) || strlen($bin) < 2000) return null;
    // re-encode to JPEG to keep the cache shape consistent
    $im = @imagecreatefromstring($bin);
    if (!$im) {
        // fall back to raw save if GD can't parse (unlikely for png/jpg)
        if (!@file_put_contents($cachedUp, $bin)) return null;
    } else {
        @imagejpeg($im, $cachedUp, 86);
        imagedestroy($im);
    }
    if (!is_file($cachedUp) || filesize($cachedUp) < 1500) return null;
    // cost: prefer prediction.metrics.predict_time * known $/sec if available; else use estimate
    $cost = WW_REPLICATE_EST_PRICE_PER_RUN;
    try { ww_db()->prepare("INSERT INTO api_calls (job_id, provider, model, prompt_tokens, completion_tokens, cost_usd, key_label) VALUES (?, 'replicate', ?, 0, 0, ?, 'replicate')")
        ->execute([$job_id, WW_REPLICATE_MODEL . ':upscale-x' . $scale, $cost]); } catch (Throwable $e) {}
    return ['ok' => true, 'path' => $cachedUp, 'cost' => $cost, 'cached' => false];
}

/** Return [width, height] of a cached img-proxy file (jpg/png/webp/gif). Returns null if unknown. */
function ww_img_cache_dims(string $srcUrl): ?array {
    $key = sha1($srcUrl);
    foreach (['jpg','png','gif','webp'] as $ext) {
        $f = '/var/www/sites/trywebwiz/data/imgcache/' . $key . '.' . $ext;
        if (is_file($f)) {
            $g = @getimagesize($f);
            if ($g && !empty($g[0])) return [(int)$g[0], (int)$g[1]];
        }
    }
    return null;
}

/** True if the cached source image carries meaningful transparency (logos, cutouts). */
function ww_img_cache_has_alpha(string $srcUrl): bool {
    $key = sha1($srcUrl);
    foreach (["png","webp","gif"] as $ext) { // jpg never has alpha
        $f = "/var/www/sites/trywebwiz/data/imgcache/" . $key . "." . $ext;
        if (!is_file($f)) continue;
        $im = @imagecreatefromstring((string)@file_get_contents($f));
        if (!$im) return false;
        $w = imagesx($im); $h = imagesy($im); $found = false;
        for ($sx = 0; $sx < $w && !$found; $sx += max(1, (int)($w / 24))) {
            for ($sy = 0; $sy < $h; $sy += max(1, (int)($h / 24))) {
                if (((imagecolorat($im, $sx, $sy) >> 24) & 0x7F) > 8) { $found = true; break; }
            }
        }
        imagedestroy($im);
        return $found;
    }
    return false;
}

/**
 * Post-finalize pass: upscale any low-res images present in the generated HTML.
 * Rewrites matching /api/img.php?u=... URLs to include &up=1 (img.php serves the upscaled cache).
 */
function ww_upscale_html_images(string $html, float $budget_usd_remaining, ?int $job_id = null): array {
    $upscaled = 0; $cost = 0.0;
    if ($budget_usd_remaining <= 0) return ['html' => $html, 'upscaled' => 0, 'cost' => 0.0, 'budget_left' => $budget_usd_remaining];
    if (!preg_match_all('~/api/img\.php\?u=([^"\'\s<>]+)~i', $html, $m)) {
        return ['html' => $html, 'upscaled' => 0, 'cost' => 0.0, 'budget_left' => $budget_usd_remaining];
    }
    $seen = [];
    foreach ($m[0] as $idx => $fullPath) {
        if ($budget_usd_remaining < WW_REPLICATE_EST_PRICE_PER_RUN) break;
        // skip if this exact URL already has &up=1
        if (strpos($fullPath, 'up=1') !== false) continue;
        // Never upscale logos: Real-ESRGAN re-encodes to opaque raster and destroys the alpha
        // channel, turning a transparent logo into a broken white/black blob. Flat brand graphics
        // also gain nothing from photo super-resolution.
        if (stripos($fullPath, 'logo') !== false) continue;
        // extract the u= value (urlencoded original URL)
        $enc = $m[1][$idx];
        // some u values include leading & from earlier params; trim to just the u value
        $enc = preg_split('~&~', $enc)[0];
        $orig = urldecode($enc);
        if (isset($seen[$orig])) continue;
        $seen[$orig] = true;
        $dims = ww_img_cache_dims($orig);
        if (!$dims) continue; // unknown size: skip
        if ($dims[0] >= WW_UPSCALE_WIDTH_THRESHOLD) continue;
        if (ww_img_cache_has_alpha($orig)) continue; // transparent image: upscaling flattens the alpha channel
        // Dynamic scale: tiny sources (<400px) get 4x; medium (400-1200px) get 2x. Real-ESRGAN supports both.
        $scale = ($dims[0] < 400) ? 4 : 2;
        $up = ww_replicate_upscale($orig, $scale, $job_id);
        if (!$up || empty($up['ok'])) continue;
        $cost += (float)($up['cost'] ?? 0);
        $budget_usd_remaining -= (float)($up['cost'] ?? 0);
        $upscaled++;
        // rewrite every occurrence of this exact URL in HTML to add &up=1
        $needle = '/api/img.php?u=' . $enc;
        $repl   = $needle . '&up=1';
        $html   = str_replace($needle, $repl, $html);
    }
    return ['html' => $html, 'upscaled' => $upscaled, 'cost' => $cost, 'budget_left' => $budget_usd_remaining];
}

/** Settings-aware variant-HTML upscale wrapper. Per-job budget shared across variants. */
function ww_apply_upscale(string $html, ?int $job_id = null): string {
    static $cfg = null; static $cache = [];
    if ($cfg === null) {
        $d = ww_db();
        $g = function ($k, $d2) use ($d) { $s = $d->prepare("SELECT value FROM settings WHERE key=?"); $s->execute([$k]); $v = $s->fetchColumn(); return $v === false ? $d2 : (string)$v; };
        $cfg = ["enabled" => $g("upscale_enabled", "1") === "1", "cap" => (float)$g("upscale_max_usd_per_site", "0.10")];
    }
    if (!$cfg["enabled"] || $cfg["cap"] <= 0) return $html;
    $jk = (string)($job_id ?? "none");
    if (!isset($cache[$jk])) $cache[$jk] = $cfg["cap"];
    if ($cache[$jk] < WW_REPLICATE_EST_PRICE_PER_RUN) return $html;
    $r = ww_upscale_html_images($html, $cache[$jk], $job_id);
    $cache[$jk] = (float)$r["budget_left"];
    return $r["html"];
}
