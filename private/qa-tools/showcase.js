// showcase.js — capture a hero screenshot of a generated preview without puppeteer.
// Puppeteer-launched Chrome was failing nondeterministically with
// "chrome_crashpad_handler: --database is required" on this droplet; native
// Chrome (--headless=new --screenshot) works reliably. We then transcode PNG->JPG
// via ImageMagick `convert` to keep the cache shape unchanged (showcase.jpg).
const { execSync, spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const url = process.argv[2], out = process.argv[3];
if (!url || !out) { console.error('usage: showcase.js URL OUT'); process.exit(2); }

const ud = fs.mkdtempSync('/tmp/crud-');
const pngPath = path.join(ud, 'shot.png');
const args = [
  '--headless=new','--no-sandbox','--disable-gpu','--disable-dev-shm-usage',
  '--disable-breakpad','--no-crash-upload','--disable-features=Crashpad',
  '--hide-scrollbars','--force-color-profile=srgb',
  '--user-data-dir=' + ud,
  '--crash-dumps-dir=' + ud,
  '--window-size=1280,820',
  '--virtual-time-budget=3000',
  '--screenshot=' + pngPath,
  url,
];

try {
  // Ensure Chrome has a writable HOME (cron user "nobody" doesn't).
  const env = Object.assign({}, process.env, { HOME: ud });
  const r = spawnSync('/bin/google-chrome-stable', args, { env, timeout: 65000, stdio: 'ignore' });
  if (r.status !== 0 && !fs.existsSync(pngPath)) {
    console.error('ERR chrome rc=' + r.status + ' sig=' + r.signal);
    process.exit(2);
  }
  if (!fs.existsSync(pngPath) || fs.statSync(pngPath).size < 4000) {
    console.error('ERR no/tiny png at ' + pngPath);
    process.exit(2);
  }
  // Transcode PNG -> JPG at 82 quality, upscale to 2x (2560x1640) to match the
  // historical output shape the admin UI expects.
  const c = spawnSync('/bin/convert', [pngPath, '-resize', '2560x1640', '-quality', '82', out], { timeout: 30000, stdio: 'ignore' });
  if (c.status !== 0 || !fs.existsSync(out) || fs.statSync(out).size < 2000) {
    console.error('ERR convert rc=' + c.status);
    process.exit(2);
  }
  console.log('OK');
} catch (e) {
  console.error('ERR ' + e.message);
  process.exit(2);
} finally {
  try { fs.rmSync(ud, { recursive: true, force: true }); } catch(e){}
}
