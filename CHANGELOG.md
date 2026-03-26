# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

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

[1.1.0]: https://github.com/webready-se/puls/releases/tag/v1.1.0
[1.0.0]: https://github.com/webready-se/puls/releases/tag/v1.0.0
