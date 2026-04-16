# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.10.1] — 2026-04-16

### Fixed

- PWA icons (`icon-180.png`, `icon-192.png`, `icon-512.png`) regenerated in coral — previously still rendered in the old indigo default
- Favicon in `dashboard.html` had a hardcoded indigo fill — now coral (login page favicon already picked up the change via `APP_ACCENT`)

## [1.10.0] — 2026-04-16

### Changed

- Default accent palette switched from generic indigo (`#6366f1`) to Webready's brand colors: `#f16272` coral as primary, `#19acca` teal as secondary
- Dashboard chart bars, donut segments, and UTM wizard now use the coral/teal palette
- Snippet and UTM wizard boxes switched from deep indigo gradient to neutral near-black (`#1a1a1a` -> `#0a0a0a`) so coral accents pop without a competing tinted background
- Login page dark mode fallback updated to match new palette
- Semantic colors preserved: channel colors (Paid/Organic/Social/etc.) and bot category tags remain distinct since they carry information
- `APP_ACCENT` env var still overrides everything - white-label customers see no change

## [1.9.0] — 2026-04-16

### Changed

- Accessibility sweep continuing Epic 12 after the quick wins in 1.8.x
- Login form: `autocomplete="username"` / `"current-password"` attributes so password managers work, error banner gets `role="alert"` and is linked to inputs via `aria-describedby`
- Visible keyboard focus indicators: global `:focus-visible` rule shows an accent outline on any interactive element when reached via keyboard (mouse clicks unchanged)
- Date picker and goal search inputs gain an accent glow on focus to match other form fields
- Unlabeled inputs get `aria-label`: date range inputs, goal search, command palette
- Muted text darkened in light mode (`#64748b` → `#475569`) to meet WCAG AA contrast (4.25:1 → 7.49:1). Dark mode unchanged.

## [1.8.0] — 2026-04-06

### Added

- Docker support — Alpine + PHP built-in server image with auto-setup entrypoint (generates APP_KEY, creates admin from ADMIN_PASSWORD env var)
- White-label branding — APP_NAME, APP_TAGLINE, APP_ACCENT env vars customize login, dashboard, manifest, and favicon
- One-click deploy — Render (with deploy button), Railway, and Fly.io configs with persistent disk support
- docker-compose.yml for single-command local setup

### Changed

- Footer shows "Powered by Puls" when custom APP_NAME is set
- Logo icon box-shadow uses CSS color-mix for dynamic accent color support

## [1.7.0] — 2026-04-06

### Added

- Comparison mode — overlay previous period in chart with combined tooltip and % delta
- Automated screenshot pipeline — `bash scripts/screenshots.sh` generates all README screenshots from seeded demo data
- Dashboard supports URL hash params for state control (`#theme=dark&compare=1&tabs=...`)

### Changed

- README screenshots refreshed with 5 views: dashboard, compare, events+countries, bots, light mode
- README restructured with feature list, Why Puls, and Privacy sections

### Fixed

- Migration tracking now uses per-database `PRAGMA user_version` instead of shared file flag

## [1.6.0] — 2026-04-04

### Added

- Goals/conversions — set target pages and track conversion rate per goal
- Goal picker in hamburger menu — searchable page list for adding/removing goals
- Target icon on page rows with improved visibility (always slightly visible, not just on hover)
- Country/region stats — detect visitor country from Accept-Language header, new Countries tab with flag emojis
- Backfill migration (v15) populates country for existing data from unambiguous language codes
- Expanded language name mapping — Romanian, Albanian, Lithuanian, Ukrainian, Hindi, Croatian, Persian, Bulgarian, etc.

### Fixed

- Invalid UTF-8 bytes from scanner bots in broken_links caused empty API responses
- API now uses `JSON_INVALID_UTF8_SUBSTITUTE` to safely handle malformed data
- Sanitize invalid UTF-8 before storing in broken_links
- Added `/etc/passwd` to scanner noise path filter

