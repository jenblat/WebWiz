<?php
// /var/www/sites/trywebwiz/private/lib/design.php
//
// De-templating layer. Before this existed, every generated site came from ONE static
// system prompt plus one of THREE hardcoded "direction" strings, so nothing about the
// specific business ever influenced the design. Measured across 400 shipped v1 pages:
// 398 used Manrope, 302 used Fraunces, 400/400 had the identical
// <nav> -> N x <section> -> <footer> skeleton, and 301 had exactly 6 or 7 sections.
//
// Two layers fix that:
//   1. ww_design_dna()  - deterministic per-(job,variant) sampling across orthogonal
//      design axes. Assigns ONE concrete choice per axis instead of offering a menu
//      (a menu is what collapsed to Manrope). Seeded from the job token so a given
//      job always regenerates to the same look, and the three variants of one job are
//      guaranteed to draw DIFFERENT values on every axis.
//   2. ww_art_direction_brief() - one cheap LLM pre-pass that reads the scrape and
//      writes a bespoke palette / voice / section plan for THIS business, so the page
//      derives from the client rather than from a fixed skeleton.

declare(strict_types=1);

/**
 * Deterministically shuffle $pool by ($seed,$axis) and return the $variant-th entry.
 * Distinct-by-construction across variants whenever count($pool) >= number of variants.
 */
function ww_dna_pick(array $pool, string $seed, string $axis, int $variant) {
    $n = count($pool);
    if ($n === 0) return null;
    $order = range(0, $n - 1);
    usort($order, fn($a, $b) => strcmp(
        hash('sha256', $seed . '|' . $axis . '|' . $a),
        hash('sha256', $seed . '|' . $axis . '|' . $b)
    ));
    return $pool[$order[($variant - 1) % $n]];
}

/** Curated (display, body) Google Font pairings. One is ASSIGNED per variant - never a menu. */
function ww_type_pairings(): array {
    return [
        ['display' => 'Anton',              'body' => 'Inter',             'note' => 'condensed poster display over a neutral grotesk'],
        ['display' => 'Bebas Neue',         'body' => 'Work Sans',         'note' => 'tall condensed caps, utilitarian body'],
        ['display' => 'Playfair Display',   'body' => 'Source Sans 3',     'note' => 'high-contrast didone over humanist sans'],
        ['display' => 'Fraunces',           'body' => 'Libre Franklin',    'note' => 'soft wonky serif over a newsy grotesk'],
        ['display' => 'Instrument Serif',   'body' => 'Figtree',           'note' => 'literary serif over a friendly geometric'],
        ['display' => 'Bodoni Moda',        'body' => 'Karla',             'note' => 'fashion-magazine didone over a quirky grotesk'],
        ['display' => 'Syne',               'body' => 'DM Sans',           'note' => 'art-gallery wide display over clean geometric'],
        ['display' => 'Unbounded',          'body' => 'Public Sans',       'note' => 'contemporary display over a plain civic sans'],
        ['display' => 'Archivo Black',      'body' => 'Archivo',           'note' => 'single-superfamily heavy/regular contrast'],
        ['display' => 'Oswald',             'body' => 'Lato',              'note' => 'condensed newsroom display over warm sans'],
        ['display' => 'Cormorant Garamond', 'body' => 'Nunito Sans',       'note' => 'delicate old-style serif over a rounded sans'],
        ['display' => 'DM Serif Display',   'body' => 'Epilogue',          'note' => 'sturdy editorial serif over a modern grotesk'],
        ['display' => 'Abril Fatface',      'body' => 'Barlow',            'note' => 'fat display serif over a squarish sans'],
        ['display' => 'Marcellus',          'body' => 'Hanken Grotesk',    'note' => 'roman inscriptional caps over a neutral sans'],
        ['display' => 'Prata',              'body' => 'Mulish',            'note' => 'refined serif over a light geometric'],
        ['display' => 'Big Shoulders Display', 'body' => 'Chivo',          'note' => 'ultra-condensed industrial over a sturdy grotesk'],
        ['display' => 'Bricolage Grotesque','body' => 'Schibsted Grotesk', 'note' => 'variable expressive grotesk pairing'],
        ['display' => 'Yeseva One',         'body' => 'Rubik',             'note' => 'decorative display serif over a rounded sans'],
        ['display' => 'Spectral',           'body' => 'Outfit',            'note' => 'screen serif over a geometric sans'],
        ['display' => 'Newsreader',         'body' => 'Onest',             'note' => 'newspaper serif over a contemporary neutral'],
        ['display' => 'Familjen Grotesk',   'body' => 'IBM Plex Sans',     'note' => 'nordic grotesk over a technical sans'],
        ['display' => 'Zilla Slab',         'body' => 'Plus Jakarta Sans', 'note' => 'slab display over a soft geometric'],
        ['display' => 'EB Garamond',        'body' => 'Space Grotesk',     'note' => 'classical serif against a technical grotesk'],
        ['display' => 'Frank Ruhl Libre',   'body' => 'Assistant',         'note' => 'contemporary bookish serif over a plain sans'],
        ['display' => 'Literata',           'body' => 'Manrope',           'note' => 'warm reading serif over a rounded grotesk'],
        ['display' => 'Crimson Pro',        'body' => 'Sora',              'note' => 'classic text serif over a wide techy sans'],
        ['display' => 'Bitter',             'body' => 'Cabin',             'note' => 'contrast slab over a humanist sans'],
        ['display' => 'Gloock',             'body' => 'Albert Sans',       'note' => 'sharp modern serif over a geometric sans'],
    ];
}

