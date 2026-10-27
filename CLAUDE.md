# CLAUDE.md

## Project Overview

**Puls** — Cookieless, lightweight analytics. One PHP file + SQLite. No frameworks, no dependencies.

- **Tagline:** "One file. No cookies. Full picture."
- **Subtitle:** "See your traffic. Respect their privacy."

## Architecture

Single PHP entry point (`public/index.php`) handles all routing:

| Endpoint | Auth | Purpose |
|----------|------|---------|
| `GET /?js` | No | Serves tracking JavaScript |
| `POST /?collect` | No | Receives pageview data (sendBeacon) |
| `POST /?event` | No | Receives custom event data (sendBeacon) |
| `GET /?pixel&s={site}&p={path}` | No | 1x1 GIF for bot detection |
| `GET /?log&s={site}&p={path}` | No | Server-side bot logging (Nginx mirror) |
| `GET /?status_log&s={site}&p={path}&status={code}` | No | Broken link/redirect tracking (Nginx post_action) |
| `GET /?health` | No | Health check (200/503) |
| `GET /?manifest` | No | PWA web app manifest |
| `GET /?csrf` | No | CSRF token refresh (AJAX) |
| `GET /?share=<token>` | No | Shared read-only dashboard |
| `GET /?api&share=<token>` | No | Shared JSON API (scoped to token's site); the token also works as `Authorization: Bearer <token>` |
| `GET /?api&days=7[&site=x]` | Yes | JSON API |
| `GET /?api&sites` | Yes | List tracked sites |
| `GET /` | Yes | Dashboard |
| `POST /?login` | No | Login form submission |
| `POST /?goal_add` | Yes | Add a conversion goal (page path or event name) |
| `POST /?goal_remove` | Yes | Remove a conversion goal |
| `POST /?goal_target` | Yes | Set a goal's target (count per day, week or month); 0 clears it |
| `POST /?breakdown_add` | Yes | Save or rename an event breakdown card |
| `POST /?breakdown_remove` | Yes | Remove a breakdown card |
| `POST /?breakdown_order` | Yes | Save the order of a site's breakdown cards (`ids` in display order) |
| `POST /?annotation_add` | Yes | Add a chart note on a date or date range |
| `POST /?annotation_update` | Yes | Change a chart note's text |
| `POST /?annotation_remove` | Yes | Remove a chart note |
| `GET /?excluded&site=x` | Yes | A site's excluded IPs (and the all-sites ones), plus the caller's own address and whether it is excluded |
| `POST /?exclude_add` | Yes | Exclude an IP or CIDR range on a site (`*` = all sites, only for users with every site) |
| `POST /?exclude_remove` | Yes | Count an excluded IP or range again |
| `GET /?logout` | Yes | End session |

Static file requests (anything with `.` in path) return 404 early to prevent routing conflicts (e.g. favicon.ico triggering CSRF token regeneration).

### Files

```text
public/index.php        — All backend logic (routing, tracking, API, auth, bot detection)
public/dashboard.html   — Self-contained dashboard (CSS + JS, no build step)
public/fonts/           - Figtree (variable woff2) and its SIL Open Font License, copied from the design kit
config.php              — Configuration (loads .env, auto-detects Forge paths)
.env.example            — Environment template (checked in)
.env                    - Optional environment config (gitignored, copy of .env.example)
puls                    — CLI entrypoint (all management commands, interactive with readline)
users.json              — User credentials (bcrypt hashed, auto-created by CLI)
data/puls.sqlite        — SQLite database (auto-created, gitignored)
composer.json           — Platform requirements (PHP 8.3+, pdo_sqlite) + dev dependencies (Pest)
phpunit.xml             — Test configuration (Pest)
tests/                  — Pest test suite (308 tests, unit + feature)
docs/integrations.md    - Tracking setup: script attributes, events, keeping personal data out of URLs, excluding own visits, reverse proxies
docs/api.md             - Read API reference (JSON/CSV, share tokens)
docs/install.md         - Install and run: release download, shared hosting, Nginx, Apache, Forge, Docker, upgrading
docs/configuration.md   - .env settings, users and access, share links, excluding own visits, white-label, CLI reference
scripts/hooks/pre-push  - Git hook: stops a push that sends a real name (see below), then runs Pest
scripts/hooks/pre-commit, commit-msg - Git hooks: stop a commit whose added lines or message name a real site, customer, server or person
scripts/hooks/real-names - Shared by the name hooks: prints lines that match .private/real-names.txt (gitignored; without it nothing is checked)
scripts/build-release.sh — Builds release zip with runtime files only
scripts/readme-media.mjs - Generates every README image, the drill-down GIF and the social preview (headless Chrome over DevTools protocol + demo DB, ffmpeg)
scripts/smoke.mjs       — Dashboard smoke test: headless Chrome over DevTools protocol, fails on JS errors (no npm deps)
scripts/seed-demo.php   — Seeds a demo SQLite database with realistic fake data
Dockerfile              — Alpine + PHP built-in server (~93MB image)
docker-entrypoint.sh    - Creates .env from .env.example, creates admin from ADMIN_PASSWORD env var
docker-compose.yml      — One-command setup with volume for /app/data
.dockerignore           — Excludes dev files from Docker build
.claude/hooks/php-lint.sh — PostToolUse hook: syntax-checks PHP after edit
.claude/hooks/no-real-names.sh - PreToolUse hook: stops a gh command whose text for GitHub names a real site, customer, server or person
```

**Sensitive files:** `users.json` (accounts, password hashes), `data/*.sqlite` (visitor data, the local copy may be production data) and `.env` are gitignored and must stay that way. Change users and data only through the `puls` CLI. Don't print their contents, query real visitor data, or overwrite or delete them without asking first. Use `data/demo.sqlite` (`scripts/seed-demo.php`) for demos and experiments.

### Key Design Decisions

- **No framework** — pure PHP 8.3+, only requires `pdo_sqlite`
- **SQLite with WAL mode** — good concurrent read performance, single-file database
- **Session-based auth** — CSRF-protected, brute-force lockout per IP (failed logins counted in `audit_log`), bcrypt passwords; a new session id every hour, the old one leading to it for a minute so requests in flight stay logged in; session files in `data/sessions` deleted once a day (PHP's own clean-up does not reach them on Forge)
- **Per-user site access** — users can be restricted to specific sites
- **Self-contained dashboard** — no build tools, no CDN, works offline after load
- **Dashboard islands** - `render()` wraps each section in `island(key, html)`, and `patchIslands()` replaces only the islands whose HTML changed since the last render of the same view, so the 60 s refresh leaves the rest of the DOM alone (hover, focus, open drill-downs, tabs, chart scroll). A new section goes through `island()` with a unique key; a list of cards is one `data-island-list` element with a `data-island` key per card
- **Privacy-first** - visitor hash of IP + UA + a random salt per day (`salts` table, deleted at the first request of the next day), so old hashes can never be recomputed; no cookies, no PII stored
- **Forge auto-detection** — `resolve_path()` in config.php detects `/releases/\d+/` pattern and resolves to site root
- **Sender owns the data** - Puls gives sites the controls (`data-query`, `data-path`, no PII in `puls.track`) and documents the risks, but does not inspect, mask or audit what they send. Masking was considered and declined: pattern matching would silently damage legitimate paths and give false confidence
- **Share tokens in plain text** - hashing them (as Hamstra does) was considered and declined: a token only reads one site's statistics, which live in the same database, so anyone with the database already has what the token protects. Plain text lets `share:list` show a link again, and needs no migration that the CLI (which opens the database without running migrations) would have to handle
- **Times without an offset** - a stored time is the wall clock of the install's time zone (`TIMEZONE`, else php.ini) with no offset, so days and hours group in SQL as they are. Use PHP's clock, never SQLite's `date('now')`, which is UTC. The dashboard reads a stored time as UTC and formats it as UTC, which keeps that wall clock whatever the viewer's zone, and counts "x min ago" from the API's `now`
- **Data quality** — normalize_path strips tracking params (fbclid, gclid, utm_*) and, for Meta ad traffic only, Meta's per-click brid (media_type is kept: it records the ad format); normalize_referrer groups social domains, IDN decode

## Design

This tool follows the Webready open source design kit
(https://github.com/webready-se/open-source-design-kit, cloned next to this repo).
Read its README.md first, then the doc for what you are changing. Copy values and
files from it; never link to it at runtime or install it. When the kit and this
tool disagree, the kit wins, unless this tool's docs say why it differs.

**Calm by default.** The dashboard opens on what most site owners need: the numbers, the chart, pages and sources. More detail is one click away, so a new card or panel usually opens on demand (a click on a day, a row or a button) and can be closed again, rather than being always on. Anyone who wants it always there is served by remembering that it is open, as Compare and the chart tab are remembered, without making it everyone's default. Why: Puls is for busy site owners who are not analytics experts, and every section that is always on pushes the rest further down, on a phone most of all.

Puls's copy of the kit's tokens is at the top of the `<style>` in `public/dashboard.html`, with the kit version it was copied from. Where Puls differs on purpose:

- **Strict CSP comes later.** The kit asks for `script-src 'self'` and `style-src 'self'`; Puls still has inline handlers and styles and gets there in steps (ROADMAP, Epic 3)
- **White-label.** `APP_ACCENT` sets `--accent-tile` (logo, bars, charts), and `--accent` (text, links, buttons) only where `accent_text_colours()` finds it readable, through `--brand-text` and `--brand-text-dark`
- **`--on-accent`** is Puls's own token for text on a filled `--accent` button: white in light mode, dark in dark mode, since the dark accent is light
- **Chart markers.** Releases are coral and notes teal, so the two can be told apart (the kit draws both as slate lines)

## Development

```bash
# Initial setup
php puls user:add admin

# Install dev dependencies + activate pre-push hook
composer install

# Run locally
php -S localhost:8080 -t public

# Run tests
./vendor/bin/pest

# Dashboard smoke test (needs Node 22+ and Chrome; runs in CI as its own job)
node scripts/smoke.mjs

# Add user with site restriction
php puls user:add client --sites=their-site
```

### Screenshots

All README images are generated from a seeded demo database:

```bash
node scripts/readme-media.mjs    # Requires: Node 22+, Chrome, ffmpeg
```

This seeds a temporary database with 30 days of fake data (`scripts/seed-demo.php`, sessions of one to five pages, nothing dated after now), adds goals with targets, saved cards, a release and a chart note, logs in and drives headless Chrome over the DevTools protocol. Every image is cut to a dashboard element (`[data-island=...]`), so layout changes do not shift the crops. The drill-down GIF is recorded frame by frame with a drawn pointer and encoded with ffmpeg.

Images in `docs/screenshots/`: `dashboard` and `dashboard-light` (README hero, picked by the reader's color scheme), `dashboard-compare`, `events`, `cards`, `bots`, `drill-down.gif`, and `social-preview` (1280x640, uploaded by hand in the repository settings: GitHub has no API for it).

### Pre-push hook

Tests run automatically before every `git push`. The hook is configured via `composer post-install-cmd` — any contributor gets it after `composer install`. No manual setup needed.

The git hooks and a Claude Code hook also keep real names (sites, customers, servers, people) out of commits, pushes and texts sent to GitHub, from a gitignored list in `.private/real-names.txt`: see `.claude/rules/no-real-names.md`. Without the list, as in a fresh clone, they check nothing.

## Multi-site Hub

Puls is designed as a central hub for multiple sites. Add `data-site="name"` on the tracking script to separate data. Users with empty `sites` array see all sites; restricted users only see their assigned sites.

## Conventions

- English UI, code, and comments
- No external dependencies at runtime — keep it deployable as a file drop
- 4-space indentation in PHP, 2-space in HTML/CSS/JS
- All user input is truncated/sanitized before storage
- Pest for testing (dev dependency only)

## Testing

Pest for tests. Two levels:

- **Unit** (`tests/Unit/`) — pure functions, CLI as subprocess
- **Feature** (`tests/Feature/`) — PHP dev server + HTTP requests

### When to write tests

| Change | Test? |
|--------|-------|
| New or changed API endpoint | Always |
| Bug fix | Always — write test first (TDD), verify it fails, then fix |
| CLI deterministic output (error messages, no-arg fallbacks) | Yes |
| Pure functions (normalize, detect, etc.) | Yes |
| Interactive STDIN prompts | No — manual testing |
| Frontend/dashboard JS | Smoke test — extend `scripts/smoke.mjs` when adding a dashboard feature |

### Principles

- Bug fix = test first. No exceptions.
- API tests are cheap (~5 lines with existing helpers). Write them.
- Don't test what you can't control (STDIN, browser JS).
- Pre-push hook enforces all tests pass before push.

---

## Roadmap

`ROADMAP.md` is the source of truth for what to build. Completed items stay — it's a historical document.

See `.claude/skills/roadmap/` for how Claude should interact with the roadmap.

---

## Session Continuity

On session start:
1. Check for uncommitted work: `git status` and `git stash list`
2. Read `ROADMAP.md` to understand current progress
3. Check for unreleased work: `git log $(git describe --tags --abbrev=0)..HEAD --oneline`
   — suggest a release only per the release policy below (batched, not per PR)
4. Check what others have sent: open issues (`gh issue list`), pull requests from others
   (`gh pr list --search '-author:@me'`) and private vulnerability reports
   (`gh api 'repos/webready-se/puls/security-advisories?state=triage'`). Mention each one
   and suggest a reply; a vulnerability report comes first (SECURITY.md promises a reply within 48 hours)

Merging to main deploys the production installs (Forge); that alone needs no release.
Tagged releases are for self-hosters and are batched, see `.claude/skills/release/`:
- Epic or coherent feature set completed → suggest a minor release
- Security, data loss, or broken install/upgrade fix → suggest a patch release immediately
- Tests, CI, docs, screenshots → never a release of their own
- `CHANGELOG.md` keeps exactly one `## [Unreleased]` section

Write a handoff when the user says "handoff", "bye", "done for today", or similar.
Handoffs go in `.claude/handoffs/` (gitignored), never `docs/handoffs/`: the repo is
public and handoffs name customers and sites. Do not create `docs/handoffs/`, since the
handoff command switches to it (shared, committed mode) whenever it exists.

---

## Memory

When you learn something about how the user prefers to work
(not a project decision, but a personal workflow preference),
suggest saving it with /memory so it persists across sessions.
