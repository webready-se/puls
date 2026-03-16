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

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS' && isset($_GET['collect'])) {
    $cors = cors_headers($config['allowed_origins']);
    $cors
        ? respond('', 204, null, $cors + ['Access-Control-Allow-Methods' => 'POST', 'Access-Control-Allow-Headers' => 'Content-Type', 'Access-Control-Max-Age' => '86400'])
        : respond('', 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['collect'])) {
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
    respond(file_get_contents($dashboard), 200, 'text/html');
}
respond('Dashboard file not found.', 404, 'text/plain');

// =====================================================================
// AUTH
// =====================================================================

function start_session(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    session_set_cookie_params([
        'lifetime' => $config['session_lifetime'],
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function handle_login(array $config): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_login'])) {
        // Verify CSRF token
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_token'] ?? '')) {
            show_login('Ogiltig förfrågan. Försök igen.');
            return;
        }

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // Check brute-force lockout
        if (is_locked_out($config)) {
            show_login("För många misslyckade försök. Vänta {$config['lockout_minutes']} minuter.");
            return;
        }

        $users = load_users($config['users_file']);

        if (isset($users[$username]) && password_verify($password, $users[$username]['password'])) {
            // Success — regenerate session
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['login_attempts'] = 0;

            // Redirect to dashboard
            respond('', 302, null, ['Location' => '/']);
        }

        // Failed login
        $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
        $_SESSION['last_attempt'] = time();
        show_login('Felaktigt användarnamn eller lösenord.');
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
    <meta name="theme-color" content="#6366f1">
    <link rel="manifest" href="/?manifest">
    <link rel="apple-touch-icon" href="/icon-180.png">
    <title>Puls — Logga in</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 36 36'><rect width='36' height='36' rx='8' fill='%236366f1'/><path d='M24 26V16M18 26V10M12 26v-8' stroke='white' stroke-width='2.5' stroke-linecap='round'/></svg>">
    <style>
      *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
      body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
        background: #f8fafc; color: #1e293b; min-height: 100vh;
        display: flex; align-items: center; justify-content: center;
      }
      .login-card {
        background: #fff; border-radius: 16px; padding: 40px;
        border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        width: 100%; max-width: 380px;
      }
      .logo { display: flex; align-items: center; gap: 12px; margin-bottom: 32px; justify-content: center; }
      .logo-icon {
        width: 40px; height: 40px; border-radius: 12px;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 2px 8px rgba(99,102,241,0.3);
      }
      .logo-icon svg { width: 20px; height: 20px; }
      .logo-text { font-weight: 700; font-size: 22px; }
      .tagline { text-align: center; font-size: 13px; color: #94a3b8; margin-top: -20px; margin-bottom: 28px; }
      label { display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 6px; }
      input[type="text"], input[type="password"] {
        width: 100%; padding: 10px 14px; border-radius: 10px;
        border: 1px solid #e2e8f0; font-size: 14px; outline: none;
        margin-bottom: 16px; transition: border-color 0.15s;
      }
      input:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }
      button {
        width: 100%; padding: 12px; border-radius: 10px; border: none;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: #fff; font-size: 14px; font-weight: 600; cursor: pointer;
        transition: opacity 0.15s;
      }
      button:hover { opacity: 0.9; }
      .error {
        background: #fef2f2; color: #dc2626; padding: 10px 14px;
        border-radius: 10px; font-size: 13px; margin-bottom: 16px;
        border: 1px solid #fecaca;
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
        <label for="username">Användarnamn</label>
        <input type="text" id="username" name="username" required autofocus>
        <label for="password">Lösenord</label>
        <input type="password" id="password" name="password" required>
        <button type="submit">Logga in</button>
      </form>
    </div>
    </body>
    </html>
    HTML, 200, 'text/html');
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
      function utm(){var p=new URLSearchParams(location.search),o={};['source','medium','campaign','term','content'].forEach(function(k){var v=p.get('utm_'+k);if(v)o[k]=v});return Object.keys(o).length?o:null}
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
    $input = json_decode(file_get_contents('php://input'), true);
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

    $stmt = $db->prepare("SELECT DATE(created_at) as date, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE created_at >= ? {$siteFilter} GROUP BY date ORDER BY date");
    $stmt->execute(array_merge([$since], $siteParams));
    $byDay = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT path, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE created_at >= ? {$siteFilter} GROUP BY path ORDER BY views DESC LIMIT 10");
    $stmt->execute(array_merge([$since], $siteParams));
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT referrer as source, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE created_at >= ? {$siteFilter} AND referrer IS NOT NULL GROUP BY referrer ORDER BY visitors DESC LIMIT 10");
    $stmt->execute(array_merge([$since], $siteParams));
    $referrers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE created_at >= ? {$siteFilter}");
    $stmt->execute(array_merge([$since], $siteParams));
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT browser as name, COUNT(*) as cnt FROM pageviews WHERE created_at >= ? {$siteFilter} GROUP BY browser ORDER BY cnt DESC");
    $stmt->execute(array_merge([$since], $siteParams));
    $browsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = array_sum(array_column($browsers, 'cnt'));
    $smartPct = fn($cnt, $sum) => $sum > 0 ? (($p = $cnt / $sum * 100) < 1 && $p > 0 ? round($p, 1) : round($p)) : 0;
    $browsers = array_map(fn($b) => ['name' => $b['name'], 'pct' => $smartPct($b['cnt'], $total)], $browsers);

    $stmt = $db->prepare("SELECT device as name, COUNT(*) as cnt FROM pageviews WHERE created_at >= ? {$siteFilter} GROUP BY device ORDER BY cnt DESC");
    $stmt->execute(array_merge([$since], $siteParams));
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $devices = array_map(fn($d) => ['name' => $d['name'], 'pct' => $smartPct($d['cnt'], $total)], $devices);

    $stmt = $db->prepare("SELECT COUNT(DISTINCT visitor_hash) as live FROM pageviews WHERE created_at >= ? {$siteFilter}");
    $stmt->execute(array_merge([date('Y-m-d H:i:s', strtotime('-5 minutes'))], $siteParams));
    $live = $stmt->fetch(PDO::FETCH_ASSOC)['live'] ?? 0;

    $stmt = $db->prepare("SELECT utm_source as source, utm_medium as medium, utm_campaign as campaign, utm_term as term, utm_content as content, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE created_at >= ? {$siteFilter} AND utm_source IS NOT NULL GROUP BY utm_source, utm_medium, utm_campaign, utm_term, utm_content ORDER BY visitors DESC LIMIT 10");
    $stmt->execute(array_merge([$since], $siteParams));
    $utm = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT language as name, COUNT(*) as cnt FROM pageviews WHERE created_at >= ? {$siteFilter} AND language IS NOT NULL GROUP BY language ORDER BY cnt DESC");
    $stmt->execute(array_merge([$since], $siteParams));
    $languages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $langTotal = array_sum(array_column($languages, 'cnt'));
    $languages = array_map(fn($l) => ['name' => $l['name'], 'pct' => $smartPct($l['cnt'], $langTotal)], $languages);

    // Bot data
    $stmt = $db->prepare("SELECT bot_name as name, bot_category as category, COUNT(*) as visits FROM bot_visits WHERE created_at >= ? {$siteFilter} GROUP BY bot_name, bot_category ORDER BY visits DESC");
    $stmt->execute(array_merge([$since], $siteParams));
    $bots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT bot_category as category, COUNT(*) as visits FROM bot_visits WHERE created_at >= ? {$siteFilter} GROUP BY bot_category ORDER BY visits DESC");
    $stmt->execute(array_merge([$since], $siteParams));
    $botCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT path, COUNT(*) as visits FROM bot_visits WHERE created_at >= ? {$siteFilter} GROUP BY path ORDER BY visits DESC LIMIT 10");
    $stmt->execute(array_merge([$since], $siteParams));
    $botPages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT bot_name as name, bot_category as category, site, path, created_at FROM bot_visits WHERE created_at >= ? {$siteFilter} ORDER BY created_at DESC LIMIT 20");
    $stmt->execute(array_merge([$since], $siteParams));
    $botActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return json_encode([
        'site'      => $site ?: 'Alla sajter',
        'days'      => $days,
        'live'      => (int)$live,
        'totals'    => $totals,
        'pageviews' => $byDay,
        'pages'     => $pages,
        'referrers' => $referrers,
        'browsers'  => $browsers,
        'devices'   => $devices,
        'utm'       => $utm,
        'languages' => $languages,
        'bots'      => $bots,
        'botCategories' => $botCategories,
        'botPages'  => $botPages,
        'botActivity' => $botActivity,
    ], JSON_PRETTY_PRINT);
}

