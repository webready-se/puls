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
- [x] Pest-testsvit (65 tester: unit + integration)
- [x] Favicon (inline SVG)
- [x] Live på puls.wrlabs.se med data från lillabosgarden.se
- [x] Fullständig UTM-spårning (alla 5 parametrar: source, medium, campaign, term, content)
- [x] Pre-push hook — tester körs automatiskt före varje push (aktiveras via `composer install`)

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
- [x] **Pest-testsvit** — 65 tester (unit + integration)
- [x] **UTM-länkgenerator** — Inbyggd i dashboarden
- [x] **.env-baserad konfiguration** — php puls key:generate

## Epic 2: Stabilitet & datahygien

Bör på plats nu när det kör live.

- [ ] **Data retention** — Auto-rensa pageviews/bot_visits äldre än N dagar (konfigurerbart, default 365). Enklast via cron: `DELETE FROM pageviews WHERE created_at < date('now', '-365 days')`
- [ ] **Lazy migration-check** — Undvik `PRAGMA table_info` på varje request. En version-tabell eller enkel flagga räcker

## Epic 3: Säkerhet & härdning

- [ ] **Content-Security-Policy** — Strikt CSP på dashboard
- [ ] **Rate limiting (Nginx)** — `limit_req_zone` på collect-endpoint
- [ ] **Session-rotation** — Förnya session-ID periodiskt, inte bara vid login
- [ ] **Audit log** — Logga login-försök (lyckade + misslyckade) i SQLite

## Epic 4: Dashboard-förbättringar

Vänta tills Puls kört live ett tag och man ser vad som faktiskt saknas.

- [ ] **Realtidsuppdatering** — Auto-refresh var 30:e sekund (polling)
- [ ] **Jämförelse** — Visa trend vs föregående period (▲12%)
- [ ] **Datumväljare** — Anpassat datumintervall utöver 24h/7d/30d/90d
- [ ] **Export** — CSV-export av data
- [ ] **Dark mode** — Automatiskt via prefers-color-scheme

## Epic 5: Server-side bot-tracking

Pixeln fångar botar som laddar bilder (GPTBot, Meta AI, Ahrefs etc.), men inte verktyg som bara hämtar HTML utan att ladda resurser (t.ex. `curl`, Claude Code `WebFetch`). En server-side lösning täcker alla bot-besök.

- [x] **`/?log` endpoint** — Tar emot site, path och User-Agent. Kör detect_bot() och loggar i bot_visits precis som `/?pixel`
- [ ] **Nginx mirror** — Snyggaste lösningen. Zero latency, ingen kod på sajterna. `map` filtrerar bort non-bot trafik direkt i Nginx. Konfigureras per sajt i Forge:
  ```nginx
  map $http_user_agent $is_bot {
      default 0;
      ~*(bot|crawl|spider|GPTBot|ClaudeBot|Bytespider|Meta-ExternalAgent|Applebot|Ahrefs|Semrush|facebookexternalhit) 1;
  }

  server {
      location / {
          mirror /puls_log;
      }
      location = /puls_log {
          internal;
          if ($is_bot = "0") { return 204; }
          resolver 8.8.8.8;
          proxy_ssl_verify off;
          proxy_ssl_server_name on;
          proxy_pass https://puls.wrlabs.se/?log&s=$host&p=$request_uri;
          proxy_set_header User-Agent $http_user_agent;
      }
  }
  ```
  OBS: bot-listan dupliceras (Nginx + PHP). Nginx är grov förfiltrering, PHP `detect_bot()` gör riktig klassificering.
- [x] **Deduplicering** — Samma bot + path + site inom 10s skippas. Förhindrar dubbelräkning mellan `/?pixel` och `/?log`
- [x] **Separat visning** — Bot-aktivitetstidslinje i dashboarden med relativa tider, separerad från aggregerad statistik

## Epic 6: Smartare data

- [ ] **Bounce rate** — Besökare som bara ser en sida (data finns, ~41% i nuläget)
- [ ] **Session-längd** — Ungefärlig tid på sajten (baserat på flera pageviews)
- [ ] **Entry/exit pages** — Vilka sidor folk landar på och lämnar från
- [ ] **Filtrering** — Filtrera dashboard per browser, device, referrer

## Epic 7: Skalning & underhåll

Inte relevant förrän det finns riktig volym. Avvakta.

- [ ] **Aggregeringstabeller** — Daglig sammanställning för snabbare queries på stor data
- [ ] **Backup-script** — Automatisk SQLite-backup (cron)
- [ ] **Migration CLI** — `php puls migrate` för framtida schema-ändringar

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
