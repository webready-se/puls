<?php
/**
 * Puls — Cookieless analytics. One file. No cookies. Full picture.
 *
 * Drop this into any PHP project's public directory.
 * The SQLite database is created automatically.
 *
 * TRACKING:
 *   <script src="https://puls.example.com/?js" data-site="my-site" defer></script>
 *
 * DASHBOARD:
 *   https://puls.example.com/
 *
 * API:
 *   GET /?api&days=7              → all data
 *   GET /?api&days=7&site=blog    → filtered by site
 *   GET /?api&sites               → list all sites
 */

$config = require __DIR__ . '/../config.php';

// =====================================================================
// ROUTING
// =====================================================================

// Serve PWA icons, reject other static file requests
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri !== '/' && str_contains($uri, '.')) {
    if (preg_match('#^/icon-\d+\.png$#', $uri) && file_exists(__DIR__ . $uri)) {
        respond(file_get_contents(__DIR__ . $uri), 200, 'image/png', ['Cache-Control' => 'public, max-age=604800']);
    }
    http_response_code(404);
    exit;
}

// Public endpoints (no auth required)

if (isset($_GET['health'])) {
    try {
        $db = get_db($config['db_path']);
        respond(json_encode(['status' => 'ok']), 200, 'application/json');
    } catch (Throwable $e) {
        respond(json_encode(['status' => 'error']), 503, 'application/json');
    }
}

if (isset($_GET['js'])) {
    respond(get_tracking_script(), 200, 'application/javascript', ['Cache-Control' => 'public, max-age=86400']);
}

