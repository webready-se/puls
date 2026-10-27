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
  const env = { ...process.env, DB_PATH: dbPath, USERS_FILE: usersFile };
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
    // A second card, so the logged-in view can arrange them
    $db->exec("INSERT INTO breakdowns (site, label, event_name, group_key, created_at) VALUES ('demo.example.com', 'Versions', 'cta_click', '@version', datetime('now'))");
    // More than five values, so the card offers Show all
    $more = $db->prepare("INSERT INTO events (site, event_name, page_path, event_data, visitor_hash, created_at) VALUES ('demo.example.com', 'cta_click', '/', ?, ?, datetime('now'))");
    for ($i = 0; $i < 6; $i++) $more->execute([json_encode(['location' => 'extra' . $i]), 'extra-' . $i]);
    // Hostile names from the public endpoints must render as text, never as markup or script
    $hostile = 'x"><img src=x onerror="window.__xss=1">' . "');window.__xss=2;('";
    $ev = $db->prepare("INSERT INTO events (site, event_name, page_path, visitor_hash, created_at) VALUES ('demo.example.com', ?, '/', ?, datetime('now'))");
    for ($i = 0; $i < 40; $i++) $ev->execute([$hostile, 'xss-' . $i]);
    $pv = $db->prepare("INSERT INTO pageviews (site, path, visitor_hash, created_at) VALUES ('demo.example.com', ?, ?, datetime('now'))");
    for ($i = 0; $i < 400; $i++) $pv->execute(['/' . $hostile, 'xss-' . $i]);
    // A path a spreadsheet would read as a formula: the CSV export must defuse it
    for ($i = 0; $i < 450; $i++) $pv->execute(['=HYPERLINK("https://example.com","x")', 'csv-' . $i]);
    // Annotations: a range, a label that needs escaping, and one on the release day (stacked labels)
    $note = $db->prepare("INSERT INTO annotations (site, date, end_date, label, created_at) VALUES ('demo.example.com', ?, ?, ?, datetime('now'))");
    $note->execute([date('Y-m-d', strtotime('-6 days')), date('Y-m-d', strtotime('-4 days')), 'Smoke campaign']);
    $note->execute([date('Y-m-d', strtotime('-2 days')), null, 'Say "hi" <b>bold</b>']);
    $note->execute([date('Y-m-d'), null, 'Smoke film']);
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

    // Saved card at a glance: count and sparkline, five rows, Show all expands in place
    const bdCard = q('.breakdown-card');
    expect(/^\d+$/.test(bdCard.querySelector('.breakdown-count')?.textContent || '') && bdCard.querySelectorAll('.breakdown-spark rect title').length === 7, 'saved card shows its count and a 7-day sparkline');
    const shownRows = () => [...bdCard.querySelectorAll('.breakdown-rows .trow')].filter((r) => r.offsetParent !== null).length;
    const bdToggle = bdCard.querySelector('.breakdown-toggle');
    expect(bdToggle && shownRows() === 5, 'saved card shows five rows and Show all');
    if (bdToggle) {
      bdToggle.click();
      expect(shownRows() > 5 && bdToggle.getAttribute('aria-expanded') === 'true' && bdToggle.textContent === 'Show fewer', 'Show all expands the card in place');
      bdToggle.click();
      expect(shownRows() === 5, 'Show fewer collapses it again');
    }
    expect(/^\d+(\.\d)?% bounce$/.test(q('[data-panel="entry"] .trow-bounce')?.textContent || ''), 'bounce rate on entry pages');

    // Order: the chart right after the numbers, then its hours, saved cards after pages and sources
    const isle = (k) => q(`#app [data-island="${k}"]`);
    const order = ['stats', 'chart', 'hours', 'pages', 'traffic', 'breakdowns', 'bots'].map(isle);
    expect(order.every((el, i) => el && (!i || order[i - 1].compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING)), 'sections in order: numbers, chart, hours, pages, sources, saved cards, bots');
    expect(isle('chart')?.nextElementSibling === isle('hours'), 'the hourly card sits right under the chart');

    // Islands: a refresh of the same view keeps unchanged parts as the same DOM, an open drill-down included
    const card = q('.breakdown-card'), chartCard = q('#chart-card');
    q('.breakdown-card .breakdown-open').click();
    await waitFor(() => q('.breakdown-card .event-detail') && q('.breakdown-card .event-detail')._state, 'card drill-down before a refresh');
    const cardDetail = q('.breakdown-card .event-detail');
    q('#sr-status').textContent = '';
    q('.period-btn.active').click();
    await waitFor(() => /Showing/.test(q('#sr-status').textContent), 'refresh of the same view');
    expect(q('.breakdown-card') === card && q('#chart-card') === chartCard, 'unchanged islands keep their DOM across a refresh');
    expect(q('.breakdown-card .event-detail') === cardDetail, 'open drill-down survives a refresh untouched');
    q('.breakdown-card .breakdown-open').click();

    // Hostile event name and path: shown as text, attributes intact, clicking runs no injected code
    const hostile = 'x"><img src=x onerror="window.__xss=1">' + "');window.__xss=2;('";
    expect(!document.querySelector('#app [onerror]'), 'no injected attribute from a hostile name');
    expect([...document.querySelectorAll('.trow-label')].some((l) => l.title === '/' + hostile), 'hostile path kept intact in its title');
    const hostileRow = [...document.querySelectorAll('.trow[data-event]')].find((r) => r.getAttribute('data-event') === hostile);
    expect(hostileRow, 'hostile event name kept intact in data-event');
    if (hostileRow) {
      hostileRow.click();
      await sleep(300);
      document.querySelectorAll('.event-detail').forEach((d) => d.remove());
    }
    expect(window.__xss === undefined, 'no injected script ran');
    const notes = [...document.querySelectorAll('#chart-card .chart-note')];
    expect(notes.length === 3, 'three annotation labels on the pageview chart');
    expect(document.querySelectorAll('#chart-card .chart-col.in-note').length === 3, 'range annotation tints its three days');
    expect(notes.some((n) => n.textContent === 'Say "hi" <b>bold</b>') && !q('#chart-card .chart-note b'), 'annotation label is escaped');
    expect(q('#chart-card .chart-note.stacked') && q('#chart-card .chart-scroll.stacked-marks'), 'annotation and release on one day stack');
    expect(!q('.note-toggle'), 'no + Note button on a shared dashboard');
    expect(!q('.breakdown-grip'), 'no card grip on a shared dashboard');

    // Accessibility: tabs pattern, dialog focus handling
    const tablist = q('.card-tabs[role="tablist"]');
    expect(tablist, 'card tabs are a tablist');
    if (tablist) {
      const tabs = [...tablist.querySelectorAll('[role="tab"]')];
      expect(tabs.length > 1 && tabs.filter((t) => t.getAttribute('aria-selected') === 'true').length === 1, 'exactly one selected tab');
      tabs[0].focus();
      tabs[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
      await sleep(50);
      expect(tabs[1].getAttribute('aria-selected') === 'true' && document.activeElement === tabs[1], 'ArrowRight moves to and selects the next tab');
      tabs[0].click();
    }
    expect(!document.querySelector('div[onclick]:not([role]):not([class*="overlay"])'), 'every clickable div has a role');
    const showAllBtn = q('.show-all');
    if (showAllBtn) {
      showAllBtn.focus();
      showAllBtn.click();
      await waitFor(() => q('#overlay.open') && q('.overlay-panel').contains(document.activeElement), 'focus moves into Show all dialog');
      const panel = q('.overlay-panel');
      expect(panel.getAttribute('role') === 'dialog' && panel.getAttribute('aria-modal') === 'true', 'Show all is a modal dialog');
      const close = q('.overlay-close');
      close.focus();
      close.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));
      expect(panel.contains(document.activeElement), 'Tab stays inside the dialog');
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      await sleep(50);
      expect(!q('#overlay.open') && document.activeElement === showAllBtn, 'Escape closes and returns focus');
    }

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
    q('#sr-status').textContent = '';
    q('.period-btn[data-days="90"]').click();
    await waitFor(() => /last 90 days/.test(q('#sr-status').textContent), 'screen reader status announces the period change');
    await waitFor(() => q('.event-detail .event-chart.dense'), 'dense 90-day chart with panel still open', 8000);
    // A long period scrolls the pageview chart; it opens on the latest days, not the oldest
    const mainScroll = q('#chart-card .chart-scroll');
    expect(mainScroll.scrollWidth > mainScroll.clientWidth, '90-day pageview chart is wider than the card');
    expect(mainScroll.scrollLeft + mainScroll.clientWidth >= mainScroll.scrollWidth - 1, 'pageview chart opens scrolled to the latest day');
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

    // The 1-day period starts at midnight, so it reads as today, not the last 24 hours
    const todayBtn = q('.period-btn[data-days="1"]');
    expect(todayBtn.textContent === 'Today', 'the 1-day period button reads Today');
    q('#sr-status').textContent = '';
    todayBtn.click();
    if (await waitFor(() => /, today:/.test(q('#sr-status').textContent), 'screen reader status says today')) {
      const sub = q('#chart-card .card-subtitle')?.textContent || '';
      expect(/today/.test(sub) && !/last 1 days/.test(sub), 'chart subtitle says today');
    }

    // Light theme renders too
    document.documentElement.setAttribute('data-theme', 'light');
    expect(getComputedStyle(document.body).backgroundColor !== '', 'light theme applies');
    // The shared footer: name, version and freshness, then the Webready credit with the family's marks
    const foot = q('#app .foot');
    expect(foot && /^Puls \d+\.\d+\.\d+ · from the tracking script, updated \d\d:\d\d$/.test(foot.firstElementChild.textContent), 'footer names Puls, its version and when it was updated');
    expect(foot && foot.querySelector('a[href="https://webready.se"]') && foot.querySelectorAll('.foot-marks svg').length === 1 && foot.querySelector('.foot-marks a[href="https://github.com/webready-se/puls"]'), 'footer credit links Webready, with only the public tools\' marks (today Puls), each linked');
    // Figtree, self-hosted, actually loads (a CSP without font-src would block it silently)
    await document.fonts.ready;
    expect([...document.fonts].some((f) => f.family.replace(/"/g, '') === 'Figtree' && f.status === 'loaded'), 'Figtree is loaded');
    // Colours from the design kit: text in the readable accent, the coral for bars and the mark
    const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
    expect(css('--accent') === '#be123c' && css('--accent-tile') === '#f16272', 'light theme: readable text accent, coral tile');
    await sleep(400); // colours transition after the theme switch
    const tabColour = getComputedStyle(q('.card-tab.active')).color;
    expect(tabColour === 'rgb(190, 18, 60)', `the active tab uses the readable accent (got ${tabColour})`);
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
    const goalTile = () => [...document.querySelectorAll('.goal-tile')].find((t) => t.textContent.includes('pwa_install'));
    await waitFor(() => goalTile() && goalTile().classList.contains('is-empty'), 'unseen event goal is a dimmed tile', 8000);

    // A goal target: Set target opens a form in the tile; the bar scales the target to the period
    goalTile().querySelector('.goal-target-set').click();
    const targetForm = goalTile().querySelector('.goal-target-form');
    if (!targetForm || document.activeElement !== targetForm.elements.target) fails.push('Set target opens a form in the tile and focuses it');
    else {
      targetForm.elements.target.value = '3';
      targetForm.elements.period.value = 'day';
      targetForm.requestSubmit();
      await waitFor(() => goalTile()?.querySelector('.goal-meter') && /^0 \/ 21/.test(goalTile().querySelector('.goal-target').textContent), 'target bar scaled to 7 days (3 per day = 21)', 8000);
    }
    if (q('.menu-close').getAttribute('aria-label') !== 'Close settings') fails.push('settings close button is labelled');

    // CSV export: a visitor-sent value starting with = gets an apostrophe, so a spreadsheet shows it as text
    let csvBlob = null;
    const realCreate = URL.createObjectURL, realClick = HTMLAnchorElement.prototype.click;
    URL.createObjectURL = (b) => { csvBlob = b; return realCreate.call(URL, b); };
    HTMLAnchorElement.prototype.click = function() {};
    window.exportData('csv');
    URL.createObjectURL = realCreate;
    HTMLAnchorElement.prototype.click = realClick;
    const csvText = csvBlob ? await csvBlob.text() : '';
    if (!csvText.includes("'=HYPERLINK(") || /(^|,|\n)"?=HYPERLINK/.test(csvText)) fails.push('CSV export defuses a formula-like path');

    // Arranging saved cards: arrow keys on the grip, then dragging it back; the order is saved
    const titles = () => [...document.querySelectorAll('.breakdown-card .card-title')].map((t) => t.textContent);
    const savedTitles = async () => (await (await fetch('/?api&days=7&site=demo.example.com')).json()).breakdowns.map((b) => b.label);
    const waitSaved = async (want) => {
      for (let t = 0; t < 30; t++) { if ((await savedTitles()).join() === want.join()) return true; await sleep(100); }
      return false;
    };
    const start = titles();
    const grip = q('.breakdown-card .breakdown-grip');
    if (!grip || start.length < 2) { fails.push('a grip on each saved card'); return fails; }
    grip.focus();
    grip.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
    const moved = [start[1], start[0], ...start.slice(2)];
    if (titles().join() !== moved.join() || document.activeElement !== grip) fails.push('an arrow key moves the card and keeps focus on its grip');
    if (!await waitSaved(moved)) fails.push('the new card order is saved');
    const dragGrip = [...document.querySelectorAll('.breakdown-card')].find((c) => c.querySelector('.card-title').textContent === start[0]).querySelector('.breakdown-grip');
    const target = [...document.querySelectorAll('.breakdown-card')].find((c) => c.querySelector('.card-title').textContent === start[1]);
    target.scrollIntoView({ block: 'center' });
    const box = target.getBoundingClientRect(), from = dragGrip.getBoundingClientRect();
    dragGrip.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, button: 0, clientX: from.x + 5, clientY: from.y + 5 }));
    document.dispatchEvent(new PointerEvent('pointermove', { bubbles: true, clientX: box.x + box.width / 2, clientY: box.y + box.height / 2 }));
    document.dispatchEvent(new PointerEvent('pointerup', { bubbles: true }));
    if (titles().join() !== start.join()) fails.push('dragging the grip onto another card moves it there');
    if (!await waitSaved(start)) fails.push('the dragged order is saved');

    // Removing a card or goal waits behind an Undo toast; leaving the page sends it
    const toast = q('#toast');
    const cardCount = document.querySelectorAll('.breakdown-card').length;
    q('.breakdown-card .goal-remove').click();
    if (document.querySelectorAll('.breakdown-card').length !== cardCount - 1 || toast.hidden || !/Card removed/.test(toast.textContent)) fails.push('removing a card hides it and shows the Undo toast');
    q('#toast-undo').click();
    if (document.querySelectorAll('.breakdown-card').length !== cardCount || !toast.hidden) fails.push('Undo brings the card back');
    const goalCount = document.querySelectorAll('.goal-tile').length;
    q('.goal-tile .goal-remove').click();
    if (document.querySelectorAll('.goal-tile').length !== goalCount - 1 || !/Goal removed/.test(toast.textContent)) fails.push('removing a goal hides it behind the Undo toast');
    q('#toast-undo').click();
    if (document.querySelectorAll('.goal-tile').length !== goalCount) fails.push('Undo brings the goal back');
    q('.breakdown-card .goal-remove').click();
    window.dispatchEvent(new Event('pagehide'));
    let removed = false;
    for (let t = 0; t < 30 && !removed; t++) {
      await sleep(100);
      removed = (await savedTitles()).length === cardCount - 1;
    }
    if (!removed) fails.push('a waiting removal is sent when the page is left');

    // Annotations: + Note creates, Settings relabels and removes
    window.closeMenu();
    const toggle = q('.note-toggle');
    if (!toggle) { fails.push('+ Note button on the chart'); return fails; }
    toggle.click();
    const form = q('#note-form');
    if (form.hidden || document.activeElement !== form.elements.label) fails.push('+ Note opens the form and focuses the text');
    form.elements.label.value = 'Smoke note';
    form.requestSubmit();
    await waitFor(() => [...document.querySelectorAll('#chart-card .chart-note')].some((n) => /Smoke note/.test(n.title)), 'new note on the chart', 8000);
    window.openMenu();
    const item = [...document.querySelectorAll('#note-list .note-item')].find((i) => i.querySelector('input').value === 'Smoke note');
    if (!item) { fails.push('note listed in Settings'); return fails; }
    const field = item.querySelector('input');
    field.value = 'Smoke note, clarified';
    field.dispatchEvent(new Event('change'));
    await waitFor(() => [...document.querySelectorAll('#chart-card .chart-note')].some((n) => /Smoke note, clarified/.test(n.title)), 'relabelled note on the chart', 8000);
    item.querySelector('button').click();
    await waitFor(() => ![...document.querySelectorAll('#chart-card .chart-note')].some((n) => /Smoke note/.test(n.title)), 'removed note leaves the chart', 8000);
    window.closeMenu();
    q('.note-toggle').click();
    q('.note-form-hint button').click();
    if (!q('#note-form').hidden || !q('#menu-panel').classList.contains('open')) fails.push('note form hint closes the form and opens Settings');

    // Excluded IPs: Settings shows your own address, Exclude it lists it, × counts it again
    window.openMenu();
    if (await waitFor(() => q('#excluded-mine code') && q('#excluded-mine button'), 'your own address in Settings')) {
      const mine = q('#excluded-mine code').textContent;
      const row = () => [...document.querySelectorAll('#excluded-list .excluded-item')].find((i) => i.getAttribute('data-ip') === mine);
      q('#excluded-mine button').click();
      if (await waitFor(() => row() && /is not counted/.test(q('#excluded-mine').textContent), 'own address excluded and listed', 8000)) {
        row().querySelector('button').click();
        await waitFor(() => !row() && q('#excluded-mine button'), 'own address counted again', 8000);
      }
    }
    window.closeMenu();
    return fails;
  };
  const result2 = await send('Runtime.evaluate', { expression: `(${loggedIn.toString()})()`, awaitPromise: true, returnByValue: true });
  if (result2.exceptionDetails) errors.push(`Logged-in steps threw: ${result2.exceptionDetails.exception?.description}`);
  failures.push(...(result2.result?.value || []));

  // 7. A hostile site name already in the database renders as text in the chart and the site overview
  const hostileSite = 'x"><img src=x onerror="window.__xss=1">' + "');window.__xss=2;('";
  execFileSync('php', ['-r', `
    $db = new PDO('sqlite:' . $argv[1]);
    $db->prepare("INSERT INTO pageviews (site, path, visitor_hash, created_at) VALUES (?, '/', 'xss-site', datetime('now'))")->execute([$argv[2]]);
  `, dbPath, hostileSite]);
  await send('Runtime.evaluate', { expression: `localStorage.setItem('puls-site', ${JSON.stringify(hostileSite)})` });
  const wait5 = loaded();
  await send('Page.navigate', { url: `${base}/` });
  await wait5;
  const hostileSiteSteps = async (name) => {
    const fails = [];
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const q = (s) => document.querySelector(s);
    const waitFor = async (fn, what, ms = 5000) => {
      for (let t = 0; t < ms; t += 100) { if (fn()) return true; await sleep(100); }
      fails.push(`timed out waiting for ${what}`);
      return false;
    };
    if (!await waitFor(() => q('#chart-card .card-subtitle'), 'chart for the hostile site')) return fails;
    if (!q('#chart-card .card-subtitle').textContent.startsWith(name)) fails.push('hostile site name shown as text in the chart subtitle');
    const select = q('#site-select');
    select.value = '';
    select.dispatchEvent(new Event('change'));
    if (await waitFor(() => q('.site-overview-row'), 'site overview with the hostile site')) {
      if (![...document.querySelectorAll('.site-overview-name')].some((n) => n.textContent.startsWith(name))) fails.push('hostile site name shown as text in the site overview');
    }
    await sleep(300);
    if (document.querySelector('#app [onerror]')) fails.push('no injected attribute from a hostile site name');
    if (window.__xss !== undefined) fails.push('no injected script ran from a hostile site name');
    return fails;
  };
  const result4 = await send('Runtime.evaluate', { expression: `(${hostileSiteSteps.toString()})(${JSON.stringify(hostileSite)})`, awaitPromise: true, returnByValue: true });
  if (result4.exceptionDetails) errors.push(`Hostile site steps threw: ${result4.exceptionDetails.exception?.description}`);
  failures.push(...(result4.result?.value || []));

  // 8. Installed app (PWA) with a custom date range: pull-to-refresh reloads that same range
  const day = (ago) => new Date(Date.now() - ago * 86400000).toISOString().slice(0, 10);
  const range = { from: day(20), to: day(10) };
  await send('Runtime.evaluate', { expression: `localStorage.setItem('puls-from', '${range.from}'); localStorage.setItem('puls-to', '${range.to}')` });
  await send('Page.addScriptToEvaluateOnNewDocument', { source: `Object.defineProperty(navigator, 'standalone', { value: true })` });
  const wait4 = loaded();
  await send('Page.navigate', { url: `${base}/` });
  await wait4;
  const installed = async (range) => {
    const fails = [];
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const waitFor = async (fn, what, ms = 5000) => {
      for (let t = 0; t < ms; t += 100) { if (fn()) return true; await sleep(100); }
      fails.push(`timed out waiting for ${what}`);
      return false;
    };
    if (!await waitFor(() => document.querySelector('#chart-card'), 'dashboard in the installed app')) return fails;
    const urls = [];
    const realFetch = window.fetch;
    window.fetch = function(url) { urls.push(String(url)); return realFetch.apply(this, arguments); };
    const touch = (type, y) => document.dispatchEvent(new TouchEvent(type, {
      bubbles: true, touches: y === undefined ? [] : [new Touch({ identifier: 1, target: document.body, clientY: y })],
    }));
    window.scrollTo(0, 0);
    touch('touchstart', 10);
    touch('touchmove', 160);
    touch('touchend');
    if (!await waitFor(() => urls.some((u) => u.includes('?api')), 'pull-to-refresh request')) return fails;
    const url = urls.find((u) => u.includes('?api'));
    if (!url.includes(`from=${range.from}`) || !url.includes(`to=${range.to}`)) fails.push(`pull-to-refresh keeps the custom date range (requested ${url})`);
    return fails;
  };
  const result3 = await send('Runtime.evaluate', { expression: `(${installed.toString()})(${JSON.stringify(range)})`, awaitPromise: true, returnByValue: true });
  if (result3.exceptionDetails) errors.push(`Installed-app steps threw: ${result3.exceptionDetails.exception?.description}`);
  failures.push(...(result3.result?.value || []));

  // 9. The chart's Pageviews | Visitors tab is remembered across a reload
  await send('Runtime.evaluate', { expression: `localStorage.removeItem('puls-from'); localStorage.removeItem('puls-to')` });
  const pickTab = async (tab) => {
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    for (let t = 0; t < 5000 && !document.querySelector('#chart-card .card-tab[data-chart]'); t += 100) await sleep(100);
    const el = document.querySelector(`#chart-card .card-tab[data-chart="${tab}"]`);
    if (!el) return 'no chart tab to click';
    el.click();
    return localStorage.getItem('puls-chart') === tab ? '' : `picking ${tab} is not stored`;
  };
  const readTab = async () => {
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    for (let t = 0; t < 5000 && !document.querySelector('#chart-card .card-tab.active[data-chart]'); t += 100) await sleep(100);
    return document.querySelector('#chart-card .card-tab.active[data-chart]')?.dataset.chart || 'none';
  };
  for (const tab of ['visitors', 'views']) {
    const picked = await send('Runtime.evaluate', { expression: `(${pickTab.toString()})(${JSON.stringify(tab)})`, awaitPromise: true, returnByValue: true });
    if (picked.result?.value) failures.push(picked.result.value);
    const wait6 = loaded();
    await send('Page.navigate', { url: `${base}/` });
    await wait6;
    const shown = await send('Runtime.evaluate', { expression: `(${readTab.toString()})()`, awaitPromise: true, returnByValue: true });
    if (shown.result?.value !== tab) failures.push(`chart opens on ${tab} after picking it (showed ${shown.result?.value})`);
  }

  // 10. The hourly card: opens on the latest day, follows a clicked day and the chart's tab
  const hourly = async () => {
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const q = (s) => document.querySelector(s);
    const waitFor = async (fn) => { for (let t = 0; t < 5000 && !fn(); t += 100) await sleep(100); return fn(); };
    const fails = [];
    // An earlier step leaves Today (one bar) picked; seven days give other days to click
    if (!await waitFor(() => q('.period-btn[data-days="7"]'))) return ['no 7 days period button'];
    q('.period-btn[data-days="7"]').click();
    if (!await waitFor(() => document.querySelectorAll('#chart-card .chart-col[data-date]').length === 7)) return ['7 days shows seven days'];
    if (!await waitFor(() => document.querySelectorAll('#hour-card .hour-chart .chart-col').length === 24)) return ['hourly card shows 24 hours'];
    const days = [...document.querySelectorAll('#chart-card .chart-col[data-date]')].map((c) => c.dataset.date);
    if (q('#chart-card .chart-col.selected')?.dataset.date !== days[days.length - 1]) fails.push('hourly card opens on the latest day');
    const pick = days[days.length - 3];
    const label = new Date(pick + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
    q(`#chart-card .chart-col[data-date="${pick}"]`).click();
    if (!await waitFor(() => q('#hour-title').textContent.endsWith(label) && document.querySelectorAll('#hour-card .hour-chart .chart-col').length === 24)) fails.push(`clicking ${pick} shows its hours (title: ${q('#hour-title').textContent})`);
    if (document.querySelectorAll('#chart-card .chart-col.selected').length !== 1 || q('#chart-card .chart-col.selected').dataset.date !== pick) fails.push('the clicked day is the one marked');
    if (q(`#chart-card .chart-col[data-date="${pick}"]`).getAttribute('aria-pressed') !== 'true') fails.push('the picked day is aria-pressed');
    // Keyboard: Enter on a focused day picks it
    const keyed = q(`#chart-card .chart-col[data-date="${days[days.length - 2]}"]`);
    keyed.focus();
    keyed.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    if (!await waitFor(() => keyed.classList.contains('selected'))) fails.push('Enter on a focused day picks it');
    q('#chart-card .card-tab[data-chart="visitors"]').click();
    if (!q('#hour-title').textContent.startsWith('Visitors by hour')) fails.push('the hourly card follows the chart tab');
    q('#chart-card .card-tab[data-chart="views"]').click();
    return fails;
  };
  const resultHours = await send('Runtime.evaluate', { expression: `(${hourly.toString()})()`, awaitPromise: true, returnByValue: true });
  if (resultHours.exceptionDetails) errors.push(`Hourly card steps threw: ${resultHours.exceptionDetails.exception?.description}`);
  failures.push(...(resultHours.result?.value || []));

  ws.close();
  failures.push(...errors);
  if (failures.length) {
    console.error(`Dashboard smoke test FAILED (${failures.length}):`);
    failures.forEach((f) => console.error('  - ' + f));
    process.exitCode = 1;
  } else {
    console.log('Dashboard smoke test passed: load, section order, islands kept across a refresh, release markers, breakdown card (count, sparkline, Show all) and its details, arranging cards (keys, drag), undo for card and goal removal, hostile event, path and site names render as text, entry page bounce, annotations (shared view, add, relabel, remove), excluding your own address in Settings, keyboard drill-down, group by version, funnel incl. unseen event, compare, 90 days, chart tab remembered, hourly card (latest day, clicked day, chart tab), Today period wording, light theme, logged-in goal for an unseen event (dimmed tile, target bar), tabs pattern, dialog focus trap, status announcements. No JS errors.');
  }
}

main()
  .catch((e) => { console.error('Smoke test error:', e.message); process.exitCode = 1; })
  .finally(cleanup);
