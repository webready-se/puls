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
- [x] Pest test suite (106 tests)
- [x] Pre-push hook — tests run automatically before every push
- [x] GitHub Actions CI (PHP 8.1–8.4)
- [x] Broken link tracking (404/301/other) via Nginx post_action
- [x] Data retention — auto-cleanup of old pageviews, bot visits, broken links
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

- [x] Data retention — auto-cleanup of old pageviews/bot_visits/broken_links
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
- [x] Guided UTM wizard — 3-step wizard, editable domain, auto-refresh pause

## Epic 8: Customer Value

- [ ] **Shareable dashboards** — token-based read-only links (share stats without login)
- [ ] **Data export** — CSV/JSON export from dashboard

> Shareable dashboards deliver the most customer value fastest — clients want to view stats without needing a login. A simple `/?share=<token>` showing a read-only dashboard.

## Epic 9: Deeper Insights

- [x] **Realtime view** — "N online now" badge + stat card (last 5 min) — already implemented
- [ ] **Custom events** — track button clicks, form submissions, downloads via `puls.track('event')` JS API
- [ ] **Outbound link tracking** — auto-track clicks on external links via JS click listener
- [ ] **Country/region stats** — based on Accept-Language, visual list or map
- [ ] **Comparison mode** — "This week vs last" as overlay in chart
- [ ] **Goals/conversions** — define target page (e.g. /thank-you), show conversion rate
- [ ] **Custom date range** — date picker beyond 7d/30d/90d
- [ ] **Summary cards** — "Best day: Tuesday", "Peak traffic: 2–3 PM"

## Epic 10: Release Management

- [x] **CHANGELOG.md** — retrospective for v1.0.0 covering all completed work
- [x] **Release build script** — `scripts/build-release.sh` creates a clean zip with only runtime files
- [x] **GitHub Action for releases** — triggers on tag push, builds zip, creates GitHub Release with changelog
- [x] **Claude skill `/release`** — prepare release: gather commits since last tag, suggest version, update CHANGELOG, create tag
- [ ] **Release validation in CI** — extract zip, run health check to verify it works
- [x] **README install instructions** — download badge + zip-based quick start (not just git clone)

## Epic 11: Adoption

- [ ] **Dockerfile** — Alpine + PHP built-in server (~30MB image), optional alternative to zip/git
- [ ] **One-click deploy buttons** — DigitalOcean, Railway, Render badges in README
- [ ] **White-label** — configurable branding (name, logo) via .env for customer installs

## Epic 12: Automation

- [ ] **Weekly report via email** — CLI command + cron for summaries, uses `mail()` — no dependencies
- [ ] **Webhooks** — HTTP callbacks on traffic spikes, new 404s, or threshold alerts
- [x] **Auto-refresh** — dashboard refreshes every 60 seconds (paused during UTM wizard)

---

## Ideas

Things to explore later — not committed to.

- **API keys** — alternative to session auth for headless API access
- **Slack/Discord notifications** — daily summary via webhook
- **Aggregation tables** — daily rollups for faster queries at scale
- **Backup/restore CLI** — `php puls backup` / `php puls restore` for SQLite snapshots
- **Funnels** — visualize visitor paths (landing → page → conversion)
- **MySQL support** — optional driver via PDO adapter, SQLite remains default
