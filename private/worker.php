<?php
// /var/www/sites/trywebwiz/private/worker.php
// Cron: * * * * * sudo -u nobody php8.3 /var/www/sites/trywebwiz/private/worker.php
// Single-instance (flock). Drains multiple queued jobs per run within a time budget.
// Each job generates 3 variants CONCURRENTLY (anthropic_multi), retrying only failures.

declare(strict_types=1);
require '/var/www/sites/trywebwiz/private/webwiz_lib.php';
require '/var/www/sites/trywebwiz/private/lib/anthropic.php';
require '/var/www/sites/trywebwiz/private/lib/scrape.php';
require '/var/www/sites/trywebwiz/private/lib/qa.php';
require '/var/www/sites/trywebwiz/private/lib/batch.php';

set_time_limit(0);

const WORKER_MAX_RUN_SECONDS = 270;

$lock = fopen('/tmp/webwiz-worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "[worker] already running, exit\n";
    exit(0);
}

$db = ww_db();
$run_started = time();
$did = 0;

while ((time() - $run_started) < WORKER_MAX_RUN_SECONDS) {
    $row = $db->query(
        "SELECT * FROM jobs
         WHERE status = 'queued' AND COALESCE(generation_mode,'sync') <> 'batch' AND datetime(scheduled_for) <= datetime('now')
         ORDER BY id ASC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$row) { if ($did === 0) echo "[worker] no jobs\n"; break; }
    process_job($db, $row);
    $did++;
}

// Batch pipeline (all CSV uploads): build queued uploads (scrape+submit) and poll in-flight batches.
try { ww_build_batches($db); } catch (Throwable $e) { echo "[batch] build error: ".$e->getMessage()."\n"; }
try { ww_poll_batches($db); } catch (Throwable $e) { echo "[batch] poll error: ".$e->getMessage()."\n"; }
try { ww_generate_missing_showcases($db, 4); } catch (Throwable $e) { echo "[showcase] error: ".$e->getMessage()."\n"; }

flock($lock, LOCK_UN);
fclose($lock);
echo "[worker] run complete, processed {$did} job(s)\n";

