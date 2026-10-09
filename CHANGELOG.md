# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Goals and funnels for events that have not happened yet. The goal search offers the typed text as a new event goal (or page goal for `/paths`), and such goals show No hits yet. The funnel's Then field is free text with seen events as suggestions.
- Details button on breakdown cards opens the event drill-down inside the card, grouped on the card's key. It reopens in place after a refresh or period change.
- The smoke test covers these, keyboard activation of rows and a logged-in pass.

### Fixed

- Event, outbound and channel rows can be reached and opened from the keyboard (role=button, tabindex, Enter and Space, focus ring). The settings close button is labelled Close settings.

## [1.19.1] — 2026-10-09

### Fixed

- On a brand-new install the first request could fail: the fresh schema lacked the `app_versions` and `breakdowns` tables (added in 1.17.0 and 1.18.0 as migrations only), and migrations did not run until the second request. Migrations now always run after the schema is created, so a new database starts on the current version with every table.

### Changed

- Funnel query joins a per-visitor aggregate of the second event instead of running a correlated lookup per visitor. Same results (verified row for row on a 500,000-event database), but a 30-day funnel there went from 25 s to no measurable cost on top of the drill-down.

### Added

- Dashboard smoke test (`node scripts/smoke.mjs`): seeds a demo database with the old schema, serves it, drives headless Chrome over the DevTools protocol through load, release markers, breakdown cards, event drill-down, group by version, funnel, compare and the 90-day view, and fails on any JavaScript error or missing element. Runs in CI as its own job. No npm dependencies.

## [1.19.0] — 2026-10-09

### Security

- Users restricted to specific sites could add and remove goals on any site through `?goal_add` and `?goal_remove`. Both now return 403 for a site outside the user's access, using the same check as the breakdown endpoints.

### Changed

- Custom events are kept for 400 days instead of 90, so a season can be compared with the same season a year later. Set `EVENTS_RETENTION_DAYS` in `.env` to change it, or `0` to keep events forever. Existing installs keep more history from the next daily cleanup on; nothing already deleted comes back.

## [1.18.0] — 2026-10-09

### Added

