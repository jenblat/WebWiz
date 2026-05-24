<?php
// /var/www/sites/trywebwiz/private/worker.php
// Cron entry: * * * * * sudo -u nobody php8.3 /var/www/sites/trywebwiz/private/worker.php

declare(strict_types=1);
require '/var/www/sites/trywebwiz/private/webwiz_lib.php';
require '/var/www/sites/trywebwiz/private/lib/anthropic.php';
require '/var/www/sites/trywebwiz/private/lib/scrape.php';

set_time_limit(0);

$lock = fopen('/tmp/webwiz-worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "[worker] already running, exit\n";
    exit(0);
}

$db = ww_db();
$secrets = ww_secrets();
$cap = (float)($db->query("SELECT value FROM settings WHERE key='job_max_cost_usd'")->fetchColumn() ?: 1.50);
$qa_raw = $db->query("SELECT value FROM settings WHERE key='visual_qa_enabled'")->fetchColumn();
$qa_enabled = ($qa_raw === false || $qa_raw === null) ? false : ((string)$qa_raw === '1');

$row = $db->query(
    "SELECT * FROM jobs
     WHERE status = 'queued' AND datetime(scheduled_for) <= datetime('now')
     ORDER BY id ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$row) { echo "[worker] no jobs\n"; exit(0); }

$job_id = (int)$row['id'];
echo "[worker] job #{$job_id} starting\n";
$db->prepare("UPDATE jobs SET status='running', started_at=datetime('now') WHERE id=?")->execute([$job_id]);

try {
    $prospect = null;
    if ($row['prospect_id']) {
        $st = $db->prepare("SELECT * FROM prospects WHERE id = ?");
        $st->execute([$row['prospect_id']]);
        $prospect = $st->fetch(PDO::FETCH_ASSOC);
    }
    $url   = $prospect['current_url'] ?? ($row['scrape_data'] ?? '');
    $biz   = $prospect['business_name'] ?? $row['business_name'] ?? 'Their Business';
    $email = $prospect['email'] ?? $row['customer_email'] ?? '';
    $industry = $prospect['industry'] ?? '';

    if (!$url) throw new Exception('No current_url available for this job');

    echo "[worker]  scraping {$url}\n";
    $scrape = scrape_multi($url);
    $db->prepare("UPDATE jobs SET scrape_data=? WHERE id=?")->execute([json_encode($scrape), $job_id]);

    $usable_imgs = array_values(array_filter($scrape['images'] ?? [], function($i){
        return empty($i['is_logo']) && empty($i['is_thumb']) && empty($i['is_team_card']);
    }));
    echo "[worker]  scrape: " . count($scrape['images'] ?? []) . " live images, " . count($usable_imgs) . " usable\n";

    $model = $row['model'] ?: 'claude-sonnet-4-6';
    $system = build_system_prompt($industry, count($usable_imgs));

    $total_cost = 0.0;
    $variants_made = [];
    $public_dir = '/var/www/sites/trywebwiz/public/preview/' . $row['token'];

    for ($v = 1; $v <= 3; $v++) {
        if ($total_cost >= $cap) throw new Exception("Cost cap (\${$cap}) hit before variant {$v}");

        // ---- Generate (structural gate + 1 retry) ----
        [$html, $gen_cost, $gen_err] = generate_variant($model, $system, $scrape, $biz, $industry, $v, $job_id, $cap - $total_cost, '');
        $total_cost += $gen_cost;
        if (!$html) throw new Exception("Variant {$v} failed: {$gen_err}");

        $variant_dir = $public_dir . '/v' . $v;
        if (!is_dir($variant_dir)) @mkdir($variant_dir, 0755, true);
        file_put_contents($variant_dir . '/index.html', $html);

        // ---- Visual QA pass (best-effort) ----
        if ($qa_enabled && $total_cost < $cap) {
            $variant_url = 'https://trywebwiz.com/preview/' . $row['token'] . '/v' . $v . '/index.html?qa=' . time();
            $qa = ww_visual_qa($variant_url, $job_id);
            if ($qa !== null) {
                $total_cost += $qa['cost'];
                if (!$qa['ok'] && !empty($qa['issues']) && $total_cost < $cap) {
                    $feedback = "A visual review of your previous attempt found these problems: " . implode('; ', $qa['issues']) . ". Fix every one of them — no blank/empty sections, no broken images, no people cropped at the head/neck/body.";
                    echo "[worker]   variant {$v} QA flagged: " . implode('; ', $qa['issues']) . " -> regenerating\n";
                    [$html2, $cost2] = generate_variant($model, $system, $scrape, $biz, $industry, $v, $job_id, $cap - $total_cost, $feedback);
                    $total_cost += $cost2;
                    if ($html2) {
                        file_put_contents($variant_dir . '/index.html', $html2);
                        $html = $html2;
                    }
                } else {
                    echo "[worker]   variant {$v} QA: ok\n";
                }
            }
        }

        $stub_path = $public_dir . '/index.php';
        if (!is_file($stub_path)) {
            file_put_contents($stub_path, "<?php\n\$_GET['t'] = basename(__DIR__);\nrequire __DIR__ . '/../index.php';\n");
        }

        $rel_path = '/preview/' . $row['token'] . '/v' . $v . '/index.html';
        $db->prepare("INSERT INTO previews (job_id, variant_n, html_path) VALUES (?, ?, ?)")
           ->execute([$job_id, $v, $rel_path]);
        $variants_made[] = $rel_path;
    }

    $db->prepare("UPDATE jobs SET status='ready', completed_at=datetime('now'), total_cost_cents=? WHERE id=?")
       ->execute([(int)round($total_cost * 100), $job_id]);

    echo "[worker] job #{$job_id} ready, total cost \$" . number_format($total_cost, 4) . "\n";

} catch (Throwable $e) {
    $msg = $e->getMessage();
    echo "[worker] job #{$job_id} FAILED: {$msg}\n";
    $db->prepare("UPDATE jobs SET status='failed', error=?, completed_at=datetime('now') WHERE id=?")
       ->execute([substr($msg, 0, 500), $job_id]);
}