function process_job(PDO $db, array $row): void {
    $job_id = (int)$row['id'];
    echo "[worker] job #{$job_id} starting\n";
    $db->prepare("UPDATE jobs SET status='running', started_at=datetime('now') WHERE id=?")->execute([$job_id]);
    $cap = (float)($db->query("SELECT value FROM settings WHERE key='job_max_cost_usd'")->fetchColumn() ?: 1.50);

    try {
        $prospect = null;
        if ($row['prospect_id']) {
            $st = $db->prepare("SELECT * FROM prospects WHERE id = ?");
            $st->execute([$row['prospect_id']]);
            $prospect = $st->fetch(PDO::FETCH_ASSOC);
        }
        $url      = $prospect['current_url'] ?? ($row['scrape_data'] ?? '');
        $biz      = $prospect['business_name'] ?? $row['business_name'] ?? 'Their Business';
        $industry = $prospect['industry'] ?? '';
        if (!$url) throw new Exception('No current_url available for this job');

        echo "[worker]  scraping {$url}\n";
        $scrape = scrape_multi($url);
        $db->prepare("UPDATE jobs SET scrape_data=? WHERE id=?")->execute([json_encode($scrape), $job_id]);

        $usable = array_values(array_filter($scrape['images'] ?? [], fn($i) =>
            empty($i['is_logo']) && empty($i['is_thumb']) && empty($i['is_team_card'])));
        echo "[worker]  scrape: " . count($scrape['images'] ?? []) . " live images, " . count($usable) . " usable\n";

        $model  = $row['model'] ?: 'claude-sonnet-4-6';
        $system = build_system_prompt($industry, count($usable));

        $reqs = [];
        for ($v = 1; $v <= 3; $v++) {
            $reqs[$v] = ['system' => $system, 'messages' => [['role' => 'user', 'content' => build_user_prompt($scrape, $biz, $industry, $v)]]];
        }
        echo "[worker]  generating 3 variants in parallel -> {$model}\n";
        $res = anthropic_multi($model, $reqs, 14000, 0.7, $job_id, ['</html>']);

        $total_cost = 0.0;
        $htmls = [];
        $retry = [];
        foreach ($reqs as $v => $_) {
            $total_cost += (float)($res[$v]['cost_usd'] ?? 0);
            $cand = finalize_html($res[$v]['text'] ?? '');
            $gate = $cand ? quality_gate($cand) : ['ok' => false, 'reason' => 'no usable HTML'];
            if ($gate['ok']) { $htmls[$v] = $cand; }
            else { $retry[$v] = $gate['reason']; echo "[worker]   variant {$v} round1 failed: {$gate['reason']}\n"; }
        }

        if ($retry && $total_cost < $cap) {
            $rreqs = [];
            foreach ($retry as $v => $reason) {
                $rreqs[$v] = ['system' => $system, 'messages' => [['role' => 'user',
                    'content' => build_user_prompt($scrape, $biz, $industry, $v) .
                        "\n\nIMPORTANT - your previous attempt failed quality gate: {$reason}. Keep it SHORT and COMPLETE: end with </html>, include an <h1>, a <footer>, 3+ <section> tags, and 4+ distinct /api/img.php images. Drop the FAQ if needed to finish."]]];
            }
            echo "[worker]  retrying " . count($rreqs) . " variant(s) in parallel\n";
            $rres = anthropic_multi($model, $rreqs, 14000, 0.5, $job_id, ['</html>']);
            foreach ($rreqs as $v => $_) {
                $total_cost += (float)($rres[$v]['cost_usd'] ?? 0);
                $cand = finalize_html($rres[$v]['text'] ?? '');
                if ($cand && quality_gate($cand)['ok']) { $htmls[$v] = $cand; unset($retry[$v]); }
            }
        }

        if (count($htmls) < 1) throw new Exception("no variants passed the quality gate");
        if (count($htmls) < 3) echo "[worker]  shipping " . count($htmls) . "/3 variants (others failed the gate)\n";

        $public_dir = '/var/www/sites/trywebwiz/public/preview/' . $row['token'];
        ksort($htmls);
        $write_variant = function (int $v, string $html) use ($public_dir, $row): void {
            $dir = $public_dir . '/v' . $v;
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            file_put_contents($dir . '/index.html', $html);
        };
        foreach ($htmls as $v => $html) { $write_variant($v, $html); }
        $stub = $public_dir . '/index.php';
        if (!is_file($stub)) {
            file_put_contents($stub, "<?php\n\$_GET['t'] = basename(__DIR__);\nrequire __DIR__ . '/../index.php';\n");
        }

        // ---------- VISUAL QA LOOP ----------
        $qa_enabled    = ((string)($db->query("SELECT value FROM settings WHERE key='visual_qa_enabled'")->fetchColumn()) === '1');
        $qa_max_retries= (int)($db->query("SELECT value FROM settings WHERE key='qa_max_retries'")->fetchColumn() ?: 2);
        $qa_block      = ((string)($db->query("SELECT value FROM settings WHERE key='qa_block_on_fail'")->fetchColumn()) === '1');
        $qa_results = [];
        if ($qa_enabled) {
            for ($round = 0; $round <= $qa_max_retries; $round++) {
                $urls = [];
                foreach ($htmls as $v => $_) {
                    $urls[$v] = 'https://trywebwiz.com/preview/' . $row['token'] . '/v' . $v . '/index.html?qa=' . time() . $round;
                }
                $warm = 0; foreach ($htmls as $_v => $_html) $warm += ww_prewarm_images($_html);
                echo "[worker]  QA round {$round}: warmed {$warm} images, rendering " . count($urls) . " variant(s)\n";
                $shots = ww_render_screenshots($urls, $job_id);
                $fails = [];
                foreach ($htmls as $v => $_) {
                    $png = $shots[$v] ?? null;
                    if (!$png) { $qa_results[$v] = ['pass'=>true,'score'=>-1,'issues'=>[],'summary'=>'render-failed']; echo "[worker]   v{$v}: render failed, skipping QA\n"; continue; }
                    $verdict = ww_visual_inspect($png, $biz, $job_id);
                    $qa_results[$v] = $verdict;
                    echo "[worker]   v{$v}: " . ($verdict['pass']?'PASS':'FAIL') . " score={$verdict['score']} - {$verdict['summary']}\n";
                    if (!$verdict['pass']) $fails[$v] = $verdict['issues'];
                }
                if (!$fails) break;
                if ($round >= $qa_max_retries) break;
                if ($total_cost >= $cap) { echo "[worker]  QA stop: cost cap reached\n"; break; }
                $rreqs = [];
                foreach ($fails as $v => $issues) {
                    $fb = ww_qa_feedback($issues);
                    $rreqs[$v] = ['system'=>$system, 'messages'=>[['role'=>'user','content'=>build_user_prompt($scrape, $biz, $industry, $v) . "\n\n" . $fb]]];
                }
                echo "[worker]  QA regenerating " . count($rreqs) . " variant(s)\n";
                $rres = anthropic_multi($model, $rreqs, 14000, 0.6, $job_id, ['</html>']);
                foreach ($rreqs as $v => $_) {
                    $total_cost += (float)($rres[$v]['cost_usd'] ?? 0);
                    $cand = finalize_html($rres[$v]['text'] ?? '');
                    if ($cand && quality_gate($cand)['ok']) { $htmls[$v] = $cand; $write_variant($v, $cand); }
                }
            }
            // block-on-fail: drop still-failing variants, but never drop to zero
            if ($qa_block) {
                $passing = [];
                foreach ($htmls as $v => $html) { if ($qa_results[$v]['pass'] ?? true) $passing[$v] = $html; }
                if ($passing && count($passing) < count($htmls)) {
                    foreach ($htmls as $v => $_) {
                        if (!($qa_results[$v]['pass'] ?? true)) {
                            foreach ((glob($public_dir . '/v' . $v . '/*') ?: []) as $gf) @unlink($gf);
                            @rmdir($public_dir . '/v' . $v);
                            echo "[worker]   v{$v}: dropped (failed QA after retries)\n";
                        }
                    }
                    $htmls = $passing;
                }
            }
        }

        $any_fail = false;
        foreach ($htmls as $v => $_) { if (!($qa_results[$v]['pass'] ?? true)) $any_fail = true; }
        $qa_status = !$qa_enabled ? 'disabled' : ($any_fail ? 'needs_review' : 'passed');

        ksort($htmls);
        foreach ($htmls as $v => $html) {
            $rel = '/preview/' . $row['token'] . '/v' . $v . '/index.html';
            $q = $qa_results[$v] ?? null;
            $db->prepare("INSERT INTO previews (job_id, variant_n, html_path, qa_score, qa_pass, qa_issues) VALUES (?, ?, ?, ?, ?, ?)")
               ->execute([$job_id, $v, $rel, $q['score'] ?? null, isset($q['pass']) ? ($q['pass']?1:0) : null, $q ? json_encode($q['issues']) : null]);
        }
        $db->prepare("UPDATE jobs SET status='ready', completed_at=datetime('now'), total_cost_cents=?, qa_status=? WHERE id=?")
           ->execute([(int)round($total_cost * 100), $qa_status, $job_id]);
        echo "[worker] job #{$job_id} ready ({$qa_status}), " . count($htmls) . " variant(s), cost \$" . number_format($total_cost, 4) . "\n";

    } catch (Throwable $e) {
        $msg = $e->getMessage();
        echo "[worker] job #{$job_id} FAILED: {$msg}\n";
        $db->prepare("UPDATE jobs SET status='failed', error=?, completed_at=datetime('now') WHERE id=?")
           ->execute([substr($msg, 0, 500), $job_id]);
    }
}

