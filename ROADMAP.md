# Roadmap

## Done

- [x] Tracking script (JS + pixel + server-side Nginx mirror)
- [x] Pageview collection with privacy-first daily-rotating visitor hash
- [x] Bot detection (25+ bots: AI, search engines, social, SEO, monitors, automated clients)
- [x] Dashboard with charts, tables, donuts
- [x] Multi-site hub with site selector
- [x] UTM campaign tracking + guided UTM link wizard
- [x] Language and device stats
- [x] Session-based auth with CSRF protection
- [x] Multi-user with per-site access control
- [x] Brute-force protection on login
- [x] CLI tool (`php puls`) for key generation, user management, site management
- [x] SQLite with WAL mode + auto-migration
- [x] CORS support for cross-origin tracking
- [x] Path normalization (trailing slash, URL-decode, tracking param stripping)
- [x] Referrer grouping (Facebook, Instagram, Twitter/X, Google, etc.)
- [x] IDN/punycode decode on referrers
- [x] Self-referral filtering
- [x] Health check endpoint (`/?health`)
- [x] Auto-detect Forge zero-deploy paths
- [x] Pest test suite (156 tests)
- [x] Pre-push hook — tests run automatically before every push
- [x] GitHub Actions CI (PHP 8.3–8.5)
- [x] Broken link tracking (404/301/other) via Nginx post_action
- [x] Data retention - auto-cleanup of old bot visits and broken links (pageviews are kept)
- [x] PWA — installable, pull-to-refresh
- [x] Content-Security-Policy + security headers
- [x] Trend indicators on stat cards (comparison with previous period)
- [x] Bounce rate + median session length
- [x] Entry/exit pages (window functions)
- [x] CMD+K command palette with search + page filtering
- [x] Dark mode (System/Dark/Light)
- [x] Traffic channels (Paid/Campaign/Organic/Social/Referral/Direct) with click filtering
- [x] Tabbed cards (Chart, Pages, Traffic, Visitors)
- [x] Overlay drill-down with "Show all" on all lists
- [x] Google Ads detection (gad_source/gad_campaignid auto-fill, JS + server-side)
- [x] Stripping of gad\_\*, gbraid, wbraid, \_gl, ved from paths
- [x] Realtime view — "N online now" badge (last 5 min)
- [x] Exclude own visits: IP addresses and CIDR ranges per site or all sites (`exclude:add/list/remove`, or Settings in the dashboard), per browser with `#puls-ignore`

---

## Epic 1: Deploy & Go Live

- [x] Nginx config + SSL via Laravel Forge
- [x] Migrate existing SQLite data — auto-migration ran on first load
- [x] Create admin user — `php puls user:add admin`
- [x] Verify CORS — cross-origin tracking working
- [x] Health check endpoint — `/?health` returns 200/503
- [x] Stable visitor hash salt — APP_KEY in .env
- [x] Strip tracking query params — fbclid, gclid, utm\_\* etc. in normalize_path
- [x] Referrer grouping — Facebook, Instagram, Twitter/X, Google etc.
- [x] Auto-detect Forge zero-deploy — resolve_path() finds site root automatically
- [x] Pest test suite
- [x] UTM link wizard — guided 3-step wizard in dashboard
- [x] .env-based configuration — `php puls key:generate`

## Epic 2: Stability & Data Hygiene

- [x] Data retention - auto-cleanup of old bot_visits/broken_links (pageviews are kept)
- [x] Lazy migration check — version flag, no PRAGMA calls per request

## Epic 3: Security & Hardening

