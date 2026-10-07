'use strict';
/**
 * WebWiz site audit.
 *
 * Renders a page in headless Chrome at desktop (1440) and phone (390) widths,
 * scrolls it the way a visitor would, and runs the checks a WebWiz variant has
 * to pass before it is revealed to a prospect. Ported from SeedSite's
 * site-audit.js (BusySeed/SeedSite commit c0a725b) with the WebWiz motion kit
 * names and four WebWiz specific truth checks (invented-contact, invented-stat,
 * image-source, kit-present), which only run when the caller passes the
 * scrape facts for the prospect.
 *
 * Works on any URL, so the same engine scores the prospect's current website
 * and the variant we built, and compareSites() puts the two side by side.
 *
 * CLI
 *   node audit.js <url> [--json out.json] [--shots dir] [--facts facts.json] [--full]
 *   node audit.js --compare <ourUrl> <theirUrl> [--json out.json] [--shots dir]
 *                 [--facts facts.json] [--their-cache theirs.json]
 *                 [--ours-json audit.json --ours-shots dir]   reuse an audit of ours that just ran
 *                 [--label "Your new site"]
 *
 * One Chrome at a time (chromelock.js). Two concurrent renders saturate the
 * 2 core box.
 */
const fs = require('fs');
const path = require('path');
const sharp = require('sharp');
const chromelock = require('./chromelock');

const CHROMIUM = process.env.WW_CHROMIUM || '/bin/google-chrome-stable';
const NAV_TIMEOUT = 25000;
const THEIR_CACHE_TTL_MS = 7 * 24 * 3600 * 1000;

// Phrases that mark a page as generated. Mirrors the generator rules in
// private/worker.php, which were written from client rejections.
const TEMPLATE_PHRASES = [
  'ready to get started', 'ready to transform', 'what our clients say', 'trusted by',
  'everything you need', 'why choose us', "let's build something together",
  'take your business to the next level', 'to the next level', 'elevate your',
  'in today\'s fast-paced world', 'at the end of the day', 'our core values',
  'uncompromising integrity', 'unwavering commitment', 'relentless excellence',
];
const BANNED_WORDS = [
  'elevate', 'empower', 'unlock', 'seamless', 'robust', 'leverage', 'delve', 'realm',
  'tapestry', 'testament', 'journey', 'curated', 'bespoke', 'holistic', 'synergy', 'transform your',
];
const PLACEHOLDER_RE = /lorem ipsum|dolor sit amet|placeholder|\btodo\b|\btbd\b|\bxxx+\b|\[(?:insert|your|client|company|phone|email|address)[^\]]*\]|coming soon/i;

function hostOf(url) { try { return new URL(url).host.replace(/^www\./, ''); } catch { return ''; } }

async function withBrowser(fn) {
  let puppeteer;
  try { puppeteer = require('puppeteer-core'); } catch { throw new Error('puppeteer-core not installed'); }
  const release = await chromelock.acquire();
  const ud = fs.mkdtempSync('/tmp/wwaudit-');
  // Same launch shape as shot.js: HOME must be writable and the crash
  // subsystem fully disabled or Chrome's crashpad child fails with
  // "--database is required". userDataDir is passed as a launch option so
  // puppeteer does not provision its own profile dir that nobody deletes.
  const prevHome = process.env.HOME;
  process.env.HOME = ud;
  let browser;
  try {
    browser = await puppeteer.launch({
      executablePath: CHROMIUM, headless: 'new', userDataDir: ud,
      args: [
        '--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--no-zygote',
        '--disable-breakpad', '--no-crash-upload', '--disable-features=Crashpad,DialMediaRouteProvider',
        '--user-data-dir=' + ud, '--crash-dumps-dir=' + ud,
        '--hide-scrollbars', '--force-color-profile=srgb',
        '--disable-background-networking', '--disable-default-apps', '--no-first-run',
        '--no-default-browser-check', '--disable-extensions',
      ],
    });
    return await fn(browser);
  } finally {
    if (browser) await browser.close().catch(() => {});
    if (prevHome !== undefined) process.env.HOME = prevHome;
    try { fs.rmSync(ud, { recursive: true, force: true }); } catch {}
    release();
  }
}

async function scrollThrough(page) {
  await page.evaluate(async () => {
    // One step per rendered frame. A timer based scroll outruns headless
    // Chrome's frame rate and whole sections pass through the viewport without
    // ever being painted, so IntersectionObserver reveals never fire and the
    // page screenshots with blank bands. That was the real cause of the "empty
    // section" defect in a second disguise. Keep this frame paced.
    await new Promise((resolve) => {
      const step = Math.max(300, Math.floor(window.innerHeight * 0.75));
      const deadline = Date.now() + 12000;
      (function next() {
        window.scrollBy(0, step);
        const atEnd = window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 2;
        if (atEnd || Date.now() > deadline) return resolve();
        requestAnimationFrame(next);
      })();
    });
    document.querySelectorAll('img[loading="lazy"]').forEach((i) => { i.loading = 'eager'; });
    window.scrollTo(0, 0);
  });
  await page.evaluate(async () => {
    const pending = [...document.images].filter((i) => !i.complete);
    await Promise.race([
      Promise.all(pending.map((i) => new Promise((r) => { i.onload = i.onerror = r; }))),
      new Promise((r) => setTimeout(r, 3000)),
    ]);
  });
  await new Promise((r) => setTimeout(r, 2500)); // let the kit's 1.2s failsafe, transitions and web fonts settle
}

