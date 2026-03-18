# Epic: Trendjämförelse + Döljbara verktyg

## Kontext
Dashboarden visar idag 4 stat-kort utan historisk jämförelse — svårt att veta om trafiken ökar eller minskar. Dessutom tar tracking-snippet, UTM-generator och Nginx-snippet mycket plats längst ner, trots att de sällan behövs.

## Del 1: Trendjämförelse på stat-korten

**Backend** (`public/index.php`, `get_api_data()`):
- Ny query: hämta totals för föregående period (samma längd)
  - Om `days=7` → jämför senaste 7 dagar vs 7 dagarna innan
- Returnera `previousTotals` i JSON-svaret
- Ingen trend på "Live nu" (5-min-fönster är för brusigt)

**Frontend** (`public/dashboard.html`):
- Beräkna procentuell förändring per kort (views, visitors, pages/visitor)
- Visa liten indikator under siffran: `▲ +12%` (grön) / `▼ -8%` (röd)
- Visa inget om föregående period saknar data (undvik division med 0)

## Del 2: Döljbara verktyg

**Frontend** (`public/dashboard.html`):
- Wrappa snippet-sektionen (tracking + UTM + Nginx) i en togglebar container
- Knapp: "Visa verktyg" / "Dölj verktyg" med chevron
- Default: kollapsat
- Spara state i `localStorage('puls-tools-open')`
- CSS `max-height` transition för smooth animation
- Undantag: om ingen data finns (nyinstallation) → visa snippets direkt (behövs för setup)

## Filer som ändras
- `public/index.php` — ny query i `get_api_data()`
- `public/dashboard.html` — CSS + JS för trends + toggle
- `tests/Feature/EndpointTest.php` — test att `previousTotals` finns i API-svar

## Verifiering
- `./vendor/bin/pest` — alla test gröna
- Manuellt: byt period (7d/30d) och se att trenden uppdateras
- Manuellt: toggle verktyg → reload → state sparas
- Manuellt: mobil/PWA — korten och toggle ser bra ut