- Saved breakdowns: "Save as card" in an event drill-down keeps that grouping on the dashboard as its own card with the top ten values, NEW badges and the count of new values. Click the title to rename it. Cards show on shared dashboards read-only. API: `breakdowns` in the dashboard response, `?breakdown_add` and `?breakdown_remove` (authenticated, checked against the user's site access).

### Changed

- Migration v18 adds a `breakdowns` table. `sites:rename` and `sites:remove` include it.
- The 60-second refresh skips a cycle while a card title is being edited.

## [1.17.0] — 2026-10-09

### Added

- `data-version` on the tracking script sends an app version with every pageview and event. The day a version is first seen is marked on the pageview chart and on event trends, and the event drill-down can group by version. API: `versions` (version, first_seen) in the dashboard and drill-down responses, and `@version` as a group key.

### Changed

- Migration v17 adds `app_version` to `pageviews` and `events` and a small `app_versions` table with the first-seen date per site and version.
- The funnel picker lists events by use, most used first, instead of alphabetically. A site with more than 200 event names no longer drops recent ones from the list.
- `sites:rename` and `sites:remove` include `app_versions` and skip tables a not-yet-migrated database does not have.

## [1.16.0] — 2026-10-09

### Added

- Two-step funnel in the event drill-down: pick a second event under "Then" to see how many visitors who did the first went on to the second the same day, with the rate, change vs previous period and a per-day bar. API: `?api&event=a&then=b` returns `funnel`, and the drill-down lists `eventNames` for the picker.

## [1.15.0] — 2026-10-09

### Added

- Goals can target an event name as well as a page path. The goal picker in the menu lists events under their own heading, and the Goals card marks event goals. Conversions count distinct visitors who triggered the event. Migration v16 adds a `type` column to `goals`.
- Event drill-down flags values that did not occur in the previous period with a `new` badge and shows how many are new. API: each `eventGroup` row carries `new`, plus `eventGroupNew`.
- CSV download in the event drill-down: every row in the period with `created_at`, `page_path` and one column per data key. API: `?api&event=<name>&format=csv`, also through share tokens.

### Changed

- The `form_submit` auto-event listens in the bubble phase and is skipped when the page called `preventDefault()`, so JavaScript-handled forms and search boxes are not counted as submissions. It now records a `form` field from `data-puls-event`, `name` or `id` on the form.
- Event drill-down requests (`?api&event=<name>`) no longer run every dashboard query. The response carries only what the panel needs: totals, daily visitors for the rate, and the event fields.
- Event drill-down chart for periods over 31 days drops per-bar values and shows about six date labels, so 90 days fit without horizontal scrolling. Hover still shows the detail per day.

### Fixed

- The 60-second dashboard refresh reset every card to its first tab and closed any open event drill-down. Tab selection and the open drill-down (including its group-by choice) now survive a refresh and a period change.

## [1.14.0] — 2026-10-09

### Added

- Event drill-down shows a day-by-day trend for the event, as count or per 100 visitors, with the previous period overlaid when Compare is on. Totals, unique visitors, rate and change versus the previous period sit above the chart.
- Group by any data key in the event drill-down. Keys are discovered from the event's recent rows and offered as chips; the most common key is selected on open. Each value is listed with count and unique visitors. The raw recent rows remain available under Recent.
- API: `?api&event=<name>` now returns `eventSeries`, `previousEventSeries`, `eventTotals`, `previousEventTotals`, `eventKeys`, `eventGroup` and `eventGroupKey`. `&group=<key>` picks the key, `&group=` disables grouping. Available through share tokens.

## [1.13.0] — 2026-10-09

### Added

- `data-debug` on the tracking script logs every pageview and event to the browser console as it is sent. Beacons are fire-and-forget, so this is the first way to see from the browser what Puls received.
- Queue stub for `puls.track` calls made before the script has loaded. Pages add `window.puls = window.puls || { q: [], track: function () { this.q.push(arguments) } }` and the script drains the queue on init. Documented in `docs/integrations.md`.
- Event naming and data conventions, TypeScript declaration and payload limits in `docs/integrations.md`.

### Fixed

- Event deduplication ignored `event_data`, so two events with the same name but different data from one visitor within 10 seconds kept only the first. A search box sending `search_miss` for two different queries lost the second. The dedup key now includes the data.
- Unique visitors per event were queried but never shown in the Events list.

## [1.12.0] — 2026-08-21

### Fixed

- Docker image built on PHP 8.2, below the 8.3 floor declared in `composer.json` and below every version covered by CI. It now ships PHP 8.4.
- `composer install` failed on PHP 8.3 even though 8.3 is the supported floor: `composer.lock` had been resolved on a newer PHP and pulled Symfony packages requiring 8.4+. Composer now resolves the lock against the floor via `config.platform.php`.

### Changed

- Site list query (`?api&sites`, hit on every dashboard load) no longer scans the whole `pageviews` table. A recursive loose index scan replaces `SELECT DISTINCT`, so cost follows the number of sites rather than the number of rows: 92 ms to 0.01 ms on a 2,000,000-row table. Behaviour is unchanged.
- Dependabot now watches GitHub Actions and the Composer dev dependencies.

## [1.11.0] — 2026-05-22

### Added

- Live visitors per site on the all-sites overview — a small green pulsing badge next to each site name, counting distinct visitors in the last 5 minutes. Only shown when > 0; refreshes with the existing 60 s dashboard reload (no extra polling).

### Fixed

- Compare overlay: "previous period" no longer renders 0 for the rightmost bar. The current period spanned `days + 1` calendar dates while previous spanned `days`, so index-aligned chart bars always mismatched on the last entry (most visible at 24h, where 1 of 2 bars was blank). Both periods now span exactly `days` dates.
- CMD+K search dialog: removed the rectangular keyboard-focus outline that the global `:focus-visible` rule drew inside the rounded dialog chrome.

## [1.10.2] — 2026-05-13

### Fixed

- Tracking script: defer initial beacon send via `requestIdleCallback` (with `setTimeout` fallback) so reading `innerWidth` no longer triggers a forced reflow on the critical path. PageSpeed previously attributed ~116 ms of layout time to this read.

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

[1.19.1]: https://github.com/webready-se/puls/releases/tag/v1.19.1
[1.19.0]: https://github.com/webready-se/puls/releases/tag/v1.19.0
[1.18.0]: https://github.com/webready-se/puls/releases/tag/v1.18.0
[1.17.0]: https://github.com/webready-se/puls/releases/tag/v1.17.0
[1.16.0]: https://github.com/webready-se/puls/releases/tag/v1.16.0
[1.15.0]: https://github.com/webready-se/puls/releases/tag/v1.15.0
[1.14.0]: https://github.com/webready-se/puls/releases/tag/v1.14.0
[1.13.0]: https://github.com/webready-se/puls/releases/tag/v1.13.0
[1.12.0]: https://github.com/webready-se/puls/releases/tag/v1.12.0
[1.11.0]: https://github.com/webready-se/puls/releases/tag/v1.11.0
[1.10.2]: https://github.com/webready-se/puls/releases/tag/v1.10.2
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
