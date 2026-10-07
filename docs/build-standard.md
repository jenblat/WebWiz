# The WebWiz Build Standard

Version 1.0, October 2026. This is the rulebook for anyone who hand builds or
hand fixes a preview, which is what happened for MRC, and it is the same bar the
generator is held to automatically. Ported from the SeedSite build standard;
the tooling here is the WebWiz worker, `private/lib/qa.php` and the audit CLI.

A WebWiz preview is a website nobody could have generated with a prompt and
nobody could have built on their own. It has to be gorgeous, it has to move,
and it has to beat the website the prospect already has. This document says
what that means in practice and how it is checked.

## 1. The bar

Three things are true of every preview before a reveal link reaches a prospect:

1. The audit returns `preview-ready`. No failing checks, score 85 or more
   (setting `audit_min_score`).
2. The compare returns `beats-current-site`. Ours scores higher than the
   prospect's current website overall and wins or ties every category.
3. A human looked at the desktop and phone screenshots the tools returned and
   would be proud to put their name on them.

The generator enforces 1 and 2 on every variant (`private/worker.php` regenerates
with the failing check ids as feedback, the live `/api/magic.php` path holds a
failing page instead of revealing it). A hand built page goes through the same
two commands before it is sent:

```bash
cd /var/www/sites/trywebwiz/private/qa-tools
node audit.js "https://trywebwiz.com/preview/<token>/v1/index.html" --facts facts.json --shots /tmp/shots
node audit.js --compare "https://trywebwiz.com/preview/<token>/v1/index.html" "https://theirsite.com" --facts facts.json --shots /tmp/shots
```

`facts.json` is `{ "emails": [], "phones": [], "text": "", "images": [], "logo": [] }`
from the scrape (`ww_audit_facts()` builds it from a `scrape_multi()` result). Without
it the four truth checks do not run, and the score is not comparable to a generated
variant's. Run the tools as `www-data`; `private/` is 750.

If any of the three is false, the build is not finished. Keep going.

## 2. Before you design anything

Facts first. The single most damaging defect we have shipped was an invented
contact email on a generated preview (info@mrcbuilt.com, when the real address
was info@michaelrobertsconstruction.com). It cost trust with a lead who had
already said yes in writing. The `invented-contact` check now fails any page
whose `mailto:` or `tel:` is not in the scrape; `invented-stat` fails any number
with a plus sign, a percent sign or a count noun near it that the scrape does
not contain; `image-source` fails any proxied image the scrape did not verify
or any image used twice.

- Open the prospect's current site and read every page. Pull the real phone,
  email, address, licence numbers, people, project names and services. The
  scraper does this for the generator (`emails`, `phones`, `paragraphs`,
  `extra_pages`); for a hand build, do it by hand and keep the list.
- Every fact on the new site comes from the prospect's own material or from
  the person who briefed you. If a number is not in the source, it is not on
  the page. No "500+ projects", no "20 years" unless they said it.
- Keep the prospect's own phrases where they are good. "The best builder, not
  the biggest" came from the client. It is better than anything we would have
  written.
- Use their real photography. Pull it from the current site, resize to a
  maximum of 1800px on the long edge, convert to WebP where possible, keep each
  image under 400 KB. Never a stock photo where a real one exists and never an
  empty image slot. Generated pages proxy images through `/api/img.php`; a hand
  build may keep local copies in `v1/img/`.

## 3. Art direction, not a template

Decide a direction before writing a line of CSS, and write it down in one
sentence at the top of the stylesheet. "Charcoal and MRC red, Archivo display,
drone photography, hard edges, no rounded corners." Everything on the page then
has a reason. The generator gets this from `private/lib/design.php` (an assigned
design DNA per variant plus a per job art direction brief).

- Palette from the prospect's brand. Two neutrals, one brand colour, one
  accent at most. Declare them as CSS variables and use nothing else.
- Two typefaces. One display face with real character, one body face that
  reads at 17px. Load only those two from Google Fonts with `display=swap`.
- Structure is yours to decide. The page order comes from what this business
  needs to say, not from the hero, three icons, stats, testimonials, CTA band
  sequence that every generator produces.
- Headlines must say something only this business could say. Banned: "Ready to
  get started", "Why choose us", "What our clients say", "Trusted by",
  "Everything you need", "Elevate your", "Take your X to the next level", and
  any abstract virtue heading. The audit checks for them.
- Copy reads like a person wrote it. No em dashes, no en dashes, no double
  hyphens anywhere. No "not just X, but Y". No anaphora triads. No model
  vocabulary (elevate, empower, unlock, seamless, robust, leverage, journey,
  curated, bespoke, holistic). Vary sentence length. One true detail beats three
  confident adjectives. `ww_dedash_copy()` strips dashes from generated pages as
  a backstop; a hand build has no backstop.
- One CTA in the first screen, a phone number as a `tel:` link in the header on
  every site that takes calls, a real email as `mailto:` or a real form. Nothing
  links to `#`.

