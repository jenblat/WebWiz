const puppeteer = require('puppeteer-core');
const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
  const url = process.argv[2], out = process.argv[3];
  const browser = await puppeteer.launch({
    executablePath: '/bin/google-chrome-stable',
    headless: 'new',
    args: ['--no-sandbox','--disable-dev-shm-usage','--disable-gpu','--hide-scrollbars','--force-color-profile=srgb'],
    defaultViewport: { width: 1280, height: 820, deviceScaleFactor: 2 },
  });
  try {
    const page = await browser.newPage();
    await page.goto(url, { waitUntil: 'networkidle2', timeout: 45000 });
    // force-reveal entrance animations so the hero isn't blank
    await page.evaluate(() => {
      const e = document.querySelectorAll('.fade-up,.fade-in,.reveal,[data-reveal],.animate,.scroll-reveal');
      e.forEach(el => { el.classList.add('visible','active','in-view','show'); el.style.opacity='1'; el.style.transform='none'; el.style.visibility='visible'; });
    });
    // wait for above-the-fold images to load
    await page.evaluate(async () => {
      const imgs = Array.from(document.images).slice(0, 14);
      await Promise.all(imgs.map(img => (img.complete && img.naturalWidth > 0)
        ? Promise.resolve()
        : new Promise(res => { img.addEventListener('load', res); img.addEventListener('error', res); setTimeout(res, 6000); })));
      if (document.fonts && document.fonts.ready) { try { await document.fonts.ready; } catch(e){} }
    });
    await sleep(700);
    await page.screenshot({ path: out, type: 'jpeg', quality: 82, fullPage: false, clip: { x:0, y:0, width:1280, height:820 } });
    console.log('OK');
  } catch (e) { console.error('ERR ' + e.message); process.exitCode = 2; }
  finally { await browser.close(); }
})();