- [x] Content-Security-Policy — strict CSP on dashboard + security headers
- [x] Rate limiting (Nginx) — `limit_req_zone` on collect endpoint (documented in integrations.md)
- [x] Session rotation — hourly session ID regeneration for authenticated users
- [x] Audit log — login attempts (success/failed) logged in SQLite with username, IP, timestamp
- [x] **Site names escaped and cleaned (v1.26.1)** - a site name posted to the public endpoints ran script in the dashboard chart (found through a private report); the chart escapes it and `normalize_site()` strips markup and control characters. Advisory: [GHSA-376p-rq3h-j2vw](https://github.com/webready-se/puls/security/advisories/GHSA-376p-rq3h-j2vw)
- [x] **Login lockout per IP (v1.26.1)** - failed logins counted in `audit_log` per IP instead of in the session, which a client could drop
- [x] **Private vulnerability reporting** - enabled on GitHub, as SECURITY.md asks
- [x] **CSV exports defuse spreadsheet formulas (1.27.0)** - visitor-sent values starting with `=`, `+`, `-` or `@` get an apostrophe, in the dashboard export and the event CSV
- [x] **Read API takes `Authorization: Bearer` (1.27.0)** - keeps the share token out of URLs, server logs and shell history; `&share=` keeps working
- [x] **Hashed share tokens: considered, declined** - anyone with the database already has the data a token protects; see the design decisions in CLAUDE.md
- [x] **Dashboard in three files** - `dashboard.html` is 233 KB of markup, CSS and JS in one file; split it into `dashboard.html`, `dashboard.css` and `dashboard.js`, with no change in behaviour, as the first step towards the CSP below (external script and style files are what `'self'` allows). "One file" in the tagline is `index.php`, which stays one file, and the promise that matters to users, unpack and run with no build and no dependencies, holds with three. Caching between versions (`dashboard.js?v=1.30.0`) is a side effect, not the reason: gzipped it is ~50 KB. Done: PHP puts the version into the two URLs when it serves the page. The theme script in `<head>` (runs before the first paint, so there is no flash of the wrong theme) stays inline for now and moves with the CSP step. `APP_ACCENT` is not a style block on the dashboard: PHP injects `window.PULS_BRANDING` as a script and the dashboard sets the colours through the CSSOM, which no CSP blocks; that script becomes a JSON data block in the CSP step
- [x] **Dashboard CSP without `'unsafe-inline'`, in `script-src` and `style-src`** - with it, one missed `esc()` runs script (as in v1.26.1). Done: `script-src 'self'; style-src 'self'` on the dashboard, the shared dashboard and the login page. The 83 inline handlers became `data-action` (click) and `data-on-<event>` attributes with their arguments in `data-*`, dispatched by one listener per event type that walks from the target to the document and runs every action on the way, as the inline handlers bubbled. The 127 inline `style="..."` became classes for fixed values and `data-w`, `data-h`, `data-bg`, `data-color` for bar sizes and colours, applied through the CSSOM by a MutationObserver. The branding and the share are a JSON data block (`#puls-config`), which the policy does not govern, and the theme script in `<head>` is `theme.js`, shared with the login page, which also applies the install's accent through the CSSOM; so no nonce and no `/?css` endpoint (considered and not needed: the early theme script exists anyway). The login page's styles and CSRF refresh are `login.css` and `login.js`. The smoke test fails on any CSP violation

## Epic 4: Dashboard Improvements

- [x] Trend indicators — comparison with previous period (▲12%)
- [x] Dark mode — System/Dark/Light via CSS variables + hamburger menu
- [x] CMD+K command palette — search + page filtering with drill-down
- [x] PWA — installable, pull-to-refresh
- [x] Collapsible tools — show/hide tools section

## Epic 5: Server-side Bot Tracking

- [x] `/?log` endpoint — receives site, path, and User-Agent, logs to bot_visits
- [x] Nginx mirror — zero latency bot capture, configured per site
- [x] Deduplication — same bot + path + site within 10s is skipped
- [x] Separate view — bot activity timeline in dashboard

## Epic 6: Smarter Data

- [x] Bounce rate — with inverted trend (lower = green)
- [x] Median session length — avoids outlier distortion from idle tabs
- [x] Entry/exit pages — window functions (ROW_NUMBER per visitor_hash)
- [x] Broken link tracking — 404/301/other via Nginx post_action, per-status limit, expand/collapse

## Epic 7: First Customer Pilot

- [x] Deploy to customer — live with webhook auto-deploy
- [x] Google Ads detection — gad_source/gad_campaignid auto-fill (JS + server-side fallback)
- [x] Traffic channels — Paid/Campaign/Organic search/Social/Referral/Direct with click filtering
- [x] Tabbed cards — Chart, Pages, Traffic, Visitors — reduces scroll ~50%
- [x] Overlay drill-down — "Show all" on all lists (limit 10 + expand)
- [x] CLI improvements — sites:list, user:edit, interactive site selector
- [x] Interactive CLI — all commands work without arguments (pickers, prompts, confirmations)
- [x] Guided UTM wizard — 3-step wizard, editable domain, auto-refresh pause

## Epic 8: Customer Value

- [x] **Shareable dashboards** — token-based read-only links (share stats without login)
- [x] **Data export** — CSV/JSON export from dashboard (hamburger menu, client-side)

> Shareable dashboards deliver the most customer value fastest — clients want to view stats without needing a login. A simple `/?share=<token>` showing a read-only dashboard.

## Epic 9: Deeper Insights

- [x] **Realtime view** — "N online now" badge + stat card (last 5 min) — already implemented
- [x] **Custom events** — track button clicks, form submissions, downloads via `puls.track('event')` JS API
- [x] **Outbound link tracking** — auto-track clicks on external links via JS click listener
- [x] **Auto event tracking** — `data-auto-events` attribute for zero-config tracking of phone clicks, email clicks, downloads, form submissions
- [x] **Country/region stats** — based on Accept-Language, flag emojis + visitor count in Countries tab
- [x] **Comparison mode** — "This week vs last" as overlay in chart with combined tooltip and % delta
- [x] **Goals/conversions** — define target page (e.g. /thank-you), show conversion rate
- [x] **Custom date range** — date picker beyond 7d/30d/90d
- [x] **Summary cards** — "Best day: Tuesday", "Peak traffic: 2–3 PM"

## Epic 10: Release Management

- [x] **CHANGELOG.md** — retrospective for v1.0.0 covering all completed work
- [x] **Release build script** — `scripts/build-release.sh` creates a clean zip with only runtime files
- [x] **GitHub Action for releases** — triggers on tag push, builds zip, creates GitHub Release with changelog
- [x] **Claude skill `/release`** — prepare release: gather commits since last tag, suggest version, update CHANGELOG, create tag
- [x] **Release validation in CI** — extract zip, run health check to verify it works
- [x] **README install instructions** — download badge + zip-based quick start (not just git clone)

## Epic 11: Adoption

- [x] **Dockerfile** — Alpine + PHP built-in server (~93MB image), auto-setup entrypoint
- [x] **One-click deploy buttons** — Render, Railway, Fly.io configs + badge in README
- [x] **White-label** — configurable branding (APP_NAME, APP_TAGLINE, APP_ACCENT) via .env

Seven stars after seven months: the code is not the problem, being found is. Everything here costs time, not money.

- [x] **GitHub topics** - analytics, web-analytics, privacy, self-hosted, cookieless, gdpr, google-analytics-alternative, php, sqlite, single-file, so the repo shows up in topic search. No homepage link yet: the production install only shows a login
- [x] **README as a landing page** - every channel (topic search, awesome lists, a launch post) lands there, so it decides whether a visitor tries Puls. Lead with what few others show (AI crawlers and other bots, broken links from Nginx, one file on any PHP host), a three-line quick start right under the screenshot (file drop first, Docker second: the pitch is no containers), a short feature list instead of 45 bullets, the live demo link, and the Nginx, Apache and Forge details moved to `docs/`
- [x] **README images** - `scripts/readme-media.mjs` captures every image from the demo data, cut to dashboard elements, plus a drill-down GIF and the social preview; replaces `screenshots.sh`
- [ ] **Live demo** - a separate install with seeded demo data, reseeded nightly, shown read-only through a share link. Never the production install or real customer data. Linked at the top of README and set as the repo homepage. First of the open items here: Ackee, Umami and Plausible all have one, and in a comparison it is where people click before they read anything
- [x] **Social preview image** - for links shared on Hacker News, Reddit and Slack: `docs/screenshots/social-preview.png`, uploaded under Settings > Social preview (no API for it)
- [ ] **awesome-selfhosted** - PR to the Analytics section; check the list's current requirements first
- [ ] **Launch post** - Show HN and r/selfhosted, once the demo exists. The hook is "one PHP file on any host"
- [ ] **Docker image on GHCR** - published by the release workflow, so `docker run ghcr.io/webready-se/puls` works without building locally (free for public images)
- [ ] **WordPress snippet** - how to add the script in WordPress, in `docs/integrations.md`. A plugin is a larger, ongoing commitment

## Epic 12: Accessibility & Polish

Lighthouse: Performance 100, A11y 71 → target 90+. No visual regressions.

**Quick wins (zero visual impact)**
- [x] **ARIA attributes** — aria-label on icon buttons, aria-hidden on decorative SVGs, aria-live on live badge
- [x] **Language fix** — login page lang="sv" → lang="en"
- [x] **Reduced motion** — `@media (prefers-reduced-motion: reduce)` disables animations
- [x] **Skip-to-content link** — hidden link for keyboard users to jump past header
- [x] **Meta description** — add `<meta name="description">` for SEO (Lighthouse flag)
- [x] **Main landmark** — wrap #app in `<main>` element

**Low risk (minimal visual change)**
- [x] **Focus indicators** — replace `outline: none` with accent-colored glow on all interactive elements
- [x] **Form labels** — aria-label on search inputs, goal search, date picker
- [x] **Login form** — autocomplete attributes, error linked via aria-describedby

**Medium risk (test visually)**
- [x] **Color contrast** — darken muted text (#64748b → #475569) to meet WCAG AA 4.5:1
- [x] **Semantic buttons** — every clickable div gets role=button and tabindex, Enter/Space activate (applied to all rendered markup, no visual change)
- [x] **Tab ARIA pattern** — tablist/tab/tabpanel, aria-selected, roving tabindex, arrow keys and Home/End

**Larger effort**
- [x] **Modal focus trap** — Show all, search and Settings are modal dialogs: focus moves in, Tab is trapped, Escape closes, focus returns
- [x] **Screen reader live regions** — a polite status region announces period/site changes and drill-downs the user opened (not the 60 s refresh)

## Epic 13: Automation

- [ ] **Weekly report via email** — CLI command + cron for summaries, uses `mail()`— no dependencies
- [ ] **Webhooks** — HTTP callbacks on traffic spikes, new 404s, or threshold alerts
- [x] **Auto-refresh** — dashboard refreshes every 60 seconds (paused during UTM wizard)

## Epic 14: Event Analytics

Feedback from the first SPA integration (ten named events with data). Today events answer "how many clicked"; this epic makes them answer "is it changing, and what are the values".

**Patch (integration blockers)**
- [x] **Dedup includes event_data** — same name + different data within 10 s are separate events
- [x] **Queue stub** — `window.puls = window.puls || { q: [], track: ... }` drained by the script on init
- [x] **Debug mode** — `data-debug` logs every pageview and event to the console
- [x] **Visitors in Events list** — fetched by the API but never rendered
- [x] **Docs** — naming conventions, data rules, 1000-char limit, TypeScript declaration

**Next feature**
- [x] **Group by data key** — drill-down picks a key from `event_data` (auto-discovered from recent rows), shows value, count, unique visitors. Generalises the hardcoded outbound `url` grouping.
- [x] **Trend per event** — day-by-day chart on click with the same date range and compare mode as pageviews
- [x] **Rate, not just count** — events per 100 visitors next to the count, toggle in the trend

**Later**
- [x] **Two-event funnel** — pick two event names, get ratio and trend (feedback_opened vs feedback_sent)
- [x] **Goals as events** — a goal is a path or an event name, same conversion view
- [x] **Event API via share token** — `?api&share=…&event=x&group=key` JSON + CSV with data keys as columns (Epic 13)
- [x] **App version on hits** — `data-version` stored on pageviews/events, release markers in charts, group by version
- [x] **Saved breakdowns** — name a grouping ("Missed searches" = search_miss by query), show as its own card
- [x] **New values highlighted** — values not seen before in a breakdown (also fits referrers)
- [x] **Longer event history** — `EVENTS_RETENTION_DAYS`, default 400 (0 = forever). Chosen over daily rollups: history does not slow queries and events are small, so keeping raw rows is simpler and keeps every detail
- [x] **form_submit auto-event** — skip when `defaultPrevented`, read `data-puls-event` on the form for identity

## Epic 15: Clean Paths (v1.23.0)

Two customer sites stored personal data in paths without noticing: an internal app sent search terms, seller names and registration numbers in query strings, and a claims form sent each customer's personal link (`/{token}`). The principle, recorded in CLAUDE.md: the sender owns what it sends. Puls gives the controls and documents the risks, but does not inspect or mask data (masking was considered and declined).

- [x] **`data-query`** — `none` drops query strings in the browser, `page,tab` keeps only those parameters
- [x] **`data-collapse-ids`** — numeric segments sent as `:id` (one app went from ~4,400 distinct paths to 131)
- [x] **`data-path`** — page sets the path Puls receives, e.g. `/:token` on personal-link pages
- [x] **`sites:clean-paths`** — `--query`/`--keep`, `--ids`, `--tokens`, `--dry-run` for data already stored, per site
- [x] **Meta `brid`** — per-click id stripped for Meta ad traffic only (migration v20); `media_type` kept on purpose, it is the only record of the ad format
- [x] **Read API docs** — `docs/api.md` (share tokens, parameters, response fields, CSV) and a privacy section, attribute table and checklist in `docs/integrations.md`

## Epic 16: Campaign Outcomes

From customer integrations. Traffic per channel and campaign exists; what happens after the click does not. A same-campaign comparison done by hand showed video-ad visitors clicking through 10x more often than image-ad visitors, which no ad platform shows. Not decided yet.

- [x] **Campaigns name the platform** - `ig`, `fb`, `msg` and `an` read as Meta's platforms and `google` with a cost per click as Google Ads, in the label only. Splitting the Paid channel per platform was set aside for now: it adds rows that are always on, and a click on Paid already filters to its campaigns, which now say the platform
- [ ] **Events, goals and bounce per channel and campaign** — today the channel filter only affects pageview stats and hides goals. Include the Meta ad format (`media_type`) as a campaign attribute
- [ ] **Goal with a data condition** — event + key=value, e.g. `form-success` where `type=service-booking`, or outbound clicks to one domain
- [ ] **Path groups** — filter or group pageviews by prefix (`/workshop/*`), also as an API prefix filter
- [ ] **Funnel with more than two steps** — same-day by design (daily visitor hash)

## Epic 17: Dashboard at a Glance

From a site with 4 goals and 9 saved cards: the page got long, the chart ended up below every card, a removed card could not be brought back, and the 60 s refresh redrew the whole page, so content jumped and a click could land on the wrong card.

- [x] **Goals and saved cards stay on their site** - All sites showed every site's goals, with the rate counted against every site's visitors, and every site's cards. Now they show only when the view is one site anyway (an install with a single site, a user limited to one); otherwise they are on their site, where the site overview leads

**Order and a calm refresh**
- [x] **Order** - the numbers and the chart first, then goals, pages and sources; saved cards after them
- [x] **Islands** - the refresh replaces only the sections and saved cards whose HTML changed; the rest keep their DOM (hover, focus, open drill-down, tab, chart scroll). Held while the tab is hidden or the viewer used the page in the last 10 s
- [x] **Undo instead of instant removal** - removing a saved card or goal shows Undo for 6 s before it is sent; leaving the page sends it right away

**Saved cards**
- [x] **Top 5 and Show all (n)** - expands in place (up to 50 rows, then Details); cards side by side share one height
- [x] **Card header at a glance** - events in the period, change vs the previous period (neutral arrow: more can be good or bad) and a daily sparkline. One extra query per card covers both periods
- [x] **Reorder cards** - drag the grip (Pointer Events, mouse and touch) or arrow keys on it; the order is stored per site on the server (position column, migration v23, `?breakdown_order`), so shared links follow it

**Goals**
- [x] **Goals as KPI tiles** - conversions large, rate, trend arrow vs the previous period (colored: more conversions is good), sparkline; goals without hits dimmed instead of "No hits yet"
- [x] **Goal target** - a count per day, week or month with a bar that fills, scaled to the period on screen (10 per week is 43 over 30 days); migration v24, `?goal_target`

**Sections**
- [x] **Collapsible sections with a summary** - a section few look at every day (bots, pages to fix) folds to its header, and the header then says in one line what is inside ("1,240 bot visits, 3 pages to fix"), so a folded section still tells when it is worth opening. Each viewer's choice is remembered, as Compare is. Not an accordion on every section, and no settings page of toggles: the summary is what makes folding calm instead of hidden
- [x] **Settings menu as an overview** - Chart notes, Excluded IPs and Goals in the settings menu fold to a header with a one-line summary ("3 notes", "2 excluded · your address is counted", "4 goals"), so the menu shows at a glance what is set and opens one task at a time; today every section is open and Excluded IPs is a scroll away. Appearance and Export stay open: one row each, so folding them would only add a click. Folded each time the menu opens, not remembered: the menu is opened for a task, and remembered sections would make it long again. The same component as the dashboard's folding sections, in a lighter style for the panel

**Switching site**
- [x] **Sites in the CMD+K palette** - with nothing typed, the palette lists the viewer's sites (the current one marked); typing filters them in the browser, above the page search for the current site, and a query that starts with `/` searches pages only. One palette and no second shortcut: site names and paths rarely look alike, and today the palette stays empty until two characters are typed. A user with one site sees the palette as today. The results are a listbox with a group per kind and `aria-activedescendant`. Who is online per site was left out: `?api&sites` gives names only, so it would take a query on every open or a change to a documented endpoint, and the site overview on All sites already shows it; the own site picker below can take it up
- [x] **Own site picker** - the native `<select>` becomes a button with a list anchored under it, sharing the palette's rows. Not the palette: a control that shows a value is expected to open a list in place, and the palette is for whoever asked to search. A filter field on top only with many sites; Escape or a click outside closes it and returns focus to the button; full width under the header on a phone, without focusing the filter there, so the keyboard does not cover the list. Worth it for search among many sites and the online count, not for looks alone: the native select is accessible and good on phones. Build it after the palette item, whose rows it reuses. Filter from 8 sites; a hub with fewer scrolls a short list. Who is online per site was left out here too: `?api&sites` gives names only, and the site overview on All sites shows it (Ideas)

**The chart**
- [x] **A day's hours, on demand** - the chart says which day was busy, not when in the day; Peak hour gives one hour for the whole period. A click on a day opens its hours in a card under the chart, a click on another day switches, on the picked day or the card's x closes it. Closed by default and remembered when opened, as Compare is; nothing is fetched while it is closed (`?api&hours=`). The chart's days are one tab stop with the arrows, Home and End between them; the card is its own island, so a refresh with the same hours leaves it alone. Idea and first version by an outside contributor (#104)
- [ ] **A normal day behind the hours** - with Compare on, the hours card draws a normal day of the same weekday behind its bars, as the chart draws the previous period: the average of the last four of that weekday, whatever the period, since a Monday and a Sunday have different shapes and a 7-day period holds one of each. The tooltip says "vs a normal Tuesday: 35". Worth most for today ("ahead of a normal Tuesday so far?"). Left for later: one more query and a second layer in a small card, for a card not yet in use; an average over all days was set aside, since it mixes weekdays and misleads
- [x] **The month under the dates** - the dates said "3 Tue", so a long period scrolled back did not say its month. Each month starts at a tick between the 31st and the 1st, and the month in view stays at the left edge, outside the part that scrolls, until the next month's label reaches it. A label on the first day in view was tried and dropped: it hopped from day to day while scrolling, and looked the same as a month start. Bars 12 px lower for the line, so the card keeps its height; dates read "Tue 3", tooltips and notes "Tue, Nov 3"

## Epic 18: Engagement Time

The session length is the time from a visitor's first to last pageview (`HAVING COUNT(*) > 1`), so the time on the last page is never counted and a visit of one page, however long, is not in the measure at all. Someone who reads an article for five minutes and leaves counts as nothing. "Full picture" promises more than that, and time on page is the measure Puls is most often missing when compared with others. Ackee counts time while the page is open, which is what this epic adds.

- [ ] **The tracker sends engaged time** - counts only while the tab is visible (pause on `visibilitychange` to hidden), sends the total with `sendBeacon` when the tab is hidden, on `pagehide`, and just before a SPA navigation sends the next pageview. Nothing under one second, a cap of 30 minutes per page (a tab left open is not reading). No id leaves the browser: the server finds the pageview itself. About 200 bytes more in the script. The script is 2,030 bytes gzipped and the README says under 2 KB, so trim it first (the debug messages are the cheapest) or change the README's number
- [ ] **`engaged_ms` on pageviews** - a migration adds the column; `?collect` takes `{e: ms, u, site}` and updates the latest row with the same site, visitor hash and path within 30 minutes with `MAX(engaged_ms, ?)`. Same bot, excluded-IP and `normalize_path` treatment as a pageview. That UPDATE needs the covering index from Maintenance (site, created_at, visitor_hash) first, or it is a new cost on every page
- [ ] **Session length and time on page in the dashboard** - session length becomes the sum of `engaged_ms` per visitor, median over every visit including those of one page; a "Time on page" column (median) in the pages table. Days before the migration keep the old measure, and the changelog says where the seam is
- [ ] **Engaged visit instead of bounce** - with engaged time in hand, a bounce can mean "one page, under 10 seconds, no event" rather than "one page": a visitor who reads one page for three minutes or sends an event is not a bounce. Decide the definition before changing the number, and say so in the changelog

## Epic 19: Site Groups

A hub with 32 sites (26 shops and six others) shows them as one flat list of 32 rows on All sites, in the site picker and in ⌘K, while the owner thinks in "private sites", "the agency's sites", "the shops on server 1". Vaka groups its sites the same way, so the two tools read alike.

- [x] **A site belongs to one group, or none** - a `site_groups` table (`site`, `group_name`), migration v27. One group per site, as Vaka chose; tags if one group proves too narrow (Vaka's roadmap says the same). No group table: a group exists as long as a site points to it, so there is nothing to add or remove apart from the sites. `sites:rename` moves the group with the site, `sites:remove` drops it
- [x] **CLI** - `php puls sites:group <group> <site...>` puts one or many sites in a group (26 shops in one command), `sites:ungroup <site...>` takes them out; both interactive without arguments, as every command. `sites:list` lists sites under their group when groups exist. A group name follows the site-name rules (100 characters, no angle brackets or quotes)
- [x] **All sites overview by group** - a header per group with the group's visitors, views and trend over the period, its sites under it, groups by views, sites without a group under "Other sites" last, which carries its count only (a sum of the leftovers says nothing, and with one site it would repeat the row); a site's row opens the site as today. Checked at phone width in the smoke test through an emulated viewport, never by resizing a window. Seen on a hub of 27 sites: groups sort the list but do not shorten it, so a group folds to its header on a click (open until the viewer folds it, remembered per viewer like Bots and Pages to fix) and the header keeps the sums, so a folded hub reads as one line per group; a group of more than six sites shows its five biggest and sums the rest behind "Show 6 more sites (12 views)", as Top pages does. The header is a faint band out to the card's edges (`--surface-2`, no new colour): in grey uppercase alone it weighed less than the site rows under it and the eye scrolled past it `siteOverview` rows carry `group` and `prev_views`, so the dashboard sums a group itself; the API lists groups with `?api&groups` (`{group: [sites]}`, limited to the viewer's sites)
- [x] **Groups in the site picker and ⌘K** - the picker lists sites under group headers (listbox groups, so a screen reader hears the group), and its filter matches a group's name too, so "shop" lists every site in Shops. ⌘K keeps one flat Sites list and names the group after a site that has one: nested groups in a listbox read badly
- [x] **Docs** - `docs/configuration.md` (Site groups, CLI reference), `docs/api.md`, README's "Teams and clients" line
- [ ] **Group a site before its first visit** - `sites:group` refuses a name that is not tracked yet, to catch typos, so a hub that adds many sites at once has to wait for each one's first pageview (on a 26-site setup, five had none by the evening). Either `--new` to accept names not seen yet, with the typo guard kept for the rest, or a site that arrives with a pending group. A pending row in `site_groups` already works: the overview and the picker only list sites with pageviews, so it shows the day the first visit comes

Later, not now: the group as a scope of its own (a dashboard over the group's sites, `site=` taking a group, share links and user access per group as Vaka has). It is more code in every query; wait until groups exist and show how they are used. When it comes, the overview takes Vaka's form, a card per group with the header as the way in; today a group is a band inside the Sites card (the same `--surface-2` uppercase row as Vaka's column header), since a card with a big heading would promise a click that leads nowhere yet. Also considered: a group's sums as a drill-down on its header. The header is a sum today and nothing more, since a click on a group has nowhere to go until the group is a scope.

## Epic 20: Long Periods in a Fraction of a Second

A dashboard request runs about 35 queries, and each reads every pageview of the period, so its time grows with the period. On a production install on a small server (1 vCPU, 1 GB RAM, which is what many self-hosters run Puls on) 30 days took 1.9 s, 90 days 5.8 s and January to September 10 s, all of it CPU. Indexes, merged queries and SQLite's settings are spent (Maintenance has the numbers). What changes the class is a summary per site and day that the dashboard reads instead of the rows.

Puls can sum exactly: the visitor hash changes every day, so a visitor never spans two days, and every figure counted per visitor over a period (distinct visitors, bounces, entry and exit pages, channels, sessions) is the sum of the days'. The summary is also small: on that install a day holds about 1,350 pageviews but 46 distinct paths, 11 referrers, 5 UTM combinations and 14 countries, so a day is about a hundred rows and 90 days about nine thousand.

To decide before building (a proposal for each):
- [x] **What is summed** - one table, `daily(site, date, kind, key, views, visitors, entries, bounces, exits)`: per day views, visitors and bounces; views per hour; per path views, visitors, entries, entry bounces and exits; visitors per referrer, UTM combination and country; visitors and views per channel; pageviews per browser, device and language; visits of more than one page counted per length in seconds, so the median over a period stays exact. A new figure is a new kind, not a migration
- [x] **Today from the rows** - the days not over yet (today, and yesterday for the first five minutes after midnight) are counted from their pageviews on every request, for every site on screen at once
- [x] **Summed a little at a time** - summing a day costs about what reading it from the rows does, so summing every missing day in the request that meets them made the first long view after an upgrade twice as slow as without the summary (90 days of all sites on a small server: 18 s instead of 9 s). A request sums at most one window of 31 days, newest first, counted in one pass and written in one short transaction, and reads the rows while days are still missing; a few views of a long period sum it. The first pageview after midnight sums the day before for every site; `php puls stats:build` sums everything at once. No migration walks the history
- [x] **A cache, never the source** - `sites:clean-paths` deletes the site's summary, `sites:rename` and `sites:remove` rename and delete it with the rest. A test compares every field of nine views (periods of 1 to 90 days, a custom range, All sites, the expanded lists) from the summary with the same from the rows, on three random data sets; ties are broken by name in both, where the rows query left them to chance
- [x] **What stays on the rows** - a path or a channel filter, the hours of a day, events and saved cards, bots, broken links and live
- [x] **The seam before v22** - none: before v22 the salt held the date, so a hash never spanned days then either. The seam is the site in the hash (1.30.0): before it, one person on two sites of an install had one hash a day, so All sites from the rows counted them once and from the summary counts them once per site, as everything since 1.30.0 does
- [x] **Measured before and after** - 2 million pageviews on a laptop: one site 30 days from 2.6 s to 0.13 s, 90 days from 9 s to 0.23 s, a year from 27 s to 0.5 s, All sites 30 days from 3.3 s to 0.17 s. On a production install on a 1 vCPU, 1 GB server: 30 days from 1.9 s to 0.5 s, 90 days from 6.5 s to 0.85 to 1.1 s, all sites 90 days from 9.1 s to 1 to 1.2 s; what is left there is mostly events and bots (below)
- [ ] **Events and bots in the summary too** - with the pageviews summed, they are most of what is left of a long view on a small server: on that install, of 1.2 s for 90 days of all sites, events took about 0.4 s (outbound clicks most) and bots about 0.3 s. Per day: events and visitors per event name and outbound URL, visits per bot, category and path

## Epic 21: Visitors Who Are Not People

Puls counts whatever runs its script as a visitor, and a program driving a real browser runs it. On a hub of 26 shops, two days held 2,888 visitors, and 82% of them looked like two such programs, nearly all on three shops. The shop that looked biggest had 1,179 visitors, 31 of them looking like people, while the biggest by that measure had 64. On the other 23 shops about 13% of the visitors looked the same, and about 2% on a client install with 23,000 visitors in 30 days, so this is not everywhere, but on those three shops the number is 20 to 40 times too high. These are patterns, not proof, and nobody knows who runs them: finding out would take logging IP addresses, which none of this needs.

- [x] **A browser that sends no language is a bot** - the first program: Chrome on desktop, no referrer, no `Accept-Language`, 2,774 distinct paths in 2,802 pageviews, mostly at night, and 1,140 of its 1,151 visitors on one shop. Browsers people use send the header, so `?collect` without it goes to Bots as "Automated browser" and `?event` without it is dropped. What it costs, measured on the client install: 45 of 22,998 visitors lacked it (0.2%), none on a phone, none from a campaign, one with a referrer, four with an event and none of those a sent form. Stored rows stay: `language` is empty on every row older than the column, so moving them by that would take real visits along, and a site with such traffic shows a seam. A setting to turn it off was set aside: there is nothing to gain from counting these, and a proxy that strips the header shows at once, with every visit under the bot's name
- [ ] **Say when a site's visitors look wrong** - the second program: language English, desktop, one page, no referrer, a mix of Chrome, Firefox and Edge, nearly only between 07 and 18 Swedish time with a sharp stop at both ends (night in the US), and 1,144 of its 1,209 visitors on two shops. No rule per visit can catch it: on the client install 373 visitors look the same, 40 of them did something and one sent a form. So a signal per site and not a filter: the share of such visitors against what is usual for the site, a flat or cut-off day, or visitors far from what the site's outbound clicks say. Time on page (Epic 18) would make it sharper. Needs design: Puls should not accuse a site's visitors on a guess
- [ ] **Server requests against script pageviews** - the Nginx mirror forwards only crawler user agents. If it sent every page request, even as counts per site, "the server saw 40,000 browser requests, the script saw 200" would measure the bots that do not run the script, which the two above do not reach: one shop's out clicks were about 20 times its visitors. It does not see the programs above, which count on both sides. Costs a mirror request per page
- [ ] **Outbound clicks to a site's own redirect paths** - `data-outbound` skips links on the same host, but affiliate sites link to their own `/buy/...`, which then redirects. An attribute naming those paths, so real clicks can be compared with what the networks report
- [ ] **`sites:list` for a period** - it gives pageviews and visitors since the site's first day; `--days=1` makes it comparable with a daily number without dividing by hand
- [ ] **Say where to run the CLI** - on a zero-downtime deploy `puls` is in `current/` under the site root, so `php puls` from the root says "Could not open input file". A line in `docs/install.md`

## Maintenance

- [x] **Release marker edge clipping** — `.chart-release` labels clip at the edge on narrow screens (fixed for chart notes, not for release markers)
- [ ] **Remove dead `renderTable()`** — no callers in dashboard.html
- [x] **Shorter cache for the tracking script** — `?js` was cached 24 h, so new attributes took up to a day to reach every visitor. Now 1 h plus an ETag, so the hourly check is a bodyless 304
- [x] **Visitor IP behind a proxy** — Puls behind a reverse proxy or Cloudflare saw every visitor as the proxy (visitor hashes differed by user agent only). `TRUSTED_PROXIES` lets those proxies set the visitor IP via `X-Forwarded-For` / `X-Real-IP`; Forge (Nginx + PHP-FPM) was not affected
- [x] **Dashboard performance at scale** — 30-day dashboard ~17 s on a 2M-pageview benchmark. In this order: a benchmark seed script in the repo (`scripts/seed-benchmark.php`; benchmarks run on seeded data, never on customer data), then a covering index on `pageviews (site, created_at, visitor_hash)`, since the bounce and session queries filter by site and period and group by visitor, and `idx_visitor` covers the hash alone, then measure. Also the session median in SQL (`LIMIT 1 OFFSET n/2` after a count) instead of fetching every session's duration into PHP. README says "small and medium sites", and 2M pageviews is a medium site, so either this gets faster or the README says a number Done: `scripts/seed-benchmark.php` (2M pageviews over a year, 90% on one site, the same every run) and `scripts/benchmark.php` (times the API as the dashboard asks for it, with query plans). On that database All sites for 30 days went from 10.5 s to 3.5 s and one site from 3.1 s to 2.8 s. Most of All sites was `idx_visitor`: nothing looked a visitor up by hash, and the planner walked it over the whole table to avoid sorting; it is gone, and the covering index replaces `idx_site_date` (its prefix) too. A sampled `ANALYZE` once a day (`refresh_statistics`) tells the planner there are few sites with many rows each, so All sites walks the index site by site instead of scanning. The median in SQL (a window, one pass) bounds memory, 12 MB for a year of the big site in PHP before, but is no faster
- [x] **One site still takes 2 to 3 s on 30 days at 2M pageviews** - about 20 queries each read the period's rows: pages, sources, countries and channels group by a column the index does not hold, and entry and exit pages sort the period twice for their window functions (the dearest query, about 500 ms). Merging the paired queries (a table and its count, entry and exit, the summary's top page and source) took 30 days from 2.8 s to 2.6 s and a year from 31 s to 27 s; `path` in the covering index measured at about 150 ms more. What is left is a daily rollup table per site (pages, sources, countries, channels, visitors per day), which Puls can do exactly: the visitor hash changes every day, so distinct visitors over a period are the sum of the days. Done in Epic 20: the dashboard reads a daily summary, and that view takes 0.13 s. 90 days takes 9 s and a year 27 s, so that is where those views end up if they matter. Measured on a production install on a small server (1 vCPU, 1 GB RAM, 460,000 pageviews): 7 days 0.5 s, 30 days 1.9 s, 90 days 5.8 s, January to September 10 s, all of it CPU. Set aside after measuring there: a larger SQLite page cache and `temp_store=MEMORY` (20% faster on a laptop, slower on that server, since the cache competes with the system's file cache in 1 GB: a 64 MB cache took 90 days from 5.3 s to 9 to 11 s, `temp_store=MEMORY` to 7 s), and `VACUUM` (a copy without its 71 MB of free pages was no faster)
- [x] **The dashboard's requests do not wait for each other** - the data request held the login session from the login check to its last query, and PHP keeps a session locked until it is written, so every other request from the same browser waited: the hours, a drill-down, the next refresh, a second tab. On that server a day's hours took 10 s instead of 35 ms. `session_write_close()` once the login is checked; a feature test runs two requests at once on a server with workers
- [x] **A slow load shows that it is under way** - while the rollup above waits, a long period or range takes seconds, and the old view stayed on screen with no sign of the new one coming. A bar along the header and a dimmed page, both after 150 ms so a quick answer flashes neither, and after a second a line that names what is loading (also told to screen readers). The whole page loads one way: the first load too, from the HTML before the script runs, with its line after 300 ms instead of a spinner and "Loading stats...". A part that opens in place (a drill-down) keeps its own "Loading…" where it opens, pull-to-refresh only its own spinner, and the 60 s refresh stays quiet
- [x] **Only the latest load renders** - a 90-day answer arriving after a 7-day one replaced it under the 7-day button; each load has a number, and only the latest draws
- [x] **No refresh for a range that ends before today** - it cannot change, and a long range is the dearest request the dashboard makes, asked again every minute while a tab stayed open
- [x] **Site in the visitor hash** - `visitor_hash()` is `sha256(ip . ua . salt)`, without the site, so one person visiting two sites of the same install on one day gets the same hash on both and the visits could be joined. Add the site (Plausible does), one line, no migration: the hash changes every day with the salt anyway. A visitor on two sites then counts as two on All sites, which is what a privacy tool should do
- [x] **No duplicate pageview on a `pushState` to the same URL** - the tracker sends a pageview on every `pushState` and `popstate`, also when the URL did not change (a hash change, a state-only push). Remember the last URL sent and skip an unchanged one
- [x] **`puls.page()` for a pageview the script cannot see** - the tracker counts a page on load, `pushState` and `popstate`. A listing that loads more with `history.replaceState` (endless scroll) or an app that manages history itself never counts pages 2..n; the Google Analytics setup it replaced had a manual pageview there. `puls.page(path?)` sends one through the same rules as an automatic pageview (`data-query`, `data-collapse-*`, `data-path`, the same-URL guard), and the queue stub takes it before the script has loaded, as `puls.track`. Hooking `replaceState` too was considered: Umami does, Plausible does not, since sites use it for filters and query changes that are not a navigation, and with `data-query` on every filter would count. A manual call says what the site means. Found in a 26-store integration. Done: `puls.page(path?)`, a full URL on the same site too; the queue stub takes `{ page: arguments }` in the same queue as `track`, so the order holds, and the old stub still works. The smoke test loads the script in the page with `sendBeacon` recorded and checks what it would send. The script grew from 1,944 to 2,030 bytes gzipped, 18 under the README's "under 2 KB"
- [ ] **`--yes` on destructive CLI commands** - `sites:remove` asks for the site name to be typed, `user:remove`, `share:revoke` and `sites:clean-paths` for y/N, so none can be scripted without piping the right answer, and a piped "y" aborts `sites:remove`. `--yes` skips the question and prints what was done as the interactive run does; without it, nothing changes. Found when 26 stores' test rows had to be cleaned with SQL instead
- [ ] **A full URL in `u` is stored as its path** - the script sends path and query, but a hand-made or server-side collect with `u: "https://shop.example/test"` stores `https:/shop.example/test` (the slash collapse in `normalize_path`). When `u` has a scheme, keep its path and query (`parse_url`) and ignore the host, since `site` names the site
- [ ] **Changelog entries lead with one sentence** - every entry is a paragraph, which keeps the reasons but is heavy to skim at release time. Keep the reasons; put a sentence that stands alone first
- [ ] **`PRAGMA synchronous = NORMAL`** - FULL today. In WAL mode NORMAL keeps the database intact on power loss (only the latest writes can be lost) and speeds up writes; measure with the 2M benchmark. Not OFF, which risks a corrupt database
- [x] **Pull-to-refresh keeps the view** - the installed app reloaded the last N days instead of a custom date range and left out the share token; it now goes through the same request as the dashboard
- [x] **Time zone setting** - Puls stores visits in the server's local time and never sets a time zone, so php.ini decides. The Docker image and most servers run UTC, which puts Peak hour, the hourly card and the day boundary one or two hours off for a site in Sweden, and nothing says so. `TIMEZONE` in `.env`, set in `config.php`; unset keeps php.ini, so existing installs do not shift. Stored visits keep their times, so changing the zone on a running install leaves a seam (document it). Show the zone in the dashboard, e.g. in the footer, so a wrong one gets noticed. A zone per site would mean storing UTC and converting in every query: not now
- [ ] **Design kit 0.6.0** - Puls's copy of the tokens says kit 0.4.0; the tokens are unchanged since, so only the version line moves. Copy Snoka's new mark (a hedgehog, kit 0.5.0) into the footer's family marks: it shows only once Snoka is public, and would be the old lens then. Puls already follows 0.6.0's other rules (`docs/controls.md`, the footer as wide as the content under a hairline)
- [ ] **Sparklines say what a day holds at once** - the sparklines in saved cards and goal tiles use an SVG `title` per day, which waits a second and never shows on a phone. The kit's `docs/charts.md` (0.5.0) asks a bar to say what it holds where it is pointed at or tapped, as the main chart's tooltip already does. Some design to it: a tooltip on a 100 px chart must not cover what it explains
- [ ] **Focus events in the smoke test** - headless Chrome sends no focus events without `Emulation.setFocusEmulationEnabled` (learned in Vaka). The smoke test checks focus through `document.activeElement`, which works, but a step that waits on a focus or blur handler would pass or fail for the wrong reason; turn it on in `scripts/smoke.mjs`
- [x] **One clock format** - times are formatted four ways today: Peak hour in 24 h from PHP, the footer in `en-GB`, the live list and event details in `en-US` (`formatTime`, "02:32 PM"), and the hourly card from #104 as "2p". `TIME_FORMAT=24h` (default) or `12h` in `.env`, like `APP_ACCENT`, and one helper in the dashboard for every clock time; Peak hour also gets its hour as a number, so the API string stays. Not the browser's locale: Chrome formats by its UI language, not the OS region, so a Swede on English Chrome would get 2 PM. A per-viewer choice can come if someone asks for it

---

## Ideas

Things to explore later — not committed to.

- **API keys** — alternative to session auth for headless API access
- **Country from GeoIP: considered, set aside** - Accept-Language guesses the region, and guesses wrong often (a Swede abroad, `en-US` on a Swedish machine). A local country database (DB-IP Lite, free) would look the IP up at collection without storing it, as Plausible does. Against it: the database has to be downloaded and refreshed, and read by an mmdb reader Puls would have to carry, which breaks "drop the file and run with no dependencies"; and it uses the IP for something more than hashing, which is one more thing to explain in the privacy text. Honest but coarse beats a dependency, for now
- **`php puls bots:unknown`** - lists the user agents behind the visits named Unknown bot/crawler/spider/client, with counts, so a self-hoster can paste them into an issue (#124 came from someone who had counted them by hand). Cheap to build; waits until a second person asks. Renaming stored visits when the list grows is not a command: it runs as a migration on upgrade, so nobody has to know about it- **Exact text the name hooks let through** - Hamstra has a gitignored list of exact lines the real-name hooks pass, so the author line in `composer.json`, which names a person on the list, can change without being stopped. Puls accepts today that an edit to that line is stopped, since it rarely changes; worth it if it starts to
- **Who is online per site in the site picker and CMD+K** - left out of both: the site list (`?api&sites`) gives names only, so it would take a query on every open or a change to a documented endpoint, and the site overview on All sites already shows it. Worth it if a hub's owner switches sites to find where visitors are now
- **Slack/Discord notifications** — daily summary via webhook
- **Aggregation tables** — daily rollups for faster queries at scale
- **Backup/restore CLI** - `php puls backup` / `php puls restore` for SQLite snapshots. Snoka has built `php snoka backup`, a shape to copy:
  - `VACUUM INTO` a bound path: one consistent copy while tracking goes on writing, without the free space, and no `sqlite3` binary needed
  - `umask(0077)` around it and `chmod 0600` after, since the copy holds all the data
  - `PRAGMA integrity_check` on the copy; a copy that fails is removed and the older ones kept
  - Named `puls-YYYY-MM-DD-HHMMSS.sqlite` so the newest sorts last; the newest 7 kept (`--keep=N`), deleting only files that match that pattern
  - Off the server is not the tool's job: a storage bucket shared by all Webready tools' backups, copied by rclone in a scheduled job per tool, is likely but not decided, since it is a new monthly cost
  - Restore differs from Snoka's: Puls writes on every tracking request, so stopping scheduled jobs is not enough. Writes have to stop (PHP-FPM or the site) before the database is copied over and `-wal`/`-shm` removed, or an open connection writes to the old file
  - `docs/integrations.md` already says to take a backup before `sites:clean-paths` without saying how; the docs for this command answer that
- **Funnels** — visualize visitor paths (landing → page → conversion)
- **MySQL support** — optional driver via PDO adapter, SQLite remains default
- **Numeric aggregates on event data** — sum, mean and median of a numeric key (today: send buckets)
- **Internal-site mode** — for logged-in apps: hide referrers and campaigns, show use per week and per role
- **API pagination** — page past 1,000 rows in `expand`
- **Saved card across events** - one card for several events that share a group key (e.g. two events with `where`); sending one event is the workaround today
- **Respect GPC as an option** - `data-respect-gpc` on the script tag skips tracking when the browser sends Global Privacy Control. Off by default: Puls stores no personal data, GPC's legal weight concerns selling or sharing it, and Brave and DuckDuckGo send it by default, so honoring it everywhere would undercount. Do Not Track is effectively gone (W3C ended it, Safari removed it in 2019)
