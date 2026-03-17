# Epic: Broken Links & Redirect Tracking

**Mål:** Spåra 404- och 301-svar för att visa sajt-ägare vilka sidor som behöver åtgärdas.
**Retention:** 30 dagar (kort livslängd — actionable data, inte historik).

---

## Bakgrund

Idag fångar Puls bara bot-besök via Nginx mirror, som **bara triggar på 200-svar**. Det betyder att:
- Botar som träffar 404-sidor loggas aldrig
- Redirects (301) som indikerar gamla länkar syns inte
- Sajt-ägare har ingen aning om brutna länkar eller onödiga redirects

### Värde

| Signal | Vad den säger | Åtgärd |
|--------|---------------|--------|
| 404 med många träffar | Bruten länk som påverkar besökare + SEO | Skapa sida eller redirect |
| 404 med referrer | Visar VAR den brutna länken finns | Kontakta källa eller fixa intern länk |
| 301 med många träffar | Gammal URL som fortfarande används | Uppdatera länken vid källan |
| 301-kedjor | Redirect pekar på annan redirect | Förkorta kedjan |

---

## Arkitektur

### Ny tabell: `broken_links`

Aggregerad modell — en rad per unik kombination, räknar träffar:

```sql
CREATE TABLE broken_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site TEXT NOT NULL,
    path TEXT NOT NULL,
    status INTEGER NOT NULL,
    referrer TEXT,
    hits INTEGER DEFAULT 1,
    first_seen TEXT NOT NULL,
    last_seen TEXT NOT NULL
);
CREATE UNIQUE INDEX idx_broken_unique ON broken_links (site, path, status, referrer);
CREATE INDEX idx_broken_site_status ON broken_links (site, status, hits DESC);
```

**Varför aggregerat (inte individuella rader):**
- 404:or kan generera enorma volymer (botar som skannar /wp-admin, /.env etc.)
- Vi bryr oss om *vilka* sidor som är brutna och *hur ofta*, inte varje enskilt besök
- Upsert-mönster håller tabellen liten och snabb

### Nytt endpoint: `GET /?status_log&s={site}&p={path}&status={code}`

```
Ingen auth. Anropas av Nginx post_action.
Accepterar: s (site), p (path), status (HTTP-statuskod)
Referrer: från X-Original-Referer header (satt av Nginx)
```

**Logik:**
1. Filtrera bort 200, 204, 304 (inte intressanta)
2. Normalisera path + site (samma som handle_log)
3. Trunkera referrer till domän+path (ta bort query strings)
4. UPSERT: `INSERT ... ON CONFLICT UPDATE hits = hits + 1, last_seen = ?`
5. Lazy cleanup: radera entries äldre än 30 dagar (en gång per dag, marker-fil `.broken_cleanup`)

**Ignorera kända brus-paths** (valfritt, fas 2):
- `/wp-admin`, `/wp-login.php`, `/.env`, `/.git` — spambotar som skannar alla sajter
- Konfigurerbar blocklist eller fast lista

### API-tillägg

Utöka befintligt `?api&days=N` response med ny sektion:

```json
{
  "...existing fields...",
  "brokenLinks": [
    {
      "path": "/gammal-sida",
      "status": 404,
      "hits": 47,
      "referrers": ["google.com/search", "facebook.com"],
      "first_seen": "2026-03-10",
      "last_seen": "2026-03-17"
    },
    {
      "path": "/blogg/flytt",
      "status": 301,
      "hits": 12,
      "referrers": ["intern-sida.se/kontakt"],
      "first_seen": "2026-03-15",
      "last_seen": "2026-03-17"
    }
  ]
}
```

**Query:**
```sql
SELECT path, status, SUM(hits) as hits,
       MIN(first_seen) as first_seen, MAX(last_seen) as last_seen,
       GROUP_CONCAT(DISTINCT referrer) as referrers
FROM broken_links
WHERE last_seen >= date('now', '-30 days')
  AND site = ?
GROUP BY path, status
ORDER BY hits DESC
LIMIT 25
```

