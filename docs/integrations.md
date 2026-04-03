# Integrations

Puls works with any website or framework that serves HTML. The tracking script is a single `<script>` tag — no npm packages, no build plugins.

## Quick Start (any site)

```html
<script defer src="https://your-puls-domain/?js" data-site="my-site" data-outbound></script>
```

Puls automatically tracks:

- Page views (including SPA client-side navigation via `pushState`/`popstate`)
- Referrers, UTM parameters, browsers, devices, languages

## Framework Examples

### Next.js

**Pages Router** (`_document.tsx`):

```tsx
import Document, { Head, Html, Main, NextScript } from 'next/document'

export default class MyDocument extends Document {
  render() {
    return (
      <Html>
        <Head />
        <body>
          <Main />
          <NextScript />
          <script defer src="https://your-puls-domain/?js" data-site="my-site" data-outbound />
        </body>
      </Html>
    )
  }
}
```

**App Router** (`app/layout.tsx`):

```tsx
import Script from 'next/script'

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html>
      <body>
        {children}
        <Script
          src="https://your-puls-domain/?js"
          data-site="my-site"
          data-outbound
          strategy="afterInteractive"
        />
      </body>
    </html>
  )
}
```

No custom route change listeners needed — Puls detects Next.js navigation automatically.

### Astro

In your base layout (`src/layouts/Layout.astro`):

```astro
<html>
  <head>
    <script defer src="https://your-puls-domain/?js" data-site="my-site" data-outbound></script>
  </head>
  <body>
    <slot />
  </body>
</html>
```

For static sites, you can also add a `<noscript>` pixel for visitors with JavaScript disabled:

```astro
---
const path = Astro.url.pathname
---
<noscript>
  <img src={`https://your-puls-domain/?pixel&s=my-site&p=${path}`}
       alt="" width="1" height="1" style="position:absolute;opacity:0" />
</noscript>
```

Astro renders the path server-side, so the pixel gets the correct page path.

### Laravel (Blade)

In your base layout (`resources/views/layouts/app.blade.php`):

```blade
<body>
    @yield('content')

    <script defer src="https://your-puls-domain/?js" data-site="my-site" data-outbound></script>

    @if(!request()->header('User-Agent') || str_contains(request()->header('User-Agent'), 'bot'))
        {{-- noscript pixel with server-side path --}}
    @endif
</body>
```

### Statamic

In your Antlers layout (`resources/views/layout.antlers.html`):

```antlers
<body>
    {{ template_content }}

    <script defer src="https://your-puls-domain/?js" data-site="my-site" data-outbound></script>
    <noscript>
        <img src="https://your-puls-domain/?pixel&s=my-site&p={{ url }}"
             alt="" width="1" height="1" style="position:absolute;opacity:0" />
    </noscript>
</body>
```

### React SPA (Vite / Create React App)

In `index.html`:

```html
<body>
  <div id="root"></div>
  <script defer src="https://your-puls-domain/?js" data-site="my-site" data-outbound></script>
</body>
```

Puls hooks into `pushState` and `popstate` automatically — no React Router integration needed.

### Static HTML

```html
<script defer src="https://your-puls-domain/?js" data-site="my-site" data-outbound></script>
```

That's it. Works on any HTML page.

## Custom Events

Track any interaction with `puls.track(name, data)`. Events appear in the dashboard under Traffic > Events.

```javascript
// Form submission
document.querySelector('form').addEventListener('submit', function() {
  puls.track('form_submit', { form: 'contact' });
});

// Phone number click
document.querySelector('a[href^="tel:"]').addEventListener('click', function() {
  puls.track('phone_click');
});

// File download
puls.track('download', { file: 'brochure.pdf' });

// Signup with plan info
puls.track('signup', { plan: 'pro' });