/** Structural archetypes. These replace the fixed nav -> sections -> footer skeleton. */
function ww_layout_archetypes(): array {
    return [
        'SPLIT STAGE: hero is a hard 50/50 vertical split - type block one side, full-height image the other, no gap between them. Content below alternates which side the image sits on.',
        'FULL-BLEED OVERLAY: nav sits transparent on top of an edge-to-edge hero photograph; headline anchored bottom-left over the image with a scrim. Subsequent sections are contained and calm by contrast.',
        'TYPOGRAPHIC POSTER: hero is type only - no photo - at enormous scale filling the viewport, colour-field background. The first photograph does not appear until the second section, where it runs full-bleed.',
        'SIDEBAR RAIL: a fixed left rail (about 220px) holds the wordmark, nav and contact detail; all content scrolls in the column to its right. Collapses to a top bar under 900px.',
        'EDITORIAL MASTHEAD: a newspaper-style masthead with hairline rules above and below, a dateline strip, then a multi-column opening spread. Rules and column dividers carry the structure instead of cards.',
        'OFFSET ASYMMETRY: nothing is centred. Headline sits on a left third, the hero image bleeds off the right edge and past the top, and every section below uses a different asymmetric column split.',
        'FRAMED CANVAS: the whole page sits inside a visible margin/border of flat colour, like a printed page. Sections are separated by that frame colour rather than by padding alone.',
        'STACKED BANDS: the page reads as a stack of full-width colour bands that alternate light/dark/accent, each band a different height, with content vertically centred inside it.',
        'INDEX / LIST: the core section is a typographic index - numbered rows with hairline dividers that reveal an image on hover - instead of a card grid. Very few boxes, lots of rules.',
        'OVERLAPPING PLANES: elements deliberately break their containers - the hero image overlaps the nav, cards overlap section boundaries, negative margins used throughout for depth.',
        'HORIZONTAL GALLERY: one section is a horizontally scrolling image track (CSS scroll-snap, overflow-x:auto) rather than a grid, giving the page a lateral movement nothing else has.',
        'CENTRED MONUMENT: strictly symmetrical and centred throughout - a narrow measure, a single centred column, an oversized centred wordmark. Restraint is the whole idea.',
    ];
}

