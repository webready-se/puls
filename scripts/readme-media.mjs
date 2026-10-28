#!/usr/bin/env node
// README media: seed a demo database, serve it and drive headless Chrome over the
// DevTools protocol to capture the screenshots, the drill-down GIF and the social
// preview card in docs/screenshots/. Every image is cut to a dashboard element, so
// layout changes do not shift the crops.
//
// Usage: node scripts/readme-media.mjs          (CHROME=/path/to/chrome to override)
//        node scripts/readme-media.mjs social   only the social preview, from the current dashboard.png
// Needs: Node 22+ (built-in WebSocket), PHP with pdo_sqlite, Chrome or Chromium,
// ffmpeg for the GIF. No npm dependencies.

import { spawn, execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync, readdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const out = join(root, 'docs/screenshots');
const PHP_PORT = 8766;
const CDP_PORT = 9334;
const tmp = mkdtempSync(join(tmpdir(), 'puls-media-'));
const children = [];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const onlySocial = process.argv[2] === 'social';

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

async function waitForHttp(url, tries = 100) {
  for (let i = 0; i < tries; i++) {
    try {
      const r = await fetch(url);
      if (r.ok) return r;
    } catch {}
    await sleep(100);
  }
  throw new Error(`Timed out waiting for ${url}`);
}

async function main() {
  if (!onlySocial) execFileSync('ffmpeg', ['-version'], { stdio: 'ignore' });
  mkdirSync(out, { recursive: true });
  const base = `http://localhost:${PHP_PORT}`;

  if (!onlySocial) {
    // 1. Demo data: 30 days of traffic, plus what newer features need to be visible
    const dbPath = join(tmp, 'demo.sqlite');
    execFileSync('php', [join(root, 'scripts/seed-demo.php'), dbPath], { stdio: 'ignore' });
    const usersFile = join(tmp, 'users.json');
    execFileSync('php', ['-r', 'file_put_contents($argv[1], json_encode(["demo" => ["password" => password_hash("demo-pass", PASSWORD_BCRYPT, ["cost" => 4]), "sites" => []]]));', usersFile]);
    children.push(spawn('php', ['-S', `localhost:${PHP_PORT}`, '-t', join(root, 'public')], {
      env: { ...process.env, DB_PATH: dbPath, USERS_FILE: usersFile }, stdio: 'ignore',
    }));
    await waitForHttp(`${base}/?health`); // runs every migration on the seeded schema
    execFileSync('php', ['-r', `
      $db = new PDO('sqlite:' . $argv[1]);
      $site = 'demo.example.com';
      foreach ([['2.4.0', 20], ['2.5.0', 4]] as [$v, $ago]) {
          $db->prepare('INSERT OR IGNORE INTO app_versions (site, version, first_seen) VALUES (?, ?, ?)')->execute([$site, $v, date('Y-m-d', strtotime("-$ago days"))]);
      }
      $db->exec("UPDATE events SET app_version = CASE WHEN created_at >= date('now', '-4 days') THEN '2.5.0' ELSE '2.4.0' END");
      $card = $db->prepare("INSERT INTO breakdowns (site, label, event_name, group_key, created_at, position) VALUES (?, ?, ?, ?, datetime('now'), ?)");
      $card->execute([$site, 'CTA placement', 'cta_click', 'location', 0]);
      $card->execute([$site, 'Sign-ups by plan', 'signup', 'plan', 1]);
      $goal = $db->prepare("INSERT INTO goals (site, path, label, type, created_at, target, target_period) VALUES (?, ?, ?, ?, datetime('now'), ?, ?)");
      $goal->execute([$site, 'signup', 'Signed up', 'event', 5, 'week']);
      $goal->execute([$site, 'cta_click', 'CTA clicked', 'event', 15, 'week']);
      $goal->execute([$site, '/pricing', 'Saw pricing', 'page', null, null]);
      $note = $db->prepare("INSERT INTO annotations (site, date, end_date, label, created_at) VALUES (?, ?, ?, ?, datetime('now'))");
      $note->execute([$site, date('Y-m-d', strtotime('-3 days')), null, 'Newsletter']);
    `, dbPath]);
  }

  // 2. Headless Chrome with remote debugging
  children.push(spawn(findChrome(), [
    '--headless=new', `--remote-debugging-port=${CDP_PORT}`, `--user-data-dir=${join(tmp, 'chrome')}`,
    '--no-first-run', '--no-default-browser-check', '--disable-gpu', '--hide-scrollbars', 'about:blank',
  ], { stdio: 'ignore' }));
  await waitForHttp(`http://127.0.0.1:${CDP_PORT}/json/version`, 300);
  const page = (await (await fetch(`http://127.0.0.1:${CDP_PORT}/json/list`)).json()).find((t) => t.type === 'page');
  const ws = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
  let id = 0;
  const pending = new Map();
  const listeners = [];
  ws.onmessage = (msg) => {
    const data = JSON.parse(msg.data);
    if (data.id && pending.has(data.id)) {
      const { resolve, reject } = pending.get(data.id);
      pending.delete(data.id);
      data.error ? reject(new Error(data.error.message)) : resolve(data.result);
      return;
    }
    listeners.forEach((l) => l(data));
  };
  const send = (method, params = {}) => new Promise((resolve, reject) => {
    const msgId = ++id;
    pending.set(msgId, { resolve, reject });
    ws.send(JSON.stringify({ id: msgId, method, params }));
  });
  const loaded = () => new Promise((resolve) => {
    const l = (d) => { if (d.method === 'Page.loadEventFired') { listeners.splice(listeners.indexOf(l), 1); resolve(); } };
    listeners.push(l);
  });
  const run = async (expression) => {
    const r = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
    return r.result.value;
  };
  const navigate = async (url) => { const w = loaded(); await send('Page.navigate', { url }); await w; };
  const viewport = (width, height, deviceScaleFactor = 2) => send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor, mobile: false });
  const until = (cond) => run(`new Promise(async (res, rej) => { for (let t = 0; t < 100; t++) { if (${cond}) return res(true); await new Promise((r) => setTimeout(r, 100)); } rej(new Error('timed out: ' + ${JSON.stringify(cond)})); })`);
  // Page coordinates of elements from the top of the first to the bottom of the last
  const box = (first, last = first, pad = 0) => run(`(() => {
    const a = document.querySelector(${JSON.stringify(first)}).getBoundingClientRect();
    const b = document.querySelector(${JSON.stringify(last)}).getBoundingClientRect();
    return { x: Math.max(0, a.left + scrollX - ${pad}), y: Math.max(0, a.top + scrollY - ${pad}), width: Math.max(a.right, b.right) - a.left + 2 * ${pad}, height: b.bottom - a.top + 2 * ${pad} };
  })()`);
  const shoot = async (name, clip) => {
    const img = await send('Page.captureScreenshot', { format: 'png', clip: { ...clip, scale: 1 }, captureBeyondViewport: true });
    writeFileSync(join(out, name), Buffer.from(img.data, 'base64'));
    console.log(`  ${name}`);
  };
  const theme = (t) => run(`document.documentElement.setAttribute('data-theme', '${t}')`);

  await send('Page.enable');
  await send('Runtime.enable');
  await viewport(1400, 1000);

  if (!onlySocial) {
    // 3. Log in (goals only show to signed-in users) and wait for the dashboard. The demo
    // database has several sites, so pick the demo site, as the dashboard would remember it,
    // and open the folded bots and pages to fix for bots.png
    await navigate(`${base}/`);
    await run(`localStorage.setItem('puls-site', 'demo.example.com'); localStorage.setItem('puls-bots-open', '1'); localStorage.setItem('puls-broken-open', '1')`);
    const w = loaded();
    await run(`document.getElementById('username').value = 'demo'; document.getElementById('password').value = 'demo-pass'; document.querySelector('form[action="/?login"]').submit()`);
    await w;
    await until(`document.querySelector('.goal-tile') && document.querySelector('.breakdown-card')`);
    console.log('Capturing to docs/screenshots/');

    // Hero: header, numbers, chart, goals and the summary row, in both themes
    const hero = await box('.header', '[data-island="goals"]', 0);
    hero.height += 16;
    await theme('dark');
    await sleep(300);
    await shoot('dashboard.png', { ...hero, x: 0, width: 1400 });
    await theme('light');
    await sleep(300);
    await shoot('dashboard-light.png', { ...hero, x: 0, width: 1400 });
    await theme('dark');

    await run(`document.querySelector('.chart-compare-toggle:not(.note-toggle)').click()`);
    await sleep(300);
    await shoot('dashboard-compare.png', await box('#chart-card', '#chart-card', 12));
    await run(`document.querySelector('.chart-compare-toggle:not(.note-toggle)').click()`);

    await run(`document.querySelector('.card-tab[data-tab="events"]').click(); document.querySelector('.card-tab[data-tab="countries"]').click()`);
    await sleep(300);
    await shoot('events.png', await box('[data-island="traffic"]', '[data-island="traffic"]', 12));
    await shoot('cards.png', await box('[data-island="breakdowns"]', '[data-island="breakdowns"]', 12));
    await shoot('bots.png', await box('[data-island="bots"]', '[data-island="broken"]', 12));

    // 4. GIF: open an event, group it on a key. A drawn pointer shows where each click lands.
    await navigate(`${base}/`);
    await until(`document.querySelector('.breakdown-card')`);
    await theme('dark');
    await run(`(() => {
      const card = document.querySelector('[data-island="traffic"] .card');
      // Below the sticky header, which would otherwise cover the tabs in the capture
      window.scrollTo(0, card.getBoundingClientRect().top + scrollY - 110);
      const p = document.createElement('div');
      p.id = 'demo-pointer';
      p.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24"><path d="M4 2l16 10-7 1.6L9.4 21z" fill="#fff" stroke="#0f172a" stroke-width="1.5" stroke-linejoin="round"/></svg>';
      p.style.cssText = 'position:absolute;z-index:9999;pointer-events:none;transition:left .7s ease,top .7s ease;left:0;top:0';
      document.body.appendChild(p);
      const r = card.getBoundingClientRect();
      p.style.left = (r.left + scrollX + r.width * 0.7) + 'px';
      p.style.top = (r.top + scrollY + 160) + 'px';
    })()`);
    const gifClip = { ...(await box('[data-island="traffic"] .card')), height: 0 };
    gifClip.height = 760;
    const frames = join(tmp, 'frames');
    mkdirSync(frames);
    let recording = true, count = 0;
    const started = Date.now();
    const recorder = (async () => {
      while (recording) {
        const img = await send('Page.captureScreenshot', { format: 'png', clip: { ...gifClip, scale: 1 }, captureBeyondViewport: true });
        writeFileSync(join(frames, `f${String(count++).padStart(4, '0')}.png`), Buffer.from(img.data, 'base64'));
      }
    })();
    const pointTo = (selector) => run(`(() => {
      const r = document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();
      const p = document.getElementById('demo-pointer');
      p.style.left = (r.left + scrollX + Math.min(40, r.width / 2)) + 'px';
      p.style.top = (r.top + scrollY + r.height / 2) + 'px';
    })()`);
    const click = (selector) => run(`document.querySelector(${JSON.stringify(selector)}).click()`);
    await sleep(700);
    await pointTo('[data-island="traffic"] .card-tab[data-tab="events"]');
    await sleep(900);
    await click('[data-island="traffic"] .card-tab[data-tab="events"]');
    await sleep(700);
    await pointTo('.trow[data-event="cta_click"]');
    await sleep(900);
    await click('.trow[data-event="cta_click"]');
    await until(`document.querySelector('.event-detail .event-chart .chart-bar')`);
    await sleep(1400);
    await pointTo('.event-detail .event-chip[data-key="location"]');
    await sleep(900);
    await click('.event-detail .event-chip[data-key="location"]');
    await until(`document.querySelector('.event-detail') && document.querySelector('.event-detail')._state.data.eventGroupKey === 'location'`);
    await sleep(2200);
    recording = false;
    await recorder;
    const fps = Math.max(4, Math.round(count / ((Date.now() - started) / 1000)));
    execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-framerate', String(fps), '-i', join(frames, 'f%04d.png'),
      '-vf', 'fps=12,scale=720:-1:flags=lanczos,split[a][b];[a]palettegen=max_colors=96:stats_mode=diff[p];[b][p]paletteuse=dither=bayer:bayer_scale=5:diff_mode=rectangle',
      '-loop', '0', join(out, 'drill-down.gif')]);
    console.log(`  drill-down.gif (${count} frames at ${fps} fps)`);
  }

  // 5. Social preview (1280x640) for links shared on Hacker News, Reddit, Slack:
  //    set it in the repository settings, GitHub has no API for it. The icon is the app icon itself
  const card = join(tmp, 'social.html');
  writeFileSync(card, `<!doctype html><html><head><meta charset="utf-8"><style>
    * { margin: 0; box-sizing: border-box; }
    body { width: 1280px; height: 640px; overflow: hidden; background: #0f172a; color: #f1f5f9;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif; display: flex; }
    .text { width: 560px; padding: 84px 0 0 80px; }
    .brand { display: flex; align-items: center; gap: 18px; margin-bottom: 44px; }
    .icon { width: 64px; height: 64px; }
    .name { font-size: 46px; font-weight: 800; letter-spacing: -0.02em; }
    h1 { font-size: 50px; line-height: 1.12; letter-spacing: -0.03em; font-weight: 800; }
    h1 span { color: #fb7185; }
    p { margin-top: 26px; font-size: 24px; line-height: 1.45; color: #94a3b8; }
    .shot { flex: 1; position: relative; }
    .shot img { position: absolute; left: 0; top: 70px; width: 980px; border-radius: 16px;
      border: 1px solid #334155; box-shadow: 0 30px 80px rgba(0,0,0,.55); }
  </style></head><body>
    <div class="text">
      <div class="brand"><img class="icon" src="${pathToFileURL(join(root, 'public/icon-192.png'))}" alt=""><div class="name">Puls</div></div>
      <h1>One file.<br>No cookies.<br><span>Full picture.</span></h1>
      <p>Self-hosted web analytics in a single PHP file and SQLite. MIT licensed.</p>
    </div>
    <div class="shot"><img src="${pathToFileURL(join(out, 'dashboard.png'))}"></div>
  </body></html>`);
  await viewport(1280, 640, 1);
  await navigate(pathToFileURL(card).href);
  await sleep(300);
  await shoot('social-preview.png', { x: 0, y: 0, width: 1280, height: 640 });

  ws.close();
  console.log(`Done. ${readdirSync(out).length} files in docs/screenshots/`);
}

main()
  .catch((e) => { console.error('readme-media failed:', e.message); process.exitCode = 1; })
  .finally(() => {
    children.forEach((c) => { try { c.kill('SIGKILL'); } catch {} });
    try { rmSync(tmp, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 }); } catch {}
  });
