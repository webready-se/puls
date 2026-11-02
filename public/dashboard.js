(function() {
  // --- Config ---
  const API = '/';
  const COLORS = ['#f16272','#19acca','#f59e0b','#22c55e','#8b5cf6','#94a3b8'];
  const isShared = !!window.PULS_SHARE;
  const shareToken = isShared ? window.PULS_SHARE.token : null;
  const shareSite = isShared ? window.PULS_SHARE.site : null;

  // --- Branding ---
  const branding = window.PULS_BRANDING || {};
  const appName = branding.name || 'Puls';
  if (appName !== 'Puls') {
    document.title = appName;
    var metaTitle = document.querySelector('meta[name="apple-mobile-web-app-title"]');
    if (metaTitle) metaTitle.content = appName;
    var logoText = document.querySelector('.logo-text');
    if (logoText) logoText.textContent = appName;
  }
  if (branding.accent && branding.accent !== '#f16272') {
    var faviconSvg = 'data:image/svg+xml,' + encodeURIComponent("<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 36 36'><rect width='36' height='36' rx='8' fill='" + branding.accent + "'/><path d='M24 26V16M18 26V10M12 26v-8' stroke='white' stroke-width='2.5' stroke-linecap='round'/></svg>");
    var favicon = document.querySelector('link[rel="icon"]');
    if (favicon) favicon.href = faviconSvg;
  }

  // --- State ---
  let currentDays = isShared ? 7 : (+(localStorage.getItem('puls-days')) || 7);
  let currentSite = isShared ? shareSite : (localStorage.getItem('puls-site') || '');
  let currentFrom = isShared ? '' : (localStorage.getItem('puls-from') || '');
  let currentTo = isShared ? '' : (localStorage.getItem('puls-to') || '');
  let currentPath = '';
  let currentChannel = '';
  let sites = [];

  // --- Elements ---
  const app = document.getElementById('app');
  const periodGroup = document.getElementById('period-group');
  const datePicker = document.getElementById('date-picker');
  const dateFromInput = document.getElementById('date-from');
  const dateToInput = document.getElementById('date-to');
  const customRangeBtn = document.getElementById('custom-range-btn');
  const liveBadge = document.getElementById('live-badge');
  const liveCount = document.getElementById('live-count');

  // --- SVG Icons ---
  const icons = {
    eye: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>',
    users: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    layers: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/></svg>',
    zap: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>',
    chart: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/></svg>',
    bounce: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>',
    clock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
    grip: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>',
  };

  // --- Helpers ---
  // Safe in text and in quoted attributes: quotes are escaped too
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
  // A string argument for an inline handler: a JS literal, then escaped for the attribute it sits in
  function jsArg(s) { return esc(JSON.stringify(String(s == null ? '' : s))); }
  function num(n) { return Number(n || 0).toLocaleString('en-US'); }
  function formatDuration(s) {
    if (!s || s < 10) return '< 10s';
    if (s < 60) return Math.round(s) + 's';
    var m = Math.floor(s / 60);
    var sec = Math.round(s % 60);
    return sec > 0 ? m + 'm ' + sec + 's' : m + 'm';
  }
  // Times are in the install's time zone (TIMEZONE) and carry no offset. Read as UTC and formatted
  // as UTC, they keep the wall clock the server wrote, whatever the viewer's zone; serverSkew is
  // how far that wall clock is from the real now, so "x min ago" counts from the server's now
  var serverSkew = 0;
  function wallClock(dt) { return new Date(dt.replace(' ', 'T') + 'Z'); }
  // A clock time as TIME_FORMAT says: 14:05 or 2:05 PM; without minutes 14:00 or 2 PM.
  // Written out by hand: Intl puts a narrow no-break space before PM in some browsers only
  function clock(h, m) {
    var mm = ('0' + (m || 0)).slice(-2);
    if (branding.timeFormat !== '12h') return ('0' + h).slice(-2) + ':' + mm;
    return (h % 12 || 12) + (m == null ? '' : ':' + mm) + (h < 12 ? ' AM' : ' PM');
  }
  function formatTime(dt) {
    var d = wallClock(dt);
    var diff = (Date.now() + serverSkew - d) / 1000;
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
    if (diff < 172800) return Math.floor(diff / 3600) + ' hr ago';
    return d.toLocaleDateString('en-US', {day:'numeric', month:'short', timeZone:'UTC'}) + ' ' + clock(d.getUTCHours(), d.getUTCMinutes());
  }
  function decode(s) { try { return decodeURIComponent(s); } catch(e) { return s; } }

  function formatDay(dateStr) {
    const dt = new Date(dateStr + 'T00:00:00');
    return dt.toLocaleDateString('en-US', { weekday: 'short', day: 'numeric' });
  }

  // --- Events ---
  window.selectSite = function(site) {
    currentSite = site;
    currentChannel = '';
    currentPath = '';
    if (!isShared) localStorage.setItem('puls-site', currentSite);
    siteLabel();
    pendingAnnouncement = 'site';
    load(currentDays);
  };
  function siteLabel() { document.getElementById('site-btn-label').textContent = currentSite || 'All sites'; }

  // Sites whose name has what was typed, names that start with it first: on a hub with many
  // sites, they are the ones meant. The site picker and the CMD+K palette share these rows
  function matchSites(q) {
    var needle = q.toLowerCase();
    var at = function(s) { return (s || 'All sites').toLowerCase().indexOf(needle); };
    var found = [''].concat(sites).filter(function(s) { return at(s) >= 0; });
    return found.filter(function(s) { return at(s) === 0; }).concat(found.filter(function(s) { return at(s) > 0; }));
  }
  function siteRowHtml(s) {
    return '<div class="cmd-item" role="option" aria-selected="false" data-site="' + esc(s) + '">' +
      '<span class="cmd-item-path">' + (s ? esc(s) : 'All sites') + '</span>' +
      (s === currentSite ? '<span class="cmd-item-current">Current</span>' : '') +
    '</div>';
  }

  // --- Site picker: a button with the sites in a list under it, not the palette: a control that
  // shows a value is expected to open a list in place. It opens on the site on screen; a filter
  // field shows only with many sites, and takes focus only with a fine pointer, since a phone's
  // keyboard would cover the list. Escape or a click outside closes it, Escape back to the button
  var SITE_FILTER_FROM = 8;
  var sitePicker = document.getElementById('site-picker');
  var siteBtn = document.getElementById('site-btn');
  var siteMenu = document.getElementById('site-menu');
  var siteFilter = document.getElementById('site-filter');
  var siteList = document.getElementById('site-list');
  var siteActive = -1;
  var siteTyped = '', siteTypedTimer = 0;

  function paintSites() {
    var rows = matchSites(siteFilter.hidden ? '' : siteFilter.value.trim());
    siteList.innerHTML = rows.length ? rows.map(siteRowHtml).join('') : '<div class="cmd-empty">No site matches</div>';
    var items = siteList.querySelectorAll('.cmd-item');
    items.forEach(function(el, i) { el.id = 'site-option-' + i; });
    siteActive = Math.min(siteActive, items.length - 1);
    if (siteActive < 0 && items.length) siteActive = 0;
    markSite(items);
  }
  function markSite(items, byMouse) {
    var owner = siteFilter.hidden ? siteList : siteFilter;
    items.forEach(function(el, i) {
      el.classList.toggle('active', i === siteActive);
      el.setAttribute('aria-selected', String(i === siteActive));
    });
    if (items[siteActive]) {
      owner.setAttribute('aria-activedescendant', items[siteActive].id);
      if (!byMouse) items[siteActive].scrollIntoView({ block: 'nearest' });
    } else {
      owner.removeAttribute('aria-activedescendant');
    }
  }
  function openSites() {
    siteFilter.hidden = sites.length < SITE_FILTER_FROM;
    siteFilter.value = '';
    siteMenu.hidden = false;
    siteBtn.setAttribute('aria-expanded', 'true');
    siteActive = matchSites('').indexOf(currentSite);
    paintSites();
    (siteFilter.hidden || matchMedia('(pointer: coarse)').matches ? siteList : siteFilter).focus();
  }
  function closeSites(refocus) {
    if (siteMenu.hidden) return;
    siteMenu.hidden = true;
    siteBtn.setAttribute('aria-expanded', 'false');
    if (refocus) siteBtn.focus();
  }
  siteBtn.addEventListener('click', function() { if (siteMenu.hidden) openSites(); else closeSites(true); });
  siteBtn.addEventListener('keydown', function(e) {
    if (e.key === 'ArrowDown' && siteMenu.hidden) { e.preventDefault(); openSites(); }
  });
  siteFilter.addEventListener('input', function() { siteActive = -1; paintSites(); });
  // The mouse moves the highlight too, so only one row ever looks picked
  siteList.addEventListener('mousemove', function(e) {
    var item = e.target.closest('.cmd-item'), items = siteList.querySelectorAll('.cmd-item');
    var i = [].indexOf.call(items, item);
    if (i >= 0 && i !== siteActive) { siteActive = i; markSite(items, true); }
  });
  siteList.addEventListener('click', function(e) {
    var item = e.target.closest('.cmd-item');
    if (!item) return;
    closeSites(true);
    if (item.getAttribute('data-site') !== currentSite) selectSite(item.getAttribute('data-site'));
  });
  siteMenu.addEventListener('keydown', function(e) {
    var items = siteList.querySelectorAll('.cmd-item');
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      siteActive = Math.max(0, Math.min(items.length - 1, siteActive + (e.key === 'ArrowDown' ? 1 : -1)));
      markSite(items);
    } else if ((e.key === 'Home' || e.key === 'End') && siteFilter.hidden) {
      // In the filter field, Home and End move the caret as usual
      e.preventDefault();
      siteActive = e.key === 'Home' ? 0 : items.length - 1;
      markSite(items);
    } else if (e.key === 'Enter' && items[siteActive]) {
      e.preventDefault();
      items[siteActive].click();
    } else if (e.key === 'Escape') {
      e.preventDefault();
      closeSites(true);
    } else if (e.key === 'Tab') {
      closeSites(false);
    } else if (siteFilter.hidden && e.key.length === 1 && !e.metaKey && !e.ctrlKey && !e.altKey && (siteTyped || e.key !== ' ')) {
      // Without the filter, typing the start of a name moves to it, as in the browser's own select:
      // one letter, or the same letter again, goes to the next name that starts with it
      siteTyped += e.key.toLowerCase();
      clearTimeout(siteTypedTimer);
      siteTypedTimer = setTimeout(function() { siteTyped = ''; }, 600);
      var names = [].map.call(items, function(el) { return (el.getAttribute('data-site') || 'All sites').toLowerCase(); });
      var cycle = siteTyped.split('').every(function(c) { return c === siteTyped.charAt(0); });
      var prefix = cycle ? siteTyped.charAt(0) : siteTyped;
      for (var i = 0; i < names.length; i++) {
        var k = (Math.max(siteActive, 0) + (cycle ? 1 : 0) + i) % names.length;
        if (names[k].indexOf(prefix) === 0) { siteActive = k; markSite(items); break; }
      }
    }
  });
  document.addEventListener('click', function(e) {
    if (!siteMenu.hidden && !sitePicker.contains(e.target)) closeSites(false);
  });

  periodGroup.addEventListener('click', function(e) {
    const btn = e.target.closest('.period-btn');
    if (!btn || btn.id === 'custom-range-btn') return;
    pendingAnnouncement = 'period';
    periodGroup.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentFrom = '';
    currentTo = '';
    if (!isShared) {
      localStorage.setItem('puls-days', btn.dataset.days);
      localStorage.removeItem('puls-from');
      localStorage.removeItem('puls-to');
    }
    datePicker.classList.remove('open');
    customRangeBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:14px;height:14px"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>';
    load(+btn.dataset.days);
  });

  // --- Data loading ---
  function apiUrl(params) {
    let url = API + params;
    if (shareToken) url += '&share=' + encodeURIComponent(shareToken);
    return url;
  }

  // auto: the 60 s refresh, which keeps the current view if the request fails
  function load(days, auto) {
    currentDays = days;
    lastLoad = Date.now();
    let url;
    if (currentFrom && currentTo) {
      url = apiUrl('?api&from=' + currentFrom + '&to=' + currentTo);
    } else {
      url = apiUrl('?api&days=' + days);
    }
    if (currentSite) url += '&site=' + encodeURIComponent(currentSite);
    if (currentPath) url += '&path=' + encodeURIComponent(currentPath);
    if (currentChannel) url += '&channel=' + encodeURIComponent(currentChannel);
    return fetch(url)
      .then(function(r) {
        if (r.status === 401) {
          if (isShared) { app.innerHTML = '<div class="empty"><p>This link has expired or is no longer valid.</p></div>'; return; }
          location.href = '/?login'; return;
        }
        return r.json();
      })
      .then(function(data) {
        if (!data) return;
        if (data.now) serverSkew = wallClock(data.now) - Date.now();
        render(data);
      })
      .catch(function() {
        if (auto) return;
        app.innerHTML = '<div class="empty">' + icons.chart +
          '<p>Could not load data. Check that everything is configured correctly.</p></div>';
      });
  }

  // Shared mode: hide admin UI
  if (isShared) {
    document.getElementById('menu-btn').style.display = 'none';
    document.title = shareSite + ' — Puls';
  }

  // Load sites, then data
  if (isShared) {
    sites = [shareSite];
    load(7);
  } else {
    fetch(apiUrl('?api&sites'))
      .then(function(r) {
        if (r.status === 401) { location.href = '/?login'; return; }
        return r.json();
      })
      .then(function(s) {
        if (!s) return;
        sites = s;
        sitePicker.hidden = s.length <= 1;
        // Restore saved site selection
        if (!currentSite || s.indexOf(currentSite) < 0) {
          currentSite = '';
          localStorage.removeItem('puls-site');
        }
        siteLabel();
        // Restore saved period or custom range
        if (currentFrom && currentTo) {
          periodGroup.querySelectorAll('.period-btn').forEach(function(b) { b.classList.remove('active'); });
          customRangeBtn.classList.add('active');
          customRangeBtn.textContent = formatDateLabel(currentFrom) + ' \u2013 ' + formatDateLabel(currentTo);
          currentDays = Math.max(1, Math.round((new Date(currentTo) - new Date(currentFrom)) / 86400000) + 1);
        } else {
          periodGroup.querySelectorAll('.period-btn').forEach(function(b) {
            b.classList.toggle('active', +b.dataset.days === currentDays);
          });
        }
        load(currentDays);
      })
      .catch(function() { load(7); });
  }

  // Auto-refresh every 60 seconds. Held while the tab is hidden, while the viewer is using the
  // page (pointer, scroll or keys in the last 10 s), and while the UTM wizard, the note form,
  // a goal target or a card rename is in use; it runs as soon as none of that applies.
  function utmWizardActive() {
    var path = document.getElementById('utm-path');
    var campaign = document.getElementById('utm-campaign');
    var domain = document.getElementById('utm-domain');
    var domainEdited = domain && domain.value !== utmDomain();
    return (utmState && utmState.source) || (path && path.value) || (campaign && campaign.value) || domainEdited;
  }
  var lastLoad = Date.now(), lastActivity = 0;
  ['pointermove', 'pointerdown', 'keydown', 'wheel', 'scroll', 'touchstart'].forEach(function(type) {
    document.addEventListener(type, function() { lastActivity = Date.now(); }, { capture: true, passive: true });
  });
  setInterval(function() {
    if (Date.now() - lastLoad < 60000 || document.hidden || Date.now() - lastActivity < 10000) return;
    if (utmWizardActive() || noteFormOpen() || editingTarget !== null || (document.activeElement && document.activeElement.isContentEditable)) return;
    load(currentDays, true);
  }, 5000);

  // --- Islands ---
  // Each section is an island: a <div data-island="key"> around what its render function
  // returns. A refresh of the same view replaces only the islands whose HTML changed, so the
  // rest keep their DOM: hover, focus, open drill-downs, tabs and chart scroll survive, and
  // nothing is swapped under the pointer. An island marked data-island-list holds islands of
  // its own (one per saved card); its own markup stays the same.
  var islandHtml = {};
  function island(key, html) {
    return html ? '<div data-island="' + esc(key) + '">' + html + '</div>' : '';
  }

  // Brings #app to the given HTML and returns the islands it inserted
  function patchIslands(html, sameView) {
    var tpl = document.createElement('template');
    tpl.innerHTML = html;
    var fresh = {};
    tpl.content.querySelectorAll('[data-island]').forEach(function(el) { fresh[el.getAttribute('data-island')] = el.outerHTML; });
    var before = sameView ? islandHtml : {};
    var added = [];
    (function patch(parent, next) {
      var old = {};
      [].forEach.call(parent.children, function(el) { if (el.hasAttribute('data-island')) old[el.getAttribute('data-island')] = el; });
      var nodes = [].map.call(next.children, function(el) {
        var key = el.getAttribute('data-island'), cur = key !== null && old[key];
        if (cur && before[key] === fresh[key]) return cur;
        if (cur && el.hasAttribute('data-island-list')) { patch(cur, el); return cur; }
        added.push(el);
        return el;
      });
      // Remove what is gone first, then insert and move, so kept islands are not detached
      [].slice.call(parent.children).forEach(function(el) { if (nodes.indexOf(el) < 0) el.remove(); });
      nodes.forEach(function(el, i) { if (parent.children[i] !== el) parent.insertBefore(el, parent.children[i] || null); });
    })(app, tpl.content);
    islandHtml = fresh;
    return added;
  }

  // --- Undo: a removal waits behind a toast for a few seconds before it is sent ---
  var pendingRemoval = null, removalTimer = 0;
  var toast = document.getElementById('toast');
  function removing(item) { return !!pendingRemoval && pendingRemoval.item === item; }
  // item identifies what render() should hide meanwhile; send(leaving) makes the request
  function removeLater(item, message, send) {
    sendRemoval();
    pendingRemoval = { item: item, send: send };
    document.getElementById('toast-text').textContent = message;
    toast.hidden = false;
    countdown();
    announce(message);
    render(lastData);
  }
  function countdown() { clearTimeout(removalTimer); removalTimer = setTimeout(sendRemoval, 6000); }
  function endRemoval() {
    var p = pendingRemoval;
    pendingRemoval = null;
    clearTimeout(removalTimer);
    toast.hidden = true;
    return p;
  }
  function sendRemoval(leaving) {
    var p = endRemoval();
    if (!p) return;
    var req = p.send(leaving === true);
    if (leaving !== true) req.then(function() { load(currentDays); });
  }
  document.getElementById('toast-undo').addEventListener('click', function() {
    if (endRemoval()) { render(lastData); announce('Restored'); }
  });
  // The countdown waits while the pointer or keyboard focus is on the toast
  toast.addEventListener('pointerenter', function() { clearTimeout(removalTimer); });
  toast.addEventListener('focusin', function() { clearTimeout(removalTimer); });
  toast.addEventListener('pointerleave', function() { if (pendingRemoval && !toast.contains(document.activeElement)) countdown(); });
  toast.addEventListener('focusout', function() { if (pendingRemoval) countdown(); });
  // Leaving the page sends a waiting removal right away
  window.addEventListener('pagehide', function() { sendRemoval(true); });

  // --- Render ---
  var lastData = null;
  // The 1-day period starts at midnight, so it is today, not the last 24 hours
  function periodLabel() {
    return currentDays === 1 && !(currentFrom && currentTo) ? 'today' : 'last ' + currentDays + ' days';
  }
  function render(d) {
    lastData = d;
    if (pendingAnnouncement && d && d.totals) {
      var period = currentFrom && currentTo ? currentFrom + ' to ' + currentTo : periodLabel();
      announce('Showing ' + (currentSite || 'all sites') + ', ' + period + ': ' + num(d.totals.views) + ' pageviews, ' + num(d.totals.visitors) + ' visitors');
      pendingAnnouncement = '';
    }
    if (d.error) {
      app.innerHTML = '<div class="empty"><p>' + esc(d.error) + '</p></div>';
      return;
    }

    // Live badge
    if (d.live > 0) {
      liveBadge.style.display = 'inline-flex';
      liveCount.textContent = d.live;
    } else {
      liveBadge.style.display = 'none';
    }

    var hasData = d.totals && +d.totals.views > 0;

    if (!hasData) {
      app.innerHTML = '<div class="empty">' + icons.chart +
        '<p>No data yet. Add the tracking snippet below to start collecting visitor stats.</p></div>' +
        renderSnippet(d.site) + renderFooter();
      return;
    }

    // Keep tab selection and any open event drill-down across the 60 s refresh
    var activeTabs = [].map.call(document.querySelectorAll('#app .card-tab.active[data-tab]'), function(t) { return t.getAttribute('data-tab'); });
    var openDetail = document.querySelector('.event-detail');
    var openEvent = openDetail && openDetail._state
      ? { name: openDetail._state.name, group: openDetail._state.data.eventGroupKey || '', showAll: openDetail._state.showAll, then: openDetail._state.then,
          anchor: openDetail.previousElementSibling && openDetail.previousElementSibling.getAttribute('data-anchor') }
      : null;

    var totalViews = +d.totals.views;
    var totalVisitors = +d.totals.visitors;
    var pagesPerVisitor = totalVisitors > 0 ? (totalViews / totalVisitors).toFixed(1) : '—';

    // Trend comparison
    var prev = d.previousTotals;
    var viewsTrend = null, visitorsTrend = null, ppvTrend = null;
    if (prev && +prev.views > 0) {
      viewsTrend = Math.round(((totalViews - +prev.views) / +prev.views) * 100);
      visitorsTrend = Math.round(((totalVisitors - +prev.visitors) / +prev.visitors) * 100);
      var prevPpv = +prev.visitors > 0 ? (+prev.views / +prev.visitors) : 0;
      if (prevPpv > 0) {
        ppvTrend = Math.round((((totalViews / totalVisitors) - prevPpv) / prevPpv) * 100);
      }
    }

    // Bounce rate trend (inverted: lower is better)
    var bounceTrend = null;
    if (d.previousBounceRate !== null && d.previousBounceRate > 0) {
      bounceTrend = Math.round(((d.bounceRate - d.previousBounceRate) / d.previousBounceRate) * 100);
    }

    // Session length trend
    var sessionTrend = null;
    if (d.previousMedianSessionSeconds !== null && d.previousMedianSessionSeconds > 0) {
      sessionTrend = Math.round(((d.medianSessionSeconds - d.previousMedianSessionSeconds) / d.previousMedianSessionSeconds) * 100);
    }

    var filtered = !!d.path;
    var html = '';

    // Filter chips
    if (filtered) {
      html += island('filter-path', '<div class="path-filter">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg>' +
        '<span>Filtering on</span>' +
        '<span class="path-filter-path" title="' + esc(d.path) + '">' + esc(decode(d.path)) + '</span>' +
        '<button class="path-filter-clear" onclick="clearPathFilter()">Clear filter</button>' +
      '</div>');
    }
    if (d.channel) {
      var channelName = Object.keys(channelSlugs).find(function(k) { return channelSlugs[k] === d.channel; }) || d.channel;
      var cColor = channelColors[channelName] || 'var(--accent-tile)';
      html += island('filter-channel', '<div class="path-filter">' +
        '<span style="width:10px;height:10px;border-radius:50%;background:' + cColor + ';flex-shrink:0"></span>' +
        '<span>Channel</span>' +
        '<span class="path-filter-path">' + esc(channelName) + '</span>' +
        '<button class="path-filter-clear" onclick="filterChannel(\'\')">Clear filter</button>' +
      '</div>');
    }

    // The numbers and the chart first, then the summary row, goals, pages and sources; saved
    // cards after them. The summary sits above the Goals heading so it does not read as goals.
    html += island('stats', renderStats(d, totalViews, totalVisitors, pagesPerVisitor, viewsTrend, visitorsTrend, ppvTrend, bounceTrend, sessionTrend)) +
      island('chart', renderChart(d)) +
      island('sites', renderSiteOverview(d.siteOverview)) +
      island('summary', renderSummary(d.summary)) +
      renderGoals(d.goals);

    if (!filtered) {
      html += island('pages', '<div class="grid-2">' +
        renderTabbedPages(d.pages, d.entryPages, d.exitPages, d.pagesTotal) +
        renderChannels(d.channels) +
      '</div>');
    }
    html += island('traffic', '<div class="grid-2">' +
      renderTabbedTraffic(d.referrers, d.utm, d.referrersTotal, d.utmTotal, d.events, d.eventsTotal, d.outbound, d.outboundTotal) +
      renderTabbedVisitors(d.browsers, d.devices, d.languages, d.countries) +
    '</div>');

    html += renderBreakdowns(d.breakdowns);

    if (!filtered) {
      html += island('bots', renderBotSection(d)) +
        island('broken', renderBrokenLinks(d.brokenLinks));
    }

    if (!isShared) html += island('tools', renderTools(d.site));
    html += island('footer', renderFooter());
    // A refresh of the same view patches only the islands that changed; another site, period
    // or filter redraws everything. Keep a chart the viewer scrolled back in.
    var viewKey = [currentSite, currentDays, currentFrom, currentTo, currentPath, currentChannel].join('|');
    var sameView = viewKey === lastViewKey;
    lastViewKey = viewKey;
    var oldScroll = app.querySelector('#chart-card .chart-scroll');
    var keepLeft = oldScroll && sameView && oldScroll.scrollLeft + oldScroll.clientWidth < oldScroll.scrollWidth - 1 ? oldScroll.scrollLeft : null;
    patchIslands(html, sameView).forEach(showLatestDays);
    var newScroll = app.querySelector('#chart-card .chart-scroll');
    if (keepLeft !== null && newScroll && newScroll !== oldScroll) newScroll.scrollLeft = keepLeft;

    activeTabs.forEach(function(id) {
      var tab = document.querySelector('.card-tab[data-tab="' + id + '"]');
      if (tab && !tab.classList.contains('active')) switchPageTab(tab);
    });
    if (openEvent && !document.contains(openDetail)) {
      [].some.call(document.querySelectorAll('[data-anchor]'), function(el) {
        if (el.getAttribute('data-anchor') !== openEvent.anchor) return false;
        openEventDetail(el, openEvent.name, openEvent.group, openEvent.showAll, openEvent.then, true);
        var btn = el.querySelector('.breakdown-open');
        if (btn) btn.setAttribute('aria-expanded', 'true');
        return true;
      });
    }

    // Screenshot helper: switch tabs if requested (used by scripts/screenshots.sh)
    var screenshotTabs = localStorage.getItem('puls-screenshot-tabs');
    if (screenshotTabs) {
      localStorage.removeItem('puls-screenshot-tabs');
      var tabs = JSON.parse(screenshotTabs);
      Object.keys(tabs).forEach(function(k) {
        var tab = document.querySelector('.card-tab[data-tab="' + tabs[k] + '"]');
        if (tab) tab.click();
      });
    }
  }

  // Saved breakdowns: one card each, top values of an event grouped on a key
  function renderBreakdowns(list) {
    list = (list || []).filter(function(b) { return !removing('bd:' + b.id); });
    if (!list.length) return '';
    // Cards can be arranged when one site is in view (the order is per site)
    var arrange = !isShared && list.length > 1 && !!(currentSite || sites.length === 1);
    var html = '<div class="grid-2" data-island="breakdowns" data-island-list>';
    list.forEach(function(b) {
      var key = b.group === '@version' ? 'version' : b.group;
      var auto = b.event.replace(/_/g, ' ') + ' by ' + key;
      var sub = (b.label !== auto ? [auto] : []).concat(currentSite ? [] : [b.site]).join(' · ');
      var max = Math.max.apply(null, b.rows.map(function(r) { return r.count; }).concat([1]));
      html += '<div class="card breakdown-card" data-island="bd:' + b.id + '">' +
        '<div class="breakdown-head" data-anchor="bd:' + b.id + '">' +
          '<div style="min-width:0"><span class="card-title"' + (isShared ? '' : ' contenteditable="plaintext-only" spellcheck="false" title="Click to rename" data-bd-site="' + esc(b.site) + '" data-bd-event="' + esc(b.event) + '" data-bd-group="' + esc(b.group) + '" onkeydown="if(event.key===\'Enter\'){event.preventDefault();this.blur()}" onblur="renameBreakdown(this)"') + '>' + esc(b.label) + '</span>' +
            (sub ? '<div class="breakdown-sub">' + esc(sub) + '</div>' : '') + '</div>' +
          '<div class="breakdown-actions">' +
            (arrange ? '<button class="breakdown-grip" aria-label="Move card: drag it, or use the arrow keys" title="Drag to move, or use the arrow keys" onpointerdown="startCardDrag(event, this)" onkeydown="moveCardKey(event, this)">' + icons.grip + '</button>' : '') +
            '<button class="event-chip breakdown-open" aria-expanded="false" data-event="' + esc(b.event) + '" data-group="' + esc(b.group) + '" onclick="openBreakdownDetail(this)" title="Trend, funnel and CSV for this event">Details</button>' +
            (isShared ? '' : '<button class="goal-remove" onclick="removeBreakdown(' + b.id + ', ' + jsArg(b.site) + ')" title="Remove card" aria-label="Remove card">&times;</button>') +
          '</div>' +
        '</div>' +
        renderBreakdownGlance(b);
      if (!b.rows.length) {
        html += '<p class="breakdown-rows" style="color:var(--text-2);font-size:13px">No values in this period</p>';
      } else {
        // Five rows; Show all expands the rest in place
        var open = !!expandedCards[b.id];
        html += '<div class="breakdown-rows">';
        b.rows.forEach(function(r, i) {
          if (i === CARD_ROWS) html += '<div id="bd-more-' + b.id + '"' + (open ? '' : ' hidden') + '>';
          var value = String(r.value);
          html += '<div class="trow">' +
            '<span class="trow-label" title="' + esc(value) + '">' + esc(value) + ' <span style="color:var(--text-3);font-size:11px">' + num(r.visitors) + '</span>' + (r.new ? '<span class="event-new">new</span>' : '') + '</span>' +
            '<div class="trow-bar"><div class="trow-fill" style="width:' + (r.count / max * 100) + '%;background:var(--accent-tile)"></div></div>' +
            '<span class="trow-val">' + num(r.count) + '</span>' +
          '</div>';
        });
        if (b.rows.length > CARD_ROWS) {
          var more = b.total > b.rows.length ? 'Show top ' + b.rows.length + ' of ' + num(b.total) : 'Show all (' + num(b.total) + ')';
          html += '</div></div><button class="breakdown-toggle" aria-expanded="' + open + '" aria-controls="bd-more-' + b.id + '" data-more="' + esc(more) + '" onclick="toggleBreakdownRows(this, ' + b.id + ')">' + (open ? 'Show fewer' : esc(more)) + '</button>';
        } else {
          html += '</div>';
        }
      }
      var foot = [];
      if (b.new) foot.push(num(b.new) + ' new vs previous period');
      if (foot.length) html += '<div class="breakdown-foot">' + foot.join(' · ') + '</div>';
      html += '</div>';
    });
    return html + '</div>';
  }

  // A saved card at a glance: events with a value in the period, the change against the
  // previous period and a daily sparkline. The arrow stays neutral: whether more is good
  // depends on the event (more sign-ups, more missed searches).
  function renderBreakdownGlance(b) {
    var trend = '';
    if (b.previousCount > 0) {
      var pct = Math.round((b.count - b.previousCount) / b.previousCount * 100);
      trend = '<span class="breakdown-trend" title="Previous period: ' + num(b.previousCount) + '">' +
        (pct > 0 ? '▲ +' : (pct < 0 ? '▼ ' : '– ')) + pct + '%</span>';
    }
    return '<div class="breakdown-glance">' +
      '<div><span class="breakdown-count">' + num(b.count) + '</span> <span class="breakdown-unit">events</span>' + trend + '</div>' +
      sparkline(b.series || []) +
    '</div>';
  }

  // Daily counts as a 2px line over a faint wash, the latest day marked. Each day is a
  // hover target with its date and count; the values are also in Details.
  function sparkline(series) {
    var n = series.length;
    if (n < 2) return '';
    var w = 100, h = 32, pad = 6;
    var max = Math.max.apply(null, series.concat([1]));
    var start = currentFrom ? new Date(currentFrom + 'T00:00:00') : new Date(Date.now() - (n - 1) * 86400000);
    var pts = series.map(function(v, i) {
      return [pad + i * (w - 2 * pad) / (n - 1), h - pad - v / max * (h - 2 * pad)];
    });
    var line = pts.map(function(p) { return p[0].toFixed(1) + ',' + p[1].toFixed(1); }).join(' ');
    var last = pts[n - 1];
    var hits = series.map(function(v, i) {
      var day = new Date(start.getTime() + i * 86400000);
      return '<rect x="' + (pts[i][0] - (w - 2 * pad) / (n - 1) / 2).toFixed(1) + '" y="0" width="' + ((w - 2 * pad) / (n - 1)).toFixed(1) + '" height="' + h + '" fill="transparent"><title>' + formatDay(ymd(day)) + ': ' + num(v) + '</title></rect>';
    }).join('');
    return '<svg class="breakdown-spark" width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '" aria-hidden="true">' +
      '<path d="M' + line.replace(/ /g, ' L') + ' L' + last[0].toFixed(1) + ',' + (h - pad) + ' L' + pad + ',' + (h - pad) + ' Z" fill="var(--accent-tile)" fill-opacity="0.1"/>' +
      '<polyline points="' + line + '" fill="none" stroke="var(--accent-tile)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>' +
      '<circle cx="' + last[0].toFixed(1) + '" cy="' + last[1].toFixed(1) + '" r="4" fill="var(--accent-tile)" stroke="var(--surface)" stroke-width="2"/>' +
      hits +
    '</svg>';
  }

  // Show all on a saved card expands the rest of its rows in place, and stays open across refreshes
  var CARD_ROWS = 5;
  var expandedCards = {};
  window.toggleBreakdownRows = function(btn, id) {
    var more = document.getElementById('bd-more-' + id);
    more.hidden = !more.hidden;
    expandedCards[id] = !more.hidden;
    btn.setAttribute('aria-expanded', String(!more.hidden));
    btn.textContent = more.hidden ? btn.getAttribute('data-more') : 'Show fewer';
  };

  // Arrange saved cards: drag the grip (mouse or touch), or press the arrow keys on it.
  // The new order shows at once and is saved for the site.
  function cardIds(grid) {
    return [].map.call(grid.children, function(card) { return +card.getAttribute('data-island').slice(3); });
  }
  function saveCardOrder(grid) {
    fetch(apiUrl('?breakdown_order'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ site: currentSite || sites[0], ids: cardIds(grid) })
    }).then(function() { load(currentDays); });
  }
  function announceCardPosition(card) {
    var cards = [].slice.call(card.parentNode.children);
    announce('Card moved to position ' + (cards.indexOf(card) + 1) + ' of ' + cards.length);
  }

  window.startCardDrag = function(e, grip) {
    if (e.button !== 0) return;
    e.preventDefault();
    var card = grip.closest('.breakdown-card'), grid = card.parentNode;
    var before = cardIds(grid).join();
    card.classList.add('dragging');
    function move(ev) {
      // Near the top or bottom edge the page scrolls, so a card can travel far on a phone
      if (ev.clientY < 60) window.scrollBy(0, -12);
      else if (ev.clientY > window.innerHeight - 60) window.scrollBy(0, 12);
      var over = document.elementFromPoint(ev.clientX, ev.clientY);
      var target = over && over.closest('.breakdown-card');
      if (!target || target === card || target.parentNode !== grid) return;
      var later = card.compareDocumentPosition(target) & Node.DOCUMENT_POSITION_FOLLOWING;
      grid.insertBefore(card, later ? target.nextSibling : target);
    }
    function end() {
      document.removeEventListener('pointermove', move);
      document.removeEventListener('pointerup', end);
      document.removeEventListener('pointercancel', end);
      card.classList.remove('dragging');
      if (cardIds(grid).join() === before) return;
      announceCardPosition(card);
      saveCardOrder(grid);
    }
    document.addEventListener('pointermove', move);
    document.addEventListener('pointerup', end);
    document.addEventListener('pointercancel', end);
  };

  window.moveCardKey = function(e, grip) {
    var step = { ArrowLeft: -1, ArrowUp: -1, ArrowRight: 1, ArrowDown: 1 }[e.key];
    if (!step) return;
    e.preventDefault();
    var card = grip.closest('.breakdown-card'), grid = card.parentNode;
    var next = step < 0 ? card.previousElementSibling : card.nextElementSibling;
    if (!next) return;
    grid.insertBefore(card, step < 0 ? next : next.nextSibling);
    grip.focus();
    announceCardPosition(card);
    saveCardOrder(grid);
  };

  window.renameBreakdown = function(el) {
    var label = el.textContent.trim().slice(0, 100);
    if (!label) { load(currentDays); return; }
    fetch(apiUrl('?breakdown_add'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ site: el.getAttribute('data-bd-site'), event: el.getAttribute('data-bd-event'), group: el.getAttribute('data-bd-group'), label: label })
    }).then(function() { load(currentDays); });
  };

  window.openBreakdownDetail = function(btn) {
    var head = btn.closest('.breakdown-head');
    var open = head.nextElementSibling && head.nextElementSibling.classList.contains('event-detail');
    document.querySelectorAll('.event-detail').forEach(function(d) { d.remove(); });
    document.querySelectorAll('.breakdown-open').forEach(function(b) { b.setAttribute('aria-expanded', 'false'); });
    if (open) return;
    btn.setAttribute('aria-expanded', 'true');
    openEventDetail(head, btn.getAttribute('data-event'), btn.getAttribute('data-group'), false, '');
  };

  window.removeBreakdown = function(id, site) {
    removeLater('bd:' + id, 'Card removed', function(leaving) {
      return fetch(apiUrl('?breakdown_remove'), {
        method: 'POST',
        keepalive: leaving,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ site: site, id: id })
      });
    });
  };

  window.saveBreakdown = function(btn) {
    var detail = btn.closest('.event-detail');
    var st = detail._state, key = st.data.eventGroupKey;
    var site = currentSite || (sites.length === 1 ? sites[0] : '');
    if (!site || !key) return;
    btn.disabled = true;
    btn.textContent = 'Saved';
    fetch(apiUrl('?breakdown_add'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ site: site, event: st.name, group: key, label: st.name.replace(/_/g, ' ') + ' by ' + (key === '@version' ? 'version' : key) })
    }).then(function() { load(currentDays); });
  };

  // A tap on a chart marker label shows its full list, as hovering does with a mouse; a tap
  // anywhere else closes it
  document.addEventListener('click', function(e) {
    var mark = e.target.closest('.chart-release, .chart-note');
    document.querySelectorAll('.chart-release.open, .chart-note.open').forEach(function(m) { if (m !== mark) m.classList.remove('open'); });
    if (mark) mark.classList.toggle('open');
  });

  // ---------- Accessibility ----------

  // Keyboard: Enter and Space activate non-button elements acting as buttons or tabs;
  // arrow keys move between tabs in a tablist (ARIA tabs pattern).
  document.addEventListener('keydown', function(e) {
    var t = e.target;
    var role = t.getAttribute && t.getAttribute('role');
    if ((e.key === 'Enter' || e.key === ' ') && (role === 'button' || role === 'tab') && t.tagName !== 'BUTTON') {
      e.preventDefault();
      t.click();
      return;
    }
    if (role === 'tab' && (e.key === 'ArrowRight' || e.key === 'ArrowLeft' || e.key === 'Home' || e.key === 'End')) {
      var tabs = [].slice.call(t.parentNode.querySelectorAll('[role="tab"]'));
      var i = tabs.indexOf(t);
      var next = e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length - 1
        : (i + (e.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
      e.preventDefault();
      tabs[next].click();
      tabs[next].focus();
    }
  });

  // Dialogs: remember what had focus, move focus in, trap Tab, restore on close
  var dialogReturnFocus = [];
  function openDialogPanel() {
    var candidates = [
      document.querySelector('#cmd-overlay.open .cmd-dialog'),
      document.querySelector('#overlay.open .overlay-panel'),
      document.querySelector('#menu-panel.open'),
    ];
    return candidates.filter(Boolean)[0] || null;
  }
  function focusables(el) {
    return [].filter.call(el.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])'), function(n) {
      return n.offsetParent !== null;
    });
  }
  function dialogOpened(panel) {
    dialogReturnFocus.push(document.activeElement);
    if (!panel) return;
    setTimeout(function() {
      var f = focusables(panel);
      (f[0] || panel).focus();
    }, 30);
  }
  function dialogClosed() {
    var el = dialogReturnFocus.pop();
    if (el && el.focus && document.contains(el)) el.focus();
  }
  document.addEventListener('keydown', function(e) {
    if (e.key !== 'Tab') return;
    var panel = openDialogPanel();
    if (!panel) return;
    var f = focusables(panel);
    if (!f.length) { e.preventDefault(); return; }
    var first = f[0], last = f[f.length - 1];
    if (!panel.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
    else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });

  // Semantics for markup built as strings: clickable divs become buttons, card tabs
  // follow the tabs pattern. Runs on every DOM change, so re-rendered parts are covered.
  function enhanceA11y(root) {
    [].forEach.call(root.querySelectorAll('div[onclick], span[onclick]'), function(el) {
      if (el.hasAttribute('role') || /overlay/.test(el.className)) return;
      if (el.classList.contains('card-tab')) return;
      el.setAttribute('role', 'button');
      el.setAttribute('tabindex', '0');
    });
    [].forEach.call(root.querySelectorAll('.card-tabs'), function(list) {
      list.setAttribute('role', 'tablist');
      [].forEach.call(list.querySelectorAll('.card-tab'), function(tab) {
        var active = tab.classList.contains('active');
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.setAttribute('tabindex', active ? '0' : '-1');
      });
    });
    [].forEach.call(root.querySelectorAll('.tab-panel'), function(p) { p.setAttribute('role', 'tabpanel'); });
  }
  var a11yQueued = false;
  new MutationObserver(function() {
    if (a11yQueued) return;
    a11yQueued = true;
    Promise.resolve().then(function() { a11yQueued = false; enhanceA11y(document); });
  }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
  enhanceA11y(document);

  // Screen reader announcements for changes the user asked for (not the 60 s refresh)
  var pendingAnnouncement = '';
  function announce(msg) {
    var el = document.getElementById('sr-status');
    if (!el) return;
    el.textContent = '';
    setTimeout(function() { el.textContent = msg; }, 50);
  }

  function renderGoals(goals) {
    var site = currentSite || (sites.length === 1 ? sites[0] : '');
    goals = (goals || []).filter(function(g) { return !removing('goal:' + site + '\n' + g.path); });
    if (!goals.length || isShared) return '';
    // One island per goal, so editing a target is not redrawn when another goal changes
    var html = '<div class="goal-grid" data-island="goals" data-island-list>' +
      '<div class="goal-grid-title" data-island="goals-title"><span class="card-title">Goals</span></div>';
    goals.forEach(function(g) {
      var isEvent = g.type === 'event', empty = !g.conversions;
      var trend = '';
      if (g.previousConversions > 0) {
        var pct = Math.round((g.conversions - g.previousConversions) / g.previousConversions * 100);
        trend = '<span class="stat-trend ' + (pct > 0 ? 'up' : (pct < 0 ? 'down' : 'flat')) + '" title="Previous period: ' + num(g.previousConversions) + '">' +
          (pct > 0 ? '▲ +' : (pct < 0 ? '▼ ' : '– ')) + pct + '%</span>';
      }
      html += '<div class="goal-tile' + (empty ? ' is-empty' : '') + '" data-island="goal:' + esc(goalKey(g)) + '">' +
        '<div class="goal-tile-head">' +
          '<div class="goal-name"><span class="goal-label" title="' + esc(g.path) + '">' + esc(g.label) + '</span>' + (isEvent ? '<span class="goal-type">event</span>' : '') + '</div>' +
          '<button class="goal-remove" onclick="removeGoal(' + jsArg(g.path) + ')" title="Remove goal" aria-label="Remove goal">&times;</button>' +
        '</div>' +
        '<div class="goal-tile-body">' +
          '<div><div class="goal-value">' + num(g.conversions) + '</div>' +
          '<div class="goal-meta">' + (empty ? 'No hits in this period' : g.rate + '% of visitors') + trend + '</div></div>' +
          (empty ? '' : sparkline(g.series || [])) +
        '</div>' +
        renderGoalTarget(g, site) +
      '</div>';
    });
    return html + '</div>';
  }

  // A target is a count per day, week or month, scaled to the period shown: 10 per week
  // is 43 over 30 days. Targets are per site, so they are set with one site in view.
  var TARGET_DAYS = { day: 1, week: 7, month: 30 };
  var editingTarget = null;
  // Across sites the same path can be a goal with different labels, so a goal is its type, path and label
  function goalKey(g) { return [g.type, g.path, g.label].join('\n'); }
  function renderGoalTarget(g, site) {
    var path = jsArg(g.path), key = jsArg(goalKey(g));
    if (editingTarget === goalKey(g)) {
      var period = g.targetPeriod || 'week';
      return '<form class="goal-target-form" onsubmit="saveGoalTarget(event, ' + path + ')" onkeydown="if(event.key===\'Escape\')editGoalTarget(null)">' +
        '<input type="number" name="target" min="1" step="1" required value="' + (g.target || '') + '" aria-label="Target">' +
        '<select name="period" aria-label="Target period">' + ['day', 'week', 'month'].map(function(p) {
          return '<option value="' + p + '"' + (p === period ? ' selected' : '') + '>per ' + p + '</option>';
        }).join('') + '</select>' +
        '<button type="submit">Save</button>' +
        (g.target ? '<button type="button" onclick="saveGoalTarget(event, ' + path + ', true)">Clear</button>' : '') +
        '<button type="button" onclick="editGoalTarget(null)">Cancel</button>' +
      '</form>';
    }
    if (!g.target) return site ? '<button class="goal-target-set" onclick="editGoalTarget(' + key + ')">Set target</button>' : '';
    var goal = Math.max(1, Math.round(g.target * currentDays / TARGET_DAYS[g.targetPeriod]));
    var reached = g.conversions >= goal;
    return '<div class="goal-meter' + (reached ? ' reached' : '') + '" role="progressbar" aria-label="Target" aria-valuemin="0" aria-valuemax="' + goal + '" aria-valuenow="' + Math.min(g.conversions, goal) + '">' +
        '<div class="goal-meter-fill" style="width:' + Math.min(100, g.conversions / goal * 100) + '%"></div></div>' +
      '<div class="goal-target">' + num(g.conversions) + ' / ' + num(goal) + (reached ? ' · reached' : '') +
        ' <button class="goal-target-set" onclick="editGoalTarget(' + key + ')" title="Change the target">' + num(g.target) + ' per ' + g.targetPeriod + '</button></div>';
  }

  window.editGoalTarget = function(key) {
    editingTarget = key;
    render(lastData);
    var input = key !== null && document.querySelector('.goal-target-form input');
    if (input) input.focus();
  };

  window.saveGoalTarget = function(e, path, clear) {
    e.preventDefault();
    var form = e.target.closest('form');
    var site = currentSite || (sites.length === 1 ? sites[0] : '');
    editingTarget = null;
    fetch(apiUrl('?goal_target'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ site: site, path: path, target: clear ? 0 : +form.elements.target.value, period: form.elements.period.value })
    }).then(function() { load(currentDays); });
  };

  function renderSummary(summary) {
    if (!summary) return '';
    var html = '<div class="summary-grid">';
    if (summary.bestDay) {
      html += '<div class="summary-card">' +
        '<div class="summary-card-label">Best day</div>' +
        '<div class="summary-card-value">' + esc(summary.bestDay) + '</div>' +
        '<div class="summary-card-sub">' + num(summary.bestDayViews) + ' views</div>' +
      '</div>';
    }
    if (summary.peakHour) {
      html += '<div class="summary-card">' +
        '<div class="summary-card-label">Peak hour</div>' +
        '<div class="summary-card-value">' + clock(summary.peakHourStart) + '–' + clock((summary.peakHourStart + 1) % 24) + '</div>' +
        '<div class="summary-card-sub">' + num(summary.peakHourViews) + ' views</div>' +
      '</div>';
    }
    if (summary.topPage) {
      html += '<div class="summary-card">' +
        '<div class="summary-card-label">Top page</div>' +
        '<div class="summary-card-value" style="font-size:13px;word-break:break-all">' + esc(decode(summary.topPage)) + '</div>' +
        '<div class="summary-card-sub">' + num(summary.topPageViews) + ' views</div>' +
      '</div>';
    }
    if (summary.topSource) {
      html += '<div class="summary-card">' +
        '<div class="summary-card-label">Top source</div>' +
        '<div class="summary-card-value">' + esc(summary.topSource) + '</div>' +
        '<div class="summary-card-sub">' + num(summary.topSourceVisitors) + ' visitors</div>' +
      '</div>';
    }
    html += '</div>';
    return html;
  }

  function renderSiteOverview(overview) {
    if (!overview || overview.length < 2) return '';
    var html = '<div class="card"><span class="card-title">Sites</span><div style="margin-top:12px">';
    overview.forEach(function(s) {
      var trendHtml = '';
      if (s.trend !== null) {
        var cls = s.trend > 0 ? 'up' : (s.trend < 0 ? 'down' : 'flat');
        var arrow = s.trend > 0 ? '\u25B2' : (s.trend < 0 ? '\u25BC' : '\u2013');
        var sign = s.trend > 0 ? '+' : '';
        trendHtml = '<span class="site-overview-trend stat-trend ' + cls + '">' + arrow + ' ' + sign + s.trend + '%</span>';
      } else {
        trendHtml = '<span class="site-overview-trend" style="color:var(--text-2)">\u2013</span>';
      }
      var sinceHtml = '';
      if (s.first_seen) {
        var fd = new Date(s.first_seen + 'T00:00:00');
        sinceHtml = '<div style="font-size:10px;color:var(--text-2);font-weight:400;margin-top:1px;opacity:0.6">since ' + fd.toLocaleDateString('en-US', {month:'short', day:'numeric'}) + '</div>';
      }
      var liveHtml = '';
      if (s.live > 0) {
        liveHtml = '<span class="site-overview-live" title="' + s.live + ' online now"><span class="live-dot" aria-hidden="true"></span>' + s.live + '</span>';
      }
      html += '<div class="site-overview-row" onclick="selectSite(' + jsArg(s.site) + ')">' +
        '<span class="site-overview-name">' + esc(s.site) + liveHtml + sinceHtml + '</span>' +
        '<span class="site-overview-stat">' + num(s.visitors) + ' visitors</span>' +
        '<span class="site-overview-stat">' + num(s.views) + ' views</span>' +
        trendHtml +
      '</div>';
    });
    html += '</div></div>';
    return html;
  }

  function renderStats(d, views, visitors, pagesPerVisitor, viewsTrend, visitorsTrend, ppvTrend, bounceTrend, sessionTrend) {
    return '<div class="stats">' +
      statCard('Pageviews', num(views), icons.eye, viewsTrend) +
      statCard('Unique visitors', num(visitors), icons.users, visitorsTrend) +
      statCard('Bounce rate', d.bounceRate + '%', icons.bounce, bounceTrend, true) +
      statCard('Pages / visitor', pagesPerVisitor, icons.layers, ppvTrend) +
      statCard('Time / session', formatDuration(d.medianSessionSeconds), icons.clock, sessionTrend) +
      statCard('Live now', num(d.live), icons.zap, null) +
    '</div>';
  }

  function statCard(label, value, icon, trend, inverted) {
    var trendHtml = '';
    if (trend !== null && trend !== undefined) {
      var good = inverted ? trend < 0 : trend > 0;
      var bad = inverted ? trend > 0 : trend < 0;
      var cls = good ? 'up' : (bad ? 'down' : 'flat');
      var arrow = trend > 0 ? '▲' : (trend < 0 ? '▼' : '–');
      var sign = trend > 0 ? '+' : '';
      trendHtml = '<div class="stat-trend ' + cls + '">' + arrow + ' ' + sign + trend + '%</div>';
    }
    return '<div class="stat">' +
      '<div class="stat-label">' + label + '</div>' +
      '<div class="stat-top"><div>' +
      '<div class="stat-value">' + value + '</div>' +
      trendHtml +
      '</div><div class="stat-icon">' + icon + '</div></div></div>';
  }

  var chartMode = localStorage.getItem('puls-chart') === 'visitors' ? 'visitors' : 'views';
  var compareMode = localStorage.getItem('puls-compare') === '1';

  function renderChart(d) {
    if (!d.pageviews || !d.pageviews.length) return '';
    var siteName = currentSite || 'all sites';
    var hasCompare = d.previousPageviews && d.previousPageviews.length > 0;

    return '<div class="card" id="chart-card">' +
      '<div class="card-header">' +
        '<div class="card-tabs" style="margin:0;border:0;padding:0">' +
          '<div class="card-tab' + (chartMode === 'views' ? ' active' : '') + '" data-chart="views" onclick="switchChartMode(this)">Pageviews</div>' +
          '<div class="card-tab' + (chartMode === 'visitors' ? ' active' : '') + '" data-chart="visitors" onclick="switchChartMode(this)">Visitors</div>' +
        '</div>' +
        '<span class="card-subtitle">' + esc(siteName) + ' — ' + periodLabel() +
          (hasCompare ? '<button class="chart-compare-toggle' + (compareMode ? ' active' : '') + '" onclick="toggleCompareMode(this)">Compare</button>' : '') +
          (noteSite() ? '<button class="chart-compare-toggle note-toggle" aria-expanded="false" aria-controls="note-form" onclick="toggleNoteForm()">+ Note</button>' : '') +
        '</span>' +
      '</div>' +
      (noteSite() ? renderNoteForm() : '') +
      (compareMode && hasCompare ? '<div class="chart-legend" style="padding:0 4px 10px">' +
        '<div class="chart-legend-item"><span class="chart-legend-dot" style="background:var(--accent-tile)"></span>Current period</div>' +
        '<div class="chart-legend-item"><span class="chart-legend-dot" style="background:var(--line)"></span>Previous period</div>' +
      '</div>' : '') +
      '<div class="chart-scroll' + (compareMode && hasCompare ? ' compare' : '') + (marksFor(d, d.pageviews.map(function(p) { return p.date; })).stacked ? ' stacked-marks' : '') + '"><div class="chart">' + buildBars(d.pageviews, d.previousPageviews) + '</div></div>' +
    '</div>';
  }

  // A long period is wider than the card and scrolls; open it on the latest days
  var lastViewKey = '';
  function showLatestDays(root) {
    root.querySelectorAll('.chart-scroll').forEach(function(el) { el.scrollLeft = el.scrollWidth; });
  }

  // Chart markers per date: release markers for app versions first seen that day, and
  // annotations. A range annotation tints every day it covers and is labelled on its first
  // visible day. Returns { byDate: date -> { cls, html }, stacked } where stacked means
  // some day has both kinds of label, so the chart needs room for two rows.
  function chartMarkers(dates, versions, annotations) {
    var releases = {}, notes = {}, band = {};
    (versions || []).forEach(function(v) { (releases[v.first_seen] = releases[v.first_seen] || []).push(v.version); });
    (annotations || []).forEach(function(a) {
      var end = a.end_date || a.date;
      var covered = dates.filter(function(d) { return d >= a.date && d <= end; });
      if (!covered.length) return;
      (notes[covered[0]] = notes[covered[0]] || []).push(a);
      if (a.end_date) covered.forEach(function(d) { band[d] = true; });
    });
    var byDate = {}, stacked = false;
    dates.forEach(function(date) {
      var cls = '', html = '', rel = releases[date], list = notes[date];
      if (rel) {
        // Newest first, compared as version numbers: as text, 2.9.0 would come after 2.21.1
        rel.sort(function(a, b) { return b.localeCompare(a, 'en', { numeric: true }); });
        cls += ' has-release';
        html += '<span class="chart-release">' + esc(rel[0]) + (rel.length > 1 ? ' +' + (rel.length - 1) : '') + '</span>' +
          markTip('First seen ' + formatDay(date), rel);
      }
      if (list) {
        cls += ' has-note';
        if (rel) stacked = true;
        html += '<span class="chart-note' + (rel ? ' stacked' : '') + '">' + esc(list[0].label) + (list.length > 1 ? ' +' + (list.length - 1) : '') + '</span>' +
          markTip(list.length > 1 ? 'Notes' : 'Note', list.map(function(a) { return formatDay(a.date) + (a.end_date ? ' to ' + formatDay(a.end_date) : '') + ': ' + a.label; }));
      }
      if (band[date]) cls += ' in-note';
      if (cls) byDate[date] = { cls: cls, html: html };
    });
    return { byDate: byDate, stacked: stacked };
  }

  // The full list behind a marker label, one line each under a heading
  function markTip(heading, lines) {
    return '<span class="mark-tip"><span>' + esc(heading) + '</span>' + lines.map(function(l) { return '<span>' + esc(l) + '</span>'; }).join('') + '</span>';
  }

  function marksFor(d, dates) {
    return chartMarkers(dates, d && d.versions, d && d.annotations);
  }

  function buildBars(pageviews, previousPageviews) {
    var key = chartMode === 'visitors' ? 'visitors' : 'views';
    var marks = marksFor(lastData, pageviews.map(function(p) { return p.date; })).byDate;
    var showCompare = compareMode && previousPageviews && previousPageviews.length > 0;
    var allVals = pageviews.map(function(p) { return +p[key]; });
    if (showCompare) {
      allVals = allVals.concat(previousPageviews.map(function(p) { return +p[key]; }));
    }
    var maxVal = Math.max.apply(null, allVals.concat([1]));
    var bars = '';
    pageviews.forEach(function(p, i) {
      var h = Math.max(4, (+p[key] / maxVal) * 140);
      var label = formatDay(p.date);
      var prev = showCompare && previousPageviews[i] ? previousPageviews[i] : null;
      var prevH = prev ? Math.max(4, (+prev[key] / maxVal) * 140) : 0;

      if (showCompare) {
        var curVal = +p[key];
        var prevVal = prev ? +prev[key] : 0;
        var delta = prevVal > 0 ? Math.round(((curVal - prevVal) / prevVal) * 100) : null;
        var deltaStr = delta === null ? '' : (delta >= 0 ? ' (+' + delta + '%)' : ' (' + delta + '%)');
        // Tooltip has inverted background (dark text → light bg in dark mode),
        // so use darker colors that read well on the light tooltip surface.
        var deltaColor = delta === null ? '#64748b' : (delta >= 0 ? '#059669' : '#dc2626');
        var prevLabel = prev ? formatDay(prev.date) : '';
        var combinedTip = '<div class="tip" style="text-align:left">' +
          '<div style="font-weight:600;margin-bottom:4px">' + label + '<span style="color:' + deltaColor + ';font-weight:500">' + deltaStr + '</span></div>' +
          '<div>' + num(p.views) + ' views, ' + num(p.visitors) + ' visitors</div>' +
          (prev ? '<div style="color:var(--text-3);margin-top:2px">vs ' + prevLabel + ': ' + num(prev.views) + ' / ' + num(prev.visitors) + '</div>' : '') +
        '</div>';

        bars += '<div class="chart-col chart-col-compare' + (marks[p.date] ? marks[p.date].cls : '') + '">' +
          (marks[p.date] ? marks[p.date].html : '') +
          combinedTip +
          '<div class="chart-bars">' +
            '<div class="chart-bar-wrap">' +
              '<span class="chart-val">' + num(p[key]) + '</span>' +
              '<div class="chart-bar" style="height:' + h + 'px"></div>' +
            '</div>' +
            '<div class="chart-bar-wrap">' +
              '<span class="chart-val-prev">' + (prev ? num(prev[key]) : '0') + '</span>' +
              '<div class="chart-bar chart-bar-prev" style="height:' + prevH + 'px"></div>' +
            '</div>' +
          '</div>' +
          '<span class="chart-date">' + label + '</span>' +
        '</div>';
      } else {
        bars += '<div class="chart-col' + (marks[p.date] ? marks[p.date].cls : '') + '">' +
          (marks[p.date] ? marks[p.date].html : '') +
          '<span class="chart-val">' + num(p[key]) + '</span>' +
          '<div class="chart-bar" style="height:' + h + 'px">' +
            '<div class="tip">' + label + ': ' + num(p.views) + ' views, ' + num(p.visitors) + ' visitors</div>' +
          '</div>' +
          '<span class="chart-date">' + label + '</span>' +
        '</div>';
      }
    });
    return bars;
  }

  window.switchChartMode = function(el) {
    chartMode = el.getAttribute('data-chart');
    localStorage.setItem('puls-chart', chartMode);
    var card = el.closest('.card');
    card.querySelectorAll('.card-tab').forEach(function(t) { t.classList.remove('active'); });
    el.classList.add('active');
    var chartEl = card.querySelector('.chart');
    chartEl.innerHTML = buildBars(lastData.pageviews, lastData.previousPageviews);
  };

  window.toggleCompareMode = function(el) {
    compareMode = !compareMode;
    localStorage.setItem('puls-compare', compareMode ? '1' : '0');
    // Re-render whole chart card to update legend
    var card = el.closest('.card');
    var wrapper = document.createElement('div');
    wrapper.innerHTML = renderChart(lastData);
    card.replaceWith(wrapper.firstChild);
    // An open event drill-down follows the same compare setting
    document.querySelectorAll('.event-detail').forEach(function(d) { if (d._state) renderEventDetail(d); });
  };

  function renderTable(title, rows, labelKey, valKey, color) {
    if (!rows || !rows.length) {
      return '<div class="card"><span class="card-title">' + title + '</span>' +
        '<p style="color:var(--text-2);font-size:13px;margin-top:12px">No data yet</p></div>';
    }
    var max = Math.max.apply(null, rows.map(function(r) { return +r[valKey]; }));
    var prefix = '';
    if (labelKey === 'path' && currentSite && rows.length > 1) {
      // Check if site name is a path prefix (subpath sites like /garden-guide)
      var sitePrefix = '/' + currentSite + '/';
      var sitePrefixBase = '/' + currentSite;
      if (rows.some(function(r) { var p = decode(r[labelKey]); return p.indexOf(sitePrefix) === 0; })) {
        prefix = sitePrefix;
      } else {
        // Generic common prefix detection
        var parts = decode(rows[0][labelKey]).split('/');
        for (var i = 1; i < parts.length; i++) {
          var candidate = parts.slice(0, i + 1).join('/') + '/';
          var base = candidate.slice(0, -1);
          if (rows.every(function(r) { var p = decode(r[labelKey]); return p === base || p.indexOf(candidate) === 0; })) {
            prefix = candidate;
          } else break;
        }
      }
    }
    var html = '<div class="card"><span class="card-title">' + title + (prefix ? ' <span style="font-weight:400;color:var(--text-3);font-size:12px">' + esc(prefix) + '</span>' : '') + '</span><div style="margin-top:14px">';
    rows.forEach(function(r) {
      var pct = (+r[valKey] / max) * 100;
      var full = decode(r[labelKey]);
      var stripped = prefix ? full.substring(prefix.length) : '';
      var label = prefix ? (stripped ? '/' + stripped : '/') : full;
      html += '<div class="trow">' +
        '<span class="trow-label" title="' + esc(full) + '">' + esc(label) + '</span>' +
        '<div class="trow-bar"><div class="trow-fill" style="width:' + pct + '%;background:' + color + '"></div></div>' +
        '<span class="trow-val">' + num(r[valKey]) + '</span>' +
      '</div>';
    });
    html += '</div></div>';
    return html;
  }

  function renderTableContent(rows, labelKey, valKey, color) {
    if (!rows || !rows.length) {
      return '<p style="color:var(--text-2);font-size:13px;margin-top:12px">No data yet</p>';
    }
    var max = Math.max.apply(null, rows.map(function(r) { return +r[valKey]; }));
    var prefix = '';
    if (labelKey === 'path' && currentSite && rows.length > 1) {
      var sitePrefix = '/' + currentSite + '/';
      if (rows.some(function(r) { var p = decode(r[labelKey]); return p.indexOf(sitePrefix) === 0; })) {
        prefix = sitePrefix;
      } else {
        var parts = decode(rows[0][labelKey]).split('/');
        for (var i = 1; i < parts.length; i++) {
          var candidate = parts.slice(0, i + 1).join('/') + '/';
          var base = candidate.slice(0, -1);
          if (rows.every(function(r) { var p = decode(r[labelKey]); return p === base || p.indexOf(candidate) === 0; })) {
            prefix = candidate;
          } else break;
        }
      }
    }
    var html = '';
    rows.forEach(function(r) {
      var pct = (+r[valKey] / max) * 100;
      var full = decode(r[labelKey]);
      var stripped = prefix ? full.substring(prefix.length) : '';
      var label = prefix ? (stripped ? '/' + stripped : '/') : full;
      var bounce = r.bounceRate !== undefined ? ' <span class="trow-bounce" style="color:var(--text-3);font-size:11px" title="Share of visitors who entered here and saw only this page">' + r.bounceRate + '% bounce</span>' : '';
      html += '<div class="trow">' +
        '<span class="trow-label" title="' + esc(full) + '">' + esc(label) + bounce + '</span>' +
        '<div class="trow-bar"><div class="trow-fill" style="width:' + pct + '%;background:' + color + '"></div></div>' +
        '<span class="trow-val">' + num(r[valKey]) + '</span>' +
      '</div>';
    });
    return html;
  }

  function renderPagesContent(rows) {
    if (!rows || !rows.length) {
      return '<p style="color:var(--text-2);font-size:13px;margin-top:12px">No data yet</p>';
    }
    var max = Math.max.apply(null, rows.map(function(r) { return +r.views; }));
    var goalPaths = (lastData && lastData.goals) ? lastData.goals.map(function(g) { return g.path; }) : [];
    var html = '';
    rows.forEach(function(r) {
      var pct = (+r.views / max) * 100;
      var isGoal = goalPaths.indexOf(r.path) >= 0;
      var goalBtn = isShared ? '' : '<button class="goal-toggle' + (isGoal ? ' is-goal' : '') + '" onclick="event.stopPropagation();toggleGoal(' + jsArg(r.path) + ')" title="' + (isGoal ? 'Remove goal' : 'Set as goal') + '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:14px;height:14px"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg></button>';
      html += '<div class="trow">' +
        goalBtn +
        '<span class="trow-label" title="' + esc(r.path) + '">' + esc(decode(r.path)) + '</span>' +
        '<div class="trow-bar"><div class="trow-fill" style="width:' + pct + '%;background:var(--accent-tile)"></div></div>' +
        '<span class="trow-val">' + num(r.views) + '</span>' +
      '</div>';
    });
    return html;
  }

  var expandRenderers = {
    pages: function(d) { return renderPagesContent(d.pages); },
    entryPages: function(d) { return renderTableContent(d.entryPages, 'path', 'entries', '#059669'); },
    exitPages: function(d) { return renderTableContent(d.exitPages, 'path', 'exits', '#d97706'); },
    referrers: function(d) { return renderTableContent(d.referrers, 'source', 'visitors', '#19acca'); },
    utm: function(d) { return renderUtmContent(d.utm); },
    events: function(d) { return renderEventsContent(d.events); },
    outbound: function(d) { return renderOutboundContent(d.outbound); },
    bots: function(d) { return renderBotTableContent(d.bots); },
    botPages: function(d) { return renderTableContent(d.botPages, 'path', 'visits', '#2563eb'); },
    botActivity: function(d) { return renderBotActivityContent(d.botActivity); }
  };

  function showAllBtn(total, expandType, title) {
    if (total <= 10) return '';
    return '<button class="show-all" onclick="showAll(\'' + expandType + '\', ' + jsArg(title) + ')">Show all (' + total + ')</button>';
  }

  function renderTabbedPages(pages, entryPages, exitPages, pagesTotal) {
    var tabs = [
      { id: 'popular', label: 'Popular', rows: pages, valKey: 'views', color: 'var(--accent-tile)', expand: 'pages', total: pagesTotal },
      { id: 'entry', label: 'Entry', rows: entryPages, valKey: 'entries', color: '#059669', expand: 'entryPages', total: 0 },
      { id: 'exit', label: 'Exit', rows: exitPages, valKey: 'exits', color: '#d97706', expand: 'exitPages', total: 0 }
    ];

    var html = '<div class="card">' +
      '<div class="card-tabs">';
    tabs.forEach(function(t, i) {
      html += '<div class="card-tab' + (i === 0 ? ' active' : '') + '" data-tab="' + t.id + '" onclick="switchPageTab(this)">' + t.label + '</div>';
    });
    html += '</div>';

    tabs.forEach(function(t, i) {
      html += '<div class="tab-panel" data-panel="' + t.id + '"' + (i !== 0 ? ' style="display:none"' : '') + '>' +
        renderTableContent(t.rows, 'path', t.valKey, t.color) +
        showAllBtn(t.total, t.expand, t.label + ' pages') +
      '</div>';
    });

    html += '</div>';
    return html;
  }

  window.switchPageTab = function(el) {
    var card = el.closest('.card');
    var tabId = el.getAttribute('data-tab');
    card.querySelectorAll('.card-tab').forEach(function(t) { t.classList.remove('active'); });
    el.classList.add('active');
    card.querySelectorAll('.tab-panel').forEach(function(p) {
      p.style.display = p.getAttribute('data-panel') === tabId ? '' : 'none';
    });
  }

  function renderDonutContent(items) {
    if (!items || !items.length) {
      return '<p style="color:var(--text-2);font-size:13px;margin-top:12px">No data yet</p>';
    }

    var cum = 0;
    var total = items.reduce(function(s, i) { return s + (+i.pct || 0); }, 0) || 1;
    var paths = '';
    items.forEach(function(item, idx) {
      var start = cum;
      var pct = +item.pct || 0;
      cum += pct;
      var r = 16;
      if (pct >= total) {
        paths += '<circle cx="18" cy="18" r="' + r + '" fill="' + COLORS[idx % COLORS.length] + '"/>';
        return;
      }
      var s = (start / total) * 360 - 90;
      var e = (cum / total) * 360 - 90;
      var large = (pct / total) > 0.5 ? 1 : 0;
      var x1 = 18 + r * Math.cos(s * Math.PI / 180);
      var y1 = 18 + r * Math.sin(s * Math.PI / 180);
      var x2 = 18 + r * Math.cos(e * Math.PI / 180);
      var y2 = 18 + r * Math.sin(e * Math.PI / 180);
      paths += '<path d="M18,18 L' + x1 + ',' + y1 + ' A' + r + ',' + r + ' 0 ' + large + ',1 ' + x2 + ',' + y2 + ' Z" fill="' + COLORS[idx % COLORS.length] + '"/>';
    });

    var legend = '';
    items.forEach(function(item, idx) {
      legend += '<div class="donut-item">' +
        '<span class="donut-dot" style="background:' + COLORS[idx % COLORS.length] + '"></span>' +
        esc(item.name) + ' <span class="donut-pct">' + item.pct + '%</span>' +
      '</div>';
    });

    return '<div class="donut-wrap">' +
      '<svg viewBox="0 0 36 36" width="96" height="96">' + paths +
      '<circle cx="18" cy="18" r="9" fill="var(--surface)"/></svg>' +
      '<div class="donut-legend">' + legend + '</div>' +
    '</div>';
  }

  function renderDonut(title, items) {
    return '<div class="card"><span class="card-title">' + title + '</span>' +
      renderDonutContent(items) + '</div>';
  }

  function renderChannels(channels) {
    return '<div class="card"><span class="card-title">Channels</span><div style="margin-top:14px">' +
      renderChannelsContent(channels) + '</div></div>';
  }

  function renderTabbedTraffic(referrers, utm, referrersTotal, utmTotal, events, eventsTotal, outbound, outboundTotal) {
    var html = '<div class="card">' +
      '<div class="card-tabs">' +
        '<div class="card-tab active" data-tab="sources" onclick="switchPageTab(this)">Sources</div>' +
        '<div class="card-tab" data-tab="utm" onclick="switchPageTab(this)">UTM</div>' +
        '<div class="card-tab" data-tab="events" onclick="switchPageTab(this)">Events</div>' +
        '<div class="card-tab" data-tab="outbound" onclick="switchPageTab(this)">Outbound</div>' +
      '</div>' +
      '<div class="tab-panel" data-panel="sources">' +
        renderTableContent(referrers, 'source', 'visitors', '#19acca') +
        showAllBtn(referrersTotal, 'referrers', 'Traffic sources') +
      '</div>' +
      '<div class="tab-panel" data-panel="utm" style="display:none">' +
        renderUtmContent(utm) +
        showAllBtn(utmTotal, 'utm', 'UTM campaigns') +
      '</div>' +
      '<div class="tab-panel" data-panel="events" style="display:none">' +
        renderEventsContent((events || []).slice(0, 10)) +
        showAllBtn(eventsTotal, 'events', 'Custom events') +
      '</div>' +
      '<div class="tab-panel" data-panel="outbound" style="display:none">' +
        renderOutboundContent(outbound) +
        showAllBtn(outboundTotal, 'outbound', 'Outbound links') +
      '</div>' +
    '</div>';
    return html;
  }

  function renderEventsContent(rows) {
    if (!rows || !rows.length) {
      return '<p style="color:var(--text-2);font-size:13px;margin-top:12px">No data yet</p>';
    }
    var max = Math.max.apply(null, rows.map(function(r) { return +r.count; }));
    var html = '';
    rows.forEach(function(r) {
      var pct = (+r.count / max) * 100;
      var name = r.event_name.replace(/_/g, ' ');
      var visitors = r.visitors !== undefined ? num(r.visitors) + ' visitor' + (+r.visitors === 1 ? '' : 's') : '';
      html += '<div class="trow trow-clickable" role="button" tabindex="0" aria-label="' + esc(name) + ': ' + num(r.count) + ' events, show details" data-event="' + esc(r.event_name) + '" data-anchor="event:' + esc(r.event_name) + '" onclick="loadEventDetail(' + jsArg(r.event_name) + ', this)">' +
        '<span class="trow-label">' + esc(name) + (visitors ? ' <span style="color:var(--text-3);font-size:11px">' + visitors + '</span>' : '') + '</span>' +
        '<div class="trow-bar"><div class="trow-fill" style="width:' + pct + '%;background:var(--accent-tile)"></div></div>' +
        '<span class="trow-val">' + num(r.count) + '</span>' +
      '</div>';
    });
    return html;
  }

  function renderOutboundContent(rows) {
    if (!rows || !rows.length) {
      return '<p style="color:var(--text-2);font-size:13px;margin-top:12px">No data yet</p>';
    }
    var max = Math.max.apply(null, rows.map(function(r) { return +r.clicks; }));
    var html = '';
    rows.forEach(function(r) {
      var pct = (+r.clicks / max) * 100;
      var display = r.url ? r.url.replace(/^https?:\/\//, '').replace(/\/$/, '') : '(unknown)';
      html += '<div class="trow trow-clickable" role="button" tabindex="0" onclick="loadOutboundDetail(' + jsArg(r.url || '') + ', this)">' +
        '<span class="trow-label" title="' + esc(r.url || '') + '">' + esc(display) + '</span>' +
        '<div class="trow-bar"><div class="trow-fill" style="width:' + pct + '%;background:#06b6d4"></div></div>' +
        '<span class="trow-val">' + num(r.clicks) + '</span>' +
      '</div>';
    });
    return html;
  }

  function renderPctBars(items, color) {
    if (!items || !items.length) {
      return '<p style="color:var(--text-2);font-size:13px;margin-top:12px">No data yet</p>';
    }
    var maxPct = Math.max.apply(null, items.map(function(i) { return +i.pct; }));
    var html = '';
    items.forEach(function(i) {
      var w = maxPct > 0 ? (+i.pct / maxPct) * 100 : 0;
      html += '<div class="trow">' +
        '<span class="trow-label">' + esc(i.name) + '</span>' +
        '<div class="trow-bar"><div class="trow-fill" style="width:' + w + '%;background:' + color + '"></div></div>' +
        '<span class="trow-val">' + i.pct + '%</span>' +
      '</div>';
    });
    return html;
  }

  function countryFlag(code) {
    if (!code || code.length !== 2) return '';
    return String.fromCodePoint(0x1F1E6 + code.charCodeAt(0) - 65, 0x1F1E6 + code.charCodeAt(1) - 65);
  }

  function renderCountries(countries) {
    if (!countries || !countries.length) {
      return '<p style="color:var(--text-2);font-size:13px;margin-top:12px">No data yet</p>';
    }
    var max = Math.max.apply(null, countries.map(function(c) { return +c.visitors; }));
    var html = '';
    countries.forEach(function(c) {
      var pct = (+c.visitors / max) * 100;
      html += '<div class="trow">' +
        '<span class="trow-label" style="display:flex;align-items:center"><span style="margin-right:6px;font-size:18px;line-height:1">' + countryFlag(c.code) + '</span>' + esc(c.name) + '</span>' +
        '<div class="trow-bar"><div class="trow-fill" style="width:' + pct + '%;background:#d97706"></div></div>' +
        '<span class="trow-val">' + num(c.visitors) + '</span>' +
      '</div>';
    });
    return html;
  }

  function renderTabbedVisitors(browsers, devices, languages, countries) {
    var html = '<div class="card">' +
      '<div class="card-tabs">' +
        '<div class="card-tab active" data-tab="browsers" onclick="switchPageTab(this)">Browsers</div>' +
        '<div class="card-tab" data-tab="devices" onclick="switchPageTab(this)">Devices</div>' +
        '<div class="card-tab" data-tab="countries" onclick="switchPageTab(this)">Countries</div>' +
        '<div class="card-tab" data-tab="languages" onclick="switchPageTab(this)">Languages</div>' +
      '</div>' +
      '<div class="tab-panel" data-panel="browsers">' + renderPctBars(browsers, 'var(--accent-tile)') + '</div>' +
      '<div class="tab-panel" data-panel="devices" style="display:none">' + renderPctBars(devices, '#19acca') + '</div>' +
      '<div class="tab-panel" data-panel="countries" style="display:none">' + renderCountries(countries) + '</div>' +
      '<div class="tab-panel" data-panel="languages" style="display:none">' + renderPctBars(languages, '#059669') + '</div>' +
    '</div>';
    return html;
  }

  var channelSlugs = {
    'Paid': 'paid', 'Campaign': 'campaign', 'Organic search': 'organic-search',
    'Social': 'social', 'Referral': 'referral', 'Direct': 'direct'
  };
  var channelColors = {
    'Paid': '#ef4444', 'Campaign': '#f59e0b', 'Organic search': '#22c55e',
    'Social': '#8b5cf6', 'Referral': '#06b6d4', 'Direct': '#6b7280'
  };

  function renderChannelsContent(channels) {
    if (!channels || !channels.length) {
      return '<p style="color:var(--text-2);font-size:13px;margin-top:12px">No data yet</p>';
    }
    var maxVisitors = Math.max.apply(null, channels.map(function(c) { return +c.visitors; }));
    var html = '';
    channels.forEach(function(c) {
      var pct = (+c.visitors / maxVisitors) * 100;
      var color = channelColors[c.channel] || 'var(--accent-tile)';
      var slug = channelSlugs[c.channel] || '';
      html += '<div class="trow trow-clickable" role="button" tabindex="0" onclick="filterChannel(\'' + slug + '\')">' +
        '<span class="trow-label">' + esc(c.channel) +
          ' <span style="color:var(--text-3);font-size:11px">' + c.pages_per_visitor + ' p/v</span>' +
        '</span>' +
        '<div class="trow-bar"><div class="trow-fill" style="width:' + pct + '%;background:' + color + '"></div></div>' +
        '<span class="trow-val">' + num(c.visitors) + '</span>' +
      '</div>';
    });
    return html;
  }

  window.filterChannel = function(slug) {
    currentChannel = currentChannel === slug ? '' : slug;
    load(currentDays);
  };

  function renderUtmContent(rows) {
    if (!rows || !rows.length) {
      return '<p style="color:var(--text-2);font-size:13px;margin-top:12px">No data yet</p>';
    }
    var max = Math.max.apply(null, rows.map(function(r) { return +r.visitors; }));
    var html = '';
    rows.forEach(function(r) {
      var pct = (+r.visitors / max) * 100;
      var label = esc(r.source) + (r.medium ? ' / ' + esc(r.medium) : '') + (r.campaign ? ' — ' + esc(r.campaign) : '') + (r.term ? ' [' + esc(r.term) + ']' : '') + (r.content ? ' (' + esc(r.content) + ')' : '');
      html += '<div class="trow">' +
        '<span class="trow-label" title="' + label + '">' + label + '</span>' +
        '<div class="trow-bar"><div class="trow-fill" style="width:' + pct + '%;background:#059669"></div></div>' +
        '<span class="trow-val">' + num(r.visitors) + '</span>' +
      '</div>';
    });
    return html;
  }

  function renderUtm(rows) {
    return '<div class="card"><span class="card-title">UTM campaigns</span><div style="margin-top:14px">' +
      renderUtmContent(rows) + '</div></div>';
  }

  function renderBotSection(d) {
    var bots = d.bots || [];
    var cats = d.botCategories || [];
    var pages = d.botPages || [];
    var totalVisits = bots.reduce(function(s, b) { return s + (+b.visits); }, 0);

    if (!totalVisits) return foldSection('bots', 'Bots &amp; crawlers', 'No bot activity yet', '');

    var badges = '';
    cats.forEach(function(c) {
      var color = botCatColors[c.category] || botCatColors['Other'];
      badges += '<span style="display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;font-size:13px;font-weight:600;background:' + color + '15;color:' + color + '">' +
        esc(c.category) + ' <span style="opacity:0.7">' + num(c.visits) + '</span></span> ';
    });

    var html = '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">' + badges + '</div>' +
      '<div class="grid-2">';

    html += renderBotTable(bots, d.botsTotal);
    html += '<div class="card"><span class="card-title">Most crawled pages</span><div style="margin-top:14px">' +
      renderTableContent(pages, 'path', 'visits', '#2563eb') + '</div>' +
      showAllBtn(d.botPagesTotal, 'botPages', 'Most crawled pages') +
    '</div>';

    html += '</div>';

    // Bot activity timeline
    var activity = d.botActivity || [];
    if (activity.length) {
      html += '<div class="card" style="margin-top:14px"><span class="card-title">Latest bot activity</span>' +
        '<div style="margin-top:12px">' +
        renderBotActivityContent(activity) +
        '</div>' +
        showAllBtn(activity.length >= 10 ? 11 : 0, 'botActivity', 'Bot activity') +
      '</div>';
    }

    return foldSection('bots', 'Bots &amp; crawlers', num(totalVisits) + ' visits from ' + num(d.botsTotal) + ' bot' + (+d.botsTotal === 1 ? '' : 's'), html);
  }

  // A section few look at every day folds to its header, and the header says in one line what
  // is inside, so a folded section still tells when it is worth opening. Folded until the viewer
  // opens it; the choice is remembered per viewer, as Compare is. Without a body there is
  // nothing to open, and the header only says so
  var foldChevron = '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
  function foldSection(key, title, summary, body) {
    var row = '<span class="fold-title">' + title + '</span><span class="fold-summary">' + summary + '</span>';
    if (!body) return '<section class="fold"><h2 class="fold-head"><span class="fold-row">' + row + '</span></h2></section>';
    var open = localStorage.getItem('puls-' + key + '-open') === '1';
    return '<section class="fold" data-fold="' + key + '">' +
      '<h2 class="fold-head"><button class="fold-row fold-toggle" aria-expanded="' + open + '" aria-controls="fold-' + key + '">' + foldChevron + row + '</button></h2>' +
      '<div class="fold-body" id="fold-' + key + '"' + (open ? '' : ' hidden') + '>' + body + '</div>' +
    '</section>';
  }

  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.fold-toggle');
    if (!btn) return;
    var body = document.getElementById(btn.getAttribute('aria-controls'));
    body.hidden = !body.hidden;
    btn.setAttribute('aria-expanded', String(!body.hidden));
    // The dashboard's sections remember the choice; the menu's fold again each time it opens
    var fold = btn.closest('[data-fold]');
    if (fold) localStorage.setItem('puls-' + fold.getAttribute('data-fold') + '-open', body.hidden ? '0' : '1');
  });

  var botCatColors = { 'AI': '#dc2626', 'Search engine': '#2563eb', 'Social': '#7c3aed', 'SEO': '#d97706', 'Monitor': '#059669', 'Other': '#94a3b8' };

  function renderBotTableContent(bots) {
    if (!bots || !bots.length) {
      return '<p style="color:var(--text-2);font-size:13px;margin-top:12px">No data yet</p>';
    }
    var max = Math.max.apply(null, bots.map(function(b) { return +b.visits; }));
    var html = '';
    bots.forEach(function(b) {
      var pct = (+b.visits / max) * 100;
      var color = botCatColors[b.category] || botCatColors['Other'];
      html += '<div class="trow">' +
        '<span class="trow-label" title="' + esc(b.name) + ' (' + esc(b.category) + ')">' +
          '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + color + ';margin-right:6px;flex-shrink:0"></span>' +
          esc(b.name) + ' <span style="color:var(--text-3);font-size:11px">' + esc(b.category) + '</span>' +
        '</span>' +
        '<div class="trow-bar"><div class="trow-fill" style="width:' + pct + '%;background:' + color + '"></div></div>' +
        '<span class="trow-val">' + num(b.visits) + '</span>' +
      '</div>';
    });
    return html;
  }

  function renderBotTable(bots, botsTotal) {
    return '<div class="card"><span class="card-title">Active bots</span><div style="margin-top:14px">' +
      renderBotTableContent(bots) + '</div>' +
      showAllBtn(botsTotal, 'bots', 'Active bots') +
    '</div>';
  }

  function renderBotActivityContent(activity) {
    if (!activity || !activity.length) {
      return '<p style="color:var(--text-2);font-size:13px">No data yet</p>';
    }
    var html = '';
    activity.forEach(function(a) {
      var color = botCatColors[a.category] || botCatColors['Other'];
      var time = formatTime(a.created_at);
      html += '<div class="bot-row">' +
        '<span style="width:8px;height:8px;border-radius:50%;background:' + color + ';flex-shrink:0"></span>' +
        '<span class="bot-row-name">' + esc(a.name) + '</span>' +
        '<span class="bot-row-site">' + esc(a.site) + '</span>' +
        '<span class="bot-row-path">' + esc(a.path) + '</span>' +
        '<span class="bot-row-time">' + time + '</span>' +
      '</div>';
    });
    return html;
  }

  function renderBrokenGroup(items, color, showStatus) {
    var html = '';
    items.forEach(function(l) {
      var time = formatTime(l.last_seen);
      var refs = (l.referrers || []).filter(function(r) { return r; });
      html += '<div style="padding:8px 0;border-bottom:1px solid var(--line)">' +
        '<div style="display:flex;align-items:center;gap:10px;font-size:13px;overflow:hidden">' +
          '<span style="width:8px;height:8px;border-radius:50%;background:' + color + ';flex-shrink:0"></span>' +
          (l.site ? '<span style="color:var(--text-3);font-size:11px;min-width:0">' + esc(l.site) + '</span>' : '') +
          '<span style="font-weight:600;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(l.path) + '">' + esc(l.path) + '</span>' +
          (showStatus ? '<span style="font-size:11px;color:' + color + ';font-weight:600">' + l.status + '</span>' : '') +
          '<span style="color:var(--text-2);white-space:nowrap;font-size:12px">' + num(l.hits) + ' hits</span>' +
          '<span style="color:var(--text-3);white-space:nowrap;font-size:12px">' + time + '</span>' +
        '</div>';
      if (refs.length) {
        html += '<div style="margin-left:18px;margin-top:4px;font-size:12px;color:var(--text-3)">' +
          '\u2190 ' + refs.map(esc).join(', ') + '</div>';
      }
      html += '</div>';
    });
    return html;
  }

  function renderBrokenLinks(links) {
    if (!links || !links.length) return '';

    var links404 = links.filter(function(l) { return l.status === 404; });
    var links301 = links.filter(function(l) { return l.status === 301 || l.status === 302; });
    var linksOther = links.filter(function(l) { return l.status !== 404 && l.status !== 301 && l.status !== 302; });

    var html = '';
    var BROKEN_SHOW = 10;

    function renderGroup(title, color, items, showStatus) {
      if (!items.length) return '';
      var visible = items.slice(0, BROKEN_SHOW);
      var g = '<div class="card" style="margin-bottom:14px">' +
        '<span class="card-title" style="display:flex;align-items:center;gap:8px">' + title +
        ' <span style="font-size:12px;font-weight:400;color:var(--text-2)">' + items.length + ' pages</span></span>' +
        '<div style="margin-top:12px">' +
        renderBrokenGroup(visible, color, showStatus) +
        '</div>';
      if (items.length > BROKEN_SHOW) {
        g += '<button class="show-all" onclick="showBrokenAll(' + jsArg(title) + ',\'' + color + '\',' + !!showStatus + ')">Show all (' + items.length + ')</button>';
      }
      g += '</div>';
      return g;
    }

    html += renderGroup('404 Not found', '#dc2626', links404);
    html += renderGroup('301 Redirects', '#d97706', links301);
    html += renderGroup('Other errors', '#94a3b8', linksOther, true);

    var counts = [];
    if (links404.length) counts.push(links404.length + ' not found');
    if (links301.length) counts.push(links301.length + ' redirect' + (links301.length === 1 ? '' : 's'));
    if (linksOther.length) counts.push(linksOther.length + ' other error' + (linksOther.length === 1 ? '' : 's'));
    return foldSection('broken', 'Pages to fix', counts.join(', ') + ' in the last 30 days', html);
  }

  function renderTools(site) {
    return foldSection('tools', 'Tools', 'Tracking snippet, trackable links, server-side bot tracking', renderSnippet(site));
  }

  // Webready's open source family, for the footer credit. A tool's mark shows only once it is
  // released and public (give it a url), linked; icons that lead nowhere would only puzzle people
  var familyMarks = [
    { name: 'Puls', url: 'https://github.com/webready-se/puls', svg: '<svg aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 36"><rect width="36" height="36" rx="8" fill="#f16272"/><path d="M24 26V16M18 26V10M12 26v-8" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/></svg>' },
    { name: 'Vaka', url: null, svg: '<svg aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 36"><rect width="36" height="36" rx="8" fill="#0e7490"/><g transform="translate(0.4 0.6) scale(0.88)"><path d="M10 12.6L7.4 6.2L10.9 8.3L11.5 5.2L14.6 10Z" fill="#19acca"/><path d="M30 12.6L32.6 6.2L29.1 8.3L28.5 5.2L25.4 10Z" fill="#19acca"/><path d="M20 8C27.6 8 33 13.2 33 20.8C33 29.2 27.6 35.6 20 35.6S7 29.2 7 20.8C7 13.2 12.4 8 20 8Z" fill="#19acca"/><path d="M11.2 25.4C11.8 31.2 15.4 34.2 20 34.2S28.2 31.2 28.8 25.4C26.2 27 23.2 27.8 20 27.8S13.8 27 11.2 25.4Z" fill="#bfeaf3"/><path d="M14.4 29.6l1.5 1.1 1.5-1.1M22.6 29.6l1.5 1.1 1.5-1.1" fill="none" stroke="#19acca" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="14.6" cy="17.6" r="5.2" fill="#fff"/><circle cx="25.4" cy="17.6" r="5.2" fill="#fff"/><circle cx="14.6" cy="17.8" r="2.7" fill="#0f172a"/><circle cx="25.4" cy="17.8" r="2.7" fill="#0f172a"/><circle cx="15.6" cy="16.8" r="0.85" fill="#fff"/><circle cx="26.4" cy="16.8" r="0.85" fill="#fff"/><path d="M18.2 22.8L20 26L21.8 22.8Q20 23.4 18.2 22.8Z" fill="#f5b942"/><path d="M15.5 36.6v1.6M18 36.6v1.6M22 36.6v1.6M24.5 36.6v1.6" stroke="#f5b942" stroke-width="1.4" stroke-linecap="round"/></g></svg>' },
    { name: 'Snoka', url: null, svg: '<svg aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 36"><rect width="36" height="36" rx="8" fill="#5f7d2b"/><path d="M22.4 22.4L29 29" stroke="#fff" stroke-width="5" stroke-linecap="round"/><circle cx="15.5" cy="15.5" r="9.2" fill="#fff"/><path d="M11.2 13.4H19.8M11.2 17.6H16.6" stroke="#5f7d2b" stroke-width="2.3" stroke-linecap="round"/></svg>' },
    { name: 'Hamstra', url: null, svg: '<svg aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 36"><rect width="36" height="36" rx="8" fill="#c46c1d"/><g transform="translate(0.4 2.2) scale(0.88)"><circle cx="11.4" cy="8.8" r="3.3" fill="#8c4a12"/><circle cx="28.6" cy="8.8" r="3.3" fill="#8c4a12"/><circle cx="11.4" cy="9" r="1.7" fill="#f2a39c"/><circle cx="28.6" cy="9" r="1.7" fill="#f2a39c"/><path d="M20 6C27 6 31 10.5 31.6 15.5C35.6 17.5 37.4 22 36.6 26.5C35.8 32 30.5 36.4 20 36.4S4.2 32 3.4 26.5C2.6 22 4.4 17.5 8.4 15.5C9 10.5 13 6 20 6Z" fill="#e39a3b"/><path d="M8.6 18.6C5.6 20.2 4.6 23.4 5.2 26.6C5.9 30.7 10.4 34.2 20 34.2S34.1 30.7 34.8 26.6C35.4 23.4 34.4 20.2 31.4 18.6C27.8 20.6 24 21.6 20 21.6S12.2 20.6 8.6 18.6Z" fill="#fffaf3"/><circle cx="15.2" cy="15.6" r="2" fill="#2b1b10"/><circle cx="24.8" cy="15.6" r="2" fill="#2b1b10"/><circle cx="15.8" cy="15" r="0.65" fill="#fff"/><circle cx="25.4" cy="15" r="0.65" fill="#fff"/><path d="M18.6 19.4Q20 18.8 21.4 19.4Q21 20.8 20 21Q19 20.8 18.6 19.4Z" fill="#e57f86"/><path d="M20 21V21.8M18.2 21.8Q19.1 22.9 20 21.8Q20.9 22.9 21.8 21.8" fill="none" stroke="#9a5a2a" stroke-width="0.8" stroke-linecap="round" stroke-linejoin="round"/><ellipse cx="9.4" cy="24.6" rx="2.4" ry="1.5" fill="#f7c9bc"/><ellipse cx="30.6" cy="24.6" rx="2.4" ry="1.5" fill="#f7c9bc"/><ellipse cx="13.6" cy="36.2" rx="3" ry="1.5" fill="#fbd5cf"/><ellipse cx="26.4" cy="36.2" rx="3" ry="1.5" fill="#fbd5cf"/></g></svg>' }
  ];

  // The shared footer (the kit's docs/layout.md). Left: what this is and how fresh. Right: the
  // credit, or "Powered by Puls" under white-label. FOOTER_CREDIT=false turns the credit off
  // The install's time zone, named only when its clock differs from the viewer's, in plain words
  // ("Stockholm time", "UTC"): then the times are not the viewer's own, and an install still on
  // its server's UTC gets noticed. serverSkew is the install's offset, give or take clock drift
  function zoneNote() {
    var tz = lastData && lastData.timezone;
    var viewerOffset = -new Date().getTimezoneOffset() * 60000;
    if (!tz || Math.abs(serverSkew - viewerOffset) < 10 * 60000) return '';
    return ', ' + (tz.indexOf('/') < 0 || tz.indexOf('Etc/') === 0 ? tz.replace('Etc/', '') : tz.split('/').pop().replace(/_/g, ' ') + ' time');
  }

  function renderFooter() {
    var whiteLabel = appName !== 'Puls';
    var now = new Date(Date.now() + serverSkew);
    var updated = clock(now.getUTCHours(), now.getUTCMinutes()) + zoneNote();
    var left = esc(appName) + (whiteLabel ? '' : ' ' + esc(branding.version || '')) + ' · from the tracking script, updated ' + updated;
    var right = '';
    if (whiteLabel) {
      right = '<span>Powered by <a href="https://github.com/webready-se/puls" target="_blank" rel="noopener">Puls</a></span>';
    } else if (branding.credit !== false) {
      var publicMarks = familyMarks.filter(function(m) { return m.url; });
      right = '<span>Made by <a href="https://webready.se" target="_blank" rel="noopener">Webready</a> in Sweden</span>' +
        (publicMarks.length ? '<span class="foot-marks">' + publicMarks.map(function(m) {
          return '<a href="' + m.url + '" target="_blank" rel="noopener" title="' + m.name + '" aria-label="' + m.name + ' on GitHub">' + m.svg + '</a>';
        }).join('') + '</span>' : '');
    }
    return '<footer class="foot"><span>' + left + '</span>' + (right ? '<span class="foot-credit">' + right + '</span>' : '') + '</footer>';
  }

  function renderSnippet(site) {
    var host = location.origin;
    var siteVal = currentSite || 'my-site';

    return '<div class="snippet">' +
      '<h3>Tracking snippet</h3>' +
      '<p>Paste before &lt;/body&gt; on your site. The pixel catches bots that don\'t run JS.</p>' +
      '<pre id="snippet-code" onclick="copySnippet(this)">' +
        '&lt;script src="' + host + '/?js" data-site="' + esc(siteVal) + '" data-outbound defer&gt;&lt;/script&gt;\n' +
        '&lt;noscript&gt;&lt;img src="' + host + '/?pixel&amp;s=' + esc(siteVal) + '&amp;p=/" alt="" width="1" height="1"&gt;&lt;/noscript&gt;' +
      '</pre>' +
    '</div>' +

    '<div class="snippet">' +
      '<h3>Trackable link</h3>' +
      '<p>Create a link that shows where visitors came from in the dashboard.</p>' +

      '<div class="utm-step">' +
        '<div class="utm-step-label"><span class="utm-step-num active">1</span> Which page should the link go to?</div>' +
        '<div style="display:flex;gap:8px;align-items:center">' +
          '<input type="text" id="utm-domain" value="' + esc(utmDomain()) + '" placeholder="https://your-site.com" oninput="updateUtm()" style="width:45%">' +
          '<input type="text" id="utm-path" placeholder="/ (homepage)" oninput="updateUtm()" style="flex:1">' +
        '</div>' +
      '</div>' +

      '<div class="utm-step">' +
        '<div class="utm-step-label"><span class="utm-step-num" id="utm-step2-num">2</span> Where will you share the link?</div>' +
        '<div class="utm-channels" id="utm-channels">' +
          '<div class="utm-ch-btn" data-source="facebook" data-medium="social" onclick="selectUtmChannel(this)"><span class="utm-ch-icon">f</span>Facebook</div>' +
          '<div class="utm-ch-btn" data-source="instagram" data-medium="social" onclick="selectUtmChannel(this)"><span class="utm-ch-icon">ig</span>Instagram</div>' +
          '<div class="utm-ch-btn" data-source="linkedin" data-medium="social" onclick="selectUtmChannel(this)"><span class="utm-ch-icon">in</span>LinkedIn</div>' +
          '<div class="utm-ch-btn" data-source="twitter" data-medium="social" onclick="selectUtmChannel(this)"><span class="utm-ch-icon">X</span>Twitter/X</div>' +
          '<div class="utm-ch-btn" data-source="newsletter" data-medium="email" onclick="selectUtmChannel(this)"><span class="utm-ch-icon">\u2709</span>Newsletter</div>' +
          '<div class="utm-ch-btn" data-source="website" data-medium="referral" onclick="selectUtmChannel(this)"><span class="utm-ch-icon">www</span>Website</div>' +
        '</div>' +
        '<div id="utm-custom-wrap" style="display:none;margin-top:8px">' +
          '<div class="snippet-grid">' +
            '<div><label>Source</label><input type="text" id="utm-source-custom" placeholder="e.g. podcast" oninput="updateUtm()"></div>' +
            '<div><label>Medium</label>' +
              '<select id="utm-medium-custom" onchange="updateUtm()">' +
                '<option value="social">Social</option>' +
                '<option value="email">Email</option>' +
                '<option value="referral">Referral</option>' +
                '<option value="cpc">Ad (CPC)</option>' +
              '</select>' +
            '</div>' +
          '</div>' +
        '</div>' +
        '<div style="margin-top:8px;text-align:center">' +
          '<span class="utm-ch-btn" style="display:inline-block;width:auto;padding:6px 16px;font-size:11px" id="utm-other-btn" onclick="toggleUtmCustom()">Other source...</span>' +
        '</div>' +
      '</div>' +

      '<div class="utm-step">' +
        '<div class="utm-step-label"><span class="utm-step-num" id="utm-step3-num">3</span> Campaign name <span style="color:#64748b;font-weight:400">(optional)</span></div>' +
        '<input type="text" id="utm-campaign" placeholder="e.g. spring-campaign, black-friday" oninput="updateUtm()">' +
      '</div>' +

      '<div id="utm-result-wrap" style="display:none">' +
        '<div class="utm-result-box">' +
          '<div class="utm-result-url" id="utm-result-url"></div>' +
          '<button class="utm-copy-btn" id="utm-copy-btn" onclick="copyUtmLink()">Copy link</button>' +
        '</div>' +
      '</div>' +
      '<div id="utm-placeholder" class="utm-placeholder">Choose where to share the link</div>' +

      '<span class="utm-advanced-toggle" onclick="toggleUtmAdvanced()">' +
        '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>' +
        'Advanced' +
      '</span>' +
      '<div class="utm-advanced" id="utm-advanced">' +
        '<div class="snippet-grid">' +
          '<div><label>source</label><input type="text" id="utm-adv-source" oninput="updateUtmFromAdvanced()"></div>' +
          '<div><label>medium</label><input type="text" id="utm-adv-medium" oninput="updateUtmFromAdvanced()"></div>' +
        '</div>' +
      '</div>' +
    '</div>' +

    '<div class="snippet">' +
      '<h3>Server-side bot tracking (Nginx)</h3>' +
      '<p>Catch bots that don\'t load images or run JavaScript. Add to Nginx config per site.</p>' +
      '<pre id="nginx-snippet" onclick="copySnippet(this)">' +
        '# Outside the server block\n' +
        'map $http_user_agent $is_bot {\n' +
        '    default 0;\n' +
        '    ~*(bot|crawl|spider|GPTBot|ClaudeBot|Bytespider|Meta-ExternalAgent|Applebot|Ahrefs|Semrush|facebookexternalhit) 1;\n' +
        '    ~*(axios|python-requests|Go-http-client|curl/|wget/|httpie|node-fetch|undici|ruby|perl|java/|scrapy|puppeteer|playwright|HeadlessChrome|PhantomJS|Pingdom) 1;\n' +
        '}\n' +
        '\n' +
        '# Inside the server block\n' +
        'location / {\n' +
        '    mirror /_puls_log;\n' +
        '    # ...existing config (try_files etc.)\n' +
        '}\n' +
        '\n' +
        '# Bot tracking (200 responses)\n' +
        'location = /_puls_log {\n' +
        '    internal;\n' +
        '    if ($is_bot = "0") { return 204; }\n' +
        '    resolver 8.8.8.8;\n' +
        '    proxy_ssl_verify off;\n' +
        '    proxy_ssl_server_name on;\n' +
        '    proxy_pass ' + location.origin + '/?log&amp;s=$host&amp;p=$request_uri;\n' +
        '    proxy_set_header User-Agent $http_user_agent;\n' +
        '}\n' +
        '\n' +
        '# Broken link tracking (404/301)\n' +
        '# PHP site: add post_action in location ~ \\.php$\n' +
        '# Static site: add post_action in error_page location\n' +
        'error_page 404 /404.html;\n' +
        'location = /404.html {\n' +
        '    internal;\n' +
        '    post_action @_puls_status_log;  # static site\n' +
        '}\n' +
        '# location ~ \\.php$ {\n' +
        '#     post_action @_puls_status_log;  # PHP site\n' +
        '# }\n' +
        '\n' +
        'location @_puls_status_log {\n' +
        '    internal;\n' +
        '    if ($status = 200) { return 204; }\n' +
        '    if ($status = 204) { return 204; }\n' +
        '    if ($status = 304) { return 204; }\n' +
        '    resolver 8.8.8.8;\n' +
        '    proxy_ssl_verify off;\n' +
        '    proxy_ssl_server_name on;\n' +
        '    proxy_pass ' + location.origin + '/?status_log&amp;s=$host&amp;p=$request_uri&amp;status=$status;\n' +
        '    proxy_set_header User-Agent $http_user_agent;\n' +
        '    proxy_set_header X-Original-Referer $http_referer;\n' +
        '}' +
      '</pre>' +
      '<p style="margin-top:12px;margin-bottom:0"><strong style="color:#f1f5f9">How does it work?</strong> Nginx sends a copy of bot requests to Puls in the background (mirror). ' +
        'Broken links (404) are caught via post_action after the response is sent. ' +
        'PHP site: add post_action in the PHP block. Static site: in the error_page location. Zero impact on response time.</p>' +
    '</div>';
  }

  // UTM wizard state
  var utmState = { source: '', medium: '', custom: false };

  function utmDomain() {
    var site = currentSite || '';
    if (site && site.indexOf('.') !== -1) return 'https://' + site;
    if (site) return 'https://' + site + '.com';
    return 'https://your-site.com';
  }

  window.selectUtmChannel = function(el) {
    // Deselect all
    var btns = document.getElementById('utm-channels').querySelectorAll('.utm-ch-btn');
    for (var i = 0; i < btns.length; i++) btns[i].classList.remove('selected');
    el.classList.add('selected');

    utmState.source = el.dataset.source;
    utmState.medium = el.dataset.medium;
    utmState.custom = false;
    document.getElementById('utm-custom-wrap').style.display = 'none';
    document.getElementById('utm-other-btn').classList.remove('selected');

    // Sync advanced fields
    var advSrc = document.getElementById('utm-adv-source');
    var advMed = document.getElementById('utm-adv-medium');
    if (advSrc) advSrc.value = utmState.source;
    if (advMed) advMed.value = utmState.medium;

    document.getElementById('utm-step2-num').classList.add('active');
    updateUtm();
  };

  window.toggleUtmCustom = function() {
    var wrap = document.getElementById('utm-custom-wrap');
    var btn = document.getElementById('utm-other-btn');
    var isOpen = wrap.style.display !== 'none';
    wrap.style.display = isOpen ? 'none' : 'block';
    btn.classList.toggle('selected', !isOpen);

    if (!isOpen) {
      // Deselect preset channels
      var btns = document.getElementById('utm-channels').querySelectorAll('.utm-ch-btn');
      for (var i = 0; i < btns.length; i++) btns[i].classList.remove('selected');
      utmState.custom = true;
      utmState.source = '';
      utmState.medium = '';
      document.getElementById('utm-source-custom').focus();
    }
    updateUtm();
  };

  window.toggleUtmAdvanced = function() {
    var el = document.getElementById('utm-advanced');
    el.classList.toggle('open');
  };

  window.updateUtmFromAdvanced = function() {
    utmState.source = document.getElementById('utm-adv-source').value.trim();
    utmState.medium = document.getElementById('utm-adv-medium').value.trim();

    // Deselect visual buttons to avoid confusion
    var btns = document.getElementById('utm-channels');
    if (btns) {
      var all = btns.querySelectorAll('.utm-ch-btn');
      for (var i = 0; i < all.length; i++) all[i].classList.remove('selected');
    }
    document.getElementById('utm-step2-num').classList.add('active');
    updateUtm();
  };

  function updateUtm() {
    var source = utmState.custom
      ? document.getElementById('utm-source-custom').value.trim()
      : utmState.source;
    var medium = utmState.custom
      ? document.getElementById('utm-medium-custom').value
      : utmState.medium;
    var campaign = document.getElementById('utm-campaign').value.trim();
    var pathInput = document.getElementById('utm-path');
    var path = pathInput ? pathInput.value.trim() : '';
    var domainInput = document.getElementById('utm-domain');
    var domain = domainInput ? domainInput.value.trim() : utmDomain();

    var resultWrap = document.getElementById('utm-result-wrap');
    var placeholder = document.getElementById('utm-placeholder');
    var resultUrl = document.getElementById('utm-result-url');
    var step3 = document.getElementById('utm-step3-num');

    // Sync advanced fields (if not typing there)
    var advSrc = document.getElementById('utm-adv-source');
    var advMed = document.getElementById('utm-adv-medium');
    if (advSrc && document.activeElement !== advSrc) advSrc.value = source;
    if (advMed && document.activeElement !== advMed) advMed.value = medium;

    if (campaign) step3.classList.add('active');
    else step3.classList.remove('active');

    if (!source) {
      resultWrap.style.display = 'none';
      placeholder.style.display = 'block';
      return;
    }

    if (!domain) domain = utmDomain();
    if (domain.indexOf('http') !== 0) domain = 'https://' + domain;
    var base = domain + (path.indexOf('/') === 0 ? path : '/' + path);
    if (base.slice(-1) === '/' && base.length > domain.length + 1) {
      base = base.slice(0, -1);
    }

    var params = 'utm_source=' + encodeURIComponent(source);
    if (medium) params += '&utm_medium=' + encodeURIComponent(medium);
    if (campaign) params += '&utm_campaign=' + encodeURIComponent(campaign);

    var sep = base.indexOf('?') === -1 ? '?' : '&';
    var fullUrl = base + sep + params;

    // Show with highlighted params
    resultUrl.innerHTML = esc(base) + '<span class="utm-highlight">' + esc(sep + params) + '</span>';
    resultWrap.style.display = 'block';
    placeholder.style.display = 'none';

    // Reset copy button
    var btn = document.getElementById('utm-copy-btn');
    btn.textContent = 'Copy link';
    btn.classList.remove('copied');
    btn.dataset.url = fullUrl;
  }

  window.copyUtmLink = function() {
    var btn = document.getElementById('utm-copy-btn');
    var url = btn.dataset.url;
    if (!url) return;
    navigator.clipboard.writeText(url).then(function() {
      btn.textContent = 'Copied!';
      btn.classList.add('copied');
      setTimeout(function() {
        btn.textContent = 'Copy link';
        btn.classList.remove('copied');
      }, 2000);
    });
  };

  window.copySnippet = function(el) {
    var text = el.textContent;
    if (!text || !text.trim()) return;
    navigator.clipboard.writeText(text).then(function() {
      el.classList.add('copied');
      setTimeout(function() { el.classList.remove('copied'); }, 1500);
    });
  };

  window.updateUtm = updateUtm;

  window.clearPathFilter = function() {
    currentPath = '';
    load(currentDays);
  };

  window.showAll = function(type, title) {
    var renderFn = expandRenderers[type];
    var overlay = document.getElementById('overlay');
    var overlayTitle = document.getElementById('overlay-title');
    var overlayContent = document.getElementById('overlay-content');
    overlayTitle.textContent = title;
    overlayContent.innerHTML = '<p style="color:var(--text-2);font-size:13px">Loading...</p>';
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    dialogOpened(overlay.querySelector('.overlay-panel'));

    var url = API + '?api&days=' + currentDays + '&expand=' + type;
    if (currentSite) url += '&site=' + encodeURIComponent(currentSite);
    if (currentPath) url += '&path=' + encodeURIComponent(currentPath);
    if (currentChannel) url += '&channel=' + encodeURIComponent(currentChannel);
    fetch(url)
      .then(function(r) { return r.json(); })
      .then(function(d) { overlayContent.innerHTML = renderFn(d); });
  };

  window.closeOverlay = function() {
    var overlay = document.getElementById('overlay');
    if (!overlay.classList.contains('open')) return;
    overlay.classList.remove('open');
    document.body.style.overflow = '';
    dialogClosed();
  };

  window.showBrokenAll = function(title, color, showStatus) {
    var overlay = document.getElementById('overlay');
    var overlayTitle = document.getElementById('overlay-title');
    var overlayContent = document.getElementById('overlay-content');
    overlayTitle.textContent = title;
    var links = lastData.brokenLinks || [];
    var filtered;
    if (title.indexOf('404') >= 0) filtered = links.filter(function(l) { return l.status === 404; });
    else if (title.indexOf('301') >= 0) filtered = links.filter(function(l) { return l.status === 301 || l.status === 302; });
    else filtered = links.filter(function(l) { return l.status !== 404 && l.status !== 301 && l.status !== 302; });
    overlayContent.innerHTML = renderBrokenGroup(filtered, color, showStatus);
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    dialogOpened(overlay.querySelector('.overlay-panel'));
  };

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeOverlay();
  });

  window.toggleBrokenGroup = function(btn) {
    var id = btn.getAttribute('data-bl-toggle');
    var rows = document.querySelectorAll('[data-bl-group="' + id + '"]');
    var showing = rows[0] && rows[0].style.display !== 'none';
    rows.forEach(function(r) { r.style.display = showing ? 'none' : ''; });
    btn.textContent = showing ? 'Show all ' + (rows.length + 5) : 'Show less';
  };

  // --- Menu ---
  var menuOverlay = document.getElementById('menu-overlay');
  var menuPanel = document.getElementById('menu-panel');

  window.toggleDatePicker = function(e) {
    e.stopPropagation();
    var isOpen = datePicker.classList.toggle('open');
    if (isOpen) {
      dateFromInput.value = currentFrom || '';
      dateToInput.value = currentTo || '';
      if (!dateToInput.value) dateToInput.value = new Date().toISOString().slice(0, 10);
      if (!dateFromInput.value) {
        var d = new Date(); d.setDate(d.getDate() - 7);
        dateFromInput.value = d.toISOString().slice(0, 10);
      }
      dateToInput.max = new Date().toISOString().slice(0, 10);
    }
  };

  window.applyDateRange = function() {
    var from = dateFromInput.value;
    var to = dateToInput.value;
    if (!from || !to || from > to) return;
    currentFrom = from;
    currentTo = to;
    currentDays = Math.max(1, Math.round((new Date(to) - new Date(from)) / 86400000) + 1);
    periodGroup.querySelectorAll('.period-btn').forEach(function(b) { b.classList.remove('active'); });
    customRangeBtn.classList.add('active');
    customRangeBtn.textContent = formatDateLabel(from) + ' – ' + formatDateLabel(to);
    datePicker.classList.remove('open');
    if (!isShared) {
      localStorage.setItem('puls-from', from);
      localStorage.setItem('puls-to', to);
      localStorage.removeItem('puls-days');
    }
    load(currentDays);
  };

  function formatDateLabel(dateStr) {
    var d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  }

  // Close date picker when clicking outside
  document.addEventListener('click', function(e) {
    if (datePicker.classList.contains('open') && !datePicker.contains(e.target) && e.target !== customRangeBtn) {
      datePicker.classList.remove('open');
    }
  });

  var eventChartMode = localStorage.getItem('puls-event-mode') === 'rate' ? 'rate' : 'count';

  window.loadEventDetail = function(eventName, el) {
    // Toggle: close if already open
    var existing = el.nextElementSibling;
    if (existing && existing.classList.contains('event-detail')) {
      existing.remove();
      return;
    }
    // Close any other open detail
    document.querySelectorAll('.event-detail').forEach(function(d) { d.remove(); });

    openEventDetail(el, eventName, null, false);
  };

  // quiet: reopened after a refresh, so do not announce it again
  function openEventDetail(row, eventName, group, showAll, then, quiet) {
    var detail = document.createElement('div');
    detail.className = 'event-detail';
    detail.innerHTML = '<span style="color:var(--text-2)">Loading...</span>';
    row.after(detail);
    fetchEventDetail(detail, eventName, group, showAll, then, quiet);
  }

  // group: null = let the API pick the most common key, '' = recent rows, 'key' = group on it
  function eventApiUrl(eventName) {
    var base = currentFrom && currentTo ? '?api&from=' + currentFrom + '&to=' + currentTo : '?api&days=' + currentDays;
    var url = apiUrl(base + '&event=' + encodeURIComponent(eventName));
    if (currentSite) url += '&site=' + encodeURIComponent(currentSite);
    return url;
  }

  function fetchEventDetail(detail, eventName, group, showAll, then, quiet) {
    var url = eventApiUrl(eventName);
    if (group !== null) url += '&group=' + encodeURIComponent(group);
    if (then) url += '&then=' + encodeURIComponent(then);

    fetch(url)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        detail._state = { name: eventName, data: data, showAll: !!showAll, then: then || '' };
        renderEventDetail(detail);
        var tot = data.eventTotals || { count: 0, visitors: 0 };
        if (!quiet) announce(eventName.replace(/_/g, ' ') + ': ' + num(tot.count) + ' events from ' + num(tot.visitors) + ' visitors' + (data.funnel && data.funnel.rate !== null ? ', ' + data.funnel.rate + '% went on to ' + data.funnel.then.replace(/_/g, ' ') : ''));
      })
      .catch(function() { detail.innerHTML = '<span style="color:var(--text-2)">Failed to load</span>'; });
  }

  window.setEventGroup = function(btn) {
    var detail = btn.closest('.event-detail');
    fetchEventDetail(detail, detail._state.name, btn.getAttribute('data-key'), false, detail._state.then);
  };

  window.setEventFunnel = function(sel) {
    var detail = sel.closest('.event-detail');
    var st = detail._state;
    fetchEventDetail(detail, st.name, st.data.eventGroupKey || '', st.showAll, sel.value);
  };

  window.setEventChartMode = function(btn) {
    eventChartMode = btn.getAttribute('data-mode');
    localStorage.setItem('puls-event-mode', eventChartMode);
    renderEventDetail(btn.closest('.event-detail'));
  };

  window.showAllEventGroup = function(btn) {
    var detail = btn.closest('.event-detail');
    detail._state.showAll = true;
    renderEventDetail(detail);
  };

  function renderEventDetail(detail) {
    var st = detail._state, d = st.data;
    var tot = d.eventTotals || { count: 0, visitors: 0 };
    var prev = d.previousEventTotals || { count: 0, visitors: 0 };
    var siteVisitors = d.totals ? +d.totals.visitors : 0;
    var rate = siteVisitors ? (tot.count / siteVisitors * 100) : 0;
    var delta = prev.count > 0 ? Math.round(((tot.count - prev.count) / prev.count) * 100) : null;
    var deltaHtml = delta === null ? '' :
      '<span style="color:var(--' + (delta >= 0 ? 'green' : 'red') + ')">' + (delta >= 0 ? '\u25B2' : '\u25BC') + Math.abs(delta) + '% vs previous</span>';

    var html = '<div class="event-summary">' +
      '<span><b>' + num(tot.count) + '</b> events</span>' +
      '<span><b>' + num(tot.visitors) + '</b> visitors</span>' +
      (siteVisitors ? '<span><b>' + rate.toFixed(1) + '</b> per 100 visitors</span>' : '') +
      deltaHtml +
      '<a class="event-csv" href="' + eventApiUrl(st.name) + '&format=csv" title="Every row in the period, one column per data key">CSV</a>' +
    '</div>';

    if (d.eventSeries && d.eventSeries.length) {
      var showCompare = compareMode && d.previousEventSeries && d.previousEventSeries.length > 0;
      var dense = d.eventSeries.length > 31;
      html += '<div class="event-chips">' +
        '<button class="event-chip' + (eventChartMode === 'count' ? ' active' : '') + '" data-mode="count" onclick="setEventChartMode(this)">Count</button>' +
        '<button class="event-chip' + (eventChartMode === 'rate' ? ' active' : '') + '" data-mode="rate" onclick="setEventChartMode(this)">Per 100 visitors</button>' +
        (showCompare ? '<span class="event-chips-label" style="margin-left:auto">vs previous period</span>' : '') +
      '</div>' +
      '<div class="chart-scroll event-chart' + (showCompare ? ' compare' : '') + (dense ? ' dense' : '') + (marksFor(d, d.eventSeries.map(function(r) { return r.date; })).stacked ? ' stacked-marks' : '') + '"><div class="chart">' +
        buildEventBars(d.eventSeries, showCompare ? d.previousEventSeries : null, d.pageviews, d.previousPageviews, dense, d) +
      '</div></div>';
    }

    html += renderEventFunnel(st, d);

    var keys = d.eventKeys || [];
    if (keys.length) {
      html += '<div class="event-chips"><span class="event-chips-label">Group by</span>';
      keys.forEach(function(k) {
        html += '<button class="event-chip' + (k.key === d.eventGroupKey ? ' active' : '') + '" data-key="' + esc(k.key) + '" onclick="setEventGroup(this)">' + esc(k.key === '@version' ? 'version' : k.key) + '</button>';
      });
      var canSave = !isShared && d.eventGroupKey && (currentSite || sites.length === 1);
      var saved = canSave && lastData && (lastData.breakdowns || []).some(function(b) { return b.event === st.name && b.group === d.eventGroupKey; });
      html += '<button class="event-chip' + (!d.eventGroupKey ? ' active' : '') + '" data-key="" onclick="setEventGroup(this)">Recent</button>' +
        (d.eventGroupKey && d.eventGroupNew ? '<span class="event-chips-label" style="margin-left:auto">' + num(d.eventGroupNew) + ' new vs previous period</span>' : '') +
        (canSave ? '<button class="event-chip event-save"' + (saved ? ' disabled' : '') + ' onclick="saveBreakdown(this)" title="Show this grouping as its own card on the dashboard">' + (saved ? 'Saved' : 'Save as card') + '</button>' : '') +
      '</div>';
    }

    if (d.eventGroupKey && d.eventGroup) {
      html += renderEventGroup(d.eventGroup, st.showAll);
    } else {
      html += renderEventRecent(d.eventDetail || []);
    }
    detail.innerHTML = html;
    showLatestDays(detail);
  }

  // Two-step funnel: of the visitors who did this event, how many did the chosen one after it
  function renderEventFunnel(st, d) {
    var names = d.eventNames || [];
    // Free text with suggestions, so an event not seen yet can be the second step
    var listId = 'funnel-events-' + Math.random().toString(36).slice(2);
    var html = '<div class="event-chips"><span class="event-chips-label">Then</span>' +
      '<input class="event-select" list="' + listId + '" value="' + esc(st.then || '') + '" placeholder="Event name…" onchange="setEventFunnel(this)" aria-label="Second event for funnel" autocomplete="off" spellcheck="false">' +
      '<datalist id="' + listId + '">';
    names.forEach(function(n) {
      html += '<option value="' + esc(n) + '">';
    });
    html += '</datalist></div>';

    var f = d.funnel;
    if (!f) return html;
    if (!f.started) {
      return html + '<div class="event-funnel"><div class="event-funnel-head"><span>No visitors did <b>' + esc(st.name.replace(/_/g, ' ')) + '</b> in this period yet, so there is nothing to follow to <b>' + esc(f.then.replace(/_/g, ' ')) + '</b>.</span></div></div>';
    }
    var delta = f.rate !== null && f.previousRate !== null ? Math.round((f.rate - f.previousRate) * 10) / 10 : null;
    html += '<div class="event-funnel">' +
      '<div class="event-funnel-head">' +
        '<span class="event-funnel-rate">' + (f.rate === null ? '–' : f.rate.toFixed(1) + '%') + '</span>' +
        '<span>' + num(f.converted) + ' of ' + num(f.started) + ' visitors went on to <b>' + esc(f.then.replace(/_/g, ' ')) + '</b> the same day</span>' +
        (delta === null ? '' : '<span style="color:var(--' + (delta >= 0 ? 'green' : 'red') + ')">' + (delta >= 0 ? '\u25B2' : '\u25BC') + Math.abs(delta) + ' pts vs previous</span>') +
      '</div>' +
      '<div class="event-funnel-days">';
    var maxStarted = Math.max.apply(null, f.series.map(function(r) { return r.started; }).concat([1]));
    f.series.forEach(function(r) {
      var h = Math.max(2, r.started / maxStarted * 36);
      var ch = r.started ? h * r.converted / r.started : 0;
      var pct = r.started ? Math.round(100 * r.converted / r.started) + '%' : 'no starts';
      html += '<div class="event-funnel-day" title="' + formatDay(r.date) + ': ' + num(r.converted) + ' of ' + num(r.started) + ' (' + pct + ')">' +
        '<div class="event-funnel-bar" style="height:' + h + 'px"><div class="event-funnel-fill" style="height:' + ch + 'px"></div></div>' +
      '</div>';
    });
    html += '</div></div>';
    return html;
  }

  function buildEventBars(series, prevSeries, pageviews, previousPageviews, dense, d) {
    var marks = marksFor(d, series.map(function(r) { return r.date; })).byDate;
    var visitorsByDate = {};
    (pageviews || []).concat(previousPageviews || []).forEach(function(p) { visitorsByDate[p.date] = +p.visitors; });
    function val(row) {
      if (eventChartMode === 'rate') {
        var v = visitorsByDate[row.date] || 0;
        return v ? Math.round(row.count / v * 1000) / 10 : 0;
      }
      return +row.count;
    }
    function fmt(v) { return eventChartMode === 'rate' ? v.toFixed(1) : num(v); }
    var vals = series.map(val);
    if (prevSeries) vals = vals.concat(prevSeries.map(val));
    var maxVal = Math.max.apply(null, vals.concat([1]));
    // Labels sit mid-step so the first and last are not clipped by the chart edges
    var labelStep = dense ? Math.ceil(series.length / 6) : 1;
    var labelOffset = Math.floor(labelStep / 2);
    var bars = '';
    series.forEach(function(row, i) {
      var v = val(row), h = Math.max(3, (v / maxVal) * 60), label = formatDay(row.date);
      var tipBody = num(row.count) + ' events, ' + num(row.visitors) + ' visitors';
      // Dense ranges: no per-bar values, about six date labels, tooltip keeps the detail
      var valHtml = dense ? '' : '<span class="chart-val">' + fmt(v) + '</span>';
      var dateHtml = '<span class="chart-date">' + (dense && i % labelStep !== labelOffset ? '&nbsp;' : label) + '</span>';
      if (prevSeries) {
        var p = prevSeries[i], pv = p ? val(p) : 0, ph = p ? Math.max(3, (pv / maxVal) * 60) : 0;
        var delta = pv > 0 ? Math.round(((v - pv) / pv) * 100) : null;
        var deltaStr = delta === null ? '' : (delta >= 0 ? ' (+' + delta + '%)' : ' (' + delta + '%)');
        bars += '<div class="chart-col chart-col-compare' + (marks[row.date] ? marks[row.date].cls : '') + '">' +
          (marks[row.date] ? marks[row.date].html : '') +
          '<div class="tip" style="text-align:left"><div style="font-weight:600;margin-bottom:4px">' + label + '<span style="color:' + (delta === null ? '#64748b' : (delta >= 0 ? '#059669' : '#dc2626')) + ';font-weight:500">' + deltaStr + '</span></div>' +
            '<div>' + tipBody + '</div>' +
            (p ? '<div style="color:var(--text-3);margin-top:2px">vs ' + formatDay(p.date) + ': ' + num(p.count) + ' / ' + num(p.visitors) + '</div>' : '') +
          '</div>' +
          '<div class="chart-bars">' +
            '<div class="chart-bar-wrap">' + valHtml + '<div class="chart-bar" style="height:' + h + 'px"></div></div>' +
            '<div class="chart-bar-wrap">' + (dense ? '' : '<span class="chart-val-prev">' + fmt(pv) + '</span>') + '<div class="chart-bar chart-bar-prev" style="height:' + ph + 'px"></div></div>' +
          '</div>' +
          dateHtml +
        '</div>';
      } else {
        bars += '<div class="chart-col' + (marks[row.date] ? marks[row.date].cls : '') + '">' +
          (marks[row.date] ? marks[row.date].html : '') +
          valHtml +
          '<div class="chart-bar" style="height:' + h + 'px"><div class="tip">' + label + ': ' + tipBody + '</div></div>' +
          dateHtml +
        '</div>';
      }
    });
    return bars;
  }

  function renderEventGroup(rows, showAll) {
    if (!rows.length) return '<span style="color:var(--text-2)">No values for this key in the period</span>';
    var limit = showAll ? rows.length : 20;
    var max = Math.max.apply(null, rows.map(function(r) { return +r.count; }));
    var html = '';
    rows.slice(0, limit).forEach(function(r) {
      var value = String(r.value);
      html += '<div class="trow">' +
        '<span class="trow-label" title="' + esc(value) + '">' + esc(value) + ' <span style="color:var(--text-3);font-size:11px">' + num(r.visitors) + '</span>' + (r.new ? '<span class="event-new">new</span>' : '') + '</span>' +
        '<div class="trow-bar"><div class="trow-fill" style="width:' + (+r.count / max * 100) + '%;background:var(--accent-tile)"></div></div>' +
        '<span class="trow-val">' + num(r.count) + '</span>' +
      '</div>';
    });
    if (rows.length > limit) {
      html += '<button class="show-all" onclick="showAllEventGroup(this)">Show all (' + rows.length + ')</button>';
    }
    return html;
  }

  function renderEventRecent(items) {
    if (!items.length) return '<span style="color:var(--text-2)">No detail data</span>';
    var html = '';
    items.slice(0, 20).forEach(function(item) {
      var dataStr = '';
      if (item.event_data) {
        var parts = [];
        for (var k in item.event_data) {
          parts.push(k + ': ' + item.event_data[k]);
        }
        dataStr = parts.join(', ');
      }
      html += '<div class="event-detail-row">' +
        (dataStr ? '<span class="event-detail-data">' + esc(dataStr) + '</span>' : '') +
        '<span class="event-detail-page">' + esc(item.page_path) + '</span>' +
        '<span class="event-detail-time">' + formatTime(item.created_at) + '</span>' +
      '</div>';
    });
    if (items.length > 20) {
      html += '<div style="padding:4px 0;color:var(--text-2);font-size:11px">+ ' + (items.length - 20) + ' more</div>';
    }
    return html;
  }

  window.toggleGoal = function(path) {
    var goalPaths = (lastData && lastData.goals) ? lastData.goals.map(function(g) { return g.path; }) : [];
    var isGoal = goalPaths.indexOf(path) >= 0;
    var site = currentSite || (sites.length === 1 ? sites[0] : '');
    if (!site) return;
    var endpoint = isGoal ? '?goal_remove' : '?goal_add';
    fetch(apiUrl(endpoint), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ site: site, path: path })
    }).then(function() { load(currentDays); });
  };

  window.removeGoal = function(path) {
    var site = currentSite || (sites.length === 1 ? sites[0] : '');
    if (!site) return;
    removeLater('goal:' + site + '\n' + path, 'Goal removed', function(leaving) {
      return fetch(apiUrl('?goal_remove'), {
        method: 'POST',
        keepalive: leaving,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ site: site, path: path })
      });
    });
  };

  window.loadOutboundDetail = function(url, el) {
    var existing = el.nextElementSibling;
    if (existing && existing.classList.contains('event-detail')) {
      existing.remove();
      return;
    }
    document.querySelectorAll('.event-detail').forEach(function(d) { d.remove(); });

    var apiBase = currentFrom && currentTo
      ? apiUrl('?api&from=' + currentFrom + '&to=' + currentTo)
      : apiUrl('?api&days=' + currentDays);
    var fetchUrl = apiBase + '&event=outbound_click&event_url=' + encodeURIComponent(url);
    if (currentSite) fetchUrl += '&site=' + encodeURIComponent(currentSite);

    var detail = document.createElement('div');
    detail.className = 'event-detail';
    detail.innerHTML = '<span style="color:var(--text-2)">Loading...</span>';
    el.after(detail);

    fetch(fetchUrl)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        var items = data.eventDetail || [];
        if (!items.length) {
          detail.innerHTML = '<span style="color:var(--text-2)">No detail data</span>';
          return;
        }
        var html = '';
        items.slice(0, 20).forEach(function(item) {
          var text = item.event_data && item.event_data.text ? item.event_data.text : '';
          html += '<div class="event-detail-row">' +
            (text ? '<span class="event-detail-data">' + esc(text) + '</span>' : '') +
            '<span class="event-detail-page">' + esc(item.page_path) + '</span>' +
            '<span class="event-detail-time">' + formatTime(item.created_at) + '</span>' +
          '</div>';
        });
        if (items.length > 20) {
          html += '<div style="padding:4px 0;color:var(--text-2);font-size:11px">+ ' + (items.length - 20) + ' more</div>';
        }
        detail.innerHTML = html;
      })
      .catch(function() { detail.innerHTML = '<span style="color:var(--text-2)">Failed to load</span>'; });
  };

  // The menu opens as an overview: notes, excluded IPs and goals folded to a line that says what
  // is set, so it is short and one task opens at a time. Not remembered: the menu is opened for
  // a task, and sections left open would make it long again. A link to one section (the note
  // form's "Edit or remove notes in Settings") opens the menu with that section open
  window.openMenu = function(section) {
    menuOverlay.classList.add('open');
    menuPanel.classList.add('open');
    menuPanel.querySelectorAll('.menu-fold-toggle').forEach(function(btn) {
      var open = btn.getAttribute('aria-controls') === section + '-body';
      btn.setAttribute('aria-expanded', String(open));
      document.getElementById(btn.getAttribute('aria-controls')).hidden = !open;
    });
    populateGoalPicker();
    populateNotes();
    populateExcluded();
    dialogOpened(menuPanel);
  };

  window.closeMenu = function() {
    if (!menuPanel.classList.contains('open')) return;
    menuOverlay.classList.remove('open');
    menuPanel.classList.remove('open');
    dialogClosed();
  };

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && menuPanel.classList.contains('open')) closeMenu();
  });

  // --- Chart notes (annotations): "+ Note" on the chart creates, Settings edits and removes ---
  function noteSite() {
    if (isShared) return '';
    return currentSite || (sites.length === 1 ? sites[0] : '');
  }

  function noteFormOpen() {
    var f = document.getElementById('note-form');
    return !!(f && !f.hidden);
  }

  function ymd(date) {
    return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
  }

  function renderNoteForm() {
    var yesterday = ymd(new Date(Date.now() - 86400000));
    return '<form class="note-form" id="note-form" hidden onsubmit="saveNote(event)">' +
      '<label>Date<input type="date" name="date" required value="' + yesterday + '" max="' + ymd(new Date()) + '"></label>' +
      '<label>Until (optional)<input type="date" name="end_date"></label>' +
      '<label class="note-form-text">Note<input type="text" name="label" required maxlength="100" placeholder="What happened, e.g. a film on Facebook"></label>' +
      '<button type="submit">Save</button>' +
      '<button type="button" onclick="toggleNoteForm()">Cancel</button>' +
      '<p class="note-form-hint">Edit or remove notes in <button type="button" onclick="toggleNoteForm(); openMenu(\'notes\')">Settings</button></p>' +
    '</form>';
  }

  window.toggleNoteForm = function() {
    var form = document.getElementById('note-form');
    var btn = document.querySelector('.note-toggle');
    if (!form) return;
    form.hidden = !form.hidden;
    if (btn) btn.setAttribute('aria-expanded', String(!form.hidden));
    if (!form.hidden) form.elements.label.focus();
    else if (btn) btn.focus();
  };

  window.saveNote = function(e) {
    e.preventDefault();
    var form = e.target;
    var date = form.elements.date.value, end = form.elements.end_date.value;
    if (end && end < date) {
      form.elements.end_date.setCustomValidity('Must be on or after the date');
      form.reportValidity();
      form.elements.end_date.setCustomValidity('');
      return;
    }
    form.querySelector('button[type="submit"]').disabled = true;
    fetch(apiUrl('?annotation_add'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ site: noteSite(), date: date, end_date: end, label: form.elements.label.value })
    }).then(function(r) {
      if (!r.ok) throw new Error('Could not save note');
      announce('Note added');
      form.hidden = true;
      load(currentDays);
    }).catch(function() {
      form.querySelector('button[type="submit"]').disabled = false;
      announce('Could not save the note');
    });
  };

  var notesSection = document.getElementById('notes-section');
  var noteList = document.getElementById('note-list');

  // A folded menu section's summary: how many there are, or that there are none
  function menuSummary(id, n, one, many, none) {
    document.getElementById(id + '-summary').textContent = n ? num(n) + ' ' + (n === 1 ? one : many) : none;
  }

  function populateNotes() {
    if (isShared || !lastData) { notesSection.style.display = 'none'; return; }
    notesSection.style.display = '';
    noteList.innerHTML = (lastData.annotations || []).map(function(a) {
      var when = formatDay(a.date) + (a.end_date ? ' to ' + formatDay(a.end_date) : '');
      return '<div class="note-item" data-id="' + (+a.id) + '" data-site="' + esc(a.site) + '">' +
        '<span class="note-item-date">' + esc(when) + '</span>' +
        '<input type="text" maxlength="100" value="' + esc(a.label) + '" aria-label="Note for ' + esc(when) + '" onchange="updateNote(this)">' +
        '<button type="button" aria-label="Remove note for ' + esc(when) + '" onclick="removeNote(this)">&times;</button>' +
      '</div>';
    }).join('');
    notesSummary();
  }

  // Without notes, the open section says where to add one rather than showing nothing
  function notesSummary() {
    var n = noteList.children.length;
    menuSummary('notes', n, 'note', 'notes', 'None in this period');
    document.getElementById('notes-empty').hidden = n > 0;
  }

  function postNote(endpoint, item, extra) {
    var body = { site: item.getAttribute('data-site'), id: +item.getAttribute('data-id') };
    Object.keys(extra || {}).forEach(function(k) { body[k] = extra[k]; });
    return fetch(apiUrl(endpoint), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function(r) { if (!r.ok) throw new Error(); load(currentDays); });
  }

  window.updateNote = function(input) {
    var label = input.value.trim();
    if (!label) return;
    postNote('?annotation_update', input.closest('.note-item'), { label: label })
      .then(function() { announce('Note updated'); }, function() { announce('Could not update the note'); });
  };

  window.removeNote = function(btn) {
    var item = btn.closest('.note-item');
    postNote('?annotation_remove', item)
      .then(function() {
        var next = item.nextElementSibling || item.previousElementSibling;
        item.remove();
        notesSummary();
        (next ? next.querySelector('input') : menuPanel.querySelector('.menu-close')).focus();
        announce('Note removed');
      }, function() { announce('Could not remove the note'); });
  };

  // --- Excluded IPs: the site's list and the viewer's own address, editable in Settings ---
  var excludedSection = document.getElementById('excluded-section');
  var excludedMine = document.getElementById('excluded-mine');
  var excludedList = document.getElementById('excluded-list');
  var excludedForm = document.getElementById('excluded-form');

  function populateExcluded() {
    if (isShared) { excludedSection.style.display = 'none'; return; }
    excludedSection.style.display = '';
    var site = noteSite();
    excludedForm.hidden = !site;
    var summary = document.getElementById('excluded-summary');
    if (!site) {
      excludedMine.textContent = 'Pick a site to see its excluded IPs.';
      excludedList.innerHTML = '';
      summary.textContent = 'Pick a site first';
      return;
    }
    fetch(apiUrl('?excluded&site=' + encodeURIComponent(site)))
      .then(function(r) { if (!r.ok) throw new Error(); return r.json(); })
      .then(function(d) {
        var mine = 'your IP is ' + (d.ip_excluded ? 'not counted' : 'counted');
        summary.textContent = d.excluded.length ? num(d.excluded.length) + ' excluded · ' + mine : mine.charAt(0).toUpperCase() + mine.slice(1);
        excludedMine.innerHTML = 'Your IP <code>' + esc(d.ip) + '</code>' + (d.ip_excluded
          ? '<span>is not counted</span>'
          : '<button type="button" onclick="excludeMine(' + jsArg(d.ip) + ')">Exclude it</button>');
        excludedList.innerHTML = d.excluded.map(function(x) {
          var note = [x.label, x.site === '*' ? 'all sites' : ''].filter(Boolean).join(' · ');
          return '<div class="note-item excluded-item" data-site="' + esc(x.site) + '" data-ip="' + esc(x.ip) + '">' +
            '<span class="excluded-ip">' + esc(x.ip) + '</span>' +
            (note ? '<span class="note-item-date">' + esc(note) + '</span>' : '') +
            '<button type="button" aria-label="Count ' + esc(x.ip) + ' again" onclick="removeExcluded(this)">&times;</button>' +
          '</div>';
        }).join('');
      })
      .catch(function() { excludedMine.textContent = 'Could not load excluded IPs.'; summary.textContent = 'Could not load'; });
  }

  function postExcluded(endpoint, body) {
    return fetch(apiUrl(endpoint), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function(r) { if (!r.ok) throw new Error(); populateExcluded(); });
  }

  window.excludeMine = function(ip) {
    postExcluded('?exclude_add', { site: noteSite(), ip: ip })
      .then(function() { announce(ip + ' excluded'); }, function() { announce('Could not exclude ' + ip); });
  };

  window.addExcluded = function(e) {
    e.preventDefault();
    var ip = excludedForm.elements.ip.value.trim();
    postExcluded('?exclude_add', { site: noteSite(), ip: ip, label: excludedForm.elements.label.value })
      .then(function() { excludedForm.reset(); announce(ip + ' excluded'); }, function() {
        excludedForm.elements.ip.setCustomValidity('Not an IP address or range');
        excludedForm.reportValidity();
        excludedForm.elements.ip.setCustomValidity('');
      });
  };

  window.removeExcluded = function(btn) {
    var item = btn.closest('.excluded-item'), ip = item.getAttribute('data-ip');
    postExcluded('?exclude_remove', { site: item.getAttribute('data-site'), ip: ip })
      .then(function() { menuPanel.querySelector('.menu-close').focus(); announce(ip + ' is counted again'); },
        function() { announce('Could not remove ' + ip); });
  };

  // --- Goal picker in menu ---
  var goalSection = document.getElementById('goals-section');
  var goalSearch = document.getElementById('goal-search');
  var goalList = document.getElementById('goal-list');

  function populateGoalPicker() {
    if (isShared || !lastData || !lastData.pages) {
      if (goalSection) goalSection.style.display = 'none';
      return;
    }
    goalSection.style.display = '';
    goalSearch.value = '';
    renderGoalList('');
  }

  function renderGoalList(filter) {
    if (!lastData || !lastData.pages) { goalList.innerHTML = ''; return; }
    var goals = lastData.goals || [];
    menuSummary('goals', goals.length, 'goal', 'goals', 'No goals yet');
    var goalPaths = goals.map(function(g) { return g.path; });
    var lower = filter.toLowerCase();
    var pages = lastData.pages.filter(function(p) {
      return !lower || decode(p.path).toLowerCase().indexOf(lower) >= 0;
    });
    var events = (lastData.events || []).filter(function(e) {
      return !lower || e.event_name.toLowerCase().indexOf(lower) >= 0;
    });
    var html = '';
    // Show current goals first when no filter
    if (!lower) {
      goals.forEach(function(g) {
        var list = g.type === 'event' ? events : pages;
        var key = g.type === 'event' ? 'event_name' : 'path';
        var exists = list.some(function(item) { return item[key] === g.path; });
        if (!exists) {
          html += goalItemHtml(g.path, true, g.type);
        }
      });
    }
    if (pages.length && events.length) html += '<div class="goal-list-label">Pages</div>';
    pages.forEach(function(p) {
      html += goalItemHtml(p.path, goalPaths.indexOf(p.path) >= 0, 'page');
    });
    if (events.length) html += '<div class="goal-list-label">Events</div>';
    events.forEach(function(e) {
      html += goalItemHtml(e.event_name, goalPaths.indexOf(e.event_name) >= 0, 'event');
    });
    // Not seen yet: offer the typed text as a goal so it counts from the first hit
    var typed = filter.trim();
    var typedType = typed.charAt(0) === '/' ? 'page' : 'event';
    var known = typedType === 'page'
      ? pages.some(function(p) { return p.path === typed; })
      : events.some(function(e) { return e.event_name === typed; });
    if (typed && !known && goalPaths.indexOf(typed) < 0) {
      html += '<div class="goal-list-label">Not seen yet</div>' +
        '<button class="goal-item goal-item-new" data-type="' + typedType + '" onclick="menuToggleGoal(this.getAttribute(\'data-path\'), this.getAttribute(\'data-type\'))" data-path="' + esc(typed) + '">' +
          '<span class="goal-item-path">+ Add ' + typedType + ' goal: ' + esc(typed) + '</span>' +
        '</button>';
    }
    goalList.innerHTML = html;
  }

  function goalItemHtml(path, isGoal, type) {
    return '<button class="goal-item' + (isGoal ? ' is-goal' : '') + '" data-type="' + (type || 'page') + '" onclick="menuToggleGoal(' + jsArg(path) + ', this.getAttribute(\'data-type\'))">' +
      '<svg class="goal-item-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>' +
      '<span class="goal-item-path">' + esc(type === 'event' ? path.replace(/_/g, ' ') : decode(path)) + '</span>' +
    '</button>';
  }

  if (goalSearch) {
    goalSearch.addEventListener('input', function() {
      renderGoalList(this.value);
    });
  }

  window.menuToggleGoal = function(path, type) {
    type = type === 'event' ? 'event' : 'page';
    var goalPaths = (lastData && lastData.goals) ? lastData.goals.map(function(g) { return g.path; }) : [];
    var isGoal = goalPaths.indexOf(path) >= 0;
    var site = currentSite || (sites.length === 1 ? sites[0] : '');
    if (!site) return;
    var endpoint = isGoal ? '?goal_remove' : '?goal_add';
    // Optimistic update: toggle in local data immediately
    if (isGoal) {
      lastData.goals = lastData.goals.filter(function(g) { return g.path !== path; });
    } else {
      lastData.goals = lastData.goals || [];
      lastData.goals.push({ path: path, label: path, type: type, conversions: 0, rate: 0 });
    }
    renderGoalList(goalSearch.value);
    // Send to server and refresh in background
    fetch(apiUrl(endpoint), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ site: site, path: path, type: type })
    }).then(function() { load(currentDays); });
  };

  // --- Export ---
  if (isShared) {
    var exportSection = document.getElementById('export-section');
    if (exportSection) exportSection.style.display = 'none';
    if (goalSection) goalSection.style.display = 'none';
  }

  window.exportData = function(format) {
    if (!lastData) return;
    var d = lastData;
    var siteName = currentSite || 'all-sites';
    var dateStr = new Date().toISOString().slice(0, 10);
    var filename = 'puls-' + siteName + '-' + currentDays + 'd-' + dateStr;

    if (format === 'json') {
      var exported = {
        site: currentSite || 'all',
        period: currentDays + ' days',
        exported_at: new Date().toISOString(),
        totals: d.totals,
        bounce_rate: d.bounceRate,
        median_session_seconds: d.medianSessionSeconds,
        pageviews_by_day: d.pageviews,
        pages: d.pages,
        referrers: d.referrers,
        utm_campaigns: d.utm,
        channels: d.channels,
        browsers: d.browsers,
        devices: d.devices,
        languages: d.languages,
        entry_pages: d.entryPages,
        exit_pages: d.exitPages,
        events: d.events,
        outbound_links: d.outbound
      };
      var blob = new Blob([JSON.stringify(exported, null, 2)], { type: 'application/json' });
      downloadBlob(blob, filename + '.json');
    } else {
      var csv = buildCsv(d);
      var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
      downloadBlob(blob, filename + '.csv');
    }
    closeMenu();
  };

  function downloadBlob(blob, name) {
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(a.href);
  }

  function csvEscape(val) {
    if (val == null) return '';
    var s = String(val);
    // Visitor-sent text starting with = + - @ would run as a spreadsheet formula
    if (typeof val === 'string' && /^[=+\-@\t\r]/.test(s) && !isFinite(s)) s = "'" + s;
    if (s.indexOf(',') >= 0 || s.indexOf('"') >= 0 || s.indexOf('\n') >= 0) {
      return '"' + s.replace(/"/g, '""') + '"';
    }
    return s;
  }

  function csvSection(title, headers, rows) {
    var lines = ['## ' + title];
    lines.push(headers.map(csvEscape).join(','));
    rows.forEach(function(r) { lines.push(r.map(csvEscape).join(',')); });
    lines.push('');
    return lines.join('\n');
  }

  function buildCsv(d) {
    var parts = [];

    // Summary
    parts.push(csvSection('Summary', ['Metric', 'Value'], [
      ['Views', d.totals.views],
      ['Visitors', d.totals.visitors],
      ['Bounce rate', d.bounceRate != null ? d.bounceRate + '%' : ''],
      ['Median session (s)', d.medianSessionSeconds]
    ]));

    // Pageviews by day
    if (d.pageviews && d.pageviews.length) {
      parts.push(csvSection('Pageviews by day',
        ['Date', 'Views', 'Visitors'],
        d.pageviews.map(function(p) { return [p.date, p.views, p.visitors]; })
      ));
    }

    // Pages
    if (d.pages && d.pages.length) {
      parts.push(csvSection('Pages',
        ['Path', 'Views', 'Visitors'],
        d.pages.map(function(p) { return [p.path, p.views, p.visitors]; })
      ));
    }

    // Referrers
    if (d.referrers && d.referrers.length) {
      parts.push(csvSection('Referrers',
        ['Referrer', 'Views', 'Visitors'],
        d.referrers.map(function(r) { return [r.referrer, r.views, r.visitors]; })
      ));
    }

    // UTM campaigns
    if (d.utm && d.utm.length) {
      parts.push(csvSection('UTM campaigns',
        ['Source', 'Medium', 'Campaign', 'Views', 'Visitors'],
        d.utm.map(function(u) { return [u.utm_source, u.utm_medium, u.utm_campaign, u.views, u.visitors]; })
      ));
    }

    // Channels
    if (d.channels && d.channels.length) {
      parts.push(csvSection('Channels',
        ['Channel', 'Views', 'Visitors'],
        d.channels.map(function(c) { return [c.channel, c.views, c.visitors]; })
      ));
    }

    // Browsers
    if (d.browsers && d.browsers.length) {
      parts.push(csvSection('Browsers',
        ['Browser', 'Count'],
        d.browsers.map(function(b) { return [b.browser, b.count]; })
      ));
    }

    // Devices
    if (d.devices && d.devices.length) {
      parts.push(csvSection('Devices',
        ['Device', 'Count'],
        d.devices.map(function(dv) { return [dv.device, dv.count]; })
      ));
    }

    // Languages
    if (d.languages && d.languages.length) {
      parts.push(csvSection('Languages',
        ['Language', 'Count'],
        d.languages.map(function(l) { return [l.language, l.count]; })
      ));
    }

    // Entry pages
    if (d.entryPages && d.entryPages.length) {
      parts.push(csvSection('Entry pages',
        ['Path', 'Entries', 'Bounces', 'Bounce rate'],
        d.entryPages.map(function(p) { return [p.path, p.entries, p.bounces, p.bounceRate]; })
      ));
    }

    // Exit pages
    if (d.exitPages && d.exitPages.length) {
      parts.push(csvSection('Exit pages',
        ['Path', 'Exits'],
        d.exitPages.map(function(p) { return [p.path, p.exits]; })
      ));
    }

    // Events
    if (d.events && d.events.length) {
      parts.push(csvSection('Events',
        ['Event', 'Count', 'Visitors'],
        d.events.map(function(e) { return [e.event_name, e.count, e.visitors]; })
      ));
    }

    // Outbound links
    if (d.outbound && d.outbound.length) {
      parts.push(csvSection('Outbound links',
        ['URL', 'Clicks', 'Visitors'],
        d.outbound.map(function(o) { return [o.event_data, o.count, o.visitors]; })
      ));
    }

    return parts.join('\n');
  }

  // --- Theme toggle ---
  var themeToggle = document.getElementById('theme-toggle');
  var currentTheme = localStorage.getItem('puls-theme') || 'system';

  function applyTheme(theme) {
    currentTheme = theme;
    localStorage.setItem('puls-theme', theme);
    if (theme === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
    else if (theme === 'light') document.documentElement.setAttribute('data-theme', 'light');
    else document.documentElement.removeAttribute('data-theme');
    themeToggle.querySelectorAll('button').forEach(function(b) {
      b.classList.toggle('active', b.dataset.value === theme);
    });
  }

  applyTheme(currentTheme);

  themeToggle.addEventListener('click', function(e) {
    var btn = e.target.closest('button');
    if (btn && btn.dataset.value) applyTheme(btn.dataset.value);
  });

  // --- Command palette (CMD+K search) ---
  var cmdOverlay = document.getElementById('cmd-overlay');
  var cmdInput = document.getElementById('cmd-input');
  var cmdResults = document.getElementById('cmd-results');
  var cmdActive = -1;
  var cmdTimer = null;
  var cmdLastQuery = '';
  var cmdPages = null; // the page search's results for the query in the box; null while there are none
  var cmdFailed = false;

  window.openSearch = function() {
    closeSites(false);
    dialogOpened(null);
    cmdOverlay.classList.add('open');
    cmdInput.value = '';
    cmdActive = -1;
    cmdLastQuery = '';
    cmdPages = null;
    cmdFailed = false;
    var withSites = cmdSites('').length > 0;
    var label = withSites ? 'Search sites and pages' : 'Search pages';
    cmdInput.placeholder = label + '...';
    document.getElementById('cmd-hint').textContent = '↑↓ navigate · Enter open · ' + (withSites ? '/ pages only · ' : '') + 'Esc close';
    cmdInput.setAttribute('aria-label', label);
    document.getElementById('cmd-dialog').setAttribute('aria-label', label);
    paintCmd();
    setTimeout(function() { cmdInput.focus(); }, 50);
  };

  // Sites come first: with nothing typed the palette lists them, and typing filters them at once
  // while pages are searched on the server. Paths start with /, so a query that does searches
  // pages only. One palette and no second shortcut; a user with one site sees pages as before
  function cmdSites(q) {
    if (isShared || sites.length <= 1 || q.charAt(0) === '/') return [];
    return matchSites(q);
  }

  function paintCmd() {
    var q = cmdInput.value.trim();
    var siteMatches = cmdSites(q);
    var html = '';
    if (siteMatches.length) {
      html += '<div role="group" aria-labelledby="cmd-group-sites"><div class="cmd-group" id="cmd-group-sites">Sites</div>';
      html += siteMatches.map(siteRowHtml).join('');
      html += '</div>';
    }
    if (cmdPages && cmdPages.length) {
      html += '<div role="group" aria-labelledby="cmd-group-pages"><div class="cmd-group" id="cmd-group-pages">Pages <span class="cmd-group-scope">on ' + (currentSite ? esc(currentSite) : 'all sites') + '</span></div>';
      cmdPages.forEach(function(r) {
        html += '<div class="cmd-item" role="option" aria-selected="false" data-path="' + esc(r.path) + '">' +
          '<span class="cmd-item-path" title="' + esc(r.path) + '">' + esc(decode(r.path)) + '</span>' +
          '<span class="cmd-item-stats">' +
            '<span>' + num(r.views) + ' views</span>' +
            '<span>' + num(r.visitors) + ' vis.</span>' +
          '</span>' +
        '</div>';
      });
      html += '</div>';
    } else if (cmdFailed) {
      html += '<div class="cmd-empty">Could not search pages</div>';
    } else if (cmdPages && !siteMatches.length) {
      html += '<div class="cmd-empty">No pages found</div>';
    }
    cmdResults.innerHTML = html;
    var items = cmdResults.querySelectorAll('.cmd-item');
    items.forEach(function(el, i) { el.id = 'cmd-option-' + i; });
    cmdInput.setAttribute('aria-expanded', String(items.length > 0));
    // Page results arrive after the sites, so keep the highlighted row where it was. Once something
    // is typed, the first result is highlighted, so Enter opens it
    cmdActive = Math.min(cmdActive, items.length - 1);
    if (cmdActive < 0 && q && items.length) cmdActive = 0;
    updateCmdActive(items);
  }

  window.closeSearch = function() {
    if (!cmdOverlay.classList.contains('open')) return;
    cmdOverlay.classList.remove('open');
    cmdActive = -1;
    dialogClosed();
  };

  document.addEventListener('keydown', function(e) {
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
      e.preventDefault();
      if (cmdOverlay.classList.contains('open')) closeSearch();
      else openSearch();
      return;
    }
    if (!cmdOverlay.classList.contains('open')) return;
    if (e.key === 'Escape') { closeSearch(); return; }

    var items = cmdResults.querySelectorAll('.cmd-item');
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      cmdActive = Math.min(cmdActive + 1, items.length - 1);
      updateCmdActive(items);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      cmdActive = Math.max(cmdActive - 1, 0);
      updateCmdActive(items);
    } else if (e.key === 'Enter' && cmdActive >= 0 && items[cmdActive]) {
      e.preventDefault();
      items[cmdActive].click();
    }
  });

  function updateCmdActive(items, byMouse) {
    items.forEach(function(el, i) {
      el.classList.toggle('active', i === cmdActive);
      el.setAttribute('aria-selected', String(i === cmdActive));
    });
    if (items[cmdActive]) {
      cmdInput.setAttribute('aria-activedescendant', items[cmdActive].id);
      if (!byMouse) items[cmdActive].scrollIntoView({ block: 'nearest' });
    } else {
      cmdInput.removeAttribute('aria-activedescendant');
    }
  }

  cmdInput.addEventListener('input', function() {
    var q = cmdInput.value.trim();
    if (q === cmdLastQuery) return;
    cmdLastQuery = q;
    cmdActive = -1;
    cmdPages = null;
    cmdFailed = false;
    clearTimeout(cmdTimer);
    paintCmd();
    if (q.length < 2) return;

    cmdTimer = setTimeout(function() {
      var url = API + '?api&search=' + encodeURIComponent(q) + '&days=' + currentDays;
      if (currentSite) url += '&site=' + encodeURIComponent(currentSite);
      if (shareToken) url += '&share=' + encodeURIComponent(shareToken);
      fetch(url)
        .then(function(r) {
          if (r.status === 401) { location.href = '/?login'; return; }
          return r.json();
        })
        .then(function(data) {
          if (!data || data.query !== cmdInput.value.trim()) return;
          cmdPages = data.results || [];
          paintCmd();
        })
        .catch(function() { cmdFailed = true; paintCmd(); });
    }, 200);
  });

  // A site opens that site; a page filters the dashboard on it
  cmdResults.addEventListener('mousemove', function(e) {
    var item = e.target.closest('.cmd-item'), items = cmdResults.querySelectorAll('.cmd-item');
    var i = [].indexOf.call(items, item);
    if (i >= 0 && i !== cmdActive) { cmdActive = i; updateCmdActive(items, true); }
  });

  cmdResults.addEventListener('click', function(e) {
    var item = e.target.closest('.cmd-item');
    if (!item) return;
    closeSearch();
    if (item.hasAttribute('data-site')) {
      selectSite(item.getAttribute('data-site'));
      return;
    }
    currentPath = item.getAttribute('data-path');
    load(currentDays);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  // --- Pull to refresh (PWA standalone) ---
  if (window.matchMedia('(display-mode: standalone)').matches || navigator.standalone) {
    var ptr = document.getElementById('ptr');
    var ptrSpinner = document.getElementById('ptr-spinner');
    var ptrText = document.getElementById('ptr-text');
    var ptrStartY = 0;
    var ptrDist = 0;
    var ptrTracking = false;
    var ptrRefreshing = false;
    var PTR_THRESHOLD = 60;
    var PTR_MAX = 100;

    document.addEventListener('touchstart', function(e) {
      if (ptrRefreshing) return;
      if (window.scrollY <= 0) {
        ptrStartY = e.touches[0].clientY;
        ptrTracking = true;
        ptrDist = 0;
      }
    }, { passive: true });

    document.addEventListener('touchmove', function(e) {
      if (!ptrTracking || ptrRefreshing) return;
      var dy = e.touches[0].clientY - ptrStartY;
      if (dy <= 0 || window.scrollY > 0) {
        // Scrolling up or page is scrolled — reset
        if (ptrDist > 0) {
          ptr.classList.remove('active', 'pulling');
          ptr.style.height = '0';
          ptrDist = 0;
        }
        return;
      }
      ptrDist = dy;
      var progress = Math.min(ptrDist / PTR_MAX, 1);
      var h = Math.min(ptrDist * 0.45, 56);
      ptr.classList.add('active', 'pulling');
      ptr.classList.remove('releasing');
      ptr.style.height = h + 'px';
      ptrSpinner.style.transform = 'rotate(' + (progress * 360) + 'deg)';
      ptrSpinner.style.opacity = Math.min(progress * 1.5, 1);
      ptrText.textContent = ptrDist >= PTR_THRESHOLD ? 'Release to refresh' : 'Pull down to refresh';
    }, { passive: true });

    document.addEventListener('touchend', function() {
      if (!ptrTracking) return;
      ptrTracking = false;

      if (ptrDist >= PTR_THRESHOLD && !ptrRefreshing) {
        ptrRefreshing = true;
        ptr.classList.remove('pulling');
        ptr.classList.add('releasing', 'refreshing');
        ptr.style.height = '44px';
        ptrText.textContent = 'Loading...';

        // Same request as the dashboard: custom date range and share token included
        load(currentDays)
          .finally(function() {
            ptr.classList.add('releasing');
            ptr.classList.remove('refreshing');
            ptr.style.height = '0';
            setTimeout(function() {
              ptr.classList.remove('active', 'releasing');
              ptrRefreshing = false;
            }, 300);
          });
      } else {
        ptr.classList.add('releasing');
        ptr.classList.remove('pulling');
        ptr.style.height = '0';
        setTimeout(function() {
          ptr.classList.remove('active', 'releasing');
        }, 300);
      }
      ptrDist = 0;
    }, { passive: true });
  }
})();
