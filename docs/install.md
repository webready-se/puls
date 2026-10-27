# Install and run Puls

Puls is one PHP file, a dashboard page and a CLI. Pick the way that fits your hosting.

- [Requirements](#requirements)
- [Download a release](#download-a-release) (any PHP host)
- [Shared hosting](#shared-hosting)
- [Nginx](#nginx) · [Apache](#apache) · [Laravel Forge](#laravel-forge)
- [Docker](#docker)
- [One-click deploy](#one-click-deploy) (Render, Railway, Fly.io)
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

> **Note:** server-side bot tracking (the Nginx mirror) is not available on Apache. Use the `<noscript>` [tracking pixel](integrations.md#bot-tracking-pixel-optional) instead.

## Laravel Forge

Puls detects Forge's release directories (zero-downtime deploys) and keeps `data/` and `users.json` in the site root, so they survive every deploy. No symlinks needed.

**First-time setup (SSH):**

```bash
cd /home/forge/puls.example.com/current
php puls user:add admin
```

**Deploy script (the default is fine):**

```bash
$CREATE_RELEASE()

cd $FORGE_RELEASE_DIRECTORY

$ACTIVATE_RELEASE()
```

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

## Upgrading

Replace the files with the ones from the new release and keep `data/`, `users.json` and `.env`. The database is migrated on the first request after the upgrade. Read the [changelog](../CHANGELOG.md) first: it says when something needs your attention.

## Development

```bash
git clone git@github.com:webready-se/puls.git
cd puls
php puls user:add admin
composer install        # dev dependencies + pre-push test hook
php -S localhost:8080 -t public
```

`./vendor/bin/pest` runs the tests and `node scripts/smoke.mjs` the dashboard smoke test.