### Dashboard-sektion: "Sidor att åtgärda"

Ny sektion i dashboarden (mellan bot-tracking och snippets/UTM):

```
┌─────────────────────────────────────────────────────┐
│  Sidor att åtgärda                        30 dagar  │
│                                                     │
│  404 Saknas                                         │
│  ─────────────────────────────────────────────────  │
│  /gammal-sida              47 träffar   idag        │
│    ← google.com, facebook.com                       │
│  /blogg/2024/test          12 träffar   2 dagar     │
│    ← intern-sida.se/kontakt                         │
│  /om-oss.html               3 träffar   5 dagar     │
│                                                     │
│  301 Redirects                                      │
│  ─────────────────────────────────────────────────  │
│  /blogg/flytt → ?          12 träffar   idag        │
│    ← intern-sida.se/kontakt                         │
│  /old-path                  5 träffar   3 dagar     │
│                                                     │
└─────────────────────────────────────────────────────┘
```

- Grupperat per status (404 / 301)
- Sorterat efter antal träffar (mest brådskande först)
- Visar referrer (var den brutna länken finns)
- Relativ tid för senaste träff

---

## Nginx-konfiguration

### Utmaning

`mirror` triggar bara på 200-svar. Vi behöver fånga ALLA statuskoder.

### Rekommenderad approach: `post_action`

`post_action` kör en subrequest **efter** att svaret skickats till klienten — oavsett statuskod. `$status`-variabeln är tillgänglig eftersom svaret redan genererats.

```nginx
# Inuti server-blocket — KOMPLETT konfiguration (ersätter mirror)

location / {
    mirror /puls_log;              # Behåll: bot-tracking (200-svar)
    post_action @puls_status_log;  # Nytt: broken link tracking (alla svar)
    # ...befintlig config (try_files, proxy_pass etc.)
}

# Befintlig bot-tracking (oförändrad)
location = /puls_log {
    internal;
    if ($is_bot = "0") { return 204; }
    resolver 8.8.8.8;
    proxy_ssl_verify off;
    proxy_ssl_server_name on;
    proxy_pass https://puls.wrlabs.se/?log&s=$host&p=$request_uri;
    proxy_set_header User-Agent $http_user_agent;
}

# Ny: status-tracking
location @puls_status_log {
    internal;
    # Hoppa över 200/204/304 — bara intressant med felkoder och redirects
    if ($status ~ "^[23]0[04]$") { return 204; }
    if ($status = 200) { return 204; }
    resolver 8.8.8.8;
    proxy_ssl_verify off;
    proxy_ssl_server_name on;
    proxy_pass https://puls.wrlabs.se/?status_log&s=$host&p=$request_uri&status=$status;
    proxy_set_header User-Agent $http_user_agent;
    proxy_set_header X-Original-Referer $http_referer;
}
```

**Fördelar:**
- Kör efter svar → noll påverkan på svarstid
- `$status` tillgänglig i post_action-kontext
- Samexisterar med befintlig mirror (ingen förändring i bot-tracking)
- Bara icke-200 skickas → låg volym

**Risker:**
- `post_action` är semi-dokumenterat i Nginx (har funnits sedan 0.5.x men saknar officiell docs)
- `if` i Nginx-locations har kända edge cases ("if is evil")
- Behöver verifieras att `$status` verkligen fungerar i regex-match i denna kontext

### Fallback: Custom access_log + daemon

Om `post_action` inte fungerar tillfredsställande:

```nginx
# Lägg till i server-blocket
log_format puls_status '$status|$host|$request_uri|$http_user_agent|$http_referer';
access_log /var/log/nginx/puls-status.log puls_status if=$log_non_200;

# I map-blocket
map $status $log_non_200 {
    200 0;
    204 0;
    304 0;
    default 1;
}
```

PHP-daemon som körs via Forge:
```php
// scripts/process-status-log.php
// Tail:ar loggen, parsar non-200, POSTar till ?status_log
// Körs som Forge daemon: php /path/to/puls/scripts/process-status-log.php
```