function finalize_html(string $text): ?string {
    $cand = extract_html($text);
    if (!$cand || stripos($cand, '<html') === false) return null;
    if (stripos($cand, '</html>') === false) $cand = rtrim($cand) . "\n</html>";
    // Force EAGER image loading: screenshot renderers (and full-page screenshots) do not scroll,
    // so loading="lazy" leaves below-the-fold images unloaded = blank boxes. Strip it.
    $cand = preg_replace('/\s*loading\s*=\s*([\x27"])lazy\1/i', '', $cand);
    // FAILSAFE REVEAL: generated pages use IntersectionObserver entrance animations
    // (.fade-up/.fade-in start at opacity:0). The observer is unreliable - in screenshots,
    // inside the preview iframe, and for users who don't scroll - leaving whole sections
    // permanently invisible (the "empty section" / blank-box defect). Inject a guaranteed
    // reveal so no content is ever stuck hidden. The observer still gives the staggered
    // effect for users who scroll within the first ~1.1s; this only rescues the rest.
    $failsafe = "\n<script>/*ww-reveal-failsafe*/(function(){function r(){try{var e=document.querySelectorAll('.fade-up,.fade-in,.reveal,[data-reveal],.animate,.scroll-reveal');for(var i=0;i<e.length;i++){e[i].classList.add('visible','active','in-view','show');e[i].style.opacity='1';e[i].style.transform='none';e[i].style.visibility='visible';}}catch(x){}}var d=false;function g(){if(d)return;d=true;r();}window.addEventListener('load',function(){setTimeout(g,1100);});document.addEventListener('DOMContentLoaded',function(){setTimeout(g,2200);});setTimeout(g,3500);})();</script>\n";
    if (stripos($cand, '</body>') !== false) {
        $cand = preg_replace('/<\/body>/i', $failsafe . '</body>', $cand, 1);
    } else {
        $cand .= $failsafe;
    }
    return $cand;
}

