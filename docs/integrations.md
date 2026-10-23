# Integrations

Puls works with any website or framework that serves HTML. The tracking script is a single `<script>` tag — no npm packages, no build plugins.

## Quick Start (any site)

```html
<script defer src="https://your-puls-domain/?js" data-site="my-site" data-outbound></script>
```

Puls automatically tracks:

- Page views (including SPA client-side navigation via `pushState`/`popstate`)
- Referrers, UTM parameters, browsers, devices, languages

### Script attributes

| Attribute | What it does |
|-----------|--------------|
| `data-site="name"` | Site name. Separates data in a multi-site setup. Defaults to the hostname |
| `data-outbound` | Tracks clicks on external links as `outbound_click` ([more](#outbound-link-tracking)) |
| `data-auto-events` | Tracks phone and email clicks, downloads and form submissions ([more](#auto-event-tracking)) |
| `data-version="2.6.0"` | App version on every pageview and event, shown as release markers ([more](#app-version)) |
| `data-query="none"` | Query strings to send: `none` drops them, `page,tab` keeps only those parameters ([more](#keeping-personal-data-out-of-urls)) |
| `data-collapse-ids` | Sends numeric path segments as `:id`, so `/lead/1234` becomes `/lead/:id` ([more](#keeping-personal-data-out-of-urls)) |
| `data-collapse-tokens` | Sends token segments (cuid, UUID and similar ids) as `:token`, so `/movement/ckx3abc...` becomes `/movement/:token` ([more](#keeping-personal-data-out-of-urls)) |
| `data-path="/:token"` | Path to send instead of the page URL ([more](#keeping-personal-data-out-of-urls)) |
| `data-debug` | Logs every pageview and event to the console ([more](#debug-mode)) |

### Checklist for a new site or app

1. Add the script tag with `data-site`. The site appears in Puls as soon as its first pageview arrives.
2. Look at your URLs. If any carry search terms, filters with names, record ids or personal links, add `data-query`, `data-collapse-ids`, `data-collapse-tokens` or `data-path` before going live (see below).
3. If you deploy with versions, add `data-version` so releases show on the charts.
4. Add `puls.track` calls for the actions that matter (signups, searches, form results), following the [naming and data conventions](#naming-and-data-conventions).
5. Create users or share links with the CLI: `php puls user:add client --sites=my-site` or `php puls share:create my-site`. A share link also gives read-only [API access](api.md).

## Keeping personal data out of URLs

Puls stores what your site sends. It does not inspect or mask data, so keeping personal data out is the sending site's job, and the script gives you the controls for it. Tracking parameters (`utm_*`, `gclid`, `fbclid` and similar) are always stripped from paths.

**Query strings.** By default the full query string is sent with each pageview. If your URLs carry search terms, filters or anything typed by a user, drop them:

```html
<!-- Drop every query string -->
<script defer src="https://your-puls-domain/?js" data-site="my-app" data-query="none"></script>

<!-- Keep only page and tab, drop the rest -->
<script defer src="https://your-puls-domain/?js" data-site="my-app" data-query="page,tab"></script>
```

The filtering happens in the browser, so the dropped values never reach Puls. Campaign detection still works: UTM and Google Ads parameters are read before the query string is dropped.

**Record ids.** Apps with ids in their URLs get one Top pages row per record. `data-collapse-ids` sends purely numeric segments as `:id`, so `/lead/1234/edit` becomes `/lead/:id/edit`. Segments that only contain digits as part of a word, such as `/2026-report`, are left alone.

**Token ids.** Apps that use cuids, UUIDs or similar generated ids get the same one-row-per-record problem. `data-collapse-tokens` sends such segments as `:token`, so `/movement/ckx3abc9def0ghi1jkl2mno3p` becomes `/movement/:token`. A token is a whole segment of 16 or more letters and digits containing both, or a UUID: the same rule as `sites:clean-paths --tokens`. Words and hyphenated slugs are left alone. It works in single-page apps, since every view is checked, and can be combined with `data-collapse-ids`.

**Personal links.** Some pages are reached through a link that is itself a key, such as `/{token}` for a customer's claim or a password reset. `data-path` replaces the path Puls receives, for pageviews and events. Set it on those pages only:

```blade
{{-- Laravel: a page reached through a personal link --}}
<script defer src="https://your-puls-domain/?js" data-site="claims" data-path="/:token"></script>
```

`data-path` is fixed for the page. In a single-page app it would be sent for every view, so use it on server-rendered pages, and use `data-collapse-tokens` in single-page apps. If you also use the `<noscript>` pixel, send the same value there (`p=/:token`), since the pixel does not run the script.

**Data already stored.** If a site has sent this kind of data before, clean it with the CLI. It works per site and always shows the row counts first with `--dry-run`:

```bash
php puls sites:clean-paths my-app --query --ids --tokens --dry-run
php puls sites:clean-paths my-app --query --ids --tokens
```

`--query` drops query strings (`--keep=page,tab` keeps those parameters), `--ids` collapses numeric segments and `--tokens` replaces token segments (16 or more letters and digits containing both, or a UUID) with `:token`. Take a backup of the database first: the rewrite cannot be undone.

**Event data.** The same rule applies to `puls.track`: send stable ids such as a department or a page type, never names, email addresses, phone numbers or user ids.

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

Track any interaction with `puls.track(name, data)`. Events appear in the dashboard under Traffic > Events. Click an event to open its drill-down:

- **Trend** — one bar per day for the selected period, as count or per 100 visitors, with the previous period overlaid when Compare is on.
- **Group by** — one chip per key found in the event's data. Pick `query` on a `search_miss` event and you get every searched value with count and unique visitors. The most common key is selected on open. **Recent** shows the raw rows instead.
- **New values** — in a grouping, values that did not occur in the previous period carry a `new` badge, and the count of new values is shown next to the chips. "New searches with no result this week" is one click.
- **Totals** — events, visitors, rate per 100 visitors and the change versus the previous period.
- **CSV** — every row in the period with `created_at`, `page_path` and one column per data key.

**Save as card** keeps a grouping on the dashboard: with an event grouped on a key, click **Save as card** and it appears as its own card under Goals, with the top ten values, NEW badges and how many values are new. Click **Details** on a card to open the full drill-down (trend, funnel, CSV) inside it. Click the card title to rename it, for example "Missed searches" for `search_miss` grouped by `query`. Cards follow the selected period and also show on shared dashboards, read-only. API: `breakdowns` in the dashboard response.

Any event can also be a **goal**: open the menu, search under Goals, and pick the event name from the Events section. The Goals card then shows how many visitors triggered it and the conversion rate, next to page goals. This is the way to measure a conversion that has no thank-you page, such as `feedback_sent` or `pwa_install` in a single-page app. An event does not have to have happened yet: type its name in the goal search and pick **Add event goal**, and it shows No hits yet until the first one arrives. The funnel's **Then** field takes any event name the same way.

The same data is available as JSON: `?api&days=30&event=search_miss&group=query` returns `eventSeries`, `previousEventSeries`, `eventTotals`, `eventKeys`, `eventGroup` (each value with `count`, `visitors`, `new`) and `eventGroupNew`. Add `&format=csv` for the CSV instead. Both work with `&share=<token>` for read-only access, so a script can pull missed searches straight into a backlog:

```bash
curl -s 'https://your-puls-domain/?api&share=TOKEN&days=7&event=search_miss&format=csv' > missed-searches.csv
```

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

The `data` parameter is optional. When provided, it is stored as JSON and visible in the dashboard drill-down.

### Naming and data conventions

- **Event names:** `snake_case`, past tense verb, max 100 characters. `feedback_sent`, `plant_added`, `search_miss` — not `sendFeedback` or `Plant Added`. Events are grouped by exact name, so keep them consistent.
- **Data keys:** short and stable (`query`, `crop`, `zone`), so the same key can be grouped across events over time.
- **Data values:** short strings, numbers or booleans. Trim and lowercase free text before sending (a search query, for example) so the same value is counted once.
- **No personal data.** Never send names, email addresses, phone numbers, user IDs or anything else that identifies a person. Puls stores no PII and events should not become the exception.
- **Limits:** `event_data` is stored as JSON and silently truncated at 1,000 characters. Keep payloads small.
- **Deduplication:** the same event name with the same data from the same visitor within 10 seconds is stored once. Different data within the window counts as separate events, so `search_miss {query: "kale"}` followed by `search_miss {query: "rhubarb"}` records both.

### Calling `puls.track` before the script has loaded

The script is loaded with `defer`, so `window.puls` does not exist until the page has parsed. Single-page apps often fire events earlier than that (reading settings from `localStorage` on first render, PWA launch). Add this stub before any code that tracks, and the script drains the queue when it initialises:

```html
<script>window.puls = window.puls || { q: [], track: function () { this.q.push(arguments) } }</script>
<script src="https://your-puls-domain/?js" data-site="my-site" defer></script>
```

Queued events are sent in order with the same `site` and `page_path` the live call would have used.

### Debug mode

Add `data-debug` to the script tag to log every pageview and event to the browser console as it is sent:

```html
<script src="https://your-puls-domain/?js" data-site="my-site" data-debug defer></script>
```

```text
[puls] pageview /guides/carrot
[puls] draining 2 queued event(s)
[puls] event zone_set {zone: 4}
```

Beacons are fire-and-forget, so this is the only way to see from the browser what Puls received. Remove the attribute in production.

### App version

Add `data-version` to the script tag and every pageview and event carries it:

```html
<script src="https://your-puls-domain/?js" data-site="my-site" data-version="2.6.0" defer></script>
```

The day a version is first seen shows as a marker on the pageview chart and on each event's trend, so "before and after 2.6.0" is visible without looking up release dates. In an event drill-down, **Group by version** splits counts per version. Versions are cleaned to letters, digits and `. - + _`, max 50 characters. With Vite, `data-version="%npm_package_version%"` or an injected `import.meta.env` value keeps it in sync with `package.json`.

### Chart notes

Some spikes have no source in the data: a film shown in the Facebook or Instagram app sends no referrer, so its visitors land under Direct and Social. Click **+ Note** on the pageview chart to mark the date, with an optional end date for something that runs over several days, such as a campaign week. A single day shows as a line with its text, a range as a tinted band. Notes are per site, show on event trends too, and appear on shared dashboards, read-only. Change the text or remove a note under **Settings**. API: `annotations` in the dashboard response, `[{id, site, date, end_date, label}]`, every note that overlaps the period.

### TypeScript

Puls ships no npm package. Declare the global once in your project:

```typescript
// puls.d.ts
interface Puls {
  track(name: string, data?: Record<string, string | number | boolean>): void;
}

declare global {
  interface Window {
    puls?: Puls;
  }
}

export {};
```

Then call it as `window.puls?.track('plant_added', { crop: 'carrot' })`. The optional chaining keeps the call safe if the script is blocked; combine it with the queue stub above if you need early events to survive.

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
| Form submission | `form_submit` | form, action, page |

All events appear in the dashboard under Traffic > Events with full detail. No markup changes needed — Puls detects these interactions automatically via event delegation.

`form_submit` is skipped when the page called `preventDefault()` on the submit, so a search box or a form handled in JavaScript is not counted as a submission. The `form` field comes from `data-puls-event` on the form, falling back to its `name` or `id`, so several forms on one site can be told apart with **Group by form** in the dashboard:

```html
<form data-puls-event="newsletter" action="/subscribe" method="post">
```

If you handle the submit yourself and want to count only successful sends, call `puls.track('newsletter_sent')` after the response instead.

## Outbound Link Tracking

Add `data-outbound` to the script tag to auto-track clicks on external links:

```html
<script src="https://your-puls-domain/?js" data-site="my-site" data-outbound defer></script>
```

Clicks on links pointing to other domains are tracked as `outbound_click` events. Results appear in the dashboard under Traffic > Outbound, with drill-down showing which pages the clicks came from.

## Excluding Your Own Visits

Your own and your team's visits can be left out of the numbers in two ways. Both apply to pageviews and events only. Bot visits and broken links are logged as before.

### By IP address

Pageviews and events from an excluded address or range are dropped before anything is stored. Use it for an office or another network with a fixed IP.

In the dashboard, open **Settings** and find **Excluded IPs**. It shows your own address as Puls sees it, with **Exclude it** to stop counting it, and the site's list, where you can add an address or range with a label, or remove one with ×. Pick a site first when you can see several. Rows for all sites are listed too, and only a user with access to every site can add or remove them.

From the CLI:

```bash
php puls exclude:add my-site 81.2.3.4 --label=Office   # one address
php puls exclude:add my-site 81.2.3.0/24              # a range (CIDR)
php puls exclude:add '*' 2001:db8:1::/48              # IPv6 prefix, on all sites
php puls exclude:list [my-site]
php puls exclude:remove [my-site 81.2.3.4]
```

Without arguments, `exclude:add` asks for the site (or all sites), the address and a label. To find your address, search for "what is my ip" from the network you want to exclude. Home connections and phones often change IP, and IPv6 at home usually changes within a fixed prefix (often a `/56` or `/64`), so exclude the prefix rather than a single address, or use the per-browser opt-out below.

If Puls runs behind a reverse proxy, load balancer or Cloudflare, set `TRUSTED_PROXIES` so it sees the visitor's address, see [Behind a Reverse Proxy](#behind-a-reverse-proxy-docker-cloudflare-load-balancers).

### Per browser

Open any page on the tracked site with `#puls-ignore` at the end of the address, for example `https://example.com/#puls-ignore`. A message confirms it, and from then on that browser sends nothing to Puls for that site. Open a page with `#puls-track` to be counted again.

It is stored in the browser's `localStorage` (no cookie), so it follows the device across networks but has to be done once per browser and site, and clearing the site's data resets it. With `data-debug`, skipped pageviews are logged to the console.

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

## Behind a Reverse Proxy (Docker, Cloudflare, load balancers)

This is about what sits in front of **Puls**, not the tracked site. The tracking script sends pageviews and events from the visitor's browser straight to Puls, so a proxy in front of your site does not matter.

Puls uses the visitor's IP address for the daily visitor hash (unique visitors, sessions, bounce rate, funnels) and for [IP exclusion](#by-ip-address). By default it takes the address the connection comes from. That is right when visitors connect to Puls directly, as with Nginx and PHP-FPM on the same server (Laravel Forge, most VPS setups) or the Docker image with its port published directly.

If something forwards requests to Puls instead, such as Nginx, Caddy or Traefik in front of the Docker container, a load balancer, or Cloudflare with the proxy enabled, every request comes from the proxy. Puls then sees one address for everyone: unique visitors are counted by browser only, and IP exclusion cannot match. List the proxies in `.env`:

```env
TRUSTED_PROXIES=172.16.0.0/12          # e.g. the Docker network your proxy runs on
```

Comma-separated IPs or CIDR ranges. For Cloudflare, add its published ranges from <https://www.cloudflare.com/ips/>. Requests from a trusted proxy have the visitor's address read from `X-Forwarded-For` (from the right, skipping trusted proxies) or `X-Real-IP`. Requests from anywhere else keep the connection address, because anyone can send those headers.

The proxy must forward the visitor's address. For Nginx:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

Caddy and Traefik send `X-Forwarded-For` by default.

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