// =====================================================================
// HELPERS
// =====================================================================

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
    } else {
        // Migrate: add columns/tables if missing
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

        // Migrate: normalize paths
        $needsNorm = $db->query("SELECT COUNT(*) FROM pageviews WHERE path LIKE '%/' AND path != '/'")->fetchColumn();
        if ($needsNorm > 0) {
            $db->exec("UPDATE pageviews SET path = RTRIM(path, '/') WHERE path LIKE '%/' AND path != '/'");
            $db->exec("UPDATE bot_visits SET path = RTRIM(path, '/') WHERE path LIKE '%/' AND path != '/'");
        }
    }

    return $db;
}

function handle_log(array $config): void
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $bot = detect_bot($ua);

    // If Nginx sent it here, something non-human made the request
    if (!$bot) {
        $bot = ['name' => 'Okänd klient', 'category' => 'Klient'];
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

function cors_headers(array $allowedOrigins): ?array
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!$origin) return null;
    if (empty($allowedOrigins)) {
        return ['Access-Control-Allow-Origin' => $origin];
    }
    foreach ($allowedOrigins as $allowed) {
        if (strcasecmp($origin, $allowed) === 0) {
            return ['Access-Control-Allow-Origin' => $origin];
        }
    }
    return null;
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
                   'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
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
    return $path;
}

