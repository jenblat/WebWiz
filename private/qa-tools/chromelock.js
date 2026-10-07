'use strict';
/**
 * One headless Chrome at a time, across processes.
 *
 * The box has two cores. The worker cron, the live /api/magic.php build and an
 * admin audit can all want a render at the same moment, and two concurrent
 * Chromes saturate it (measured 2026-05-24). Every renderer in this directory
 * takes this lock before launching and releases it after closing.
 *
 * mkdir is atomic on ext4, so the lock is a directory. A lock older than
 * STALE_MS is treated as abandoned (a renderer that was killed mid-run) and
 * stolen, so a crash can never wedge the pipeline.
 */
const fs = require('fs');

const LOCK_DIR = process.env.WW_CHROME_LOCK || '/tmp/ww-chrome.lock';
const STALE_MS = 240000;

function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }

async function acquire(waitMs = 180000) {
  const until = Date.now() + waitMs;
  for (;;) {
    try {
      fs.mkdirSync(LOCK_DIR);
      fs.writeFileSync(LOCK_DIR + '/owner', String(process.pid) + ' ' + new Date().toISOString());
      return release;
    } catch (e) {
      if (e.code !== 'EEXIST') throw e;
      let age = 0;
      try { age = Date.now() - fs.statSync(LOCK_DIR).mtimeMs; } catch { age = STALE_MS + 1; }
      if (age > STALE_MS) { try { fs.rmSync(LOCK_DIR, { recursive: true, force: true }); } catch {} continue; }
      if (Date.now() > until) throw new Error('chrome lock busy for ' + Math.round(waitMs / 1000) + 's');
      await sleep(250 + Math.floor(Math.random() * 250));
    }
  }
}

function release() {
  try { fs.rmSync(LOCK_DIR, { recursive: true, force: true }); } catch {}
}

module.exports = { acquire, release, LOCK_DIR };
