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
| `GET /?api&share=<token>` | No | Shared JSON API (scoped to token's site) |
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
config.php              — Configuration (loads .env, auto-detects Forge paths)
.env.example            — Environment template (checked in)
.env                    - Optional environment config (gitignored, copy of .env.example)
puls                    — CLI entrypoint (all management commands, interactive with readline)
users.json              — User credentials (bcrypt hashed, auto-created by CLI)
data/puls.sqlite        — SQLite database (auto-created, gitignored)
composer.json           — Platform requirements (PHP 8.3+, pdo_sqlite) + dev dependencies (Pest)
phpunit.xml             — Test configuration (Pest)
tests/                  — Pest test suite (260 tests, unit + feature)
docs/integrations.md    - Tracking setup: script attributes, events, keeping personal data out of URLs, excluding own visits, reverse proxies
docs/api.md             - Read API reference (JSON/CSV, share tokens)
docs/install.md         - Install and run: release download, shared hosting, Nginx, Apache, Forge, Docker, upgrading
docs/configuration.md   - .env settings, users and access, share links, excluding own visits, white-label, CLI reference
scripts/hooks/pre-push  — Git hook: runs Pest before allowing push
scripts/build-release.sh — Builds release zip with runtime files only
scripts/readme-media.mjs - Generates every README image, the drill-down GIF and the social preview (headless Chrome over DevTools protocol + demo DB, ffmpeg)
scripts/smoke.mjs       — Dashboard smoke test: headless Chrome over DevTools protocol, fails on JS errors (no npm deps)
scripts/seed-demo.php   — Seeds a demo SQLite database with realistic fake data
Dockerfile              — Alpine + PHP built-in server (~93MB image)
docker-entrypoint.sh    - Creates .env from .env.example, creates admin from ADMIN_PASSWORD env var
docker-compose.yml      — One-command setup with volume for /app/data
.dockerignore           — Excludes dev files from Docker build
.claude/hooks/php-lint.sh — PostToolUse hook: syntax-checks PHP after edit
```

**Sensitive files:** `users.json` (accounts, password hashes), `data/*.sqlite` (visitor data, the local copy may be production data) and `.env` are gitignored and must stay that way. Change users and data only through the `puls` CLI. Don't print their contents, query real visitor data, or overwrite or delete them without asking first. Use `data/demo.sqlite` (`scripts/seed-demo.php`) for demos and experiments.

### Key Design Decisions

- **No framework** — pure PHP 8.3+, only requires `pdo_sqlite`
- **SQLite with WAL mode** — good concurrent read performance, single-file database
- **Session-based auth** — CSRF-protected, brute-force lockout, bcrypt passwords
- **Per-user site access** — users can be restricted to specific sites
- **Self-contained dashboard** — no build tools, no CDN, works offline after load
- **Dashboard islands** - `render()` wraps each section in `island(key, html)`, and `patchIslands()` replaces only the islands whose HTML changed since the last render of the same view, so the 60 s refresh leaves the rest of the DOM alone (hover, focus, open drill-downs, tabs, chart scroll). A new section goes through `island()` with a unique key; a list of cards is one `data-island-list` element with a `data-island` key per card
- **Privacy-first** - visitor hash of IP + UA + a random salt per day (`salts` table, deleted at the first request of the next day), so old hashes can never be recomputed; no cookies, no PII stored
- **Forge auto-detection** — `resolve_path()` in config.php detects `/releases/\d+/` pattern and resolves to site root
- **Sender owns the data** - Puls gives sites the controls (`data-query`, `data-path`, no PII in `puls.track`) and documents the risks, but does not inspect, mask or audit what they send. Masking was considered and declined: pattern matching would silently damage legitimate paths and give false confidence
- **Data quality** — normalize_path strips tracking params (fbclid, gclid, utm_*) and, for Meta ad traffic only, Meta's per-click brid (media_type is kept: it records the ad format); normalize_referrer groups social domains, IDN decode

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

Merging to main deploys puls.wrlabs.se (Forge); that alone needs no release.
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