flock($lock, LOCK_UN);
fclose($lock);

// ============ Generation ============

// Returns [html|null, cost_spent, error]. Does up to 2 attempts (structural gate + retry).
function generate_variant(string $model, string $system, array $scrape, string $biz, string $industry, int $v, int $job_id, float $budget, string $extra_feedback): array {
    $html = null; $spent = 0.0; $last_err = null;
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        if ($spent >= $budget) break;
        $note = '';
        if ($attempt === 2 && $last_err) $note = "\n\nIMPORTANT — your previous attempt failed quality gate: {$last_err}. Fix it this time.";
        if ($extra_feedback) $note .= "\n\n" . $extra_feedback;
        $user_prompt = build_user_prompt($scrape, $biz, $industry, $v) . $note;
        echo "[worker]  variant {$v} attempt {$attempt} -> {$model}\n";
        $resp = anthropic_chat($model, [['role' => 'user', 'content' => $user_prompt]], $system, 12000, 0.75, $job_id, ['</html>']);
        $spent += $resp['cost_usd'];

        $cand = extract_html($resp['text']);
        if (!$cand || stripos($cand, '<html') === false) { $last_err = 'no usable HTML returned'; continue; }
        if (stripos($cand, '</html>') === false) $cand = rtrim($cand) . "\n</html>";

        $gate = quality_gate($cand);
        if ($gate['ok']) { $html = $cand; break; }
        $last_err = $gate['reason'];
        if ($spent >= $budget) break;
    }
    return [$html, $spent, $last_err];
}

// ============ Visual QA (best-effort, never throws) ============

// Returns ['ok'=>bool,'issues'=>[],'cost'=>float] or null if a screenshot couldn't be obtained.
function ww_visual_qa(string $page_url, int $job_id): ?array {
    try {
        $jpeg = ww_fetch_screenshot($page_url, 55);
        if ($jpeg === null) { echo "[worker]   QA: no screenshot, skipping\n"; return null; }
        $b64 = base64_encode($jpeg);
        $messages = [[
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $b64]],
                ['type' => 'text', 'text' =>
                    "This is a full-page screenshot of an auto-generated small-business marketing website. " .
                    "Look ONLY for serious visual defects: " .
                    "(1) large blank/empty areas with no content; " .
                    "(2) broken or missing images (gray boxes, placeholder icons, monogram fallbacks); " .
                    "(3) a person or photo awkwardly cropped (head, face, or body cut off). " .
                    "Reply with STRICT JSON only: {\"ok\": true|false, \"issues\": [\"short phrase\", ...]}. " .
                    "Set ok=false ONLY when there is a clear, serious defect a paying client would reject. Normal whitespace and tasteful spacing are fine."
                ],
            ],
        ]];
        $resp = anthropic_chat('claude-haiku-4-5-20251001', $messages, null, 400, 0.0, $job_id);
        $cost = (float)$resp['cost_usd'];
        if (preg_match('/\{[\s\S]*\}/', $resp['text'], $m)) {
            $j = json_decode($m[0], true);
            if (is_array($j) && array_key_exists('ok', $j)) {
                return ['ok' => (bool)$j['ok'], 'issues' => array_slice((array)($j['issues'] ?? []), 0, 6), 'cost' => $cost];
            }
        }
        return ['ok' => true, 'issues' => [], 'cost' => $cost]; // fail-open on parse error
    } catch (Throwable $e) {
        echo "[worker]   QA error: " . $e->getMessage() . "\n";
        return null;
    }
}

