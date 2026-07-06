<?php
// /var/www/sites/trywebwiz/private/lib/scrape.php
// Homepage scraper. Pulls logo, colors, headings, copy, images, videos.
// Subpages are fetched concurrently (curl_multi) and images are validated in parallel.

declare(strict_types=1);

function ww_normalize_image_url(string $u): string {
    $u = preg_replace('~-\d{2,4}[wh]\.(png|jpe?g|webp|gif)(\?|$)~i', '.$1$2', $u) ?? $u;
    $u = preg_replace('~([?&])(w|h|width|height)=\d+~i', '$1', $u) ?? $u;
    $u = rtrim($u, '?&');
    return $u;
}

function ww_upgrade_image_url(string $u): string {
    // Wix CDN — strip thumbnail transform; request large fitted high-q version.
    // Pattern: static.wixstatic.com/media/<id>/v1/[crop/...,]fill/w_XX,h_XX,...,enc_avif,quality_auto/<file>
    // We replace the entire /v1/.../ transform path with our own large preset.
    if (preg_match('~^(https?://(?:[a-z0-9\-]+\.)?wixstatic\.com/media/[^/]+)/v1/[^/]+(?:/[^/]+)*?/([^/?#]+\.(?:png|jpe?g|webp|gif))~i', $u, $m)) {
        return $m[1] . '/v1/fit/w_1920,h_1280,al_c,q_85,enc_auto/' . $m[2];
    }

    // Shopify CDN — _NNNx.jpg, _NNNxNNN.jpg or _small.jpg / _medium.jpg / _large.jpg suffixes.
    if (preg_match('~(cdn\.shopify\.com|shopifycdn\.com|myshopify\.com).*?(_(?:\d{1,4}x(?:\d{1,4})?|small|medium|large|grande|compact))\.(png|jpe?g|webp|gif)~i', $u, $m)) {
        return preg_replace('~_(?:\d{1,4}x(?:\d{1,4})?|small|medium|large|grande|compact)\.(png|jpe?g|webp|gif)~i', '_2048x.$1', $u, 1) ?? $u;
    }

    // Squarespace / generic -NNNw.jpg suffix (kept from previous version).
    if (preg_match('~-(\d{2,4})[wh]\.(png|jpe?g|webp|gif)~i', $u, $m)) {
        if ((int)$m[1] < 1000) {
            return preg_replace('~-\d{2,4}[wh]\.(png|jpe?g|webp|gif)~i', '-1920w.$1', $u, 1) ?? $u;
        }
    }

    // Imgix / common query-param sizing — bump w= to at least 1600.
    if (preg_match('~([?&])w=(\d+)~', $u, $m) && (int)$m[2] < 1200) {
        $u = preg_replace('~([?&])w=\d+~', '${1}w=1920', $u, 1) ?? $u;
    }

    return $u;
}

function ww_image_is_thumb(string $u): bool {
    if (preg_match('~-(\d{1,3})[wh]\.~', $u, $m)) return (int)$m[1] < 250;
    return false;
}

function ww_image_is_icon(string $u, string $alt = ''): bool {
    // URL-pattern signals — explicit naming or icon directories
    if (preg_match('~(^|[/_.-])(icon|icons|sprite|svg-?icon|logomark|favicon)([/_.-]|$)~i', $u)) return true;
    if (preg_match('~(^|[/_.-])(check-?list|check-?mark|house-?check|house-?umbrella|umbrella-?check)([/_.-]|$)~i', $u)) return true;
    // Tiny dimensions in URL — anything <=200 on either axis is icon territory
    if (preg_match('~-(\d{1,3})x(\d{1,3})\.~', $u, $m)) {
        if ((int)$m[1] <= 200 || (int)$m[2] <= 200) return true;
    }
    // Common icon-y alt phrases
    if ($alt !== '') {
        $a = strtolower($alt);
        foreach (['icon','clipart','symbol','badge'] as $w) {
            if (strpos($a, $w) !== false) return true;
        }
    }
    return false;
}