**Fördelar:** Robust, vältestad mekanism, inga Nginx-edge-cases.
**Nackdelar:** Extra process att drifta, fördröjning (tail-f buffer).

---

## Implementation — Arbetspaket

### 1. Backend: Tabell + endpoint + migration
**Filer:** `public/index.php`

- [ ] Skapa `broken_links`-tabell i `get_db()` (ny tabell + migration för befintliga DBs)
- [ ] Ny funktion `handle_status_log()` — ta emot s, p, status, referrer
- [ ] UPSERT-logik (INSERT ON CONFLICT UPDATE)
- [ ] Path/site-normalisering (återanvänd `normalize_path()`, `normalize_site()`)
- [ ] Referrer-trunkering (domän + path, inga query strings)
- [ ] Lazy 30-dagars cleanup (`cleanup_broken_links()`, marker-fil `.broken_cleanup`)
- [ ] Route: `isset($_GET['status_log'])` → `handle_status_log()`
- [ ] Tester: endpoint, upsert, cleanup, path-normalisering

### 2. API: Inkludera broken links i response
**Filer:** `public/index.php`

- [ ] Utöka `get_api_data()` med `brokenLinks`-query
- [ ] Gruppera per path+status, summera hits, samla referrers
- [ ] Respektera site-filter och user access restrictions
- [ ] Begränsa till 25 entries (sorterat på hits DESC)
- [ ] Tester: API response innehåller brokenLinks

### 3. Dashboard: Ny sektion "Sidor att åtgärda"
**Filer:** `public/dashboard.html`

- [ ] Ny render-funktion för broken links-sektionen
- [ ] Gruppera 404 och 301 separat
- [ ] Visa: path, antal träffar, senast sedd (relativ tid), referrers
- [ ] Stilsätt med befintliga CSS-variabler (röd/orange badges)
- [ ] Göm sektionen om inga broken links finns

### 4. Nginx: Konfigurera `post_action` på alla sajter
**Server:** forge-monitor-2

- [ ] Testa `post_action` på EN sajt först (lillabosgarden.se)
- [ ] Verifiera att `$status` fungerar som URL-parameter
- [ ] Verifiera att `if ($status = 200)` korrekt filtrerar bort 200:or
- [ ] Uppdatera Nginx-snippet i dashboard.html
- [ ] Rulla ut till övriga sajter: jarnesjo.com, snittränta.se, odlingsguiden

### 5. Kvalitet + polish
- [ ] Ignorera brus-paths (wp-admin, .env, .git etc.) — hårdkodad lista
- [ ] Rate limiting på `?status_log` (max 100 req/min per IP?)
- [ ] Uppdatera dashboard Nginx-snippet med post_action-exempel
- [ ] Dokumentera i CLAUDE.md

---

## Test-strategi

```
# Enhetstester
- handle_status_log() skapar rad i broken_links
- Upsert: andra anrop ökar hits + uppdaterar last_seen
- 200/204/304 ignoreras
- Path normaliseras (trailing slash, tracking params)
- Site normaliseras (punycode → IDN)
- Referrer trunkeras (query strings borttagen)
- Cleanup tar bort entries äldre än 30 dagar

# Feature-tester
- API inkluderar brokenLinks i response
- brokenLinks respekterar site-filter
- brokenLinks respekterar user access restrictions
- Tom array om inga broken links

# Manuellt
- Nginx post_action skickar korrekt status till endpoint
- 404-sida på lillabosgarden.se dyker upp i Puls dashboard
- 301-redirect loggas med rätt referrer
```

---

## Prioritetsordning

1. **Backend** (tabell + endpoint) — kan utvecklas och testas utan Nginx
2. **API** — utöka response
3. **Dashboard** — visa data
4. **Nginx** — koppla ihop allt
5. **Polish** — brus-filtrering, rate limiting

Fas 1–3 kan utvecklas och testas lokalt. Fas 4 kräver server-access.
