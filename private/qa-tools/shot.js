const puppeteer = require('puppeteer-core');
const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
  const url = process.argv[2], out = process.argv[3];
  const browser = await puppeteer.launch({
    executablePath: '/bin/google-chrome-stable',
    headless: 'new',
    args: ['--no-sandbox','--disable-dev-shm-usage','--disable-gpu','--hide-scrollbars','--force-color-profile=srgb'],
    defaultViewport: { width: 1280, height: 900, deviceScaleFactor: 1 },
  });
  try {
    const page = await browser.newPage();
    await page.goto(url, { waitUntil: 'networkidle2', timeout: 45000 });
    // scroll top->bottom to trigger reveal-on-scroll animations / IntersectionObservers
    await page.evaluate(async () => {
      const sleep = ms => new Promise(r => setTimeout(r, ms));
      const h = () => document.body.scrollHeight;
      for (let y = 0; y <= h(); y += Math.round(window.innerHeight * 0.85)) { window.scrollTo(0, y); await sleep(110); }
      window.scrollTo(0, h()); await sleep(250);
      window.scrollTo(0, 0); await sleep(150);
    });
    // FORCE-REVEAL: IntersectionObserver entrance animations are unreliable and can leave
    // sections stuck at opacity:0. Reveal everything so the screenshot reflects the real,
    // fully-loaded page (the live page has its own failsafe that does the same for users).
    await page.evaluate(() => {
      const e = document.querySelectorAll('.fade-up,.fade-in,.reveal,[data-reveal],.animate,.scroll-reveal');
      e.forEach(el => { el.classList.add('visible','active','in-view','show'); el.style.opacity='1'; el.style.transform='none'; el.style.visibility='visible'; });
    });
    // ensure all images finished loading/decoding
    await page.evaluate(async () => {
      const imgs = Array.from(document.images);
      await Promise.all(imgs.map(img => (img.complete && img.naturalWidth > 0)
        ? Promise.resolve()
        : new Promise(res => { img.addEventListener('load', res); img.addEventListener('error', res); setTimeout(res, 8000); })));
      if (document.fonts && document.fonts.ready) { try { await document.fonts.ready; } catch(e){} }
    });
    await sleep(800);
    await page.screenshot({ path: out, fullPage: true, type: 'png' });
    console.log('OK');
  } catch (e) { console.error('ERR ' + e.message); process.exitCode = 2; }
  finally { await browser.close(); }
})();