/**
 * Detect "upscaled-soft" images. CMSs like Wix happily deliver a 5120x3413 file
 * from a 1024x683 source upload — pixel dimensions are big but the actual detail
 * is low. Heuristic: bytes-per-megapixel at requested CDN dimensions. A real
 * photographic JPEG at q>=85 lands around 0.5-1.5 MB/MP. Anything under 0.30 with
 * >0.5MP claimed is almost certainly an upscaled soft source.
 *
 * $url    — image URL (we parse dimensions from Wix-style URL hints if present)
 * $bytes  — Content-Length of the source fetch
 * $w/$h   — fallback dimensions from <img width/height> attrs if URL has no hints
 */
function ww_image_is_soft(string $url, int $bytes, int $w_attr = 0, int $h_attr = 0): bool {
    if ($bytes <= 0) return false;
    // Try Wix-style URL dimensions first: /v1/.../w_NNNN,h_NNNN/...
    $w = 0; $h = 0;
    if (preg_match('~/v1/[^/]*?w_(\d+)[^/]*?h_(\d+)~i', $url, $m)) {
        $w = (int)$m[1]; $h = (int)$m[2];
    }
    // Squarespace-style suffix: -NNNNw.jpg
    if (!$w && preg_match('~-(\d{3,4})w\.~i', $url, $m2)) {
        $w = (int)$m2[1]; $h = (int)round($w * 0.66); // assume 3:2
    }
    // Fall back to attribute hints
    if (!$w) $w = $w_attr;
    if (!$h) $h = $h_attr;
    if ($w < 600 || $h < 400) return false; // not "big" — not relevant to softness
    $mp = ($w * $h) / 1e6;
    if ($mp < 0.5) return false;
    $bytes_per_mp = $bytes / 1e6 / $mp;
    return $bytes_per_mp < 0.30; // soft threshold
}

function ww_image_is_placeholder_alt(string $alt): bool {
    $alt = strtolower(trim($alt));
    if ($alt === '') return false;
    foreach (['a white background with a few lines on it','a black background with a few lines','placeholder','image'] as $b) {
        if ($alt === $b) return true;
    }
    return false;
}

function ww_image_is_cutout(string $u, string $alt): bool {
    if (preg_match('~transparent|cut-?out|removebg|no-?bg|headshot~i', $u)) return true;
    if (preg_match('~transparent|cut-?out|headshot~i', $alt)) return true;
    return false;
}

// Parallel GET of multiple URLs. Returns [origUrl => ['html'=>string,'final_url'=>string,'http'=>int]].
/**
 * Build the curl opts for a scrape fetch, given a UA "profile":
 *   'chrome'   -> real Chrome UA + browser-style Accept headers (works on most sites)
 *   'bare'     -> NO User-Agent header at all (some WAFs blanket-block Mozilla/Bot strings
 *                 but allow bare or curl-default UAs; we saw this on vivamexicocantinagrill.com)
 */
function ww_scrape_curl_opts(string $profile, int $timeout): array {
    $base = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING       => '', // accept gzip/deflate/br
    ];
    if ($profile === 'bare') {
        // Send empty UA + minimal headers — defeats WAFs that key on 'Mozilla'/'Bot'.
        $base[CURLOPT_USERAGENT] = '';
        $base[CURLOPT_HTTPHEADER] = ['Accept: */*'];
    } else {
        $base[CURLOPT_USERAGENT] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36';
        $base[CURLOPT_HTTPHEADER] = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Sec-CH-UA: "Not(A:Brand";v="24", "Chromium";v="127"',
            'Sec-CH-UA-Mobile: ?0',
            'Sec-CH-UA-Platform: "Windows"',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-User: ?1',
            'Upgrade-Insecure-Requests: 1',
        ];
    }
    return $base;
}

