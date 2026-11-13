# Read API

Everything the dashboard shows is available as JSON from the same URL, so other applications, scripts and reports can read it. The API is read-only: data comes in through the tracking script, and goals, cards and notes are managed in the dashboard.

## Authentication

There are two ways in:

- **Share token (for other applications).** Create one with `php puls share:create` (below) and send it as a header, `Authorization: Bearer TOKEN`, which keeps it out of URLs, server logs and shell history, or add `&share=TOKEN` to every request (the header works for `?api`, not for the shared dashboard page). Some Apache hosts drop the header before PHP sees it, which gives a `404`: add the two lines in [Apache](install.md#apache) to `public/.htaccess`, or use `&share=`. A token needs no login. An expired token gets `401` with the day it expired and how to extend it, and an unknown or revoked one `404` with a short message.
- **Session (the dashboard itself).** A logged-in browser session. Without one, `?api` returns `401 {"error": "Unauthorized"}`. Users restricted to some sites only see those; asking for another site returns `{"error": "Access denied"}`.

Treat a share token like a password: anyone with it can read the statistics of what it covers, and the same URL without `api` opens the read-only dashboard.

### Share tokens

A token reads one site, or the sites in one or more groups:

```bash
php puls share:create example.com --label="AI assistant"
php puls share:create Shops --label="Report bot"
php puls share:create --groups=Shops,Private --label="Reporting tool"
```

Make one token per reader. A tool that reads two groups keeps its tokens in the same `.env`, so they would leak together: a second token limits nothing, and one token is one thing to revoke. When a site and a group have the same name, `share:create <name>` means the site, which reads less, and `--groups=<name>` means the group.

A token for groups reads what is in them now, also sites added later. `sites:group` and `sites:ungroup` say which tokens they affect, and a group left without sites reads nothing, never every site. A token holds no list of sites: put them in a group, and the token follows them. `?api&sites` lists what it reads, `&site=` picks one, and without `&site=` the answer covers them together, with one row per site in `siteOverview`.

A new token works for 90 days, until the day given with `--expires=YYYY-MM-DD`, or for good with `--expires=never`, which has to be chosen, so a forgotten token stops by itself. Tokens made before this keep what they had.

```bash
php puls share:list                                # what each token reads, its label, last day and last use
php puls share:edit puls_1a2b3c4d --expires=2027-12-31   # a new last day, label (none removes it) or groups
php puls share:revoke puls_1a2b3c4d                # end it
```

`share:edit` also works on a token that has expired, and the token itself stays the same, so whoever holds it changes nothing. A token for one site stays one: reading groups is another reader, so another token.

```bash
curl -s 'https://your-puls-domain/?api&share=TOKEN&days=30'
curl -s -H 'Authorization: Bearer TOKEN' 'https://your-puls-domain/?api&days=30'
```

## Requests

All requests are `GET` with query parameters.

| Request | Returns |
|---------|---------|
| `?api&sites` | The sites you can read, as a JSON array of names. With a share token, the site or the groups' sites it reads |
| `?api&groups` | The site groups and their sites, as `{"Shops": ["a", "b"]}`, limited to the sites you can read. `{}` without groups |
| `?api` | The dashboard data for a period (below) |
| `?api&format=md` | The same as a Markdown report for an AI assistant (below) |
| `?api&event=<name>` | One event's drill-down (below) |
| `?api&hours=<date>` | One day by hour (below) |
| `?api&search=<text>` | Up to 20 paths containing the text (2+ characters), with views and visitors |

### Common parameters

| Parameter | Meaning |
|-----------|---------|
| `days=30` | Period of 1 to 365 days ending today. Default 7 |
| `from=2026-09-01&to=2026-09-30` | A fixed date range instead of `days` (both required, `YYYY-MM-DD`) |
| `site=name` | One site. Implied with a token for one site or a user with a single site. A token for groups or a user with several sites picks one of theirs, by the name `?api&sites` lists |
| `path=/pricing` | Only pageviews of this exact path (the page drill-down) |
| `channel=paid` | Only visitors from one channel: `paid`, `ai`, `campaign`, `organic-search`, `social`, `referral`, `direct`. Goals are left out when `channel` or `path` is set |
| `language=sv` | Only pageviews in one language (two letters, as in `languages`). Reads the rows, as `channel` and `path` do, and goals are left out here too |
| `expand=pages` | Return up to 1,000 rows for one list instead of the top 10 (50 for `events`): `pages`, `referrers`, `entryPages`, `exitPages`, `utm`, `events`, `outbound`, `bots`, `botPages`, `botActivity` |
| `bots=summary` | Leave out `botPages`, `botPagesTotal`, `botActivity` and `botKeptDays`, which read every bot visit of the period. The bot totals stay. Faster on a site with many crawler visits |
| `botDetail` | Answer with only `botPages`, `botPagesTotal`, `botActivity` and `botKeptDays`, for the same period and site. `expand=botPages` and `expand=botActivity` work here too, and `bot=Googlebot` narrows them to one bot |
| `botDays` | Answer with only `botDays`: `[{date, site, name, category, visits}]`, one row per day, site and bot. Read from the counts, so a long period costs nothing |
| `botAgents` | Answer with only `botAgents`: `[{user_agent, name, visits}]`, the top 100 user agents behind the visits Puls has no name for, or behind one bot with `bot=` |

Every response compares with the previous period of the same length, in the `previous*` fields.

## Dashboard data

`?api&days=30` returns one object. The most useful fields:

| Field | Shape |
|-------|-------|
| `totals`, `previousTotals` | `{views, visitors}` |
| `pageviews`, `previousPageviews` | One row per day: `[{date, views, visitors}]` |
| `bounceRate`, `previousBounceRate` | Percentage of visitors with a single pageview. `0` with `path`, which does not measure it |
| `medianEngagedSeconds`, `previousMedianEngagedSeconds` | Median engaged time per visitor in a day, visits of one page included ([engaged time](integrations.md#engaged-time)), of the visitors who had a page on screen for at least a second: a page never shown (a background tab, a prerender, a program that loads and leaves) is no reader. With `path`, the time on that page. Whole seconds under a minute, then steps of 5 s to ten minutes, 30 s to an hour and 5 minutes after. `null` when no visitor in the period had a measured second |
| `medianSessionSeconds`, `previousMedianSessionSeconds` | The earlier measure, which the dashboard no longer shows: median time between first and last pageview, for visitors with more than one, so on a site of one page mostly the time between two visits. Kept for scripts that read it. `0` when no visitor had more than one, and with `path` |
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
| `siteOverview` | On All sites: `[{site, group, views, prev_views, visitors, live, trend, first_seen}]`, `group` is `null` for a site in no group |
| `summary` | `{bestDay, bestDayViews, peakHour, peakHourStart, peakHourViews, topPage, topPageViews, topSource, topSourceVisitors}`. `peakHourStart` is the hour (0 to 23) that `peakHour` starts |
| `versions` | `[{version, first_seen}]` from `data-version` |
| `annotations` | Chart notes overlapping the period: `[{id, site, date, end_date, label}]` |
| `posts`, `postsTotal` | Posts in the period, handed over by a tool on the same server (`php puls posts:add`): `[{date, time, site, sources, text, url}]`, the newest 500, oldest first, and the number in the period. `sources` is a list (`["instagram", "facebook"]`); `text` and `url` may be null |
| `brokenLinks` | 404s and redirects logged by Nginx: `[{site, path, status, hits, referrers, first_seen, last_seen}]`. `referrers` are the pages that linked to it, as host and path; a referrer with the link's own path (scanners send the address they probe) or on a bare IP is left out |
| `bots`, `botCategories`, `botPages`, `botActivity` | Crawler and AI bot visits |
| `version`, `timezone`, `now` | The Puls version, the install's time zone, and its clock at the answer. Stored times are in that zone, without an offset |
| `completeThrough` | The latest day whose figures will not change: today goes on until midnight |
| `tokenExpires` | With a token: its last day, or `null` if it has none. The Markdown report says so too, and warns in the last 14 days. A script that reads every morning can pass that on, instead of stopping without warning |
| `scope` | For a token for groups: `{type: "group", name: "Shops, Private", groups: ["Shops", "Private"]}` |
| `botKeptDays` | Set when the period reaches further back than bot visits are kept (`BOT_RETENTION_DAYS`): `botPages` and `botActivity` then cover that many days, while `bots` and `botCategories` cover the whole period. Otherwise `null` |
| `live` | Visitors in the last 5 minutes |
| `now`, `timezone` | The server's time and its time zone (`TIMEZONE`). Every time in the API is in that zone and carries no offset |

## Hours of a day

`?api&hours=2026-11-03` returns one day's pageviews and visitors per hour, the data behind the card that opens under the chart when a day is clicked. It takes the same `site`, `path` and `channel` as the dashboard data, and `days` or `from`/`to` for the period the channel is judged over.

```json
{"date": "2026-11-03", "hours": [{"hour": 0, "views": 0, "visitors": 0}, ...], "today": "2026-11-03", "hourNow": 14}
```

- `hours` always has 24 rows, 0 to 23, in the install's time zone (`TIMEZONE`)
- `today` and `hourNow` say where the server's clock is, so the hours of today still to come can be told from hours without visits
- A visitor seen in two hours counts in both, so the hours' visitors can add up to more than the day's; pageviews add up exactly
- A date that is not `YYYY-MM-DD`, or does not exist (`2026-02-30`), returns `{"error": "Invalid date"}`

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

A share token lets an AI coding assistant, such as Claude Code, read a site's numbers from inside that site's own project. It can then check which pages matter before it changes something, and whether a change made a difference after the deploy. Puls itself never calls an AI service.

[`llms.txt`](../llms.txt) in this repository is the short version for an agent. `?api&format=md` answers with a report written for the assistant: a short guide first, then the numbers, day by day, pages, sources, goals, events and bots, in far less text than the JSON. It takes `days`, `from`, `to`, `site`, `path` and `channel` like the JSON. With a token for groups it adds a list of the sites, and `&site=` narrows it to one. Long lists end with "and N more", and the rest is in the JSON. The guide also says how to look closer: `&site=`, `&path=`, `&event=<name>&group=<key>` and `&hours=<date>`.

1. Create a token for the site, or for a group if the project covers several sites, with a label that says what it is for. It works for 90 days unless you say otherwise, and `share:create` prints what the next steps need:

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
   (set -a; . ./.env; curl -s -H "Authorization: Bearer $PULS_SHARE_TOKEN" "$PULS_URL/?api&days=30&format=md")   # loads .env itself, so the token stays out of logs
   The same as JSON is that address without format=md, described in https://github.com/webready-se/puls/blob/main/docs/api.md.
   Never print or commit the token. Say so when the numbers are too small to draw conclusions.
   ```

Paths, referrers, campaign values and event names are sent by visitors' browsers, so anyone can put any text there. The assistant should treat them as data, never as instructions. In the Markdown report each sits on one line in a code span, so it cannot pass itself off as a heading or an instruction, and the report's guide says so. Reading the JSON into an assistant has no such protection.

Extend the token with `php puls share:edit TOKEN --expires=YYYY-MM-DD` when it is about to stop, and end it with `php puls share:revoke TOKEN` when it is no longer needed.

## Things to know

- **Visitors are counted per day.** The visitor id is a hash that rotates daily and no cookies are used, so someone who comes back on another day counts again, and funnels only connect actions on the same day.
- **Paths are what the site sent.** Query strings are kept unless the site uses `data-query`. See [Keeping personal data out of URLs](integrations.md#keeping-personal-data-out-of-urls).
- **Lists are capped.** Lists return the top 10 unless you use `expand`, which returns up to 1,000. The `*Total` fields tell you how many rows exist, so you can see when a list is cut off.
- **Bots are kept apart.** Known crawlers never count as visitors; they show up in the `bots` fields only.