## [1.5.0] — 2026-03-31

### Added

- Summary insight cards — Best day, Peak hour, Top page, Top source shown below stat cards
- Outbound link drill-down — click an outbound URL to see which pages the clicks came from
- Event drill-down for outbound uses `&event_url=` API parameter for URL-specific filtering

### Fixed

- Auto-events use capture phase to work with `stopPropagation` (JS-handled forms like fetch/preventDefault)
- Tracking script supports `data-*` attributes on dynamically injected scripts (querySelector fallback)

## [1.4.0] — 2026-03-31

### Added

- Custom date range picker — calendar icon opens from/to date selector, backend supports `&from=&to=` parameters
- Auto event tracking — `data-auto-events` attribute for zero-config tracking of phone clicks, email clicks, file downloads, and form submissions via event delegation
- Event drill-down — click an event name in the dashboard to see individual occurrences with data (number, email, action), page path, and timestamp
- `data-auto-events` documented in README with data captured table

### Changed

- All snippet examples now include `data-outbound` by default
- Expanded `puls.track` documentation with script attributes table and practical examples
- All API queries refactored to use `$dateFilter`/`$dateParams` for consistent date filtering
- Auto-events use capture phase to work with `stopPropagation` (JS-handled forms)

### Fixed

- Tracking script supports `data-*` attributes on dynamically injected script tags (querySelector fallback)
- Dark mode calendar picker icon now visible (CSS filter invert)

## [1.3.0] — 2026-03-30

### Added

- Site overview card — shows all sites with visitors, views, trend, and first seen date on All Sites view
- Persist site and period selection across page reloads via localStorage
- Puls logo is now clickable — returns to All Sites view
- Path-scoped docs-sync rule to keep README/CLAUDE.md up to date

### Changed

- Updated README.md and CLAUDE.md with current features, endpoints, and config options

## [1.2.0] — 2026-03-30

### Added

- Data export — CSV/JSON download from hamburger menu (client-side, respects site/period)
- `IGNORED_BOTS` env variable — exclude bot UA patterns from bot_visits tracking
- PHP syntax check hook — catches .php errors immediately after edit
- Proactive release checks in session continuity rules

### Changed

- broken_links table redesigned — UNIQUE on `(site, path, status)` instead of `(site, path, status, referrer)`, referrers aggregated into single comma-separated column
- Time format — "yesterday HH:MM" replaced with "X hr ago" (up to 48h)
- CLI uses `readline()` for interactive input (arrow keys, Ctrl+A, Home/End)
- `nginx:config` defaults to APP_URL when set
- Schema version 12 → 13
- Test suite: 142 tests (276 assertions)

### Fixed

- Mobile layout for bot activity — no more horizontal scroll, time always right-aligned
- `.env` parser strips double quotes from values
- `share:create` uses correct variable for expiry date

## [1.1.0] — 2026-03-27

### Added

- Custom events tracking — `puls.track('event', {data})` JS API
- Outbound link tracking — `data-outbound` attribute for external link clicks
- Events and Outbound tabs in dashboard Traffic card
- Shareable dashboards — token-based read-only links (`/?share=<token>`)
- CMD+K search in shared dashboards
- Interactive CLI — all commands work without arguments (pickers + prompts)
- `share:create` / `share:list` / `share:revoke` CLI commands
- Share API with site-scoped access
- CSRF token auto-refresh on idle login page
- Release validation smoke test in CI
- Testing strategy documented in CLAUDE.md

### Fixed

- Events API path filter uses correct column (`page_path` instead of `path`)
- Session persistence — dedicated session directory prevents premature GC
- Share page rendering with full URL display

### Changed

- Schema version 10 → 12 (share_tokens, events tables)
- Test suite expanded to 138 tests (266 assertions)
- CLI help text shows optional arguments with `[brackets]`

## [1.0.0] — 2026-03-26

First public release.

### Core

