# Configuration and CLI

- [Settings (`.env`)](#settings-env)
- [Allowed origins](#allowed-origins)
- [Users and access](#users-and-access)
- [Share links](#share-links)
- [Excluding your own visits](#excluding-your-own-visits)
- [Time zone](#time-zone)
- [White-label](#white-label)
- [CLI reference](#cli-reference)

## Settings (`.env`)

Configuration is optional and lives in `.env` in the project root. Copy `.env.example` to `.env` and change what you need:

```env
APP_URL=https://puls.example.com  # Base URL (used in CLI for share links)
ALLOWED_ORIGINS=            # Allowed domains (comma-separated, includes subdomains)
DB_PATH=data/puls.sqlite    # Database path (relative to project root)
USERS_FILE=users.json       # User credentials path (relative to project root)
SESSION_LIFETIME=2592000    # 30 days
MAX_LOGIN_ATTEMPTS=5        # Failed logins from one IP before it is locked out
LOCKOUT_MINUTES=15          # Window for those failures, and how long the lockout lasts
IGNORED_BOTS=               # Bot UA patterns to exclude (comma-separated)
TRUSTED_PROXIES=            # Proxies in front of Puls (IPs/CIDR), see integrations.md
EVENTS_RETENTION_DAYS=400   # Days to keep custom events, 0 = forever
TIMEZONE=                   # Time zone for days and hours, e.g. Europe/Stockholm; empty = php.ini's
TIME_FORMAT=24h             # Clock times in the dashboard: 24h (14:00) or 12h (2 PM)
FOOTER_CREDIT=true          # "Made by Webready in Sweden" in the dashboard footer, false = off
```

`TRUSTED_PROXIES` matters when Puls runs behind a reverse proxy, Cloudflare or a load balancer: see [reverse proxies](integrations.md#behind-a-reverse-proxy-docker-cloudflare-load-balancers).

## Allowed origins

To restrict which sites can send tracking data, add their domains to `ALLOWED_ORIGINS`. Subdomains are included automatically:

```env
ALLOWED_ORIGINS=example.com,another-site.com
```

This allows tracking from `example.com`, `www.example.com`, `app.example.com` and so on. Leave it empty to allow all origins.

## Users and access

Users live in `users.json` (bcrypt password hashes) and are managed with the CLI. A user sees every site, or only the sites you give them:

```bash
php puls user:add admin                  # Full access
php puls user:add client --sites=a,b     # Only sites a and b
php puls user:edit client                # Change sites or password
```

Both ask for a password and suggest one: press Enter and Puls generates a strong password (four groups of five characters, easy to read out or type on a phone) and shows it once. Copy it then, since only its hash is stored. A password you type yourself needs at least 10 characters, and on a terminal you type it twice. Without a name, `user:add` asks for one.

## Share links

A share link shows one site's dashboard read-only, without a login, and also opens the [read API](api.md) for that site:

```bash
php puls share:create example.com --label="Client Q1" --expires=2026-06-30
```

## Excluding your own visits

Stop counting an office or a home connection by IP address or range, per site or for all sites (`*`):

```bash
php puls exclude:add example.com 81.2.3.0/24 --label="Office"
```

Or in the dashboard under **Settings**, **Excluded IPs**, which also shows your current address. Or per browser: open any tracked page with `#puls-ignore`, and `#puls-track` to count it again. Details in [integrations](integrations.md#excluding-your-own-visits).

## Time zone

Puls stores each visit with the time on its own clock, and that clock decides which day a visit counts on, the hour in Peak hour and when "Today" starts. Set it to where your visitors are:

```env
TIMEZONE=Europe/Stockholm
```

Without `TIMEZONE`, Puls uses PHP's time zone (`date.timezone` in php.ini), which is UTC on most servers and in the Docker image. For a site in Sweden that puts Peak hour and midnight one or two hours off. When the zone differs from the reader's own, the dashboard footer names it ("Stockholm time", "UTC"), so an install still on UTC shows. A name PHP does not know keeps PHP's zone and logs a warning, so a typo never stops the tracking.

Changing the zone on a running install applies to new visits only: stored times keep the clock they were written with, so for a while the hours and day boundaries before the change stay as they were. Nothing is lost, and it grows out of view as the periods move on.

`TIME_FORMAT=12h` shows clock times as 2 PM instead of 14:00. Puls does not follow the browser here: Chrome formats by its own language, not the computer's region, so English Chrome would show 2 PM to a reader who expects 14:00.

## White-label

Customize the branding in `.env`:

```env
APP_NAME=MyAnalytics
APP_TAGLINE=Your custom tagline
APP_ACCENT=#e11d48
```

This changes the login page, dashboard header, PWA manifest and favicon. `APP_ACCENT` colours the logo, the bars and the charts. Links and buttons use it only where it is readable as text (a contrast of 4.5:1 against the background, checked for light and dark mode separately); otherwise they keep Puls's red, so a pale brand colour never makes links hard to read. Under white-label the footer says "Powered by Puls". Without it, the footer says "Made by Webready in Sweden"; `FOOTER_CREDIT=false` turns that off.

## CLI reference

All management goes through `php puls`. Every command also works without arguments: pickers and prompts guide you through.

```bash
php puls user:add [<name>]               # Add user with full access
php puls user:add <name> --sites=a,b     # Add user restricted to specific sites
php puls user:edit <name>                # Edit user (sites, password)
php puls user:remove <name>              # Remove a user
php puls user:list                       # List all users
php puls sites:list                      # List all tracked sites
php puls sites:rename <old> <new>        # Rename a tracked site
php puls sites:remove <name>             # Delete all data for a site
php puls sites:clean-paths <site> --query --ids  # Clean stored paths (--keep=a,b, --tokens, --dry-run)
php puls share:create <site>             # Create a shareable dashboard link
php puls share:create <site> --label="Client Q1" --expires=2026-06-30
php puls share:list                      # List all share tokens
php puls share:revoke <token>            # Revoke a share token
php puls exclude:add <site> <ip>         # Stop counting an IP or range (e.g. 81.2.3.0/24, site * = all)
php puls exclude:list                    # List excluded IPs
php puls exclude:remove <site> <ip>      # Count an excluded IP again
php puls nginx:config                    # Generate Nginx config for a site
```