/**
 * Identify Cloudflare's interstitial JS challenge ('Just a moment...') so we can
 * route around it. CF returns it as 200 OR 403 with the same body — checking
 * the body is the reliable signal.
 */
function ww_is_cloudflare_challenge(string $html, int $http): bool {
    if ($http && $http >= 200 && $http < 300 && strlen($html) > 50000) return false; // big real page
    if (stripos($html, 'Just a moment...') !== false && stripos($html, 'challenges.cloudflare.com') !== false) return true;
    if (stripos($html, 'cf-mitigated') !== false || stripos($html, 'cf-chl-bypass') !== false) return true;
    return false;
}

/**
 * Last-resort fetch using SYSTEM curl via shell_exec. Cloudflare's bot scoring
 * uses JA3 (TLS fingerprint), and the system curl binary on this droplet
 * produces a JA3 that CF treats as 'trusted client' while libcurl-from-PHP
 * does not. Confirmed empirically on vivamexicocantinagrill.com.
 */
function ww_http_get_shell(string $url, int $timeout = 25): array {
    $tmp = tempnam('/tmp', 'wwsh_');
    if (!$tmp) return ['html' => '', 'http' => 0, 'final_url' => $url];
    // Use --write-out separator that's unlikely to appear in any HTML body so we
    // can split status from body easily.
    $wfmt = "\n--WWHTTP--%{http_code}--WWURL--%{url_effective}\n";
    $cmd = '/usr/bin/curl -sS --max-time ' . (int)$timeout
         . ' -L --compressed --max-redirs 5 -w ' . escapeshellarg($wfmt)
         . ' -o ' . escapeshellarg($tmp)
         . ' ' . escapeshellarg($url) . ' 2>/dev/null';
    $stat = (string)shell_exec($cmd);
    $body = @file_get_contents($tmp) ?: '';
    @unlink($tmp);
    $http = 0; $final = $url;
    if (preg_match('~--WWHTTP--(\d+)--WWURL--([^\n]*)~', $stat, $m)) {
        $http = (int)$m[1];
        $final = trim($m[2]) ?: $url;
    }
    return ['html' => $body, 'http' => $http, 'final_url' => $final];
}

function ww_http_get_many(array $urls, int $timeout = 12): array {
    if (!$urls) return [];

    // Pass 1: real Chrome UA via libcurl multi.
    $fire = function(array $urls, string $profile) use ($timeout) {
        $mh = curl_multi_init();
        $handles = [];
        $opts = ww_scrape_curl_opts($profile, $timeout);
        foreach ($urls as $u) {
            $ch = curl_init($u);
            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($mh, $ch);
            $handles[$u] = $ch;
        }
        do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);
        $out = [];
        foreach ($handles as $u => $ch) {
            $out[$u] = [
                'html'      => (string)curl_multi_getcontent($ch),
                'final_url' => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $u,
                'http'      => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    };

    $out = $fire($urls, 'chrome');

    // Pass 2: retry 403/429/CF-challenge URLs with no UA (defeats simple WAF UA rules).
    $needs_retry = function(array $r): bool {
        $h = (int)$r['http']; $b = (string)$r['html'];
        if ($h === 403 || $h === 429 || $h === 0 || $b === '') return true;
        if (ww_is_cloudflare_challenge($b, $h)) return true;
        return false;
    };
    $retry = array_keys(array_filter($out, $needs_retry));
    if ($retry) {
        $bare = $fire($retry, 'bare');
        foreach ($bare as $u => $r) {
            $new_http = (int)$r['http'];
            if (!$needs_retry($r) && ($new_http >= 200 && $new_http < 400)) {
                $out[$u] = $r;
            }
        }
    }

    // Pass 3: anything still failing (esp. Cloudflare challenge) — shell out to
    // system curl, which produces a JA3 that CF treats as legitimate.
    $shell_retry = array_keys(array_filter($out, $needs_retry));
    foreach ($shell_retry as $u) {
        $r = ww_http_get_shell($u, max($timeout, 20));
        if (!$needs_retry($r) && (int)$r['http'] >= 200 && (int)$r['http'] < 400) {
            $out[$u] = $r;
        }
    }

    return $out;
}

// Parallel HEAD-check; keep only images that respond 200-399 w/ image content-type (405/0 kept).
function ww_filter_live_images(array $images, int $max_check = 24, int $timeout = 6): array {
    if (!$images) return $images;
    $images = array_slice($images, 0, $max_check);
    $mh = curl_multi_init();
    $handles = [];
    foreach ($images as $i => $img) {
        $ch = curl_init($img['url']);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);
    $alive = [];
    foreach ($handles as $i => $ch) {
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '');
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        $keep = ($code === 0 || $code === 405) || ($code >= 200 && $code < 400 && ($ctype === '' || stripos($ctype, 'image/') === 0));
        if ($keep) $alive[] = $images[$i];
    }
    curl_multi_close($mh);
    return $alive;
}

