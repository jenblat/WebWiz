/*! WebWiz Motion Kit v1.1.0 | no dependencies | pair with webwiz-motion.css */
(function () {
  'use strict';
  var VERSION = '1.1.0';
  var doc = document, root = doc.documentElement, win = window;
  var reduce = win.matchMedia && win.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var finePointer = win.matchMedia && win.matchMedia('(hover: hover) and (pointer: fine)').matches;
  var hasIO = 'IntersectionObserver' in win;

  // Only switch motion on when it can be done safely. Without this class the
  // CSS never hides anything, so a page without JS is a fully visible page.
  if (!reduce && hasIO) root.classList.add('ww-motion');

  var revealSel = '[data-reveal],[data-reveal-stagger],[data-words],[data-highlight]';
  var revealed = false;

  function revealAll() {
    if (revealed) return;
    revealed = true;
    var els = doc.querySelectorAll(revealSel);
    for (var i = 0; i < els.length; i++) enter(els[i]);
  }

  function enter(el) {
    if (el.classList.contains('ww-in')) return;
    el.classList.add('ww-in');
    var counters = el.matches('[data-count]') ? [el] : el.querySelectorAll('[data-count]');
    for (var i = 0; i < counters.length; i++) runCounter(counters[i]);
  }

  // ---------- auto enhancement for WebWiz pages ----------
  // Generated pages carry body[data-ambient] (finalize_html adds it). On those the
  // kit adds the effects a designer would have asked for, so every existing preview
  // gets them from a kit update without being regenerated. Everything added here is
  // an ordinary kit attribute, so every failsafe below covers it.
  function autoEnhance() {
    var body = doc.body;
    if (!body.hasAttribute('data-ambient') || body.getAttribute('data-ww-auto') === 'off') return;
    var inRevealed = function (el) { return el.closest('[data-reveal],[data-reveal-stagger] > *,[data-parallax],[data-marquee],[data-scroll-x]'); };
    // 1. content photographs unveil with a curtain
    var imgs = doc.querySelectorAll('main img, section img, article img');
    for (var i = 0; i < imgs.length; i++) {
      var im = imgs[i];
      if (im.closest('header,nav,footer,[data-parallax],[data-marquee],[data-no-curtain]') || im.hasAttribute('data-reveal')) continue;
      if (im.closest('[data-reveal]')) continue;  // its block already animates
      var r = im.getBoundingClientRect();
      if (r.width < 220) continue;                                         // logos, icons, avatars
      if (im.complete && im.naturalHeight && r.height < 140) continue;      // short strips and badges
      // the settle-from-zoom only where a clipping frame hides the overscale;
      // an unframed image would poke past its column (and past a phone's edge)
      var po = win.getComputedStyle(im.parentElement || im).overflow;
      if (!/hidden|clip/.test(po)) im.style.setProperty('--ww-curtain-scale', '1');
      im.setAttribute('data-reveal', 'curtain');
    }
    // 2. section headlines rise when nothing else animates them
    var hs = doc.querySelectorAll('main h2, section h2, article h2');
    for (var h = 0; h < hs.length; h++) {
      if (inRevealed(hs[h]) || hs[h].hasAttribute('data-words')) continue;
      hs[h].setAttribute('data-reveal', 'rise');
    }
    // 3. cards tilt and catch the light
    var cards = doc.querySelectorAll('[data-lift]');
    for (var c = 0; c < cards.length; c++) if (!cards[c].hasAttribute('data-tilt')) cards[c].setAttribute('data-tilt', '');
    // 4. call to action buttons pull toward the cursor
    var btns = doc.querySelectorAll('a[class*="btn"], button[class*="btn"], a[class*="button"], a[class*="cta"], .btn, [data-cta]');
    for (var b = 0; b < btns.length; b++) {
      var br = btns[b].getBoundingClientRect();
      if (br.width > 0 && br.width < 420 && !btns[b].hasAttribute('data-magnetic')) btns[b].setAttribute('data-magnetic', '');
    }
  }

  // ---------- prepare elements ----------
  function prep() {
    autoEnhance();
    // per child delay index for staggered groups
    var groups = doc.querySelectorAll('[data-reveal-stagger]');
    for (var g = 0; g < groups.length; g++) {
      var gap = parseFloat(groups[g].getAttribute('data-reveal-stagger'));
      if (gap > 0) groups[g].style.setProperty('--ww-stagger', (gap / 1000) + 's');
      var kids = groups[g].children;
      for (var k = 0; k < kids.length; k++) kids[k].style.setProperty('--ww-i', k);
    }
    var delayed = doc.querySelectorAll('[data-reveal-delay]');
    for (var d = 0; d < delayed.length; d++) {
      delayed[d].style.setProperty('--ww-delay', (parseFloat(delayed[d].getAttribute('data-reveal-delay')) / 1000) + 's');
    }
    // word by word headlines, text only elements so markup is never broken.
    // Each word is an outer mask (.ww-w) holding the moving word (.ww-wi).
    var words = doc.querySelectorAll('[data-words]');
    for (var w = 0; w < words.length; w++) {
      var el = words[w];
      if (el.children.length || el.getAttribute('data-ww-split')) continue;
      var parts = el.textContent.split(/(\s+)/), html = '', idx = 0;
      for (var p = 0; p < parts.length; p++) {
        if (!parts[p]) continue;
        if (/^\s+$/.test(parts[p])) { html += parts[p]; continue; }
        html += '<span class="ww-w"><span class="ww-wi" style="--ww-i:' + (idx++) + '">' + parts[p].replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</span></span>';
      }
      el.innerHTML = html;
      el.setAttribute('data-ww-split', '1');
    }
    // marquee: duplicate the track so the loop is seamless
    var mq = doc.querySelectorAll('[data-marquee]');
    for (var m = 0; m < mq.length; m++) {
      if (mq[m].querySelector('.ww-track')) continue;
      var track = doc.createElement('div');
      track.className = 'ww-track';
      while (mq[m].firstChild) track.appendChild(mq[m].firstChild);
      var clone = track.cloneNode(true);
      clone.setAttribute('aria-hidden', 'true');
      track.appendChild(clone);
      mq[m].appendChild(track);
    }
    // ambient field: html carries the page colour, body goes clear so the
    // field shows through on sections that have no background of their own
    if (doc.body.hasAttribute('data-ambient') && !doc.querySelector('.ww-ambient')) {
      var bg = win.getComputedStyle(doc.body).backgroundColor;
      if (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') {
        root.style.backgroundColor = bg;
        doc.body.style.backgroundColor = 'transparent';
      }
      var amb = doc.createElement('div');
      amb.className = 'ww-ambient';
      amb.setAttribute('aria-hidden', 'true');
      doc.body.insertBefore(amb, doc.body.firstChild);
    }
  }

  // ---------- pointer effects: tilt, spotlight, magnetic, ambient follow ----------
  // Fine pointers only (no phones), never under reduced motion, rAF throttled.
  function pointer() {
    if (reduce || !finePointer || root.getAttribute('data-ww-pointer')) return;
    root.setAttribute('data-ww-pointer', '1');
    var tilts = doc.querySelectorAll('[data-tilt]');
    for (var t = 0; t < tilts.length; t++) bindTilt(tilts[t]);
    var mags = doc.querySelectorAll('[data-magnetic]');
    for (var m = 0; m < mags.length; m++) bindMagnet(mags[m]);
    var pending = null;
    win.addEventListener('pointermove', function (e) {
      if (pending) return;
      pending = win.requestAnimationFrame(function () {
        pending = null;
        root.style.setProperty('--ww-mx', (e.clientX / win.innerWidth).toFixed(2));
        root.style.setProperty('--ww-my', (e.clientY / win.innerHeight).toFixed(2));
      });
    }, { passive: true });
  }
  function bindTilt(el) {
    el.classList.add('ww-tilt', 'ww-spot');
    if (win.getComputedStyle(el).position === 'static') el.style.position = 'relative';
    var raf = null, ev = null;
    el.addEventListener('pointermove', function (e) {
      ev = e;
      if (raf) return;
      raf = win.requestAnimationFrame(function () {
        raf = null;
        var r = el.getBoundingClientRect();
        var x = (ev.clientX - r.left) / r.width, y = (ev.clientY - r.top) / r.height;
        // rotate around an axis perpendicular to the cursor offset, up to ~7 degrees
        var dx = x - .5, dy = y - .5, mag = Math.min(1, Math.sqrt(dx * dx + dy * dy) * 2);
        if (el.parentElement && !el.parentElement.style.perspective) el.parentElement.style.perspective = '1100px';
        el.style.rotate = (-dy).toFixed(3) + ' ' + dx.toFixed(3) + ' 0 ' + (mag * 7).toFixed(2) + 'deg';
        el.style.setProperty('--ww-px', (x * 100).toFixed(1) + '%');
        el.style.setProperty('--ww-py2', (y * 100).toFixed(1) + '%');
      });
    });
    el.addEventListener('pointerleave', function () { el.style.rotate = ''; });
  }
  function bindMagnet(el) {
    el.classList.add('ww-mag');
    var raf = null, ev = null;
    el.addEventListener('pointermove', function (e) {
      ev = e;
      if (raf) return;
      raf = win.requestAnimationFrame(function () {
        raf = null;
        var r = el.getBoundingClientRect();
        var gx = (ev.clientX - (r.left + r.width / 2)) * .28, gy = (ev.clientY - (r.top + r.height / 2)) * .38;
        el.classList.add('ww-pulling');
        el.style.translate = gx.toFixed(1) + 'px ' + gy.toFixed(1) + 'px';
      });
    });
    el.addEventListener('pointerleave', function () { el.classList.remove('ww-pulling'); el.style.translate = ''; });
  }

  // ---------- counters ----------
  function runCounter(el) {
    if (el.getAttribute('data-ww-counted')) return;
    el.setAttribute('data-ww-counted', '1');
    var target = parseFloat(String(el.getAttribute('data-count')).replace(/[^0-9.\-]/g, ''));
    if (isNaN(target)) return;
    var dec = parseInt(el.getAttribute('data-count-decimals') || '0', 10);
    var dur = parseInt(el.getAttribute('data-count-duration') || '1800', 10);
    var pre = el.getAttribute('data-count-prefix') || '', suf = el.getAttribute('data-count-suffix') || '';
    var sep = el.getAttribute('data-count-separator') !== 'none';
    var fmt = function (n) {
      var s = n.toFixed(dec);
      if (sep) { var bits = s.split('.'); bits[0] = bits[0].replace(/\B(?=(\d{3})+(?!\d))/g, ','); s = bits.join('.'); }
      return pre + s + suf;
    };
    if (reduce || !root.classList.contains('ww-motion')) { el.textContent = fmt(target); return; }
    var t0 = null;
    function step(t) {
      if (!t0) t0 = t;
      var k = Math.min(1, (t - t0) / dur);
      var e = 1 - Math.pow(1 - k, 4);
      el.textContent = fmt(target * e);
      if (k < 1) win.requestAnimationFrame(step); else el.textContent = fmt(target);
    }
    win.requestAnimationFrame(step);
  }

  // ---------- observers ----------
  function observe() {
    if (!root.classList.contains('ww-motion')) { revealAll(); return; }
    var io = new IntersectionObserver(function (entries) {
      for (var i = 0; i < entries.length; i++) {
        if (entries[i].isIntersecting) { enter(entries[i].target); io.unobserve(entries[i].target); }
      }
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    var els = doc.querySelectorAll(revealSel);
    pending = [];
    for (var i = 0; i < els.length; i++) {
      // already on screen at load: reveal now, with the natural stagger
      var r = els[i].getBoundingClientRect();
      if (r.top < win.innerHeight && r.bottom > 0) enter(els[i]); else { io.observe(els[i]); pending.push(els[i]); }
    }
    // counters outside reveal groups still count when seen
    var loose = doc.querySelectorAll('[data-count]');
    var cio = new IntersectionObserver(function (entries) {
      for (var i = 0; i < entries.length; i++) if (entries[i].isIntersecting) { runCounter(entries[i].target); cio.unobserve(entries[i].target); }
    }, { threshold: 0.3 });
    for (var c = 0; c < loose.length; c++) if (!loose[c].closest(revealSel)) cio.observe(loose[c]);
  }

  // ---------- scroll driven: progress, nav, parallax, section tint, scroll-x ----------
  var parallax = [], shifts = [], bands = [], nav = null, ticking = false, lastProg = null, pending = [];
  function collect() {
    parallax = [].slice.call(doc.querySelectorAll('[data-parallax]'));
    shifts = [].slice.call(doc.querySelectorAll('[data-bg-shift]'));
    bands = [].slice.call(doc.querySelectorAll('[data-scroll-x]'));
    nav = doc.querySelector('[data-nav]');
  }
  function frame() {
    ticking = false;
    var y = win.pageYOffset || root.scrollTop, vh = win.innerHeight;
    var max = Math.max(1, root.scrollHeight - vh);
    var prog = Math.min(1, Math.max(0, y / max)).toFixed(3);
    if (prog !== lastProg) { lastProg = prog; root.style.setProperty('--ww-progress', prog); }
    if (nav) nav.classList.toggle('is-scrolled', y > 24);
    var motion = root.classList.contains('ww-motion');
    // Scroll based fallback: anything the observer missed between frames
    // (fast flicks, synthetic scrolls, screenshot renderers) is revealed as
    // soon as any part of it is at or above the viewport.
    if (pending.length) {
      var keep = [];
      for (var q = 0; q < pending.length; q++) {
        var pr = pending[q].getBoundingClientRect();
        if (pr.top < vh * 0.92) enter(pending[q]); else keep.push(pending[q]);
      }
      pending = keep;
    }
    for (var i = 0; i < parallax.length; i++) {
      var el = parallax[i], r = el.getBoundingClientRect();
      if (r.bottom < -vh || r.top > vh * 2) continue;
      var f = parseFloat(el.getAttribute('data-parallax')) || 0.12;
      var centre = r.top + r.height / 2 - vh / 2;
      el.style.setProperty('--ww-py', (motion ? (-centre * f).toFixed(1) : 0) + 'px');
    }
    for (var s = 0; s < shifts.length; s++) {
      var se = shifts[s], sr = se.getBoundingClientRect();
      if (sr.bottom < -vh || sr.top > vh * 2) continue;
      // 0 when the section's top enters at the bottom, 1 when its bottom leaves at the top
      var p = Math.min(1, Math.max(0, (vh - sr.top) / (vh + sr.height))).toFixed(2);
      if (se.getAttribute('data-ww-p') !== p) { se.setAttribute('data-ww-p', p); se.style.setProperty('--ww-p', p); }
    }
    for (var b = 0; b < bands.length; b++) {
      var be = bands[b], bor = be.getBoundingClientRect();
      if (bor.bottom < -vh || bor.top > vh * 2) continue;
      var bp = Math.min(1, Math.max(0, (vh - bor.top) / (vh + bor.height)));
      var amt = parseFloat(be.getAttribute('data-scroll-x')) || 0.25;
      // alternate direction band by band so stacked bands shear past each other
      var dir = (b % 2) ? 1 : -1;
      var sx = (motion ? (dir * (bp - .5) * amt * 100) : 0).toFixed(2) + '%';
      if (be.getAttribute('data-ww-sx') !== sx) { be.setAttribute('data-ww-sx', sx); be.style.setProperty('--ww-sx', sx); }
    }
  }
  function onScroll() { if (!ticking) { ticking = true; win.requestAnimationFrame(frame); } }

  // ---------- boot ----------
  function boot() {
    prep(); collect(); observe(); frame(); pointer();
    win.addEventListener('scroll', onScroll, { passive: true });
    win.addEventListener('resize', onScroll);
    // Failsafes. A flaky observer, a background tab, a screenshot renderer or
    // a visitor who never scrolls must never see a blank section.
    setTimeout(function () {
      var els = doc.querySelectorAll(revealSel), vh = win.innerHeight;
      for (var i = 0; i < els.length; i++) if (els[i].getBoundingClientRect().top < vh * 1.5) enter(els[i]);
    }, 1200);
    setTimeout(revealAll, 6000);
    win.addEventListener('beforeprint', revealAll);
    doc.addEventListener('visibilitychange', function () { if (doc.visibilityState === 'visible') onScroll(); });
  }
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', boot); else boot();

  win.WebWizMotion = {
    version: VERSION,
    revealAll: revealAll,
    refresh: function () { prep(); collect(); observe(); frame(); }
  };
})();
