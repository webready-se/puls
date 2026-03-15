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
| `GET /?pixel&s={site}&p={path}` | No | 1x1 GIF for bot detection |
| `GET /?api&days=7[&site=x]` | Yes | JSON API |
| `GET /?api&sites` | Yes | List tracked sites |
| `GET /` | Yes | Dashboard |
| `POST /?login` | No | Login form submission |
| `GET /?logout` | Yes | End session |

### Files

```
public/index.php      — All backend logic (routing, tracking, API, auth, bot detection)
public/dashboard.html  — Self-contained dashboard (CSS + JS, no build step)
config.php             — Configuration (loads .env)
.env.example           — Environment template (checked in)
.env                   — Environment config (gitignored, created by key:generate)
puls                   — CLI entrypoint (key:generate, user:add/remove/list)
users.json             — User credentials (bcrypt hashed, auto-created by CLI)
data/puls.sqlite       — SQLite database (auto-created, gitignored)
```

### Key Design Decisions

- **No framework** — pure PHP 8.1+, only requires `pdo_sqlite`
- **SQLite with WAL mode** — good concurrent read performance, single-file database
- **Session-based auth** — CSRF-protected, brute-force lockout, bcrypt passwords
- **Per-user site access** — users can be restricted to specific sites
- **Self-contained dashboard** — no build tools, no CDN, works offline after load
- **Privacy-first** — daily-rotating visitor hash (IP + UA + date salt), no cookies, no PII stored

## Development

```bash
# Initial setup
php puls key:generate
php puls user:add admin

# Run locally
php -S localhost:8080 -t public

# Add user with site restriction
php puls user:add client --sites=their-site

# List users
php puls user:list
```

## Multi-site Hub

Puls is designed as a central hub for multiple sites. Add `data-site="name"` on the tracking script to separate data. Users with empty `sites` array see all sites; restricted users only see their assigned sites.

## Conventions

- Swedish UI, English code/comments
- No external dependencies — keep it deployable as a file drop
- 4-space indentation in PHP, 2-space in HTML/CSS/JS
- All user input is truncated/sanitized before storage