// Parse already-fetched HTML into the structured scrape array.
function scrape_parse(string $html, string $final_url): array {
    $base = parse_url($final_url);
    $origin = ($base['scheme'] ?? 'https') . '://' . ($base['host'] ?? '');

    $abs = function($u) use ($origin, $final_url) {
        if (!$u) return null;
        if (preg_match('~^https?://~i', $u)) return $u;
        if (str_starts_with($u, '//')) return 'https:' . $u;
        if (str_starts_with($u, '/'))  return $origin . $u;
        $dir = preg_replace('~/[^/]*$~', '/', $final_url);
        return $dir . $u;
    };

    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $xp = new DOMXPath($dom);

    $title = '';
    $tn = $xp->query('//title')->item(0);
    if ($tn) $title = trim($tn->textContent);
    $desc = '';
    $mn = $xp->query('//meta[@name="description"]/@content')->item(0);
    if ($mn) $desc = trim((string)$mn->nodeValue);

    $logo = null;
    foreach (['link[rel*="icon"]', 'link[rel="apple-touch-icon"]'] as $sel) {
        $nodes = $xp->query('//' . str_replace('link[rel*="icon"]', "link[contains(@rel,'icon')]", $sel));
        foreach ($nodes as $n) {
            $href = $n->getAttribute('href');
            if ($href) { $logo = $abs($href); break 2; }
        }
    }
    if (!$logo) {
        foreach ($xp->query('//header//img | //img[contains(@alt,"logo") or contains(@alt,"Logo") or contains(@class,"logo")]') as $img) {
            $src = trim((string)$img->getAttribute('src'));
            if ($src === '' || stripos($src, 'data:') === 0) $src = $img->getAttribute('nitro-lazy-src') ?: $img->getAttribute('data-lazy-src') ?: $img->getAttribute('data-src') ?: '';
            if ($src !== '' && stripos($src, 'data:') !== 0) { if (preg_match('~/nitropack_static/[^/]+/assets/images/optimized/[^/]+/([^/]+)/(wp-content/.+)$~i', $src, $npm)) $src = 'https://' . $npm[1] . '/' . $npm[2]; $logo = $abs($src); break; }
        }
    }

    $colors = [];
    $tc = $xp->query('//meta[@name="theme-color"]/@content')->item(0);
    if ($tc) $colors[] = trim((string)$tc->nodeValue);
    if (preg_match_all('/#[0-9a-fA-F]{6}\b/', $html, $mm)) {
        $counts = array_count_values(array_map('strtolower', $mm[0]));
        arsort($counts);
        foreach (array_slice(array_keys($counts), 0, 6) as $c) $colors[] = $c;
    }
    $colors = array_values(array_unique($colors));

    $h1 = []; foreach ($xp->query('//h1') as $n) { $t = trim($n->textContent); if ($t) $h1[] = $t; }
    $h2 = []; foreach ($xp->query('//h2') as $n) { $t = trim($n->textContent); if ($t) $h2[] = $t; }
    $h3 = []; foreach ($xp->query('//h3') as $n) { $t = trim($n->textContent); if ($t) $h3[] = $t; }

    $paras = [];
    foreach ($xp->query('//p') as $n) {
        $t = trim(preg_replace('/\s+/', ' ', $n->textContent));
        if (mb_strlen($t) >= 40) $paras[] = $t;
        if (count($paras) >= 20) break;
    }

    $seen = []; $images = [];
    foreach ($xp->query('//img') as $img) {
        $src = trim((string)$img->getAttribute('src'));
        if ($src === '' || stripos($src, 'data:') === 0) {
            // Lazy-loaded (NitroPack/WP Rocket/etc.): real URL lives in a data-*/nitro attr, not src.
            $src = $img->getAttribute('nitro-lazy-src') ?: $img->getAttribute('data-lazy-src') ?: $img->getAttribute('data-src')
                ?: $img->getAttribute('data-original') ?: $img->getAttribute('data-orig-file') ?: $img->getAttribute('data-lazy') ?: '';
            if ($src === '') { $ss = $img->getAttribute('srcset') ?: $img->getAttribute('data-srcset'); if ($ss) { $first = trim(explode(',', $ss)[0]); $src = trim(explode(' ', $first)[0]); } }
        }
        if ($src === '' || stripos($src, 'data:') === 0) continue;
        if (preg_match('~/nitropack_static/[^/]+/assets/images/optimized/[^/]+/([^/]+)/(wp-content/.+)$~i', $src, $npm)) $src = 'https://' . $npm[1] . '/' . $npm[2];
        $u = $abs($src);
        if (!$u || preg_match('/\.svg($|\?)/i', $u)) continue;
        $alt = trim($img->getAttribute('alt'));
        $width_attr  = (int)($img->getAttribute('width') ?: 0);
        $height_attr = (int)($img->getAttribute('height') ?: 0);
        $norm = ww_normalize_image_url($u);
        if (isset($seen[$norm])) continue;
        $seen[$norm] = true;
        if (ww_image_is_placeholder_alt($alt)) continue;
        $is_thumb = ww_image_is_thumb($u);
        $is_icon  = ww_image_is_icon($u, $alt);
        $is_soft  = false; // computed later in ww_filter_live_images via Content-Length
        $u_full   = ww_upgrade_image_url($u);
        $is_logo  = (stripos($u, 'logo') !== false || stripos($alt, 'logo') !== false);
        $is_team_card = false;
        if (preg_match('/\b[A-Z][a-z]+\s+[A-Z]\.?\s*(?:[A-Z][a-z]+)?\b/', $alt) &&
            (stripos($alt, 'manager') !== false || stripos($alt, 'director') !== false || stripos($alt, 'officer') !== false || stripos($alt, 'client success') !== false)) {
            $is_team_card = true;
        }
        $is_cutout   = ww_image_is_cutout($u, $alt);
        $is_portrait = ($height_attr > 0 && $width_attr > 0 && $height_attr > $width_attr * 1.15);
        $images[] = [
            'url' => $u_full, 'alt' => $alt, 'width_hint' => $width_attr, 'height_hint' => $height_attr,
            'is_logo' => $is_logo, 'is_thumb' => $is_thumb, 'is_team_card' => $is_team_card,
            'is_cutout' => $is_cutout, 'is_portrait' => $is_portrait, 'is_icon' => $is_icon, 'is_soft' => $is_soft,
        ];
        if (count($images) >= 30) break;
    }
    usort($images, function($a, $b) {
        $score = fn($x) => ($x['is_logo']?5:0) + ($x['is_thumb']?2:0) + ($x['is_team_card']?4:0) + ($x['is_icon']?10:0) + ($x['is_soft']?6:0);
        return $score($a) <=> $score($b);
    });

    $videos = [];
    foreach ($xp->query('//iframe[contains(@src,"youtube") or contains(@src,"vimeo") or contains(@src,"wistia")]') as $f) {
        $videos[] = ['type' => 'iframe', 'url' => $f->getAttribute('src')];
    }
    foreach ($xp->query('//video') as $v) {
        $src = $v->getAttribute('src');
        if (!$src) { $s = $xp->query('.//source', $v)->item(0); if ($s) $src = $s->getAttribute('src'); }
        if ($src) $videos[] = ['type' => 'video', 'url' => $abs($src)];
    }

    $nav_links = [];
    foreach ($xp->query('//nav//a | //header//a') as $a) {
        $t = trim($a->textContent);
        if ($t && mb_strlen($t) < 40) $nav_links[] = $t;
        if (count($nav_links) >= 12) break;
    }

    return [
        'url' => $final_url, 'origin' => $origin, 'title' => $title, 'description' => $desc,
        'logo' => $logo, 'colors' => $colors, 'h1' => $h1,
        'h2' => array_slice($h2, 0, 12), 'h3' => array_slice($h3, 0, 12),
        'paragraphs' => array_slice($paras, 0, 10), 'images' => array_slice($images, 0, 16),
        'videos' => $videos, 'nav_links' => array_values(array_unique($nav_links)), 'html_length' => strlen($html),
    ];
}

