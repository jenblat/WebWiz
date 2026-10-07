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
- cut_off_person: a person's face/head/body sliced by a container edge or only partly visible - INCLUDING the top of a head, forehead, hair, or chin clipped at the TOP or bottom edge of a hero image or full-bleed background band (this applies to background band photos, not just cards). If a face in a hero/band is missing the crown of the head or is awkwardly framed against the container edge, that is CRITICAL. (A person legitimately split across two of MY slices does NOT count - judge the stitched page.)
- broken_logo: look CLOSELY at the top-left NAV LOGO. Flag critical if it is a garbled/pixelated/noisy blob, a solid or near-solid filled rectangle (e.g. an all-white or all-dark box) with no legible mark, a logo whose transparency has been flattened onto a clashing background color, or otherwise illegible/corrupted rather than a crisp wordmark or icon. A brand logo that does not read cleanly is embarrassing and ALWAYS critical.
- broken_image: a broken-image icon or obviously failed/garbled image.
- text_overflow: text clipped, cut off mid-word, or overflowing/colliding with other elements.
- overlap: elements overlapping so text is hard to read.
- placeholder_text: lorem ipsum, "TODO", or stand-in monogram letters used as a hero/feature image.
- icon_used_as_photo: a service card, product card, hero, or section illustration that's actually a flat icon, clipart, or symbol (single-color shapes, transparent or solid background, no real photography, looks vector-traced). A house icon with a checkmark, a clipboard graphic, a pixelated cartoon, an SVG-style flat illustration used IN PLACE of an actual photograph is ALWAYS critical. Card grids in particular must use real photography, not icons.
- low_resolution_image: an image that's so pixelated or compressed that the texture/objects are mushy, especially when shown at hero or large-card size. Even if the SUBJECT is correct, a 200px image stretched to 600px wide is ALWAYS critical.
- dead_section: a section introduced by a heading or eyebrow label (e.g. "Our Work", "Insights", "Results", "Team", "Reviews") that is then followed by a large empty band with no cards/images/text beneath it - a heading with nothing under it is ALWAYS critical. Also flag ANY near-full-width band taller than half a slice that is a single flat color (black, white, beige, brand-tint) with essentially no content; do NOT excuse it as breathing room or minimalism.

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
        "\nHARD RULES:\n"
        . "- Never output an empty/gray placeholder box for a missing image. Remove that slot OR replace with a /api/genimg.php URL using a SPECIFIC photorealistic prompt.\n"
        . "- For ANY 'icon_used_as_photo' defect: replace that <img src> with /api/genimg.php?prompt=<URL-encoded photorealistic scene description matching the slot's purpose>&ar=4:3. Do NOT reuse the same icon URL. Examples: for a 'Pre-Listing Inspection' card use /api/genimg.php?prompt=professional%20home%20inspector%20with%20clipboard%20examining%20house%20exterior%2C%20photorealistic&ar=4:3 — never reuse the original icon-style URL.\n"
        . "- For ANY 'low_resolution_image' defect: replace with /api/genimg.php at a larger aspect ratio. The original was too small to use.\n"
        . "- Never crop a person; if you lack enough images for a card grid, use fewer cards rather than leaving blank image areas.\n"
        . "- Keep all text inside its container; every section with a heading MUST have visible content beneath it — never leave a heading followed by empty space.\n"
        . "- For ANY broken_logo defect: reference the nav logo with the PLAIN /api/img.php?u=<logo>&l=<name> URL with NO &up=1 param (upscaling flattens transparent logos into blobs), or fall back to a clean text wordmark of the business name. Never upscale a logo.\n"
        . "- For ANY cut_off_person defect in a hero or full-bleed band: set object-position:center top and make the band taller (>=60vh hero / >=460px band) so the whole head is visible, OR move that portrait into a framed portrait card (aspect 3/4-4/5, object-position center top) with the name beneath. Never leave a head clipped by the container edge.";
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

/* ---- Showcase screenshots: a real stored JPG of the generated site (replaces flaky mShots) ---- */

/**
 * Capture via screenshotmachine.com (paid PAYG, ~$0.002/shot). Returns true on success.
 * Far faster than local Chrome (parallel HTTP vs single-host CPU contention).
 */
