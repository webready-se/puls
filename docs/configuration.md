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
BOT_RETENTION_DAYS=90       # Days to keep each bot visit, 0 = forever. The count per day and bot stays
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

A domain that is not in the list gets `403` on every pageview and event, and the site shows no error, so add a new site's domain before its script goes live. The [checklist for a new site](integrations.md#checklist-for-a-new-site-or-app) has a `curl` that tells whether a domain is let in.

## Users and access

Users live in `users.json` (bcrypt password hashes) and are managed with the CLI. A user sees every site, or only the sites you give them:

```bash
php puls user:add admin                  # Full access
php puls user:add client --sites=a,b     # Only sites a and b
php puls user:edit client                # Change sites or password
```

Both ask for a password and suggest one: press Enter and Puls generates a strong password (four groups of five characters, easy to read out or type on a phone) and shows it once. Copy it then, since only its hash is stored. A password you type yourself needs at least 10 characters, and on a terminal you type it twice. Without a name, `user:add` asks for one.

## Site groups

With many sites, put them in groups: the All sites overview, the site picker and ⌘K list them under their group, and the overview sums a group's visitors, views and trend. A click on a group's header folds it to that line (remembered in your browser), and a large group shows its five biggest sites with the rest behind "Show more". A site is in one group or none, and a group exists as long as a site points to it, so there is nothing to create or delete apart from the sites in it.

```bash
php puls sites:group Shops shop-a.example shop-b.example   # Put sites in a group (as many as you like)
php puls sites:ungroup shop-b.example                      # Take a site out of its group
php puls sites:list                                        # Sites under their group
```

Without arguments, `sites:group` offers the groups and the sites by number. A site keeps its group when renamed, and loses it when removed.

A name Puls has not seen a visit from is refused, since it is most often a typo. For a new site, open it once in a browser and run the command again, which also shows that the script is in place (a visit from an [excluded address](#excluding-your-own-visits) is not counted). To group sites before their first visit, as when setting up many at once, add `--new`: `sites:list` shows them under their group as "no visits yet" until one comes, and the dashboard lists them from then on.

## Share links

A share link shows a dashboard read-only, without a login, and also opens the [read API](api.md) for the same data. It covers one site, or the sites in one or more groups, with a site picker in the dashboard:

```bash
php puls share:create example.com --label="Client Q1" --expires=2026-06-30
php puls share:create --groups=Shops,Private --label="Reporting tool"
```

A link works for 90 days unless you give another last day, or `--expires=never`. See [share tokens](api.md#share-tokens) for how they work.

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

All management goes through `php puls`. Every command also works without arguments: pickers and prompts guide you through. A command that removes or revokes something asks first; `--yes` skips that question, for a script. An option a command does not take stops it before it does anything, with the options it takes (exit code 1), so a typo such as `--dry_run` never writes for real. An unknown command shows the help and exits with 1 as well.

```bash
php puls user:add [<name>]               # Add user with full access
php puls user:add <name> --sites=a,b     # Add user restricted to specific sites
php puls user:edit <name>                # Edit user (sites, password)
php puls user:remove <name>              # Remove a user
php puls user:list                       # List all users
php puls sites:list                      # List all tracked sites
php puls sites:rename <old> <new>        # Rename a tracked site (--merge: into a name that already has visits)
php puls sites:remove <name>             # Delete all data for a site (--yes: without typing the name)
php puls sites:group <group> <site>...   # Put sites in a group (one group per site; --new: before their first visit)
php puls sites:ungroup <site>...         # Take sites out of their group
php puls sites:clean-paths <site> --query --ids  # Clean stored paths (--keep=a,b, --tokens, --dry-run)
php puls share:create <site or group>    # Create a read token and dashboard link, for 90 days
php puls share:create --groups=a,b --label="Client Q1" --expires=2026-06-30   # Several groups, a label, a last day (or never)
php puls share:list                      # List tokens: what they read, last day, last use
php puls share:edit <token> --expires=2027-12-31   # New last day, --label (none removes it) or --groups; the token stays the same
php puls share:revoke <token>            # Revoke a share token
php puls bots:unknown [<site>] --days=30 # The user agents behind visits Puls has no name for
php puls posts:add --json < post.json    # Add a post to show on its day (one post as JSON on stdin)
php puls posts:list [<site>] --days=30   # The latest posts, newest first
php puls posts:remove [<id>] [--yes]     # Remove a post deleted on the platform or sent to the wrong site (without an id, pick from the latest)
php puls exclude:add <site> <ip>         # Stop counting an IP or range (e.g. 81.2.3.0/24, site * = all)
php puls exclude:list                    # List excluded IPs
php puls exclude:remove <site> <ip>      # Count an excluded IP again
php puls nginx:config                    # Generate Nginx config for a site
php puls stats:build                     # Sum every finished day into the dashboard's daily summary at once
php puls backup [<dir>] [--keep=7]       # A checked, compressed copy of the database, in data/backups/ by default (see install.md#backups)
```