function cleanup_bot_visits(PDO $db): void
{
    $marker = dirname($db->query("PRAGMA database_list")->fetch()['file'] ?? __DIR__) . '/.bot_cleanup';
    if (file_exists($marker) && file_get_contents($marker) === date('Y-m-d')) return;

    $db->exec("DELETE FROM bot_visits WHERE created_at < '" . date('Y-m-d H:i:s', strtotime('-90 days')) . "'");
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
        ['pattern' => 'Googlebot',         'name' => 'Googlebot',        'category' => 'Sökmotor'],
        ['pattern' => 'bingbot',           'name' => 'Bingbot',          'category' => 'Sökmotor'],
        ['pattern' => 'YandexBot',         'name' => 'YandexBot',        'category' => 'Sökmotor'],
        ['pattern' => 'DuckDuckBot',       'name' => 'DuckDuckBot',      'category' => 'Sökmotor'],
        ['pattern' => 'Baiduspider',       'name' => 'Baiduspider',      'category' => 'Sökmotor'],
        ['pattern' => 'Applebot',          'name' => 'Applebot',         'category' => 'Sökmotor'],
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
        ['pattern' => 'axios/',           'name' => 'Axios',           'category' => 'Klient'],
        ['pattern' => 'python-requests',  'name' => 'Python Requests', 'category' => 'Klient'],
        ['pattern' => 'Go-http-client',   'name' => 'Go HTTP',        'category' => 'Klient'],
        ['pattern' => 'curl/',            'name' => 'curl',            'category' => 'Klient'],
        ['pattern' => 'wget/',            'name' => 'Wget',            'category' => 'Klient'],
        ['pattern' => 'node-fetch',       'name' => 'Node Fetch',     'category' => 'Klient'],
        ['pattern' => 'undici',           'name' => 'Undici',          'category' => 'Klient'],
        ['pattern' => 'httpie',           'name' => 'HTTPie',          'category' => 'Klient'],
        ['pattern' => 'java/',            'name' => 'Java HTTP',       'category' => 'Klient'],
        ['pattern' => 'ruby',             'name' => 'Ruby HTTP',       'category' => 'Klient'],
        ['pattern' => 'perl',             'name' => 'Perl HTTP',       'category' => 'Klient'],
        ['pattern' => 'libwww-perl',      'name' => 'Perl LWP',       'category' => 'Klient'],
        ['pattern' => 'scrapy',           'name' => 'Scrapy',          'category' => 'Klient'],
        ['pattern' => 'puppeteer',        'name' => 'Puppeteer',       'category' => 'Klient'],
        ['pattern' => 'playwright',       'name' => 'Playwright',      'category' => 'Klient'],
        ['pattern' => 'HeadlessChrome',   'name' => 'Headless Chrome', 'category' => 'Klient'],
        ['pattern' => 'PhantomJS',        'name' => 'PhantomJS',       'category' => 'Klient'],
        // Generic bot patterns (keep last)
        ['pattern' => 'bot',               'name' => 'Okänd bot',        'category' => 'Övrigt'],
        ['pattern' => 'crawler',           'name' => 'Okänd crawler',    'category' => 'Övrigt'],
        ['pattern' => 'spider',            'name' => 'Okänd spider',     'category' => 'Övrigt'],
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
    return 'Övrigt';
}

function detect_device(int $width): string
{
    if ($width === 0)    return 'Okänd';
    if ($width < 768)    return 'Mobil';
    if ($width < 1024)   return 'Surfplatta';
    return 'Desktop';
}