function ww_capture_showcase_smc(string $token): bool {
    $s = ww_secrets();
    $key = (string)($s['SCREENSHOTMACHINE_KEY'] ?? '');
    if ($key === '') return false;
    $base = '/var/www/sites/trywebwiz/public/preview/' . $token;
    if (!is_dir($base . '/v1')) return false;
    $url = 'https://trywebwiz.com/preview/' . $token . '/v1/index.html?sc=' . time();
    $out = $base . '/showcase.jpg';
    $api = 'https://api.screenshotmachine.com/?' . http_build_query([
        'key'        => $key,
        'url'        => $url,
        'dimension'  => '1280x820',
        'device'     => 'desktop',
        'format'     => 'jpg',
        'cacheLimit' => 0,
        'delay'      => 1500,
    ]);
    $ch = curl_init($api);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $bin  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($bin) || strlen($bin) < 4000) return false;
    // SMC returns small JPEGs containing an error message on failure — guard
    // with JPEG SOI signature (0xFF 0xD8 0xFF) before writing.
    if (substr($bin, 0, 3) !== chr(0xFF) . chr(0xD8) . chr(0xFF)) return false;
    return (bool)@file_put_contents($out, $bin);
}

/**
 * Fallback: local headless Chrome via private/qa-tools/showcase.js.
 *
 * Invoked by absolute path, NOT via `cd` into qa-tools. The old `cd ... && node
 * showcase.js` form failed silently-but-noisily for every job: `cd` writes to
 * the shell's own stderr (the `2>&1` only ever applied to node), so worker.log
 * filled with bare `sh: 1: cd: can't cd to .../private/qa-tools` lines while
 * this function just returned false with no explanation. The cd bought nothing
 * — showcase.js requires only node built-ins, no node_modules resolution — and
 * private/ is 750 www-data, so any caller not running as www-data cannot
 * traverse it. ww_render_screenshots() above already uses the absolute form.
 */
function ww_capture_showcase_local(string $token): bool {
    $base = '/var/www/sites/trywebwiz/public/preview/' . $token;
    if (!is_dir($base . '/v1')) return false;
    $script = '/var/www/sites/trywebwiz/private/qa-tools/showcase.js';
    if (!is_readable($script)) {
        // Almost always "wrong user": private/ is 750 www-data.
        echo "[showcase] local capture unavailable: cannot read {$script} as uid " . getmyuid() . "\n";
        return false;
    }
    $url  = 'file:///var/www/sites/trywebwiz/public/preview/' . $token . '/v1/index.html';
    $out  = $base . '/showcase.jpg';
    $node = trim((string)@shell_exec('command -v node')) ?: '/usr/bin/node';
    $cmd  = 'timeout 35 ' . escapeshellarg($node) . ' ' . escapeshellarg($script) . ' '
          . escapeshellarg($url) . ' ' . escapeshellarg($out) . ' 2>&1';
    $o = []; $rc = 0;
    @exec($cmd, $o, $rc);
    $ok = is_file($out) && filesize($out) > 1500;
    if (!$ok) echo "[showcase] local capture rc={$rc}: " . substr(trim(implode(' ', $o)), 0, 200) . "\n";
    return $ok;
}