// WordPress mShots: screenshot any public URL. Serves a small placeholder while rendering; poll until the real (larger) image arrives.
function ww_fetch_screenshot(string $page_url, int $max_wait = 55): ?string {
    $shot = 'https://s0.wp.com/mshots/v1/' . urlencode($page_url) . '?w=1100';
    $deadline = time() + $max_wait;
    $best = null;
    while (time() < $deadline) {
        $ch = curl_init($shot);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; WebWizBot/1.0)',
        ]);
        $img = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($img !== false && $code === 200 && stripos($ctype, 'image/') === 0) {
            // Real screenshots are sizeable; the "generating" placeholder is small.
            if (strlen($img) > 35000) return $img;
            $best = $img;
        }
        sleep(6);
    }
    return null; // only had placeholder — treat as "couldn't verify"
}

// ============ Quality gate (structural) ============

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

// ============ Prompt builders ============

function build_system_prompt(string $industry, int $usable_img_count): string {
    $img_note = $usable_img_count >= 4
        ? "Source has {$usable_img_count} usable content images — use at least 4 distinct ones."
        : "Source has only {$usable_img_count} usable content images — supplement with thumbnail-tagged images to reach 4+ distinct images.";

    return <<<TXT
You are WebWiz, an elite designer building single-page marketing sites that rival Apple, Patagonia, Stripe, Linear, Aesop.

OUTPUT
Return ONLY a complete HTML5 document, no markdown, no commentary, no code fences. First character `<`, last character `>`. Must include <!DOCTYPE html>, <html>, <head>, <body>, end with </html>. Target 4500–5500 tokens.

ABSOLUTE RULES
1. ALL CONTENT VISIBLE AT FIRST PAINT. No opacity:0 or visibility:hidden without a guaranteed CSS-only reveal. No JS-gated reveals.
2. ENTRANCE ANIMATIONS WRAPPED IN @media (prefers-reduced-motion: no-preference). Outside that, elements at final state.
3. HTML COMPLETE. Close every tag. End with </html>.
4. IMAGES ARE MANDATORY. Use a MINIMUM of 4 DISTINCT images via the proxy. Every image MUST be wrapped exactly like:
   <img src="/api/img.php?u=<URL-ENCODED-original>&l=<URL-ENCODED-short-label>" alt="..." loading="lazy">
5. {$img_note}
6. EVERY image URL you use MUST come from the provided source data (images.photo / images.cutout / images.thumbnail / images.logo). All provided URLs have been verified to load. Do NOT invent URLs. Do NOT reuse a URL twice.

IMAGE FRAMING — this is critical, clients reject cropped people:
- LANDSCAPE PHOTOS (images.photo): scenes, offices, products, abstract. Container with aspect-ratio + overflow:hidden + object-fit:cover; object-position:center. Fine to use as hero/feature/full-bleed.
- CUTOUT / PORTRAIT / PERSON images (images.cutout, or anything that is a person on a transparent/plain background): NEVER crop. Render with `object-fit:contain` inside a fixed-height box (e.g. height:420px) with a soft brand-tint or neutral background and padding, so the whole person is visible. NEVER use a cutout person as a full-bleed hero background. NEVER put a face in a tight 1:1 or 16/9 cover crop.
- If you only have cutout/person images for a section, place them in a framed "meet the team" card grid with contain fit and a colored card background — not stretched edge-to-edge.
- Hero: prefer a LANDSCAPE photo. If none exists, use a CSS gradient/SVG hero with the headline and put people photos lower in framed cards.

NO EMPTY SPACE
- Every section must contain visible content (headline, copy, image, stats, or cards). Never leave a section that is taller than ~40vh with nothing in it.
- Never reserve a fixed-height image box and leave it empty. If you place an <img>, it has a real src from the source data.

HEADER REQUIREMENTS
- Sticky top nav: business name/logo left, 3-5 nav links (use scraped nav_links), and 1-2 right-aligned CTAs.
- CTA TEXT MUST MATCH THE BUSINESS. NEVER use "Sign In" / "Log In" unless the source clearly has an authenticated product.
  Inference: Agency/consultancy → "Book a Call"/"Get a Quote"; Restaurant → "Reserve a Table"/"Order Online"; Ecommerce → "Shop Now"; SaaS → "Get Started"/"Try Free"; Law/medical → "Get a Consultation"/"Book Appointment". When unsure, mirror the source's main CTA.

VISUAL DENSITY (at least 3 of these)
- Gradient mesh / animated blob behind hero. Marquee strip (CSS @keyframes). Card grid with hover lift. Decorative SVG accents. Section dividers as SVG curves or angled clip-paths.

TYPOGRAPHY (only from this list)
- Body sans: Inter, Manrope, Plus Jakarta Sans, Sora, Space Grotesk, IBM Plex Sans, Geist, DM Sans
- Display sans: Manrope, Sora, Space Grotesk, Geist, Inter, Anton
- Display serif (when industry suits): Playfair Display, Fraunces, Instrument Serif, DM Serif Display, Lora
- FORBIDDEN: Bagel Fat One, Lilita One, Modak, Concert One, Bowlby, Fredoka One, Boogaloo, anything novelty/kid-like.
- System fallback: font-family:'Manrope',system-ui,-apple-system,sans-serif. Headlines tracked tight: letter-spacing:-0.025em.

DESIGN STANDARDS
- Contemporary, confident, magazine-quality. 3-5 brand colors. High contrast text. Generous whitespace, sections 80-120px vertical padding.
- Sections (adapt to industry): hero, trust strip/stats, services or work showcase (with images), about/story (people or place imagery, framed correctly), social proof, CTA band, optional FAQ, footer.
- 2 primary CTAs above the fold. Fully responsive at 375px.

FORBIDDEN
- Chatbots, popups, cookie banners. Fake testimonials (use real source quotes or skip). Lorem Ipsum. External JS frameworks. Links to URLs not in source data. "Sign In" on non-SaaS sites. Any opacity:0 reveal without CSS-only animation. Empty sections. Cropped faces/bodies. Reusing an image URL.

QUALITY GATE (auto-checked)
Must have: an <h1>, a <footer>, 3+ <section> tags, 4+ DISTINCT /api/img.php?u= image URLs. A visual review also checks for blank space, broken images, and cropped people.

Industry: {$industry}
TXT;
}

