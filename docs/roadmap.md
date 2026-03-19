# Roadmap

## Kommande

### Sökning / Command Palette (CMD+K)
- CMD+K öppnar sökfält (command palette-stil)
- Söker via API med `&path=` param → LIKE-sökning i databasen
- Visar matchande sidor med views, besökare, trend
- Stäng/rensa → tillbaka till vanlig dashboard
- Mobilvänligt (touch-trigger utöver CMD+K)

### Data-export
- Exportera statistik som CSV eller JSON

## Klart

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