/** Colour strategies. Each one must be BUILT FROM the scraped brand colours, not invented. */
function ww_color_strategies(): array {
    return [
        'MONOCHROME + ONE ACCENT: near-black and near-white plus exactly one saturated brand colour used sparingly for emphasis only.',
        'DUOTONE: two brand colours carry the entire page, including tinted photo treatments. No third hue anywhere.',
        'WARM EARTH: clay, ochre, sand and umber derived from the brand palette. No pure white and no pure black.',
        'INK ON PAPER: a warm cream/off-white ground with a deep ink text colour, one accent reserved for links and buttons.',
        'DARK JEWEL: a deep dark ground (not pure black) with a single luminous jewel accent. All text light and high-contrast.',
        'SATURATED FLOOD: the brand primary floods entire sections edge to edge as a background, with knocked-out white type.',
        'MUTED ARCHIVAL: desaturated, slightly faded palette as if printed decades ago. Low chroma, high texture.',
        'HIGH-KEY CONTRAST: pure white and pure black with one electric accent, hard edges, no mid-tones or soft greys.',
        'TONAL SOFT: a narrow range of closely related pastel tints of the brand hue, very low contrast between sections, contrast carried by type weight instead.',
        'COMPLEMENTARY CLASH: the brand colour deliberately set against its complement in confident, large flat areas.',
    ];
}

/** Ornament / texture language - what carries visual interest besides photos. */
function ww_ornament_modes(): array {
    return [
        'PURE TYPOGRAPHIC: no decoration at all. Scale, weight and spacing do all the work. No gradients, no shadows, no shapes.',
        'HAIRLINE GRID: 1px rules everywhere - between sections, between columns, boxing content. A visible structural grid.',
        'GRAIN + HALFTONE: a subtle SVG/CSS noise overlay and halftone-dot treatment on imagery, print-like.',
        'HARD GEOMETRY: flat circles, arcs and rectangles in brand colours placed behind and beside content. No blur, no gradients.',
        'OUTLINED TYPE: outline/stroked text (-webkit-text-stroke) used as a deliberate display device - ONLY on a standalone oversized word, section number, or a full headline line that sits on its own. NEVER stroke individual words inside a running sentence, and never stroke text below ~40px: at small sizes or mid-sentence it reads as a rendering fault, not a design. Always pair the stroke with an explicit colour so the text is legible if -webkit-text-stroke is unsupported.',
        'OVERSIZED NUMERALS: huge section numbers (01, 02, 03) set behind or beside content as a structural device.',
        'LABELLED RULES: every section opens with a rule plus a small monospace/uppercase label with generous letter-spacing.',
        'PHOTO COLLAGE: images deliberately overlap and rotate slightly, with visible edges, like pinned prints.',
        'STICKER BADGES: small rotated circular badges/seals containing short claims, placed against section corners.',
        'DUOTONE PHOTO TREATMENT: all photography pushed through a consistent CSS filter/blend so the imagery itself is the ornament.',
    ];
}

function ww_shape_languages(): array {
    return [
        'SHARP: every corner 0px. No border-radius anywhere, including buttons and images.',
        'PILL: fully rounded buttons and pills (999px) against square-cornered images and cards.',
        'SOFT UNIFORM: a consistent 10-14px radius on everything - cards, images, buttons, inputs.',
        'LARGE ARCH: generous 24-32px radii, and at least one image masked to an arch/dome top.',
        'MIXED: square images, rounded buttons, and one section that uses an organic blob/squircle mask.',
    ];
}

function ww_rhythm_modes(): array {
    return [
        'AIRY LUXURY: very generous vertical space (140-180px between sections), short copy, large type.',
        'COMPACT EDITORIAL: tight vertical rhythm (56-72px), denser text, more content visible at once.',
        'VARIED CADENCE: section spacing deliberately uneven - some sections cramped and dense, the next one vast and near-empty.',
        'MAGAZINE DENSE: multi-column text, pull quotes, sidebars, high information density throughout.',
    ];
}

/**
 * Assign one concrete value per design axis for a given (job seed, variant).
 * Deterministic: same token+variant always yields the same DNA, so regeneration is stable.
 */
