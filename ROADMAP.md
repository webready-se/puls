# Puls — Roadmap

## Klart

- [x] Tracking script (JS + pixel)
- [x] Pageview collection med privacy-first visitor hash
- [x] Bot detection (25+ botar: AI, sökmotorer, social, SEO, monitor)
- [x] Dashboard med grafer, tabeller, donuts
- [x] Multi-site hub med site-selector
- [x] UTM-kampanjspårning + UTM-länkgenerator i dashboarden
- [x] Språk- och enhetsstatistik
- [x] Session-baserad auth med CSRF-skydd
- [x] Fleranvändarstöd med per-sajt åtkomstkontroll
- [x] Brute-force-skydd på login
- [x] CLI-verktyg (`php puls`) för key:generate + användarhantering
- [x] SQLite med WAL mode + auto-migration
- [x] CORS-stöd för cross-origin tracking
- [x] Path-normalisering (trailing slash, URL-decode, query param-strippning)
- [x] Referrer-gruppering (Facebook, Instagram, Twitter/X, Google etc.)
- [x] IDN/punycode-decode på referrers
- [x] Self-referral-filtrering
- [x] Stabil visitor hash-salt via APP_KEY i .env
- [x] .env-baserad konfiguration
- [x] Hälsokontroll-endpoint (`/?health`)
- [x] Auto-detect Forge zero-deploy paths (ingen symlink behövs)
- [x] Pest-testsvit (90 tester, 166 assertions)
- [x] Favicon (inline SVG)
- [x] Live på puls.wrlabs.se med data från 4 sajter
- [x] Fullständig UTM-spårning (alla 5 parametrar)
- [x] Pre-push hook — tester körs automatiskt före varje push
- [x] Server-side bot-tracking via Nginx mirror + `/?log` endpoint
- [x] Broken link tracking (404/301/övriga) via Nginx post_action + `/?status_log`
- [x] Data retention — auto-rensning av gamla pageviews, bot_visits, broken_links
- [x] Lazy migrations med versionsflagga (inga PRAGMA-anrop per request)
- [x] PWA — installerbar, pull-to-refresh
- [x] Content-Security-Policy + security headers
- [x] Trendpilar på stat-kort (jämförelse mot föregående period)
- [x] Bounce rate + median sessionslängd
- [x] Entry/exit pages (window functions)
- [x] CMD+K command palette med sök + sidfiltrering
- [x] Dark mode (System/Dark/Light) med hamburger-meny
- [x] Broken links: per-status limit, expand/collapse, statuskod-badge

---

## Epic 1: Deploya & gå live ✅

- [x] **Nginx-config + SSL** — Laravel Forge, puls.wrlabs.se
- [x] **Migrera SQLite från lillabosgarden.se** — Kopierad, auto-migration kördes
- [x] **Skapa admin-user** — `php puls user:add admin`
- [x] **Uppdatera tracking-snippets** — lillabosgarden.se, odlingsguiden, snittränta.se, jarnesjo.com
- [x] **Verifiera CORS** — Cross-origin tracking fungerar
- [x] **Hälsokontroll-endpoint** — `/?health` → 200/503
- [x] **Stabil visitor hash-salt** — APP_KEY i .env istället för hash_file(users.json)
- [x] **Strippa tracking query params** — fbclid, gclid, utm_* m.fl. strippas i normalize_path
- [x] **Referrer-gruppering** — Facebook, Instagram, Twitter/X, Google etc.
- [x] **Auto-detect Forge zero-deploy** — resolve_path() hittar sajtroten automatiskt
- [x] **Pest-testsvit** — 90 tester (166 assertions)
- [x] **UTM-länkgenerator** — Inbyggd i dashboarden
- [x] **.env-baserad konfiguration** — php puls key:generate

## Epic 2: Stabilitet & datahygien ✅

- [x] **Data retention** — Auto-rensa pageviews/bot_visits/broken_links äldre än N dagar
- [x] **Lazy migration-check** — Versionsflagga, inga PRAGMA-anrop per request

