# Configuration and CLI

- [Settings (`.env`)](#settings-env)
- [Allowed origins](#allowed-origins)
- [Users and access](#users-and-access)
- [Share links](#share-links)
- [Excluding your own visits](#excluding-your-own-visits)
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
MAX_LOGIN_ATTEMPTS=5
LOCKOUT_MINUTES=15
IGNORED_BOTS=               # Bot UA patterns to exclude (comma-separated)
TRUSTED_PROXIES=            # Proxies in front of Puls (IPs/CIDR), see integrations.md
EVENTS_RETENTION_DAYS=400   # Days to keep custom events, 0 = forever
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

## White-label

Customize the branding in `.env`:

```env
APP_NAME=MyAnalytics
APP_TAGLINE=Your custom tagline
APP_ACCENT=#e11d48
```

This changes the login page, dashboard header, PWA manifest and favicon. The footer always shows "Powered by Puls".

## CLI reference

All management goes through `php puls`. Every command also works without arguments: pickers and prompts guide you through.

```bash
php puls user:add <name>                 # Add user with full access
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