if (isset($_GET['manifest'])) {
    respond(json_encode([
        'name' => 'Puls',
        'short_name' => 'Puls',
        'start_url' => '/',
        'display' => 'standalone',
        'background_color' => '#f8fafc',
        'theme_color' => '#6366f1',
        'icons' => [
            ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 200, 'application/manifest+json', ['Cache-Control' => 'public, max-age=86400']);
}


if (isset($_GET['pixel'])) {
    handle_pixel($config);
    respond(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, 'image/gif', ['Cache-Control' => 'no-store']);
}

if (isset($_GET['log'])) {
    handle_log($config);
    respond('', 204, null, ['Cache-Control' => 'no-store']);
}

if (isset($_GET['status_log'])) {
    handle_status_log($config);
    respond('', 204, null, ['Cache-Control' => 'no-store']);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS' && isset($_GET['collect'])) {
    $cors = cors_headers($config['allowed_origins']);
    $cors
        ? respond('', 204, null, $cors + ['Access-Control-Allow-Methods' => 'POST', 'Access-Control-Allow-Headers' => 'Content-Type', 'Access-Control-Max-Age' => '86400'])
        : respond('', 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['collect'])) {
    if (!empty($config['allowed_origins'])) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (!is_origin_allowed($origin, $config['allowed_origins'])) {
            respond('', 403);
        }
    }
    handle_collect($config);
    $cors = cors_headers($config['allowed_origins']);
    respond('', 204, null, $cors ?: []);
}

// Protected endpoints (auth required)

start_session($config);

if (isset($_GET['logout'])) {
    handle_logout();
}

if (isset($_GET['login']) || $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_login'])) {
    handle_login($config);
}

if (isset($_GET['api'])) {
    require_auth();
    $user = get_current_user_data($config);
    respond(get_api_data($config, $user), 200, 'application/json');
}

// Default: show dashboard or login
if (!is_authenticated()) {
    handle_login($config);
}

// Serve dashboard
$dashboard = __DIR__ . '/dashboard.html';
if (file_exists($dashboard)) {
    respond(file_get_contents($dashboard), 200, 'text/html', security_headers());
}
respond('Dashboard file not found.', 404, 'text/plain');

// =====================================================================
// AUTH
// =====================================================================

function start_session(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    ini_set('session.gc_maxlifetime', $config['session_lifetime']);
    session_set_cookie_params([
        'lifetime' => $config['session_lifetime'],
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    // Rotate session ID every hour to prevent session fixation
    if (!empty($_SESSION['authenticated'])) {
        $lastRotated = $_SESSION['last_rotated'] ?? 0;
        if (time() - $lastRotated > 3600) {
            session_regenerate_id(true);
            $_SESSION['last_rotated'] = time();
        }
    }
}

function handle_login(array $config): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_login'])) {
        // Verify CSRF token
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_token'] ?? '')) {
            show_login('Invalid request. Try again.');
            return;
        }

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // Check brute-force lockout
        if (is_locked_out($config)) {
            show_login("Too many failed attempts. Wait {$config['lockout_minutes']} minutes.");
            return;
        }

        $users = load_users($config['users_file']);

        if (isset($users[$username]) && password_verify($password, $users[$username]['password'])) {
            // Success — regenerate session, invalidate CSRF token
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']);
            $_SESSION['authenticated'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['login_attempts'] = 0;
            $_SESSION['last_rotated'] = time();
            audit_log($config, $username, 'login_success');

            // Redirect to dashboard
            respond('', 302, null, ['Location' => '/']);
        }

        // Failed login
        $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
        $_SESSION['last_attempt'] = time();
        audit_log($config, $username, 'login_failed');
        return;
    }

    show_login();
}

function handle_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    respond('', 302, null, ['Location' => '/?login']);
}

function audit_log(array $config, string $username, string $action): void
{
    $db = get_db($config['db_path']);
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (str_contains($ip, ',')) $ip = trim(explode(',', $ip)[0]);
    $stmt = $db->prepare('INSERT INTO audit_log (username, action, ip, created_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$username, $action, $ip, date('Y-m-d H:i:s')]);
}

function is_authenticated(): bool
{
    return !empty($_SESSION['authenticated']);
}

function require_auth(): void
{
    if (!is_authenticated()) {
        respond(json_encode(['error' => 'Unauthorized']), 401, 'application/json');
    }
}

function get_current_user_data(array $config): array
{
    $username = $_SESSION['username'] ?? '';
    $users = load_users($config['users_file']);
    return $users[$username] ?? ['sites' => []];
}

function is_locked_out(array $config): bool
{
    $attempts = $_SESSION['login_attempts'] ?? 0;
    $lastAttempt = $_SESSION['last_attempt'] ?? 0;

    if ($attempts >= $config['max_login_attempts']) {
        if (time() - $lastAttempt < $config['lockout_minutes'] * 60) {
            return true;
        }
        // Lockout expired
        $_SESSION['login_attempts'] = 0;
    }
    return false;
}

function load_users(string $file): array
{
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}

function show_login(?string $error = null): void
{
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    $errorHtml = $error ? '<div class="error">' . htmlspecialchars($error) . '</div>' : '';

    respond(<<<HTML
    <!DOCTYPE html>
    <html lang="sv">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Puls">
    <meta name="theme-color" content="#6366f1" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0f172a" media="(prefers-color-scheme: dark)">
    <link rel="manifest" href="/?manifest">
    <link rel="apple-touch-icon" href="/icon-180.png">
    <title>Puls — Log in</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 36 36'><rect width='36' height='36' rx='8' fill='%236366f1'/><path d='M24 26V16M18 26V10M12 26v-8' stroke='white' stroke-width='2.5' stroke-linecap='round'/></svg>">
    <script>
      (function() {
        var s = localStorage.getItem('puls-theme') || 'system';
        if (s === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
        else if (s === 'light') document.documentElement.setAttribute('data-theme', 'light');
      })();
    </script>
    <style>
      *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
      :root {
        --bg: #f8fafc; --card: #fff; --border: #e2e8f0;
        --text: #1e293b; --muted: #94a3b8; --label: #475569;
        --accent: #6366f1; --accent2: #8b5cf6;
        --error-bg: #fef2f2; --error-text: #dc2626; --error-border: #fecaca;
      }
      [data-theme="dark"] {
        --bg: #0f172a; --card: #1e293b; --border: #334155;
        --text: #f1f5f9; --muted: #64748b; --label: #94a3b8;
        --accent: #818cf8; --accent2: #a78bfa;
        --error-bg: rgba(248,113,113,0.1); --error-text: #f87171; --error-border: rgba(248,113,113,0.2);
      }
      @media (prefers-color-scheme: dark) {
        :root:not([data-theme="light"]) {
          --bg: #0f172a; --card: #1e293b; --border: #334155;
          --text: #f1f5f9; --muted: #64748b; --label: #94a3b8;
          --accent: #818cf8; --accent2: #a78bfa;
          --error-bg: rgba(248,113,113,0.1); --error-text: #f87171; --error-border: rgba(248,113,113,0.2);
        }
      }
      body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
        background: var(--bg); color: var(--text); min-height: 100vh;
        display: flex; align-items: center; justify-content: center;
      }
      .login-card {
        background: var(--card); border-radius: 16px; padding: 40px;
        border: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        width: 100%; max-width: 380px;
      }
      .logo { display: flex; align-items: center; gap: 12px; margin-bottom: 32px; justify-content: center; }
      .logo-icon {
        width: 40px; height: 40px; border-radius: 12px;
        background: linear-gradient(135deg, var(--accent), var(--accent2));
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 2px 8px rgba(99,102,241,0.3);
      }
      .logo-icon svg { width: 20px; height: 20px; }
      .logo-text { font-weight: 700; font-size: 22px; }
      .tagline { text-align: center; font-size: 13px; color: var(--muted); margin-top: -20px; margin-bottom: 28px; }
      label { display: block; font-size: 13px; font-weight: 600; color: var(--label); margin-bottom: 6px; }
      input[type="text"], input[type="password"] {
        width: 100%; padding: 10px 14px; border-radius: 10px;
        border: 1px solid var(--border); background: var(--bg); color: var(--text);
        font-size: 14px; outline: none;
        margin-bottom: 16px; transition: border-color 0.15s;
      }
      input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }
      button {
        width: 100%; padding: 12px; border-radius: 10px; border: none;
        background: linear-gradient(135deg, var(--accent), var(--accent2));
        color: #fff; font-size: 14px; font-weight: 600; cursor: pointer;
        transition: opacity 0.15s;
      }
      button:hover { opacity: 0.9; }
      .error {
        background: var(--error-bg); color: var(--error-text); padding: 10px 14px;
        border-radius: 10px; font-size: 13px; margin-bottom: 16px;
        border: 1px solid var(--error-border);
      }
    </style>
    </head>
    <body>
    <div class="login-card">
      <div class="logo">
        <div class="logo-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/>
          </svg>
        </div>
        <span class="logo-text">Puls</span>
      </div>
      <p class="tagline">See your traffic. Respect their privacy.</p>
      {$errorHtml}
      <form method="POST" action="/?login">
        <input type="hidden" name="_login" value="1">
        <input type="hidden" name="_token" value="{$token}">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required autofocus>
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
        <button type="submit">Log in</button>
      </form>
    </div>
    </body>
    </html>
    HTML, 200, 'text/html', security_headers());
}

// =====================================================================
// TRACKING SCRIPT
// =====================================================================

function get_tracking_script(): string
{
    return <<<'JS'
    (function(){
      if(navigator.webdriver||document.visibilityState==='prerender')return;
      var sc=document.currentScript||{src:'/stats.php',dataset:{}};
      var ep=sc.src.split('?')[0]+'?collect';
      var site=sc.dataset.site||location.hostname;
      function utm(){var p=new URLSearchParams(location.search),o={};['source','medium','campaign','term','content'].forEach(function(k){var v=p.get('utm_'+k);if(v)o[k]=v});if(!o.source&&p.get('gad_source')){o.source='google';o.medium='cpc';var c=p.get('gad_campaignid');if(c)o.campaign=c}return Object.keys(o).length?o:null}
      function s(){
        var d=JSON.stringify({u:location.pathname+location.search,r:document.referrer,w:innerWidth,site:site,utm:utm()});
        navigator.sendBeacon?navigator.sendBeacon(ep,d):0;
      }
      s();
      if(history.pushState){var o=history.pushState;history.pushState=function(){o.apply(this,arguments);s()};addEventListener('popstate',s)}
    })();
    JS;
}

// =====================================================================
// COLLECT ENDPOINT
// =====================================================================

function handle_collect(array $config): void
{
    $raw = file_get_contents('php://input');
    if (strlen($raw) > 10000) return; // 10 KB max

    $input = json_decode($raw, true);
    if (!$input || empty($input['u'])) return;

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $bot = detect_bot($ua);
    if ($bot) {
        $db = get_db($config['db_path']);
        $site = normalize_site(!empty($input['site']) ? $input['site'] : ($_SERVER['HTTP_HOST'] ?? 'unknown'));
        $path = normalize_path(urldecode(substr($input['u'], 0, 500)));
        $stmt = $db->prepare('INSERT INTO bot_visits (site, path, bot_name, bot_category, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$site, $path, $bot['name'], $bot['category'], substr($ua, 0, 500), date('Y-m-d H:i:s')]);
        return;
    }

    $db = get_db($config['db_path']);

    $site = normalize_site(!empty($input['site']) ? $input['site'] : ($_SERVER['HTTP_HOST'] ?? 'unknown'));

    $salt = date('Y-m-d') . 'puls-' . $config['app_key'];
    $hash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . ($_SERVER['HTTP_USER_AGENT'] ?? '') . $salt);

    $browser = detect_browser($ua);
    $device = detect_device((int)($input['w'] ?? 0));

    $referrer = null;
    if (!empty($input['r'])) {
        $parsed = parse_url($input['r'], PHP_URL_HOST);
        $self = [$_SERVER['HTTP_HOST'] ?? '', $site];
        if ($parsed && !in_array($parsed, $self, true) && !in_array(str_replace('www.', '', $parsed), $self, true)) {
            $referrer = normalize_referrer($parsed);
        }
    }

    $utm = $input['utm'] ?? null;
    $utmSource   = $utm && !empty($utm['source'])   ? substr($utm['source'], 0, 100)   : null;
    $utmMedium   = $utm && !empty($utm['medium'])   ? substr($utm['medium'], 0, 100)   : null;
    $utmCampaign = $utm && !empty($utm['campaign']) ? substr($utm['campaign'], 0, 200) : null;
    $utmTerm     = $utm && !empty($utm['term'])     ? substr($utm['term'], 0, 200)     : null;
    $utmContent  = $utm && !empty($utm['content'])  ? substr($utm['content'], 0, 200)  : null;

    // Server-side fallback: detect Google Ads params if JS missed them
    if (!$utmSource) {
        $rawUrl = $input['u'] ?? '';
        if (str_contains($rawUrl, 'gad_source=')) {
            $utmSource = 'google';
            $utmMedium = 'cpc';
            parse_str(parse_url($rawUrl, PHP_URL_QUERY) ?? '', $gadParams);
            if (!empty($gadParams['gad_campaignid'])) {
                $utmCampaign = substr($gadParams['gad_campaignid'], 0, 200);
            }
        }
    }

    $lang = null;
    if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    }

    $path = normalize_path(urldecode(substr($input['u'], 0, 500)));

    $stmt = $db->prepare('INSERT INTO pageviews (site, path, referrer, browser, device, visitor_hash, utm_source, utm_medium, utm_campaign, utm_term, utm_content, language, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $site,
        $path,
        $referrer ? substr($referrer, 0, 255) : null,
        $browser,
        $device,
        $hash,
        $utmSource,
        $utmMedium,
        $utmCampaign,
        $utmTerm,
        $utmContent,
        $lang,
        date('Y-m-d H:i:s'),
    ]);
}

// =====================================================================
// API ENDPOINT
// =====================================================================

