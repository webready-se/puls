#!/usr/bin/env node
// Draws public/og.png (1200 x 630), the image a shared link shows when it is pasted into a chat:
// the mark, the name and a line for the person opening the link in the middle, all within the
// centre square a chat crops to (Teams, WhatsApp), and the coral day bars faint along the bottom.
// No figures: the image is the same for every link, and a preview service keeps it on its own
// servers. Not the tagline: a reader who has never heard of Puls took it for an ad.
//
// Usage: node scripts/og-image.mjs        (CHROME=/path/to/chrome to override)
// Run it again when the mark or the kit's night surface changes, and commit the PNG.
// Needs: Node 22+, Chrome or Chromium. No npm dependencies.

import { execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const out = join(root, 'public/og.png');

function findChrome() {
  const candidates = [
    process.env.CHROME,
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/usr/bin/google-chrome',
    '/usr/bin/google-chrome-stable',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
  ].filter(Boolean);
  const found = candidates.find((p) => existsSync(p));
  if (!found) throw new Error('Chrome not found. Set CHROME=/path/to/chrome');
  return found;
}

// Three weeks of days, weekdays high and weekends low: the dashboard's bars, read as a pattern
const bars = [40, 55, 48, 70, 62, 30, 26, 44, 58, 52, 76, 68, 34, 30, 47, 60, 54, 80, 72, 38, 34];

const page = `<!doctype html><html><head><meta charset="utf-8"><style>
  @font-face { font-family: Figtree; src: url(${pathToFileURL(join(root, 'public/fonts/figtree-latin.woff2'))}) format('woff2'); font-weight: 300 900; }
  * { margin: 0; box-sizing: border-box; }
  html, body { width: 1200px; height: 630px; overflow: hidden; }
  body { background: #0f172a; color: #f1f5f9; font-family: Figtree, sans-serif; -webkit-font-smoothing: antialiased; position: relative; }
  .stack { position: absolute; left: 0; right: 0; top: 96px; display: flex; flex-direction: column; align-items: center; text-align: center; }
  .mark { width: 112px; height: 112px; display: block; }
  b { font-size: 60px; font-weight: 800; letter-spacing: -0.03em; margin-top: 18px; line-height: 1; }
  h1 { font-size: 50px; margin-top: 30px; font-weight: 800; letter-spacing: -0.025em; line-height: 1.08; }
  h1 span { color: #fb7185; }
  .bars { position: absolute; left: 50%; transform: translateX(-50%); bottom: 0; height: 120px; display: flex; align-items: flex-end; gap: 14px; opacity: .45; }
  .bars i { display: block; width: 44px; border-radius: 10px 10px 0 0; background: linear-gradient(#f16272, #f7a1aa); }
</style></head><body>
  <div class="stack">
    <svg class="mark" viewBox="0 0 36 36" aria-hidden="true"><rect width="36" height="36" rx="8" fill="#f16272"/><path d="M24 26V16M18 26V10M12 26v-8" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/></svg>
    <b>Puls</b>
    <h1>Your website’s <span>visitors</span></h1>
  </div>
  <div class="bars">${bars.map((h) => `<i style="height:${h}%"></i>`).join('')}</div>
</body></html>`;

const tmp = mkdtempSync(join(tmpdir(), 'puls-og-'));
try {
  const html = join(tmp, 'og.html');
  writeFileSync(html, page);
  execFileSync(findChrome(), [
    '--headless=new', '--disable-gpu', '--hide-scrollbars', '--force-device-scale-factor=1',
    `--user-data-dir=${join(tmp, 'profile')}`, '--window-size=1200,630', `--screenshot=${out}`,
    pathToFileURL(html).href,
  ], { stdio: 'ignore' });
  console.log(`public/og.png (${Math.round(statSync(out).size / 1024)} KB)`);
} finally {
  rmSync(tmp, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
}
