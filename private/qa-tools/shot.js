// shot.js — full-page screenshot via puppeteer. Crashpad-safe args (matches showcase.js).
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const url = process.argv[2], out = process.argv[3];
  const ud = fs.mkdtempSync('/tmp/wwshot-');
  // Critical: HOME must be writable and crash subsystem fully disabled —
  // otherwise Chrome's crashpad child fails with "--database is required".
  process.env.HOME = ud;
  const browser = await puppeteer.launch({
    executablePath: '/bin/google-chrome-stable',
    headless: 'new',
    args: [
      '--no-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
      '--disable-breakpad',
      '--no-crash-upload',
      '--disable-features=Crashpad,DialMediaRouteProvider',
      '--user-data-dir=' + ud,
      '--crash-dumps-dir=' + ud,
      '--hide-scrollbars',
      '--force-color-profile=srgb',
      '--disable-background-networking',
      '--disable-default-apps',
      '--no-first-run',
      '--no-default-browser-check',
      '--disable-extensions',
    ],
    defaultViewport: { width: 1280, height: 900, deviceScaleFactor: 1 },
  });
  try {
    const page = await browser.newPage();
    await page.goto(url, { waitUntil: 'networkidle2', timeout: 45000 });
    await page.evaluate(async () => {
      const sleep = ms => new Promise(r => setTimeout(r, ms));
      const h = () => document.body.scrollHeight;
      for (let y = 0; y <= h(); y += Math.round(window.innerHeight * 0.85)) { window.scrollTo(0, y); await sleep(110); }
      window.scrollTo(0, h()); await sleep(250);
      window.scrollTo(0, 0); await sleep(150);
    });
    await page.evaluate(() => {
      const e = document.querySelectorAll('.fade-up,.fade-in,.reveal,[data-reveal],.animate,.scroll-reveal');
      e.forEach(el => { el.classList.add('visible','active','in-view','show'); el.style.opacity='1'; el.style.transform='none'; el.style.visibility='visible'; });
    });
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
  } catch (e) {
    console.error('ERR ' + e.message);
    process.exitCode = 2;
  } finally {
    try { await browser.close(); } catch(e){}
    try { fs.rmSync(ud, { recursive: true, force: true }); } catch(e){}
  }
})();