function quality_gate(string $html): array {
    if (!preg_match('/<h1[\s>]/i', $html)) return ['ok' => false, 'reason' => 'missing <h1>'];
    preg_match_all('~/api/img\.php\?u=([^"&\s]+)~i', $html, $m);
    $distinct = array_unique($m[1] ?? []);
    if (count($distinct) < 4) return ['ok' => false, 'reason' => 'only ' . count($distinct) . ' distinct images (need 4+)'];
    if (!preg_match('/<footer[\s>]/i', $html)) return ['ok' => false, 'reason' => 'missing <footer>'];
    $sections = preg_match_all('/<section[\s>]/i', $html);
    if ($sections < 3) return ['ok' => false, 'reason' => "only {$sections} <section> tags (need 3+)"];
    return ['ok' => true, 'reason' => ''];
}

function extract_html(string $text): ?string {
    $text = trim($text);
    if (preg_match('/```(?:html)?\s*([\s\S]+?)```/i', $text, $m)) $text = trim($m[1]);
    if (preg_match('/<(?:!doctype|html)[\s\S]+/i', $text, $m)) $text = $m[0];
    if (stripos($text, '<html') === false) return null;
    return $text;
}

function build_system_prompt(string $industry, int $usable_img_count): string {
    $img_note = $usable_img_count >= 4
        ? "Source has {$usable_img_count} usable content images - use at least 4 distinct ones."
        : "Source has only {$usable_img_count} usable content images - supplement with thumbnail-tagged images to reach 4+ distinct images.";
    return <<<TXT
You are WebWiz, an elite designer building single-page marketing sites that rival Apple, Patagonia, Stripe, Linear, Aesop.

OUTPUT
Return ONLY a complete HTML5 document, no markdown, no commentary, no code fences. First character `<`, last character `>`. Must include <!DOCTYPE html>, <html>, <head>, <body>, end with </html>. Target ~5000 tokens.

ABSOLUTE RULES
1. ALL CONTENT VISIBLE AT FIRST PAINT. No opacity:0 or visibility:hidden without a guaranteed CSS-only reveal. No JS-gated reveals.
2. ENTRANCE ANIMATIONS WRAPPED IN @media (prefers-reduced-motion: no-preference). Outside that, elements at final state.
3. HTML COMPLETE - TOP PRIORITY. Close every tag and END WITH </html>. If running long, SHORTEN copy + CSS and DROP the FAQ section, but NEVER omit the <footer> or leave the document unclosed. A complete ~5000-token page beats a richer page that gets cut off. Keep CSS compact (group selectors, no redundant rules).
4. IMAGES ARE MANDATORY. Use a MINIMUM of 4 DISTINCT images via the proxy. Every image MUST be wrapped exactly like:
   <img src="/api/img.php?u=<URL-ENCODED-original>&l=<URL-ENCODED-short-label>" alt="...">  (do NOT add loading="lazy" - all images must load eagerly)
5. {$img_note}
6. EVERY image URL you use MUST come from the provided source data (images.photo / images.cutout / images.thumbnail / images.logo). All provided URLs are verified to load. Do NOT invent URLs. Do NOT reuse a URL twice.

IMAGE FRAMING - clients reject cropped people:
- LANDSCAPE PHOTOS (images.photo): scenes, offices, products. Container with aspect-ratio + overflow:hidden + object-fit:cover; object-position:center. Fine as hero/feature/full-bleed.
- CUTOUT / PORTRAIT / PERSON images (images.cutout, or anyone on a transparent/plain background): NEVER crop. Render with object-fit:contain inside a fixed-height box (e.g. height:420px) with a soft brand-tint/neutral background and padding, so the whole person is visible. NEVER use a cutout person as a full-bleed hero. NEVER put a face in a tight 1:1 or 16/9 cover crop.
- Hero: prefer a LANDSCAPE photo. If none exists, use a CSS gradient/SVG hero and put people photos lower in framed cards.

NO EMPTY SPACE / NO EMPTY IMAGE BOXES (clients reject these instantly)
- Every section must contain visible content. Never leave a section taller than ~40vh with nothing in it.
- NEVER create an image slot, card thumbnail, or photo box you cannot fill with a REAL provided image URL. An empty or solid-color/gray/tinted rectangle where a photo belongs is an AUTOMATIC REJECTION.
- If you do not have enough distinct images for a layout (e.g. a 3-card services/insights/blog grid, or an about/team photo), then REDESIGN that section to need fewer images, or make it text/icon/stat based, or drop it. Fewer cards with real images beats more cards with blank image areas.
- Do NOT build a "latest articles / insights / blog / news" card grid with image thumbnails unless you have a distinct real image for EVERY card.
- The founder/CEO/about photo is optional: only include a person photo if a real provided image exists for it; otherwise use a text-forward about block. Never leave a labeled-but-empty portrait frame.

HEADER
- Sticky top nav: business name/logo left, 3-5 nav links (use scraped nav_links), 1-2 right-aligned CTAs.
- CTA TEXT MUST MATCH THE BUSINESS. NEVER "Sign In"/"Log In" unless the source clearly has an authenticated product.
  Inference: Agency -> "Book a Call"/"Get a Quote"; Restaurant -> "Reserve a Table"/"Order Online"; Ecommerce -> "Shop Now"; SaaS -> "Get Started"/"Try Free"; Law/medical -> "Get a Consultation"/"Book Appointment".

VISUAL DENSITY (at least 3): gradient mesh/blob behind hero; marquee strip (CSS @keyframes); card grid hover-lift; decorative SVG accents; SVG/clip-path section dividers.

TYPOGRAPHY (only from this list)
- Body sans: Inter, Manrope, Plus Jakarta Sans, Sora, Space Grotesk, IBM Plex Sans, Geist, DM Sans
- Display sans: Manrope, Sora, Space Grotesk, Geist, Inter, Anton
- Display serif (when suited): Playfair Display, Fraunces, Instrument Serif, DM Serif Display, Lora
- FORBIDDEN: Bagel Fat One, Lilita One, Modak, Concert One, Bowlby, Fredoka One, Boogaloo, novelty/kid-like.
- System fallback: font-family:'Manrope',system-ui,-apple-system,sans-serif. Headlines tracked tight: letter-spacing:-0.025em.

DESIGN STANDARDS
- Contemporary, confident, magazine-quality. 3-5 brand colors. High contrast. Generous whitespace, sections 80-120px vertical padding.
- Sections (adapt to industry): hero, trust strip/stats, services/work showcase (with images), about/story (people/place imagery framed correctly), social proof, CTA band, footer. Keep it tight enough to finish.
- 2 primary CTAs above the fold. Fully responsive at 375px.

FORBIDDEN
- Chatbots, popups, cookie banners. Fake testimonials. Lorem Ipsum. External JS frameworks. Links to URLs not in source data. "Sign In" on non-SaaS sites. Any opacity:0 reveal without CSS-only animation. Empty sections. Cropped faces/bodies. Reusing an image URL.

QUALITY GATE (auto-checked): an <h1>, a <footer>, 3+ <section> tags, 4+ DISTINCT /api/img.php?u= image URLs.

Industry: {$industry}
TXT;
}