function ww_design_dna(string $seed, int $variant): array {
    return [
        'type'      => ww_dna_pick(ww_type_pairings(),    $seed, 'type',     $variant),
        'layout'    => ww_dna_pick(ww_layout_archetypes(),$seed, 'layout',   $variant),
        'color'     => ww_dna_pick(ww_color_strategies(), $seed, 'color',    $variant),
        'ornament'  => ww_dna_pick(ww_ornament_modes(),   $seed, 'ornament', $variant),
        'shape'     => ww_dna_pick(ww_shape_languages(),  $seed, 'shape',    $variant),
        'rhythm'    => ww_dna_pick(ww_rhythm_modes(),     $seed, 'rhythm',   $variant),
    ];
}

/** Render the DNA as the prompt block that replaces the old fixed "Direction N" string. */
function ww_dna_prompt_block(array $dna): string {
    $t = $dna['type'];
    return "ASSIGNED ART DIRECTION FOR THIS VARIANT - follow every line. These are instructions, not options.\n"
        . "- TYPEFACES: display/headlines = '{$t['display']}', body/UI = '{$t['body']}' ({$t['note']}). Load exactly these two from Google Fonts and use NO other family. The CSS fallback stack must name '{$t['body']}' first, not any other font.\n"
        . "- LAYOUT ARCHETYPE: {$dna['layout']}\n"
        . "- COLOUR STRATEGY: {$dna['color']}\n"
        . "- ORNAMENT LANGUAGE: {$dna['ornament']}\n"
        . "- SHAPE LANGUAGE: {$dna['shape']}\n"
        . "- SPATIAL RHYTHM: {$dna['rhythm']}\n";
}

/**
 * ONE cheap LLM pre-pass per job. Reads the scrape and returns a bespoke art-direction
 * brief for THIS business: real palette, voice, and a section plan with actual headlines.
 * Never throws - a failed brief degrades to [] and the build proceeds without it.
 */
function ww_art_direction_brief(array $scrape, string $biz, string $industry, ?int $job_id = null): array {
    $input = [
        'business_name' => $biz,
        'industry'      => $industry ?: 'unknown',
        'page_title'    => $scrape['title'] ?? '',
        'meta_desc'     => $scrape['description'] ?? '',
        'brand_colors'  => array_slice($scrape['colors'] ?? [], 0, 6),
        'h1'            => array_slice($scrape['h1'] ?? [], 0, 5),
        'h2'            => array_slice($scrape['h2'] ?? [], 0, 12),
        'h3'            => array_slice($scrape['h3'] ?? [], 0, 12),
        'paragraphs'    => array_slice($scrape['paragraphs'] ?? [], 0, 14),
        'nav_links'     => array_slice($scrape['nav_links'] ?? [], 0, 10),
        'image_alts'    => array_slice(array_values(array_filter(array_map(
                              fn($i) => $i['alt'] ?? '', $scrape['images'] ?? []))), 0, 14),
    ];

    $system = <<<'TXT'
You are an art director briefing a designer on ONE specific client. Your job is to make the
resulting website look like it was made for THIS business and no other. Generic is failure.

Read the scraped source material and return ONLY strict JSON (no prose, no code fences):

{
  "positioning": "one sentence on what this business actually is and who buys from it, in concrete terms - not marketing filler",
  "voice": "3-6 words describing the copy register, e.g. 'plainspoken, technical, unshowy' or 'warm, family, local'",
  "palette": [{"hex":"#RRGGBB","role":"ground|ink|primary|accent|support","why":"short"}],
  "avoid": ["specific visual or verbal cliches that would be wrong for THIS business"],
  "sections": [
    {"id":"short-slug","purpose":"what this section does for THIS business","headline":"the actual headline to use, in the business's own voice","needs_image":true|false}
  ],
  "signature_detail": "one specific, concrete visual idea unique to this business that a designer could execute in CSS"
}

RULES
- palette: 4-5 entries. DERIVE them from the supplied brand_colors wherever those are usable
  (adjust for contrast if needed and say so in "why"). Only invent a hue if brand_colors is
  empty or unusable. Always include a readable ink colour and a ground colour.
- sections: choose 4-8 sections THIS business genuinely needs, in the order they should appear.
  Do NOT default to hero/stats/services/about/testimonials/cta. A restaurant does not need a
  trust-stat strip; a law firm does not need a product grid. Omit anything the source data
  cannot substantiate - if there are no real testimonials in the source, there is no
  testimonials section.
- headline: write the real headline text, specific to this business. Never "Ready to Get
  Started", "What Our Clients Say", "Trusted By", "Everything You Need", "Why Choose Us",
  or any interchangeable phrasing that would fit any company.
- headlines must NOT be abstract virtue nouns. "Uncompromising Integrity", "Unwavering
  Commitment", "Relentless Excellence", "Built on Trust", "Our Core Values" are banned:
  they are the house style of generated marketing and say nothing. Write the claim itself
  in plain words, so a competitor could not use the same line. Weak: "Uncompromising
  Integrity". Strong: "You see the same numbers the lender sees".
- NO EM DASHES OR EN DASHES anywhere in any string you return (no "—", no "–", no " -- ").
  Use commas or full stops. This is the most recognisable AI tell in written copy.
- No anaphora triads ("Every decision, every communication, every report"), no
  "not just X, but Y", and none of: elevate, empower, unlock, seamless, robust, leverage,
  curated, bespoke, journey, "transform your", "next level".
- signature_detail must be concrete and buildable (a specific treatment, motif or device),
  not an adjective.
TXT;

    $user = "Source material for **{$biz}**:\n\n"
          . json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
          . "\n\nReturn the JSON brief.";

    try {
        $r = anthropic_chat('claude-sonnet-4-6', [['role' => 'user', 'content' => $user]], $system, 1600, 0.9, $job_id);
    } catch (Throwable $e) {
        error_log('[design] brief failed: ' . $e->getMessage());
        return [];
    }

    $txt = $r['text'] ?? '';
    if (preg_match('/\{[\s\S]*\}/', $txt, $m)) $txt = $m[0];
    $j = json_decode($txt, true);
    if (!is_array($j)) return [];
    $j['_cost_usd'] = (float)($r['cost_usd'] ?? 0);
    return $j;
}

