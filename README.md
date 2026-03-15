# Puls

**One file. No cookies. Full picture.**

See your traffic. Respect their privacy.

Puls is a cookieless, lightweight analytics tool built with a single PHP file and SQLite. No frameworks, no dependencies, no build step. Drop it on any PHP host and go.

## Features

- **Cookieless** — GDPR-friendly by default, no consent banners needed
- **Lightweight** — ~15KB tracking script, single PHP file backend
- **Multi-site hub** — Track all your sites from one dashboard
- **Bot detection** — Separates real visitors from bots (AI crawlers, search engines, social, SEO tools)
- **Privacy-first** — Daily-rotating visitor hashes, no PII stored
- **Multi-user** — Bcrypt auth with per-user site access control
- **Zero dependencies** — PHP 8.1+ and SQLite, nothing else

## Quick Start

```bash
# 1. Clone
git clone <repo-url> puls
cd puls

# 2. Create your first user
php manage-users.php add admin

# 3. Point your web server to public/
# Or for local testing:
php -S localhost:8080 -t public

# 4. Add tracking to your site
```

```html
<script src="https://your-puls-domain/?js" data-site="my-site" defer></script>
```

## Bot Tracking (optional)

Add a tracking pixel to catch bots that don't execute JavaScript:

```html
<img src="https://your-puls-domain/?pixel&s=my-site&p=/current-path"
     alt="" width="1" height="1" style="position:absolute;opacity:0" />
```

## User Management

```bash
php manage-users.php add <username>                    # Full access
php manage-users.php add <username> --sites=site1,site2  # Restricted access
php manage-users.php remove <username>
php manage-users.php list
```

Users with no `--sites` flag can see all sites. Restricted users only see their assigned sites.

## Configuration

Edit `config.php`:

```php
return [
    'db_path' => __DIR__ . '/data/puls.sqlite',
    'users_file' => __DIR__ . '/users.json',
    'allowed_origins' => [],       // CORS: ['https://example.com']
    'session_lifetime' => 2592000, // 30 days
    'max_login_attempts' => 5,
    'lockout_minutes' => 15,
];
```

### CORS

If tracking scripts are loaded cross-origin, add the origins to `allowed_origins` in `config.php` or set the `PULS_ALLOWED_ORIGINS` environment variable (comma-separated).

## Deployment

### Requirements

- PHP 8.1+
- `pdo_sqlite` extension (included in most PHP installations)
- Write access to `data/` directory

### Nginx

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

### Shared Hosting

1. Upload all files via FTP/SSH
2. Point the domain to the `public/` directory
3. Run `php manage-users.php add admin` via SSH
4. Done

## Data Collected

**Pageviews:** path, referrer domain, browser, device type, daily visitor hash, UTM params, language

**Bots:** path, bot name, category (AI, Search engine, Social, SEO, Monitor), user agent

**Not collected:** IP addresses, cookies, personal data, fingerprints

## License

MIT
