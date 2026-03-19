# Roadmap

## Kommande

### Dark mode
- System / Dark / Light toggle
- Bygger på befintliga CSS-variabler

### Delbara dashboards
- Read-only länk med token (ingen login krävs)
- Typ `/?share=abc123` — perfekt för kunder
- Respekterar site-restriktioner

### Veckorapport via email
- CLI-kommando: `php puls report:send` (körs via cron)
- Sammanfattning av veckans stats i ett enkelt mail
- Använder `mail()` — inga externa beroenden

### Data-export
- Exportera statistik som CSV eller JSON

### Arkitektur
- Bryt ut `get_api_data()` till egen fil om index.php växer förbi ~1500 rader

## Klart

### CMD+K Command Palette (2026-03-19)
- Sökoverlay med ⌘K/Ctrl+K, debounce, tangentbordsnavigering
- Klick på resultat filtrerar hela dashboarden (chart, referrers, browsers, UTM)
- Filter-chip med "Rensa filter"-knapp
- Backend: `?api&search=` + `?api&path=` endpoints

### Smartare data (2026-03-19)
- Bounce rate (stat-kort med trend, inverterad — lägre = bättre)
- Median sessionslängd (median istället för snitt, undviker outliers)
- Ingångssidor / Utgångssidor (window functions: ROW_NUMBER per visitor)
- 6 stat-kort i 3x2 grid (var 4x1)
- Ingen schemaändring

### Trendjämförelse + Döljbara verktyg (2026-03-19)
- Trend-indikatorer (▲/▼ %) på stat-kort
- Snippets bakom "Visa verktyg"-toggle

### Security headers (2026-03-19)
- CSP, X-Frame-Options, Referrer-Policy, Permissions-Policy

### Pull-to-refresh PWA (2026-03-19)
- Custom pull-to-refresh i standalone-läge

### Broken link tracking (2026-03-18)
- 404/301 tracking via Nginx post_action
- Lazy migrations, schema versioning

### Bot tracking (2026-03-16)
- Server-side bot detection via Nginx mirror
- Automatisk klientdetektering, kategorisering

### Grundfunktioner (2026-03-16)
- Dashboard, API, auth, PWA
- UTM-tracking, referrer-gruppering
- Multi-site hub
