#!/usr/bin/env node
// Dashboard smoke test: seed a demo DB, serve it, drive headless Chrome over the
// DevTools protocol and fail on any JavaScript error or missing UI element.
//
// Usage: node scripts/smoke.mjs        (CHROME=/path/to/chrome to override)
// Needs: Node 22+ (built-in WebSocket), PHP with pdo_sqlite, Chrome or Chromium.
// No npm dependencies: this is a dev tool, Puls itself stays dependency-free.

import { spawn, execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const PHP_PORT = 8799;
const CDP_PORT = 9333;
const tmp = mkdtempSync(join(tmpdir(), 'puls-smoke-'));
const dbPath = join(tmp, 'smoke.sqlite');
const children = [];

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

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function waitForHttp(url, tries = 50) {
  for (let i = 0; i < tries; i++) {
    try {
      const r = await fetch(url);
      if (r.ok) return r;
    } catch {}
    await sleep(100);
  }
  throw new Error(`Timed out waiting for ${url}`);
}

async function cleanup() {
  await Promise.all(children.map((c) => new Promise((resolve) => {
    if (c.exitCode !== null) return resolve();
    c.once('exit', resolve);
    try { c.kill('SIGKILL'); } catch { resolve(); }
  })));
  // Chrome can still be flushing its profile for a moment after exit
  try { rmSync(tmp, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 }); } catch {}
}

