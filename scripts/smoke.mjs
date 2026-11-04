#!/usr/bin/env node
// Dashboard smoke test: seed a demo DB, serve it, drive headless Chrome over the
// DevTools protocol and fail on any JavaScript error or missing UI element.
//
// Usage: node scripts/smoke.mjs        (CHROME=/path/to/chrome to override)
// Needs: Node 22+ (built-in WebSocket), PHP with pdo_sqlite, Chrome or Chromium.
// No npm dependencies: this is a dev tool, Puls itself stays dependency-free.

import { spawn, execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
// Ports nothing listens on, from the system, so a server another project runs on this
// machine never answers in place of ours
const freePort = () => new Promise((resolve, reject) => {
  const srv = createServer().once('error', reject).listen(0, '127.0.0.1', () => {
    const { port } = srv.address();
    srv.close(() => resolve(port));
  });
});
const PHP_PORT = await freePort();
const CDP_PORT = await freePort();
// Seed, server and browser run in a zone other than UTC, so a time read as UTC shows up
const TIMEZONE = 'Europe/Stockholm';
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
  const seed = execFileSync('php', ['-d', `date.timezone=${TIMEZONE}`, join(root, 'scripts/seed-demo.php'), dbPath], { encoding: 'utf8' });
  const token = seed.match(/Share token: ([a-f0-9]+)/)?.[1];
  if (!token) throw new Error('Seed did not print a share token');

  // 2. Serve it
  const usersFile = join(tmp, 'users.json');
  execFileSync('php', ['-r', 'file_put_contents($argv[1], json_encode(["smoke" => ["password" => password_hash("smoke-pass", PASSWORD_BCRYPT, ["cost" => 4]), "sites" => []]]));', usersFile]);
  const env = { ...process.env, DB_PATH: dbPath, USERS_FILE: usersFile, TIMEZONE };
  // Two sites in a group and one without: the picker and the overview list them under group headers
  execFileSync('php', [join(root, 'puls'), 'sites:group', 'Shops', 'demo.example.com', 'shop.example.com'], { env });
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
  execFileSync('php', ['-d', `date.timezone=${TIMEZONE}`, '-r', `
    $db = new PDO('sqlite:' . $argv[1]);
    $now = date('Y-m-d H:i:s');
    // Campaigns as Meta's ads and Google Ads tag them, many enough to be in the top ten
    $ads = $db->prepare("INSERT INTO pageviews (site, path, visitor_hash, utm_source, utm_medium, utm_campaign, created_at) VALUES ('demo.example.com', '/', ?, ?, ?, 'smoke-ads', ?)");
    foreach ([['ig', 'paid'], ['fb', 'paid'], ['google', 'cpc']] as [$source, $medium]) {
        for ($i = 0; $i < 400; $i++) $ads->execute(["ads-{$source}-{$i}", $source, $medium, $now]);
    }
    $db->exec("INSERT INTO breakdowns (site, label, event_name, group_key, created_at) VALUES ('demo.example.com', 'Smoke card', 'cta_click', 'location', '$now')");
    // A second card, so the logged-in view can arrange them
    $db->exec("INSERT INTO breakdowns (site, label, event_name, group_key, created_at) VALUES ('demo.example.com', 'Versions', 'cta_click', '@version', '$now')");
    // More than five values, so the card offers Show all
    $more = $db->prepare("INSERT INTO events (site, event_name, page_path, event_data, visitor_hash, created_at) VALUES ('demo.example.com', 'cta_click', '/', ?, ?, '$now')");
    for ($i = 0; $i < 6; $i++) $more->execute([json_encode(['location' => 'extra' . $i]), 'extra-' . $i]);
    // Hostile names from the public endpoints must render as text, never as markup or script
    $hostile = 'x"><img src=x onerror="window.__xss=1">' . "');window.__xss=2;('";
    $ev = $db->prepare("INSERT INTO events (site, event_name, page_path, visitor_hash, created_at) VALUES ('demo.example.com', ?, '/', ?, '$now')");
    for ($i = 0; $i < 40; $i++) $ev->execute([$hostile, 'xss-' . $i]);
    $pv = $db->prepare("INSERT INTO pageviews (site, path, visitor_hash, created_at) VALUES ('demo.example.com', ?, ?, '$now')");
    for ($i = 0; $i < 400; $i++) $pv->execute(['/' . $hostile, 'xss-' . $i]);
    // A path a spreadsheet would read as a formula: the CSV export must defuse it
    for ($i = 0; $i < 450; $i++) $pv->execute(['=HYPERLINK("https://example.com","x")', 'csv-' . $i]);
    // Annotations: a range, a label that needs escaping, and one on the release day (stacked labels)
    $note = $db->prepare("INSERT INTO annotations (site, date, end_date, label, created_at) VALUES ('demo.example.com', ?, ?, ?, '$now')");
    $note->execute([date('Y-m-d', strtotime('-6 days')), date('Y-m-d', strtotime('-4 days')), 'Smoke campaign']);
    $note->execute([date('Y-m-d', strtotime('-2 days')), null, 'Say "hi" <b>bold</b>']);
    $note->execute([date('Y-m-d'), null, 'Smoke film']);
    // A version that sorts first as text but is the newest by number (9.10.0 after 9.9.2)
    $db->prepare("INSERT OR IGNORE INTO app_versions (site, version, first_seen) VALUES ('demo.example.com', '9.10.0', ?)")->execute([date('Y-m-d')]);
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
  // A Content Security Policy violation is a console.error, which fails the run below
  await send('Page.addScriptToEvaluateOnNewDocument', { source: `document.addEventListener('securitypolicyviolation', (e) => console.error('CSP violation: ' + e.violatedDirective + ' blocked ' + (e.blockedURI || 'inline') + ' at ' + (e.sourceFile || location.pathname) + ':' + e.lineNumber))` });
  await send('Emulation.setTimezoneOverride', { timezoneId: TIMEZONE });

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
    // The newest version by number, every version in its tip, one line drawn by the day itself,
    // and a tap shows the tip (hover does it with a mouse)
    const rel = q('#chart-card .chart-release'), relTip = rel?.nextElementSibling;
    expect(rel?.textContent === '9.10.0 +3', `release marker names the newest version by number (shows ${rel?.textContent})`);
    expect(relTip?.classList.contains('mark-tip') && ['9.10.0', '9.9.2', '9.9.1', '9.9.0'].every((v) => relTip.textContent.includes(v)), 'release tip lists every version');
    expect(rel && getComputedStyle(rel.closest('.chart-col'), '::before').borderLeftWidth === '1px' && getComputedStyle(rel, '::after').content === 'none', 'release line is one element');
    rel?.click();
    expect(rel?.classList.contains('open') && relTip.offsetWidth > 20, 'a tap on the release marker shows its tip');
    rel?.click();
    expect(!rel?.classList.contains('open') && relTip.offsetWidth <= 1, 'a second tap hides it');
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

    // Times are the install's, in its zone (TIMEZONE): Peak hour is 24 h, and "x hr ago" counts
    // from the server's now, not from a time read as UTC (the footer's zone, step 10)
    const peak = [...document.querySelectorAll('.summary-card')].find((c) => c.querySelector('.summary-card-label')?.textContent === 'Peak hour');
    expect(/^\d{2}:00–\d{2}:00$/.test(peak?.querySelector('.summary-card-value')?.textContent || ''), 'Peak hour in 24 h');
    const api = await (await fetch('/?api&days=7&share=' + new URLSearchParams(location.search).get('share'))).json();
    const wall = (t) => Date.parse(t.replace(' ', 'T') + 'Z');
    const ago = (s) => s < 60 ? 'just now' : s < 3600 ? Math.floor(s / 60) + ' min ago' : Math.floor(s / 3600) + ' hr ago';
    const age = (wall(api.now) - wall(api.botActivity[0].created_at)) / 1000;
    const shownAgo = q('.bot-row-time')?.textContent;
    // The dashboard rendered a little before this request, so it may show up to two minutes less
    expect([ago(age), ago(age - 120)].includes(shownAgo), `latest bot visit counts from the server's now (shows ${shownAgo}, expected ${ago(age)})`);

    // Bots and Pages to fix start folded, with a header that says what is inside; a click opens
    // one and remembers it (the refresh below keeps it open)
    const fold = (k) => q(`[data-fold="${k}"]`);
    const folded = (k) => fold(k)?.querySelector('.fold-toggle').getAttribute('aria-expanded') === 'false' && fold(k).querySelector('.fold-body').offsetParent === null;
    const foldSummary = (k) => fold(k)?.querySelector('.fold-summary').textContent;
    expect(folded('bots') && folded('broken'), 'bots and pages to fix start folded');
    expect(/^[\d,]+ visits from \d+ bots$/.test(foldSummary('bots') || ''), `folded bots say what is inside (say ${foldSummary('bots')})`);
    expect(foldSummary('broken') === '2 not found, 3 redirects in the last 30 days', `folded pages to fix say what is inside (say ${foldSummary('broken')})`);
    fold('bots')?.querySelector('.fold-toggle').click();
    expect(!folded('bots') && q('.bot-row-time')?.offsetParent && localStorage.getItem('puls-bots-open') === '1', 'a click opens the bots and remembers it');

    // Order: the chart right after the numbers, saved cards after pages and sources
    const isle = (k) => q(`#app [data-island="${k}"]`);
    const order = ['stats', 'chart', 'pages', 'traffic', 'breakdowns', 'bots'].map(isle);
    expect(order.every((el, i) => el && (!i || order[i - 1].compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING)), 'sections in order: numbers, chart, pages, sources, saved cards, bots');

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
    expect(!folded('bots') && folded('broken'), 'an opened section stays open across a refresh, the other stays folded');
    fold('bots')?.querySelector('.fold-toggle').click();
    expect(folded('bots') && localStorage.getItem('puls-bots-open') === '0', 'a second click folds it again');

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
    input.dispatchEvent(new Event('change', { bubbles: true }));
    await waitFor(() => q('.event-detail .event-funnel') && q('.event-detail')._state.then === 'never_seen_event', 'funnel to an unseen event');
    const again = q('.event-detail input.event-select');
    again.value = options[0].value;
    again.dispatchEvent(new Event('change', { bubbles: true }));
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
    // The tooltip on the first and last day stays inside the chart, its arrow on the middle of the day
    // (measured first: a tooltip sticking out to the right widens the scroll area itself)
    const content = mainScroll.getBoundingClientRect().left - mainScroll.scrollLeft, contentEnd = content + mainScroll.scrollWidth;
    for (const [edge, col] of [['first', mainScroll.querySelector('.chart-col:first-child')], ['last', mainScroll.querySelector('.chart-col:last-child')]]) {
      const tip = col?.querySelector(':scope > .tip, .chart-bar > .tip');
      if (!tip) { fails.push(`tooltip on the ${edge} day`); continue; }
      tip.style.display = 'block';
      const t = tip.getBoundingClientRect(), c = col.getBoundingClientRect();
      const arrow = t.left + parseFloat(getComputedStyle(tip, '::after').left);
      expect(t.left >= content - 1 && t.right <= contentEnd + 1 && Math.abs(arrow - (c.left + c.width / 2)) < 2, `tooltip on the ${edge} day stays inside the chart and points at its day`);
      tip.style.display = '';
    }
    // The months under the dates: each starts at its 1st, and the month in view stays at the left
    // edge (the pin), a month's own label taking over at the edge; the tooltip names the month
    // too, and the dense chart's labels carry it
    const cols = [...mainScroll.querySelectorAll('.chart-col')], pin = mainScroll.nextElementSibling;
    const dateOf = (c) => c.querySelector('.chart-date').textContent;
    const monthOf = (c) => (c.querySelector('.tip')?.textContent.match(/^[A-Z][a-z]{2}, ([A-Z][a-z]{2})/) || [])[1];
    expect(cols.every((c) => /^[A-Z][a-z]{2} \d{1,2}$/.test(dateOf(c)) && !!c.querySelector('.chart-month') === / 1$/.test(dateOf(c))), 'the dates read "Tue 3", and a month label sits on each 1st and no other day');
    expect(/^[A-Z][a-z]{2}, [A-Z][a-z]{2} \d{1,2}(, \d{4})?:/.test(cols.at(-1).querySelector('.tip')?.textContent || ''), 'the chart tooltip names the month');
    expect(mainScroll.scrollHeight <= mainScroll.clientHeight, 'the month line fits under the chart without a vertical scroll');
    // The pin shows the month of the first day in view, or steps aside for a month label at the edge
    const pinRight = () => {
      const p = pin.getBoundingClientRect(), first = cols.find((c) => c.getBoundingClientRect().right > p.left + 1);
      if (pin.hasAttribute('data-covered')) return [...mainScroll.querySelectorAll('.chart-month')].some((m) => { const r = m.getBoundingClientRect(); return r.left < p.right + 8 && r.right > p.left; });
      return getComputedStyle(pin).visibility === 'visible' && pin.textContent === monthOf(first);
    };
    expect(pin?.classList.contains('chart-pin') && pinRight(), 'the scrolled chart shows the month in view at its left edge');
    const startCol = cols.find((c) => c.querySelector('.chart-month'));
    if (startCol) {
      expect(Math.abs(pin.getBoundingClientRect().top - startCol.querySelector('.chart-month').getBoundingClientRect().top) < 1, 'the pin sits on the line of the month labels');
      // Narrowed, so the 1st can reach the left edge whatever day the test runs (it can be the last)
      mainScroll.style.width = Math.min(60, mainScroll.scrollWidth - startCol.offsetLeft) + 'px';
      mainScroll.scrollLeft = startCol.offsetLeft - 4;
      await waitFor(() => pin.hasAttribute('data-covered') && pinRight(), 'a month label at the edge takes over from the pin');
      const past = startCol.offsetLeft + startCol.offsetWidth + 8;
      if (past <= mainScroll.scrollWidth - mainScroll.clientWidth) {
        mainScroll.scrollLeft = past;
        await waitFor(() => !pin.hasAttribute('data-covered') && pin.textContent === startCol.querySelector('.chart-month').textContent && pinRight(), 'past the 1st, the pin shows the new month');
      }
      mainScroll.style.width = '';
    }
    mainScroll.scrollLeft = 0;
    await waitFor(() => pinRight() && pin.textContent === pin.dataset.first, 'at the start, the edge shows the first month');
    const denseDates = [...document.querySelectorAll('.event-detail .event-chart.dense .chart-date')].map((d) => d.textContent.trim()).filter(Boolean);
    expect(denseDates.length && denseDates.every((t) => /^[A-Z][a-z]{2} \d{1,2}$/.test(t)), 'the dense chart labels its dates with the month');
    expect(!q('.event-detail .event-chart.dense + .chart-pin'), 'the dense chart has no pin');
    // The picked day's ring fits at the scroll end too: the chart keeps its padding there
    q('#chart-card .chart-scroll').scrollLeft = 1e6;
    q('#chart-card .chart-col:last-child').click();
    if (await waitFor(() => q('#chart-card .chart-col.selected'), 'the last day picked in a scrolled chart')) {
      const edge = q('#chart-card .chart-scroll').getBoundingClientRect().right, bar = q('#chart-card .chart-col.selected .chart-bar').getBoundingClientRect().right;
      expect(edge - bar >= 4, `the picked last day's ring is not cut off by the scroll edge (${Math.round(edge - bar)} px of room)`);
      q('#chart-card .chart-col.selected').click();
    }
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

    // The date range button opens its picker from its icon, which is what a click lands on, and a
    // click outside closes it again
    q('#custom-range-btn svg path').dispatchEvent(new MouseEvent('click', { bubbles: true }));
    expect(q('#date-picker').classList.contains('open'), 'a click on the date range icon opens the picker');
    q('#app').dispatchEvent(new MouseEvent('click', { bubbles: true }));
    expect(!q('#date-picker').classList.contains('open'), 'a click outside closes the date picker');

    // The 1-day period starts at midnight, so it reads as today, not the last 24 hours
    const todayBtn = q('.period-btn[data-days="1"]');
    expect(todayBtn.textContent === 'Today', 'the 1-day period button reads Today');
    q('#sr-status').textContent = '';
    todayBtn.click();
    if (await waitFor(() => /, today:/.test(q('#sr-status').textContent), 'screen reader status says today')) {
      const sub = q('#chart-card .card-subtitle')?.textContent || '';
      expect(/today/.test(sub) && !/last 1 days/.test(sub), 'chart subtitle says today');
    }

    // A slow answer for an earlier choice never replaces the view chosen after it
    const realFetch = window.fetch, mainApi = (url, days) => new RegExp('\\?api&days=' + days + '(&|$)').test(String(url));
    let slowDone = false;
    window.fetch = (url, ...rest) => mainApi(url, 90)
      ? sleep(800).then(() => realFetch(url, ...rest)).then((r) => { slowDone = true; return r; })
      : realFetch(url, ...rest);
    q('.period-btn[data-days="90"]').click();
    q('.period-btn[data-days="7"]').click();
    await waitFor(() => slowDone, 'the slow 90-day answer arrives');
    await sleep(300);
    window.fetch = realFetch;
    expect(q('#chart-card .chart').children.length <= 7, 'a slow answer for an earlier period does not replace the later one');

    // A load the viewer asked for shows that it is under way: busy at once, the page dimmed and a
    // line that says what is loading after a second, all gone when the answer is in
    window.fetch = (url, ...rest) => mainApi(url, 30) ? sleep(1500).then(() => realFetch(url, ...rest)) : realFetch(url, ...rest);
    q('.period-btn[data-days="30"]').click();
    expect(q('#app').getAttribute('aria-busy') === 'true' && document.body.hasAttribute('data-loading') && q('#load-status').hidden, 'a load marks the page busy at once, with no line yet');
    if (await waitFor(() => !q('#load-status').hidden, 'after a second, a line says what is loading')) {
      expect(q('#load-status').textContent === 'Loading the last 30 days…', `the line names the period (got ${q('#load-status').textContent})`);
      expect(+getComputedStyle(q('#app')).opacity < 0.6, 'the page dims while it loads');
    }
    await waitFor(() => q('#app').getAttribute('aria-busy') === 'false' && !document.body.hasAttribute('data-loading') && q('#load-status').hidden, 'the busy state clears when the answer is in');
    window.fetch = realFetch;

    // A range that ends before today cannot change, so the 60 s refresh leaves it alone; a period
    // that ends today is still refreshed. The clock is moved two minutes on to reach the refresh
    const realNow = Date.now, dayAgo = (n) => new Date(realNow() - n * 86400000).toISOString().slice(0, 10);
    let apiCalls = 0;
    window.fetch = (url, ...rest) => { if (/\?api&(days|from)=/.test(String(url))) apiCalls++; return realFetch(url, ...rest); };
    q('#date-from').value = dayAgo(20);
    q('#date-to').value = dayAgo(10);
    q('.date-picker-apply').click();
    await waitFor(() => q('#chart-card .chart')?.children.length === 11 && q('#app').getAttribute('aria-busy') === 'false', 'a past range loads');
    apiCalls = 0;
    Date.now = () => realNow() + 120000;
    await sleep(5500);
    expect(apiCalls === 0, 'a range that ends before today is not refreshed');
    Date.now = realNow;
    q('.period-btn[data-days="7"]').click();
    await waitFor(() => q('#chart-card .chart')?.children.length <= 7 && q('#app').getAttribute('aria-busy') === 'false', 'back to 7 days');
    apiCalls = 0;
    Date.now = () => realNow() + 120000;
    await waitFor(() => apiCalls > 0, 'a period that ends today is still refreshed', 7000);
    Date.now = realNow;
    window.fetch = realFetch;

    // A custom range is told to screen readers like a period, its dates as words
    {
      const ago = (n) => new Date(Date.now() - n * 86400000).toISOString().slice(0, 10);
      const words = (d) => new Date(d + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
      const [from, to] = [ago(20), ago(10)];
      q('#sr-status').textContent = '';
      q('#date-from').value = from;
      q('#date-to').value = to;
      q('.date-picker-apply').click();
      await waitFor(() => q('#sr-status').textContent.includes(`, ${words(from)} to ${words(to)}:`), 'screen reader status announces a custom range');
      q('#sr-status').textContent = '';
      q('.period-btn[data-days="7"]').click();
      await waitFor(() => /last 7 days/.test(q('#sr-status').textContent), 'back to 7 days after the custom range');
    }

    // A campaign's source reads in words, the title keeps what was sent
    {
      const row = (sent) => [...document.querySelectorAll('.trow-label')].find((l) => l.title === sent);
      expect(row('ig / paid — smoke-ads')?.textContent === 'Instagram / paid — smoke-ads', "Meta's ig reads Instagram");
      expect(row('fb / paid — smoke-ads')?.textContent === 'Facebook / paid — smoke-ads', "Meta's fb reads Facebook");
      expect(row('google / cpc — smoke-ads')?.textContent === 'Google Ads — smoke-ads', 'google with cpc reads Google Ads');
    }

    // A day opens its hours under the chart, on demand
    {
      const hoursAsked = [], realFetch = window.fetch;
      window.fetch = (url, ...rest) => { if (/[?&]hours=/.test(String(url))) hoursAsked.push(String(url)); return realFetch(url, ...rest); };
      const col = (date) => q(`#chart-card .chart-col[data-date="${date}"]`), picked = () => q('#chart-card .chart-col.selected');
      const refresh = async (what) => { q('#sr-status').textContent = ''; q('.period-btn.active').click(); await waitFor(() => /Showing/.test(q('#sr-status').textContent), what); };
      const dates = [...document.querySelectorAll('#chart-card .chart-col[data-date]')].map((c) => c.dataset.date);
      const [day, latest] = dates.slice(-2);
      expect(!q('#hours-card') && !picked() && !hoursAsked.length, 'the hours are closed on load, no day picked, nothing asked for');
      expect(document.querySelectorAll('#chart-card .chart-col[tabindex="0"]').length === 1 && col(latest).tabIndex === 0, 'the chart is one tab stop, on the latest day');
      expect(/Click to see by hour/.test(col(day).querySelector('.tip').textContent), "a day's tooltip says a click shows its hours");

      col(day).click();
      if (await waitFor(() => q('#hours-card .hours-chart'), 'the hours of a clicked day')) {
        expect(hoursAsked.length === 1 && hoursAsked[0].includes(`hours=${day}`), 'one request, for the clicked day');
        expect(q('#hours-card .hours-chart').children.length === 24, 'the card has 24 hours');
        expect(picked()?.dataset.date === day && picked().getAttribute('aria-pressed') === 'true' && /Click to close/.test(picked().querySelector('.tip').textContent), 'the picked day is marked, and its tooltip says a click closes');
        expect(localStorage.getItem('puls-hours') === '1', 'an open card is remembered');
        expect(/^By hour \u00b7 .* views$/.test(q('#hours-card .card-subtitle').textContent), `the card counts what the chart tab shows (says "${q('#hours-card .card-subtitle').textContent}")`);
        expect(getComputedStyle(q('#chart-card')).marginBottom === '0px' && getComputedStyle(q('#hours-card')).borderTopStyle === 'none', 'the open card hangs from the chart');
      }
      // A refresh that brings the same hours leaves the card as it is
      const card = q('#hours-card');
      await refresh('a refresh with the card open');
      await waitFor(() => hoursAsked.length === 2, 'the hours asked for again on a refresh');
      await sleep(300);
      expect(q('#hours-card') === card, 'a refresh with the same hours leaves the card alone');
      // The tab switches the card too, and the chart (and focus on its tab) stays as it is
      const tab = q('#chart-card .card-tab[data-chart="visitors"]');
      tab.focus();
      tab.click();
      expect(/visitors$/.test(q('#hours-card .card-subtitle')?.textContent || ''), 'the card follows the Visitors tab');
      expect(document.activeElement === tab && q('#chart-card .card-tab[data-chart="visitors"]') === tab, 'switching the tab with the card open keeps the chart and its focus');
      q('#chart-card .card-tab[data-chart="views"]').click();
      // Another day switches, the picked day closes
      col(latest).click();
      await waitFor(() => picked()?.dataset.date === latest && hoursAsked.at(-1).includes(`hours=${latest}`) && q('#hours-card .hours-chart'), 'another day switches the card');
      col(latest).click();
      expect(!q('#hours-card') && !picked() && localStorage.getItem('puls-hours') === '0', 'a click on the picked day closes the card, and closed is remembered');
      // The keyboard: the arrows move between days, Enter opens, the card's x closes and focus goes back to the day
      col(latest).focus();
      col(latest).dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
      expect(document.activeElement === col(day) && col(day).tabIndex === 0 && col(latest).tabIndex === -1, 'the left arrow moves to the day before');
      col(day).dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
      await waitFor(() => picked()?.dataset.date === day && q('#hours-card .hours-chart'), 'Enter opens the hours of the focused day');
      q('#hours-card [data-action="close-hours"]').click();
      expect(!q('#hours-card') && document.activeElement === col(day), "the card's x closes it and focus goes back to the day");
      // Closed, nothing is asked for
      const asked = hoursAsked.length;
      await refresh('a refresh with the card closed');
      await sleep(300);
      expect(hoursAsked.length === asked, 'no request for hours while the card is closed');
      window.fetch = realFetch;
    }

    // Light theme renders too
    document.documentElement.setAttribute('data-theme', 'light');
    expect(getComputedStyle(document.body).backgroundColor !== '', 'light theme applies');
    // The shared footer: name, version and freshness, then the Webready credit with the family's marks
    const foot = q('#app .foot');
    expect(foot && /^Puls \d+\.\d+\.\d+ · from the tracking script, updated \d\d:\d\d$/.test(foot.firstElementChild.textContent), 'footer names Puls, its version and when it was updated (no zone: the viewer shares it)');
    expect(foot && foot.querySelector('a[href="https://webready.se"]') && foot.querySelectorAll('.foot-marks svg').length === 2 && foot.querySelector('.foot-marks a[href="https://github.com/webready-se/puls"]') && foot.querySelector('.foot-marks a[href="https://github.com/webready-se/vaka"]'), 'footer credit links Webready, with only the public tools\' marks (today Puls and Vaka), each linked');
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
  const failures = [...(Array.isArray(result.result?.value) ? result.result.value : [])];

  // 6. Logged in: All sites first, then the demo site, where a goal can be added for an event
  // that has not happened yet
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
    const expect = (cond, what) => { if (!cond) fails.push(what); };
    // With several sites the dashboard opens on All sites: the picker and the overview list
    // each one, and a row in the overview opens that site
    const seeded = ['blog.example.com', 'demo.example.com', 'shop.example.com'];
    if (!await waitFor(() => q('.site-overview-row'), 'site overview on All sites')) return fails;
    // The site picker: a button with the sites in a list under it, opened on the site on screen,
    // without a filter for three sites; Escape closes it back to the button
    const siteBtn = q('#site-btn');
    const pickerKey = (k) => document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true }));
    siteBtn?.click();
    const picker = [...document.querySelectorAll('#site-list [data-site]')].map((el) => el.getAttribute('data-site'));
    const pickerGroups = [...document.querySelectorAll('#site-list .cmd-group')].map((el) => el.textContent);
    expect(siteBtn?.offsetParent && siteBtn.getAttribute('aria-expanded') === 'true' && picker.join() === ',demo.example.com,shop.example.com,blog.example.com' && q('#site-filter').hidden, `site picker lists All sites, then the sites under their group, without a filter for three (lists ${picker.join(', ')})`);
    expect(pickerGroups.join() === 'Shops,Other sites' && q('#site-list [role="group"]')?.getAttribute('aria-labelledby') === q('#site-list .cmd-group').id, `the picker's groups are headed and named for screen readers (has ${pickerGroups.join(', ')})`);
    expect(document.activeElement === q('#site-list') && q('#site-list').getAttribute('aria-activedescendant') === q('#site-list [data-site=""]')?.id, 'the picker opens on the site on screen, its list focused');
    expect(siteBtn.textContent.startsWith('Site: All sites'), `the picker button tells a screen reader it is the site (says ${siteBtn.textContent})`);
    q('#site-list [data-site="blog.example.com"]').dispatchEvent(new MouseEvent('mousemove', { bubbles: true }));
    expect(q('#site-list .cmd-item.active')?.getAttribute('data-site') === 'blog.example.com' && document.querySelectorAll('#site-list .cmd-item.active').length === 1, 'the mouse moves the one highlight');
    pickerKey('End');
    expect(q('#site-list .cmd-item.active')?.getAttribute('data-site') === 'blog.example.com', 'End moves to the last site');
    pickerKey('Home');
    expect(q('#site-list .cmd-item.active')?.getAttribute('data-site') === '', 'Home moves to All sites');
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true, bubbles: true }));
    expect(q('#site-menu').hidden && q('#cmd-overlay.open'), 'CMD+K closes the picker as the palette opens');
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    siteBtn.click();
    pickerKey('Escape');
    expect(q('#site-menu').hidden && document.activeElement === siteBtn && siteBtn.getAttribute('aria-expanded') === 'false', 'Escape closes the picker and returns to its button');
    siteBtn?.click();
    document.body.click();
    expect(q('#site-menu').hidden, 'a click outside closes the picker');
    const rows = [...document.querySelectorAll('.site-overview-row')];
    const rowNames = rows.map((r) => r.querySelector('.site-overview-name').firstChild.textContent);
    expect(seeded.every((s) => rowNames.includes(s)), `site overview lists every site (lists ${rowNames.join(', ')})`);
    const groupHeads = [...document.querySelectorAll('.site-overview-group')].map((g) => g.querySelector('.site-overview-group-name').firstChild.textContent.trim());
    expect(groupHeads.join() === 'Shops,Other sites' && rowNames.join() === 'demo.example.com,shop.example.com,blog.example.com', `the overview lists sites under their group, the ones without one last (has ${groupHeads.join(', ')}; rows ${rowNames.join(', ')})`);
    const firstStat = (el) => parseInt(el.querySelector('.site-overview-stat').textContent.replace(/\D/g, ''), 10);
    const shopsVisitors = rows.filter((r) => r.classList.contains('in-group') && ['demo.example.com', 'shop.example.com'].includes(r.getAttribute('data-site'))).reduce((n, r) => n + firstStat(r), 0);
    expect(firstStat(q('.site-overview-group')) === shopsVisitors && q('.site-overview-group .site-overview-trend'), `a group's header sums its sites' visitors and shows a trend (${firstStat(q('.site-overview-group'))} vs ${shopsVisitors})`);
    expect(!q('.site-overview-group:last-of-type .site-overview-stat'), 'the Other sites header carries its count only, no sum');
    // A click on a group's header folds it to the header, remembered per viewer; a second click opens it
    const shopsHead = q('.site-overview-group');
    const shopsBody = () => q('#' + shopsHead.getAttribute('aria-controls'));
    const foldedGroups = () => JSON.parse(localStorage.getItem('puls-site-groups-folded') || '[]');
    expect(shopsHead?.tagName === 'BUTTON' && shopsHead.getAttribute('aria-expanded') === 'true' && shopsBody() && !shopsBody().hidden, 'a group header is a button, open at first');
    shopsHead?.click();
    expect(shopsHead?.getAttribute('aria-expanded') === 'false' && shopsBody()?.hidden && foldedGroups().includes('Shops') && shopsHead.querySelector('.site-overview-stat'), 'a click folds the group to its header, keeping its sums, and remembers it');
    shopsHead?.click();
    expect(shopsHead?.getAttribute('aria-expanded') === 'true' && !shopsBody()?.hidden && !foldedGroups().includes('Shops'), 'a second click opens the group again');
    const demoRow = rows.find((r) => r.querySelector('.site-overview-name').firstChild.textContent === 'demo.example.com');
    expect(demoRow?.querySelector('.site-overview-live'), 'site overview shows who is online on a site');
    demoRow?.click();
    if (!await waitFor(() => q('#chart-card .card-subtitle')?.textContent.startsWith('demo.example.com') && !q('.site-overview-row') && q('.breakdown-card'), 'demo site opened from the site overview')) return fails;
    expect(localStorage.getItem('puls-site') === 'demo.example.com', 'the site picked in the overview is remembered');
    // The picker by keyboard: ArrowDown on the button opens it on the demo site, typing the start
    // of a name moves to it, the arrow keys move on, Enter picks, and the button names it
    siteBtn.focus();
    pickerKey('ArrowDown');
    const pickerActive = () => q('#site-list .cmd-item.active')?.getAttribute('data-site');
    expect(pickerActive() === 'demo.example.com' && q('#site-list [data-site="demo.example.com"] .cmd-item-current'), 'the picker opens on the demo site, marked current');
    pickerKey('b');
    expect(pickerActive() === 'blog.example.com', `typing the start of a name moves to it (on ${pickerActive()})`);
    await sleep(700);
    pickerKey('a');
    expect(pickerActive() === '', `a moves to All sites (on ${pickerActive()})`);
    await sleep(700);
    pickerKey('d');
    pickerKey('ArrowDown');
    pickerKey('Enter');
    if (!await waitFor(() => q('#chart-card .card-subtitle')?.textContent.startsWith('shop.example.com') && q('#site-btn-label').textContent === 'shop.example.com' && document.activeElement === siteBtn, 'ArrowDown and Enter in the picker open the next site')) return fails;
    selectSite('demo.example.com');
    if (!await waitFor(() => q('#chart-card .card-subtitle')?.textContent.startsWith('demo.example.com') && q('#site-btn-label').textContent === 'demo.example.com' && q('.breakdown-card'), 'back on the demo site')) return fails;
    // Tools fold like bots and pages to fix, behind a button a keyboard reaches
    const tools = q('[data-fold="tools"] .fold-toggle');
    expect(tools?.tagName === 'BUTTON' && tools.getAttribute('aria-expanded') === 'false' && q('#snippet-code')?.offsetParent === null, 'tools start folded behind a button');
    tools?.click();
    expect(q('#snippet-code')?.offsetParent && localStorage.getItem('puls-tools-open') === '1', 'a click on Tools shows the tracking snippet');
    tools?.click();

    // CMD+K lists the sites before anything is typed; typing filters them, a query that starts
    // with / searches pages only, and Enter on a site opens it
    const key = (k, metaKey = false) => document.dispatchEvent(new KeyboardEvent('keydown', { key: k, metaKey, bubbles: true }));
    const typeCmd = (text) => { const input = q('#cmd-input'); input.value = text; input.dispatchEvent(new Event('input')); };
    const cmdSiteNames = () => [...document.querySelectorAll('#cmd-results [data-site]')].map((el) => el.getAttribute('data-site'));
    key('k', true);
    expect(q('#cmd-overlay.open') && cmdSiteNames().join() === ['', ...seeded].join() && q('#cmd-input').getAttribute('aria-expanded') === 'true', `CMD+K lists All sites and each site before anything is typed (lists ${cmdSiteNames().join(', ')})`);
    expect(q('#cmd-results [data-site="demo.example.com"] .cmd-item-current'), 'the site on screen is marked current');
    expect(!q('#cmd-input').hasAttribute('aria-activedescendant') && q('#cmd-hint').textContent.includes('/ pages only'), 'nothing is highlighted before anything is typed, and the hint says / searches pages only');
    key('ArrowDown');
    expect(q('#cmd-input').getAttribute('aria-activedescendant') === q('#cmd-results [data-site=""]')?.id, 'the arrow keys move the highlight, and screen readers are told');
    typeCmd('/pri');
    expect(!cmdSiteNames().length, 'a query that starts with / leaves the sites out');
    if (await waitFor(() => q('#cmd-group-pages'), 'page results in the palette')) {
      expect(q('#cmd-results [data-path="/pricing"]'), 'the page search finds /pricing');
    }
    // "s" is in shop.example.com and in All sites, and in the group Shops, which lists demo too;
    // the name that starts with it comes first
    typeCmd('s');
    expect(cmdSiteNames().join() === 'shop.example.com,,demo.example.com', `typing filters the sites by name and group, names that start with it first (shows ${cmdSiteNames().join(', ')})`);
    expect(q('#cmd-results [data-site="demo.example.com"] .cmd-item-group')?.textContent === 'Shops', 'a site in a group is named with its group in the palette');
    expect(q('#cmd-input').getAttribute('aria-activedescendant') === q('#cmd-results [data-site="shop.example.com"]')?.id, 'typing highlights the first result, so Enter opens it');
    key('Enter');
    if (!await waitFor(() => !q('#cmd-overlay.open') && q('#chart-card .card-subtitle')?.textContent.startsWith('shop.example.com'), 'Enter opens the site from the palette')) return fails;
    selectSite('demo.example.com');
    if (!await waitFor(() => q('#chart-card .card-subtitle')?.textContent.startsWith('demo.example.com') && q('.breakdown-card'), 'back on the demo site')) return fails;

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
    await waitFor(() => [...document.querySelectorAll('#chart-card .chart-note + .mark-tip')].some((t) => /Smoke note/.test(t.textContent)), 'new note on the chart', 8000);
    window.openMenu();
    const item = [...document.querySelectorAll('#note-list .note-item')].find((i) => i.querySelector('input').value === 'Smoke note');
    if (!item) { fails.push('note listed in Settings'); return fails; }
    const field = item.querySelector('input');
    field.value = 'Smoke note, clarified';
    field.dispatchEvent(new Event('change', { bubbles: true }));
    await waitFor(() => [...document.querySelectorAll('#chart-card .chart-note + .mark-tip')].some((t) => /Smoke note, clarified/.test(t.textContent)), 'relabelled note on the chart', 8000);
    item.querySelector('button').click();
    await waitFor(() => ![...document.querySelectorAll('#chart-card .chart-note + .mark-tip')].some((t) => /Smoke note/.test(t.textContent)), 'removed note leaves the chart', 8000);
    window.closeMenu();
    q('.note-toggle').click();
    q('.note-form-hint button').click();
    if (!q('#note-form').hidden || !q('#menu-panel').classList.contains('open')) fails.push('note form hint closes the form and opens Settings');
    if (q('#notes-body').hidden || q('[aria-controls="notes-body"]').getAttribute('aria-expanded') !== 'true' || !q('#goals-body').hidden) fails.push('the note form hint opens Settings on Chart notes alone');
    window.closeMenu();

    // Settings opens as an overview: notes, excluded IPs and goals folded to a summary each
    window.openMenu();
    const menuFolds = ['notes', 'excluded', 'goals'];
    if (!menuFolds.every((k) => q(`#${k}-body`).hidden && q(`[aria-controls="${k}-body"]`).getAttribute('aria-expanded') === 'false')) fails.push('Settings opens with notes, excluded IPs and goals folded');
    const menuSummary = (k) => q(`#${k}-summary`).textContent;
    if (!/^(None in this period|[\d,]+ notes?)$/.test(menuSummary('notes')) || !/^(No goals yet|[\d,]+ goals?)$/.test(menuSummary('goals'))) fails.push(`folded notes and goals say how many (say ${menuSummary('notes')}, ${menuSummary('goals')})`);
    if ((menuSummary('notes') === 'None in this period') === q('#notes-empty').hidden) fails.push('Chart notes says where to add one exactly when there are none');
    await waitFor(() => /^Your IP is counted$/.test(menuSummary('excluded')), `folded excluded IPs say your IP is counted (say ${menuSummary('excluded')})`);
    q('[aria-controls="excluded-body"]').click();
    if (q('#excluded-body').hidden || !q('#excluded-mine').offsetParent) fails.push('a click on Excluded IPs opens it');

    // Excluded IPs: Settings shows your own address, Exclude it lists it, × counts it again
    if (await waitFor(() => q('#excluded-mine code') && q('#excluded-mine button'), 'your own address in Settings')) {
      const mine = q('#excluded-mine code').textContent;
      const row = () => [...document.querySelectorAll('#excluded-list .excluded-item')].find((i) => i.getAttribute('data-ip') === mine);
      q('#excluded-mine button').click();
      if (await waitFor(() => row() && /is not counted/.test(q('#excluded-mine').textContent), 'own address excluded and listed', 8000)) {
        if (!/^1 excluded · your IP is not counted$/.test(menuSummary('excluded'))) fails.push(`the summary follows an exclusion (says ${menuSummary('excluded')})`);
        row().querySelector('button').click();
        await waitFor(() => !row() && q('#excluded-mine button'), 'own address counted again', 8000);
      }
    }
    window.closeMenu();
    window.openMenu();
    if (!q('#excluded-body').hidden) fails.push('Settings folds again the next time it opens');
    window.closeMenu();
    return fails;
  };
  const result2 = await send('Runtime.evaluate', { expression: `(${loggedIn.toString()})()`, awaitPromise: true, returnByValue: true });
  if (result2.exceptionDetails) errors.push(`Logged-in steps threw: ${result2.exceptionDetails.exception?.description}`);
  failures.push(...(Array.isArray(result2.result?.value) ? result2.result.value : []));

  // 7. A hostile site name already in the database renders as text in the chart and the site overview
  const hostileSite = 'x"><img src=x onerror="window.__xss=1">' + "');window.__xss=2;('";
  execFileSync('php', ['-d', `date.timezone=${TIMEZONE}`, '-r', `
    $db = new PDO('sqlite:' . $argv[1]);
    $now = date('Y-m-d H:i:s');
    $db->prepare("INSERT INTO pageviews (site, path, visitor_hash, created_at) VALUES (?, '/', 'xss-site', '$now')")->execute([$argv[2]]);
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
    selectSite('');
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
  failures.push(...(Array.isArray(result4.result?.value) ? result4.result.value : []));

  // 8. At phone width (the hostile steps left the page on All sites) a group header keeps its
  // name and numbers on one line, and the page does not scroll sideways. The viewport is
  // emulated over the protocol: no window changes size
  await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
  const phoneSteps = async () => {
    const fails = [];
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const q = (s) => document.querySelector(s);
    const waitFor = async (fn, what, ms = 5000) => {
      for (let t = 0; t < ms; t += 100) { if (fn()) return true; await sleep(100); }
      fails.push(`timed out waiting for ${what}`);
      return false;
    };
    if (!await waitFor(() => window.innerWidth <= 400 && q('.site-overview-group .site-overview-trend'), 'the grouped overview at phone width')) return fails;
    await sleep(300);
    const head = q('.site-overview-group');
    const name = head.querySelector('.site-overview-group-name').getBoundingClientRect();
    const trend = head.querySelector('.site-overview-trend').getBoundingClientRect();
    if (Math.abs(name.top - trend.top) > 4 || name.right > trend.left) fails.push(`a group header keeps its name and numbers on one line at phone width (name ${Math.round(name.left)}-${Math.round(name.right)}, trend at ${Math.round(trend.left)}, tops ${Math.round(name.top)} and ${Math.round(trend.top)})`);
    if (document.documentElement.scrollWidth > window.innerWidth) fails.push(`the dashboard scrolls sideways at phone width (${document.documentElement.scrollWidth} > ${window.innerWidth})`);
    return fails;
  };
  const result5 = await send('Runtime.evaluate', { expression: `(${phoneSteps.toString()})()`, awaitPromise: true, returnByValue: true });
  if (result5.exceptionDetails) errors.push(`Phone-width steps threw: ${result5.exceptionDetails.exception?.description}`);
  failures.push(...(Array.isArray(result5.result?.value) ? result5.result.value : []));
  await send('Emulation.clearDeviceMetricsOverride');

  // 8. Installed app (PWA) with a custom date range: pull-to-refresh reloads that same range
  const day = (ago) => new Date(Date.now() - ago * 86400000).toISOString().slice(0, 10);
  const range = { from: day(20), to: day(10) };
  await send('Runtime.evaluate', { expression: `localStorage.setItem('puls-from', '${range.from}'); localStorage.setItem('puls-to', '${range.to}')` });
  await send('Page.addScriptToEvaluateOnNewDocument', { source: `Object.defineProperty(navigator, 'standalone', { value: true })` });
  // The page's first answer for the range comes late, so its first load can be seen
  await send('Page.addScriptToEvaluateOnNewDocument', { source: `(() => { const f = window.fetch; let slow = true; window.fetch = function(url) { if (slow && /[?]api&from=/.test(String(url))) { slow = false; return new Promise((r) => setTimeout(r, 1500)).then(() => f.apply(this, arguments)); } return f.apply(this, arguments); }; })()` });
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
    // The first load is the page loader too: the bar, and the line naming the range after 300 ms
    // (no spinner); nothing of it is left once the dashboard is in
    const status = document.getElementById('load-status');
    const label = [range.from, range.to].map((d) => new Date(d + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' })).join(' \u2013 ');
    if (await waitFor(() => document.body.hasAttribute('data-first-load') && !status.hidden && getComputedStyle(status).opacity === '1', 'the line on the first load')) {
      if (status.textContent !== `Loading ${label}\u2026`) fails.push(`the first load's line names the range (got ${status.textContent})`);
      if (!document.body.hasAttribute('data-loading') || document.getElementById('app').getAttribute('aria-busy') !== 'true' || document.querySelector('.loading-spinner')) fails.push('the first load shows the page loader and no spinner');
    }
    if (!await waitFor(() => document.querySelector('#chart-card'), 'dashboard in the installed app')) return fails;
    if (!await waitFor(() => !document.body.hasAttribute('data-loading') && !document.body.hasAttribute('data-first-load') && status.hidden && document.getElementById('app').getAttribute('aria-busy') === 'false', 'no loading state left after the first load')) return fails;
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
    if (document.body.hasAttribute('data-loading')) fails.push('pull-to-refresh shows its own spinner, not the page loader as well');
    const url = urls.find((u) => u.includes('?api'));
    if (!url.includes(`from=${range.from}`) || !url.includes(`to=${range.to}`)) fails.push(`pull-to-refresh keeps the custom date range (requested ${url})`);
    return fails;
  };
  const result3 = await send('Runtime.evaluate', { expression: `(${installed.toString()})(${JSON.stringify(range)})`, awaitPromise: true, returnByValue: true });
  if (result3.exceptionDetails) errors.push(`Installed-app steps threw: ${result3.exceptionDetails.exception?.description}`);
  failures.push(...(Array.isArray(result3.result?.value) ? result3.result.value : []));

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

  // 9b. An open hours card is remembered across a reload, and opens on the latest day
  await send('Runtime.evaluate', { expression: `localStorage.setItem('puls-hours', '1')` });
  const wait6b = loaded();
  await send('Page.navigate', { url: `${base}/` });
  await wait6b;
  const hoursOnLoad = async () => {
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    for (let t = 0; t < 5000 && !document.querySelector('#hours-card .hours-chart'); t += 100) await sleep(100);
    const days = [...document.querySelectorAll('#chart-card .chart-col[data-date]')];
    const picked = document.querySelector('#chart-card .chart-col.selected');
    localStorage.removeItem('puls-hours');
    if (!document.querySelector('#hours-card .hours-chart')) return 'no hours card after a reload with it open';
    return picked && picked === days.at(-1) ? '' : `the remembered card opens on the latest day (picked ${picked?.dataset.date})`;
  };
  const hoursShown = await send('Runtime.evaluate', { expression: `(${hoursOnLoad.toString()})()`, awaitPromise: true, returnByValue: true });
  if (hoursShown.result?.value) failures.push(hoursShown.result.value);

  // 10. A viewer in another time zone: the footer names the install's, in plain words, and so
  // does an open hours card
  await send('Emulation.setTimezoneOverride', { timezoneId: 'America/New_York' });
  await send('Runtime.evaluate', { expression: `localStorage.setItem('puls-hours', '1')` });
  const wait7 = loaded();
  await send('Page.navigate', { url: `${base}/` });
  await wait7;
  const zoneFoot = async () => {
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    for (let t = 0; t < 5000 && !document.querySelector('#app .foot'); t += 100) await sleep(100);
    return document.querySelector('#app .foot')?.firstElementChild.textContent || '';
  };
  const footText = (await send('Runtime.evaluate', { expression: `(${zoneFoot.toString()})()`, awaitPromise: true, returnByValue: true })).result?.value || '';
  if (!/updated \d\d:\d\d, Stockholm time$/.test(footText)) failures.push(`footer names the install's zone for a viewer elsewhere (shows "${footText}")`);
  const zoneHours = async () => {
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    for (let t = 0; t < 5000 && !document.querySelector('#hours-card .hours-chart'); t += 100) await sleep(100);
    localStorage.removeItem('puls-hours');
    return document.querySelector('#hours-card .card-subtitle')?.textContent || '';
  };
  const hoursSub = (await send('Runtime.evaluate', { expression: `(${zoneHours.toString()})()`, awaitPromise: true, returnByValue: true })).result?.value || '';
  if (!/^By hour, Stockholm time \u00b7 /.test(hoursSub)) failures.push(`the hours card names the install's zone for a viewer elsewhere (shows "${hoursSub}")`);
  await send('Emulation.setTimezoneOverride', { timezoneId: TIMEZONE });

  // 11. The tracking script on a page: sendBeacon is recorded instead of sent, so what the script
  // would send is checked without a row in the database. puls.page() counts the current URL or a
  // path through the same rules as a pageview (data-query, data-collapse-ids), once per URL, never
  // another site's; calls queued before the script loaded are sent in order
  const trackerSteps = async () => {
    const fails = [];
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const sent = [];
    Object.defineProperty(navigator, 'webdriver', { get: () => false });
    navigator.sendBeacon = (url, body) => { sent.push({ url: String(url), body: JSON.parse(body) }); return true; };
    const pages = () => sent.filter((b) => b.url.endsWith('?collect')).map((b) => b.body.u);
    window.puls = { q: [], track: function () { this.q.push(arguments); }, page: function () { this.q.push({ page: arguments }); } };
    window.puls.page('/queued/7');
    window.puls.track('queued_event', { a: 1 });
    const el = document.createElement('script');
    el.src = '/?js';
    el.dataset.site = 'smoke-tracker';
    el.dataset.query = 'page';
    el.dataset.collapseIds = '';
    document.head.appendChild(el);
    for (let t = 0; t < 5000 && pages().length < 2; t += 100) await sleep(100);
    // The pageview on load runs through requestIdleCallback, which passes an object: it must count as "/"
    if (pages().join() !== '/queued/:id,/') fails.push(`a queued puls.page() is sent before the pageview on load, through the id rule (sent ${pages().join(', ')})`);
    const ev = sent.find((b) => b.url.endsWith('?event'));
    if (ev?.body.event_name !== 'queued_event' || sent.indexOf(ev) !== 1) fails.push('a queued puls.track() is still sent, in its place in the queue');
    if (!sent.every((b) => b.body.site === 'smoke-tracker')) fails.push('every beacon names the site from data-site');
    history.replaceState(null, '', '/?page=2&q=secret');
    window.puls.page();
    window.puls.page();
    window.puls.page('/products/42?page=3&ref=x');
    window.puls.page('https://other.example/elsewhere');
    window.puls.page(location.origin + '/full/9');
    history.replaceState(null, '', '/');
    const after = pages().slice(2);
    if (after.join() !== '/?page=2,/products/:id?page=3,/full/:id') fails.push(`puls.page() counts the current URL once, a path and a full URL on this site through data-query and the id rule, and nothing for another site (sent ${after.join(', ')})`);
    return fails;
  };
  const result6 = await send('Runtime.evaluate', { expression: `(${trackerSteps.toString()})()`, awaitPromise: true, returnByValue: true });
  if (result6.exceptionDetails) errors.push(`Tracker steps threw: ${result6.exceptionDetails.exception?.description}`);
  failures.push(...(Array.isArray(result6.result?.value) ? result6.result.value : []));

  ws.close();
  failures.push(...errors);
  if (failures.length) {
    console.error(`Dashboard smoke test FAILED (${failures.length}):`);
    failures.forEach((f) => console.error('  - ' + f));
    process.exitCode = 1;
  } else {
    console.log('Dashboard smoke test passed: load, section order, islands kept across a refresh, bots, pages to fix and tools folded with a summary (a click opens and is remembered), release markers (newest by number, tip on hover or tap, one line), breakdown card (count, sparkline, Show all) and its details, arranging cards (keys, drag), undo for card and goal removal, hostile event, path and site names render as text, entry page bounce, annotations (shared view, add, relabel, remove), excluding your own address in Settings, Settings as an overview (folded with a summary that follows changes, the note hint opens Chart notes, folded again on the next open), keyboard drill-down, group by version, funnel incl. unseen event, compare, 90 days, chart tab remembered, times in the configured zone (named in the footer for a viewer elsewhere), Today period wording, light theme, All sites (site picker: list under its button, arrow keys, Home and End, typing a name, the mouse moving the one highlight, Escape, a click outside and CMD+K close it; site overview, a row opens the site and is remembered; sites under their group in the picker and the overview, a group\'s header sums its sites, Other sites only counted, a group folds on its header and is remembered, headers on one line at phone width), sites in CMD+K (listed at once, filtered with names that start with the query first, a group\'s name matching its sites, / for pages only, Enter opens the first), logged-in goal for an unseen event (dimmed tile, target bar), tabs pattern, dialog focus trap, status announcements, the tracking script\'s puls.page() (queued, the current URL once, a path or a full URL through data-query and the id rule, another site ignored). No JS errors.');
  }
}

main()
  .catch((e) => { console.error('Smoke test error:', e.message); process.exitCode = 1; })
  .finally(cleanup);
