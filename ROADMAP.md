# Puls — Roadmap

## Klart

- [x] Tracking script (JS + pixel)
- [x] Pageview collection med privacy-first visitor hash
- [x] Bot detection (25+ botar: AI, sökmotorer, social, SEO, monitor)
- [x] Dashboard med grafer, tabeller, donuts
- [x] Multi-site hub med site-selector
- [x] UTM-kampanjspårning
- [x] Språk- och enhetsstatistik
- [x] Session-baserad auth med CSRF-skydd
- [x] Fleranvändarstöd med per-sajt åtkomstkontroll
- [x] Brute-force-skydd på login
- [x] CLI-verktyg för användarhantering
- [x] SQLite med WAL mode + auto-migration
- [x] CORS-stöd för cross-origin tracking
- [x] Path-normalisering (trailing slash, URL-decode)

---

## Epic 1: Deploya & gå live (PRIO 1)

Mål: Puls live på puls.wrlabs.se med riktig trafik. Allt annat är sekundärt.

- [ ] **Nginx-config + SSL** — Reverse proxy, Let's Encrypt, peka puls.wrlabs.se
- [ ] **Migrera SQLite från lillabosgarden.se** — Kopiera befintlig databas, kör auto-migration vid första request
- [ ] **Skapa admin-user** — `php manage-users.php add admin`
- [ ] **Uppdatera tracking-snippets** — Peka om lillabosgarden.se + odlingsguiden.se till puls.wrlabs.se
- [ ] **Verifiera CORS** — Testa cross-origin tracking från externa sajter
- [ ] **Hälsokontroll-endpoint** — `/?health` → 200 + basic status (för UptimeRobot etc.)

## Epic 2: Stabilitet & datahygien (PRIO 2)

Saker som bör på plats kort efter go-live, innan databasen växer.

- [ ] **Data retention** — Auto-rensa pageviews/bot_visits äldre än N dagar (konfigurerbart, default 365). Enklast via cron: `DELETE FROM pageviews WHERE created_at < date('now', '-365 days')`
- [ ] **Stabil visitor hash-salt** — Byt från `hash_file(users.json)` till en dedikerad salt i config. Nuvarande lösning gör att alla visitor-hashar ändras när en user läggs till/tas bort, vilket ger falska hopp i "unika besökare"
- [ ] **Strippa känsliga query params** — Rensa bort kända PII-params (email, token, etc.) från path innan lagring. Skyddar mot att sajter råkar läcka persondata via URL:er
- [ ] **Lazy migration-check** — Undvik `PRAGMA table_info` på varje request. En version-tabell eller enkel flagga räcker

## Epic 3: Säkerhet & härdning (PRIO 3)

- [ ] **Content-Security-Policy** — Strikt CSP på dashboard
- [ ] **Rate limiting (Nginx)** — `limit_req_zone` på collect-endpoint. Nginx-baserat är enklare och snabbare än SQLite-baserat
- [ ] **Session-rotation** — Förnya session-ID periodiskt, inte bara vid login
- [ ] **Audit log** — Logga login-försök (lyckade + misslyckade) i SQLite

## Epic 4: Dashboard-förbättringar

Vänta med dessa tills Puls kört live ett tag och man ser vad som faktiskt saknas.

- [ ] **Realtidsuppdatering** — Auto-refresh var 30:e sekund (polling)
- [ ] **Jämförelse** — Visa trend vs föregående period (▲12%)
- [ ] **Datumväljare** — Anpassat datumintervall utöver 24h/7d/30d/90d
- [ ] **Export** — CSV-export av data
- [ ] **Dark mode** — Automatiskt via prefers-color-scheme

## Epic 5: Smartare data

- [ ] **Bounce rate** — Besökare som bara ser en sida
- [ ] **Session-längd** — Ungefärlig tid på sajten (baserat på flera pageviews)
- [ ] **Entry/exit pages** — Vilka sidor folk landar på och lämnar från
- [ ] **Filtrering** — Filtrera dashboard per browser, device, referrer

## Epic 6: Skalning & underhåll

Inte relevant förrän det finns riktig volym. Avvakta.

- [ ] **Aggregeringstabeller** — Daglig sammanställning för snabbare queries på stor data
- [ ] **Backup-script** — Automatisk SQLite-backup (cron)
- [ ] **Migration CLI** — `php manage-users.php migrate` för framtida schema-ändringar

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