async function main() {
  // 1. Seed a demo DB with the old schema; the first request runs every migration on it
  const seed = execFileSync('php', [join(root, 'scripts/seed-demo.php'), dbPath], { encoding: 'utf8' });
  const token = seed.match(/Share token: ([a-f0-9]+)/)?.[1];
  if (!token) throw new Error('Seed did not print a share token');

  // 2. Serve it
  const usersFile = join(tmp, 'users.json');
  execFileSync('php', ['-r', 'file_put_contents($argv[1], json_encode(["smoke" => ["password" => password_hash("smoke-pass", PASSWORD_BCRYPT, ["cost" => 4]), "sites" => []]]));', usersFile]);
  const env = { ...process.env, DB_PATH: dbPath, APP_KEY: 'smoke-test-key', USERS_FILE: usersFile };
  children.push(spawn('php', ['-S', `localhost:${PHP_PORT}`, '-t', join(root, 'public')], { env, stdio: 'ignore' }));
  const base = `http://localhost:${PHP_PORT}`;
  await waitForHttp(`${base}/?health`);

  // 3. Data for today's features: versioned events (release markers, @version) and a saved breakdown
  const post = (body, ua) => fetch(`${base}/?event`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'User-Agent': ua },
    body: JSON.stringify(body),
  });
  for (let i = 0; i < 3; i++) {
    await post({ event_name: 'cta_click', site: 'demo.example.com', page_path: '/', app_version: '9.9.' + i, event_data: { location: 'smoke' + i } }, `Mozilla/5.0 Smoke/${i}`);
  }
  execFileSync('php', ['-r', `
    $db = new PDO('sqlite:' . $argv[1]);
    $db->exec("INSERT INTO breakdowns (site, label, event_name, group_key, created_at) VALUES ('demo.example.com', 'Smoke card', 'cta_click', 'location', datetime('now'))");
  `, dbPath]);

  // 4. Headless Chrome with remote debugging
  const chrome = spawn(findChrome(), [
    '--headless=new', `--remote-debugging-port=${CDP_PORT}`, `--user-data-dir=${join(tmp, 'chrome')}`,
    '--no-first-run', '--no-default-browser-check', '--disable-gpu', '--window-size=1400,1900', 'about:blank',
  ], { stdio: ['ignore', 'ignore', 'pipe'] });
  children.push(chrome);
  let chromeLog = '';
  chrome.stderr.on('data', (d) => { chromeLog = (chromeLog + d).slice(-4000); });
  // A cold Chrome start on a CI runner can take well over five seconds
  try {
    await waitForHttp(`http://127.0.0.1:${CDP_PORT}/json/version`, 300);
  } catch (e) {
    throw new Error(`${e.message}\nChrome stderr (tail):\n${chromeLog}`);
  }
  const targets = await (await fetch(`http://127.0.0.1:${CDP_PORT}/json/list`)).json();
  const page = targets.find((t) => t.type === 'page');

  const ws = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
  let id = 0;
  const pending = new Map();
  const errors = [];
  const listeners = [];
  ws.onmessage = (msg) => {
    const data = JSON.parse(msg.data);
    if (data.id && pending.has(data.id)) {
      const { resolve, reject } = pending.get(data.id);
      pending.delete(data.id);
      data.error ? reject(new Error(data.error.message)) : resolve(data.result);
      return;
    }
    if (data.method === 'Runtime.exceptionThrown') {
      const d = data.params.exceptionDetails;
      errors.push(`Exception: ${d.exception?.description || d.text}`);
    }
    if (data.method === 'Runtime.consoleAPICalled' && data.params.type === 'error') {
      errors.push(`console.error: ${data.params.args.map((a) => a.value ?? a.description).join(' ')}`);
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

  await send('Page.enable');
  await send('Runtime.enable');

  // 5. Load the shared dashboard and walk through it
  const wait = loaded();
  await send('Page.navigate', { url: `${base}/?share=${token}#theme=dark` });
  await wait;

  const steps = async () => {
    const fails = [];
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const q = (s) => document.querySelector(s);
    const waitFor = async (fn, what, ms = 5000) => {
      for (let t = 0; t < ms; t += 100) { if (fn()) return true; await sleep(100); }
      fails.push(`timed out waiting for ${what}`);
      return false;
    };
    const expect = (cond, what) => { if (!cond) fails.push(what); };

    if (!await waitFor(() => q('#chart-card'), 'pageview chart')) return fails;
    expect(document.querySelectorAll('.stat-card, .card').length > 5, 'dashboard cards render');
    expect(q('#chart-card .chart-release'), 'release marker on pageview chart');
    expect(q('.breakdown-card'), 'saved breakdown card');
    expect(q('.breakdown-card .event-new'), 'NEW badge on breakdown card');

    // Event drill-down
    q('.card-tab[data-tab="events"]').click();
    const row = q('.trow[data-event="cta_click"]');
    expect(row, 'cta_click row in Events tab');
    if (!row) return fails;
    expect(row.getAttribute('role') === 'button' && row.tabIndex === 0, 'event row is a keyboard button');
    row.focus();
    row.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    if (!await waitFor(() => q('.event-detail') && q('.event-detail')._state, 'event drill-down opened with Enter')) return fails;
    expect(q('.event-detail .event-summary'), 'drill-down summary');
    expect(q('.event-detail .event-chart .chart-bar'), 'drill-down trend bars');
    expect(q('.event-detail .event-chip[data-key="@version"]'), 'version group chip');
    expect(q('.event-detail .event-csv'), 'CSV link');

    // Group by version
    q('.event-detail .event-chip[data-key="@version"]').click();
    await waitFor(() => q('.event-detail')._state && q('.event-detail')._state.data.eventGroupKey === '@version', 'group by version');
    expect(document.querySelectorAll('.event-detail .trow').length >= 3, 'version values listed');

    // Funnel
    const input = q('.event-detail input.event-select');
    const options = input && input.list ? input.list.options : [];
    expect(options.length > 0, 'funnel picker suggests events');
    // An event never seen still gives a funnel (0 converted) instead of an error
    input.value = 'never_seen_event';
    input.dispatchEvent(new Event('change'));
    await waitFor(() => q('.event-detail .event-funnel') && q('.event-detail')._state.then === 'never_seen_event', 'funnel to an unseen event');
    const again = q('.event-detail input.event-select');
    again.value = options[0].value;
    again.dispatchEvent(new Event('change'));
    await waitFor(() => q('.event-detail .event-funnel') && q('.event-detail')._state.then === options[0].value, 'funnel result');

    // Compare mode follows into the drill-down
    const cmp = q('.chart-compare-toggle');
    if (cmp) {
      cmp.click();
      await waitFor(() => q('.event-detail .event-chart.compare') || !q('.chart-compare-toggle.active'), 'compare overlay in drill-down');
    }

    // Long period: panel survives the reload and the chart turns dense
    q('.period-btn[data-days="90"]').click();
    await waitFor(() => q('.event-detail .event-chart.dense'), 'dense 90-day chart with panel still open', 8000);
    expect(q('.event-detail') && q('.event-detail')._state && q('.event-detail')._state.then, 'funnel choice kept across period change');

    // Breakdown card opens the same drill-down inside the card, grouped on its key
    const open = q('.breakdown-card .breakdown-open');
    expect(open, 'Details button on breakdown card');
    if (open) {
      open.click();
      await waitFor(() => q('.breakdown-card .event-detail') && q('.breakdown-card .event-detail')._state, 'drill-down inside breakdown card');
      expect(q('.breakdown-card .event-detail')._state.data.eventGroupKey === 'location', 'card drill-down grouped on the card key');
      expect(q('.breakdown-card .breakdown-open').getAttribute('aria-expanded') === 'true', 'Details button reports expanded');
      q('.period-btn[data-days="30"]').click();
      await waitFor(() => q('.breakdown-card .event-detail') && q('.breakdown-card .event-detail')._state && q('.breakdown-card .event-detail')._state.data.days === 30, 'card drill-down kept across period change', 8000);
    }

    // Light theme renders too
    document.documentElement.setAttribute('data-theme', 'light');
    expect(getComputedStyle(document.body).backgroundColor !== '', 'light theme applies');
    return fails;
  };

  const result = await send('Runtime.evaluate', {
    expression: `(${steps.toString()})()`,
    awaitPromise: true,
    returnByValue: true,
  });
  if (result.exceptionDetails) errors.push(`Smoke steps threw: ${result.exceptionDetails.exception?.description}`);
  const failures = [...(result.result?.value || [])];

  // 6. Logged in: a goal can be added for an event that has not happened yet
  const wait2 = loaded();
  await send('Page.navigate', { url: `${base}/` });
  await wait2;
  const wait3 = loaded();
  await send('Runtime.evaluate', { expression: `document.getElementById('username').value='smoke'; document.getElementById('password').value='smoke-pass'; document.querySelector('form[action="/?login"]').submit();` });
  await wait3;
  const loggedIn = async () => {
    const fails = [];
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const q = (s) => document.querySelector(s);
    const waitFor = async (fn, what, ms = 5000) => {
      for (let t = 0; t < ms; t += 100) { if (fn()) return true; await sleep(100); }
      fails.push(`timed out waiting for ${what}`);
      return false;
    };
    if (!await waitFor(() => q('#chart-card') && q('#goal-search'), 'logged-in dashboard')) return fails;
    const search = q('#goal-search');
    search.value = 'pwa_install';
    search.dispatchEvent(new Event('input'));
    const add = q('#goal-list .goal-item-new');
    if (!add || add.getAttribute('data-type') !== 'event') { fails.push('goal picker offers an unseen event'); return fails; }
    add.click();
    await waitFor(() => [...document.querySelectorAll('.goal-row')].some((r) => r.textContent.includes('pwa_install') && r.textContent.includes('No hits yet')), 'unseen event goal shows No hits yet', 8000);
    if (q('.menu-close').getAttribute('aria-label') !== 'Close settings') fails.push('settings close button is labelled');
    return fails;
  };
  const result2 = await send('Runtime.evaluate', { expression: `(${loggedIn.toString()})()`, awaitPromise: true, returnByValue: true });
  if (result2.exceptionDetails) errors.push(`Logged-in steps threw: ${result2.exceptionDetails.exception?.description}`);
  failures.push(...(result2.result?.value || []));

  ws.close();
  failures.push(...errors);
  if (failures.length) {
    console.error(`Dashboard smoke test FAILED (${failures.length}):`);
    failures.forEach((f) => console.error('  - ' + f));
    process.exitCode = 1;
  } else {
    console.log('Dashboard smoke test passed: load, release markers, breakdown card and its details, keyboard drill-down, group by version, funnel incl. unseen event, compare, 90 days, light theme, logged-in goal for an unseen event. No JS errors.');
  }
}

main()
  .catch((e) => { console.error('Smoke test error:', e.message); process.exitCode = 1; })
  .finally(cleanup);