// CTA button click
document.getElementById('cta').addEventListener('click', function() {
  puls.track('cta_click', { location: 'hero' });
});
```

The `data` parameter is optional. When provided, it is stored as JSON and visible in the dashboard drill-down. Keep event names short and consistent — they are grouped by name.

## Auto Event Tracking

Add `data-auto-events` to the script tag to automatically track common interactions — no custom code needed:

```html
<script src="https://your-puls-domain/?js" data-site="my-site" data-outbound data-auto-events defer></script>
```

| Interaction | Event name | Data captured |
|---|---|---|
| Phone click (`tel:`) | `phone_click` | number, page |
| Email click (`mailto:`) | `email_click` | email, page |
| File download (PDF, DOC, XLS, ZIP, etc.) | `download` | file, url, page |
| Form submission (POST) | `form_submit` | action, page |

All events appear in the dashboard under Traffic > Events with full detail. No markup changes needed — Puls detects these interactions automatically via event delegation.

## Outbound Link Tracking

Add `data-outbound` to the script tag to auto-track clicks on external links:

```html
<script src="https://your-puls-domain/?js" data-site="my-site" data-outbound defer></script>
```

Clicks on links pointing to other domains are tracked as `outbound_click` events. Results appear in the dashboard under Traffic > Outbound, with drill-down showing which pages the clicks came from.

## Bot Tracking Pixel (optional)

Add a `<noscript>` pixel to catch bots and visitors that don't execute JavaScript:

```html
<noscript>
  <img
    src="https://your-puls-domain/?pixel&s=my-site&p=/current-path"
    alt=""
    width="1"
    height="1"
    style="position:absolute;opacity:0"
  />
</noscript>
```

For server-rendered frameworks (Astro, Laravel, Statamic), you can inject the current path dynamically — see the framework examples above.

## Behind a Reverse Proxy (Docker, Nginx, etc.)

If your app runs behind a reverse proxy (common with Docker, Node.js apps, or load balancers), make sure the proxy forwards the visitor's real IP. Without this, Puls sees the proxy's internal IP and counts all visitors as one.

Add these headers to your Nginx `proxy_pass` block:

```nginx
location / {
    proxy_pass http://127.0.0.1:3000;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

Puls reads `X-Forwarded-For` and `X-Real-IP` automatically.

## Server-side Bot Tracking (Nginx Mirror)

The JavaScript tracking script only runs in browsers. To also capture bots, crawlers, and automated clients, use Nginx's `mirror` directive to send a copy of each request to Puls:

```nginx
location / {
    mirror /_puls_log;
    # ...your existing proxy_pass or try_files
}

location = /_puls_log {
    internal;
    proxy_pass https://your-puls-domain/?log&s=my-site&p=$request_uri;
    proxy_set_header Host your-puls-domain;
}
```

This happens server-side with zero latency impact on your visitors. Puls deduplicates requests automatically.

## Zero-Downtime Deploys (Laravel Forge)

Puls auto-detects Forge's release directory structure (`/home/forge/site/releases/123/`). The database and user credentials are stored in the site root, not the release directory — so deploys don't lose data.

No symlinks or custom deploy scripts needed. The default Forge deploy script works out of the box:

```bash
$CREATE_RELEASE()
cd $FORGE_RELEASE_DIRECTORY
$ACTIVATE_RELEASE()
```

First-time setup:

```bash
cd /home/forge/your-site/current
php puls key:generate
php puls user:add admin
```

## Rate Limiting (Nginx)

If you set `ALLOWED_ORIGINS` in `.env`, Puls already rejects tracking data from unknown domains server-side. But as good practice, you can add Nginx rate limiting to protect against brute-force attempts on the login and excessive requests to the collect endpoint:

```nginx
# Outside server block
limit_req_zone $binary_remote_addr zone=puls_collect:10m rate=10r/s;
limit_req_zone $binary_remote_addr zone=puls_login:10m rate=5r/m;

# Inside server block
location / {
    # ...existing config
}

# Rate limit the collect endpoint
location = /index.php {
    limit_req zone=puls_collect burst=20 nodelay;
    # ...existing fastcgi config
}
```

For login protection, Puls already has built-in brute-force lockout (configurable via `MAX_LOGIN_ATTEMPTS` and `LOCKOUT_MINUTES` in `.env`).

## Content Security Policy (CSP)

If your site uses CSP headers, allow the Puls domain:

```text
script-src https://your-puls-domain;
connect-src https://your-puls-domain;
```
