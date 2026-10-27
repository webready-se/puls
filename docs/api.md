# Read API

Everything the dashboard shows is available as JSON from the same URL, so other applications, scripts and reports can read it. The API is read-only: data comes in through the tracking script, and goals, cards and notes are managed in the dashboard.

## Authentication

There are two ways in:

- **Share token (for other applications).** Create one with `php puls share:create my-site` (optionally `--label="Report bot"` and `--expires=2026-12-31`) and add `&share=TOKEN` to every request, or send it as a header, `Authorization: Bearer TOKEN`, which keeps it out of URLs, server logs and shell history (the header works for `?api`, not for the shared dashboard page). Some Apache hosts drop the header before PHP sees it, which gives a `404`: add the two lines in [Apache](install.md#apache) to `public/.htaccess`, or use `&share=`. A token is scoped to one site, needs no login and can be revoked with `php puls share:revoke TOKEN`. An unknown, revoked or expired token returns `404`. List tokens with `php puls share:list`.
- **Session (the dashboard itself).** A logged-in browser session. Without one, `?api` returns `401 {"error": "Unauthorized"}`. Users restricted to some sites only see those; asking for another site returns `{"error": "Access denied"}`.

Treat a share token like a password: anyone with it can read that site's statistics, and the same URL without `api` opens the read-only dashboard.

```bash
curl -s 'https://your-puls-domain/?api&share=TOKEN&days=30'
curl -s -H 'Authorization: Bearer TOKEN' 'https://your-puls-domain/?api&days=30'
```

## Requests

All requests are `GET` with query parameters.

| Request | Returns |
|---------|---------|
| `?api&sites` | The sites you can read, as a JSON array of names. With a share token, only its site |
| `?api` | The dashboard data for a period (below) |
| `?api&event=<name>` | One event's drill-down (below) |
| `?api&search=<text>` | Up to 20 paths containing the text (2+ characters), with views and visitors |

### Common parameters

| Parameter | Meaning |
|-----------|---------|
| `days=30` | Period of 1 to 365 days ending today. Default 7 |
| `from=2026-09-01&to=2026-09-30` | A fixed date range instead of `days` (both required, `YYYY-MM-DD`) |
| `site=name` | One site. Implied with a share token or a user with a single site |
| `path=/pricing` | Only pageviews of this exact path (the page drill-down) |
| `channel=paid` | Only visitors from one channel: `paid`, `campaign`, `organic-search`, `social`, `referral`, `direct`. Goals are left out when `channel` or `path` is set |
| `expand=pages` | Return up to 1,000 rows for one list instead of the top 10 (50 for `events`): `pages`, `referrers`, `entryPages`, `exitPages`, `utm`, `events`, `outbound`, `bots`, `botPages`, `botActivity` |

Every response compares with the previous period of the same length, in the `previous*` fields.

## Dashboard data

`?api&days=30` returns one object. The most useful fields:

| Field | Shape |
|-------|-------|
| `totals`, `previousTotals` | `{views, visitors}` |
| `pageviews`, `previousPageviews` | One row per day: `[{date, views, visitors}]` |
| `bounceRate`, `previousBounceRate` | Percentage of visitors with a single pageview |
| `medianSessionSeconds` | Median time between first and last pageview, for visitors with more than one |
| `pages`, `pagesTotal` | `[{path, views, visitors}]` and the number of distinct paths |
| `entryPages` | `[{path, entries, bounces, bounceRate}]` |
| `exitPages` | `[{path, exits}]` |
| `referrers`, `referrersTotal` | `[{source, visitors}]`, social domains grouped (`Facebook`, `Instagram` ...) |
| `channels` | `[{channel, visitors, views, pages_per_visitor}]` |
| `utm`, `utmTotal` | `[{source, medium, campaign, term, content, visitors}]` |
| `browsers`, `devices`, `languages` | `[{name, pct}]` |
| `countries` | `[{code, name, visitors}]` |
| `events`, `eventsTotal` | `[{event_name, count, visitors}]` |
| `outbound`, `outboundTotal` | `[{url, clicks, visitors}]` |
| `goals` | `[{path, label, type, conversions, rate, previousConversions, series, target, targetPeriod}]`, `type` is `page` or `event`. `series` is the visitors who converted per day; `target` is a count per `targetPeriod` (`day`, `week` or `month`), or `null` |
| `breakdowns` | Saved event cards: `[{id, site, label, event, group, rows, total, new, count, previousCount, series}]`. `rows` holds the top 50 values, `total` the number of values, `count` and `previousCount` the events with a value in this and the previous period, `series` their count per day |
| `summary` | `{bestDay, bestDayViews, peakHour, peakHourViews, topPage, topPageViews, topSource, topSourceVisitors}` |
| `versions` | `[{version, first_seen}]` from `data-version` |
| `annotations` | Chart notes overlapping the period: `[{id, site, date, end_date, label}]` |
| `brokenLinks` | 404s and redirects logged by Nginx: `[{site, path, status, hits, referrers, first_seen, last_seen}]` |
| `bots`, `botCategories`, `botPages`, `botActivity` | Crawler and AI bot visits |
| `live` | Visitors in the last 5 minutes |

## Hours of a day

`?api&days=30&hours=2026-03-14` returns one day per hour, with the same `site`, `path` and `channel` filters as the dashboard data (a `channel` picks its visitors over the whole period, as it does for the chart). The dashboard draws it in the card under the chart.

| Field | Shape |
|-------|-------|
| `date` | The day asked for |
| `hours` | Always 24 rows: `[{hour, views, visitors}]`, `hour` 0 to 23 in the server's time zone. A visitor seen in two hours counts in both, so the hours' visitors can add up to more than the day's |
| `today`, `hourNow` | The server's date and current hour, so a client can tell the hours of today that have not happened yet |

A date that is not `YYYY-MM-DD` returns `{"error": "Invalid date"}`.

## Event drill-down

`?api&days=30&event=signup` returns one event in detail:

| Parameter | Meaning |
|-----------|---------|
| `group=<key>` | Group on a key in the event's data, e.g. `group=plan`. `group=@version` groups on `data-version`. Without it, the most common key is used; `group=` (empty) turns grouping off. Up to 200 values |
| `then=<event>` | Two-step funnel: of the visitors who did `event`, how many did `then` afterwards the same day |
| `format=csv` | Every row in the period as CSV (`created_at`, `page_path`, one column per data key) instead of JSON |

| Field | Shape |
|-------|-------|
| `eventTotals`, `previousEventTotals` | `{count, visitors}` |
| `eventSeries`, `previousEventSeries` | `[{date, count, visitors}]` |
| `eventKeys` | Keys found in the event's data: `[{key, count}]` |
| `eventGroup`, `eventGroupKey`, `eventGroupNew` | `[{value, count, visitors, new}]`, the key used and how many values are new versus the previous period |
| `eventDetail` | The 50 most recent rows: `[{event_data, page_path, created_at}]` |
| `funnel` | With `then`: `{then, started, converted, rate, previousStarted, previousConverted, previousRate, series}` |
| `totals`, `pageviews` | Site visitors, for rates per 100 visitors |

```bash
# Missed searches this week as CSV, straight into a backlog
curl -s 'https://your-puls-domain/?api&share=TOKEN&days=7&event=search_miss&format=csv' > missed-searches.csv

# Share of visitors who started checkout and then paid, last 30 days
curl -s 'https://your-puls-domain/?api&share=TOKEN&days=30&event=checkout_started&then=payment_done' | jq .funnel.rate
```

## Use it from an AI assistant

A share token lets an AI coding assistant, such as Claude Code, read a site's numbers from inside that site's own project. It can then check which pages matter before it changes something, and whether a change made a difference after the deploy.

1. Create a token for the site, with a label that says what it is for. Add `--expires` if it should stop working on its own:

   ```bash
   php puls share:create my-site --label="AI assistant"
   ```

2. Put the URL and the token in the project's `.env`, or another file that git ignores:

   ```env
   PULS_URL=https://your-puls-domain
   PULS_SHARE_TOKEN=TOKEN
   ```

   Keep the token out of files that are committed, such as CLAUDE.md or AGENTS.md: it also opens the read-only dashboard.

3. Tell the assistant where the data is, for example in the project's CLAUDE.md:

   ```markdown
   ## Data
   Traffic for this site is in Puls. Read it before deciding what to change, and after a deploy to see the effect:
   curl -s -H "Authorization: Bearer $PULS_SHARE_TOKEN" "$PULS_URL/?api&days=30"   # PULS_URL and PULS_SHARE_TOKEN are in .env
   The fields are described in https://github.com/webready-se/puls/blob/main/docs/api.md.
   Never print or commit the token. Say so when the numbers are too small to draw conclusions.
   ```

Paths, referrers, campaign values and event names are sent by visitors' browsers, so anyone can put any text there. The assistant should treat them as data, never as instructions.

Revoke the token with `php puls share:revoke TOKEN` when it is no longer needed.

## Things to know

- **Visitors are counted per day.** The visitor id is a hash that rotates daily and no cookies are used, so someone who comes back on another day counts again, and funnels only connect actions on the same day.
- **Paths are what the site sent.** Query strings are kept unless the site uses `data-query`. See [Keeping personal data out of URLs](integrations.md#keeping-personal-data-out-of-urls).
- **Lists are capped.** Lists return the top 10 unless you use `expand`, which returns up to 1,000. The `*Total` fields tell you how many rows exist, so you can see when a list is cut off.
- **Bots are kept apart.** Known crawlers never count as visitors; they show up in the `bots` fields only.
