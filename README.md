<div align="center">

<img src="public/icon-192.png" width="72" height="72" alt="">

# Puls

**One file. No cookies. Full picture.**

Self-hosted web analytics in a single PHP file and SQLite.<br>
Upload it to any PHP host and see your traffic in minutes, without a cookie banner.

**[Try the live demo](https://demo.puls.webready.se)** - no account, made-up data

[![Tests](https://github.com/webready-se/puls/actions/workflows/tests.yml/badge.svg)](https://github.com/webready-se/puls/actions/workflows/tests.yml)
[![Latest release](https://img.shields.io/github/v/release/webready-se/puls)](https://github.com/webready-se/puls/releases/latest)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

</div>

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/screenshots/dashboard.png">
  <img alt="The Puls dashboard: pageviews, visitors, bounce rate and time per visitor with trends, a chart with a release marker and a note, a summary row, and goals with targets" src="docs/screenshots/dashboard-light.png">
</picture>

## Get started in two minutes

On any host with PHP 8.3 or newer, download the [latest release](https://github.com/webready-se/puls/releases/latest), then:

```bash
unzip puls-*.zip && cd puls-*/
php puls user:add admin            # create your login
php -S localhost:8080 -t public    # try it, or point your web server at public/
```

Add one line to every site you want to track:

```html
<script src="https://stats.example.com/?js" data-site="example.com" defer></script>
```

That's it. Docker, Nginx, Laravel Forge and shared hosting without SSH are covered in [Install and run](docs/install.md). Where the line goes on your site: [WordPress](docs/integrations.md#wordpress), [Next.js](docs/integrations.md#nextjs), [Astro](docs/integrations.md#astro), [Laravel](docs/integrations.md#laravel-blade), [Statamic](docs/integrations.md#statamic), [React](docs/integrations.md#react-spa-vite--create-react-app), and on [any other site](docs/integrations.md#static-html) in its HTML or a site builder's field for custom code.

## Why Puls

- **One file, no infrastructure.** A PHP file, a dashboard page and SQLite. No Node, no Docker, no database server, no build step. It runs on ordinary PHP web hosting.
- **Private by design.** No cookies and no stored IP addresses. A visitor is a hash of the address, the browser and the site with a random salt that changes every day. Once the salt is deleted the hash can never be computed again, not even by whoever runs the server. Puls is designed to work without a consent banner.
- **Your data, on your server.** One SQLite file you can copy, back up or delete. MIT licensed, no limits, no account.
- **Light on your pages.** The tracking script is under 2.5 KB gzipped and sends with `sendBeacon`, so it never holds up the page.

## What you get

### See who reads your site besides people

Bots are counted apart from your visitors: AI crawlers such as GPTBot, ClaudeBot and PerplexityBot, search engines, SEO tools, social previews, uptime monitors and browsers run by a program, with the pages they read. Most crawlers run no script, so Puls sees them through the Nginx of the site you track, with settings `php puls nginx:config` writes for you, and the same settings log 404s and redirects as **pages to fix**, with the pages that link to them. Both sections stay folded until you open them, and their headers say what is inside ("1,128 visits from 8 bots", "2 not found, 3 redirects"), so you can tell when one is worth a look.

![Bots and crawlers, grouped as monitor, search engine, AI and social, with the most crawled pages, the latest bot activity and pages to fix](docs/screenshots/bots.png)

### Events you can read, not just count

`puls.track('signup', { plan: 'pro' })` is all it takes. Each event gets a trend, a rate per 100 visitors, a breakdown by any key in its data, a two-step funnel and a CSV export. Save a breakdown and it becomes a card on the dashboard, with its total, its change and a sparkline.

![Opening an event: a daily trend with release and note markers, then the values grouped by location](docs/screenshots/drill-down.gif)

![Two saved cards: CTA clicks by placement and sign-ups by plan, each with a count, a change against the previous period and a sparkline](docs/screenshots/cards.png)

### Goals with targets

A goal is a page or an event. Its tile shows the visitors who converted, their share of all visitors, the change against the previous period and, if you set one, a target such as 10 per week.

### And the rest

- **Traffic:** channels (paid, campaign, organic, social, referral, direct), UTM campaigns with a link builder, Google Ads detection, entry and exit pages, bounce rate and engaged time
- **Over time:** compare with the previous period, custom date ranges, chart notes for campaigns and mentions, release markers from your app version, posts from Instagram, Facebook or a blog on their day, a live visitor count
- **Teams and clients:** one dashboard for many sites, sites in groups, users limited to their own sites, read-only share links, a [JSON and CSV API](docs/api.md)
- **Tracking without code:** phone and email clicks, downloads, form submissions, outbound links
- **Keeping personal data out:** drop query strings or turn ids and tokens in URLs into `:id` and `:token` before anything leaves the browser
- **Everyday use:** dark mode, a ⌘K command palette for sites and pages, installable as an app, white-label branding, an interactive CLI, checked nightly [backups](docs/install.md#backups)

<details>
<summary>More screenshots</summary>

**Compare with the previous period**

![The pageview chart with the previous period drawn behind each bar](docs/screenshots/dashboard-compare.png)

**Events and countries**

![The events list next to visitor countries](docs/screenshots/events.png)

**Light mode**

![The dashboard in light mode](docs/screenshots/dashboard-light.png)

</details>

## How Puls compares

Fathom and Simple Analytics count without cookies as a service, from $15 and €20 a month. Puls does it for free on a PHP host you may already have, but you install and update it yourself.

| | Where it runs | Database | Cookies | Bots and AI crawlers | Hosted | License |
|---|---|---|---|---|---|---|
| **Puls** | Any PHP 8.3+ host, shared too, or Docker | SQLite, built in | None | Filtered out and listed per bot, with its pages (see below) | No | MIT |
| [GoatCounter](https://www.goatcounter.com) | Own server, one Go binary | SQLite or PostgreSQL | None | Filtered out | Free | EUPL (modified) |
| [Umami](https://umami.is) | Own server, Node.js or Docker | PostgreSQL | None | Filtered out | Free tier, paid | MIT |
| [Plausible CE](https://github.com/plausible/community-edition) | Own server, Docker, 2 GB RAM | PostgreSQL, ClickHouse | None | Filtered out | Paid | AGPL-3.0 |
| [Rybbit](https://rybbit.com) | Own server, Docker, 2 GB RAM | PostgreSQL, ClickHouse, Redis | None | Filtered out, listed if they run its script | Paid | AGPL-3.0 |
| [Matomo](https://matomo.org) | A PHP host | MySQL or MariaDB | Yes, can be turned off | Filtered out; AI assistants with a server setup | Paid | GPL-3.0 |
| [Fathom](https://usefathom.com) | Hosted only | - | None | Filtered out, with a total | Paid | Proprietary |
| [Simple Analytics](https://www.simpleanalytics.com) | Hosted only | - | None | Filtered out | Free for hobby sites, paid | Proprietary |
| Google Analytics | Hosted only | - | Yes | Filtered out | Free | Proprietary |

Most crawlers, GPTBot among them, run no scripts, so a tool that counts through its script never sees them. Puls sees them when the tracked site runs on Nginx you can configure, which shared hosting usually does not allow ([how](docs/integrations.md#server-side-bot-tracking-nginx-mirror)). Otherwise it sees the ones that run its script or load its pixel.

<sub>From each project's own documentation and pricing page, November 2026. Corrections are welcome as issues or pull requests.</sub>

**When to pick something else.** Puls is made for small and medium sites that you run yourself.

- Nothing to run: Fathom or Simple Analytics, or the hosted plans of Plausible, Umami and GoatCounter.
- Millions of pageviews a month on one site: a database built for analytics, like ClickHouse in Plausible and Rybbit, stays fast at that size. Puls reads a summary per site and day, so 30 days of a site with 2 million pageviews a year take about 0.1 s (`scripts/benchmark.php`), but a view filtered to one page or channel reads every pageview and takes seconds.
- Session recordings, page speed (web vitals) or error tracking: Rybbit.
- E-commerce reports or heatmaps: Matomo.

## Privacy

- **No cookies**, ever
- **No IP addresses stored:** the visitor id is a hash of IP, user agent and site with a random salt that is deleted after the day
- **No fingerprinting:** no canvas, font or WebGL tricks
- **Collected:** page path, referrer domain, browser, device type, UTM parameters, language and country (from the `Accept-Language` header)
- **Kept:** pageviews until you delete them, custom events for 400 days (`EVENTS_RETENTION_DAYS`), bot visits for 90 days (`BOT_RETENTION_DAYS`, the count per day stays) and broken links for 30 days after the last hit

You decide what your sites send. [Keeping personal data out of URLs](docs/integrations.md#keeping-personal-data-out-of-urls) shows how to drop search terms, ids and personal links before they reach Puls.

## Documentation

- [Install and run](docs/install.md): release download, shared hosting, Nginx, Apache, Laravel Forge, Docker, upgrading
- [Tracking and integrations](docs/integrations.md): script attributes, custom events, WordPress and frameworks (Next.js, Astro, Laravel, Statamic, React), auto events, server-side bot tracking, reverse proxies
- [Configuration and CLI](docs/configuration.md): `.env` settings, users and access, share links, excluding your own visits, white-label, every CLI command
- [Read API](docs/api.md): JSON and CSV with a share token, also for [your AI assistant](docs/api.md#use-it-from-an-ai-assistant)
- [Changelog](CHANGELOG.md) and [roadmap](ROADMAP.md)

## Building with an AI agent?

Give your assistant a read-only token, and it can see what your visitors do before it changes something, and whether the change made a difference. [`?api&format=md`](docs/api.md#use-it-from-an-ai-assistant) is a report written for it, where nothing a visitor's browser sent can pass itself off as an instruction. [`llms.txt`](llms.txt) tells an agent how to read an install. Puls itself never calls an AI service.

## License

MIT. Made by [Webready](https://webready.se) in Sweden. The dashboard uses the Figtree typeface, under the SIL Open Font License (`public/fonts/OFL.txt`).