function get_api_data(array $config, array $user): string
{
    $db = get_db($config['db_path']);
    $allowedSites = $user['sites'] ?? [];

    // List all sites (filtered by user access)
    if (isset($_GET['sites'])) {
        $stmt = $db->query('SELECT DISTINCT site FROM pageviews ORDER BY site');
        $sites = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($allowedSites)) {
            $sites = array_values(array_intersect($sites, $allowedSites));
        }
        return json_encode($sites);
    }

    $days = max(1, min(365, (int)($_GET['days'] ?? 7)));
    $since = date('Y-m-d', strtotime("-{$days} days"));
    $site = $_GET['site'] ?? '';

    // Enforce site access restriction
    if (!empty($allowedSites)) {
        if ($site && !in_array($site, $allowedSites)) {
            return json_encode(['error' => 'Access denied']);
        }
        if (!$site && count($allowedSites) === 1) {
            $site = $allowedSites[0];
        }
    }

    $siteFilter = $site ? 'AND site = ?' : '';
    $siteParams = $site ? [$site] : [];

    // If user has multiple allowed sites but no specific filter, restrict to their sites
    if (!$site && !empty($allowedSites)) {
        $placeholders = implode(',', array_fill(0, count($allowedSites), '?'));
        $siteFilter = "AND site IN ({$placeholders})";
        $siteParams = $allowedSites;
    }

    // Search endpoint (early return)
    if (isset($_GET['search'])) {
        $search = mb_substr(trim($_GET['search']), 0, 100);
        if (mb_strlen($search) < 2) {
            return json_encode(['results' => [], 'query' => $search, 'days' => $days]);
        }
        $stmt = $db->prepare("SELECT path, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE created_at >= ? {$siteFilter} AND path LIKE ? GROUP BY path ORDER BY views DESC LIMIT 20");
        $stmt->execute(array_merge([$since], $siteParams, ['%' . $search . '%']));
        return json_encode(['results' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'query' => $search, 'days' => $days]);
    }

    // Expand mode (returns all rows for a specific data type)
    $expand = $_GET['expand'] ?? '';

    // Path filter (for single-page drill-down)
    $path = $_GET['path'] ?? '';
    $pathFilter = $path ? 'AND path = ?' : '';
    $pathParams = $path ? [$path] : [];

    // Channel filter
    $channel = $_GET['channel'] ?? '';
    $channelFilter = '';
    $channelParams = [];
    if ($channel) {
        $channelConditions = [
            'paid'           => "MAX(utm_medium) IN ('paid','cpc','cpm','ppc','retargeting','paid_social')",
            'campaign'       => "MAX(utm_source) IS NOT NULL AND MAX(utm_medium) NOT IN ('paid','cpc','cpm','ppc','retargeting','paid_social')",
            'organic-search' => "MAX(utm_source) IS NULL AND MAX(referrer) IN ('Google','Bing')",
            'social'         => "MAX(utm_source) IS NULL AND MAX(referrer) IN ('Facebook','Instagram','Twitter/X','LinkedIn')",
            'referral'       => "MAX(utm_source) IS NULL AND MAX(referrer) IS NOT NULL AND MAX(referrer) NOT IN ('Google','Bing','Facebook','Instagram','Twitter/X','LinkedIn')",
            'direct'         => "MAX(utm_source) IS NULL AND MAX(referrer) IS NULL",
        ];
        if (isset($channelConditions[$channel])) {
            $cond = $channelConditions[$channel];
            $channelFilter = "AND visitor_hash IN (SELECT visitor_hash FROM pageviews WHERE created_at >= ? {$siteFilter} GROUP BY visitor_hash HAVING {$cond})";
            $channelParams = array_merge([$since], $siteParams);
        }
    }

    $stmt = $db->prepare("SELECT DATE(created_at) as date, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE created_at >= ? {$siteFilter} {$pathFilter} {$channelFilter} GROUP BY date ORDER BY date");
    $stmt->execute(array_merge([$since], $siteParams, $pathParams, $channelParams));
    $byDay = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Pages table (skip when filtered to a single path)
    $pages = [];
    $pagesTotal = 0;
    if (!$path) {
        $limit = $expand === 'pages' ? 1000 : 10;
        $stmt = $db->prepare("SELECT path, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE created_at >= ? {$siteFilter} {$channelFilter} GROUP BY path ORDER BY views DESC LIMIT {$limit}");
        $stmt->execute(array_merge([$since], $siteParams, $channelParams));
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $db->prepare("SELECT COUNT(DISTINCT path) FROM pageviews WHERE created_at >= ? {$siteFilter} {$channelFilter}");
        $stmt->execute(array_merge([$since], $siteParams, $channelParams));
        $pagesTotal = (int) $stmt->fetchColumn();
    }

    $refLimit = $expand === 'referrers' ? 1000 : 10;
    $stmt = $db->prepare("SELECT referrer as source, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE created_at >= ? {$siteFilter} {$pathFilter} {$channelFilter} AND referrer IS NOT NULL GROUP BY referrer ORDER BY visitors DESC LIMIT {$refLimit}");
    $stmt->execute(array_merge([$since], $siteParams, $pathParams, $channelParams));
    $referrers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $db->prepare("SELECT COUNT(DISTINCT referrer) FROM pageviews WHERE created_at >= ? {$siteFilter} {$pathFilter} {$channelFilter} AND referrer IS NOT NULL");
    $stmt->execute(array_merge([$since], $siteParams, $pathParams, $channelParams));
    $referrersTotal = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE created_at >= ? {$siteFilter} {$pathFilter} {$channelFilter}");
    $stmt->execute(array_merge([$since], $siteParams, $pathParams, $channelParams));
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);

    // Previous period for trend comparison
    $prevSince = date('Y-m-d', strtotime("-" . ($days * 2) . " days"));
    $prevChannelFilter = str_replace('created_at >= ?', 'created_at >= ?', $channelFilter);
    $stmt = $db->prepare("SELECT COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE created_at >= ? AND created_at < ? {$siteFilter} {$pathFilter} {$channelFilter}");
    $stmt->execute(array_merge([$prevSince, $since], $siteParams, $pathParams, $channelParams));
    $previousTotals = $stmt->fetch(PDO::FETCH_ASSOC);

    // Bounce rate and session length (skip when filtered to a single path — not meaningful)
    $bounceRate = 0;
    $previousBounceRate = null;
    $medianSession = 0;
    $previousMedianSession = null;
    if (!$path) {
        $stmt = $db->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN cnt = 1 THEN 1 ELSE 0 END) as bounced FROM (SELECT visitor_hash, COUNT(*) as cnt FROM pageviews WHERE created_at >= ? {$siteFilter} {$channelFilter} GROUP BY visitor_hash)");
        $stmt->execute(array_merge([$since], $siteParams, $channelParams));
        $bounceData = $stmt->fetch(PDO::FETCH_ASSOC);
        $bounceRate = $bounceData['total'] > 0 ? round(100 * $bounceData['bounced'] / $bounceData['total'], 1) : 0;

        $stmt = $db->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN cnt = 1 THEN 1 ELSE 0 END) as bounced FROM (SELECT visitor_hash, COUNT(*) as cnt FROM pageviews WHERE created_at >= ? AND created_at < ? {$siteFilter} {$channelFilter} GROUP BY visitor_hash)");
        $stmt->execute(array_merge([$prevSince, $since], $siteParams, $channelParams));
        $prevBounce = $stmt->fetch(PDO::FETCH_ASSOC);
        $previousBounceRate = $prevBounce['total'] > 0 ? round(100 * $prevBounce['bounced'] / $prevBounce['total'], 1) : null;

        $stmt = $db->prepare("SELECT duration FROM (SELECT (julianday(MAX(created_at)) - julianday(MIN(created_at))) * 86400 as duration FROM pageviews WHERE created_at >= ? {$siteFilter} {$channelFilter} GROUP BY visitor_hash HAVING COUNT(*) > 1) ORDER BY duration");
        $stmt->execute(array_merge([$since], $siteParams, $channelParams));
        $durations = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $medianSession = !empty($durations) ? (int) $durations[intval(count($durations) / 2)] : 0;

        $stmt = $db->prepare("SELECT duration FROM (SELECT (julianday(MAX(created_at)) - julianday(MIN(created_at))) * 86400 as duration FROM pageviews WHERE created_at >= ? AND created_at < ? {$siteFilter} {$channelFilter} GROUP BY visitor_hash HAVING COUNT(*) > 1) ORDER BY duration");
        $stmt->execute(array_merge([$prevSince, $since], $siteParams, $channelParams));
        $prevDurations = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $previousMedianSession = !empty($prevDurations) ? (int) $prevDurations[intval(count($prevDurations) / 2)] : null;
    }

    // Entry/exit pages (skip when filtered to a single path)
    $entryPages = [];
    $exitPages = [];
    if (!$path) {
        $entryLimit = $expand === 'entryPages' ? 1000 : 10;
        $stmt = $db->prepare("SELECT path, COUNT(*) as entries FROM (SELECT path, ROW_NUMBER() OVER (PARTITION BY visitor_hash ORDER BY created_at ASC) as rn FROM pageviews WHERE created_at >= ? {$siteFilter} {$channelFilter}) WHERE rn = 1 GROUP BY path ORDER BY entries DESC LIMIT {$entryLimit}");
        $stmt->execute(array_merge([$since], $siteParams, $channelParams));
        $entryPages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $exitLimit = $expand === 'exitPages' ? 1000 : 10;
        $stmt = $db->prepare("SELECT path, COUNT(*) as exits FROM (SELECT path, ROW_NUMBER() OVER (PARTITION BY visitor_hash ORDER BY created_at DESC) as rn FROM pageviews WHERE created_at >= ? {$siteFilter} {$channelFilter}) WHERE rn = 1 GROUP BY path ORDER BY exits DESC LIMIT {$exitLimit}");
        $stmt->execute(array_merge([$since], $siteParams, $channelParams));
        $exitPages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $db->prepare("SELECT browser as name, COUNT(*) as cnt FROM pageviews WHERE created_at >= ? {$siteFilter} {$pathFilter} {$channelFilter} GROUP BY browser ORDER BY cnt DESC");
    $stmt->execute(array_merge([$since], $siteParams, $pathParams, $channelParams));
    $browsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = array_sum(array_column($browsers, 'cnt'));
    $smartPct = fn($cnt, $sum) => $sum > 0 ? (($p = $cnt / $sum * 100) < 1 && $p > 0 ? round($p, 1) : round($p)) : 0;
    $browsers = array_map(fn($b) => ['name' => $b['name'], 'pct' => $smartPct($b['cnt'], $total)], $browsers);

    $stmt = $db->prepare("SELECT device as name, COUNT(*) as cnt FROM pageviews WHERE created_at >= ? {$siteFilter} {$pathFilter} {$channelFilter} GROUP BY device ORDER BY cnt DESC");
    $stmt->execute(array_merge([$since], $siteParams, $pathParams, $channelParams));
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $devices = array_map(fn($d) => ['name' => $d['name'], 'pct' => $smartPct($d['cnt'], $total)], $devices);

    $stmt = $db->prepare("SELECT COUNT(DISTINCT visitor_hash) as live FROM pageviews WHERE created_at >= ? {$siteFilter} {$pathFilter} {$channelFilter}");
    $stmt->execute(array_merge([date('Y-m-d H:i:s', strtotime('-5 minutes'))], $siteParams, $pathParams, $channelParams));
    $live = $stmt->fetch(PDO::FETCH_ASSOC)['live'] ?? 0;

    $utmLimit = $expand === 'utm' ? 1000 : 10;
    $stmt = $db->prepare("SELECT utm_source as source, utm_medium as medium, utm_campaign as campaign, utm_term as term, utm_content as content, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE created_at >= ? {$siteFilter} {$pathFilter} {$channelFilter} AND utm_source IS NOT NULL GROUP BY utm_source, utm_medium, utm_campaign, utm_term, utm_content ORDER BY visitors DESC LIMIT {$utmLimit}");
    $stmt->execute(array_merge([$since], $siteParams, $pathParams, $channelParams));
    $utm = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $db->prepare("SELECT COUNT(*) FROM (SELECT 1 FROM pageviews WHERE created_at >= ? {$siteFilter} {$pathFilter} {$channelFilter} AND utm_source IS NOT NULL GROUP BY utm_source, utm_medium, utm_campaign, utm_term, utm_content)");
    $stmt->execute(array_merge([$since], $siteParams, $pathParams, $channelParams));
    $utmTotal = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT language as name, COUNT(*) as cnt FROM pageviews WHERE created_at >= ? {$siteFilter} {$pathFilter} {$channelFilter} AND language IS NOT NULL GROUP BY language ORDER BY cnt DESC");
    $stmt->execute(array_merge([$since], $siteParams, $pathParams, $channelParams));
    $languages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $langTotal = array_sum(array_column($languages, 'cnt'));
    $langNames = ['sv' => 'Swedish', 'en' => 'English', 'nb' => 'Norwegian', 'da' => 'Danish', 'fi' => 'Finnish',
                   'de' => 'German', 'fr' => 'French', 'es' => 'Spanish', 'nl' => 'Dutch', 'pl' => 'Polish',
                   'it' => 'Italian', 'pt' => 'Portuguese', 'ru' => 'Russian', 'zh' => 'Chinese', 'ja' => 'Japanese',
                   'ko' => 'Korean', 'ar' => 'Arabic', 'tr' => 'Turkish', 'th' => 'Thai', 'vi' => 'Vietnamese'];
    $languages = array_map(fn($l) => ['name' => $langNames[$l['name']] ?? strtoupper($l['name']), 'pct' => $smartPct($l['cnt'], $langTotal)], $languages);

    // Traffic channels: classify each visitor's first pageview
    $stmt = $db->prepare("
        SELECT channel, COUNT(*) as visitors, SUM(pv) as views,
               ROUND(AVG(pv), 1) as pages_per_visitor
        FROM (
            SELECT visitor_hash, COUNT(*) as pv,
                CASE
                    WHEN MAX(utm_medium) IN ('paid', 'cpc', 'cpm', 'ppc', 'retargeting', 'paid_social') THEN 'Paid'
                    WHEN MAX(utm_source) IS NOT NULL THEN 'Campaign'
                    WHEN MAX(referrer) IN ('Google', 'Bing') THEN 'Organic search'
                    WHEN MAX(referrer) IN ('Facebook', 'Instagram', 'Twitter/X', 'LinkedIn') THEN 'Social'
                    WHEN MAX(referrer) IS NOT NULL THEN 'Referral'
                    ELSE 'Direct'
                END as channel
            FROM pageviews
            WHERE created_at >= ? {$siteFilter} {$pathFilter}
            GROUP BY visitor_hash
        )
        GROUP BY channel
        ORDER BY visitors DESC
    ");
    $stmt->execute(array_merge([$since], $siteParams, $pathParams));
    $channels = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Bot data
    $botsLimit = $expand === 'bots' ? 1000 : 10;
    $stmt = $db->prepare("SELECT bot_name as name, bot_category as category, COUNT(*) as visits FROM bot_visits WHERE created_at >= ? {$siteFilter} GROUP BY bot_name, bot_category ORDER BY visits DESC LIMIT {$botsLimit}");
    $stmt->execute(array_merge([$since], $siteParams));
    $bots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $db->prepare("SELECT COUNT(*) FROM (SELECT 1 FROM bot_visits WHERE created_at >= ? {$siteFilter} GROUP BY bot_name, bot_category)");
    $stmt->execute(array_merge([$since], $siteParams));
    $botsTotal = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT bot_category as category, COUNT(*) as visits FROM bot_visits WHERE created_at >= ? {$siteFilter} GROUP BY bot_category ORDER BY visits DESC");
    $stmt->execute(array_merge([$since], $siteParams));
    $botCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $botPagesLimit = $expand === 'botPages' ? 1000 : 10;
    $stmt = $db->prepare("SELECT path, COUNT(*) as visits FROM bot_visits WHERE created_at >= ? {$siteFilter} GROUP BY path ORDER BY visits DESC LIMIT {$botPagesLimit}");
    $stmt->execute(array_merge([$since], $siteParams));
    $botPages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $db->prepare("SELECT COUNT(DISTINCT path) FROM bot_visits WHERE created_at >= ? {$siteFilter}");
    $stmt->execute(array_merge([$since], $siteParams));
    $botPagesTotal = (int) $stmt->fetchColumn();

    $activityLimit = $expand === 'botActivity' ? 1000 : 10;
    $stmt = $db->prepare("SELECT bot_name as name, bot_category as category, site, path, created_at FROM bot_visits WHERE created_at >= ? {$siteFilter} ORDER BY created_at DESC LIMIT {$activityLimit}");
    $stmt->execute(array_merge([$since], $siteParams));
    $botActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (function_exists('idn_to_utf8')) {
        foreach ($botActivity as &$a) {
            $decoded = idn_to_utf8($a['site'], 0, INTL_IDNA_VARIANT_UTS46);
            if ($decoded !== false) $a['site'] = $decoded;
        }
        unset($a);
    }

    // Broken links (last 30 days, regardless of $days filter)
    $blSiteFilter = $site ? 'AND site = ?' : '';
    $blSiteParams = $site ? [$site] : [];
    if (!$site && !empty($allowedSites)) {
        $placeholders = implode(',', array_fill(0, count($allowedSites), '?'));
        $blSiteFilter = "AND site IN ({$placeholders})";
        $blSiteParams = $allowedSites;
    }
    $blTables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='broken_links'")->fetchAll();
    $brokenLinks = [];
    if (!empty($blTables)) {
        $stmt = $db->prepare("SELECT * FROM (
                SELECT site, path, status, SUM(hits) as hits,
                    MIN(first_seen) as first_seen, MAX(last_seen) as last_seen,
                    GROUP_CONCAT(DISTINCT referrer) as referrers,
                    ROW_NUMBER() OVER (PARTITION BY status ORDER BY SUM(hits) DESC) as rn
                FROM broken_links
                WHERE last_seen >= date('now', '-30 days') {$blSiteFilter}
                GROUP BY site, path, status
            ) WHERE rn <= 25");
        $stmt->execute($blSiteParams);
        $brokenLinks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($brokenLinks as &$bl) {
            $bl['hits'] = (int) $bl['hits'];
            $bl['status'] = (int) $bl['status'];
            $bl['referrers'] = $bl['referrers']
                ? array_values(array_filter(array_map(fn($r) => urldecode($r), explode(',', $bl['referrers']))))
                : [];
            if (function_exists('idn_to_utf8')) {
                $decoded = idn_to_utf8($bl['site'], 0, INTL_IDNA_VARIANT_UTS46);
                if ($decoded !== false) $bl['site'] = $decoded;
            }
        }
        unset($bl);
    }

    return json_encode([
        'site'      => $site ?: 'All sites',
        'path'      => $path ?: null,
        'channel'   => $channel ?: null,
        'days'      => $days,
        'live'      => (int)$live,
        'totals'    => $totals,
        'previousTotals' => $previousTotals,
        'bounceRate' => $bounceRate,
        'previousBounceRate' => $previousBounceRate,
        'medianSessionSeconds' => $medianSession,
        'previousMedianSessionSeconds' => $previousMedianSession,
        'entryPages' => $entryPages,
        'exitPages'  => $exitPages,
        'pageviews' => $byDay,
        'pages'     => $pages,
        'pagesTotal' => $pagesTotal,
        'referrers' => $referrers,
        'referrersTotal' => $referrersTotal,
        'browsers'  => $browsers,
        'devices'   => $devices,
        'utm'       => $utm,
        'utmTotal'  => $utmTotal,
        'channels'  => $channels,
        'languages' => $languages,
        'bots'      => $bots,
        'botsTotal' => $botsTotal,
        'botCategories' => $botCategories,
        'botPages'  => $botPages,
        'botPagesTotal' => $botPagesTotal,
        'botActivity' => $botActivity,
        'brokenLinks' => $brokenLinks,
    ], JSON_PRETTY_PRINT);
}

// =====================================================================
// HELPERS
// =====================================================================

function security_headers(): array
{
    return [
        'Content-Security-Policy' => "default-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; manifest-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'",
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ];
}

function respond(string $body, int $status, ?string $contentType = null, array $headers = []): never
{
    http_response_code($status);
    if ($contentType) header("Content-Type: $contentType");
    foreach ($headers as $k => $v) header("$k: $v");
    echo $body;
    exit;
}

function get_db(string $path): PDO
{
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0750, true);

    $isNew = !file_exists($path);
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');

    if ($isNew) {
        $db->exec('CREATE TABLE pageviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site TEXT NOT NULL,
            path TEXT NOT NULL,
            referrer TEXT,
            browser TEXT,
            device TEXT,
            visitor_hash TEXT NOT NULL,
            utm_source TEXT,
            utm_medium TEXT,
            utm_campaign TEXT,
            utm_term TEXT,
            utm_content TEXT,
            language TEXT,
            created_at TEXT NOT NULL
        )');
        $db->exec('CREATE INDEX idx_site_date ON pageviews (site, created_at)');
        $db->exec('CREATE INDEX idx_visitor ON pageviews (visitor_hash)');
        $db->exec('CREATE TABLE bot_visits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site TEXT NOT NULL,
            path TEXT NOT NULL,
            bot_name TEXT NOT NULL,
            bot_category TEXT NOT NULL,
            user_agent TEXT,
            created_at TEXT NOT NULL
        )');
        $db->exec('CREATE INDEX idx_bot_site_date ON bot_visits (site, created_at)');
        $db->exec('CREATE TABLE broken_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site TEXT NOT NULL,
            path TEXT NOT NULL,
            status INTEGER NOT NULL,
            referrer TEXT,
            hits INTEGER DEFAULT 1,
            first_seen TEXT NOT NULL,
            last_seen TEXT NOT NULL
        )');
        $db->exec('CREATE UNIQUE INDEX idx_broken_unique ON broken_links (site, path, status, referrer)');
        $db->exec('CREATE INDEX idx_broken_site_status ON broken_links (site, status, hits DESC)');
    } else {
        run_migrations($db);
    }

    return $db;
}

