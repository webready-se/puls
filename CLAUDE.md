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
| `GET /?logout` | Yes | End session |

Static file requests (anything with `.` in path) return 404 early to prevent routing conflicts (e.g. favicon.ico triggering CSRF token regeneration).

### Files

```text
public/index.php        — All backend logic (routing, tracking, API, auth, bot detection)
public/dashboard.html   — Self-contained dashboard (CSS + JS, no build step)
config.php              — Configuration (loads .env, auto-detects Forge paths)
.env.example            — Environment template (checked in)
.env                    — Environment config (gitignored, created by key:generate)
puls                    — CLI entrypoint (all management commands, interactive with readline)
users.json              — User credentials (bcrypt hashed, auto-created by CLI)
data/puls.sqlite        — SQLite database (auto-created, gitignored)
composer.json           — Dev dependencies (Pest)
phpunit.xml             — Test configuration (Pest)
tests/                  — Pest test suite (142 tests, unit + feature)
scripts/hooks/pre-push  — Git hook: runs Pest before allowing push
scripts/build-release.sh — Builds release zip with runtime files only
.claude/hooks/php-lint.sh — PostToolUse hook: syntax-checks PHP after edit
```

### Key Design Decisions

- **No framework** — pure PHP 8.2+, only requires `pdo_sqlite`
- **SQLite with WAL mode** — good concurrent read performance, single-file database
- **Session-based auth** — CSRF-protected, brute-force lockout, bcrypt passwords
- **Per-user site access** — users can be restricted to specific sites
- **Self-contained dashboard** — no build tools, no CDN, works offline after load
- **Privacy-first** — daily-rotating visitor hash (IP + UA + APP_KEY salt), no cookies, no PII stored
- **Forge auto-detection** — `resolve_path()` in config.php detects `/releases/\d+/` pattern and resolves to site root
- **Data quality** — normalize_path strips tracking params (fbclid, gclid, utm_*), normalize_referrer groups social domains, IDN decode

## Development

```bash
# Initial setup
php puls key:generate
php puls user:add admin

# Install dev dependencies + activate pre-push hook
composer install

# Run locally
php -S localhost:8080 -t public

# Run tests
./vendor/bin/pest

# Add user with site restriction
php puls user:add client --sites=their-site
```

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
| Frontend/dashboard JS | No — Pest can't test it |

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
   — if significant changes have accumulated, suggest a release

After pushing code, check if a release is warranted:
- Epic completed → suggest minor release
- Security or breaking bugfix → suggest patch release immediately
- 5+ commits since last tag → mention it proactively

Write a handoff when the user says "handoff", "bye", "done for today", or similar.

---

## Memory

When you learn something about how the user prefers to work
(not a project decision, but a personal workflow preference),
suggest saving it with /memory so it persists across sessions.
