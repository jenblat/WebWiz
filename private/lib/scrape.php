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
    if (preg_match('~-(\d{2,4})[wh]\.(png|jpe?g|webp|gif)~i', $u, $m)) {
        if ((int)$m[1] < 1000) {
            return preg_replace('~-\d{2,4}[wh]\.(png|jpe?g|webp|gif)~i', '-1920w.$1', $u, 1) ?? $u;
        }
    }
    return $u;
}

function ww_image_is_thumb(string $u): bool {
    if (preg_match('~-(\d{1,3})[wh]\.~', $u, $m)) return (int)$m[1] < 250;
    return false;
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
function ww_http_get_many(array $urls, int $timeout = 12): array {
    if (!$urls) return [];
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $u) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; WebWizBot/1.0; +https://trywebwiz.com)',
        ]);
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
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; WebWizBot/1.0)',
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
            $src = $img->getAttribute('src') ?: $img->getAttribute('data-src');
            if ($src) { $logo = $abs($src); break; }
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
        $src = $img->getAttribute('src') ?: $img->getAttribute('data-src') ?: $img->getAttribute('data-lazy-src');
        if (!$src) continue;
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
            'is_cutout' => $is_cutout, 'is_portrait' => $is_portrait,
        ];
        if (count($images) >= 30) break;
    }
    usort($images, function($a, $b) {
        $score = fn($x) => ($x['is_logo']?5:0) + ($x['is_thumb']?2:0) + ($x['is_team_card']?4:0);
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