function handle_log(array $config): void
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $bot = detect_bot($ua);

    // If Nginx sent it here, something non-human made the request
    if (!$bot) {
        $bot = ['name' => 'Unknown client', 'category' => 'Client'];
    }

    $db = get_db($config['db_path']);
    $path = normalize_path(urldecode($_GET['p'] ?? '/'));
    $site = normalize_site($_GET['s'] ?? ($_SERVER['HTTP_HOST'] ?? 'unknown'));
    $path = substr($path, 0, 500);
    $now = date('Y-m-d H:i:s');

    // Atomic deduplicate + insert: skip if same bot + site + path within last 10 seconds
    $stmt = $db->prepare('INSERT INTO bot_visits (site, path, bot_name, bot_category, user_agent, created_at)
        SELECT ?, ?, ?, ?, ?, ?
        WHERE NOT EXISTS (
            SELECT 1 FROM bot_visits WHERE bot_name = ? AND site = ? AND path = ? AND created_at >= ?
        )');
    $stmt->execute([$site, $path, $bot['name'], $bot['category'], substr($ua, 0, 500), $now,
                     $bot['name'], $site, $path, date('Y-m-d H:i:s', strtotime('-10 seconds'))]);

    // Lazy retention: clean up entries older than 90 days (once per day)
    cleanup_bot_visits($db);
}