- Single PHP file backend — no frameworks, no runtime dependencies
- SQLite with WAL mode and automatic migrations (schema v10)
- Cookieless, privacy-first tracking with daily-rotating visitor hashes
- Session-based auth with CSRF protection and brute-force lockout
- Multi-user support with per-site access control
- CLI tool (`php puls`) for all management tasks
- Health check endpoint (`/?health`)
- CORS support with domain-based `ALLOWED_ORIGINS`
- Laravel Forge zero-downtime deploy auto-detection
- Apache and shared hosting support

### Tracking

- JavaScript tracker (~15KB) with `sendBeacon`
- Noscript tracking pixel for JS-disabled visitors
- Server-side bot tracking via Nginx mirror (`/?log`)
- Broken link tracking (404/301) via Nginx `post_action` (`/?status_log`)
- UTM campaign tracking (source, medium, campaign, term, content)
- Google Ads detection (gad_source, gad_campaignid — JS + server-side)
- Path normalization — strips fbclid, gclid, utm_*, gad_*, gbraid, wbraid, _gl, ved
- Referrer grouping — Facebook, Instagram, Twitter/X, Google, LinkedIn, etc.
- IDN/punycode decode on referrers and site names
- Self-referral filtering
- Bot detection — 25+ bots (AI crawlers, search engines, social, SEO, monitors)
- Deduplication — same bot + path + site within 10s is skipped

### Dashboard

- Charts, tables, and donut charts
- Multi-site hub with site selector
- Traffic channels — Paid / Campaign / Organic / Social / Referral / Direct
- Tabbed cards — Chart, Pages, Traffic, Visitors
- Overlay drill-down with "Show all" on all lists
- Trend indicators with previous period comparison
- Bounce rate and median session length
- Entry/exit pages
- Realtime view — "N online now" badge
- Bot activity timeline
- Broken links per status code with expand/collapse
- CMD+K command palette with page filtering
- Dark mode (System / Dark / Light)
- Guided UTM link wizard (3-step)
- PWA — installable, pull-to-refresh
- Auto-refresh every 60 seconds

### CLI

- `key:generate` — generate APP_KEY and create .env
- `user:add` / `user:edit` / `user:remove` / `user:list` — user management
- `sites:list` / `sites:rename` / `sites:remove` — site management
- `nginx:config` — interactive Nginx config generator

### Security

- Content-Security-Policy and security headers
- Parameterized SQL queries throughout
- APP_KEY validation at startup
- CSRF token invalidated after login
- Origin rejection returns 403
- Collect endpoint payload size limit (10 KB)
- Hourly session ID regeneration
- Audit log for login attempts
- Data retention — auto-cleanup of old pageviews, bot visits, broken links

### CI

- Pest test suite (108 tests, 200 assertions)
- GitHub Actions on PHP 8.3 / 8.4 / 8.5
- Pre-push hook — tests run before every push

[1.10.1]: https://github.com/webready-se/puls/releases/tag/v1.10.1
[1.10.0]: https://github.com/webready-se/puls/releases/tag/v1.10.0
[1.9.0]: https://github.com/webready-se/puls/releases/tag/v1.9.0
[1.8.0]: https://github.com/webready-se/puls/releases/tag/v1.8.0
[1.7.0]: https://github.com/webready-se/puls/releases/tag/v1.7.0
[1.6.0]: https://github.com/webready-se/puls/releases/tag/v1.6.0
[1.5.0]: https://github.com/webready-se/puls/releases/tag/v1.5.0
[1.4.0]: https://github.com/webready-se/puls/releases/tag/v1.4.0
[1.3.0]: https://github.com/webready-se/puls/releases/tag/v1.3.0
[1.2.0]: https://github.com/webready-se/puls/releases/tag/v1.2.0
[1.1.0]: https://github.com/webready-se/puls/releases/tag/v1.1.0
[1.0.0]: https://github.com/webready-se/puls/releases/tag/v1.0.0