## 4. Motion is mandatory

Every page ships the WebWiz Motion Kit. The generator's `finalize_html()` injects
it; a hand build includes it with the cache buster, which is the kit file's
modification time:

```html
<link rel="stylesheet" href="https://trywebwiz.com/kit/webwiz-motion.css?v=<filemtime>">
<script defer src="https://trywebwiz.com/kit/webwiz-motion.js?v=<filemtime>"></script>
```

The kit guarantees nothing is ever stuck hidden (no JS means a fully visible
page, and the JS force reveals near the viewport after 1.2 seconds and
everything after 6 seconds) and it honours `prefers-reduced-motion`. Never write
your own `opacity:0`, IntersectionObserver or keyframe entrance. If you find
yourself doing that, stop.

Minimum on every site:

| Where | Attribute | Why |
|---|---|---|
| The h1 (text only) | `data-words` | the headline arrives word by word |
| The hero image wrapper (height set) | `data-parallax="0.12"` | depth on the first screen |
| Every section's heading block | `data-reveal` | the page unfolds as it is read |
| Every grid of cards, services, logos, people | `data-reveal-stagger="90"` | cards arrive one after another |
| Two or three light coloured sections | `data-bg-shift` | a gentle tint travels across as you scroll over |
| `<body>` | `data-ambient` | a soft brand coloured field behind the page that moves with scroll |
| The header | `data-nav` | shadow appears once scrolled |
| Cards and project tiles | `data-lift`, `data-zoom` | hover feedback |
| Real numbers only | `data-count` | counts up on reveal |

Set the colour hooks from the prospect's palette, soft tints only:

```css
:root {
  --ww-ambient-a: rgba(217, 34, 31, .07);
  --ww-ambient-b: rgba(21, 24, 27, .07);
  --ww-shift:     rgba(217, 34, 31, .09);
}
```

Restraint is part of the standard. One parallax hero. Word by word on the h1
and at most one more headline. Stagger grids, not paragraphs. Motion should
feel like the page breathing, not like a slideshow.

Two engine lessons already paid for: a fixed full screen layer with
`filter: blur()` re-rasterises every frame and froze a real Chrome tab, so the
ambient layer uses soft radial gradients and no filter. Scroll writes are
throttled (progress and section tint only update when the value changes).
Keep both.

## 5. Mobile is half the audience

The audit renders at 390px and fails the build on horizontal overflow, tap
targets under 40px, text under 13px, a missing CTA in the first phone screen, or
a header with no reachable navigation. Design the phone layout on purpose: stack
the hero, keep the phone number as a button, make footer links tall enough to
tap.

## 6. Performance and the boring parts

- Page under 3 MB, largest image under 500 KB, images carry width and height or
  an aspect ratio so nothing jumps.
- Title 15 to 70 characters, meta description 50 to 160, Open Graph title and
  image, favicon, one h1, headings in order, `<main>` and `<footer>`.
- Every image has alt text. Every link has a name. Inputs have labels.
- Previews carry `noindex` (`ww_noindex_html()`); a hand build must keep that tag.

## 7. The loop

```
scrape_multi() or read the current site by hand, collect facts (emails, phones, numbers, images)
write v1/index.html with the kit tags and the attributes
audit.js <preview url> --facts     -> fix every fail, re-run until preview-ready
audit.js --compare <ours> <theirs> -> fix every losing category, re-run until beats-current-site
send the preview with the side by side screenshot
```

Do not send a preview that has not been through the loop. Do not describe a
site as finished in a message to a human until the two verdicts are in the
transcript.

## 8. What the prospect receives

The reveal link (`/try/?t=<token>`), which now shows the side by side desktop
composite above the preview with the two scores and the plain facts the audit
measured (load time, phone behaviour, broken images, tap targets), and three
sentences: what we kept from their current site, what we changed and why, and
the one thing we would like them to look at first on a phone.

## 9. Where things live

| What | Where |
|---|---|
| Kit | `public/kit/webwiz-motion.css`, `public/kit/webwiz-motion.js` (bump the version comment when they change) |
| Injection | `ww_inject_motion_kit()` in `private/worker.php`, called by `finalize_html()` |
| Audit engine | `private/qa-tools/audit.js` (CLI and `auditUrl()` / `compareSites()` exports) |
| One Chrome at a time | `private/qa-tools/chromelock.js`, used by audit.js and shot.js |
| PHP layer | `ww_audit_*` and `ww_compare_variant()` in `private/lib/qa.php` |
| Results | `previews.audit_score`, `audit_verdict`, `audit_json`, `compare_verdict`, `compare_json`; `/preview/<token>/compare-v<N>.json` and `compare-v<N>-desktop.jpg` / `-mobile.jpg` |
| Current site cache | `data/audit-cache/<sha1 of url>.json`, seven days |
| Settings | `audit_enabled`, `audit_min_score`, `audit_block_on_fail`, `compare_enabled` |
