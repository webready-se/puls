// Runs in <head> before the first paint, on the dashboard and the login page, so neither
// flashes the wrong theme or the default accent. Reads the branding PHP put in the page as a JSON
// data block (#puls-config). Hash params override localStorage (used by scripts/readme-media.mjs)
(function() {
  var hash = {};
  location.hash.replace(/^#/, '').split('&').forEach(function(p) {
    var kv = p.split('='); if (kv[0]) hash[kv[0]] = decodeURIComponent(kv[1] || '');
  });
  if (hash.theme) localStorage.setItem('puls-theme', hash.theme);
  if (hash.compare) localStorage.setItem('puls-compare', hash.compare);
  if (hash.tabs) localStorage.setItem('puls-screenshot-tabs', hash.tabs);
  var s = localStorage.getItem('puls-theme') || 'system';
  if (s === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
  else if (s === 'light') document.documentElement.setAttribute('data-theme', 'light');
  // The install's accent (APP_ACCENT), applied through the CSSOM, which a strict CSP allows
  var cfg = document.getElementById('puls-config');
  var b = cfg ? (JSON.parse(cfg.textContent).branding || {}) : {};
  if (b.accent && b.accent !== '#f16272') {
    var st = document.documentElement.style;
    st.setProperty('--accent-tile', b.accent);
    if (b.accentText) {
      st.setProperty('--brand-text', b.accentText.light);
      st.setProperty('--brand-text-dark', b.accentText.dark);
    }
  }
})();