function ww_capture_showcase(string $token): bool {
    // SMC first (fast, off-droplet). Falls back to local Chrome if SMC fails.
    if (ww_capture_showcase_smc($token)) return true;
    return ww_capture_showcase_local($token);
}
function ww_generate_missing_showcases(PDO $db, int $limit = 20, int $time_budget_sec = 150): void {
    // Pull a generous pool of ready jobs (oldest first so older uploads catch up). Filesystem check
    // inside the loop skips ones already done, so this happily walks until budget or limit is hit.
    $rows = $db->query("SELECT id, token FROM jobs WHERE status IN ('ready','sent','picked') AND token IS NOT NULL ORDER BY id ASC LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);
    $n = 0; $start = time();
    foreach ($rows as $r) {
        if ($n >= $limit) break;
        if (time() - $start > $time_budget_sec) { echo "[showcase] time budget hit at $n captures\n"; break; }
        $base = '/var/www/sites/trywebwiz/public/preview/' . $r['token'];
        if (is_file($base . '/showcase.jpg') && filesize($base . '/showcase.jpg') > 1500) continue;
        if (!is_dir($base . '/v1')) continue;
        if (ww_capture_showcase($r['token'])) { echo "[showcase] job #{$r['id']} captured\n"; $n++; }
        else echo "[showcase] job #{$r['id']} capture failed\n";
    }
}

/* =====================================================================================
 * The audit gate (2026-10-07): private/qa-tools/audit.js renders a variant in headless
 * Chrome at 1440px and 390px, scrolls it one step per painted frame, and runs 60+
 * checks (structure, copy truth, design, motion, mobile, performance, accessibility)
 * plus the four WebWiz truth checks that need the prospect's scrape: invented-contact,
 * invented-stat, image-source, kit-present. compareSites() runs the same audit on the
 * prospect's current site and composes the side by side JPEGs the reveal page shows.
 *
 * Everything here shells out to node. One Chrome at a time is enforced inside the
 * tools (qa-tools/chromelock.js), so the worker, the live build and an admin run can
 * all call this without saturating the two cores.
 * ===================================================================================== */

const WW_AUDIT_TOOL      = __DIR__ . '/../qa-tools/audit.js';   // resolves inside whichever checkout is running
const WW_AUDIT_CACHE_DIR = '/var/www/sites/trywebwiz/data/audit-cache';
const WW_PREVIEW_DIR     = '/var/www/sites/trywebwiz/public/preview';

/** Settings for the gate, defaults applied. */
function ww_audit_settings(PDO $db): array {
    return [
        'enabled'   => ww_setting($db, 'audit_enabled', '1') === '1',
        'min_score' => max(0, min(100, (int)ww_setting($db, 'audit_min_score', '85'))),
        'block'     => ww_setting($db, 'audit_block_on_fail', '1') === '1',
        'compare'   => ww_setting($db, 'compare_enabled', '1') === '1',
    ];
}

function ww_audit_node(): string {
    static $node = null;
    if ($node === null) $node = trim((string)@shell_exec('command -v node')) ?: '/usr/bin/node';
    return $node;
}

/**
 * The facts the truth checks compare the page against: every email, phone, image URL
 * and piece of text the scrape produced, plus anything the owner typed (describe mode),
 * which is the other authoritative source.
 */
function ww_audit_facts(array $scrape, string $owner_text = ''): array {
    $text = [];
    foreach (['title', 'description'] as $k) if (!empty($scrape[$k])) $text[] = (string)$scrape[$k];
    foreach (['h1', 'h2', 'h3', 'paragraphs', 'nav_links', 'emails', 'phones'] as $k) foreach ((array)($scrape[$k] ?? []) as $t) $text[] = (string)$t;
    foreach ((array)($scrape['extra_pages'] ?? []) as $pg) {
        foreach (['title'] as $k) if (!empty($pg[$k])) $text[] = (string)$pg[$k];
        foreach (['h1', 'h2', 'paragraphs'] as $k) foreach ((array)($pg[$k] ?? []) as $t) $text[] = (string)$t;
    }
    foreach ((array)($scrape['images'] ?? []) as $i) if (!empty($i['alt'])) $text[] = (string)$i['alt'];
    if (!empty($scrape['text'])) $text[] = (string)$scrape['text'];
    if ($owner_text !== '') $text[] = $owner_text;
    $images = []; $logo = [];
    foreach ((array)($scrape['images'] ?? []) as $i) {
        if (empty($i['url'])) continue;
        $images[] = (string)$i['url'];
        if (!empty($i['is_logo'])) $logo[] = (string)$i['url'];
    }
    if (!empty($scrape['logo'])) { $logo[] = (string)$scrape['logo']; $images[] = (string)$scrape['logo']; }
    return [
        'emails' => array_values((array)($scrape['emails'] ?? [])),
        'phones' => array_values((array)($scrape['phones'] ?? [])),
        'text'   => implode("\n", $text),
        'images' => array_values(array_unique($images)),
        'logo'   => array_values(array_unique($logo)),
        'url'    => (string)($scrape['url'] ?? ''),
    ];
}

/** Write the scrape's contact details onto the prospect row so the audit of any later variant has them. */
function ww_prospect_store_contacts(PDO $db, ?int $prospect_id, array $scrape): void {
    if (!$prospect_id) return;
    try {
        ww_db_write_retry(function () use ($db, $prospect_id, $scrape) {
            $db->prepare("UPDATE prospects SET contact_emails = ?, contact_phones = ? WHERE id = ?")
               ->execute([json_encode(array_values((array)($scrape['emails'] ?? []))), json_encode(array_values((array)($scrape['phones'] ?? []))), $prospect_id]);
            return true;
        });
    } catch (Throwable $e) { error_log('[ww_prospect_store_contacts] ' . $e->getMessage()); }
}

function ww_audit_tmp_dir(string $tag): string {
    $d = sys_get_temp_dir() . '/wwaudit_' . getmypid() . '_' . preg_replace('~[^A-Za-z0-9]~', '', $tag) . '_' . mt_rand(1000, 9999);
    @mkdir($d, 0700, true);
    return $d;
}

/**
 * Audit one URL. Returns the decoded audit (no screenshots) or null when the tool
 * could not run. $shots_dir receives audit-desktop.jpg / audit-mobile.jpg so a compare
 * right after can reuse them instead of rendering the page again.
 */
function ww_audit_run(string $url, ?array $facts = null, ?string $shots_dir = null, int $timeout = 170): ?array {
    if (!is_file(WW_AUDIT_TOOL)) { echo "[audit] tool missing: " . WW_AUDIT_TOOL . "\n"; return null; }
    $tmp = $shots_dir ?: ww_audit_tmp_dir('a');
    @mkdir($tmp, 0755, true);
    $json = $tmp . '/audit.json';   // the tool's full output stays here for a compare to reuse
    $cmd = 'timeout ' . (int)$timeout . ' ' . escapeshellarg(ww_audit_node()) . ' ' . escapeshellarg(WW_AUDIT_TOOL) . ' ' . escapeshellarg($url) . ' --json ' . escapeshellarg($json);
    if ($facts) { file_put_contents($tmp . '/facts.json', json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); $cmd .= ' --facts ' . escapeshellarg($tmp . '/facts.json'); }
    if ($shots_dir) { $cmd .= ' --shots ' . escapeshellarg($shots_dir); }
    $out = []; $rc = 0;
    @exec($cmd . ' 2>&1', $out, $rc);
    $res = is_file($json) ? json_decode((string)file_get_contents($json), true) : null;
    if ($shots_dir === null) { @unlink($json); @unlink($tmp . '/facts.json'); @rmdir($tmp); }
    if (!is_array($res) || !isset($res['checks'])) {
        echo "[audit] failed rc={$rc}: " . substr(trim(implode(' ', $out)), 0, 300) . "\n";
        return null;
    }
    // keep the page facts out of the DB column; the checks carry the detail
    unset($res['facts']);
    return $res;
}

/**
 * Audit a variant on disk by its public URL. Writes shots next to the json in a temp
 * dir and returns ['audit'=>array|null, 'json'=>path, 'shots'=>dir] for the compare.
 */
function ww_audit_variant(string $token, int $v, ?array $facts): array {
    $url = 'https://trywebwiz.com/preview/' . $token . '/v' . $v . '/index.html?audit=' . time();
    $dir = ww_audit_tmp_dir($token . 'v' . $v);
    $audit = ww_audit_run($url, $facts, $dir);
    return ['audit' => $audit, 'json' => $dir . '/audit.json', 'shots' => $dir, 'url' => $url];
}

function ww_audit_cleanup(array $run): void {
    foreach ((glob(($run['shots'] ?? '/nonexistent') . '/*') ?: []) as $f) @unlink($f);
    if (!empty($run['shots'])) @rmdir($run['shots']);
}

/**
 * Compare a variant with the prospect's current site. The current site's audit is
 * cached per URL for seven days (WW_AUDIT_CACHE_DIR) so three variants of one job do
 * not render it three times. Composites are saved as
 * public/preview/<token>/compare-v<N>-desktop.jpg and -mobile.jpg, and a small
 * compare-v<N>.json the reveal page and the nurture email read.
 */
function ww_compare_variant(string $token, int $v, string $their_url, ?array $facts, ?array $ours_run = null, int $timeout = 200): ?array {
    if (!is_file(WW_AUDIT_TOOL)) return null;
    if (!preg_match('~^https?://~i', $their_url)) $their_url = 'https://' . $their_url;
    $pdir = WW_PREVIEW_DIR . '/' . $token;
    if (!is_dir($pdir . '/v' . $v)) return null;
    @mkdir(WW_AUDIT_CACHE_DIR, 0750, true);
    $our_url = 'https://trywebwiz.com/preview/' . $token . '/v' . $v . '/index.html?audit=' . time();
    $tmp = ww_audit_tmp_dir('c' . $v);
    $json = $tmp . '/compare.json';
    $cache = WW_AUDIT_CACHE_DIR . '/' . sha1(strtolower(preg_replace('~^https?://(www\.)?~i', '', rtrim($their_url, '/')))) . '.json';
    $cmd = 'timeout ' . (int)$timeout . ' ' . escapeshellarg(ww_audit_node()) . ' ' . escapeshellarg(WW_AUDIT_TOOL) . ' --compare ' . escapeshellarg($our_url) . ' ' . escapeshellarg($their_url)
         . ' --json ' . escapeshellarg($json) . ' --shots ' . escapeshellarg($tmp) . ' --their-cache ' . escapeshellarg($cache);
    if ($facts) { file_put_contents($tmp . '/facts.json', json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); $cmd .= ' --facts ' . escapeshellarg($tmp . '/facts.json'); }
    if ($ours_run && !empty($ours_run['audit']) && is_file($ours_run['json']) && is_file($ours_run['shots'] . '/audit-desktop.jpg')) {
        $cmd .= ' --ours-json ' . escapeshellarg($ours_run['json']) . ' --ours-shots ' . escapeshellarg($ours_run['shots']);
    }
    $out = []; $rc = 0;
    @exec($cmd . ' 2>&1', $out, $rc);
    $res = is_file($json) ? json_decode((string)file_get_contents($json), true) : null;
    if (!is_array($res) || empty($res['verdict'])) {
        echo "[compare] failed rc={$rc}: " . substr(trim(implode(' ', $out)), 0, 300) . "\n";
        foreach ((glob($tmp . '/*') ?: []) as $f) @unlink($f); @rmdir($tmp);
        return null;
    }
    // move the composites into the preview dir (same owner as showcase.jpg: whoever runs this)
    $saved = [];
    foreach (['desktop', 'mobile'] as $k) {
        $src = $tmp . '/compare-' . $k . '.jpg';
        $dst = $pdir . '/compare-v' . $v . '-' . $k . '.jpg';
        if (is_file($src) && @rename($src, $dst)) { @chmod($dst, 0644); $saved[$k] = '/preview/' . $token . '/compare-v' . $v . '-' . $k . '.jpg?v=' . (@filemtime($dst) ?: time()); }
    }
    foreach ((glob($tmp . '/*') ?: []) as $f) @unlink($f); @rmdir($tmp);
    unset($res['files']);
    if (isset($res['ours_audit']['facts'])) unset($res['ours_audit']['facts']);
    $res['images'] = $saved;
    $res['at'] = gmdate('c');
    // the reveal page's copy of the result: scores, categories, plain facts, images
    $public = [
        'ours'    => ['score' => $res['ours']['score'] ?? null, 'verdict' => $res['ours']['verdict'] ?? null],
        'theirs'  => ['score' => $res['theirs']['score'] ?? null, 'url' => $their_url, 'host' => preg_replace('~^www\.~', '', (string)parse_url($their_url, PHP_URL_HOST))],
        'groups'  => $res['groups'] ?? [], 'winning' => $res['winning'] ?? [], 'losing' => $res['losing'] ?? [], 'tied' => $res['tied'] ?? [],
        'facts'   => array_slice((array)($res['facts'] ?? []), 0, 6),
        'verdict' => $res['verdict'], 'verdict_reason' => $res['verdict_reason'] ?? '',
        'images'  => $saved, 'at' => $res['at'], 'variant' => $v,
    ];
    @file_put_contents($pdir . '/compare-v' . $v . '.json', json_encode($public, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    @chmod($pdir . '/compare-v' . $v . '.json', 0644);
    return $res;
}

/** Hard rules per failing check id, appended to the regeneration feedback. */
function ww_audit_fix_hint(string $id): string {
    static $h = [
        'invented-contact'    => 'Use ONLY the addresses in contact_emails and the numbers in contact_phones from the source data, exactly as given. If those lists are empty, write no mailto: and no tel: at all and point the contact CTA at #contact or the current_url.',
        'invented-stat'       => 'Delete every number that is not in the source text. Do not round, estimate or "improve" a figure. A page with no statistics is fine; a page with one invented statistic is rejected.',
        'image-source'        => 'Copy image URLs verbatim from the images lists in the source data, use each URL once, and use /api/genimg.php for anything the source does not contain.',
        'kit-present'         => 'Do not remove or rewrite the <head>. The kit tags are injected for you.',
        'no-dashes'           => 'Replace every em dash and en dash in visible copy with a comma, a full stop or brackets. None may remain.',
        'no-template-phrases' => 'Rewrite those headlines so they say something only this business could say.',
        'no-placeholder'      => 'Remove placeholder text; every sentence must be real copy about this business.',
        'phone-link'          => 'If contact_phones has a number, render it as <a href="tel:...">. If it is empty, this check is satisfied by a contact form or a mailto to a scraped address.',
        'contact-path'        => 'Add a real way to contact the business: a mailto: to a scraped address or a short contact form.',
        'anchors-resolve'     => 'Every href="#id" must point at an element with that id on this page.',
        'no-empty-links'      => 'No href="#" and no javascript: links. Link to a real section id, the current_url, a tel: or a mailto:.',
        'no-dead-sections'    => 'Every section taller than 280px needs visible text or an image. Remove the empty band or fill it.',
        'images-load'         => 'Remove or replace every image that failed to load; never leave a broken image.',
        'real-imagery'        => 'Use at least four distinct real images from the source data.',
        'body-size'           => 'Body text must be 16px or larger.',
        'cta-above-fold'      => 'Put a visible, styled call to action in the first screen on desktop.',
        'contrast'            => 'Fix the listed text/background pairs to at least 4.5:1 (3:1 for 24px+ headings).',
        'motion-kit'          => 'Do not touch the kit tags in <head>.',
        'scroll-reveals'      => 'Add data-reveal to every section heading block and feature row, six or more in total.',
        'depth'               => 'Keep data-ambient on body, put data-parallax="0.12" on the hero image wrapper and data-bg-shift on two or three light sections.',
        'reduced-motion'      => 'Wrap any transition of your own in @media (prefers-reduced-motion: no-preference).',
        'nothing-stuck-hidden'=> 'Remove every opacity:0, visibility:hidden and hand-rolled entrance animation. Entrance motion comes only from the kit attributes.',
        'no-horizontal-scroll'=> 'Nothing may be wider than the viewport at 390px: no fixed widths, grids collapse to one column, images max-width:100%, long words wrap.',
        'tap-targets'         => 'Every link and button must be at least 40px tall and wide on a phone (padding, min-height).',
        'mobile-nav'          => 'Keep navigation reachable on a phone: visible nav links or a labelled menu button.',
        'mobile-cta'          => 'A call to action must be visible in the first phone screen (390x844) without scrolling.',
        'mobile-images-fit'   => 'Images must not exceed the phone width; give them max-width:100% inside a clipped wrapper.',
        'page-weight'         => 'Cut page weight: fewer images, no duplicate fonts, no base64 images.',
        'largest-image'       => 'Do not inline or reference an image heavier than 500 KB; pick a different source image.',
        'no-failed-requests'  => 'Remove every reference that fails to load (fonts, images, links to files that do not exist).',
        'alt-text'            => 'Every <img> needs a descriptive alt attribute.',
        'link-names'          => 'Every link needs visible text or an aria-label.',
        'one-h1'              => 'Exactly one <h1> on the page.',
        'title'               => 'Give the page a <title> of 15 to 70 characters naming the business.',
        'meta-description'    => 'Add a meta description of 50 to 160 characters.',
        'viewport'            => 'Include <meta name="viewport" content="width=device-width, initial-scale=1">.',
        'lang'                => 'Set lang="en" on <html>.',
        'doctype'             => 'Start with <!DOCTYPE html>.',
    ];
    return $h[$id] ?? '';
}

/** Regeneration feedback from a failed audit, each failing check id and detail verbatim. */
function ww_audit_feedback(?array $audit, int $min_score = 85): string {
    if (!$audit) return '';
    $lines = [];
    foreach ((array)($audit['checks'] ?? []) as $c) {
        if (($c['status'] ?? '') !== 'fail') continue;
        $lines[] = '- ' . $c['id'] . ': ' . $c['detail'];
        $hint = ww_audit_fix_hint((string)$c['id']);
        if ($hint !== '') $lines[] = '    FIX: ' . $hint;
    }
    $warns = [];
    foreach ((array)($audit['checks'] ?? []) as $c) if (($c['status'] ?? '') === 'warn') $warns[] = $c['id'] . ' (' . $c['detail'] . ')';
    $score = (int)($audit['score'] ?? 0);
    $txt = "AUTOMATED AUDIT of your previous render: score {$score}/100, needs {$min_score} or more with ZERO failing checks. It was rendered in Chrome at 1440px and 390px and scrolled.";
    if ($lines) $txt .= "\nFAILING CHECKS, fix every one:\n" . implode("\n", $lines);
    elseif ($score < $min_score) $txt .= "\nNo check failed outright but the score is below {$min_score}. Clear the warnings below to raise it.";
    if ($warns) $txt .= "\nWARNINGS (each earns half marks, clear as many as you can): " . implode('; ', array_slice($warns, 0, 12));
    $txt .= "\nKeep the SAME assigned art direction, the same images and the same source facts. Fix the defects, change nothing else, and output the complete document ending with </html>.";
    return $txt;
}

/** Regeneration feedback when the variant loses a category to the prospect's current site. */
function ww_compare_feedback(?array $cmp): string {
    if (!$cmp || empty($cmp['losing'])) return '';
    $their = (string)($cmp['theirs']['url'] ?? 'their current site');
    $os = (int)($cmp['ours']['score'] ?? 0); $ts = (int)($cmp['theirs']['score'] ?? 0);
    $txt = "COMPARED WITH THE PROSPECT'S CURRENT SITE ({$their}, score {$ts}) your page scored {$os} and LOSES these categories. It must win or tie every one:";
    foreach ((array)($cmp['what_theirs_does_better'] ?? []) as $g) {
        $grp = $cmp['groups'][$g['group']] ?? null;
        $txt .= "\n- " . ($g['label'] ?? $g['group']) . ($grp ? " (ours {$grp['ours']}, theirs {$grp['theirs']})" : '');
        foreach ((array)($g['their_passes'] ?? []) as $c) {
            $id = is_array($c) ? (string)$c['id'] : (string)$c;
            $ours = is_array($c) ? (string)($c['ours'] ?? '') : '';
            $txt .= "\n    their site passes " . $id . ($ours !== '' ? " and yours shows: " . $ours : '');
            $hint = ww_audit_fix_hint($id);
            if ($hint !== '') $txt .= "\n    FIX: " . $hint;
        }
    }
    $txt .= "\nKeep the SAME art direction, images and source facts. Fix those checks without regressing anything else.";
    return $txt;
}

/** Audit JSON for the DB: the checks and scores, never screenshots or page facts. */
function ww_audit_for_db(?array $audit): ?string {
    if (!$audit) return null;
    $keep = [
        'score' => $audit['score'] ?? null, 'verdict' => $audit['verdict'] ?? null, 'verdict_reason' => $audit['verdict_reason'] ?? null,
        'checks_run' => $audit['checks_run'] ?? count($audit['checks'] ?? []), 'fails' => $audit['fails'] ?? [], 'warns' => $audit['warns'] ?? [],
        'groups' => array_map(fn($g) => is_array($g) ? ($g['score'] ?? null) : $g, (array)($audit['groups'] ?? [])),
        'checks' => array_map(fn($c) => ['id' => $c['id'], 'group' => $c['group'], 'status' => $c['status'], 'detail' => mb_substr((string)$c['detail'], 0, 300)], (array)($audit['checks'] ?? [])),
        'duration_ms' => $audit['duration_ms'] ?? null, 'at' => gmdate('c'),
    ];
    return json_encode($keep, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function ww_compare_for_db(?array $cmp): ?string {
    if (!$cmp) return null;
    $keep = [
        'verdict' => $cmp['verdict'] ?? null, 'verdict_reason' => $cmp['verdict_reason'] ?? null,
        'ours' => $cmp['ours']['score'] ?? null, 'theirs' => $cmp['theirs']['score'] ?? null, 'their_url' => $cmp['theirs']['url'] ?? null,
        'groups' => $cmp['groups'] ?? [], 'winning' => $cmp['winning'] ?? [], 'losing' => $cmp['losing'] ?? [], 'tied' => $cmp['tied'] ?? [],
        'facts' => $cmp['facts'] ?? [], 'images' => $cmp['images'] ?? [], 'their_fails' => array_slice((array)($cmp['their_fails'] ?? []), 0, 20),
        'at' => $cmp['at'] ?? gmdate('c'),
    ];
    return json_encode($keep, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** Persist audit and compare results on the previews row, keyed by job token like ww_qa_persist(). */
function ww_audit_persist(PDO $db, string $token, int $variant_n, ?array $audit, ?array $cmp = null): bool {
    if ($token === '' || (!$audit && !$cmp)) return false;
    $sets = []; $vals = [];
    if ($audit) { $sets[] = 'audit_score = ?'; $vals[] = (int)($audit['score'] ?? 0); $sets[] = 'audit_verdict = ?'; $vals[] = (string)($audit['verdict'] ?? ''); $sets[] = 'audit_json = ?'; $vals[] = ww_audit_for_db($audit); }
    if ($cmp)   { $sets[] = 'compare_verdict = ?'; $vals[] = (string)($cmp['verdict'] ?? ''); $sets[] = 'compare_json = ?'; $vals[] = ww_compare_for_db($cmp); }
    $vals[] = $variant_n; $vals[] = $token;
    try {
        return (bool)ww_db_write_retry(function () use ($db, $sets, $vals) {
            $st = $db->prepare("UPDATE previews SET " . implode(', ', $sets) . " WHERE variant_n = ? AND job_id = (SELECT id FROM jobs WHERE token = ? ORDER BY id DESC LIMIT 1)");
            $st->execute($vals);
            return $st->rowCount() > 0;
        });
    } catch (Throwable $e) { error_log('[ww_audit_persist] ' . $e->getMessage()); return false; }
}

/**
 * Backfill for paths that cannot afford the audit inline (the batch pipeline writes
 * every row of a CSV upload inside one worker run). Audits ready previews that have no
 * audit yet, newest first, within a time budget, and flags the job needs_review when a
 * variant is not ready or loses to the current site. Nothing is regenerated here; a
 * human decides before a batch preview is emailed. Only jobs created after the gate
 * shipped are considered: older previews predate the kit and would all fail motion.
 */
function ww_audit_missing(PDO $db, int $limit = 2, int $time_budget_sec = 120): void {
    $s = ww_audit_settings($db);
    if (!$s['enabled']) return;
    $rows = $db->query(
        "SELECT p.id pid, p.variant_n, j.id jid, j.token, j.scrape_data, j.prospect_id, pr.current_url, pr.description
           FROM previews p JOIN jobs j ON j.id = p.job_id LEFT JOIN prospects pr ON pr.id = j.prospect_id
          WHERE p.audit_score IS NULL AND j.status IN ('ready','sent') AND j.qa_status = 'batch'
            AND j.created_at >= '2026-10-07' AND p.archived = 0
          ORDER BY p.id DESC LIMIT 40"
    )->fetchAll(PDO::FETCH_ASSOC);
    $n = 0; $start = time();
    foreach ($rows as $r) {
        if ($n >= $limit) break;
        if (time() - $start > $time_budget_sec) { echo "[audit] backfill time budget hit at {$n}\n"; break; }
        $token = (string)$r['token']; $v = (int)$r['variant_n'];
        if (!is_file(WW_PREVIEW_DIR . "/{$token}/v{$v}/index.html")) continue;
        $scrape = json_decode((string)($r['scrape_data'] ?? ''), true) ?: [];
        $facts = ww_audit_facts($scrape, (string)($r['description'] ?? ''));
        $run = ww_audit_variant($token, $v, $facts);
        $audit = $run['audit'];
        $cmp = null;
        if ($audit && $s['compare'] && !empty($r['current_url'])) $cmp = ww_compare_variant($token, $v, (string)$r['current_url'], $facts, $run);
        ww_audit_cleanup($run);
        if ($audit) ww_audit_persist($db, $token, $v, $audit, $cmp);
        $bad = ($audit && (($audit['verdict'] ?? '') !== 'preview-ready' || (int)$audit['score'] < $s['min_score'])) || ($cmp && ($cmp['verdict'] ?? '') !== 'beats-current-site');
        if ($bad) { try { $db->prepare("UPDATE jobs SET qa_status='needs_review' WHERE id=?")->execute([(int)$r['jid']]); } catch (Throwable $e) {} }
        echo "[audit] backfill job #{$r['jid']} v{$v}: " . ($audit ? "{$audit['verdict']} {$audit['score']}" : 'audit failed') . ($cmp ? ", compare {$cmp['verdict']}" : '') . "\n";
        $n++;
    }
}