function build_user_prompt(array $scrape, string $biz, string $industry, int $variant_n): string {
    $directions = [
        1 => 'Direction 1: BOLD EDITORIAL. Large display typography, generous whitespace, asymmetric grid, animated underlines, scroll-driven number counters, subtle film-grain texture, magazine-style full-bleed LANDSCAPE image bands.',
        2 => 'Direction 2: MODERN MAXIMALIST. Layered cards with depth shadows, vibrant gradient accents, magnetic hover on CTAs, animated SVG decorations in the hero, sticky scroll-progress indicator, photo collage in the work section.',
        3 => 'Direction 3: REFINED MINIMAL. Restrained color, museum-quality spacing, slow elegant fades, hairline dividers, full-bleed LANDSCAPE hero with parallax, oversized footer with brand statement, editorial-style photo gallery.',
    ];

    $imgs = $scrape['images'] ?? [];
    // photo = landscape content (cover-safe). cutout = person/transparent (contain only).
    $photo_imgs  = array_values(array_filter($imgs, fn($i) => empty($i['is_logo']) && empty($i['is_thumb']) && empty($i['is_team_card']) && empty($i['is_cutout']) && empty($i['is_portrait'])));
    $cutout_imgs = array_values(array_filter($imgs, fn($i) => (!empty($i['is_cutout']) || !empty($i['is_portrait'])) && empty($i['is_logo'])));
    $team_card_imgs = array_values(array_filter($imgs, fn($i) => !empty($i['is_team_card'])));
    $logo_imgs = array_values(array_filter($imgs, fn($i) => !empty($i['is_logo'])));
    $thumb_imgs = array_values(array_filter($imgs, fn($i) => !empty($i['is_thumb']) && empty($i['is_logo']) && empty($i['is_team_card']) && empty($i['is_cutout'])));

    $strip = fn($arr) => array_map(fn($i) => ['url'=>$i['url'],'alt'=>$i['alt']], $arr);

    $scrape_summary = [
        'business_name' => $biz,
        'industry'      => $industry ?: 'unknown',
        'current_url'   => $scrape['url'] ?? '',
        'page_title'    => $scrape['title'] ?? '',
        'meta_desc'     => $scrape['description'] ?? '',
        'logo_url'      => $scrape['logo'] ?? null,
        'brand_colors'  => array_slice($scrape['colors'] ?? [], 0, 5),
        'h1'            => $scrape['h1'] ?? [],
        'h2'            => $scrape['h2'] ?? [],
        'h3'            => $scrape['h3'] ?? [],
        'paragraphs'    => $scrape['paragraphs'] ?? [],
        'images' => [
            'photo'     => $strip(array_slice($photo_imgs, 0, 12)),   // landscape, cover-safe
            'cutout'    => $strip(array_slice($cutout_imgs, 0, 6)),   // people/transparent — CONTAIN only, framed
            'thumbnail' => $strip(array_slice($thumb_imgs, 0, 6)),
            'team_card' => $strip(array_slice($team_card_imgs, 0, 4)),// text baked in — testimonial cards only
            'logo'      => $strip(array_slice($logo_imgs, 0, 3)),     // nav only
        ],
        'videos'        => $scrape['videos'] ?? [],
        'nav_links'     => $scrape['nav_links'] ?? [],
        'extra_pages'   => $scrape['extra_pages'] ?? [],
    ];

    $direction = $directions[$variant_n] ?? $directions[1];
    $scrape_json = json_encode($scrape_summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    return <<<TXT
Build a single-page website for **{$biz}** using the source data below. This is variant {$variant_n} of 3.

{$direction}

SOURCE DATA:

{$scrape_json}

IMAGE PICKING GUIDE
- images.photo = your PRIMARY landscape photos. Safe to use as hero / full-bleed / cover-cropped feature images.
- images.cutout = people on transparent/plain backgrounds OR portrait-orientation photos. Use object-fit:CONTAIN in a fixed-height framed card with a brand-tint background. NEVER crop these, NEVER use as a full-bleed hero.
- images.thumbnail = small filler images, okay for small cards.
- images.team_card = ONLY for testimonial cards (name/title baked into the image).
- images.logo = ONLY in the nav.
- Every URL is verified to load. NEVER use the same URL twice.

REQUIREMENTS
- Complete HTML document with embedded <style> and <script>.
- Logo in nav: prefer logo_url; else business-name wordmark.
- Hero: a LANDSCAPE photo from images.photo (cover). If images.photo is empty, build a CSS gradient/SVG hero instead — do NOT stretch a cutout person across the hero.
- Work / services showcase: 3 cards, each with its own distinct image.
- About / story / team: at least 1 photo, framed per the rules above (people = contain, never cropped).
- Footer with real business name, copyright, and "Designed by WebWiz" link to https://trywebwiz.com.
- 2 primary CTAs above the fold, matching real conversion goals.
- Total output 4500–5500 tokens.

IMAGE TAG FORMAT — copy exactly:
<img src="/api/img.php?u=<urlencoded-URL>&l=<urlencoded-label>" alt="..." loading="lazy">

QUALITY GATE: <h1>, <footer>, 3+ <section>, 4+ DISTINCT /api/img.php URLs, no empty sections, no cropped people, no broken images.

REMEMBER: output ONLY the HTML document. First character `<`, last character `>`. No commentary.
TXT;
}

function extract_html(string $text): ?string {
    $text = trim($text);
    if (preg_match('/```(?:html)?\s*([\s\S]+?)```/i', $text, $m)) $text = trim($m[1]);
    if (preg_match('/<(?:!doctype|html)[\s\S]+/i', $text, $m)) $text = $m[0];
    if (stripos($text, '<html') === false) return null;
    return $text;
}