function scrape_homepage(string $url, int $timeout = 25): array {
    if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
    $r = ww_http_get_many([$url], $timeout)[$url] ?? null;
    if (!$r || $r['html'] === '' || $r['http'] >= 400) {
        throw new Exception("Scrape failed (" . ($r['http'] ?? 0) . ") for {$url}");
    }
    return scrape_parse($r['html'], $r['final_url']);
}

function scrape_multi(string $url): array {
    $home = scrape_homepage($url);
    $origin = rtrim($home['origin'], '/');
    $extra_paths = ['/about','/services','/products','/work','/case-studies','/portfolio','/team'];

    $seen_images = [];
    foreach ($home['images'] ?? [] as $i) $seen_images[ww_normalize_image_url($i['url'])] = true;

    // Fetch all candidate subpages CONCURRENTLY.
    $urls = array_map(fn($p) => $origin . $p, $extra_paths);
    $fetched = ww_http_get_many($urls, 10);

    $extras = [];
    foreach ($urls as $u) {
        $f = $fetched[$u] ?? null;
        if (!$f || $f['html'] === '' || $f['http'] >= 400) continue;
        try {
            $sub = scrape_parse($f['html'], $f['final_url']);
        } catch (Throwable $e) { continue; }
        if (empty($sub['paragraphs']) || count($sub['paragraphs']) <= 1) continue;
        foreach ($sub['images'] ?? [] as $img) {
            $key = ww_normalize_image_url($img['url']);
            if (!isset($seen_images[$key])) { $seen_images[$key] = true; $home['images'][] = $img; }
        }
        $extras[] = [
            'path' => parse_url($u, PHP_URL_PATH), 'title' => $sub['title'],
            'h1' => $sub['h1'], 'h2' => array_slice($sub['h2'], 0, 6),
            'paragraphs' => array_slice($sub['paragraphs'], 0, 4),
        ];
        if (count($extras) >= 3) break;
    }
    $home['extra_pages'] = $extras;

    usort($home['images'], function($a, $b) {
        $score = fn($x) => (!empty($x['is_logo'])?5:0) + (!empty($x['is_thumb'])?2:0) + (!empty($x['is_team_card'])?4:0);
        return $score($a) <=> $score($b);
    });

    // Validate images load (drop broken/404 before they reach the model).
    $home['images'] = ww_filter_live_images($home['images'], 24, 6);
    $home['images'] = array_slice($home['images'], 0, 20);
    return $home;
}