function handle_pixel(array $config): void
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $bot = detect_bot($ua);
    if (!$bot) return;

    $db = get_db($config['db_path']);
    $path = normalize_path(urldecode($_GET['p'] ?? '/'));
    $site = normalize_site($_GET['s'] ?? ($_SERVER['HTTP_HOST'] ?? 'unknown'));

    $stmt = $db->prepare('INSERT INTO bot_visits (site, path, bot_name, bot_category, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $site,
        substr($path, 0, 500),
        $bot['name'],
        $bot['category'],
        substr($ua, 0, 500),
        date('Y-m-d H:i:s'),
    ]);
}

function is_origin_allowed(string $origin, array $allowedOrigins): bool
{
    if (empty($allowedOrigins)) return true;

    $host = parse_url($origin, PHP_URL_HOST);
    if (!$host) return false;

    foreach ($allowedOrigins as $allowed) {
        $allowed = strtolower(trim($allowed));
        $host = strtolower($host);
        if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
            return true;
        }
    }
    return false;
}

function cors_headers(array $allowedOrigins): ?array
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!$origin) return null;
    if (!is_origin_allowed($origin, $allowedOrigins)) return null;
    return ['Access-Control-Allow-Origin' => $origin];
}

function normalize_referrer(string $host): string
{
    $map = [
        'm.facebook.com'   => 'Facebook',
        'l.facebook.com'   => 'Facebook',
        'lm.facebook.com'  => 'Facebook',
        'www.facebook.com' => 'Facebook',
        'facebook.com'     => 'Facebook',
        'l.instagram.com'  => 'Instagram',
        'www.instagram.com'=> 'Instagram',
        'instagram.com'    => 'Instagram',
        't.co'             => 'Twitter/X',
        'x.com'            => 'Twitter/X',
        'www.x.com'        => 'Twitter/X',
        'out.reddit.com'   => 'Reddit',
        'www.reddit.com'   => 'Reddit',
        'old.reddit.com'   => 'Reddit',
        'away.vk.com'      => 'VK',
        'www.linkedin.com' => 'LinkedIn',
        'linkedin.com'     => 'LinkedIn',
        'www.pinterest.com'=> 'Pinterest',
        'pin.it'           => 'Pinterest',
        'www.google.com'   => 'Google',
        'www.google.se'    => 'Google',
        'www.bing.com'     => 'Bing',
        'search.yahoo.com' => 'Yahoo',
        'duckduckgo.com'   => 'DuckDuckGo',
    ];

    $name = $map[strtolower($host)] ?? $host;

    // Decode IDN/punycode domains (xn--snittrnta-02a.se → snittränta.se)
    if (str_contains($name, 'xn--') && function_exists('idn_to_utf8')) {
        $decoded = idn_to_utf8($name, 0, INTL_IDNA_VARIANT_UTS46);
        if ($decoded !== false) $name = $decoded;
    }

    return $name;
}

