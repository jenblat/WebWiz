<?php
// /var/www/sites/trywebwiz/private/lib/qa.php
// Visual QA: render a live URL to a full-page screenshot via DataForSEO, inspect with a vision model.
declare(strict_types=1);

function ww_dfs_cred(): ?string {
    $s = ww_secrets();
    $u = $s['DATAFORSEO_LOGIN'] ?? ''; $p = $s['DATAFORSEO_PASSWORD'] ?? '';
    return ($u && $p) ? "$u:$p" : null;
}

function ww_dfs_post(string $path, array $payload, string $cred, int $timeout = 60): ?array {
    $ch = curl_init("https://api.dataforseo.com/v3/$path");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERPWD => $cred, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $raw = curl_exec($ch); curl_close($ch);
    if ($raw === false) return null;
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

function ww_dfs_get(string $path, string $cred, int $timeout = 30): ?array {
    $ch = curl_init("https://api.dataforseo.com/v3/$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_USERPWD => $cred]);
    $raw = curl_exec($ch); curl_close($ch);
    if ($raw === false) return null;
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

/**
 * Render multiple URLs to PNG bytes using LOCAL headless Chrome (puppeteer-core).
 * Renders all variants in PARALLEL, waiting for network idle + all images loaded. No external cost.
 * $urls = [key => url]. Returns [key => ?pngbytes].
 */
function ww_render_screenshots(array $urls, ?int $job_id = null): array {
    $out = array_fill_keys(array_keys($urls), null);
    if (!$urls) return $out;
    $node   = trim((string)@shell_exec('command -v node')) ?: '/usr/bin/node';
    $script = '/var/www/sites/trywebwiz/private/qa-tools/shot.js';
    if (!is_file($script)) return $out;
    $files = []; $parts = [];
    foreach ($urls as $k => $u) {
        $f = sys_get_temp_dir() . '/wwshot_' . getmypid() . '_' . preg_replace('/[^A-Za-z0-9]/', '', (string)$k) . '_' . mt_rand(1000, 9999) . '.png';
        $files[$k] = $f;
        $parts[] = escapeshellarg($node) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($u) . ' ' . escapeshellarg($f) . ' >/dev/null 2>&1';
    }
    $run = function(array $items) {
        if (!$items) return;
        $p = [];
        foreach ($items as $f => $u) {
            $p[] = escapeshellarg($GLOBALS['__ww_node']) . ' ' . escapeshellarg($GLOBALS['__ww_shot']) . ' ' . escapeshellarg($u) . ' ' . escapeshellarg($f) . ' >/dev/null 2>&1';
        }
        $cmd = 'export HOME=/tmp/crhome; mkdir -p /tmp/crhome; ' . implode(' ; ', $p);
        @shell_exec('timeout 160 bash -c ' . escapeshellarg($cmd));
    };
    $GLOBALS['__ww_node'] = $node; $GLOBALS['__ww_shot'] = $script;
    // map output file => url
    $job1 = []; foreach ($urls as $k => $u) $job1[$files[$k]] = $u;
    $run($job1);
    // retry any that produced no/empty file
    $retry = [];
    foreach ($files as $k => $f) { if (!(is_file($f) && filesize($f) > 1000)) $retry[$f] = $urls[$k]; }
    if ($retry) { $run($retry); }
    foreach ($files as $k => $f) {
        if (is_file($f) && filesize($f) > 1000) $out[$k] = file_get_contents($f);
        @unlink($f);
    }
    return $out;
}

/** Slice tall PNG into high-res vertical JPEG segments (base64) for vision. Returns list of ['data','media_type']. */
function ww_png_to_vision_slices(string $png, int $width = 1080, int $sliceH = 1400, int $maxSlices = 6): array {
    $im = @imagecreatefromstring($png);
    if (!$im) return [];
    $w = imagesx($im); $h = imagesy($im);
    // scale to target width
    if ($w !== $width) {
        $scale = $width / $w;
        $nw = $width; $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im); $im = $dst; $w = $nw; $h = $nh;
    }
    $slices = [];
    $n = max(1, (int)ceil($h / $sliceH));
    if ($n > $maxSlices) { $sliceH = (int)ceil($h / $maxSlices); $n = $maxSlices; }
    for ($i = 0; $i < $n; $i++) {
        $y = $i * $sliceH;
        $sh = min($sliceH, $h - $y);
        if ($sh <= 0) break;
        $seg = imagecreatetruecolor($w, $sh);
        imagecopy($seg, $im, 0, 0, 0, $y, $w, $sh);
        ob_start(); imagejpeg($seg, null, 85); $jpg = ob_get_clean(); imagedestroy($seg);
        if ($jpg) $slices[] = ['data' => base64_encode($jpg), 'media_type' => 'image/jpeg'];
    }
    imagedestroy($im);
    return $slices;
}

/** Inspect one screenshot. Returns ['pass'=>bool,'score'=>int,'issues'=>[...],'summary'=>str]. */
function ww_visual_inspect(string $png, string $biz, ?int $job_id = null): array {
    $slices = ww_png_to_vision_slices($png);
    if (!$slices) return ['pass' => true, 'score' => -1, 'issues' => [], 'summary' => 'render-unavailable'];
    $system = <<<TXT
You are a ruthless web-design QA reviewer for an agency that ships homepages to paying clients. You will receive several JPEG images that are VERTICAL SLICES of ONE full-page website screenshot, ordered top to bottom (slice 1 = very top, last slice = footer). Mentally stitch them into one page. Judge it as a picky human visitor would and find rendering defects that would embarrass us in front of the client.

CRITICAL defect types (ANY one => pass:false). BE STRICT - when unsure whether a missing/empty image is minor or critical, choose CRITICAL:
- empty_image_box: a rectangular region (gray, beige, white, or a flat brand-tint color) bigger than a small icon that contains NO photo or illustration - especially when it sits beside body text, fills a hero/about area, or has only a tiny text label floating in it. Do NOT excuse this as "whitespace", "minimalism", or "sparse". An empty box where a photo clearly belongs is ALWAYS critical.
- blank_thumbnail: a card in a grid (services, blog/insights, gallery, team) whose image area (usually the top of the card) is blank/white/flat with no real image.
- cut_off_person: a person's face/head/body sliced by a container edge or only partly visible. (A person legitimately split across two of MY slices does NOT count - judge the stitched page.)
- broken_image: a broken-image icon or obviously failed/garbled image.
- text_overflow: text clipped, cut off mid-word, or overflowing/colliding with other elements.
- overlap: elements overlapping so text is hard to read.
- placeholder_text: lorem ipsum, "TODO", or stand-in monogram letters used as a hero/feature image.

MINOR (do NOT fail): small alignment, spacing, padding, or contrast nits on elements that otherwise have real content.

Return ONLY strict JSON (no prose, no code fences):
{"pass": true|false, "score": 0-100, "issues":[{"type":"<type>","severity":"critical"|"minor","where":"short location","fix":"concrete instruction"}], "summary":"one sentence"}
pass MUST be false if there is at least one critical issue.
TXT;
    $user = "Business: {$biz}. These " . count($slices) . " images are top-to-bottom slices of one homepage. Return the JSON verdict for the whole page.";
    try {
        $r = anthropic_vision('claude-sonnet-4-6', $system, $user, $slices, 1400, 0.0, $job_id);
    } catch (Throwable $e) {
        return ['pass' => true, 'score' => -1, 'issues' => [], 'summary' => 'inspect-error'];
    }
    $txt = $r['text'] ?? '';
    if (preg_match('/\{[\s\S]*\}/', $txt, $m)) $txt = $m[0];
    $j = json_decode($txt, true);
    if (!is_array($j)) return ['pass' => true, 'score' => -1, 'issues' => [], 'summary' => 'unparseable'];
    $issues = is_array($j['issues'] ?? null) ? $j['issues'] : [];
    $crit = array_filter($issues, fn($i) => (($i['severity'] ?? '') === 'critical'));
    return [
        'pass'    => empty($crit),
        'score'   => (int)($j['score'] ?? 0),
        'issues'  => array_values($issues),
        'summary' => (string)($j['summary'] ?? ''),
    ];
}

/** Build a regeneration-feedback string from critical issues. */
function ww_qa_feedback(array $issues): string {
    $lines = [];
    foreach ($issues as $i) {
        if (($i['severity'] ?? '') !== 'critical') continue;
        $lines[] = '- [' . ($i['type'] ?? 'issue') . ' @ ' . ($i['where'] ?? '?') . '] ' . ($i['fix'] ?? $i['desc'] ?? '');
    }
    if (!$lines) return '';
    return "VISUAL QA caught these defects in your previous render. FIX every one:\n" . implode("\n", $lines) .
        "\nHARD RULES: never output an empty/gray placeholder box for a missing image (remove that slot or use a real provided image instead); never crop a person; if you lack enough images for a card grid, use fewer cards rather than leaving blank image areas; keep all text inside its container.";
}

/** Pre-fetch every /api/img.php URL in the HTML (server-side, parallel) to warm the disk cache before rendering. */
function ww_prewarm_images(string $html, string $origin = 'https://trywebwiz.com'): int {
    if (!preg_match_all('~(/api/img\.php\?[^"\'\s>]+)~', $html, $m)) return 0;
    $urls = array_values(array_unique($m[1]));
    if (!$urls) return 0;
    $mh = curl_multi_init();
    $hs = [];
    foreach ($urls as $u) {
        $full = $origin . html_entity_decode($u, ENT_QUOTES);
        $ch = curl_init($full);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>60, CURLOPT_SSL_VERIFYPEER=>false]);
        curl_multi_add_handle($mh, $ch); $hs[] = $ch;
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 2.0); } while ($run && $st === CURLM_OK);
    foreach ($hs as $ch) { curl_multi_remove_handle($mh, $ch); curl_close($ch); }
    curl_multi_close($mh);
    return count($urls);
}
