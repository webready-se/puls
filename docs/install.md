# Install and run Puls

Puls is one PHP file, a dashboard page and a CLI. Pick the way that fits your hosting.

- [Requirements](#requirements)
- [Download a release](#download-a-release) (any PHP host)
- [Shared hosting](#shared-hosting)
- [Nginx](#nginx) · [Apache](#apache) · [Laravel Forge](#laravel-forge)
- [Docker](#docker)
- [One-click deploy](#one-click-deploy) (Render, Railway, Fly.io)
- [Backups](#backups)
- [Upgrading](#upgrading)
- [Development](#development)

When it runs, [add the tracking script](integrations.md) to your sites and set up [users and configuration](configuration.md).

## Requirements

- PHP 8.3 or newer (tested on 8.3, 8.4 and 8.5)
- The `pdo_sqlite` extension (included in most PHP installs)
- Write access to the `data/` directory

The Docker image bundles its own PHP, so the host needs nothing but Docker.

## Download a release

Grab the zip from the [latest release](https://github.com/webready-se/puls/releases/latest) and extract it:

```bash
unzip puls-*.zip
cd puls-*/
php puls user:add admin
php -S localhost:8080 -t public   # try it locally
```

On a server, point the domain to the `public/` directory. Everything else (`puls`, `config.php`, `data/`, `users.json`, `.env`) stays outside the web root.

## Shared hosting

**With SSH access:**

1. Upload all files via FTP or SSH
2. Point the domain to the `public/` directory
3. Run `php puls user:add admin`

**Without SSH access:**

1. Run `php puls user:add admin` on your own computer
2. Upload all files, including `users.json` (and `.env` if you created one)
3. Point the domain to the `public/` directory

> **Important:** keep `.env` and `users.json` in the project root, never inside `public/`. They hold your settings and password hashes.

## Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name puls.example.com;
    root /var/www/puls/public;
    index index.php;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

With Nginx, Puls can also log bot visits and broken links on the server, without the tracking script: see [server-side bot tracking](integrations.md#server-side-bot-tracking-nginx-mirror). `php puls nginx:config` generates the config for a site.

## Apache

Add a `.htaccess` file in the `public/` directory:

```apache
RewriteEngine On

# Pass the Authorization header on to PHP, for a Bearer token on the read API
RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L,QSA]
```

Make sure `mod_rewrite` is enabled (`a2enmod rewrite`) and that the virtual host allows overrides:

```apache
<Directory /var/www/puls/public>
    AllowOverride All
</Directory>
```

> **Note:** server-side bot tracking (the Nginx mirror) is not available on Apache. The `<noscript>` [tracking pixel](integrations.md#bot-tracking-pixel-optional) is the alternative, but it sees only crawlers that load images, and most fetch the HTML alone, so Bots shows far fewer visits than the mirror would.

## Laravel Forge

Puls detects Forge's release directories (zero-downtime deploys) and keeps `data/` and `users.json` in the site root, so they survive every deploy. No symlinks needed.

**First-time setup (SSH):**

```bash
cd /home/forge/puls.example.com/current
php puls user:add admin
```

**Run the CLI from `current`.** The file is in the release, and `current` points at the live one, so `php puls` in the site's root says "Could not open input file". A shell left in an old release runs old code against the live database, so Puls warns when it runs from one. To run it from anywhere in the site, add this to `~/.bashrc` on the server, and type `puls` instead of `php puls`:

```bash
puls() {
    local dir=$PWD
    while [ "$dir" != "/" ] && [ ! -L "$dir/current" ]; do dir=$(dirname "$dir"); done
    if [ -L "$dir/current" ]; then (cd "$dir/current" && php puls "$@"); else php puls "$@"; fi
}
```

**Deploy script (the default is fine):**

```bash
$CREATE_RELEASE()

cd $FORGE_RELEASE_DIRECTORY

$ACTIVATE_RELEASE()
```

**OPTIONS requests.** Forge's default site config routes with `try_files $uri $uri/ /index.php?$query_string`. For `/` the `$uri/` branch matches the document root as a directory, and Nginx answers an `OPTIONS` request there with 405 before PHP runs. The tracking script is not affected (`sendBeacon` never preflights), but a `fetch()` or XHR to `?collect` or `?event` with a JSON content type is: its preflight gets 405 instead of Puls's CORS answer. Edit the site's Nginx config in Forge and drop `$uri/`, as the config above has it:

```nginx
location / {
    try_files $uri /index.php?$query_string;
}
```

**Forge's frame header.** Forge's default Nginx configuration adds `X-Frame-Options "SAMEORIGIN"`, `X-XSS-Protection` and `X-Content-Type-Options "nosniff"` to every answer, on top of what Puls sends, so `X-Frame-Options` goes out as both `DENY` (Puls) and `SAMEORIGIN` (Forge). It does no harm, since `frame-ancestors 'none'` in Puls's CSP overrules both, but a header check flags it, and nobody can tell from the answer who adds which.

In the site's `…` menu, choose **Edit Nginx configuration** and the file **General site configuration**. Remove the lines for `X-Frame-Options` and `X-XSS-Protection`, which is obsolete, and keep `X-Content-Type-Options`: it is where the files Nginx serves itself get theirs. On the server that file is `/etc/nginx/forge-conf/<site id>/site.conf`. `sites-available/<site>` holds only `include` lines, so searching there finds nothing.

## Docker

```bash
git clone https://github.com/webready-se/puls.git
cd puls
docker compose up
```

Open `http://localhost:8080` and log in as `admin` with the password from `ADMIN_PASSWORD` in `docker-compose.yml` (`changeme`: change it before you expose the port). The database lives in the `puls-data` volume.

Without Compose:

```bash
docker build -t puls https://github.com/webready-se/puls.git
docker run -p 8080:8080 -e ADMIN_PASSWORD=changeme -v puls-data:/app/data puls
```

Behind a reverse proxy, set `TRUSTED_PROXIES` so visitors are told apart by their own address: see [reverse proxies](integrations.md#behind-a-reverse-proxy-docker-cloudflare-load-balancers).

The container runs on UTC. Add `-e TIMEZONE=Europe/Stockholm` (or your zone) so days and hours follow your visitors: see [time zone](configuration.md#time-zone).

## One-click deploy

[![Deploy to Render](https://render.com/images/deploy-to-render-button.svg)](https://render.com/deploy?repo=https://github.com/webready-se/puls)

The repository also has configs for [Railway](https://railway.app) (`railway.toml`) and [Fly.io](https://fly.io) (`fly.toml`). SQLite needs a disk that survives restarts, which these platforms offer on paid plans: on a free instance without one, the data is lost on every redeploy.

## Backups

Everything Puls knows is in the database (`data/puls.sqlite`), with `users.json` and `.env` beside it, which only change when you add a user or change a setting: keep a copy of them then.

`php puls backup` writes a copy of the database to `backups/` next to it (`data/backups/` by default), checks it, compresses it with gzip and keeps the newest 7 (`--keep=N`). A database of visits shrinks about four times. Without PHP's zlib the copy is kept as it is, uncompressed. It reads one moment while tracking goes on writing, so it can run at any time. Only the owner can read the copies, since they hold every site's history. Run it once a day from cron. On Forge, under Scheduler, as the forge user, daily, with the PHP version the site uses:

```text
php8.4 /home/forge/<site>/current/puls backup
```

A name for the job, to tell it apart from the runs:

```text
Puls: nightly backup
```

In Docker, `docker compose exec puls php puls backup` writes them to the `puls-data` volume.

### Off the server

The copies are on the same server as the database, so they help against a broken database or a bad update, not against losing the server. To have them then too, copy them somewhere else after each backup with [rclone](https://rclone.org/). It speaks to Dropbox, Google Drive, S3, DigitalOcean Spaces, Backblaze B2 and many more, so storage you already pay for will often do. rclone is one file, which can live in `~/bin` without root.

Set it up once with `rclone config`, as the user the job runs as, and name the remote `backups`. With Dropbox, first create an app of your own at [dropbox.com/developers/apps](https://www.dropbox.com/developers/apps), with access to an App folder only, and give `rclone config` its key and secret: the server then reaches that one folder (`Apps/<app name>/`), not the whole account, as it would with rclone's own app. The server has no browser, so `rclone config` asks you to run `rclone authorize "dropbox" "<key>" "<secret>"` on your own computer and paste what it prints. The Dropbox account that approves it in the browser is the one the backups go to. Several tools can share the remote, each in a folder of its own.

Then let the backup job copy them right after it, and remove the ones older than 30 days there. Give rclone's full path, since cron's `PATH` is short:

```text
php8.4 /home/forge/<site>/current/puls backup && /home/forge/bin/rclone copy /home/forge/<site>/data/backups backups:puls --include 'puls-*' && /home/forge/bin/rclone delete --min-age 30d backups:puls
```

`rclone copy` never removes anything there, so the copies off the server outlive the 7 on it, and `--include 'puls-*'` leaves out a backup still being written. Check it once with `rclone ls backups:puls`.

### Restoring

To restore one, first stop everything that writes to the database: the Scheduler jobs, and the site itself, since Puls writes on every tracking request. A process still open could write to the old file, or a new one pick up an old `-wal`. Where PHP serves other sites too, as on Forge, let Nginx answer the Puls site with 503 meanwhile (a `return 503;` first in its `location /`): visits in that time are not counted. Then replace the database with the copy and remove `data/puls.sqlite-wal` and `data/puls.sqlite-shm`, as the user PHP runs as, so the database stays its own:

```bash
gunzip -c data/backups/puls-2026-11-12-030000.sqlite.gz > data/puls.sqlite
chmod 600 data/puls.sqlite
rm -f data/puls.sqlite-wal data/puls.sqlite-shm
```

A copy from off the server comes back first with `rclone copy backups:puls/puls-2026-11-12-030000.sqlite.gz data/backups/`. One that ends in `.sqlite` (without zlib) is copied as it is, with `cp`. Then start them again.

## Upgrading

Replace the files with the ones from the new release and keep `data/`, `users.json` and `.env`. The database is migrated on the first request after the upgrade. Read the [changelog](../CHANGELOG.md) first: it says when something needs your attention.

Releases are gathered, so an install is not asked to upgrade every few days: a minor release about every one or two weeks, holding the features and fixes since the last one, and a patch release at once for a security fix, data loss or a broken install or upgrade. To hear about them, watch the repository on GitHub for releases only (Watch, Custom, Releases).

## Development

```bash
git clone git@github.com:webready-se/puls.git
cd puls
php puls user:add admin
composer install        # dev dependencies + pre-push test hook
php -S localhost:8080 -t public
```

`./vendor/bin/pest` runs the tests and `node scripts/smoke.mjs` the dashboard smoke test.