function normalize_site(string $site): string
{
    $site = substr($site, 0, 100);
    if (function_exists('idn_to_utf8')) {
        $decoded = idn_to_utf8($site, 0, INTL_IDNA_VARIANT_UTS46);
        if ($decoded !== false) $site = $decoded;
    }
    return $site;
}

function normalize_path(string $path): string
{
    // Strip tracking query params (fbclid, gclid, utm_*, etc.)
    if (str_contains($path, '?')) {
        [$pathPart, $query] = explode('?', $path, 2);
        parse_str($query, $params);
        $strip = ['fbclid', 'gclid', 'gclsrc', 'dclid', 'msclkid', 'mc_eid',
                   'gad_source', 'gad_campaignid', 'gbraid', 'wbraid',
                   '_gl', 'ved',
                   'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
                   'ref', 'hsa_cam', 'hsa_grp', 'hsa_mt', 'hsa_src', 'hsa_ad', 'hsa_acc',
                   'hsa_net', 'hsa_ver', 'hsa_la', 'hsa_ol', 'hsa_kw'];
        foreach ($strip as $key) {
            unset($params[$key]);
        }
        $path = $params ? $pathPart . '?' . http_build_query($params) : $pathPart;
    }

    if ($path !== '/' && str_ends_with($path, '/')) {
        $path = rtrim($path, '/');
    }
    // Collapse multiple slashes (e.g. //feed → /feed)
    $path = preg_replace('#/+#', '/', $path);
    return $path;
}