## Epic 3: Säkerhet & härdning (pågår)

- [x] **Content-Security-Policy** — Strikt CSP på dashboard + security headers
- [ ] **Rate limiting (Nginx)** — `limit_req_zone` på collect-endpoint
- [ ] **Session-rotation** — Förnya session-ID periodiskt
- [ ] **Audit log** — Logga login-försök i SQLite

## Epic 4: Dashboard-förbättringar ✅

- [x] **Trendpilar** — Jämförelse mot föregående period (▲12%)
- [x] **Dark mode** — System/Dark/Light via CSS variables + hamburger-meny
- [x] **CMD+K command palette** — Sök + sidfiltrering med drill-down
- [x] **PWA** — Installerbar, pull-to-refresh
- [x] **Collapsible tools** — Dölj/visa verktyg-sektionen

## Epic 5: Server-side bot-tracking ✅

- [x] **`/?log` endpoint** — Tar emot site, path och User-Agent, loggar i bot_visits
- [x] **Nginx mirror** — Zero latency bot-fångare, konfigureras per sajt i Forge
- [x] **Deduplicering** — Samma bot + path + site inom 10s skippas
- [x] **Separat visning** — Bot-aktivitetstidslinje i dashboarden

## Epic 6: Smartare data ✅

- [x] **Bounce rate** — Med inverterad trend (lägre = grönt)
- [x] **Median sessionslängd** — Undviker outlier-distortion från idle tabs
- [x] **Entry/exit pages** — Window functions (ROW_NUMBER per visitor_hash)
- [x] **Broken link tracking** — 404/301/övriga via Nginx post_action, per-status limit, expand/collapse, statuskod-badge

## Epic 7: Kundpilot 🎯

Nästa stora steg — validera Puls med riktig kund.

- [x] **Deploya hos kund** — Första kundpiloten live, två sajter kopplade
- [ ] **Delbara dashboards** — Token-baserade read-only-länkar (dela statistik utan login)
- [ ] **Data-export** — CSV/JSON-export från dashboarden

> Delbara dashboards ger mest kundvärde snabbast — kunder vill kunna titta utan att behöva login. En enkel `/?share=<token>` som visar read-only dashboard.

## Epic: Automatisering

- [ ] **Veckorapport via email** — CLI-kommando + cron som skickar sammanfattning
- [ ] **Realtidsuppdatering** — Auto-refresh var 30:e sekund (polling)

## Epic: Säkerhet & härdning

- [ ] **Rate limiting (Nginx)** — `limit_req_zone` på collect-endpoint
- [ ] **Session-rotation** — Förnya session-ID periodiskt
- [ ] **Audit log** — Logga login-försök i SQLite

## Epic: Skalning & underhåll

Inte relevant förrän det finns riktig volym. Avvakta.

- [ ] **Aggregeringstabeller** — Daglig sammanställning för snabbare queries
- [ ] **Backup-script** — Automatisk SQLite-backup (cron)
- [ ] **Datumväljare** — Anpassat datumintervall utöver 24h/7d/30d/90d

---

## Idéer & tankar

Saker att bolla längre fram — inget att agera på nu.

### Open Source

- "En PHP-fil" är en unik nisch — Plausible/Fathom kräver Docker+Postgres
- MIT eller AGPL-licens (AGPL skyddar mot att någon hostar en konkurrent utan att dela kod)

### Managed Hosting

- Hosted version ("Puls by Webready") för kunder som inte vill sköta drift
- Per-sajt/månad-prissättning, Plausible-modellen

### Kundisolering

- Unik login-URL per kund eller white-label dashboard
- Eventuellt: separata SQLite-filer per kund (enkel isolation, enkel backup)

### Integrationer

- **Slack/Discord-notiser** — Daglig sammanfattning
- **WordPress-plugin** — Auto-inject tracking snippet
- **API-nycklar** — Alternativ till session-auth för headless API-access