// Everything we can learn from the DOM in one pass. Runs inside the page.
function collectDesktopFacts() {
  const out = {};
  const q = (s) => Array.from(document.querySelectorAll(s));
  const vis = (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none'; };
  const attr = (s, a) => { const el = document.querySelector(s); return el ? (el.getAttribute(a) || '') : ''; };

  out.doctype = !!document.doctype;
  out.lang = document.documentElement.getAttribute('lang') || '';
  out.title = document.title || '';
  out.description = attr('meta[name="description"]', 'content');
  out.viewport = attr('meta[name="viewport"]', 'content');
  out.canonical = attr('link[rel="canonical"]', 'href');
  out.ogTitle = attr('meta[property="og:title"]', 'content');
  out.ogImage = attr('meta[property="og:image"]', 'content');
  out.favicon = !!document.querySelector('link[rel~="icon"], link[rel="apple-touch-icon"]');
  out.charset = !!document.querySelector('meta[charset]');

  const hs = q('h1,h2,h3,h4,h5,h6').filter(vis);
  out.h1 = q('h1').map((h) => h.textContent.trim().replace(/\s+/g, ' '));
  out.headingSkips = 0;
  let last = 0;
  for (const h of hs) { const n = +h.tagName[1]; if (last && n > last + 1) out.headingSkips++; last = n; }
  const h1 = q('h1')[0];
  out.h1Px = h1 ? parseFloat(getComputedStyle(h1).fontSize) : 0;
  out.h1Words = !!(h1 && (h1.hasAttribute('data-words') || h1.querySelector('[data-words]')));
  out.bodyPx = parseFloat(getComputedStyle(document.body).fontSize) || 0;
  out.footer = !!document.querySelector('footer');
  out.main = !!document.querySelector('main');

  // images
  const imgs = q('img');
  out.images = imgs.length;
  out.imgNoAlt = imgs.filter((i) => !i.hasAttribute('alt')).length;
  out.imgBroken = imgs.filter((i) => vis(i) && i.complete && i.naturalWidth === 0).map((i) => (i.currentSrc || i.src || '').slice(0, 160));
  out.imgNoSize = imgs.filter((i) => !(i.getAttribute('width') && i.getAttribute('height')) && !/\d/.test(String(getComputedStyle(i).aspectRatio || ''))).length;
  out.imgSrcs = [...new Set(imgs.map((i) => i.currentSrc || i.src).filter(Boolean))];
  // every place an image URL can be written, for the image-source check
  const proxied = [];
  for (const i of imgs) {
    for (const s of [i.getAttribute('src') || '', i.getAttribute('srcset') || '']) {
      for (const m of s.matchAll(/\/api\/img\.php\?[^\s,"')]+/g)) proxied.push(m[0]);
    }
  }
  for (const el of q('[style*="img.php"]')) for (const m of (el.getAttribute('style') || '').matchAll(/\/api\/img\.php\?[^\s"')]+/g)) proxied.push(m[0]);
  for (const st of q('style')) for (const m of (st.textContent || '').matchAll(/\/api\/img\.php\?[^\s"')]+/g)) proxied.push(m[0]);
  out.proxiedImages = proxied;

  // links
  const links = q('a[href]');
  out.links = links.length;
  out.tel = links.filter((a) => /^tel:/i.test(a.getAttribute('href'))).map((a) => a.getAttribute('href'));
  out.mailto = links.filter((a) => /^mailto:/i.test(a.getAttribute('href'))).map((a) => a.getAttribute('href').replace(/^mailto:/i, '').split('?')[0]);
  out.hashOnly = links.filter((a) => a.getAttribute('href').trim() === '#').length;
  out.jsHref = links.filter((a) => /^javascript:/i.test(a.getAttribute('href'))).length;
  out.deadAnchors = links.map((a) => a.getAttribute('href')).filter((h) => /^#.+/.test(h)).filter((h) => { try { return !document.querySelector(h) && !document.getElementById(h.slice(1)); } catch { return true; } });
  out.exampleLinks = links.filter((a) => /example\.(com|org|net)|yourdomain|yoursite/i.test(a.getAttribute('href'))).length;
  out.linksNoText = links.filter((a) => vis(a) && !a.textContent.trim() && !a.getAttribute('aria-label') && !a.querySelector('img[alt]:not([alt=""])') && !a.getAttribute('title')).length;
  out.externalLinks = links.filter((a) => /^https?:/i.test(a.getAttribute('href')) && !a.href.includes(location.host)).length;

  // forms
  out.forms = q('form').length;
  out.embeddedForms = q('iframe[src*="jotform"], iframe[src*="typeform"], iframe[src*="hubspot"], iframe[src*="calendly"], iframe[src*="forms"]').length;
  out.inputsNoLabel = q('input:not([type=hidden]):not([type=submit]):not([type=button]), textarea, select').filter((i) => {
    if (i.getAttribute('aria-label') || i.getAttribute('aria-labelledby') || i.getAttribute('placeholder')) return false;
    if (i.id && document.querySelector('label[for="' + CSS.escape(i.id) + '"]')) return false;
    return !i.closest('label');
  }).length;
  out.buttonsNoText = q('button').filter((b) => vis(b) && !b.textContent.trim() && !b.getAttribute('aria-label') && !b.getAttribute('title')).length;

  // visible text
  const text = (document.body.innerText || '').replace(/\s+/g, ' ');
  out.textLength = text.length;
  out.text = text.slice(0, 60000);
  out.placeholder = (text.match(PLACEHOLDER_SRC) || []).slice(0, 5);
  out.dashes = { em: (text.match(/—/g) || []).length, en: (text.match(/–/g) || []).length, dbl: (text.match(/\s--\s/g) || []).length };
  const lower = text.toLowerCase();
  out.templatePhrases = TEMPLATE_SRC.filter((p) => lower.includes(p));
  out.bannedWords = BANNED_SRC.filter((w) => new RegExp('\\b' + w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b', 'i').test(lower));
  out.phonesInText = [...new Set((text.match(/\(?\b\d{3}\)?[\s.-]\d{3}[\s.-]\d{4}\b/g) || []))].slice(0, 5);
  // claims that read as statistics: a number with + or %, or a number near a
  // count noun. Each is checked against the scrape text by the invented-stat check.
  const stats = [];
  // identifiers are not statistics: phone numbers, licence and suite numbers, zip codes, prices, years
  const statText = text
    .replace(/(?:\+?1[\s.-]?)?\(?\b[2-9]\d{2}\)?[\s.-]\d{3}[\s.-]\d{4}\b/g, ' ')
    .replace(/\$\s*[\d,.]+\s*[KMB]?\b/g, ' ')
    .replace(/(?:#|\bno\.?|\blic(?:ense|ence)?\.?|\bsuite|\bste\.?|\bunit|\bp\.?o\.? box|\bCA\s+\d*)\s*#?\s*[\dA-Za-z-]+/gi, ' ')
    .replace(/\b[A-Z]{2}\s+\d{5}(?:-\d{4})?\b/g, ' ')
    .replace(/\b(?:19|20)\d{2}\b/g, ' ');
  for (const m of statText.matchAll(/(\d[\d,.]*)\s*(\+|%|percent\b)/gi)) stats.push({ n: m[1], ctx: statText.slice(Math.max(0, m.index - 20), m.index + m[0].length + 20) });
  for (const m of statText.matchAll(/(\d[\d,.]*)\+?(?:\s+[a-z]+){0,2}\s+(years?|yrs?|projects?|clients?|customers?|reviews?)\b/gi)) stats.push({ n: m[1], ctx: statText.slice(Math.max(0, m.index - 20), m.index + m[0].length + 20) });
  for (const el of q('[data-count]')) stats.push({ n: el.getAttribute('data-count') || '', ctx: 'data-count on ' + (el.textContent || '').trim().slice(0, 40) });
  out.statClaims = stats.slice(0, 40);

  // fonts
  const fams = new Set();
  for (const el of q('body, p, h1, h2, h3, a, li, button, span').slice(0, 400)) {
    const f = getComputedStyle(el).fontFamily.split(',')[0].trim().replace(/["']/g, '');
    if (f) fams.add(f);
  }
  out.fontFamilies = [...fams];
  out.googleFonts = q('link[href*="fonts.googleapis"]').length;

  // sections: dead bands (tall, nothing in them)
  const blocks = q('main > *, body > section, body > header, body > div, body > article, body > footer').filter(vis)
    .filter((b) => b.getAttribute('aria-hidden') !== 'true' && !b.classList.contains('ww-ambient') && getComputedStyle(b).position !== 'fixed');
  out.sections = q('section, article').length;
  out.deadSections = blocks.filter((b) => {
    const r = b.getBoundingClientRect();
    if (r.height < 280) return false;
    const t = (b.innerText || '').trim().length;
    const im = b.querySelectorAll('img, video, svg, iframe, canvas').length;
    return t < 30 && im === 0;
  }).map((b) => (b.id ? '#' + b.id : b.tagName.toLowerCase() + (b.className ? '.' + String(b.className).split(' ')[0] : '')));

  // layout
  const hdr = document.querySelector('header, nav, [data-nav]');
  out.stickyNav = !!hdr && ['sticky', 'fixed'].includes(getComputedStyle(hdr).position);
  const ctas = q('a, button').filter((el) => vis(el) && el.getBoundingClientRect().top < window.innerHeight && el.getBoundingClientRect().top > 0)
    .filter((el) => { const cs = getComputedStyle(el); const t = el.textContent.trim(); return t.length > 1 && t.length < 40 && (cs.backgroundColor !== 'rgba(0, 0, 0, 0)' || /^tel:|#contact|#estimate|#quote|#book|#start/i.test(el.getAttribute('href') || '') || el.tagName === 'BUTTON'); });
  out.ctaAboveFold = ctas.length;
  out.ctaTexts = ctas.slice(0, 4).map((c) => c.textContent.trim());
  out.heroImageAboveFold = q('img, video').some((i) => vis(i) && i.getBoundingClientRect().top < window.innerHeight && i.getBoundingClientRect().width > 300);

  // motion kit and reveals
  const kitCss = q('link[rel="stylesheet"][href*="webwiz-motion"]');
  const kitJs = q('script[src*="webwiz-motion"]');
  out.kit = {
    css: kitCss.length > 0,
    js: kitJs.length > 0 || !!window.WebWizMotion,
    cssInHead: kitCss.some((l) => l.closest('head')),
    jsInHead: kitJs.some((s) => s.closest('head')),
    version: window.WebWizMotion ? window.WebWizMotion.version : null,
    active: document.documentElement.classList.contains('ww-motion'),
    reveals: q('[data-reveal]').length + q('[data-words]').length,
    staggers: q('[data-reveal-stagger]').length,
    parallax: q('[data-parallax]').length,
    bgShift: q('[data-bg-shift]').length,
    ambient: document.body.hasAttribute('data-ambient'),
    counters: q('[data-count]').length,
    hover: q('[data-lift], [data-zoom]').length,
    marquee: q('[data-marquee]').length,
  };
  // any animation library at all, for sites that are not ours
  out.otherMotion = q('[data-aos], .aos-init, .wow, [data-scroll], .gsap, .fade-up, .fade-in, .reveal').length;
  // what is still invisible after a full scroll and the failsafe window.
  // stuck means invisible with no reveal in flight: anything inside a .ww-in
  // element is mid transition by definition and will finish on its own
  out.hiddenAfterLoad = q('body *').slice(0, 4000).filter((el) => {
    if (el.closest('script, style, noscript, template, [aria-hidden="true"], nav, .ww-ambient, .ww-in')) return false;
    const r = el.getBoundingClientRect(); if (r.width < 40 || r.height < 20) return false;
    const cs = getComputedStyle(el);
    return cs.opacity === '0' || cs.visibility === 'hidden';
  }).length;
  out.reducedMotionRule = (() => {
    try {
      for (const ss of document.styleSheets) {
        let rules; try { rules = ss.cssRules; } catch { continue; }
        for (const r of rules) if (r.media && /prefers-reduced-motion/.test(r.media.mediaText)) return true;
      }
    } catch {}
    return false;
  })();
  out.transitions = q('a, button, [class*=card], [class*=btn]').filter((el) => { const t = getComputedStyle(el).transitionDuration; return t && t !== '0s'; }).length;

  // contrast on a sample of text elements
  const parse = (c) => { const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(',').map(parseFloat); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
  const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const bgOf = (el) => { let n = el; while (n && n !== document.documentElement) { const cs = getComputedStyle(n); if (cs.backgroundImage !== 'none') return null; const c = parse(cs.backgroundColor); if (c && c.a >= 0.85) return c; n = n.parentElement; } const c = parse(getComputedStyle(document.documentElement).backgroundColor); return c && c.a > 0 ? c : { r: 255, g: 255, b: 255, a: 1 }; };
  const sample = q('p, h1, h2, h3, li, a, span, small, b, strong, label').filter(vis).filter((el) => el.children.length === 0 && el.textContent.trim().length > 2).slice(0, 350);
  const low = [];
  for (const el of sample) {
    const cs = getComputedStyle(el); const fg = parse(cs.color); const bg = bgOf(el);
    if (!fg || !bg || fg.a < 0.9) continue;
    // text sitting over a photo or video has no measurable background
    const rb = el.getBoundingClientRect();
    const under = document.elementsFromPoint(Math.min(window.innerWidth - 1, rb.left + rb.width / 2), Math.min(window.innerHeight - 1, Math.max(0, rb.top + rb.height / 2)));
    if (under.some((n) => /^(IMG|VIDEO|CANVAS|PICTURE|SVG)$/i.test(n.tagName))) continue;
    const l1 = lum(fg), l2 = lum(bg); const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    const px = parseFloat(cs.fontSize); const bold = parseInt(cs.fontWeight, 10) >= 700;
    const need = (px >= 24 || (px >= 18.66 && bold)) ? 3 : 4.5;
    if (ratio < need) low.push({ text: el.textContent.trim().slice(0, 40), ratio: +ratio.toFixed(2), need });
  }
  out.lowContrast = low.slice(0, 8);
  out.lowContrastCount = low.length;

  // performance
  const nav = performance.getEntriesByType('navigation')[0];
  out.timing = nav ? { dcl: Math.round(nav.domContentLoadedEventEnd), load: Math.round(nav.loadEventEnd), ttfb: Math.round(nav.responseStart) } : null;
  out.scripts = q('script[src]').map((s) => s.src);
  out.thirdPartyScripts = out.scripts.filter((s) => !s.includes(location.host)).length;
  out.frameworks = out.scripts.filter((s) => /jquery|react|vue|angular|bootstrap|elementor|wp-includes|wp-content/i.test(s)).length;
  out.wordpress = !!document.querySelector('link[href*="wp-content"], script[src*="wp-content"], meta[name="generator"][content*="WordPress"]');
  out.generator = attr('meta[name="generator"]', 'content');
  out.pageHeight = document.documentElement.scrollHeight;
  return out;
}

function collectMobileFacts() {
  const out = {};
  const q = (s) => Array.from(document.querySelectorAll(s));
  const vis = (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none'; };
  out.vw = window.innerWidth;
  out.scrollWidth = document.documentElement.scrollWidth;
  out.overflow = document.documentElement.scrollWidth > window.innerWidth + 1;
  out.overflowers = q('body *').slice(0, 4000).filter((el) => { const r = el.getBoundingClientRect(); return r.right > window.innerWidth + 2 && r.width > 40 && getComputedStyle(el).position !== 'fixed'; })
    .slice(0, 6).map((el) => el.tagName.toLowerCase() + (el.id ? '#' + el.id : el.className ? '.' + String(el.className).split(' ')[0] : ''));
  // a link set inline inside running text (a sentence in a <p> or <li>) is a
  // reading link, not a tap target; buttons, nav, footer and card links all count
  const inlineText = (el) => { if (el.tagName !== 'A') return false; if (getComputedStyle(el).display !== 'inline') return false; const par = el.parentElement; if (!par || !/^(P|LI|TD|BLOCKQUOTE|FIGCAPTION|DD|SMALL)$/.test(par.tagName)) return false; const txt = (par.textContent || '').trim().length, own = (el.textContent || '').trim().length; return txt > own + 20; };
  const targets = q('a[href], button, input[type=submit], [role=button]').filter(vis).filter((el) => !inlineText(el));
  out.smallTargets = targets.filter((el) => { const r = el.getBoundingClientRect(); return r.height < 40 || r.width < 40; }).length;
  out.targets = targets.length;
  const textEls = q('p, li, a, span, small, label, td, b, strong').filter(vis).filter((el) => el.textContent.trim().length > 2);
  const sizes = textEls.map((el) => parseFloat(getComputedStyle(el).fontSize));
  out.minFont = sizes.length ? Math.min(...sizes) : 0;
  out.tinyText = sizes.filter((s) => s < 13).length;
  const h1 = document.querySelector('h1');
  out.h1Px = h1 ? parseFloat(getComputedStyle(h1).fontSize) : 0;
  out.bodyPx = parseFloat(getComputedStyle(document.body).fontSize) || 0;
  const navLinks = q('header a, nav a').filter(vis);
  const menuBtn = q('button, [role=button], a').filter(vis).some((el) => /menu|nav|burger|hamburger/i.test((el.getAttribute('aria-label') || '') + ' ' + (el.className || '') + ' ' + (el.id || '') + ' ' + el.textContent));
  out.navReachable = navLinks.length >= 2 || menuBtn || q('a[href^="tel:"], a[href^="#"]').filter(vis).length >= 2;
  const ctas = q('a, button').filter((el) => vis(el) && el.getBoundingClientRect().top < window.innerHeight && el.getBoundingClientRect().top >= 0).filter((el) => el.textContent.trim().length > 1 && el.textContent.trim().length < 40);
  out.ctaAboveFold = ctas.length;
  // an image scaled inside a clipped wrapper (parallax, zoom) is not overflow
  const clipped = (el) => { let n = el.parentElement, d = 0; while (n && d++ < 4) { const o = getComputedStyle(n).overflow; if (/hidden|clip/.test(o)) return true; n = n.parentElement; } return false; };
  out.wideImages = q('img').filter((i) => vis(i) && i.getBoundingClientRect().width > window.innerWidth + 2 && !clipped(i)).length;
  out.pageHeight = document.documentElement.scrollHeight;
  return out;
}

// evaluate() needs the constants in page scope; inject them as source.
function withConsts(fnSource) {
  return `(() => { const PLACEHOLDER_SRC = ${PLACEHOLDER_RE.toString()}; const TEMPLATE_SRC = ${JSON.stringify(TEMPLATE_PHRASES)}; const BANNED_SRC = ${JSON.stringify(BANNED_WORDS)}; return (${fnSource})(); })()`;
}

async function fetchHead(url, ms = 6000) {
  const ctrl = new AbortController(); const t = setTimeout(() => ctrl.abort(), ms);
  try { const r = await fetch(url, { signal: ctrl.signal, redirect: 'follow', headers: { 'user-agent': 'WebWizAudit/1.0' } }); const txt = await r.text(); return { status: r.status, text: txt.slice(0, 4000) }; }
  catch (e) { return { status: 0, error: e.message }; }
  finally { clearTimeout(t); }
}

async function shot(page, opts = {}) {
  const raw = await page.screenshot({ fullPage: !!opts.fullPage, type: 'png' });
  let img = sharp(raw).resize({ width: opts.width || 1200, withoutEnlargement: true });
  if (opts.fullPage) {
    const m = await sharp(raw).metadata();
    if (m.height > 6000) img = img.extract({ left: 0, top: 0, width: m.width, height: 6000 });
  }
  const buf = await img.jpeg({ quality: 74 }).toBuffer();
  return buf;
}

/**
 * Audit one URL.
 * @param {string} url
 * @param {object} opts  { screenshots, fullPage, facts }
 *   facts: { emails: [], phones: [], text: '', images: [], logo: [] } from the
 *   prospect's scrape. When present the four WebWiz truth checks run.
 * @returns {object} { url, checks, score, groups, verdict, facts, screenshots }
 */
async function auditUrl(url, opts = {}) {
  const started = Date.now();
  const facts = { desktop: null, mobile: null, resources: null, console: [], failed: [], seoFiles: null };
  const screenshots = {};

  await withBrowser(async (browser) => {
    const page = await browser.newPage();
    const res = { count: 0, bytes: 0, byType: {}, largestImage: null, failed: [] };
    const consoleErrors = [];
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 200)); });
    page.on('pageerror', (e) => consoleErrors.push(String(e.message || e).slice(0, 200)));
    page.on('requestfailed', (r) => { if (!/beacon|analytics|gtm|facebook|doubleclick|\/events\?|sentry|hotjar|clarity|openai\.com/i.test(r.url())) res.failed.push({ url: r.url().slice(0, 160), reason: (r.failure() || {}).errorText }); });
    page.on('response', async (r) => {
      try {
        const req = r.request(); const type = req.resourceType();
        if (r.status() >= 400 && !/beacon|analytics|gtm|facebook|doubleclick|\/events\?|sentry|hotjar|clarity|openai\.com/i.test(r.url())) res.failed.push({ url: r.url().slice(0, 160), status: r.status() });
        if (r.status() >= 300 && r.status() < 400) return;
        let len = parseInt(r.headers()['content-length'] || '0', 10);
        if (!len && ['image', 'stylesheet', 'script', 'font', 'document'].includes(type)) { try { len = (await r.buffer()).length; } catch {} }
        res.count++; res.bytes += len; res.byType[type] = (res.byType[type] || 0) + len;
        if (type === 'image' && (!res.largestImage || len > res.largestImage.bytes)) res.largestImage = { url: r.url().slice(0, 160), bytes: len };
      } catch {}
    });
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: NAV_TIMEOUT });
    facts.status = resp ? resp.status() : 0;
    facts.finalUrl = page.url();
    if (!resp || resp.status() >= 400) throw new Error(`${url} returned ${resp ? resp.status() : 'no response'}`);
    await scrollThrough(page);
    facts.desktop = await page.evaluate(withConsts(collectDesktopFacts.toString()));
    if (opts.screenshots !== false) {
      screenshots.desktop = await shot(page, { width: 1200 });
      if (opts.fullPage) screenshots.desktopFull = await shot(page, { width: 900, fullPage: true });
    }
    facts.resources = res; facts.console = consoleErrors.slice(0, 10);
    await page.close();

    const m = await browser.newPage();
    await m.setViewport({ width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
    await m.setUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1 WebWizAudit');
    await m.goto(url, { waitUntil: 'domcontentloaded', timeout: NAV_TIMEOUT });
    await scrollThrough(m);
    facts.mobile = await m.evaluate(`(${collectMobileFacts.toString()})()`);
    if (opts.screenshots !== false) screenshots.mobile = await shot(m, { width: 390 });
    await m.close();
  });

  // SEO files on the same origin + path prefix
  try {
    const u = new URL(facts.finalUrl || url);
    const base = u.origin + (u.pathname.replace(/\/[^/]*$/, '') || '');
    const [robots, sitemap, llms] = await Promise.all([fetchHead(base + '/robots.txt'), fetchHead(base + '/sitemap.xml'), fetchHead(base + '/llms.txt')]);
    facts.seoFiles = { robots: robots.status === 200 && !/<html/i.test(robots.text), sitemap: sitemap.status === 200 && /<urlset|<sitemapindex/i.test(sitemap.text), llms: llms.status === 200 && !/<html/i.test(llms.text) };
  } catch { facts.seoFiles = null; }

  facts.isPreview = /\/preview\/[a-f0-9]{6,}\//i.test(facts.finalUrl || url);
  const checks = runChecks(facts, opts.facts || null);
  const summary = score(checks);
  // the page text is only needed by the truth checks; keep the stored facts small
  if (facts.desktop) delete facts.desktop.text;
  return {
    url, final_url: facts.finalUrl, host: hostOf(url), duration_ms: Date.now() - started,
    ...summary, checks, facts, screenshots,
  };
}

// ---- helpers for the WebWiz truth checks ----
const digitsOf = (s) => String(s || '').replace(/\D+/g, '');
const phoneKey = (s) => { const d = digitsOf(s); return d.length > 10 ? d.slice(-10) : d; };
const emailKey = (s) => String(s || '').trim().toLowerCase();
function imageKey(u) {
  let s = String(u || '').trim().toLowerCase();
  try { s = decodeURIComponent(s); } catch {}
  s = s.replace(/^https?:\/\//, '').replace(/^www\./, '').split('#')[0].split('?')[0];
  // CDN size variants of the same file are the same image
  s = s.replace(/-\d{2,4}(w|h|x\d{2,4})(?=\.[a-z0-9]{2,5}$)/, '');
  return s;
}
function proxyTarget(proxyUrl) {
  // /api/img.php?u=<encoded>&l=... -> decoded u
  const s = String(proxyUrl).replace(/&amp;/g, '&');
  const m = s.match(/[?&]u=([^&]+)/);
  if (!m) return '';
  try { return decodeURIComponent(m[1]); } catch { return m[1]; }
}

function runChecks(f, sf) {
  const d = f.desktop || {}, m = f.mobile || {}, r = f.resources || {}, k = d.kit || {};
  const C = [];
  const add = (id, group, weight, status, detail) => C.push({ id, group, weight, status, detail });
  const ok = (cond) => (cond ? 'pass' : 'fail');
  const warn = (cond) => (cond ? 'pass' : 'warn');

  // ---- structure & seo ----
  add('doctype', 'structure', 1, ok(d.doctype), d.doctype ? 'HTML5 doctype present' : 'Missing <!doctype html>');
  add('lang', 'structure', 1, ok(!!d.lang), d.lang ? `lang="${d.lang}"` : 'No lang attribute on <html>');
  add('title', 'structure', 2, d.title.length >= 15 && d.title.length <= 70 ? 'pass' : (d.title ? 'warn' : 'fail'), d.title ? `"${d.title}" (${d.title.length} chars, aim 15 to 70)` : 'No <title>');
  add('meta-description', 'structure', 2, d.description.length >= 50 && d.description.length <= 165 ? 'pass' : (d.description ? 'warn' : 'fail'), d.description ? `${d.description.length} chars (aim 50 to 160)` : 'No meta description');
  add('viewport', 'structure', 1, ok(/width=device-width/.test(d.viewport)), d.viewport || 'No viewport meta');
  add('open-graph', 'structure', 1, warn(!!d.ogTitle && !!d.ogImage), d.ogTitle && d.ogImage ? 'og:title and og:image set' : 'Missing og:title or og:image, link previews will look poor');
  add('favicon', 'structure', 1, warn(d.favicon), d.favicon ? 'Favicon linked' : 'No favicon');
  add('one-h1', 'structure', 2, d.h1.length === 1 ? 'pass' : 'fail', d.h1.length === 1 ? `h1: "${d.h1[0].slice(0, 80)}"` : `${d.h1.length} h1 elements (need exactly one)`);
  add('heading-order', 'structure', 1, warn(d.headingSkips === 0), d.headingSkips ? `${d.headingSkips} heading level skips` : 'Heading levels descend in order');
  add('landmarks', 'structure', 1, warn(d.footer && d.main), `${d.main ? '<main>' : 'no <main>'}, ${d.footer ? '<footer>' : 'no <footer>'}`);
  // robots.txt, sitemap.xml and llms.txt belong to the host, not the page. A WebWiz
  // preview lives under /preview/<token>/ where they cannot exist, and SeedSite
  // regenerates them when the site goes live, so they are not scored for a preview.
  // They still count for the prospect's current site, which is the real host.
  if (!f.isPreview) {
    add('robots-txt', 'structure', 1, warn(!!(f.seoFiles && f.seoFiles.robots)), f.seoFiles && f.seoFiles.robots ? 'robots.txt served' : 'No robots.txt');
    add('sitemap', 'structure', 1, warn(!!(f.seoFiles && f.seoFiles.sitemap)), f.seoFiles && f.seoFiles.sitemap ? 'sitemap.xml served' : 'No sitemap.xml');
    add('llms-txt', 'structure', 1, warn(!!(f.seoFiles && f.seoFiles.llms)), f.seoFiles && f.seoFiles.llms ? 'llms.txt served (AI crawlers get a summary)' : 'No llms.txt');
  }

  // ---- content truth & copy ----
  add('no-placeholder', 'content', 4, ok(!d.placeholder || d.placeholder.length === 0), d.placeholder && d.placeholder.length ? 'Placeholder copy found: ' + d.placeholder.join(' | ') : 'No lorem, placeholder or TODO text');
  const dashTotal = d.dashes ? d.dashes.em + d.dashes.en + d.dashes.dbl : 0;
  add('no-dashes', 'content', 3, ok(dashTotal === 0), dashTotal ? `${d.dashes.em} em dashes, ${d.dashes.en} en dashes, ${d.dashes.dbl} double hyphens in visible copy` : 'No em or en dashes in copy');
  add('no-template-phrases', 'content', 2, ok(!d.templatePhrases || d.templatePhrases.length === 0), d.templatePhrases && d.templatePhrases.length ? 'Generic headline tells: ' + d.templatePhrases.join(', ') : 'No stock template phrases');
  add('no-banned-words', 'content', 1, warn(!d.bannedWords || d.bannedWords.length === 0), d.bannedWords && d.bannedWords.length ? 'Model vocabulary: ' + d.bannedWords.join(', ') : 'No model vocabulary');
  add('phone-link', 'content', 2, ok(d.tel && d.tel.length > 0), d.tel && d.tel.length ? `tel: link ${d.tel[0]}` : (d.phonesInText && d.phonesInText.length ? `Phone shown (${d.phonesInText[0]}) but not a tel: link` : 'No phone number'));
  add('contact-path', 'content', 2, ok((d.mailto && d.mailto.length) || d.forms || d.embeddedForms), (d.mailto && d.mailto.length) ? `mailto: ${d.mailto[0]}` : (d.forms || d.embeddedForms ? 'Contact form present' : 'No email link and no form'));
  add('no-example-links', 'content', 1, ok(!d.exampleLinks), d.exampleLinks ? `${d.exampleLinks} links to example or placeholder domains` : 'No placeholder domains');
  add('anchors-resolve', 'content', 2, ok(!d.deadAnchors || d.deadAnchors.length === 0), d.deadAnchors && d.deadAnchors.length ? 'Anchors with no target: ' + d.deadAnchors.slice(0, 6).join(', ') : 'Every in-page anchor resolves');
  add('no-empty-links', 'content', 1, ok(!d.hashOnly && !d.jsHref), (d.hashOnly || d.jsHref) ? `${d.hashOnly} href="#" and ${d.jsHref} javascript: links` : 'No dead links');
  add('no-dead-sections', 'content', 2, ok(!d.deadSections || d.deadSections.length === 0), d.deadSections && d.deadSections.length ? 'Tall empty bands: ' + d.deadSections.join(', ') : 'Every section has visible content');
  add('images-load', 'content', 3, ok(!d.imgBroken || d.imgBroken.length === 0), d.imgBroken && d.imgBroken.length ? `${d.imgBroken.length} broken images: ` + d.imgBroken.slice(0, 3).join(', ') : `${d.images} images, all loaded`);
  add('real-imagery', 'content', 2, d.images >= 4 ? 'pass' : (d.images >= 1 ? 'warn' : 'fail'), `${d.images} images on the page (aim for 4 or more real photos)`);

  // ---- WebWiz truth checks: only when the prospect's scrape facts are known ----
  if (sf && typeof sf === 'object') {
    const knownEmails = new Set((sf.emails || []).map(emailKey).filter(Boolean));
    const knownPhones = new Set((sf.phones || []).map(phoneKey).filter((p) => p.length >= 7));
    const badMail = [...new Set((d.mailto || []).map(emailKey))].filter((e) => e && !knownEmails.has(e));
    const badTel = [...new Set((d.tel || []).map(phoneKey))].filter((p) => p && !knownPhones.has(p));
    const contactProblems = [];
    if (badMail.length) contactProblems.push(`mailto not in the scrape: ${badMail.slice(0, 3).join(', ')}` + (knownEmails.size ? ` (scraped: ${[...knownEmails].slice(0, 2).join(', ')})` : ' (the scrape found no email at all)'));
    if (badTel.length) contactProblems.push(`tel not in the scrape: ${badTel.slice(0, 3).join(', ')}` + (knownPhones.size ? ` (scraped: ${[...knownPhones].slice(0, 2).join(', ')})` : ' (the scrape found no phone at all)'));
    add('invented-contact', 'content', 4, ok(contactProblems.length === 0), contactProblems.length ? contactProblems.join('; ') : `Every mailto and tel on the page matches the scrape (${knownEmails.size} email, ${knownPhones.size} phone known)`);

    // Both sides normalised the same way: thousands separators dropped, a decimal
    // point folded into the digits, so 19.85 on the page matches 19.85 in the scrape
    // and 10,000,000 matches 10000000 or 10,000,000.
    const numKey = (t) => String(t).replace(/[,\u00a0]/g, '').replace(/\.(?=\d)/g, '').replace(/\D+/g, '');
    const srcText = String(sf.text || '').replace(/[,\u00a0]/g, '');
    const srcNums = new Set((srcText.match(/\d+(?:\.\d+)?/g) || []).map(numKey));
    // 10M, 2.5K, 1B on their site license 10,000,000, 2,500 and 1,000,000,000 on ours
    for (const m of srcText.matchAll(/(\d+(?:\.\d+)?)\s*([KMB])\b/gi)) srcNums.add(numKey(String(Math.round(parseFloat(m[1]) * { K: 1e3, M: 1e6, B: 1e9 }[m[2].toUpperCase()]))));
    const invented = [];
    const seenN = new Set();
    for (const c of (d.statClaims || [])) {
      const n = numKey(c.n);
      if (!n || seenN.has(n)) continue;
      seenN.add(n);
      if (!srcNums.has(n)) invented.push(`"${c.ctx.trim()}"`);
    }
    add('invented-stat', 'content', 4, ok(invented.length === 0), invented.length ? `${invented.length} number(s) not in the scrape: ` + invented.slice(0, 4).join(' | ') : `${(d.statClaims || []).length} statistic(s) on the page, all present in the scrape`);

    const known = new Set((sf.images || []).map(imageKey).filter(Boolean));
    const logos = new Set((sf.logo || []).map(imageKey).filter(Boolean));
    const used = (d.proxiedImages || []).map(proxyTarget).filter(Boolean);
    const notKnown = [...new Set(used.filter((u) => !known.has(imageKey(u)) && !logos.has(imageKey(u))).map((u) => u.slice(0, 90)))];
    const counts = {};
    for (const u of used) { const key = imageKey(u); if (logos.has(key)) continue; counts[key] = (counts[key] || 0) + 1; }
    const dupes = Object.entries(counts).filter(([, n]) => n > 1).map(([key, n]) => `${key.slice(0, 70)} x${n}`);
    const imgProblems = [];
    if (notKnown.length) imgProblems.push(`${notKnown.length} proxied image(s) not in the scrape: ` + notKnown.slice(0, 3).join(', '));
    if (dupes.length) imgProblems.push(`used twice: ` + dupes.slice(0, 3).join(', '));
    add('image-source', 'content', 3, ok(imgProblems.length === 0), imgProblems.length ? imgProblems.join('; ') : `${used.length} proxied image(s), all from the scrape, none reused`);

    add('kit-present', 'content', 3, ok(k.cssInHead && k.jsInHead), (k.cssInHead && k.jsInHead) ? 'Kit link and script are in <head>' : `Kit ${k.cssInHead ? 'css' : 'css missing'}, ${k.jsInHead ? 'js' : 'js missing'} in <head>`);
  }

  // ---- design ----
  add('font-families', 'design', 2, (d.fontFamilies || []).length <= 3 ? 'pass' : 'warn', `${(d.fontFamilies || []).length} families in use: ${(d.fontFamilies || []).slice(0, 5).join(', ')}`);
  add('h1-size', 'design', 2, d.h1Px >= 40 ? 'pass' : 'warn', `h1 renders at ${Math.round(d.h1Px)}px on desktop (aim 40px or more)`);
  add('body-size', 'design', 2, d.bodyPx >= 16 ? 'pass' : 'fail', `body text ${d.bodyPx}px (16px minimum)`);
  add('cta-above-fold', 'design', 3, ok(d.ctaAboveFold >= 1), d.ctaAboveFold ? `${d.ctaAboveFold} CTA(s) above the fold: ${(d.ctaTexts || []).join(' / ')}` : 'No call to action in the first screen');
  add('hero-imagery', 'design', 2, warn(d.heroImageAboveFold), d.heroImageAboveFold ? 'Large image or video in the first screen' : 'No imagery in the first screen');
  add('sticky-nav', 'design', 1, warn(d.stickyNav), d.stickyNav ? 'Header stays reachable while scrolling' : 'Header scrolls away');
  add('contrast', 'design', 2, d.lowContrastCount === 0 ? 'pass' : (d.lowContrastCount <= 3 ? 'warn' : 'fail'), d.lowContrastCount ? `${d.lowContrastCount} low contrast text samples, e.g. ` + (d.lowContrast || []).slice(0, 3).map((x) => `"${x.text}" ${x.ratio}:1`).join('; ') : 'Text contrast passes on every sample');
  add('hover-feedback', 'design', 1, warn((d.transitions || 0) >= 3), `${d.transitions || 0} interactive elements with transitions`);

  // ---- motion ----
  const anyMotion = k.reveals + k.parallax + k.bgShift + (k.ambient ? 1 : 0) + (d.otherMotion || 0);
  add('motion-kit', 'motion', 5, ok(k.css && k.js), k.css && k.js ? `WebWiz Motion Kit ${k.version || ''} loaded${k.active ? ' and active' : ''}` : (d.otherMotion ? `Third party animation library in use (${d.otherMotion} elements), not the WebWiz kit` : 'No motion kit'));
  add('scroll-reveals', 'motion', 4, k.reveals >= 6 ? 'pass' : (anyMotion ? 'warn' : 'fail'), `${k.reveals} elements enter on scroll (aim 6 or more)`);
  add('stagger-groups', 'motion', 2, warn(k.staggers >= 1), `${k.staggers} staggered groups (cards, lists, logos)`);
  add('depth', 'motion', 4, (k.parallax || k.ambient || k.bgShift) ? 'pass' : 'fail', `parallax ${k.parallax}, ambient ${k.ambient ? 'on' : 'off'}, background shift ${k.bgShift}`);
  add('hover-motion', 'motion', 1, warn(k.hover >= 1), `${k.hover} lift or zoom hover elements`);
  add('reduced-motion', 'motion', 2, ok(d.reducedMotionRule), d.reducedMotionRule ? 'prefers-reduced-motion honoured' : 'No prefers-reduced-motion rule');
  add('nothing-stuck-hidden', 'motion', 3, ok(!d.hiddenAfterLoad), d.hiddenAfterLoad ? `${d.hiddenAfterLoad} elements still invisible after load and scroll` : 'Everything visible after load');

  // ---- mobile ----
  add('no-horizontal-scroll', 'mobile', 5, ok(!m.overflow), m.overflow ? `Page is ${m.scrollWidth}px wide on a ${m.vw}px phone: ` + (m.overflowers || []).join(', ') : 'No horizontal scroll at 390px');
  add('tap-targets', 'mobile', 3, m.smallTargets === 0 ? 'pass' : (m.smallTargets <= 3 ? 'warn' : 'fail'), `${m.smallTargets} of ${m.targets} tap targets under 40px`);
  add('mobile-text-size', 'mobile', 2, m.tinyText === 0 ? 'pass' : 'warn', `smallest text ${m.minFont}px, ${m.tinyText} elements under 13px`);
  add('mobile-h1', 'mobile', 2, warn(m.h1Px >= 30), `h1 ${Math.round(m.h1Px)}px on a phone (aim 30px or more)`);
  add('mobile-nav', 'mobile', 2, ok(m.navReachable), m.navReachable ? 'Navigation or menu reachable' : 'No nav links and no menu button on a phone');
  add('mobile-cta', 'mobile', 2, ok(m.ctaAboveFold >= 1), `${m.ctaAboveFold} CTA(s) in the first phone screen`);
  add('mobile-images-fit', 'mobile', 1, ok(!m.wideImages), m.wideImages ? `${m.wideImages} images wider than the phone` : 'Images fit the phone width');

  // ---- performance ----
  const load = d.timing ? d.timing.load : 0;
  add('load-time', 'performance', 2, load && load < 3000 ? 'pass' : (load < 6000 ? 'warn' : 'fail'), d.timing ? `load ${load}ms, DOM ready ${d.timing.dcl}ms, first byte ${d.timing.ttfb}ms` : 'No timing');
  const mb = (r.bytes || 0) / 1048576;
  add('page-weight', 'performance', 3, mb < 3 ? 'pass' : (mb < 6 ? 'warn' : 'fail'), `${mb.toFixed(2)} MB over ${r.count || 0} requests`);
  const li = r.largestImage ? r.largestImage.bytes / 1024 : 0;
  add('largest-image', 'performance', 3, li < 500 ? 'pass' : (li < 1200 ? 'warn' : 'fail'), r.largestImage ? `${Math.round(li)} KB: ${r.largestImage.url}` : 'No images fetched');
  add('image-dimensions', 'performance', 1, warn(!d.imgNoSize), d.imgNoSize ? `${d.imgNoSize} images without width/height or aspect-ratio (layout shifts)` : 'Images reserve their space');
  add('no-failed-requests', 'performance', 2, ok(!r.failed || r.failed.length === 0), r.failed && r.failed.length ? `${r.failed.length} failed: ` + r.failed.slice(0, 3).map((x) => x.url).join(', ') : 'Every request succeeded');
  add('third-party-scripts', 'performance', 1, warn((d.thirdPartyScripts || 0) <= 3), `${d.thirdPartyScripts || 0} third party scripts`);

  // ---- accessibility ----
  add('alt-text', 'access', 3, ok(!d.imgNoAlt), d.imgNoAlt ? `${d.imgNoAlt} images without alt` : 'Every image has alt');
  add('link-names', 'access', 2, ok(!d.linksNoText), d.linksNoText ? `${d.linksNoText} links with no accessible name` : 'Every link has a name');
  add('form-labels', 'access', 1, warn(!d.inputsNoLabel), d.inputsNoLabel ? `${d.inputsNoLabel} inputs without a label` : 'Inputs labelled');
  add('button-names', 'access', 1, warn(!d.buttonsNoText), d.buttonsNoText ? `${d.buttonsNoText} buttons with no name` : 'Buttons named');
  add('console-clean', 'access', 1, warn(!f.console || f.console.length === 0), f.console && f.console.length ? `${f.console.length} console errors: ` + f.console.slice(0, 2).join(' | ') : 'No console errors');
  return C;
}

const GROUP_LABELS = { structure: 'Structure and SEO', content: 'Content truth and copy', design: 'Design', motion: 'Motion', mobile: 'Mobile', performance: 'Performance', access: 'Accessibility' };

function score(checks) {
  const groups = {};
  let earned = 0, max = 0;
  for (const c of checks) {
    const g = groups[c.group] || (groups[c.group] = { label: GROUP_LABELS[c.group] || c.group, earned: 0, max: 0, pass: 0, warn: 0, fail: 0 });
    const e = c.status === 'pass' ? c.weight : c.status === 'warn' ? c.weight / 2 : 0;
    g.earned += e; g.max += c.weight; g[c.status]++;
    earned += e; max += c.weight;
  }
  for (const g of Object.values(groups)) g.score = Math.round((g.earned / g.max) * 100);
  const fails = checks.filter((c) => c.status === 'fail');
  const total = Math.round((earned / max) * 100);
  const preview_ready = fails.length === 0 && total >= 85;
  return {
    score: total, checks_run: checks.length, groups, fails: fails.map((c) => c.id), warns: checks.filter((c) => c.status === 'warn').map((c) => c.id),
    verdict: preview_ready ? 'preview-ready' : 'not-ready',
    verdict_reason: preview_ready ? 'No failing checks and score at or above 85.' : (fails.length ? `${fails.length} failing check(s): ${fails.map((c) => c.id).join(', ')}` : `Score ${total} is below 85.`),
  };
}

/** Lightweight summary safe to store in a database column. */
function summarize(a) {
  return {
    url: a.url, score: a.score, verdict: a.verdict, groups: Object.fromEntries(Object.entries(a.groups).map(([k, g]) => [k, g.score])),
    fails: a.fails, warns: a.warns, at: new Date().toISOString(), duration_ms: a.duration_ms,
  };
}

/** Side by side hero screenshots with labels. */
async function sideBySide(leftBuf, rightBuf, leftLabel, rightLabel) {
  const W = 700, GAP = 24, LABEL = 44;
  const prep = async (buf) => { const b = await sharp(buf).resize({ width: W }).png().toBuffer(); const m = await sharp(b).metadata(); return { b, h: m.height }; };
  const L = await prep(leftBuf), R = await prep(rightBuf);
  const H = Math.max(L.h, R.h) + LABEL;
  const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;');
  const svg = Buffer.from(`<svg width="${W * 2 + GAP}" height="${H}" xmlns="http://www.w3.org/2000/svg">
    <rect width="100%" height="100%" fill="#FFF8E7"/>
    <text x="12" y="29" font-family="Helvetica, Arial, sans-serif" font-size="20" font-weight="700" fill="#12184A">${esc(leftLabel)}</text>
    <text x="${W + GAP + 12}" y="29" font-family="Helvetica, Arial, sans-serif" font-size="20" font-weight="700" fill="#12184A">${esc(rightLabel)}</text>
  </svg>`);
  return sharp(svg).composite([{ input: L.b, left: 0, top: LABEL }, { input: R.b, left: W + GAP, top: LABEL }]).jpeg({ quality: 76 }).toBuffer();
}

// The prospect's current site audit is cached on disk (screenshots included,
// base64) so three variants of one job do not render it three times.
function loadTheirCache(file, theirUrl) {
  if (!file || !fs.existsSync(file)) return null;
  try {
    const j = JSON.parse(fs.readFileSync(file, 'utf8'));
    if (j.url !== theirUrl || !j.at || Date.now() - Date.parse(j.at) > THEIR_CACHE_TTL_MS) return null;
    j.screenshots = Object.fromEntries(Object.entries(j.screenshots || {}).map(([k, v]) => [k, Buffer.from(v, 'base64')]));
    return j;
  } catch { return null; }
}
function saveTheirCache(file, a) {
  if (!file) return;
  try {
    const j = { ...a, at: new Date().toISOString(), screenshots: Object.fromEntries(Object.entries(a.screenshots || {}).map(([k, v]) => [k, Buffer.from(v).toString('base64')])) };
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, JSON.stringify(j));
  } catch {}
}

/**
 * Audit both sites and decide whether ours beats theirs.
 * opts: { facts, theirCache, ourLabel, theirLabel, full }
 */
async function compareSites(ourUrl, theirUrl, opts = {}) {
  // opts.ours: an auditUrl() result for our page (with screenshots) from a run
  // that just happened, so the worker does not render the same variant twice.
  const ours = (opts.ours && opts.ours.checks) ? opts.ours : await auditUrl(ourUrl, { screenshots: true, facts: opts.facts || null });
  if (typeof ourUrl !== 'string') ourUrl = ours.url;
  let theirs = loadTheirCache(opts.theirCache, theirUrl);
  let theirsCached = !!theirs;
  if (!theirs) {
    theirs = await auditUrl(theirUrl, { screenshots: true });
    saveTheirCache(opts.theirCache, theirs);
  }
  const groups = {};
  const losing = [], winning = [], tied = [];
  for (const g of Object.keys(ours.groups)) {
    const a = ours.groups[g].score, b = (theirs.groups[g] || { score: 0 }).score;
    groups[g] = { label: ours.groups[g].label, ours: a, theirs: b, delta: a - b };
    if (a > b) winning.push(g); else if (a < b) losing.push(g); else tied.push(g);
  }
  const beats = ours.score > theirs.score && losing.length === 0;
  const theirLabel = opts.theirLabel || `Your site today, ${hostOf(theirUrl)} (${theirs.score})`;
  const ourLabel = (opts.ourLabel || 'Your new site') + ` (${ours.score})`;
  const composite = (ours.screenshots.desktop && theirs.screenshots.desktop)
    ? await sideBySide(theirs.screenshots.desktop, ours.screenshots.desktop, theirLabel, ourLabel)
    : null;
  const mobileComposite = (ours.screenshots.mobile && theirs.screenshots.mobile)
    ? await sideBySide(theirs.screenshots.mobile, ours.screenshots.mobile, `Yours today on a phone (${theirs.score})`, `Your new site on a phone (${ours.score})`)
    : null;
  const pick = (a, id) => (a.checks.find((c) => c.id === id) || {});
  // facts for the reveal page: one plain line per category we won, numbers from the audit
  const facts = [];
  const tf = theirs.facts || {}, of = ours.facts || {};
  const td = tf.desktop || {}, od = of.desktop || {};
  const tm = tf.mobile || {}, om = of.mobile || {};
  if (od.timing && td.timing && od.timing.load && td.timing.load && od.timing.load < td.timing.load) facts.push(`Loads in ${(od.timing.load / 1000).toFixed(1)} seconds, yours loads in ${(td.timing.load / 1000).toFixed(1)}`);
  if (tm.overflow && !om.overflow) facts.push('Works on a phone, yours scrolls sideways');
  if (!tm.overflow && !om.overflow && tm.smallTargets > om.smallTargets) facts.push(`${om.smallTargets} buttons too small to tap on a phone, yours has ${tm.smallTargets}`);
  const tmb = (tf.resources || {}).bytes || 0, omb = (of.resources || {}).bytes || 0;
  if (tmb && omb && omb < tmb) facts.push(`${(omb / 1048576).toFixed(1)} MB to download, yours is ${(tmb / 1048576).toFixed(1)} MB`);
  if (pick(theirs, 'motion-kit').status === 'fail' && pick(ours, 'motion-kit').status === 'pass') facts.push('Moves as you scroll, yours is static');
  if ((td.imgNoAlt || 0) > 0 && !(od.imgNoAlt || 0)) facts.push(`Every image described for screen readers, yours has ${td.imgNoAlt} without`);
  if ((td.imgBroken || []).length && !(od.imgBroken || []).length) facts.push(`Every image loads, yours has ${td.imgBroken.length} broken`);
  if (pick(theirs, 'phone-link').status !== 'pass' && pick(ours, 'phone-link').status === 'pass') facts.push('Phone number is one tap to call, yours is not a link');
  if (pick(theirs, 'meta-description').status !== 'pass' && pick(ours, 'meta-description').status === 'pass') facts.push('Has the description search engines show, yours is missing it');
  if (pick(theirs, 'viewport').status !== 'pass' && pick(ours, 'viewport').status === 'pass') facts.push('Built for phones, yours is a desktop page shrunk down');
  if ((td.thirdPartyScripts || 0) > (od.thirdPartyScripts || 0) + 2) facts.push(`${od.thirdPartyScripts || 0} third party scripts, yours loads ${td.thirdPartyScripts}`);
  return {
    ours: { url: ourUrl, score: ours.score, verdict: ours.verdict, fails: ours.fails, warns: ours.warns },
    theirs: { url: theirUrl, score: theirs.score, fails: theirs.fails, warns: theirs.warns, cached: theirsCached, wordpress: !!td.wordpress, generator: td.generator || null },
    groups, winning, losing, tied, facts,
    verdict: beats ? 'beats-current-site' : 'does-not-beat-current-site',
    verdict_reason: beats ? `Ours scores ${ours.score} to their ${theirs.score} and wins or ties every category.` : (losing.length ? `Theirs still wins on: ${losing.map((g) => groups[g].label).join(', ')}.` : `Ours (${ours.score}) does not clear theirs (${theirs.score}).`),
    what_theirs_does_better: losing.map((g) => ({ group: g, label: groups[g].label, their_passes: theirs.checks.filter((c) => c.group === g && c.status === 'pass' && ours.checks.find((o) => o.id === c.id && o.status !== 'pass')).map((c) => ({ id: c.id, ours: (pick(ours, c.id).detail || '') })) })),
    our_fails: ours.checks.filter((c) => c.status === 'fail').map((c) => ({ id: c.id, detail: c.detail })),
    their_fails: theirs.checks.filter((c) => c.status === 'fail').map((c) => ({ id: c.id, detail: c.detail })),
    screenshots: { desktop: composite, mobile: mobileComposite },
    full: opts.full ? { ours, theirs } : undefined,
    ours_audit: ours,
  };
}

// ---- CLI ----
function parseArgs(argv) {
  const o = { _: [] };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a.startsWith('--')) {
      const k = a.slice(2);
      if (['full', 'compare', 'help'].includes(k)) o[k] = true; else o[k] = argv[++i];
    } else o._.push(a);
  }
  return o;
}
function stripShots(a) {
  const { screenshots, ours_audit, full, ...rest } = a;
  if (full) rest.full = { ours: stripShots(full.ours), theirs: stripShots(full.theirs) };
  return rest;
}
function writeShots(dir, prefix, shots) {
  if (!dir) return {};
  fs.mkdirSync(dir, { recursive: true });
  const out = {};
  for (const [k, buf] of Object.entries(shots || {})) {
    if (!buf) continue;
    const f = path.join(dir, `${prefix}${k}.jpg`);
    fs.writeFileSync(f, buf);
    out[k] = f;
  }
  return out;
}

if (require.main === module) {
  (async () => {
    const o = parseArgs(process.argv.slice(2));
    if (o.help || (!o.compare && o._.length < 1) || (o.compare && o._.length < 2)) {
      console.error('usage: node audit.js <url> [--json out] [--shots dir] [--facts facts.json] [--full]\n       node audit.js --compare <ourUrl> <theirUrl> [--json out] [--shots dir] [--facts facts.json] [--their-cache file] [--ours-json audit.json --ours-shots dir] [--label "Your new site"]');
      process.exit(2);
    }
    let facts = null;
    if (o.facts) { try { facts = JSON.parse(fs.readFileSync(o.facts, 'utf8')); } catch (e) { console.error('facts file unreadable: ' + e.message); process.exit(2); } }
    let result, files = {};
    if (o.compare) {
      let ours = null;
      if (o['ours-json']) {
        // reuse the audit the caller just ran: JSON without screenshots + the
        // screenshot files it wrote with --shots
        try {
          ours = JSON.parse(fs.readFileSync(o['ours-json'], 'utf8'));
          ours.screenshots = {};
          const sd = o['ours-shots'] || path.dirname(o['ours-json']);
          for (const k of ['desktop', 'mobile']) { const f = path.join(sd, 'audit-' + k + '.jpg'); if (fs.existsSync(f)) ours.screenshots[k] = fs.readFileSync(f); }
          if (!ours.screenshots.desktop) ours = null;
        } catch { ours = null; }
      }
      const r = await compareSites(o._[0], o._[1], { ours, facts, theirCache: o['their-cache'], ourLabel: o.label, full: !!o.full });
      files = writeShots(o.shots, 'compare-', r.screenshots);
      result = { ...stripShots(r), ours_audit: stripShots(r.ours_audit), files };
    } else {
      const a = await auditUrl(o._[0], { screenshots: !!o.shots, fullPage: !!o.full, facts });
      files = writeShots(o.shots, 'audit-', a.screenshots);
      result = { ...stripShots(a), files };
    }
    const json = JSON.stringify(result, null, o.json ? 0 : 2);
    if (o.json) { fs.writeFileSync(o.json, json); console.log(`${result.verdict} ${result.score !== undefined ? result.score : (result.ours.score + ' vs ' + result.theirs.score)}`); }
    else console.log(json);
    process.exit(0);
  })().catch((e) => { console.error('ERR ' + (e && e.message ? e.message : e)); process.exit(1); });
}

module.exports = { auditUrl, compareSites, summarize, runChecks, score, GROUP_LABELS };