function run_migrations(PDO $db): void
{
    $dbFile = $db->query("PRAGMA database_list")->fetch()['file'] ?? '';
    $marker = dirname($dbFile ?: __DIR__) . '/.schema_version';
    $currentVersion = 10; // Bump this when adding new migrations

    $version = file_exists($marker) ? (int) file_get_contents($marker) : 0;
    if ($version >= $currentVersion) return;

    // v1: UTM columns
    $cols = array_column($db->query('PRAGMA table_info(pageviews)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('utm_source', $cols)) {
        $db->exec('ALTER TABLE pageviews ADD COLUMN utm_source TEXT');
        $db->exec('ALTER TABLE pageviews ADD COLUMN utm_medium TEXT');
        $db->exec('ALTER TABLE pageviews ADD COLUMN utm_campaign TEXT');
        $db->exec('ALTER TABLE pageviews ADD COLUMN language TEXT');
    }
    if (!in_array('utm_term', $cols)) {
        $db->exec('ALTER TABLE pageviews ADD COLUMN utm_term TEXT');
        $db->exec('ALTER TABLE pageviews ADD COLUMN utm_content TEXT');
    }

    // v1: bot_visits table
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='bot_visits'")->fetchAll();
    if (empty($tables)) {
        $db->exec('CREATE TABLE bot_visits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site TEXT NOT NULL,
            path TEXT NOT NULL,
            bot_name TEXT NOT NULL,
            bot_category TEXT NOT NULL,
            user_agent TEXT,
            created_at TEXT NOT NULL
        )');
        $db->exec('CREATE INDEX idx_bot_site_date ON bot_visits (site, created_at)');
    }

    // v2: normalize trailing slashes
    $needsNorm = $db->query("SELECT COUNT(*) FROM pageviews WHERE path LIKE '%/' AND path != '/'")->fetchColumn();
    if ($needsNorm > 0) {
        $db->exec("UPDATE pageviews SET path = RTRIM(path, '/') WHERE path LIKE '%/' AND path != '/'");
        $db->exec("UPDATE bot_visits SET path = RTRIM(path, '/') WHERE path LIKE '%/' AND path != '/'");
    }

    // v3: broken_links table
    $blTables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='broken_links'")->fetchAll();
    if (empty($blTables)) {
        $db->exec('CREATE TABLE broken_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site TEXT NOT NULL,
            path TEXT NOT NULL,
            status INTEGER NOT NULL,
            referrer TEXT,
            hits INTEGER DEFAULT 1,
            first_seen TEXT NOT NULL,
            last_seen TEXT NOT NULL
        )');
        $db->exec('CREATE UNIQUE INDEX idx_broken_unique ON broken_links (site, path, status, referrer)');
        $db->exec('CREATE INDEX idx_broken_site_status ON broken_links (site, status, hits DESC)');
    }

    // v4: clean up scanner noise from broken_links
    if ($version < 4) {
        $db->exec("DELETE FROM broken_links WHERE path LIKE '%.php' OR path LIKE '/cgi-bin%' OR path LIKE '/.well-known%'");
    }

    // v5: broader noise cleanup + decode referrers
    if ($version < 5) {
        $db->exec("DELETE FROM broken_links WHERE path LIKE '%.php%' OR path LIKE '%/wp-%' OR path LIKE '%/wordpress%' OR path LIKE '%/xmlrpc%' OR path LIKE '%/cgi-bin%' OR path LIKE '/.well-known%'");
        // Decode URL-encoded referrers
        $rows = $db->query("SELECT id, referrer FROM broken_links WHERE referrer LIKE '%\%%'")->fetchAll(PDO::FETCH_ASSOC);
        $update = $db->prepare("UPDATE broken_links SET referrer = ? WHERE id = ?");
        foreach ($rows as $row) {
            $decoded = urldecode($row['referrer']);
            if ($decoded !== $row['referrer']) {
                $update->execute([$decoded, $row['id']]);
            }
        }
        // Decode punycode in referrers
        if (function_exists('idn_to_utf8')) {
            $rows = $db->query("SELECT id, referrer FROM broken_links WHERE referrer LIKE '%xn--%'")->fetchAll(PDO::FETCH_ASSOC);
            $update = $db->prepare("UPDATE broken_links SET referrer = ? WHERE id = ?");
            foreach ($rows as $row) {
                $host = explode('/', $row['referrer'])[0];
                $decoded = idn_to_utf8($host, 0, INTL_IDNA_VARIANT_UTS46);
                if ($decoded !== false && $decoded !== $host) {
                    $update->execute([str_replace($host, $decoded, $row['referrer']), $row['id']]);
                }
            }
        }
    }

    // v6: clean up test entries, normalize double slashes, URL-decode stored referrers
    if ($version < 6) {
        $db->exec("DELETE FROM broken_links WHERE path IN ('/endpoint-test', '/test-404', '/test-301', '/test-200', '/denna-sida-finns-inte-abc123', '/finns-inte-test', '/finns-inte-test-xyz', '/testar-broken-link-xyz', '/testar-broken-link-123')");
        $db->exec("UPDATE broken_links SET path = REPLACE(path, '//', '/') WHERE path LIKE '//%'");
        // URL-decode stored referrers (re-run for any missed by v5)
        $rows = $db->query("SELECT id, referrer FROM broken_links WHERE referrer LIKE '%\%%'")->fetchAll(PDO::FETCH_ASSOC);
        $update = $db->prepare("UPDATE broken_links SET referrer = ? WHERE id = ?");
        foreach ($rows as $row) {
            $decoded = urldecode($row['referrer']);
            if ($decoded !== $row['referrer']) {
                $update->execute([$decoded, $row['id']]);
            }
        }
    }

    // v7: strip utm_id from stored paths
    if ($version < 7) {
        $rows = $db->query("SELECT id, path FROM pageviews WHERE path LIKE '%utm_id=%'")->fetchAll(PDO::FETCH_ASSOC);
        $update = $db->prepare("UPDATE pageviews SET path = ? WHERE id = ?");
        foreach ($rows as $row) {
            $update->execute([normalize_path($row['path']), $row['id']]);
        }
        $hasBL = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='broken_links'")->fetch();
        if ($hasBL) {
            $rows = $db->query("SELECT id, path FROM broken_links WHERE path LIKE '%utm_id=%'")->fetchAll(PDO::FETCH_ASSOC);
            $update = $db->prepare("UPDATE broken_links SET path = ? WHERE id = ?");
            $delete = $db->prepare("DELETE FROM broken_links WHERE id = ?");
            foreach ($rows as $row) {
                try {
                    $update->execute([normalize_path($row['path']), $row['id']]);
                } catch (PDOException $e) {
                    $delete->execute([$row['id']]);
                }
            }
        }
    }

    // v8: strip Google Ads params from paths + backfill utm for gad_ traffic
    if ($version < 8) {
        // Backfill utm_source/medium/campaign for pageviews with gad_ params in path
        $rows = $db->query("SELECT id, path FROM pageviews WHERE path LIKE '%gad_source=%' AND utm_source IS NULL")->fetchAll(PDO::FETCH_ASSOC);
        $update = $db->prepare("UPDATE pageviews SET path = ?, utm_source = 'google', utm_medium = 'cpc', utm_campaign = ? WHERE id = ?");
        foreach ($rows as $row) {
            parse_str(parse_url($row['path'], PHP_URL_QUERY) ?? '', $params);
            $campaign = !empty($params['gad_campaignid']) ? $params['gad_campaignid'] : null;
            $update->execute([normalize_path($row['path']), $campaign, $row['id']]);
        }
        // Also strip gad_/gbraid/wbraid params from paths that already have utm
        $rows = $db->query("SELECT id, path FROM pageviews WHERE (path LIKE '%gad_%' OR path LIKE '%gbraid=%' OR path LIKE '%wbraid=%') AND utm_source IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        $update = $db->prepare("UPDATE pageviews SET path = ? WHERE id = ?");
        foreach ($rows as $row) {
            $update->execute([normalize_path($row['path']), $row['id']]);
        }
        // Strip _gl and ved params from paths
        $rows = $db->query("SELECT id, path FROM pageviews WHERE path LIKE '%_gl=%' OR path LIKE '%ved=%'")->fetchAll(PDO::FETCH_ASSOC);
        $update = $db->prepare("UPDATE pageviews SET path = ? WHERE id = ?");
        foreach ($rows as $row) {
            $update->execute([normalize_path($row['path']), $row['id']]);
        }
        // Clean broken_links too (table may not exist on all installations)
        $hasBrokenLinks = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='broken_links'")->fetch();
        if ($hasBrokenLinks) {
            $rows = $db->query("SELECT id, path FROM broken_links WHERE path LIKE '%gad_%' OR path LIKE '%gbraid=%' OR path LIKE '%wbraid=%' OR path LIKE '%_gl=%' OR path LIKE '%ved=%'")->fetchAll(PDO::FETCH_ASSOC);
            $update = $db->prepare("UPDATE broken_links SET path = ? WHERE id = ?");
            $delete = $db->prepare("DELETE FROM broken_links WHERE id = ?");
            foreach ($rows as $row) {
                $cleanPath = normalize_path($row['path']);
                try {
                    $update->execute([$cleanPath, $row['id']]);
                } catch (PDOException $e) {
                    // UNIQUE conflict: merge hits into existing row and delete duplicate
                    $delete->execute([$row['id']]);
                }
            }
        }
    }

    // v9: Translate Swedish values to English
    if ($version < 9) {
        $db->exec("UPDATE pageviews SET device = CASE device
            WHEN 'Mobil' THEN 'Mobile'
            WHEN 'Surfplatta' THEN 'Tablet'
            WHEN 'Okänd' THEN 'Unknown'
            ELSE device END
            WHERE device IN ('Mobil', 'Surfplatta', 'Okänd')");

        $db->exec("UPDATE pageviews SET browser = 'Other' WHERE browser = 'Övrigt'");

        $hasBotVisits = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='bot_visits'")->fetch();
        if ($hasBotVisits) {
            $db->exec("UPDATE bot_visits SET bot_category = CASE bot_category
                WHEN 'Sökmotor' THEN 'Search engine'
                WHEN 'Klient' THEN 'Client'
                WHEN 'Övrigt' THEN 'Other'
                ELSE bot_category END
                WHERE bot_category IN ('Sökmotor', 'Klient', 'Övrigt')");

            $db->exec("UPDATE bot_visits SET bot_name = CASE bot_name
                WHEN 'Okänd klient' THEN 'Unknown client'
                WHEN 'Okänd bot' THEN 'Unknown bot'
                WHEN 'Okänd crawler' THEN 'Unknown crawler'
                WHEN 'Okänd spider' THEN 'Unknown spider'
                ELSE bot_name END
                WHERE bot_name IN ('Okänd klient', 'Okänd bot', 'Okänd crawler', 'Okänd spider')");
        }
    }

    // v10: audit_log table
    if ($version < 10) {
        $db->exec('CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            action TEXT NOT NULL,
            ip TEXT,
            created_at TEXT NOT NULL
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_audit_date ON audit_log (created_at)');
    }

    file_put_contents($marker, (string) $currentVersion);
}

function handle_status_log(array $config): void
{
    $status = (int) ($_GET['status'] ?? 0);

    // Only track redirects and errors
    if ($status < 300 || $status === 304) return;

    $db = get_db($config['db_path']);
    $path = normalize_path(urldecode($_GET['p'] ?? '/'));
    $site = normalize_site($_GET['s'] ?? ($_SERVER['HTTP_HOST'] ?? 'unknown'));
    $path = substr($path, 0, 500);

    // Ignore noise paths (scanners probing for vulnerabilities)
    if (preg_match('#\.php\d?|/cgi-bin|^/\.well-known|/wp-|/wordpress|^/\.env|^/\.git|/xmlrpc|/administrator#i', $path)) return;

    // Truncate referrer to domain+path (strip query strings, decode punycode + URL-encoding)
    $referrer = $_SERVER['HTTP_X_ORIGINAL_REFERER'] ?? $_SERVER['HTTP_REFERER'] ?? null;
    if ($referrer) {
        $parsed = parse_url($referrer);
        $host = $parsed['host'] ?? '';
        if (function_exists('idn_to_utf8') && str_contains($host, 'xn--')) {
            $decoded = idn_to_utf8($host, 0, INTL_IDNA_VARIANT_UTS46);
            if ($decoded !== false) $host = $decoded;
        }
        $refPath = urldecode($parsed['path'] ?? '/');
        $referrer = substr($host . $refPath, 0, 500);
    }

    $now = date('Y-m-d H:i:s');

    // Upsert: increment hits if exists, otherwise insert
    $stmt = $db->prepare('INSERT INTO broken_links (site, path, status, referrer, hits, first_seen, last_seen)
        VALUES (?, ?, ?, ?, 1, ?, ?)
        ON CONFLICT(site, path, status, referrer) DO UPDATE SET
            hits = hits + 1,
            last_seen = excluded.last_seen');
    $stmt->execute([$site, $path, $status, $referrer, $now, $now]);

    // Lazy retention: clean up entries older than 30 days (once per day)
    cleanup_broken_links($db);
}

function cleanup_broken_links(PDO $db): void
{
    $marker = dirname($db->query("PRAGMA database_list")->fetch()['file'] ?? __DIR__) . '/.broken_cleanup';
    if (file_exists($marker) && file_get_contents($marker) === date('Y-m-d')) return;

    $stmt = $db->prepare('DELETE FROM broken_links WHERE last_seen < ?');
    $stmt->execute([date('Y-m-d H:i:s', strtotime('-30 days'))]);
    file_put_contents($marker, date('Y-m-d'));
}

function cleanup_bot_visits(PDO $db): void
{
    $marker = dirname($db->query("PRAGMA database_list")->fetch()['file'] ?? __DIR__) . '/.bot_cleanup';
    if (file_exists($marker) && file_get_contents($marker) === date('Y-m-d')) return;

    $stmt = $db->prepare('DELETE FROM bot_visits WHERE created_at < ?');
    $stmt->execute([date('Y-m-d H:i:s', strtotime('-90 days'))]);
    file_put_contents($marker, date('Y-m-d'));
}

function detect_bot(string $ua): ?array
{
    $bots = [
        // AI
        ['pattern' => 'GPTBot',            'name' => 'GPTBot',            'category' => 'AI'],
        ['pattern' => 'ChatGPT-User',      'name' => 'ChatGPT',          'category' => 'AI'],
        ['pattern' => 'ClaudeBot',         'name' => 'ClaudeBot',        'category' => 'AI'],
        ['pattern' => 'Claude-Web',        'name' => 'Claude-Web',       'category' => 'AI'],
        ['pattern' => 'PerplexityBot',     'name' => 'PerplexityBot',    'category' => 'AI'],
        ['pattern' => 'Bytespider',        'name' => 'Bytespider',       'category' => 'AI'],
        ['pattern' => 'CCBot',             'name' => 'CCBot',            'category' => 'AI'],
        ['pattern' => 'Google-Extended',   'name' => 'Google-Extended',  'category' => 'AI'],
        ['pattern' => 'Applebot-Extended', 'name' => 'Applebot-Extended','category' => 'AI'],
        ['pattern' => 'cohere-ai',         'name' => 'Cohere',           'category' => 'AI'],
        ['pattern' => 'Meta-ExternalAgent','name' => 'Meta AI',          'category' => 'AI'],
        ['pattern' => 'OAI-SearchBot',    'name' => 'OAI-SearchBot',   'category' => 'AI'],
        // Search engines
        ['pattern' => 'Googlebot',         'name' => 'Googlebot',        'category' => 'Search engine'],
        ['pattern' => 'bingbot',           'name' => 'Bingbot',          'category' => 'Search engine'],
        ['pattern' => 'YandexBot',         'name' => 'YandexBot',        'category' => 'Search engine'],
        ['pattern' => 'DuckDuckBot',       'name' => 'DuckDuckBot',      'category' => 'Search engine'],
        ['pattern' => 'Baiduspider',       'name' => 'Baiduspider',      'category' => 'Search engine'],
        ['pattern' => 'Applebot',          'name' => 'Applebot',         'category' => 'Search engine'],
        // Social
        ['pattern' => 'facebookexternalhit','name' => 'Facebook',        'category' => 'Social'],
        ['pattern' => 'Twitterbot',        'name' => 'Twitterbot',       'category' => 'Social'],
        ['pattern' => 'LinkedInBot',       'name' => 'LinkedInBot',      'category' => 'Social'],
        ['pattern' => 'Slackbot',          'name' => 'Slackbot',         'category' => 'Social'],
        ['pattern' => 'TelegramBot',       'name' => 'TelegramBot',      'category' => 'Social'],
        // SEO
        ['pattern' => 'AhrefsBot',         'name' => 'AhrefsBot',        'category' => 'SEO'],
        ['pattern' => 'SemrushBot',        'name' => 'SemrushBot',       'category' => 'SEO'],
        ['pattern' => 'MJ12bot',           'name' => 'Majestic',         'category' => 'SEO'],
        ['pattern' => 'DotBot',            'name' => 'DotBot',           'category' => 'SEO'],
        ['pattern' => 'UptimeRobot',       'name' => 'UptimeRobot',      'category' => 'Monitor'],
        ['pattern' => 'CensysInspect',    'name' => 'CensysInspect',   'category' => 'Monitor'],
        ['pattern' => 'uptime checker',   'name' => 'Uptime Checker',  'category' => 'Monitor'],
        ['pattern' => 'Pingdom',          'name' => 'Pingdom',         'category' => 'Monitor'],
        // Automated clients
        ['pattern' => 'axios/',           'name' => 'Axios',           'category' => 'Client'],
        ['pattern' => 'python-requests',  'name' => 'Python Requests', 'category' => 'Client'],
        ['pattern' => 'Go-http-client',   'name' => 'Go HTTP',        'category' => 'Client'],
        ['pattern' => 'curl/',            'name' => 'curl',            'category' => 'Client'],
        ['pattern' => 'wget/',            'name' => 'Wget',            'category' => 'Client'],
        ['pattern' => 'node-fetch',       'name' => 'Node Fetch',     'category' => 'Client'],
        ['pattern' => 'undici',           'name' => 'Undici',          'category' => 'Client'],
        ['pattern' => 'httpie',           'name' => 'HTTPie',          'category' => 'Client'],
        ['pattern' => 'java/',            'name' => 'Java HTTP',       'category' => 'Client'],
        ['pattern' => 'ruby',             'name' => 'Ruby HTTP',       'category' => 'Client'],
        ['pattern' => 'perl',             'name' => 'Perl HTTP',       'category' => 'Client'],
        ['pattern' => 'libwww-perl',      'name' => 'Perl LWP',       'category' => 'Client'],
        ['pattern' => 'scrapy',           'name' => 'Scrapy',          'category' => 'Client'],
        ['pattern' => 'puppeteer',        'name' => 'Puppeteer',       'category' => 'Client'],
        ['pattern' => 'playwright',       'name' => 'Playwright',      'category' => 'Client'],
        ['pattern' => 'HeadlessChrome',   'name' => 'Headless Chrome', 'category' => 'Client'],
        ['pattern' => 'PhantomJS',        'name' => 'PhantomJS',       'category' => 'Client'],
        // Generic bot patterns (keep last)
        ['pattern' => 'bot',               'name' => 'Unknown bot',      'category' => 'Other'],
        ['pattern' => 'crawler',           'name' => 'Unknown crawler',  'category' => 'Other'],
        ['pattern' => 'spider',            'name' => 'Unknown spider',   'category' => 'Other'],
    ];

    foreach ($bots as $bot) {
        if (stripos($ua, $bot['pattern']) !== false) {
            return ['name' => $bot['name'], 'category' => $bot['category']];
        }
    }
    return null;
}

function detect_browser(string $ua): string
{
    if (str_contains($ua, 'Firefox'))  return 'Firefox';
    if (str_contains($ua, 'Edg/'))     return 'Edge';
    if (str_contains($ua, 'Chrome'))   return 'Chrome';
    if (str_contains($ua, 'Safari'))   return 'Safari';
    if (str_contains($ua, 'Opera'))    return 'Opera';
    return 'Other';
}

function detect_device(int $width): string
{
    if ($width === 0)    return 'Unknown';
    if ($width < 768)    return 'Mobile';
    if ($width < 1024)   return 'Tablet';
    return 'Desktop';
}