function build_user_prompt(array $scrape, string $biz, string $industry, int $variant_n): string {
    $directions = [
        1 => 'Direction 1: BOLD EDITORIAL. Large display typography, generous whitespace, asymmetric grid, animated underlines, scroll-driven counters, film-grain texture, magazine-style full-bleed LANDSCAPE image bands.',
        2 => 'Direction 2: MODERN MAXIMALIST. Layered cards with depth shadows, vibrant gradient accents, magnetic hover on CTAs, animated SVG decorations in the hero, sticky scroll-progress indicator, photo collage in the work section.',
        3 => 'Direction 3: REFINED MINIMAL. Restrained color, museum-quality spacing, slow fades, hairline dividers, full-bleed LANDSCAPE hero with parallax, oversized footer with brand statement, editorial photo gallery.',
    ];
    $imgs = $scrape['images'] ?? [];
    $photo_imgs  = array_values(array_filter($imgs, fn($i) => empty($i['is_logo']) && empty($i['is_thumb']) && empty($i['is_team_card']) && empty($i['is_cutout']) && empty($i['is_portrait'])));
    $cutout_imgs = array_values(array_filter($imgs, fn($i) => (!empty($i['is_cutout']) || !empty($i['is_portrait'])) && empty($i['is_logo'])));
    $team_card_imgs = array_values(array_filter($imgs, fn($i) => !empty($i['is_team_card'])));
    $logo_imgs = array_values(array_filter($imgs, fn($i) => !empty($i['is_logo'])));
    $thumb_imgs = array_values(array_filter($imgs, fn($i) => !empty($i['is_thumb']) && empty($i['is_logo']) && empty($i['is_team_card']) && empty($i['is_cutout'])));
    $strip = fn($arr) => array_map(fn($i) => ['url' => $i['url'], 'alt' => $i['alt']], $arr);
    $scrape_summary = [
        'business_name' => $biz, 'industry' => $industry ?: 'unknown', 'current_url' => $scrape['url'] ?? '',
        'page_title' => $scrape['title'] ?? '', 'meta_desc' => $scrape['description'] ?? '',
        'logo_url' => $scrape['logo'] ?? null, 'brand_colors' => array_slice($scrape['colors'] ?? [], 0, 5),
        'h1' => $scrape['h1'] ?? [], 'h2' => $scrape['h2'] ?? [], 'h3' => $scrape['h3'] ?? [],
        'paragraphs' => $scrape['paragraphs'] ?? [],
        'images' => [
            'photo' => $strip(array_slice($photo_imgs, 0, 12)),
            'cutout' => $strip(array_slice($cutout_imgs, 0, 6)),
            'thumbnail' => $strip(array_slice($thumb_imgs, 0, 6)),
            'team_card' => $strip(array_slice($team_card_imgs, 0, 4)),
            'logo' => $strip(array_slice($logo_imgs, 0, 3)),
        ],
        'videos' => $scrape['videos'] ?? [], 'nav_links' => $scrape['nav_links'] ?? [], 'extra_pages' => $scrape['extra_pages'] ?? [],
    ];
    $direction = $directions[$variant_n] ?? $directions[1];
    $scrape_json = json_encode($scrape_summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return <<<TXT
Build a single-page website for **{$biz}** using the source data below. This is variant {$variant_n} of 3.

{$direction}

SOURCE DATA:

{$scrape_json}

IMAGE PICKING GUIDE
- images.photo = PRIMARY landscape photos. Safe as hero / full-bleed / cover-cropped feature images.
- images.cutout = people on transparent/plain backgrounds OR portrait photos. object-fit:CONTAIN in a fixed-height framed card with brand-tint background. NEVER crop, NEVER full-bleed hero.
- images.thumbnail = small filler images for small cards.
- images.team_card = ONLY for testimonial cards (name/title baked in).
- images.logo = ONLY in the nav.
- Every URL is verified to load. NEVER use the same URL twice.

REQUIREMENTS
- Complete HTML with embedded <style> and <script>. Finish the document - end with </html>.
- Logo in nav: prefer logo_url; else business-name wordmark.
- Hero: a LANDSCAPE photo from images.photo (cover). If images.photo is empty, build a CSS gradient/SVG hero - do NOT stretch a cutout person across the hero.
- Work/services showcase: 3 cards, each with its own distinct image.
- About/story/team: at least 1 photo, framed per rules (people = contain, never cropped).
- Footer with real business name, copyright, and "Designed by WebWiz" link to https://trywebwiz.com.
- 2 primary CTAs above the fold.
- Target ~5000 tokens. Completeness beats length.

IMAGE TAG FORMAT - copy exactly:
<img src="/api/img.php?u=<urlencoded-URL>&l=<urlencoded-label>" alt="...">  (NO loading="lazy")

QUALITY GATE: <h1>, <footer>, 3+ <section>, 4+ DISTINCT /api/img.php URLs, no empty sections, no cropped people, no broken images.

REMEMBER: output ONLY the HTML document. First character `<`, last character `>`. No commentary. END WITH </html>.
TXT;
}