/** Render the brief into a prompt block. Returns '' when the brief is unavailable. */
function ww_brief_prompt_block(array $brief): string {
    if (!$brief) return '';
    $out = "CLIENT-SPECIFIC ART DIRECTION BRIEF (written for this business - honour it):\n";
    if (!empty($brief['positioning'])) $out .= "- POSITIONING: {$brief['positioning']}\n";
    if (!empty($brief['voice']))       $out .= "- COPY VOICE: {$brief['voice']}. Every word you write must sit in this register.\n";

    if (!empty($brief['palette']) && is_array($brief['palette'])) {
        $parts = [];
        foreach ($brief['palette'] as $p) {
            if (empty($p['hex'])) continue;
            $parts[] = $p['hex'] . ' (' . ($p['role'] ?? 'support') . ')';
        }
        if ($parts) $out .= "- PALETTE - use these exact hex values as your CSS custom properties: " . implode(', ', $parts) . "\n";
    }

    if (!empty($brief['sections']) && is_array($brief['sections'])) {
        $out .= "- SECTION PLAN - build exactly these, in this order. Use the given headline text verbatim:\n";
        foreach ($brief['sections'] as $i => $s) {
            $n = $i + 1;
            $img = !empty($s['needs_image']) ? 'needs a real image' : 'no image needed';
            $hl  = $s['headline'] ?? '';
            $pp  = $s['purpose'] ?? '';
            $out .= "    {$n}. [" . ($s['id'] ?? 'section') . "] \"{$hl}\" - {$pp} ({$img})\n";
        }
    }

    if (!empty($brief['signature_detail'])) $out .= "- SIGNATURE DETAIL - you must build this: {$brief['signature_detail']}\n";

    if (!empty($brief['avoid']) && is_array($brief['avoid'])) {
        $out .= "- WRONG FOR THIS CLIENT, do not use: " . implode('; ', array_map('strval', $brief['avoid'])) . "\n";
    }
    return $out;
}
