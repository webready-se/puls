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

- [x] **GitHub topics** - analytics, web-analytics, privacy, self-hosted, cookieless, gdpr, google-analytics-alternative, php, sqlite, single-file, so the repo shows up in topic search. No homepage link yet: puls.wrlabs.se is the production install and only shows a login
- [x] **README as a landing page** - every channel (topic search, awesome lists, a launch post) lands there, so it decides whether a visitor tries Puls. Lead with what few others show (AI crawlers and other bots, broken links from Nginx, one file on any PHP host), a three-line quick start right under the screenshot (file drop first, Docker second: the pitch is no containers), a short feature list instead of 45 bullets, the live demo link, and the Nginx, Apache and Forge details moved to `docs/`
- [x] **README images** - `scripts/readme-media.mjs` captures every image from the demo data, cut to dashboard elements, plus a drill-down GIF and the social preview; replaces `screenshots.sh`
- [ ] **Live demo** - a separate install with seeded demo data, reseeded nightly, shown read-only through a share link. Never the production install or real customer data. Linked at the top of README and set as the repo homepage
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

- [ ] **Events, goals and bounce per channel and campaign** — today the channel filter only affects pageview stats and hides goals. Include the Meta ad format (`media_type`) as a campaign attribute
- [ ] **Goal with a data condition** — event + key=value, e.g. `form-success` where `type=service-booking`, or outbound clicks to one domain
- [ ] **Path groups** — filter or group pageviews by prefix (`/workshop/*`), also as an API prefix filter
- [ ] **Funnel with more than two steps** — same-day by design (daily visitor hash)

## Epic 17: Dashboard at a Glance

From a site with 4 goals and 9 saved cards: the page got long, the chart ended up below every card, a removed card could not be brought back, and the 60 s refresh redrew the whole page, so content jumped and a click could land on the wrong card.

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

## Maintenance

- [ ] **Release marker edge clipping** — `.chart-release` labels clip at the edge on narrow screens (fixed for chart notes, not for release markers)
- [ ] **Remove dead `renderTable()`** — no callers in dashboard.html
- [x] **Shorter cache for the tracking script** — `?js` was cached 24 h, so new attributes took up to a day to reach every visitor. Now 1 h plus an ETag, so the hourly check is a bodyless 304
- [x] **Visitor IP behind a proxy** — Puls behind a reverse proxy or Cloudflare saw every visitor as the proxy (visitor hashes differed by user agent only). `TRUSTED_PROXIES` lets those proxies set the visitor IP via `X-Forwarded-For` / `X-Real-IP`; Forge (Nginx + PHP-FPM) was not affected
- [ ] **Dashboard performance at scale** — 30-day dashboard ~17 s on a 2M-pageview benchmark; real copies of customer data in `data/` can serve as benchmark data
- [ ] **`PRAGMA synchronous = NORMAL`** - FULL today. In WAL mode NORMAL keeps the database intact on power loss (only the latest writes can be lost) and speeds up writes; measure with the 2M benchmark. Not OFF, which risks a corrupt database
- [x] **Pull-to-refresh keeps the view** - the installed app reloaded the last N days instead of a custom date range and left out the share token; it now goes through the same request as the dashboard

---

## Ideas

Things to explore later — not committed to.

- **API keys** — alternative to session auth for headless API access
- **Slack/Discord notifications** — daily summary via webhook
- **Aggregation tables** — daily rollups for faster queries at scale
- **Backup/restore CLI** — `php puls backup` / `php puls restore` for SQLite snapshots
- **Funnels** — visualize visitor paths (landing → page → conversion)
- **MySQL support** — optional driver via PDO adapter, SQLite remains default
- **Numeric aggregates on event data** — sum, mean and median of a numeric key (today: send buckets)
- **Internal-site mode** — for logged-in apps: hide referrers and campaigns, show use per week and per role
- **API pagination** — page past 1,000 rows in `expand`
- **Saved card across events** - one card for several events that share a group key (e.g. two events with `where`); sending one event is the workaround today
- **Respect GPC as an option** - `data-respect-gpc` on the script tag skips tracking when the browser sends Global Privacy Control. Off by default: Puls stores no personal data, GPC's legal weight concerns selling or sharing it, and Brave and DuckDuckGo send it by default, so honoring it everywhere would undercount. Do Not Track is effectively gone (W3C ended it, Safari removed it in 2019)
