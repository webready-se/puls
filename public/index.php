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

// Shown in the dashboard footer. Bump it with the CHANGELOG heading when releasing (tests/Unit/VersionTest.php)
const PULS_VERSION = '1.30.0';

// How long a replaced session id still leads to the new one (start_session). Up here: routing
// starts the session before execution reaches anything defined further down
const SESSION_REPLACED_GRACE_SECONDS = 60;

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
    // Short max-age so script changes reach visitors within an hour; the ETag
    // makes the hourly check a bodyless 304. Nginx gzip may weaken it to W/"…".
    $script = get_tracking_script();
    $etag = '"' . substr(sha1($script), 0, 16) . '"';
    $headers = ['Cache-Control' => 'public, max-age=3600', 'ETag' => $etag];
    $sent = array_map(fn ($t) => preg_replace('#^W/#', '', trim($t)), explode(',', $_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if (in_array($etag, $sent, true)) respond('', 304, null, $headers);
    respond($script, 200, 'application/javascript', $headers);
}

if (isset($_GET['manifest'])) {
    respond(json_encode([
        'name' => $config['app_name'],
        'short_name' => $config['app_name'],
        'start_url' => '/',
        'display' => 'standalone',
        'background_color' => '#f8fafc',
        'theme_color' => $config['app_accent'],
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

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS' && (isset($_GET['collect']) || isset($_GET['event']))) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['event'])) {
    if (!empty($config['allowed_origins'])) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (!is_origin_allowed($origin, $config['allowed_origins'])) {
            respond('', 403);
        }
    }
    handle_event($config);
    $cors = cors_headers($config['allowed_origins']);
    respond('', 204, null, $cors ?: []);
}

// CSRF token refresh (keeps login form valid for idle tabs)

if (isset($_GET['csrf'])) {
    start_session($config);
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    respond(json_encode(['token' => $token]), 200, 'application/json');
}

// Share endpoints (token-based, no session needed). The read API also takes the token as
// "Authorization: Bearer", which keeps it out of URLs, server logs and shell history

$shareToken = $_GET['share'] ?? (isset($_GET['api']) ? bearer_token($_SERVER) : null);
if ($shareToken !== null) {
    $share = find_share_token($config, $shareToken);
    if (!$share) {
        http_response_code(404);
        exit;
    }
    // PHP's clock: expires_at is in the configured time zone, SQLite's datetime('now') is UTC
    if ($share['expires_at'] && $share['expires_at'] <= date('Y-m-d H:i:s')) {
        $lastDay = date('j M Y', strtotime($share['expires_at']));
        if (isset($_GET['api'])) {
            respond(json_encode(['error' => "This token expired on {$lastDay}. Extend it with `php puls share:edit`."]), 401, 'application/json');
        }
        respond("This link expired on {$lastDay}. Ask whoever shared it for a new one.", 401, 'text/plain', security_headers());
    }
    note_share_token_use($config, $share);
    $shareSites = share_token_sites(get_db($config['db_path']), $share);
    if (isset($_GET['api'])) {
        // No sites means every site to the API, so a group left without sites gets one that cannot exist
        $user = ['sites' => $shareSites ?: ['']];
        respond(get_api_data($config, $user), 200, 'application/json');
    }
    // Serve dashboard in shared mode
    $dashboard = __DIR__ . '/dashboard.html';
    if (file_exists($dashboard)) {
        $html = str_replace('__PULS_VERSION__', PULS_VERSION, file_get_contents($dashboard));
        $html = inject_after_title($html, config_block($config, [
            'token' => $share['token'],
            'site' => $share['site'],
            'groups' => share_token_groups($share),
            'sites' => $shareSites,
        ]));
        respond($html, 200, 'text/html', security_headers());
    }
    respond('Dashboard file not found.', 404, 'text/plain');
}

// Protected endpoints (auth required)

start_session($config);

if (isset($_GET['logout'])) {
    handle_logout();
}

if (isset($_GET['login']) || $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_login'])) {
    handle_login($config);
}

if (isset($_GET['goal_add']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_auth();
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input['site']) || empty($input['path'])) respond('', 400);
    require_site_access($config, (string) $input['site']);
    $type = ($input['type'] ?? 'page') === 'event' ? 'event' : 'page';
    $db = get_db($config['db_path']);
    $stmt = $db->prepare('INSERT OR IGNORE INTO goals (site, path, label, type, created_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([substr($input['site'], 0, 200), substr($input['path'], 0, 500), substr($input['label'] ?? '', 0, 200), $type, date('Y-m-d H:i:s')]);
    respond(json_encode(['ok' => true]), 200, 'application/json');
}

if (isset($_GET['goal_remove']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_auth();
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input['site']) || empty($input['path'])) respond('', 400);
    require_site_access($config, (string) $input['site']);
    $db = get_db($config['db_path']);
    $stmt = $db->prepare('DELETE FROM goals WHERE site = ? AND path = ?');
    $stmt->execute([$input['site'], $input['path']]);
    respond(json_encode(['ok' => true]), 200, 'application/json');
}

// A goal's target: a count per day, week or month; 0 or none clears it
if (isset($_GET['goal_target']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_auth();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($input['site']) || empty($input['path'])) respond('', 400);
    require_site_access($config, (string) $input['site']);
    $target = min(1000000, max(0, (int) ($input['target'] ?? 0)));
    $period = in_array($input['period'] ?? '', ['day', 'week', 'month'], true) ? $input['period'] : 'week';
    $db = get_db($config['db_path']);
    $db->prepare('UPDATE goals SET target = ?, target_period = ? WHERE site = ? AND path = ?')
        ->execute([$target ?: null, $target ? $period : null, $input['site'], $input['path']]);
    respond(json_encode(['ok' => true]), 200, 'application/json');
}

// Saved breakdowns: an event grouped on one key, shown as its own dashboard card
if ((isset($_GET['breakdown_add']) || isset($_GET['breakdown_remove']) || isset($_GET['breakdown_order'])) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_auth();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $bdSite = substr((string) ($input['site'] ?? ''), 0, 200);
    if ($bdSite === '') respond('', 400);
    require_site_access($config, $bdSite);
    $db = get_db($config['db_path']);
    if (isset($_GET['breakdown_add'])) {
        $event = substr(trim((string) ($input['event'] ?? '')), 0, 100);
        $group = str_replace(['"', '\\'], '', substr(trim((string) ($input['group'] ?? '')), 0, 50));
        if ($event === '' || $group === '') respond('', 400);
        $label = substr(trim((string) ($input['label'] ?? '')), 0, 100);
        // A new card goes last on its site. Saving the same event + key again only renames the card
        $db->prepare('INSERT INTO breakdowns (site, label, event_name, group_key, created_at, position) VALUES (?, ?, ?, ?, ?, (SELECT COALESCE(MAX(position), -1) + 1 FROM breakdowns WHERE site = ?)) ON CONFLICT (site, event_name, group_key) DO UPDATE SET label = excluded.label')
            ->execute([$bdSite, $label, $event, $group, date('Y-m-d H:i:s'), $bdSite]);
    } elseif (isset($_GET['breakdown_order'])) {
        // ids in the order to show them; an id from another site is ignored
        $ids = array_slice(array_values((array) ($input['ids'] ?? [])), 0, 500);
        $move = $db->prepare('UPDATE breakdowns SET position = ? WHERE site = ? AND id = ?');
        $db->beginTransaction();
        foreach ($ids as $position => $id) {
            $move->execute([$position, $bdSite, (int) $id]);
        }
        $db->commit();
    } else {
        $db->prepare('DELETE FROM breakdowns WHERE site = ? AND id = ?')->execute([$bdSite, (int) ($input['id'] ?? 0)]);
    }
    respond(json_encode(['ok' => true]), 200, 'application/json');
}

// Annotations: hand-set markers on a date or date range (a film, a mention, a fair), shown on charts
if ((isset($_GET['annotation_add']) || isset($_GET['annotation_update']) || isset($_GET['annotation_remove'])) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_auth();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $anSite = substr((string) ($input['site'] ?? ''), 0, 200);
    if ($anSite === '') respond('', 400);
    require_site_access($config, $anSite);
    $db = get_db($config['db_path']);
    $label = mb_substr(trim((string) ($input['label'] ?? '')), 0, 100);
    $id = (int) ($input['id'] ?? 0);
    if (isset($_GET['annotation_add'])) {
        $isDate = fn($d) => is_string($d) && ($t = DateTime::createFromFormat('!Y-m-d', $d)) && $t->format('Y-m-d') === $d;
        $date = $input['date'] ?? '';
        $endDate = ($input['end_date'] ?? '') ?: null;
        if (!$isDate($date) || ($endDate !== null && (!$isDate($endDate) || $endDate < $date)) || $label === '') respond('', 400);
        $db->prepare('INSERT INTO annotations (site, date, end_date, label, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$anSite, $date, $endDate, $label, date('Y-m-d H:i:s')]);
        $id = (int) $db->lastInsertId();
    } elseif (isset($_GET['annotation_update'])) {
        if ($label === '') respond('', 400);
        $db->prepare('UPDATE annotations SET label = ? WHERE site = ? AND id = ?')->execute([$label, $anSite, $id]);
    } else {
        $db->prepare('DELETE FROM annotations WHERE site = ? AND id = ?')->execute([$anSite, $id]);
    }
    respond(json_encode(['ok' => true, 'id' => $id]), 200, 'application/json');
}

// Excluded IPs: a site's list plus the rows for all sites ('*'), and the caller's own
// address as the tracker sees it, so Settings can exclude it in one step
if (isset($_GET['excluded'])) {
    require_auth();
    $exSite = substr((string) ($_GET['site'] ?? ''), 0, 200);
    if ($exSite === '') respond('', 400);
    require_site_access($config, $exSite);
    $db = get_db($config['db_path']);
    $ip = client_ip($_SERVER, $config['trusted_proxies'] ?? []);
    $stmt = $db->prepare("SELECT site, ip, label, created_at FROM excluded_ips WHERE site IN (?, '*') ORDER BY site = '*', ip");
    $stmt->execute([$exSite]);
    respond(json_encode([
        'ip' => $ip,
        'ip_excluded' => is_excluded_ip($db, $exSite, $ip),
        'excluded' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]), 200, 'application/json');
}

// Add or remove an excluded IP or range. require_site_access turns away '*' for a user
// limited to some sites, so only a user with every site can change the all-sites rows
if ((isset($_GET['exclude_add']) || isset($_GET['exclude_remove'])) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_auth();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $exSite = substr((string) ($input['site'] ?? ''), 0, 200);
    if ($exSite === '') respond('', 400);
    require_site_access($config, $exSite);
    $db = get_db($config['db_path']);
    $raw = trim((string) ($input['ip'] ?? ''));
    $range = normalize_ip_range($raw);
    if (isset($_GET['exclude_add'])) {
        if ($range === null) respond(json_encode(['error' => 'Invalid IP address or range']), 400, 'application/json');
        $label = mb_substr(trim((string) ($input['label'] ?? '')), 0, 100);
        $db->prepare('INSERT OR IGNORE INTO excluded_ips (site, ip, label, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$exSite, $range, $label !== '' ? $label : null, date('Y-m-d H:i:s')]);
    } else {
        $db->prepare('DELETE FROM excluded_ips WHERE site = ? AND ip = ?')->execute([$exSite, $range ?? $raw]);
    }
    respond(json_encode(['ok' => true, 'ip' => $range ?? $raw]), 200, 'application/json');
}

if (isset($_GET['api'])) {
    require_auth();
    $user = get_current_user_data($config);
    // Reading needs nothing more from the session, and PHP holds it locked until it is written:
    // release it, so other requests from the same browser (the hours, a drill-down, another
    // tab) do not wait seconds for this one's data
    session_write_close();
    respond(get_api_data($config, $user), 200, 'application/json');
}

// Default: show dashboard or login
if (!is_authenticated()) {
    handle_login($config);
}

// Serve dashboard
$dashboard = __DIR__ . '/dashboard.html';
if (file_exists($dashboard)) {
    // The version in the stylesheet and script URLs, so a new release is fetched and not read from cache
    $html = str_replace('__PULS_VERSION__', PULS_VERSION, file_get_contents($dashboard));
    $html = inject_after_title($html, config_block($config));
    respond($html, 200, 'text/html', security_headers());
}
respond('Dashboard file not found.', 404, 'text/plain');

// =====================================================================
// SHARE TOKENS
// =====================================================================

/** The token from an "Authorization: Bearer <token>" header, or null without one. */
function bearer_token(array $server): ?string
{
    // Apache passes the header on as REDIRECT_HTTP_AUTHORIZATION after a rewrite
    $header = trim($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : null;
}

function find_share_token(array $config, string $token): ?array
{
    if (strlen($token) < 32) return null;
    $stmt = get_db($config['db_path'])->prepare('SELECT * FROM share_tokens WHERE token = ?');
    $stmt->execute([$token]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function share_token_groups(array $share): array
{
    return json_decode($share['group_names'] ?? '', true) ?: [];
}

// What a token reads: its one site, or what is in its groups now, also sites added later
function share_token_sites(PDO $db, array $share): array
{
    $groups = share_token_groups($share);
    if (!$groups) return $share['site'] !== '' ? [$share['site']] : [];
    $stmt = $db->prepare('SELECT site FROM site_groups WHERE group_name IN (' . implode(',', array_fill(0, count($groups), '?')) . ') ORDER BY site');
    $stmt->execute($groups);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// share:list says when a token was last used, so a forgotten one can be found. The day is enough,
// so it is one write a day and not one per request
function note_share_token_use(array $config, array $share): void
{
    $now = date('Y-m-d H:i:s');
    if (substr($share['last_used_at'] ?? '', 0, 10) === substr($now, 0, 10)) return;
    get_db($config['db_path'])->prepare('UPDATE share_tokens SET last_used_at = ? WHERE id = ?')->execute([$now, $share['id']]);
}

// =====================================================================
// AUTH
// =====================================================================

function start_session(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    // Use dedicated session directory to avoid GC conflicts with other PHP apps
    $sessionPath = dirname($config['db_path']) . '/sessions';
    if (!is_dir($sessionPath)) mkdir($sessionPath, 0700, true);
    cleanup_sessions($sessionPath, $config['session_lifetime']);
    ini_set('session.save_path', $sessionPath);

    ini_set('session.gc_maxlifetime', $config['session_lifetime']);
    session_set_cookie_params([
        'lifetime' => $config['session_lifetime'],
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    // An id just replaced by the hourly new one (below) leads to it for a minute, for a request
    // that was already on its way with it: the dashboard often sends two at once. Deleting the
    // old session at once gave such a request a 401, and the dashboard then went to the login page
    if (isset($_SESSION['replaced_at'])) {
        if (time() - (int) $_SESSION['replaced_at'] > SESSION_REPLACED_GRACE_SECONDS) {
            // Too late: an old id still in use could be a stolen one
            session_destroy();
            session_id(session_create_id());
            session_start();
            return;
        }
        $next = (string) $_SESSION['replaced_by'];
        session_write_close();
        session_id($next);
        session_start();
        return;
    }

    // A new session id every hour makes a stolen one short-lived
    if (!empty($_SESSION['authenticated']) && time() - ($_SESSION['last_rotated'] ?? 0) > 3600) rotate_session();
}

/**
 * Move the session to a new id, keeping the old one for a minute as a pointer to it, without the
 * user (as the PHP manual advises for session_regenerate_id() with requests in flight).
 */
function rotate_session(): void
{
    $data = $_SESSION;
    $next = session_create_id();
    $_SESSION = ['replaced_at' => time(), 'replaced_by' => $next];
    session_write_close();
    session_id($next);
    session_start();
    $_SESSION = ['last_rotated' => time()] + $data;
}

/**
 * Delete session files unused for as long as a session lasts, once a day. PHP's own clean-up
 * does not reach them: Debian and Ubuntu (Forge) set session.gc_probability to 0 and clean
 * only PHP's default directory from cron, and Puls keeps its sessions in a directory of its own.
 */
function cleanup_sessions(string $dir, int $lifetime): void
{
    $marker = $dir . '/.cleanup';
    if (file_exists($marker) && file_get_contents($marker) === date('Y-m-d')) return;
    file_put_contents($marker, date('Y-m-d'));
    foreach (glob($dir . '/sess_*') ?: [] as $file) {
        if (filemtime($file) < time() - $lifetime) @unlink($file);
    }
}

function handle_login(array $config): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_login'])) {
        // Verify CSRF token
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_token'] ?? '')) {
            show_login($config, 'Invalid request. Try again.');
            return;
        }

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // Check brute-force lockout
        if (is_locked_out($config, client_ip($_SERVER, $config['trusted_proxies'] ?? []))) {
            show_login($config, "Too many failed attempts. Wait {$config['lockout_minutes']} minutes.");
            return;
        }

        $users = load_users($config['users_file']);

        if (isset($users[$username]) && password_verify($password, $users[$username]['password'])) {
            // Success — regenerate session, invalidate CSRF token
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']);
            $_SESSION['authenticated'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['last_rotated'] = time();
            audit_log($config, $username, 'login_success');

            // Redirect to dashboard
            respond('', 302, null, ['Location' => '/']);
        }

        // Failed login (the audit log entry is what the lockout counts)
        audit_log($config, $username, 'login_failed');
        show_login($config, 'Invalid username or password.');
        return;
    }

    if (is_authenticated()) respond('', 302, null, ['Location' => '/']);

    show_login($config);
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
    $ip = client_ip($_SERVER, $config['trusted_proxies'] ?? []);
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

/** 403 unless the logged-in user may manage $site (empty site list = all sites). */
function require_site_access(array $config, string $site): void
{
    $allowed = get_current_user_data($config)['sites'] ?? [];
    if (!empty($allowed) && !in_array($site, $allowed, true)) {
        respond(json_encode(['error' => 'Access denied']), 403, 'application/json');
    }
}

function get_current_user_data(array $config): array
{
    $username = $_SESSION['username'] ?? '';
    $users = load_users($config['users_file']);
    return $users[$username] ?? ['sites' => []];
}

/**
 * Locked out after max_login_attempts failed logins from this IP in the last
 * lockout_minutes, counted since its last successful login. Counted in the audit
 * log rather than the session, so a client that drops its cookie is still counted.
 */
function is_locked_out(array $config, string $ip): bool
{
    $db = get_db($config['db_path']);
    $stmt = $db->prepare("SELECT COUNT(*) FROM audit_log
        WHERE ip = ? AND action = 'login_failed' AND created_at >= ?
        AND id > COALESCE((SELECT MAX(id) FROM audit_log WHERE ip = ? AND action = 'login_success'), 0)");
    $stmt->execute([$ip, date('Y-m-d H:i:s', time() - $config['lockout_minutes'] * 60), $ip]);
    return (int) $stmt->fetchColumn() >= $config['max_login_attempts'];
}

function load_users(string $file): array
{
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}

function show_login(array $config, ?string $error = null): void
{
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    $errorHtml = $error ? '<div class="error" id="login-error" role="alert">' . htmlspecialchars($error) . '</div>' : '';
    $describedBy = $error ? ' aria-describedby="login-error"' : '';
    $name = htmlspecialchars($config['app_name']);
    $tagline = htmlspecialchars($config['app_tagline']);
    $accent = htmlspecialchars($config['app_accent']);
    $accentEnc = str_replace('#', '%23', $accent);
    $configBlock = config_block($config);
    $v = PULS_VERSION;

    respond(<<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{$name}">
    <meta name="theme-color" content="{$accent}" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0f172a" media="(prefers-color-scheme: dark)">
    <link rel="manifest" href="/?manifest">
    <link rel="apple-touch-icon" href="/icon-180.png">
    <title>{$name} — Log in</title>{$configBlock}
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 36 36'><rect width='36' height='36' rx='8' fill='{$accentEnc}'/><path d='M24 26V16M18 26V10M12 26v-8' stroke='white' stroke-width='2.5' stroke-linecap='round'/></svg>">
    <script src="theme.js?v={$v}"></script>
    <link rel="stylesheet" href="login.css?v={$v}">
    </head>
    <body>
    <div class="login-card">
      <div class="logo">
        <div class="logo-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/>
          </svg>
        </div>
        <span class="logo-text">{$name}</span>
      </div>
      <p class="tagline">{$tagline}</p>
      {$errorHtml}
      <form method="POST" action="/?login">
        <input type="hidden" name="_login" value="1">
        <input type="hidden" name="_token" value="{$token}">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" autocomplete="username" required autofocus{$describedBy}>
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required{$describedBy}>
        <button type="submit">Log in</button>
      </form>
    </div>
    <script src="login.js?v={$v}" defer></script>
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
      var sc=document.currentScript||document.querySelector('script[src*="?js"][data-site]')||{src:'/stats.php',dataset:{}};
      var base=sc.src.split('?')[0];
      var ep=base+'?collect';
      var evEp=base+'?event';
      var site=sc.dataset.site||location.hostname;
      var ver=sc.dataset.version||null;
      var dbg=sc.dataset.debug!==undefined;
      var qk=sc.dataset.query;
      var ci=sc.dataset.collapseIds!==undefined;
      var ct=sc.dataset.collapseTokens!==undefined;
      var tk=/\/(?:(?=[A-Za-z0-9]*\d)(?=[A-Za-z0-9]*[A-Za-z])[A-Za-z0-9]{16,}|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})(?=\/|$)/g;
      function pth(x){x=x||sc.dataset.path||location.pathname;if(ct)x=x.replace(tk,'/:token');return ci?x.replace(/\/\d+(?=\/|$)/g,'/:id'):x}
      function log(){if(dbg&&window.console)console.log.apply(console,['[puls]'].concat([].slice.call(arguments)))}
      function off(){try{return localStorage.getItem('puls_ignore')==='1'}catch(e){return false}}
      function opt(){var h=location.hash;if(h!=='#puls-ignore'&&h!=='#puls-track')return;try{h==='#puls-ignore'?localStorage.setItem('puls_ignore','1'):localStorage.removeItem('puls_ignore')}catch(e){return}history.replaceState(null,'',location.pathname+location.search);alert(h==='#puls-ignore'?'Puls: visits from this browser are no longer counted on '+location.hostname+'.':'Puls: visits from this browser are counted again on '+location.hostname+'.')}
      opt();addEventListener('hashchange',opt);
      function utm(){var p=new URLSearchParams(location.search),o={};['source','medium','campaign','term','content'].forEach(function(k){var v=p.get('utm_'+k);if(v)o[k]=v});if(!o.source&&p.get('gad_source')){o.source='google';o.medium='cpc';var c=p.get('gad_campaignid');if(c)o.campaign=c}return Object.keys(o).length?o:null}
      function url(p){var s=location.search,x;if(typeof p=='string'){try{p=new URL(p,location.href)}catch(e){return}if(p.origin!==location.origin)return;x=p.pathname;s=p.search}if(qk!==undefined&&s){var k=qk==='none'?[]:qk.split(',').map(function(x){return x.trim()}),p=new URLSearchParams(s),o=new URLSearchParams();p.forEach(function(v,n){if(k.indexOf(n)>=0)o.append(n,v)});s=o.toString();s=s?'?'+s:''}return pth(x)+s}
      var last;
      function s(p){
        if(off()){log('ignored: this browser is excluded');return}
        var u=url(p);if(!u){log('ignored: not this site',p);return}if(u===last){log('skipped: same URL as the last pageview');return}last=u;
        var d=JSON.stringify({u:u,r:document.referrer,w:innerWidth,site:site,utm:utm(),v:ver});
        log('pageview',u);
        navigator.sendBeacon?navigator.sendBeacon(ep,d):0;
      }
      function q(){'requestIdleCallback' in window?requestIdleCallback(s):setTimeout(s,0)}
      q();
      if(history.pushState){var o=history.pushState;history.pushState=function(){o.apply(this,arguments);q()};addEventListener('popstate',q)}
      var prev=window.puls;
      window.puls={page:s,track:function(name,data){if(!name||off())return;var p={event_name:name,site:site,page_path:pth()};if(ver)p.app_version=ver;if(data&&typeof data==='object')p.event_data=data;log('event',name,data||'');navigator.sendBeacon?navigator.sendBeacon(evEp,JSON.stringify(p)):0}};
      if(prev&&prev.q&&prev.q.length){log('draining',prev.q.length,'queued call(s)');for(var i=0;i<prev.q.length;i++){var e=prev.q[i];e.page?s(e.page[0]):puls.track.apply(null,e)}}
      if(sc.dataset.outbound!==undefined){document.addEventListener('click',function(e){var a=e.target.closest('a[href]');if(!a)return;try{var u=new URL(a.href,location.origin);if(u.hostname===location.hostname||u.protocol!=='http:'&&u.protocol!=='https:')return;puls.track('outbound_click',{url:u.href,text:(a.textContent||'').trim().substring(0,200)})}catch(ex){}},true)}
      if(sc.dataset.autoEvents!==undefined){
        document.addEventListener('click',function(e){
          var a=e.target.closest('a[href]');if(!a)return;
          var h=a.getAttribute('href')||'';
          if(h.indexOf('tel:')===0){puls.track('phone_click',{number:h.replace('tel:','').replace(/\s/g,''),page:pth()})}
          else if(h.indexOf('mailto:')===0){puls.track('email_click',{email:h.replace('mailto:','').split('?')[0],page:pth()})}
          else{var ext=h.split('?')[0].split('.').pop().toLowerCase();if(['pdf','doc','docx','xls','xlsx','csv','zip','rar'].indexOf(ext)>=0){puls.track('download',{file:h.split('/').pop().split('?')[0],url:h,page:pth()})}}
        },true);
        document.addEventListener('submit',function(e){
          if(e.defaultPrevented)return;
          var f=e.target.closest('form');if(!f)return;
          var d={action:f.getAttribute('action')||pth(),page:pth()};
          var id=f.dataset.pulsEvent||f.getAttribute('name')||f.id;if(id)d.form=id;
          puls.track('form_submit',d)
        });
      }
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
    $bot = detect_script_bot($ua, $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    if ($bot) {
        if (is_ignored_bot($ua, $config['ignored_bots'] ?? [])) return;
        $db = get_db($config['db_path']);
        $site = normalize_site(!empty($input['site']) ? $input['site'] : ($_SERVER['HTTP_HOST'] ?? 'unknown'));
        $path = normalize_path(urldecode(substr($input['u'], 0, 500)));
        $stmt = $db->prepare('INSERT INTO bot_visits (site, path, bot_name, bot_category, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$site, $path, $bot['name'], $bot['category'], substr($ua, 0, 500), date('Y-m-d H:i:s')]);
        cleanup_bot_visits($db, $config['bot_retention_days'] ?? 90);
        return;
    }

    $db = get_db($config['db_path']);

    $site = normalize_site(!empty($input['site']) ? $input['site'] : ($_SERVER['HTTP_HOST'] ?? 'unknown'));
    $ip = client_ip($_SERVER, $config['trusted_proxies'] ?? []);
    if (is_excluded_ip($db, $site, $ip)) return;

    $hash = visitor_hash($db, $site, $ip, $_SERVER['HTTP_USER_AGENT'] ?? '');

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
    $country = null;
    if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
        $country = detect_country($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }

    $path = normalize_path(urldecode(substr($input['u'], 0, 500)), is_meta_source($utmSource) ? meta_ad_params() : []);

    $appVersion = normalize_version($input['v'] ?? null);
    record_app_version($db, $site, $appVersion);

    $stmt = $db->prepare('INSERT INTO pageviews (site, path, referrer, browser, device, visitor_hash, utm_source, utm_medium, utm_campaign, utm_term, utm_content, language, country, app_version, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
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
        $country,
        $appVersion,
        date('Y-m-d H:i:s'),
    ]);

    refresh_statistics($db);
    sum_closed_day($db);
}

/**
 * Give the query planner current statistics on the tables, once a day. Without them it
 * picks plans as if every index were equally selective; with them it knows that pageviews
 * has few distinct sites and many rows each, and walks the covering index one site at a
 * time for All sites instead of scanning the table. analysis_limit samples about a
 * thousand rows per index, so this takes milliseconds on millions of rows.
 */
function refresh_statistics(PDO $db): void
{
    $marker = dirname($db->query("PRAGMA database_list")->fetch()['file'] ?? __DIR__) . '/.analyze';
    if (file_exists($marker) && file_get_contents($marker) === date('Y-m-d')) return;

    $db->exec('PRAGMA analysis_limit = 1000');
    $db->exec('ANALYZE');
    file_put_contents($marker, date('Y-m-d'));
}

// =====================================================================
// DAILY SUMMARY
// =====================================================================

// Every site with a pageview. A loose index scan: it walks idx_site_date_visitor one distinct
// site at a time instead of scanning every row, so its cost follows the number of sites, not
// the size of the table, and stays flat as history accumulates
function tracked_sites(PDO $db): array
{
    return $db->query('WITH RECURSIVE tracked(site) AS (
            SELECT MIN(site) FROM pageviews
            UNION ALL
            SELECT (SELECT MIN(site) FROM pageviews WHERE site > tracked.site)
            FROM tracked WHERE tracked.site IS NOT NULL
        )
        SELECT site FROM tracked WHERE site IS NOT NULL ORDER BY site')->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * A summary per site and day, which the dashboard reads instead of every pageview of a period.
 * Exact, since the visitor hash changes every day: a visitor never spans two days, so a figure
 * counted per visitor over a period is the sum of the days'. A cache, never the source: rows can
 * always be deleted, and are summed again when needed. One row per kind and key:
 *   day       ''            views, visitors, bounces (visitors with one pageview)
 *   hour      0 to 23       views
 *   path      the path      views, visitors, entries, bounces (entries with one pageview), exits
 *   referrer  the source    visitors
 *   utm       the five values as a JSON array   visitors
 *   channel   its name      views, visitors
 *   country   the code      visitors
 *   browser, device, language   the value ('' for none)   views
 *   duration  seconds       visitors with more than one pageview and that length
 * The day row is there for every summed day, also one without pageviews, so a summed day can
 * be told from one not summed yet.
 */
function create_daily_table(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS daily (
        site TEXT NOT NULL,
        date TEXT NOT NULL,
        kind TEXT NOT NULL,
        key TEXT NOT NULL,
        views INTEGER NOT NULL DEFAULT 0,
        visitors INTEGER NOT NULL DEFAULT 0,
        entries INTEGER NOT NULL DEFAULT 0,
        bounces INTEGER NOT NULL DEFAULT 0,
        exits INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (site, date, kind, key)
    ) WITHOUT ROWID');
}

// A visitor's channel, from their pageviews of a day (an aggregate over GROUP BY visitor_hash)
function channel_sql(): string
{
    return "CASE
        WHEN MAX(utm_medium) IN ('paid', 'cpc', 'cpm', 'ppc', 'retargeting', 'paid_social') THEN 'Paid'
        WHEN MAX(utm_source) IS NOT NULL THEN 'Campaign'
        WHEN MAX(referrer) IN ('Google', 'Bing') THEN 'Organic search'
        WHEN MAX(referrer) IN ('Facebook', 'Instagram', 'Twitter/X', 'LinkedIn') THEN 'Social'
        WHEN MAX(referrer) IS NOT NULL THEN 'Referral'
        ELSE 'Direct'
    END";
}

/**
 * The summary rows of the sites' days in [$from, $to), from their pageviews, one pass per kind
 * over the whole span: [site][date] => rows. Everything counted per visitor is grouped by day
 * as well, so a summed period is the sum of its days even where an old hash spanned days. Ties
 * in a visitor's first and last page (two pageviews in one second) are broken by id, so a day
 * sums the same every time.
 */
function range_rows(PDO $db, array $sites, string $from, string $to): array
{
    $where = 'site IN (' . implode(',', array_fill(0, count($sites), '?')) . ') AND created_at >= ? AND created_at < ?';
    $params = array_merge($sites, [$from, $to]);
    $day = 'substr(created_at, 1, 10)';
    $rows = [];
    $each = function (string $sql, callable $row) use ($db, $params) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $row($r);
    };
    $add = function (string $site, string $date, string $kind, $key, array $values) use (&$rows) {
        $rows[$site][$date][] = ['kind' => $kind, 'key' => (string) ($key ?? '')] + $values + ['views' => 0, 'visitors' => 0, 'entries' => 0, 'bounces' => 0, 'exits' => 0];
    };

    $bounces = [];
    $each("SELECT site, d, COUNT(*) as n FROM (SELECT site, {$day} as d FROM pageviews WHERE {$where} GROUP BY site, d, visitor_hash HAVING COUNT(*) = 1) GROUP BY site, d", function ($r) use (&$bounces) { $bounces[$r['site']][$r['d']] = (int) $r['n']; });
    $each("SELECT site, {$day} as d, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE {$where} GROUP BY site, d", fn ($r) => $add($r['site'], $r['d'], 'day', '', ['views' => (int) $r['views'], 'visitors' => (int) $r['visitors'], 'bounces' => $bounces[$r['site']][$r['d']] ?? 0]));
    $each("SELECT site, {$day} as d, CAST(strftime('%H', created_at) AS INTEGER) as k, COUNT(*) as views FROM pageviews WHERE {$where} GROUP BY site, d, k", fn ($r) => $add($r['site'], $r['d'], 'hour', $r['k'], ['views' => (int) $r['views']]));
    $firstLast = [];
    $each("SELECT site, d, path, SUM(is_entry) as entries, SUM(is_entry AND cnt = 1) as bounces, SUM(is_exit) as exits FROM (SELECT site, {$day} as d, path, ROW_NUMBER() OVER (PARTITION BY site, {$day}, visitor_hash ORDER BY created_at, id) = 1 as is_entry, ROW_NUMBER() OVER (PARTITION BY site, {$day}, visitor_hash ORDER BY created_at DESC, id DESC) = 1 as is_exit, COUNT(*) OVER (PARTITION BY site, {$day}, visitor_hash) as cnt FROM pageviews WHERE {$where}) WHERE is_entry OR is_exit GROUP BY site, d, path", function ($r) use (&$firstLast) {
        $firstLast[$r['site']][$r['d']][$r['path']] = ['entries' => (int) $r['entries'], 'bounces' => (int) $r['bounces'], 'exits' => (int) $r['exits']];
    });
    $each("SELECT site, {$day} as d, path, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE {$where} GROUP BY site, d, path", fn ($r) => $add($r['site'], $r['d'], 'path', $r['path'], ['views' => (int) $r['views'], 'visitors' => (int) $r['visitors']] + ($firstLast[$r['site']][$r['d']][$r['path']] ?? [])));
    $each("SELECT site, {$day} as d, referrer as k, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE {$where} AND referrer IS NOT NULL GROUP BY site, d, referrer", fn ($r) => $add($r['site'], $r['d'], 'referrer', $r['k'], ['visitors' => (int) $r['visitors']]));
    // The five values written as SQLite's json_array() writes them, so the rows query sorts ties the same way
    $each("SELECT site, {$day} as d, utm_source, utm_medium, utm_campaign, utm_term, utm_content, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE {$where} AND utm_source IS NOT NULL GROUP BY site, d, utm_source, utm_medium, utm_campaign, utm_term, utm_content", fn ($r) => $add($r['site'], $r['d'], 'utm', json_encode([$r['utm_source'], $r['utm_medium'], $r['utm_campaign'], $r['utm_term'], $r['utm_content']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ['visitors' => (int) $r['visitors']]));
    $each("SELECT site, d, channel as k, COUNT(*) as visitors, SUM(pv) as views FROM (SELECT site, {$day} as d, COUNT(*) as pv, " . channel_sql() . " as channel FROM pageviews WHERE {$where} GROUP BY site, d, visitor_hash) GROUP BY site, d, channel", fn ($r) => $add($r['site'], $r['d'], 'channel', $r['k'], ['views' => (int) $r['views'], 'visitors' => (int) $r['visitors']]));
    $each("SELECT site, {$day} as d, country as k, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE {$where} AND country IS NOT NULL GROUP BY site, d, country", fn ($r) => $add($r['site'], $r['d'], 'country', $r['k'], ['visitors' => (int) $r['visitors']]));
    foreach (['browser', 'device', 'language'] as $column) {
        $each("SELECT site, {$day} as d, {$column} as k, COUNT(*) as views FROM pageviews WHERE {$where} GROUP BY site, d, {$column}", fn ($r) => $add($r['site'], $r['d'], $column, $r['k'], ['views' => (int) $r['views']]));
    }
    $each("SELECT site, d, duration as k, COUNT(*) as visitors FROM (SELECT site, {$day} as d, strftime('%s', MAX(created_at)) - strftime('%s', MIN(created_at)) as duration FROM pageviews WHERE {$where} GROUP BY site, d, visitor_hash HAVING COUNT(*) > 1) GROUP BY site, d, duration", fn ($r) => $add($r['site'], $r['d'], 'duration', $r['k'], ['visitors' => (int) $r['visitors']]));
    return $rows;
}

// The last day that is over: yesterday, once five minutes of today have passed, so a request
// still on its way at midnight lands in the day before it is summed
function last_closed_day(): string
{
    return date('Y-m-d', strtotime(time() - strtotime('today') < 300 ? '-2 days' : '-1 day'));
}

/**
 * Sums the days of the sites and period [$since, $until) that are over and not summed yet,
 * newest first (the short periods most people look at come first), at most $limit of them,
 * and returns how many are still missing. Days before a site's first pageview are not summed.
 * A window of up to 31 days is counted in one pass and written in one short transaction, so
 * filling an upgraded install costs about one read of its pageviews, and tracking is never
 * held up for long.
 */
function sum_missing_days(PDO $db, array $sites, string $since, string $until, int $limit): int
{
    $last = last_closed_day();
    $end = $until ? min($last, date('Y-m-d', strtotime($until . ' -1 day'))) : $last;
    $firstSeen = $db->prepare('SELECT MIN(created_at) FROM pageviews WHERE site = ?');
    $summed = $db->prepare("SELECT date FROM daily WHERE site = ? AND kind = 'day' AND date >= ? AND date <= ?");
    $missing = [];
    foreach ($sites as $site) {
        $firstSeen->execute([$site]);
        $first = $firstSeen->fetchColumn();
        // A statement left open keeps its read going, and when tracking writes meanwhile SQLite
        // refuses this connection's write at once, without waiting (SQLITE_BUSY_SNAPSHOT)
        $firstSeen->closeCursor();
        if (!$first) continue;
        $start = max($since, substr($first, 0, 10));
        if ($start > $end) continue;
        $summed->execute([$site, $start, $end]);
        $have = array_flip($summed->fetchAll(PDO::FETCH_COLUMN));
        $summed->closeCursor();
        for ($d = $end; $d >= $start; $d = date('Y-m-d', strtotime($d . ' -1 day'))) {
            if (!isset($have[$d])) $missing[] = [$site, $d];
        }
    }
    usort($missing, fn ($a, $b) => strcmp($b[1], $a[1]) ?: strcmp($a[0], $b[0]));
    $todo = array_slice($missing, 0, $limit);
    while ($todo) {
        $windowEnd = $todo[0][1];
        $windowStart = date('Y-m-d', strtotime($windowEnd . ' -30 days'));
        $window = array_values(array_filter($todo, fn ($m) => $m[1] >= $windowStart));
        $todo = array_values(array_filter($todo, fn ($m) => $m[1] < $windowStart));
        $start = end($window)[1];
        $rows = range_rows($db, array_values(array_unique(array_column($window, 0))), $start, date('Y-m-d', strtotime($windowEnd . ' +1 day')));
        store_days($db, array_map(fn ($m) => [$m[0], $m[1], $rows[$m[0]][$m[1]] ?? []], $window));
    }
    return max(0, count($missing) - $limit);
}

// Writes summed days, each [site, date, rows], replacing what was there, in one transaction. A day
// without pageviews gets its day row as zero, so it is not summed again
function store_days(PDO $db, array $days): void
{
    $own = !$db->inTransaction();
    if ($own) $db->exec('BEGIN IMMEDIATE');
    try {
        $delete = $db->prepare('DELETE FROM daily WHERE site = ? AND date = ?');
        $insert = $db->prepare('INSERT INTO daily (site, date, kind, key, views, visitors, entries, bounces, exits) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($days as [$site, $date, $rows]) {
            $delete->execute([$site, $date]);
            if (!$rows) $rows = [['kind' => 'day', 'key' => '', 'views' => 0, 'visitors' => 0, 'entries' => 0, 'bounces' => 0, 'exits' => 0]];
            foreach ($rows as $r) {
                $insert->execute([$site, $date, $r['kind'], $r['key'], $r['views'], $r['visitors'], $r['entries'], $r['bounces'], $r['exits']]);
            }
        }
        if ($own) $db->exec('COMMIT');
    } catch (Throwable $e) {
        if ($own) $db->exec('ROLLBACK');
        throw $e;
    }
}

/**
 * Once a day, at the first pageview after last_closed_day() moves on, that day is summed for
 * every site that had a pageview in it, so the dashboard rarely meets a day not summed yet. A
 * marker file holds the day, as for refresh_statistics()
 */
function sum_closed_day(PDO $db): void
{
    $day = last_closed_day();
    $marker = dirname($db->query("PRAGMA database_list")->fetch()['file'] ?? __DIR__) . '/.summed';
    if (file_exists($marker) && file_get_contents($marker) === $day) return;
    $stmt = $db->prepare('SELECT DISTINCT site FROM pageviews WHERE created_at >= ? AND created_at < ?');
    $stmt->execute([$day, date('Y-m-d', strtotime($day . ' +1 day'))]);
    $sites = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $stmt->closeCursor();
    // The pageview is stored already; a busy database only means the day is summed later
    try {
        sum_missing_days($db, $sites, $day, date('Y-m-d', strtotime($day . ' +1 day')), PHP_INT_MAX);
    } catch (PDOException $e) {
        return;
    }
    file_put_contents($marker, $day);
}

/**
 * The dashboard's pageview figures for a period [$since, $end) and the one before it, from the
 * daily summary: the days that are over from the table, the days not over yet from their
 * pageviews. Summing a missing day costs about what reading it from the rows does, so a request
 * sums at most one window of 31 days; if days are still missing after that it returns null and
 * the dashboard reads the rows, as before the summary. An upgraded install is summed over its
 * first views of long periods, or at once with php puls stats:build. The same shapes as the queries over the rows in
 * get_api_data(), which a path or a channel filter still uses; the tests compare the two field by
 * field. Ties are broken by name, as those queries do, so both list the same rows in the same order.
 */
function summary_figures(PDO $db, array $sites, string $since, string $end, string $prevSince, array $limits): ?array
{
    // If summing fails (the database busy), the dashboard reads the rows this time rather than fail
    try {
        if (sum_missing_days($db, $sites, $prevSince, $end, 31 * count($sites)) > 0) return null;
    } catch (PDOException $e) {
        return null;
    }
    $last = last_closed_day();
    // The days not over yet, counted from their pageviews: [date] => every site's rows of the day
    $open = [];
    $openFrom = max($prevSince, date('Y-m-d', strtotime($last . ' +1 day')));
    if ($openFrom < $end) {
        foreach (range_rows($db, $sites, $openFrom, $end) as $bySite) {
            foreach ($bySite as $d => $rows) $open[$d] = array_merge($open[$d] ?? [], $rows);
        }
    }
    $in = implode(',', array_fill(0, count($sites), '?'));

    // Per kind and key, summed over a period
    $sums = function (string $from, string $to) use ($db, $sites, $in, $last, $open): array {
        $stmt = $db->prepare("SELECT kind, key, SUM(views) as views, SUM(visitors) as visitors, SUM(entries) as entries, SUM(bounces) as bounces, SUM(exits) as exits FROM daily WHERE site IN ({$in}) AND date >= ? AND date < ? AND date <= ? GROUP BY kind, key");
        $stmt->execute(array_merge($sites, [$from, $to, $last]));
        $out = [];
        $add = function (array $r) use (&$out) {
            $k = &$out[$r['kind']][$r['key']];
            foreach (['views', 'visitors', 'entries', 'bounces', 'exits'] as $f) $k[$f] = ($k[$f] ?? 0) + (int) $r[$f];
        };
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $add($r);
        foreach ($open as $d => $rows) {
            if ($d >= $from && $d < $to) foreach ($rows as $r) $add($r);
        }
        return $out;
    };
    // One row per day with pageviews, as the chart wants
    $days = function (string $from, string $to) use ($db, $sites, $in, $last, $open): array {
        $stmt = $db->prepare("SELECT date, SUM(views) as views, SUM(visitors) as visitors FROM daily WHERE site IN ({$in}) AND kind = 'day' AND date >= ? AND date < ? AND date <= ? GROUP BY date HAVING SUM(views) > 0 ORDER BY date");
        $stmt->execute(array_merge($sites, [$from, $to, $last]));
        $out = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($open as $d => $rows) {
            if ($d < $from || $d >= $to) continue;
            $dayRows = array_filter($rows, fn ($r) => $r['kind'] === 'day');
            $views = array_sum(array_column($dayRows, 'views'));
            if ($views > 0) $out[] = ['date' => $d, 'views' => $views, 'visitors' => array_sum(array_column($dayRows, 'visitors'))];
        }
        usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));
        return array_map(fn ($r) => ['date' => $r['date'], 'views' => (int) $r['views'], 'visitors' => (int) $r['visitors']], $out);
    };
    // Rows of a kind, biggest first, ties by key
    $ranked = function (array $byKey, string $field) {
        uksort($byKey, fn ($a, $b) => ($byKey[$b][$field] <=> $byKey[$a][$field]) ?: strcmp((string) $a, (string) $b));
        return $byKey;
    };
    // The median of visit lengths, from counts per length: the row at n / 2, as the query does
    $median = function (array $durations): ?int {
        ksort($durations, SORT_NUMERIC);
        $n = array_sum(array_column($durations, 'visitors'));
        if (!$n) return null;
        $at = intdiv($n, 2);
        foreach ($durations as $seconds => $r) {
            if ($at < $r['visitors']) return (int) $seconds;
            $at -= $r['visitors'];
        }
        return null;
    };

    $cur = $sums($since, $end);
    $prev = $sums($prevSince, $since);
    $day = $cur['day'][''] ?? ['views' => 0, 'visitors' => 0, 'bounces' => 0];
    $prevDay = $prev['day'][''] ?? ['views' => 0, 'visitors' => 0, 'bounces' => 0];

    $pages = $ranked($cur['path'] ?? [], 'views');
    $referrers = $ranked($cur['referrer'] ?? [], 'visitors');
    $utm = $ranked($cur['utm'] ?? [], 'visitors');
    $countries = $ranked($cur['country'] ?? [], 'visitors');
    $channels = $ranked($cur['channel'] ?? [], 'visitors');
    $counts = fn (string $kind, bool $skipNone) => array_values(array_filter(array_map(
        fn ($key, $r) => ['name' => $key === '' ? null : (string) $key, 'cnt' => $r['views']],
        array_keys($ranked($cur[$kind] ?? [], 'views')), $ranked($cur[$kind] ?? [], 'views')
    ), fn ($r) => !$skipNone || $r['name'] !== null));
    $weekdays = [];
    foreach ($days($since, $end) as $r) {
        $w = (int) date('w', strtotime($r['date']));
        $weekdays[$w] = ($weekdays[$w] ?? 0) + $r['views'];
    }
    uksort($weekdays, fn ($a, $b) => ($weekdays[$b] <=> $weekdays[$a]) ?: $a <=> $b);
    $hours = array_map(fn ($r) => $r['views'], $cur['hour'] ?? []);
    uksort($hours, fn ($a, $b) => ($hours[$b] <=> $hours[$a]) ?: $a <=> $b);
    $weekdayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    return [
        'byDay' => $days($since, $end),
        'previousPageviews' => $days($prevSince, $since),
        'totals' => ['views' => $day['views'], 'visitors' => $day['visitors']],
        'previousTotals' => ['views' => $prevDay['views'], 'visitors' => $prevDay['visitors']],
        'pages' => array_map(fn ($k, $r) => ['path' => (string) $k, 'views' => $r['views'], 'visitors' => $r['visitors']], array_keys(array_slice($pages, 0, $limits['pages'], true)), array_slice($pages, 0, $limits['pages'], true)),
        'pagesTotal' => count($pages),
        'referrers' => array_map(fn ($k, $r) => ['source' => (string) $k, 'visitors' => $r['visitors']], array_keys(array_slice($referrers, 0, $limits['referrers'], true)), array_slice($referrers, 0, $limits['referrers'], true)),
        'referrersTotal' => count($referrers),
        'bounceRate' => $day['visitors'] > 0 ? round(100 * $day['bounces'] / $day['visitors'], 1) : 0,
        'previousBounceRate' => $prevDay['visitors'] > 0 ? round(100 * $prevDay['bounces'] / $prevDay['visitors'], 1) : null,
        'medianSession' => $median($cur['duration'] ?? []) ?? 0,
        'previousMedianSession' => $median($prev['duration'] ?? []),
        'firstLast' => array_values(array_map(fn ($k, $r) => ['path' => (string) $k, 'entries' => $r['entries'], 'bounces' => $r['bounces'], 'exits' => $r['exits']], array_keys($cur['path'] ?? []), $cur['path'] ?? [])),
        'browsers' => $counts('browser', false),
        'devices' => $counts('device', false),
        'languages' => $counts('language', true),
        'utm' => array_map(function ($k, $r) {
            [$source, $medium, $campaign, $term, $content] = json_decode($k, true);
            return compact('source', 'medium', 'campaign', 'term', 'content') + ['visitors' => $r['visitors']];
        }, array_keys(array_slice($utm, 0, $limits['utm'], true)), array_slice($utm, 0, $limits['utm'], true)),
        'utmTotal' => count($utm),
        'countries' => array_map(fn ($k, $r) => ['code' => (string) $k, 'visitors' => $r['visitors']], array_keys($countries), $countries),
        'channels' => array_map(fn ($k, $r) => ['channel' => (string) $k, 'visitors' => $r['visitors'], 'views' => $r['views'], 'pages_per_visitor' => round($r['views'] / $r['visitors'], 1)], array_keys($channels), $channels),
        'bestDay' => $day['views'] > 0 ? ['day' => $weekdayNames[array_key_first($weekdays)], 'views' => $weekdays[array_key_first($weekdays)]] : false,
        'peakHour' => $hours ? ['hour' => (int) array_key_first($hours), 'views' => $hours[array_key_first($hours)]] : false,
        'open' => $open,
        'last' => $last,
    ];
}

/**
 * Per site, views and visitors over a period and views over the one before, for the site
 * overview: from the summary, with the days not over yet counted from their pageviews
 */
function summary_sites(PDO $db, array $sites, string $since, string $end, string $prevSince, string $last): array
{
    $in = implode(',', array_fill(0, count($sites), '?'));
    $open = max($prevSince, date('Y-m-d', strtotime($last . ' +1 day')));
    $out = [];
    $stmt = $db->prepare("SELECT site, SUM(CASE WHEN date >= ? THEN views END) as views, SUM(CASE WHEN date >= ? THEN visitors END) as visitors, SUM(CASE WHEN date < ? THEN views END) as prev FROM daily WHERE site IN ({$in}) AND kind = 'day' AND date >= ? AND date < ? AND date <= ? GROUP BY site");
    $stmt->execute(array_merge([$since, $since, $since], $sites, [$prevSince, $end, $last]));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['site']] = ['views' => (int) $r['views'], 'visitors' => (int) $r['visitors'], 'prev' => (int) $r['prev']];
    }
    $stmt = $db->prepare("SELECT site, SUM(created_at >= ?) as views, COUNT(DISTINCT CASE WHEN created_at >= ? THEN visitor_hash END) as visitors, SUM(created_at < ?) as prev FROM pageviews WHERE site IN ({$in}) AND created_at >= ? AND created_at < ? GROUP BY site");
    $stmt->execute(array_merge([$since, $since, $since], $sites, [$open, $end]));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        foreach (['views', 'visitors', 'prev'] as $f) $out[$r['site']][$f] = ($out[$r['site']][$f] ?? 0) + (int) $r[$f];
    }
    return $out;
}

// Visitors who viewed a path, per day of a period, from the summary (a page goal's days)
function summary_path_days(PDO $db, array $sites, string $path, string $from, string $end, array $open, string $last): array
{
    $in = implode(',', array_fill(0, count($sites), '?'));
    $stmt = $db->prepare("SELECT date, SUM(visitors) as visitors FROM daily WHERE site IN ({$in}) AND kind = 'path' AND key = ? AND date >= ? AND date < ? AND date <= ? GROUP BY date");
    $stmt->execute(array_merge($sites, [$path, $from, $end, $last]));
    $out = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'visitors', 'date'));
    foreach ($open as $d => $rows) {
        if ($d < $from || $d >= $end) continue;
        foreach ($rows as $r) {
            if ($r['kind'] === 'path' && $r['key'] === $path && $r['visitors']) $out[$d] = ($out[$d] ?? 0) + $r['visitors'];
        }
    }
    return $out;
}

// Sums one site's day into the summary, replacing what was there
function sum_day(PDO $db, string $site, string $date): void
{
    $rows = range_rows($db, [$site], $date, date('Y-m-d', strtotime($date . ' +1 day')));
    store_days($db, [[$site, $date, $rows[$site][$date] ?? []]]);
}

// =====================================================================
// EVENT ENDPOINT
// =====================================================================

function handle_event(array $config): void
{
    $raw = file_get_contents('php://input');
    if (strlen($raw) > 10000) return;

    $input = json_decode($raw, true);
    if (!$input || empty($input['event_name'])) return;

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (detect_script_bot($ua, $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) return;

    $db = get_db($config['db_path']);

    $site = normalize_site(!empty($input['site']) ? $input['site'] : ($_SERVER['HTTP_HOST'] ?? 'unknown'));
    $ip = client_ip($_SERVER, $config['trusted_proxies'] ?? []);
    if (is_excluded_ip($db, $site, $ip)) return;
    $eventName = substr(trim($input['event_name']), 0, 100);
    $pagePath = !empty($input['page_path']) ? normalize_path(urldecode(substr($input['page_path'], 0, 500))) : null;

    $eventData = null;
    if (!empty($input['event_data']) && is_array($input['event_data'])) {
        $eventData = substr(json_encode($input['event_data']), 0, 1000);
    }

    $hash = visitor_hash($db, $site, $ip, $_SERVER['HTTP_USER_AGENT'] ?? '');

    // Dedup: skip same event with same data from same visitor within 10 seconds.
    // IS (not =) so two events without data also match each other (NULL IS NULL).
    $stmt = $db->prepare('SELECT id FROM events WHERE site = ? AND event_name = ? AND visitor_hash = ? AND event_data IS ? AND created_at > ? LIMIT 1');
    $stmt->execute([$site, $eventName, $hash, $eventData, date('Y-m-d H:i:s', time() - 10)]);
    if ($stmt->fetch()) return;

    $appVersion = normalize_version($input['app_version'] ?? null);
    record_app_version($db, $site, $appVersion);

    $stmt = $db->prepare('INSERT INTO events (site, event_name, event_data, page_path, visitor_hash, app_version, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $site,
        $eventName,
        $eventData,
        $pagePath,
        $hash,
        $appVersion,
        date('Y-m-d H:i:s'),
    ]);

    cleanup_events($db, $config['events_retention_days']);
}

/**
 * The visitor's IP address. Forwarded headers are only read when the request
 * comes from a trusted proxy (TRUSTED_PROXIES); anyone can send them, so
 * trusting them otherwise would let visitors pick their own address.
 * X-Forwarded-For is walked from the right, the end the proxies append to,
 * and the first address that is not a trusted proxy is the visitor.
 */
function client_ip(array $server, array $trustedProxies): string
{
    $ip = $server['REMOTE_ADDR'] ?? '';
    $trusted = function (string $addr) use ($trustedProxies): bool {
        foreach ($trustedProxies as $range) {
            if (ip_in_range($addr, $range)) return true;
        }
        return false;
    };
    if (!$trusted($ip)) return $ip;

    $hops = array_values(array_filter(array_map('trim', explode(',', $server['HTTP_X_FORWARDED_FOR'] ?? '')), 'strlen'));
    if (!$hops) {
        $realIp = trim($server['HTTP_X_REAL_IP'] ?? '');
        return filter_var($realIp, FILTER_VALIDATE_IP) !== false ? $realIp : $ip;
    }
    foreach (array_reverse($hops) as $hop) {
        if (filter_var($hop, FILTER_VALIDATE_IP) === false) break;
        $ip = $hop;
        if (!$trusted($hop)) break;
    }
    return $ip;
}

/**
 * The visitor id: a hash of IP and user agent with a salt that exists for one day.
 * Once the salt is deleted the hash can never be computed again, not even with
 * server access, so stored rows cannot be tied back to a visitor.
 */
/**
 * The site is part of the hash, so one person's visits to two sites of the same
 * install get different hashes and cannot be joined across sites.
 */
function visitor_hash(PDO $db, string $site, string $ip, string $ua): string
{
    return hash('sha256', $site . "\0" . $ip . $ua . daily_salt($db));
}

/**
 * Today's random salt. The first request of a new day creates it and deletes
 * every other day's salt. secure_delete overwrites the deleted salt on disk
 * instead of leaving it in free pages of the database file.
 */
function daily_salt(PDO $db): string
{
    $today = date('Y-m-d');
    $select = $db->prepare('SELECT salt FROM salts WHERE date = ?');
    $select->execute([$today]);
    $salt = $select->fetchColumn();
    if ($salt !== false) return $salt;

    $db->exec('PRAGMA secure_delete = ON');
    $db->prepare('INSERT OR IGNORE INTO salts (date, salt) VALUES (?, ?)')->execute([$today, bin2hex(random_bytes(32))]);
    $db->prepare('DELETE FROM salts WHERE date != ?')->execute([$today]);
    $select->execute([$today]);
    return $select->fetchColumn();
}

/**
 * Whether pageviews and events from $ip should be dropped for $site
 * (the site's own excluded_ips rows plus those for all sites, '*').
 */
function is_excluded_ip(PDO $db, string $site, string $ip): bool
{
    $stmt = $db->prepare("SELECT ip FROM excluded_ips WHERE site IN (?, '*')");
    $stmt->execute([$site]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $range) {
        if (ip_in_range($ip, $range)) return true;
    }
    return false;
}

/**
 * An address or CIDR range in canonical form (81.2.3.4, 81.2.3.0/24, 2001:db8::/48),
 * or null when it is not one. The same rule as exclude:add in the CLI, so a row
 * added in Settings and one added from the CLI are stored alike.
 */
function normalize_ip_range(string $input): ?string
{
    [$ip, $bits] = array_pad(explode('/', trim($input), 2), 2, null);
    $bin = @inet_pton($ip);
    if ($bin === false) return null;
    $ip = inet_ntop($bin);
    if ($bits === null) return $ip;
    if (!ctype_digit($bits) || (int) $bits > strlen($bin) * 8) return null;
    return $ip . '/' . (int) $bits;
}

/**
 * Match an IPv4 or IPv6 address against a single address or a CIDR range
 * (81.2.3.0/24, 2001:db8:1::/48). An IPv4-mapped IPv6 address (::ffff:1.2.3.4)
 * matches IPv4 ranges.
 */
function ip_in_range(string $ip, string $range): bool
{
    [$net, $bits] = array_pad(explode('/', $range, 2), 2, null);
    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton($net);
    if ($ipBin === false || $netBin === false) return false;
    if (strlen($ipBin) === 16 && strlen($netBin) === 4 && str_starts_with($ipBin, str_repeat("\0", 10) . "\xff\xff")) {
        $ipBin = substr($ipBin, 12);
    }
    if (strlen($ipBin) !== strlen($netBin)) return false;

    $bits = $bits === null ? strlen($ipBin) * 8 : (int) $bits;
    $bytes = intdiv($bits, 8);
    if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) return false;
    $rest = $bits % 8;
    if ($rest === 0) return true;
    $mask = chr((0xff << (8 - $rest)) & 0xff);
    return ($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask);
}

/**
 * Delete events older than $days (once per day). 0 keeps events forever.
 * The default of 400 days keeps a full year for year-over-year comparison.
 */
function cleanup_events(PDO $db, int $days): void
{
    if ($days <= 0) return;
    $marker = dirname($db->query("PRAGMA database_list")->fetch()['file'] ?? __DIR__) . '/.events_cleanup';
    if (file_exists($marker) && file_get_contents($marker) === date('Y-m-d')) return;

    $stmt = $db->prepare('DELETE FROM events WHERE created_at < ?');
    $stmt->execute([date('Y-m-d H:i:s', strtotime("-{$days} days"))]);
    file_put_contents($marker, date('Y-m-d'));
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
        $sites = tracked_sites($db);
        if (!empty($allowedSites)) {
            $sites = array_values(array_intersect($sites, $allowedSites));
        }
        return json_encode($sites);
    }

    // The groups and their sites, limited to the sites the user can read
    if (isset($_GET['groups'])) {
        $groups = [];
        foreach (site_groups($db) as $siteName => $group) {
            if (!empty($allowedSites) && !in_array($siteName, $allowedSites, true)) continue;
            $groups[$group][] = $siteName;
        }
        // An object even when empty; JSON_FORCE_OBJECT would turn the site lists into objects too
        return $groups ? json_encode($groups) : '{}';
    }

    // Date range: support both &days=7 and &from=2026-03-01&to=2026-03-15
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && $to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $since = $from;
        $until = date('Y-m-d', strtotime($to . ' +1 day'));
        $days = max(1, (int) ((strtotime($to) - strtotime($from)) / 86400) + 1);
    } else {
        $days = max(1, min(365, (int)($_GET['days'] ?? 7)));
        // Current period spans exactly $days dates ending today, so previous
        // (same length, immediately before) aligns with current bar-by-bar.
        $since = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $until = '';
    }
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
    $untilFilter = $until ? 'AND created_at < ?' : '';
    $untilParams = $until ? [$until] : [];
    // Combined date filter: [$since, $until?] for easy merging into queries
    $dateFilter = "created_at >= ? {$untilFilter}";
    $dateParams = array_merge([$since], $untilParams);

    // If user has multiple allowed sites but no specific filter, restrict to their sites
    if (!$site && !empty($allowedSites)) {
        $placeholders = implode(',', array_fill(0, count($allowedSites), '?'));
        $siteFilter = "AND site IN ({$placeholders})";
        $siteParams = $allowedSites;
    }

    // What only the rows can answer: the most crawled pages and the latest activity. Seconds on
    // an install with many bot visits, so the dashboard asks for it when Bots is open (botDetail)
    // and leaves it out of the page's own request (bots=summary)
    $readBotDetail = function (string $expand) use ($db, $config, $since, $dateFilter, $dateParams, $siteFilter, $siteParams): array {
        $pagesLimit = $expand === 'botPages' ? 1000 : 10;
        $stmt = $db->prepare("SELECT path, COUNT(*) as visits, COUNT(*) OVER () as total FROM bot_visits WHERE {$dateFilter} {$siteFilter} GROUP BY path ORDER BY visits DESC, path LIMIT {$pagesLimit}");
        $stmt->execute(array_merge($dateParams, $siteParams));
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pagesTotal = $pages ? (int) $pages[0]['total'] : 0;
        $pages = array_map(fn ($p) => ['path' => $p['path'], 'visits' => $p['visits']], $pages);

        $activityLimit = $expand === 'botActivity' ? 1000 : 10;
        $stmt = $db->prepare("SELECT bot_name as name, bot_category as category, site, path, created_at FROM bot_visits WHERE {$dateFilter} {$siteFilter} ORDER BY created_at DESC LIMIT {$activityLimit}");
        $stmt->execute(array_merge($dateParams, $siteParams));
        $activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (function_exists('idn_to_utf8')) {
            foreach ($activity as &$a) {
                $decoded = idn_to_utf8($a['site'], 0, INTL_IDNA_VARIANT_UTS46);
                if ($decoded !== false) $a['site'] = $decoded;
            }
            unset($a);
        }

        // The totals reach further back than the rows, so a period past them says how far the lists go
        $kept = $config['bot_retention_days'] ?? 90;
        return [
            'botPages' => $pages,
            'botPagesTotal' => $pagesTotal,
            'botActivity' => $activity,
            'botKeptDays' => $kept > 0 && $since < date('Y-m-d', strtotime("-{$kept} days")) ? $kept : null,
        ];
    };
    if (isset($_GET['botDetail'])) {
        return json_encode($readBotDetail($_GET['expand'] ?? ''), JSON_INVALID_UTF8_SUBSTITUTE);
    }

    // Search endpoint (early return)
    if (isset($_GET['search'])) {
        $search = mb_substr(trim($_GET['search']), 0, 100);
        if (mb_strlen($search) < 2) {
            return json_encode(['results' => [], 'query' => $search, 'days' => $days]);
        }
        $stmt = $db->prepare("SELECT path, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE {$dateFilter} {$siteFilter} AND path LIKE ? GROUP BY path ORDER BY views DESC LIMIT 20");
        $stmt->execute(array_merge($dateParams, $siteParams, ['%' . $search . '%']));
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
            $channelFilter = "AND visitor_hash IN (SELECT visitor_hash FROM pageviews WHERE {$dateFilter} {$siteFilter} GROUP BY visitor_hash HAVING {$cond})";
            $channelParams = array_merge($dateParams, $siteParams);
        }
    }

    // One day by hour (?hours=<date>): pageviews and visitors per hour for the card under the
    // chart, with the chart's site, path and channel filters, before the dashboard's queries so
    // a picked day costs one small query. A visitor seen in two hours counts in both, so the
    // hours' visitors can add up to more than the day's
    $hoursDay = $_GET['hours'] ?? '';
    if ($hoursDay !== '') {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $hoursDay, $ymd) || !checkdate((int) $ymd[2], (int) $ymd[3], (int) $ymd[1])) {
            return json_encode(['error' => 'Invalid date']);
        }
        $stmt = $db->prepare("SELECT CAST(strftime('%H', created_at) AS INTEGER) as hour, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE created_at >= ? AND created_at < ? {$siteFilter} {$pathFilter} {$channelFilter} GROUP BY hour");
        $stmt->execute(array_merge([$hoursDay, date('Y-m-d', strtotime($hoursDay . ' +1 day'))], $siteParams, $pathParams, $channelParams));
        $byHour = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'hour');
        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $hours[] = ['hour' => $h, 'views' => (int) ($byHour[$h]['views'] ?? 0), 'visitors' => (int) ($byHour[$h]['visitors'] ?? 0)];
        }
        return json_encode(['date' => $hoursDay, 'hours' => $hours, 'today' => date('Y-m-d'), 'hourNow' => (int) date('G')]);
    }

    // Previous period for trend comparison (same length, immediately before current period)
    $prevSince = date('Y-m-d', strtotime($since . " -{$days} days"));

    // Without a path or a channel filter, the pageview figures come from the daily summary
    // (summary_figures); with one, from the rows, as below
    $fig = null;
    if (!$path && !$channel && ($config['daily_summary'] ?? true)) {
        $scope = $site ? [$site] : (!empty($allowedSites) ? $allowedSites : tracked_sites($db));
        $limits = [
            'pages' => $expand === 'pages' ? 1000 : 10,
            'referrers' => $expand === 'referrers' ? 1000 : 10,
            'utm' => $expand === 'utm' ? 1000 : 10,
        ];
        if ($scope) $fig = summary_figures($db, $scope, $since, $until ?: date('Y-m-d', strtotime('+1 day')), $prevSince, $limits);
    }

    if ($fig) {
        $byDay = $fig['byDay'];
    } else {
        $stmt = $db->prepare("SELECT DATE(created_at) as date, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE {$dateFilter} {$siteFilter} {$pathFilter} {$channelFilter} GROUP BY date ORDER BY date");
        $stmt->execute(array_merge($dateParams, $siteParams, $pathParams, $channelParams));
        $byDay = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Pages table (skip when filtered to a single path)
    $pages = [];
    $pagesTotal = 0;
    if ($fig) {
        $pages = $fig['pages'];
        $pagesTotal = $fig['pagesTotal'];
    } elseif (!$path) {
        $limit = $expand === 'pages' ? 1000 : 10;
        // The number of pages beyond the limit rides along as a window over the groups, so
        // the rows are read once; the same for sources and campaigns below
        $stmt = $db->prepare("SELECT path, views, visitors, COUNT(*) OVER () as total FROM (SELECT path, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE {$dateFilter} {$siteFilter} {$channelFilter} GROUP BY path) ORDER BY views DESC, path LIMIT {$limit}");
        $stmt->execute(array_merge($dateParams, $siteParams, $channelParams));
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pagesTotal = (int) ($pages[0]['total'] ?? 0);
        foreach ($pages as &$pg) unset($pg['total']);
        unset($pg);
    }

    if ($fig) {
        $referrers = $fig['referrers'];
        $referrersTotal = $fig['referrersTotal'];
        $totals = $fig['totals'];
        $previousTotals = $fig['previousTotals'];
        $previousPageviews = $fig['previousPageviews'];
    } else {
        $refLimit = $expand === 'referrers' ? 1000 : 10;
        $stmt = $db->prepare("SELECT source, visitors, COUNT(*) OVER () as total FROM (SELECT referrer as source, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE {$dateFilter} {$siteFilter} {$pathFilter} {$channelFilter} AND referrer IS NOT NULL GROUP BY referrer) ORDER BY visitors DESC, source LIMIT {$refLimit}");
        $stmt->execute(array_merge($dateParams, $siteParams, $pathParams, $channelParams));
        $referrers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $referrersTotal = (int) ($referrers[0]['total'] ?? 0);
        foreach ($referrers as &$ref) unset($ref['total']);
        unset($ref);

        $stmt = $db->prepare("SELECT COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE {$dateFilter} {$siteFilter} {$pathFilter} {$channelFilter}");
        $stmt->execute(array_merge($dateParams, $siteParams, $pathParams, $channelParams));
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE created_at >= ? AND created_at < ? {$siteFilter} {$pathFilter} {$channelFilter}");
        $stmt->execute(array_merge([$prevSince, $since], $siteParams, $pathParams, $channelParams));
        $previousTotals = $stmt->fetch(PDO::FETCH_ASSOC);

        // Previous period daily breakdown (for chart comparison overlay)
        $stmt = $db->prepare("SELECT DATE(created_at) as date, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE created_at >= ? AND created_at < ? {$siteFilter} {$pathFilter} {$channelFilter} GROUP BY date ORDER BY date");
        $stmt->execute(array_merge([$prevSince, $since], $siteParams, $pathParams, $channelParams));
        $previousPageviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Event drill-down (?event=<name>): answered here with only what the panel
    // needs, so a click does not re-run every dashboard query.
    $eventDetail = [];
    $eventSeries = [];
    $previousEventSeries = [];
    $eventTotals = null;
    $previousEventTotals = null;
    $eventKeys = [];
    $eventGroup = [];
    $eventGroupKey = null;
    $eventGroupNew = 0;
    $eventName = $_GET['event'] ?? '';
    $eventUrl = $_GET['event_url'] ?? '';
    if ($eventName) {
        if ($eventUrl) {
            $stmt = $db->prepare("SELECT event_data, page_path, created_at FROM events WHERE event_name = ? AND json_extract(event_data, '\$.url') = ? AND {$dateFilter} {$siteFilter} ORDER BY created_at DESC LIMIT 50");
            $stmt->execute(array_merge([$eventName, $eventUrl], $dateParams, $siteParams));
        } else {
            $stmt = $db->prepare("SELECT event_data, page_path, created_at FROM events WHERE event_name = ? AND {$dateFilter} {$siteFilter} ORDER BY created_at DESC LIMIT 50");
            $stmt->execute(array_merge([$eventName], $dateParams, $siteParams));
        }
        $eventDetail = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($eventDetail as &$ed) {
            $ed['event_data'] = $ed['event_data'] ? json_decode($ed['event_data'], true) : null;
        }
        unset($ed);

        // Daily series for the event, zero-filled so the chart has one bar per day,
        // plus the previous period for the compare overlay.
        $stmt = $db->prepare("SELECT DATE(created_at) as date, COUNT(*) as count, COUNT(DISTINCT visitor_hash) as visitors FROM events WHERE event_name = ? AND {$dateFilter} {$siteFilter} GROUP BY date");
        $stmt->execute(array_merge([$eventName], $dateParams, $siteParams));
        $eventSeries = fill_days($stmt->fetchAll(PDO::FETCH_ASSOC), $since, $days);

        $stmt = $db->prepare("SELECT DATE(created_at) as date, COUNT(*) as count, COUNT(DISTINCT visitor_hash) as visitors FROM events WHERE event_name = ? AND created_at >= ? AND created_at < ? {$siteFilter} GROUP BY date");
        $stmt->execute(array_merge([$eventName, $prevSince, $since], $siteParams));
        $previousEventSeries = fill_days($stmt->fetchAll(PDO::FETCH_ASSOC), $prevSince, $days);

        $stmt = $db->prepare("SELECT COUNT(*) as count, COUNT(DISTINCT visitor_hash) as visitors FROM events WHERE event_name = ? AND {$dateFilter} {$siteFilter}");
        $stmt->execute(array_merge([$eventName], $dateParams, $siteParams));
        $eventTotals = array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC));

        $stmt = $db->prepare("SELECT COUNT(*) as count, COUNT(DISTINCT visitor_hash) as visitors FROM events WHERE event_name = ? AND created_at >= ? AND created_at < ? {$siteFilter}");
        $stmt->execute(array_merge([$eventName, $prevSince, $since], $siteParams));
        $previousEventTotals = array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC));

        // Data keys seen in the most recent rows, so the dashboard can offer "group by".
        // Nested objects and arrays are skipped: they cannot be grouped on.
        $stmt = $db->prepare("SELECT j.key as key, COUNT(*) as count FROM (SELECT event_data FROM events WHERE event_name = ? AND {$dateFilter} {$siteFilter} AND event_data IS NOT NULL ORDER BY created_at DESC LIMIT 500) e, json_each(e.event_data) j WHERE j.type NOT IN ('object', 'array') GROUP BY j.key ORDER BY count DESC, j.key");
        $stmt->execute(array_merge([$eventName], $dateParams, $siteParams));
        $eventKeys = array_map(fn ($k) => ['key' => $k['key'], 'count' => (int) $k['count']], $stmt->fetchAll(PDO::FETCH_ASSOC));

        // App version is a column, not a data key; offered as the pseudo-key "@version"
        $stmt = $db->prepare("SELECT COUNT(*) FROM events WHERE event_name = ? AND {$dateFilter} {$siteFilter} AND app_version IS NOT NULL");
        $stmt->execute(array_merge([$eventName], $dateParams, $siteParams));
        $versionCount = (int) $stmt->fetchColumn();
        if ($versionCount > 0) $eventKeys[] = ['key' => '@version', 'count' => $versionCount];

        // Group by one data key: value, count, unique visitors. The key is bound
        // as a quoted JSON path parameter, never interpolated into SQL.
        // ?group=key groups on that key, ?group= (empty) disables grouping, and no
        // ?group at all defaults to the most common key so the first open is useful.
        $groupKey = isset($_GET['group'])
            ? str_replace(['"', "\\"], '', mb_substr(trim($_GET['group']), 0, 50))
            : ($eventKeys[0]['key'] ?? '');
        if ($groupKey !== '') {
            $eventGroupKey = $groupKey;
            [$eventGroup, $eventGroupNew] = event_group($db, $eventName, $groupKey, $dateFilter, $dateParams, $siteFilter, $siteParams, $prevSince, $since, 200);
        }

        // CSV of every row in the period, one column per data key
        if (($_GET['format'] ?? '') === 'csv') {
            $stmt = $db->prepare("SELECT created_at, page_path, event_data FROM events WHERE event_name = ? AND {$dateFilter} {$siteFilter} ORDER BY created_at DESC LIMIT 10000");
            $stmt->execute(array_merge([$eventName], $dateParams, $siteParams));
            $keys = array_column($eventKeys, 'key');
            $out = fopen('php://temp', 'r+');
            fputcsv($out, array_map('csv_safe', array_merge(['created_at', 'page_path'], $keys)), ',', '"', '', "\n");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $data = $row['event_data'] ? (json_decode($row['event_data'], true) ?: []) : [];
                $line = [$row['created_at'], $row['page_path']];
                foreach ($keys as $k) {
                    $v = $data[$k] ?? '';
                    $line[] = is_bool($v) ? ($v ? 'true' : 'false') : (is_scalar($v) ? $v : json_encode($v));
                }
                fputcsv($out, array_map('csv_safe', $line), ',', '"', '', "\n");
            }
            rewind($out);
            $filename = preg_replace('/[^a-z0-9_.-]+/i', '-', ($site ?: 'all-sites') . '-' . $eventName) . '.csv';
            respond(stream_get_contents($out), 200, 'text/csv; charset=utf-8', ['Content-Disposition' => 'attachment; filename="' . $filename . '"']);
        }
    }
    // App versions first seen within the period, for release markers on charts
    $versionsUntil = $until ? 'AND first_seen < ?' : '';
    $stmt = $db->prepare("SELECT version, MIN(first_seen) as first_seen FROM app_versions WHERE first_seen >= ? {$versionsUntil} {$siteFilter} GROUP BY version ORDER BY first_seen, version LIMIT 50");
    $stmt->execute(array_merge([$since], $until ? [$until] : [], $siteParams));
    $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Annotations that overlap the period (a range may start before it)
    $annotationsUntil = $until ? 'AND date < ?' : '';
    $stmt = $db->prepare("SELECT id, site, date, end_date, label FROM annotations WHERE COALESCE(end_date, date) >= ? {$annotationsUntil} {$siteFilter} ORDER BY date, id");
    $stmt->execute(array_merge([$since], $until ? [$until] : [], $siteParams));
    $annotations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Funnel (?event=a&then=b): of the visitors who did A, how many did B at or
    // after their first A. visitor_hash rotates daily, so a funnel spans one day.
    $funnel = null;
    $eventNames = [];
    if ($eventName) {
        // Most used first, then most recent, so the picker stays useful past 200 names
        $stmt = $db->prepare("SELECT event_name FROM events WHERE {$dateFilter} {$siteFilter} AND event_name != ? GROUP BY event_name ORDER BY COUNT(*) DESC, MAX(created_at) DESC LIMIT 200");
        $stmt->execute(array_merge($dateParams, $siteParams, [$eventName]));
        $eventNames = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $then = mb_substr(trim($_GET['then'] ?? ''), 0, 100);
        if ($then !== '' && $then !== $eventName) {
            // B is aggregated once per visitor (latest occurrence) and joined, instead of a
            // correlated lookup per A visitor: linear instead of A x B. visitor_hash rotates
            // daily, so B "at or after" A only ever happens within the same period.
            $funnelSql = fn (string $range) => "SELECT DATE(a.first_at) as date, COUNT(*) as count,
                    SUM(COALESCE(b.last_at >= a.first_at, 0)) as visitors
                FROM (SELECT site, visitor_hash, MIN(created_at) as first_at FROM events WHERE event_name = ? AND {$range} {$siteFilter} GROUP BY site, visitor_hash) a
                LEFT JOIN (SELECT site, visitor_hash, MAX(created_at) as last_at FROM events WHERE event_name = ? AND {$range} {$siteFilter} GROUP BY site, visitor_hash) b
                    ON b.site = a.site AND b.visitor_hash = a.visitor_hash
                GROUP BY date";

            $stmt = $db->prepare($funnelSql($dateFilter));
            $stmt->execute(array_merge([$eventName], $dateParams, $siteParams, [$then], $dateParams, $siteParams));
            $series = fill_days($stmt->fetchAll(PDO::FETCH_ASSOC), $since, $days);

            $prevRange = [$prevSince, $since];
            $stmt = $db->prepare($funnelSql('created_at >= ? AND created_at < ?'));
            $stmt->execute(array_merge([$eventName], $prevRange, $siteParams, [$then], $prevRange, $siteParams));
            $prevSeries = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $started = array_sum(array_column($series, 'count'));
            $converted = array_sum(array_column($series, 'visitors'));
            $prevStarted = (int) array_sum(array_column($prevSeries, 'count'));
            $prevConverted = (int) array_sum(array_column($prevSeries, 'visitors'));
            $funnel = [
                'then' => $then,
                'started' => $started,
                'converted' => $converted,
                'rate' => $started > 0 ? round(100 * $converted / $started, 1) : null,
                'previousStarted' => $prevStarted,
                'previousConverted' => $prevConverted,
                'previousRate' => $prevStarted > 0 ? round(100 * $prevConverted / $prevStarted, 1) : null,
                'series' => array_map(fn ($d) => ['date' => $d['date'], 'started' => $d['count'], 'converted' => $d['visitors']], $series),
            ];
        }
    }

    if ($eventName) {
        return json_encode([
            'days' => $days,
            'site' => $site,
            'totals' => $totals,
            'pageviews' => $byDay,
            'previousPageviews' => $previousPageviews,
            'eventDetail' => $eventDetail,
            'eventSeries' => $eventSeries,
            'previousEventSeries' => $previousEventSeries,
            'eventTotals' => $eventTotals,
            'previousEventTotals' => $previousEventTotals,
            'eventKeys' => $eventKeys,
            'eventGroup' => $eventGroup,
            'eventGroupKey' => $eventGroupKey,
            'eventGroupNew' => $eventGroupNew,
            'eventNames' => $eventNames,
            'funnel' => $funnel,
            'versions' => $versions,
            'annotations' => $annotations,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    // Bounce rate and session length (skip when filtered to a single path — not meaningful)
    $bounceRate = 0;
    $previousBounceRate = null;
    $medianSession = 0;
    $previousMedianSession = null;
    if ($fig) {
        $bounceRate = $fig['bounceRate'];
        $previousBounceRate = $fig['previousBounceRate'];
        $medianSession = $fig['medianSession'];
        $previousMedianSession = $fig['previousMedianSession'];
    } elseif (!$path) {
        $stmt = $db->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN cnt = 1 THEN 1 ELSE 0 END) as bounced FROM (SELECT visitor_hash, COUNT(*) as cnt FROM pageviews WHERE {$dateFilter} {$siteFilter} {$channelFilter} GROUP BY visitor_hash)");
        $stmt->execute(array_merge($dateParams, $siteParams, $channelParams));
        $bounceData = $stmt->fetch(PDO::FETCH_ASSOC);
        $bounceRate = $bounceData['total'] > 0 ? round(100 * $bounceData['bounced'] / $bounceData['total'], 1) : 0;

        $stmt = $db->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN cnt = 1 THEN 1 ELSE 0 END) as bounced FROM (SELECT visitor_hash, COUNT(*) as cnt FROM pageviews WHERE created_at >= ? AND created_at < ? {$siteFilter} {$channelFilter} GROUP BY visitor_hash)");
        $stmt->execute(array_merge([$prevSince, $since], $siteParams, $channelParams));
        $prevBounce = $stmt->fetch(PDO::FETCH_ASSOC);
        $previousBounceRate = $prevBounce['total'] > 0 ? round(100 * $prevBounce['bounced'] / $prevBounce['total'], 1) : null;

        // Median session length in SQL: the row at position n/2 (0-based) of the sorted
        // durations, as a window over one pass, so a year of a busy site does not come
        // into PHP as hundreds of thousands of rows. Visits of one page have no length.
        // Whole seconds from strftime: julianday differences come out as 59.999...
        $medianSql = fn (string $where) => "SELECT duration FROM (SELECT duration, ROW_NUMBER() OVER (ORDER BY duration) AS rn, COUNT(*) OVER () AS n FROM (SELECT strftime('%s', MAX(created_at)) - strftime('%s', MIN(created_at)) as duration FROM pageviews WHERE {$where} GROUP BY visitor_hash HAVING COUNT(*) > 1)) WHERE rn = n / 2 + 1";
        $stmt = $db->prepare($medianSql("{$dateFilter} {$siteFilter} {$channelFilter}"));
        $stmt->execute(array_merge($dateParams, $siteParams, $channelParams));
        $medianSession = (int) $stmt->fetchColumn();

        $stmt = $db->prepare($medianSql("created_at >= ? AND created_at < ? {$siteFilter} {$channelFilter}"));
        $stmt->execute(array_merge([$prevSince, $since], $siteParams, $channelParams));
        $median = $stmt->fetchColumn();
        $previousMedianSession = $median !== false ? (int) $median : null;
    }

    // Entry/exit pages (skip when filtered to a single path)
    $entryPages = [];
    $exitPages = [];
    if (!$path) {
        $entryLimit = $expand === 'entryPages' ? 1000 : 10;
        $exitLimit = $expand === 'exitPages' ? 1000 : 10;
        // Each visitor's first and last page in one pass over the period's rows (the two
        // window orderings still sort twice, but the rows are read once). Bounces per entry
        // page use the same definition as the site bounce rate: one pageview per visitor_hash
        if ($fig) {
            $firstLast = $fig['firstLast'];
        } else {
            $stmt = $db->prepare("SELECT path, SUM(is_entry) as entries, SUM(is_entry AND cnt = 1) as bounces, SUM(is_exit) as exits FROM (SELECT path, ROW_NUMBER() OVER (PARTITION BY visitor_hash ORDER BY created_at, id) = 1 as is_entry, ROW_NUMBER() OVER (PARTITION BY visitor_hash ORDER BY created_at DESC, id DESC) = 1 as is_exit, COUNT(*) OVER (PARTITION BY visitor_hash) as cnt FROM pageviews WHERE {$dateFilter} {$siteFilter} {$channelFilter}) WHERE is_entry OR is_exit GROUP BY path");
            $stmt->execute(array_merge($dateParams, $siteParams, $channelParams));
            $firstLast = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $entries = array_values(array_filter($firstLast, fn($r) => $r['entries'] > 0));
        usort($entries, fn($a, $b) => ($b['entries'] <=> $a['entries']) ?: strcmp($a['path'], $b['path']));
        $entryPages = array_map(fn($e) => [
            'path' => $e['path'],
            'entries' => (int) $e['entries'],
            'bounces' => (int) $e['bounces'],
            'bounceRate' => round(100 * $e['bounces'] / $e['entries'], 1),
        ], array_slice($entries, 0, $entryLimit));

        $exits = array_values(array_filter($firstLast, fn($r) => $r['exits'] > 0));
        usort($exits, fn($a, $b) => ($b['exits'] <=> $a['exits']) ?: strcmp($a['path'], $b['path']));
        $exitPages = array_map(fn($e) => ['path' => $e['path'], 'exits' => (int) $e['exits']], array_slice($exits, 0, $exitLimit));
    }

    if ($fig) {
        $browsers = $fig['browsers'];
    } else {
        $stmt = $db->prepare("SELECT browser as name, COUNT(*) as cnt FROM pageviews WHERE {$dateFilter} {$siteFilter} {$pathFilter} {$channelFilter} GROUP BY browser ORDER BY cnt DESC, name");
        $stmt->execute(array_merge($dateParams, $siteParams, $pathParams, $channelParams));
        $browsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $total = array_sum(array_column($browsers, 'cnt'));
    $smartPct = fn($cnt, $sum) => $sum > 0 ? (($p = $cnt / $sum * 100) < 1 && $p > 0 ? round($p, 1) : round($p)) : 0;
    $browsers = array_map(fn($b) => ['name' => $b['name'], 'pct' => $smartPct($b['cnt'], $total)], $browsers);

    if ($fig) {
        $devices = $fig['devices'];
    } else {
        $stmt = $db->prepare("SELECT device as name, COUNT(*) as cnt FROM pageviews WHERE {$dateFilter} {$siteFilter} {$pathFilter} {$channelFilter} GROUP BY device ORDER BY cnt DESC, name");
        $stmt->execute(array_merge($dateParams, $siteParams, $pathParams, $channelParams));
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $devices = array_map(fn($d) => ['name' => $d['name'], 'pct' => $smartPct($d['cnt'], $total)], $devices);

    $stmt = $db->prepare("SELECT COUNT(DISTINCT visitor_hash) as live FROM pageviews WHERE {$dateFilter} {$siteFilter} {$pathFilter} {$channelFilter}");
    $stmt->execute(array_merge([date('Y-m-d H:i:s', strtotime('-5 minutes'))], $siteParams, $pathParams, $channelParams));
    $live = $stmt->fetch(PDO::FETCH_ASSOC)['live'] ?? 0;

    if ($fig) {
        $utm = $fig['utm'];
        $utmTotal = $fig['utmTotal'];
    } else {
        $utmLimit = $expand === 'utm' ? 1000 : 10;
        // Ties in the order the summary's key sorts in: the five values as a JSON array
        $stmt = $db->prepare("SELECT source, medium, campaign, term, content, visitors, COUNT(*) OVER () as total FROM (SELECT utm_source as source, utm_medium as medium, utm_campaign as campaign, utm_term as term, utm_content as content, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE {$dateFilter} {$siteFilter} {$pathFilter} {$channelFilter} AND utm_source IS NOT NULL GROUP BY utm_source, utm_medium, utm_campaign, utm_term, utm_content) ORDER BY visitors DESC, json_array(source, medium, campaign, term, content) LIMIT {$utmLimit}");
        $stmt->execute(array_merge($dateParams, $siteParams, $pathParams, $channelParams));
        $utm = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $utmTotal = (int) ($utm[0]['total'] ?? 0);
        foreach ($utm as &$u) unset($u['total']);
        unset($u);
    }

    if ($fig) {
        $languages = $fig['languages'];
    } else {
        $stmt = $db->prepare("SELECT language as name, COUNT(*) as cnt FROM pageviews WHERE {$dateFilter} {$siteFilter} {$pathFilter} {$channelFilter} AND language IS NOT NULL GROUP BY language ORDER BY cnt DESC, name");
        $stmt->execute(array_merge($dateParams, $siteParams, $pathParams, $channelParams));
        $languages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $langTotal = array_sum(array_column($languages, 'cnt'));
    $langNames = ['sv' => 'Swedish', 'en' => 'English', 'nb' => 'Norwegian', 'da' => 'Danish', 'fi' => 'Finnish',
                   'de' => 'German', 'fr' => 'French', 'es' => 'Spanish', 'nl' => 'Dutch', 'pl' => 'Polish',
                   'it' => 'Italian', 'pt' => 'Portuguese', 'ru' => 'Russian', 'zh' => 'Chinese', 'ja' => 'Japanese',
                   'ko' => 'Korean', 'ar' => 'Arabic', 'tr' => 'Turkish', 'th' => 'Thai', 'vi' => 'Vietnamese',
                   'ro' => 'Romanian', 'sq' => 'Albanian', 'lt' => 'Lithuanian', 'uk' => 'Ukrainian',
                   'hi' => 'Hindi', 'hr' => 'Croatian', 'fa' => 'Persian', 'bg' => 'Bulgarian',
                   'sk' => 'Slovak', 'so' => 'Somali', 'hu' => 'Hungarian', 'et' => 'Estonian',
                   'el' => 'Greek', 'bs' => 'Bosnian', 'he' => 'Hebrew', 'lv' => 'Latvian',
                   'sl' => 'Slovenian', 'cs' => 'Czech', 'ms' => 'Malay', 'id' => 'Indonesian',
                   'bn' => 'Bengali', 'ka' => 'Georgian', 'sr' => 'Serbian', 'mk' => 'Macedonian'];
    $languages = array_map(fn($l) => ['name' => $langNames[$l['name']] ?? strtoupper($l['name']), 'pct' => $smartPct($l['cnt'], $langTotal)], $languages);

    // Countries (from Accept-Language region)
    $countryNames = [
        'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark', 'FI' => 'Finland', 'IS' => 'Iceland',
        'US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada', 'AU' => 'Australia', 'NZ' => 'New Zealand',
        'DE' => 'Germany', 'FR' => 'France', 'ES' => 'Spain', 'IT' => 'Italy', 'PT' => 'Portugal',
        'NL' => 'Netherlands', 'BE' => 'Belgium', 'AT' => 'Austria', 'CH' => 'Switzerland',
        'PL' => 'Poland', 'CZ' => 'Czech Republic', 'SK' => 'Slovakia', 'HU' => 'Hungary',
        'RO' => 'Romania', 'BG' => 'Bulgaria', 'HR' => 'Croatia', 'SI' => 'Slovenia',
        'RS' => 'Serbia', 'UA' => 'Ukraine', 'RU' => 'Russia', 'BY' => 'Belarus',
        'LT' => 'Lithuania', 'LV' => 'Latvia', 'EE' => 'Estonia',
        'IE' => 'Ireland', 'LU' => 'Luxembourg', 'MT' => 'Malta', 'CY' => 'Cyprus',
        'GR' => 'Greece', 'TR' => 'Turkey', 'IL' => 'Israel', 'SA' => 'Saudi Arabia', 'AE' => 'UAE',
        'IN' => 'India', 'CN' => 'China', 'JP' => 'Japan', 'KR' => 'South Korea',
        'TH' => 'Thailand', 'VN' => 'Vietnam', 'ID' => 'Indonesia', 'MY' => 'Malaysia', 'SG' => 'Singapore', 'PH' => 'Philippines',
        'BR' => 'Brazil', 'MX' => 'Mexico', 'AR' => 'Argentina', 'CO' => 'Colombia', 'CL' => 'Chile',
        'ZA' => 'South Africa', 'NG' => 'Nigeria', 'EG' => 'Egypt', 'KE' => 'Kenya',
        'AL' => 'Albania', 'GE' => 'Georgia', 'IR' => 'Iran', 'BD' => 'Bangladesh',
    ];
    if ($fig) {
        $countries = $fig['countries'];
    } else {
        $stmt = $db->prepare("SELECT country as code, COUNT(DISTINCT visitor_hash) as visitors FROM pageviews WHERE {$dateFilter} {$siteFilter} {$pathFilter} {$channelFilter} AND country IS NOT NULL GROUP BY country ORDER BY visitors DESC, code");
        $stmt->execute(array_merge($dateParams, $siteParams, $pathParams, $channelParams));
        $countries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $countries = array_map(fn($c) => [
        'code' => $c['code'],
        'name' => $countryNames[$c['code']] ?? $c['code'],
        'visitors' => (int) $c['visitors'],
    ], $countries);

    // Traffic channels: classify each visitor's pageviews of a day (channel_sql)
    if ($fig) {
        $channels = $fig['channels'];
    } else {
        $stmt = $db->prepare("
            SELECT channel, COUNT(*) as visitors, SUM(pv) as views,
                   ROUND(AVG(pv), 1) as pages_per_visitor
            FROM (
                SELECT visitor_hash, COUNT(*) as pv, " . channel_sql() . " as channel
                FROM pageviews
                WHERE {$dateFilter} {$siteFilter} {$pathFilter}
                GROUP BY visitor_hash
            )
            GROUP BY channel
            ORDER BY visitors DESC, channel
        ");
        $stmt->execute(array_merge($dateParams, $siteParams, $pathParams));
        $channels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Bot data: the totals from the counts per day, the lists from the rows unless left out
    $botsLimit = $expand === 'bots' ? 1000 : 10;
    $botDates = 'date >= ?' . ($until ? ' AND date < ?' : '');
    $stmt = $db->prepare("SELECT name, category, SUM(visits) as visits FROM bot_days WHERE {$botDates} {$siteFilter} GROUP BY name, category ORDER BY visits DESC, name LIMIT {$botsLimit}");
    $stmt->execute(array_merge($dateParams, $siteParams));
    $bots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $db->prepare("SELECT COUNT(*) FROM (SELECT 1 FROM bot_days WHERE {$botDates} {$siteFilter} GROUP BY name, category)");
    $stmt->execute(array_merge($dateParams, $siteParams));
    $botsTotal = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT category, SUM(visits) as visits FROM bot_days WHERE {$botDates} {$siteFilter} GROUP BY category ORDER BY visits DESC, category");
    $stmt->execute(array_merge($dateParams, $siteParams));
    $botCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $botDetail = ($_GET['bots'] ?? '') === 'summary' ? [] : $readBotDetail($expand);

    // Broken links (last 30 days, regardless of $days filter). PHP's clock, not SQLite's
    // date('now'), which is UTC while stored times are in the configured time zone
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
                SELECT site, path, status, hits, referrers,
                    first_seen, last_seen,
                    ROW_NUMBER() OVER (PARTITION BY status ORDER BY hits DESC) as rn
                FROM broken_links
                WHERE last_seen >= ? {$blSiteFilter}
            ) WHERE rn <= 25");
        $stmt->execute(array_merge([date('Y-m-d', strtotime('-30 days'))], $blSiteParams));
        $brokenLinks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($brokenLinks as &$bl) {
            $bl['hits'] = (int) $bl['hits'];
            $bl['status'] = (int) $bl['status'];
            $bl['referrers'] = $bl['referrers']
                ? array_values(array_filter(explode(',', $bl['referrers'])))
                : [];
            if (function_exists('idn_to_utf8')) {
                $decoded = idn_to_utf8($bl['site'], 0, INTL_IDNA_VARIANT_UTS46);
                if ($decoded !== false) $bl['site'] = $decoded;
            }
        }
        unset($bl);
    }

    // Custom events
    $eventsLimit = $expand === 'events' ? 1000 : 50;
    $eventsPathFilter = $path ? 'AND page_path = ?' : '';
    $stmt = $db->prepare("SELECT event_name, COUNT(*) as count, COUNT(DISTINCT visitor_hash) as visitors FROM events WHERE {$dateFilter} {$siteFilter} {$eventsPathFilter} GROUP BY event_name ORDER BY count DESC LIMIT {$eventsLimit}");
    $stmt->execute(array_merge($dateParams, $siteParams, $pathParams));
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT COUNT(DISTINCT event_name) FROM events WHERE {$dateFilter} {$siteFilter}");
    $stmt->execute(array_merge($dateParams, $siteParams));
    $eventsTotal = (int) $stmt->fetchColumn();

    // Outbound link clicks
    $outboundLimit = $expand === 'outbound' ? 1000 : 10;
    $stmt = $db->prepare("SELECT json_extract(event_data, '\$.url') as url, COUNT(*) as clicks, COUNT(DISTINCT visitor_hash) as visitors FROM events WHERE event_name = 'outbound_click' AND {$dateFilter} {$siteFilter} AND event_data IS NOT NULL GROUP BY url ORDER BY clicks DESC LIMIT {$outboundLimit}");
    $stmt->execute(array_merge($dateParams, $siteParams));
    $outbound = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT COUNT(DISTINCT json_extract(event_data, '\$.url')) FROM events WHERE event_name = 'outbound_click' AND {$dateFilter} {$siteFilter} AND event_data IS NOT NULL");
    $stmt->execute(array_merge($dateParams, $siteParams));
    $outboundTotal = (int) $stmt->fetchColumn();

    // Site overview (only when viewing all sites)
    $siteOverview = [];
    if (!$site && !$path && !$channel) {
        // The join has two site columns, so a user's site restriction names p.site
        if ($fig) {
            $currentSites = [];
            $prevMap = [];
            $firstSeen = $db->prepare('SELECT MIN(created_at) FROM pageviews WHERE site = ?');
            foreach (summary_sites($db, $scope, $since, $until ?: date('Y-m-d', strtotime('+1 day')), $prevSince, $fig['last']) as $name => $r) {
                if ($r['prev']) $prevMap[$name] = $r['prev'];
                if (!$r['views']) continue;
                $firstSeen->execute([$name]);
                $currentSites[] = ['site' => $name, 'views' => $r['views'], 'visitors' => $r['visitors'], 'first_seen' => $firstSeen->fetchColumn()];
            }
            usort($currentSites, fn ($a, $b) => ($b['views'] <=> $a['views']) ?: strcmp($a['site'], $b['site']));
        } else {
            $joinSiteFilter = preg_replace('/^AND site\b/', 'AND p.site', $siteFilter);
            $stmt = $db->prepare("SELECT p.site, COUNT(*) as views, COUNT(DISTINCT p.visitor_hash) as visitors, f.first_seen
                FROM pageviews p
                LEFT JOIN (SELECT site, MIN(created_at) as first_seen FROM pageviews GROUP BY site) f ON f.site = p.site
                WHERE p.{$dateFilter} {$joinSiteFilter}
                GROUP BY p.site ORDER BY views DESC, p.site");
            $stmt->execute(array_merge($dateParams, $siteParams));
            $currentSites = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $db->prepare("SELECT site, COUNT(*) as views
                FROM pageviews WHERE created_at >= ? AND created_at < ? {$siteFilter}
                GROUP BY site");
            $stmt->execute(array_merge([$prevSince, $since], $siteParams));
            $prevMap = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $prevMap[$row['site']] = (int) $row['views'];
            }
        }

        $stmt = $db->prepare("SELECT site, COUNT(DISTINCT visitor_hash) as live
            FROM pageviews WHERE created_at >= ? {$siteFilter}
            GROUP BY site");
        $stmt->execute(array_merge([date('Y-m-d H:i:s', strtotime('-5 minutes'))], $siteParams));
        $liveMap = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $liveMap[$row['site']] = (int) $row['live'];
        }

        $groupMap = site_groups($db);
        foreach ($currentSites as $s) {
            $prev = $prevMap[$s['site']] ?? 0;
            $trend = $prev > 0 ? (int) round((((int)$s['views'] - $prev) / $prev) * 100) : null;
            $siteOverview[] = [
                'site' => $s['site'],
                'group' => $groupMap[$s['site']] ?? null,
                'views' => (int) $s['views'],
                'prev_views' => $prev,
                'visitors' => (int) $s['visitors'],
                'live' => $liveMap[$s['site']] ?? 0,
                'trend' => $trend,
                'first_seen' => $s['first_seen'] ? substr($s['first_seen'], 0, 10) : null,
            ];
        }
    }

    // Summary insights (skip when filtered to path/channel)
    $summary = null;
    if (!$path && !$channel) {
        if ($fig) {
            $bestDay = $fig['bestDay'];
            $peakHour = $fig['peakHour'];
        } else {
            $stmt = $db->prepare("SELECT CASE CAST(strftime('%w', created_at) AS INTEGER)
                WHEN 0 THEN 'Sunday' WHEN 1 THEN 'Monday' WHEN 2 THEN 'Tuesday'
                WHEN 3 THEN 'Wednesday' WHEN 4 THEN 'Thursday' WHEN 5 THEN 'Friday'
                WHEN 6 THEN 'Saturday' END as day, COUNT(*) as views
                FROM pageviews WHERE {$dateFilter} {$siteFilter}
                GROUP BY strftime('%w', created_at) ORDER BY views DESC, strftime('%w', created_at) LIMIT 1");
            $stmt->execute(array_merge($dateParams, $siteParams));
            $bestDay = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $db->prepare("SELECT CAST(strftime('%H', created_at) AS INTEGER) as hour, COUNT(*) as views
                FROM pageviews WHERE {$dateFilter} {$siteFilter}
                GROUP BY hour ORDER BY views DESC, hour LIMIT 1");
            $stmt->execute(array_merge($dateParams, $siteParams));
            $peakHour = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // The top page besides the start page and the top source come from the tables above,
        // which hold the same period with no filter here: the first page that is not '/' is
        // at rank one or two of all pages, and the first source is the top one
        $topPage = null;
        foreach ($pages as $pg) {
            if ($pg['path'] !== '/') {
                $topPage = $pg;
                break;
            }
        }
        $topSource = $referrers[0] ?? null;

        if ($bestDay) {
            $summary = [
                'bestDay' => $bestDay['day'],
                'bestDayViews' => (int) $bestDay['views'],
                'peakHour' => $peakHour ? sprintf('%d:00–%d:00', $peakHour['hour'], $peakHour['hour'] + 1) : null,
                // The hour (0-23) the peak starts, for the dashboard to format as TIME_FORMAT says
                'peakHourStart' => $peakHour ? (int) $peakHour['hour'] : null,
                'peakHourViews' => $peakHour ? (int) $peakHour['views'] : null,
                'topPage' => $topPage ? $topPage['path'] : null,
                'topPageViews' => $topPage ? (int) $topPage['views'] : null,
                'topSource' => $topSource ? $topSource['source'] : null,
                'topSourceVisitors' => $topSource ? (int) $topSource['visitors'] : null,
            ];
        }
    }

    // Goals and saved cards belong to a site. On All sites they show only when that view is
    // one site anyway: an install that tracks a single site (a user limited to one site has
    // $site set above). Across several sites a goal's rate would be counted against every
    // site's visitors, and the cards would pile up far from the site they explain.
    $cardSite = $site;
    if (!$cardSite && empty($allowedSites)) {
        // Two index seeks: the first site, and whether there is a second
        [$first, $second] = $db->query('SELECT (SELECT MIN(site) FROM pageviews), (SELECT MIN(site) FROM pageviews WHERE site > (SELECT MIN(site) FROM pageviews))')->fetch(PDO::FETCH_NUM);
        if ($first !== null && $second === null) $cardSite = $first;
    }

    // Goals with conversion rates
    $goals = [];
    $goalsTable = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='goals'")->fetch();
    if ($goalsTable && $cardSite && !$path && !$channel) {
        $stmt = $db->prepare("SELECT path, label, type, target, target_period FROM goals WHERE site = ? ORDER BY created_at");
        $stmt->execute([$cardSite]);
        $goalRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalVisitors = (int) ($totals['visitors'] ?? 0);
        foreach ($goalRows as $g) {
            // A goal is a page path or an event name; both count distinct visitors who reached it,
            // per day over this and the previous period. Visitor hashes change every day, so no
            // visitor is counted on two days and a period's visitors are the sum of its days.
            if ($fig && $g['type'] !== 'event') {
                $perDay = summary_path_days($db, $scope, $g['path'], $prevSince, $until ?: date('Y-m-d', strtotime('+1 day')), $fig['open'], $fig['last']);
            } else {
                [$table, $column] = $g['type'] === 'event' ? ['events', 'event_name'] : ['pageviews', 'path'];
                $stmt = $db->prepare("SELECT DATE(created_at) as date, COUNT(DISTINCT visitor_hash) as visitors FROM {$table} WHERE {$column} = ? AND created_at >= ? {$untilFilter} {$siteFilter} GROUP BY date");
                $stmt->execute(array_merge([$g['path'], $prevSince], $untilParams, $siteParams));
                $perDay = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'visitors', 'date'));
            }
            $series = [];
            for ($i = 0; $i < $days; $i++) {
                $series[] = $perDay[date('Y-m-d', strtotime("+{$i} days", strtotime($since)))] ?? 0;
            }
            $conversions = array_sum($series);
            $rate = $totalVisitors > 0 ? round(100 * $conversions / $totalVisitors, 1) : 0;
            $goals[] = [
                'path' => $g['path'],
                'label' => $g['label'] ?: $g['path'],
                'type' => $g['type'] ?: 'page',
                'conversions' => $conversions,
                'rate' => $rate,
                'previousConversions' => array_sum(array_filter($perDay, fn ($date) => $date < $since, ARRAY_FILTER_USE_KEY)),
                'series' => $series,
                'target' => $g['target'] !== null ? (int) $g['target'] : null,
                'targetPeriod' => $g['target_period'],
            ];
        }
    }

    // Saved breakdowns of the site on screen (see $cardSite above)
    $breakdowns = [];
    if ($cardSite && !$path && !$channel) {
        // In the order set for the site; cards never moved keep the order they were saved in
        $stmt = $db->prepare("SELECT id, site, label, event_name, group_key FROM breakdowns WHERE site = ? ORDER BY position, created_at, id");
        $stmt->execute([$cardSite]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $bd) {
            [$rows, $new] = event_group($db, $bd['event_name'], $bd['group_key'], $dateFilter, $dateParams, 'AND site = ?', [$bd['site']], $prevSince, $since, 200);
            $perDay = event_group_days($db, $bd['event_name'], $bd['group_key'], $prevSince, $until, $bd['site']);
            $series = [];
            for ($i = 0; $i < $days; $i++) {
                $series[] = $perDay[date('Y-m-d', strtotime("+{$i} days", strtotime($since)))] ?? 0;
            }
            $breakdowns[] = [
                'id' => (int) $bd['id'],
                'site' => $bd['site'],
                'label' => $bd['label'] ?: $bd['event_name'],
                'event' => $bd['event_name'],
                'group' => $bd['group_key'],
                // The card shows five rows and expands in place to these
                'rows' => array_slice($rows, 0, 50),
                'total' => count($rows),
                'new' => $new,
                'count' => array_sum($series),
                'previousCount' => array_sum(array_filter($perDay, fn ($date) => $date < $since, ARRAY_FILTER_USE_KEY)),
                'series' => $series,
            ];
        }
    }

    return json_encode([
        'site'      => $site ?: 'All sites',
        'path'      => $path ?: null,
        'channel'   => $channel ?: null,
        'days'      => $days,
        'from'      => $since,
        'to'        => $until ? date('Y-m-d', strtotime($until . ' -1 day')) : null,
        // Stored times are in this zone and carry no offset; "x min ago" counts from this now
        'now'       => date('Y-m-d H:i:s'),
        'timezone'  => date_default_timezone_get(),
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
        'previousPageviews' => $previousPageviews,
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
        'countries' => $countries,
        'bots'      => $bots,
        'botsTotal' => $botsTotal,
        'botCategories' => $botCategories,
        ...$botDetail,
        'summary'   => $summary,
        'goals'     => $goals,
        'breakdowns' => $breakdowns,
        'versions'  => $versions,
        'annotations' => $annotations,
        'siteOverview' => $siteOverview ?? [],
        'brokenLinks' => $brokenLinks,
        'events'    => $events,
        'eventDetail' => $eventDetail,
        'eventSeries' => $eventSeries,
        'previousEventSeries' => $previousEventSeries,
        'eventTotals' => $eventTotals,
        'previousEventTotals' => $previousEventTotals,
        'eventKeys' => $eventKeys,
        'eventGroup' => $eventGroup,
        'eventGroupKey' => $eventGroupKey,
        'eventsTotal' => $eventsTotal,
        'outbound'  => $outbound,
        'outboundTotal' => $outboundTotal,
    ], JSON_INVALID_UTF8_SUBSTITUTE);
}

// =====================================================================
// HELPERS
// =====================================================================

/**
 * Expand sparse per-day rows into one entry per day over a period, with
 * zero counts for missing days. Rows carry a 'date' (Y-m-d) plus count columns.
 */
/**
 * Clean a client-supplied app version: max 50 chars of letters, digits and . - + _
 * Returns null when nothing usable is left.
 */
function normalize_version(mixed $v): ?string
{
    if (!is_string($v) && !is_int($v) && !is_float($v)) return null;
    $clean = preg_replace('/[^A-Za-z0-9.+_-]/', '', mb_substr((string) $v, 0, 50));
    return $clean === '' ? null : $clean;
}

function record_app_version(PDO $db, string $site, ?string $version): void
{
    if ($version === null) return;
    $db->prepare('INSERT OR IGNORE INTO app_versions (site, version, first_seen) VALUES (?, ?, ?)')
        ->execute([$site, $version, date('Y-m-d')]);
}

/**
 * Group one event on a data key (or "@version" for the app_version column) and
 * flag values absent from the previous period. Returns [rows, newCount].
 * The key is bound as a quoted JSON path parameter, never interpolated into SQL.
 */
function event_group(PDO $db, string $eventName, string $groupKey, string $dateFilter, array $dateParams, string $siteFilter, array $siteParams, string $prevSince, string $since, int $limit): array
{
    $isVersion = $groupKey === '@version';
    $valueExpr = $isVersion ? 'app_version' : 'json_extract(event_data, ?)';
    $valueParams = $isVersion ? [] : ['$."' . $groupKey . '"'];

    $stmt = $db->prepare("SELECT {$valueExpr} as value, COUNT(*) as count, COUNT(DISTINCT visitor_hash) as visitors FROM events WHERE event_name = ? AND {$dateFilter} {$siteFilter} AND {$valueExpr} IS NOT NULL GROUP BY value ORDER BY count DESC, value LIMIT {$limit}");
    $stmt->execute(array_merge($valueParams, [$eventName], $dateParams, $siteParams, $valueParams));
    $rows = array_map(fn ($g) => ['value' => $g['value'], 'count' => (int) $g['count'], 'visitors' => (int) $g['visitors']], $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $db->prepare("SELECT DISTINCT {$valueExpr} FROM events WHERE event_name = ? AND created_at >= ? AND created_at < ? {$siteFilter} AND {$valueExpr} IS NOT NULL");
    $stmt->execute(array_merge($valueParams, [$eventName, $prevSince, $since], $siteParams, $valueParams));
    $seenBefore = array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);

    $new = 0;
    foreach ($rows as &$row) {
        $row['new'] = !isset($seenBefore[(string) $row['value']]);
        if ($row['new']) $new++;
    }
    unset($row);
    return [$rows, $new];
}

/**
 * Daily counts of one event that has a value for the group key, from $prevSince up to
 * $until (or now), as date => count: a saved card's total, its change against the
 * previous period and its sparkline, from one query.
 */
function event_group_days(PDO $db, string $eventName, string $groupKey, string $prevSince, string $until, string $site): array
{
    $isVersion = $groupKey === '@version';
    $valueExpr = $isVersion ? 'app_version' : 'json_extract(event_data, ?)';
    $valueParams = $isVersion ? [] : ['$."' . $groupKey . '"'];
    $untilFilter = $until ? 'AND created_at < ?' : '';

    $stmt = $db->prepare("SELECT DATE(created_at) as date, COUNT(*) as count FROM events WHERE event_name = ? AND created_at >= ? {$untilFilter} AND site = ? AND {$valueExpr} IS NOT NULL GROUP BY date");
    $stmt->execute(array_merge([$eventName, $prevSince], $until ? [$until] : [], [$site], $valueParams));
    return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'count', 'date'));
}

function fill_days(array $rows, string $start, int $days): array
{
    $byDate = [];
    foreach ($rows as $row) {
        $byDate[$row['date']] = $row;
    }
    $out = [];
    $t = strtotime($start);
    for ($i = 0; $i < $days; $i++) {
        $date = date('Y-m-d', strtotime("+{$i} days", $t));
        $row = $byDate[$date] ?? [];
        $out[] = [
            'date' => $date,
            'count' => (int) ($row['count'] ?? 0),
            'visitors' => (int) ($row['visitors'] ?? 0),
        ];
    }
    return $out;
}

/**
 * Insert markup right after the page's own </title>. Only the first one: the dashboard's
 * scripts can contain "</title>" in strings (SVG tooltips), and markup injected there breaks them.
 */
/**
 * The branding and, on a shared link, the share, as a JSON data block for theme.js and
 * dashboard.js to read. Data, not script: a strict Content Security Policy does not govern
 * a script element of type application/json, so nothing has to be allowed for it. < > & are
 * escaped, so a value can never close the block.
 */
function config_block(array $config, ?array $share = null): string
{
    $data = [
        'branding' => [
            'name' => $config['app_name'],
            'tagline' => $config['app_tagline'],
            'accent' => $config['app_accent'],
            'accentText' => accent_text_colours($config['app_accent']),
            'version' => PULS_VERSION,
            'credit' => $config['footer_credit'],
            'timeFormat' => $config['time_format'],
        ],
        'share' => $share,
    ];
    return '<script type="application/json" id="puls-config">' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
}

function inject_after_title(string $html, string $markup): string
{
    $at = strpos($html, '</title>');
    return $at === false ? $html : substr_replace($html, $markup, $at + strlen('</title>'), 0);
}

function security_headers(): array
{
    return [
        'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; manifest-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'",
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
            country TEXT,
            app_version TEXT,
            created_at TEXT NOT NULL
        )');
        // Covers the dashboard's visitor counts, bounces and sessions (filter by site and period,
        // count or group by visitor) without reading the table rows; see migration v26
        $db->exec('CREATE INDEX idx_site_date_visitor ON pageviews (site, created_at, visitor_hash)');
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
            referrers TEXT,
            hits INTEGER DEFAULT 1,
            first_seen TEXT NOT NULL,
            last_seen TEXT NOT NULL
        )');
        $db->exec('CREATE UNIQUE INDEX idx_broken_unique ON broken_links (site, path, status)');
        $db->exec('CREATE INDEX idx_broken_site_status ON broken_links (site, status, hits DESC)');
        $db->exec('CREATE TABLE share_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL UNIQUE,
            site TEXT NOT NULL,
            label TEXT,
            expires_at TEXT,
            created_at TEXT NOT NULL
        )');
        $db->exec('CREATE UNIQUE INDEX idx_share_token ON share_tokens (token)');
        $db->exec('CREATE TABLE events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site TEXT NOT NULL,
            event_name TEXT NOT NULL,
            event_data TEXT,
            page_path TEXT,
            visitor_hash TEXT NOT NULL,
            app_version TEXT,
            created_at TEXT NOT NULL
        )');
        $db->exec('CREATE INDEX idx_events_site_date ON events (site, created_at)');
        $db->exec('CREATE INDEX idx_events_name ON events (event_name, site, created_at)');
        $db->exec('CREATE TABLE goals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site TEXT NOT NULL,
            path TEXT NOT NULL,
            label TEXT,
            type TEXT NOT NULL DEFAULT \'page\',
            created_at TEXT NOT NULL
        )');
        $db->exec('CREATE UNIQUE INDEX idx_goals_unique ON goals (site, path)');
    }

    // Always run: a fresh schema gets the tables added by later migrations and
    // the current user_version, an existing one is brought up to date.
    run_migrations($db);

    return $db;
}

function handle_log(array $config): void
{
    // A crawler reads a page with GET or HEAD. Nginx's mirror also copies, with its method, what
    // a page's script posts when a crawler renders the page, and that is not a page being read
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) return;

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if (is_ignored_bot($ua, $config['ignored_bots'] ?? [])) return;

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

    // Lazy retention, once per day
    cleanup_bot_visits($db, $config['bot_retention_days'] ?? 90);
}

function handle_pixel(array $config): void
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $bot = detect_bot($ua);
    if (!$bot) return;
    if (is_ignored_bot($ua, $config['ignored_bots'] ?? [])) return;

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
    cleanup_bot_visits($db, $config['bot_retention_days'] ?? 90);
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

    // Decode IDN/punycode domains (xn--bcher-kva.example → bücher.example)
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
    // Anyone can post a site name: drop markup and control characters, which no real name has
    return preg_replace('/[\x00-\x1f\x7f<>"\'`\\\\]/', '', $site);
}

/**
 * A CSV cell that a spreadsheet shows as text: a value from visitors that starts with
 * = + - @ or a tab or CR gets a leading apostrophe, so it never runs as a formula.
 * Numbers, negative ones included, are left alone.
 */
function csv_safe(mixed $value): mixed
{
    if (!is_string($value) || $value === '' || is_numeric($value)) return $value;
    return str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
}

/** WCAG contrast ratio between two colours given as #rgb or #rrggbb. */
function contrast_ratio(string $a, string $b): float
{
    $luminance = function (string $hex): float {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        $channel = function (int $i) use ($hex): float {
            $v = hexdec(substr($hex, $i, 2)) / 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $channel(0) + 0.7152 * $channel(2) + 0.0722 * $channel(4);
    };
    [$l1, $l2] = [$luminance($a), $luminance($b)];
    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}

/**
 * Text colour for links and buttons, per theme, for a white-label accent. The accent
 * itself colours the mark and the bars; it is used for text only where it reaches 4.5:1
 * against the surface, so a pale brand colour never makes links unreadable.
 */
function accent_text_colours(string $accent): array
{
    $defaults = ['light' => '#be123c', 'dark' => '#fb7185'];
    $accent = strtolower($accent);
    if ($accent === '#f16272' || !preg_match('/^#([0-9a-f]{3}){1,2}$/', $accent)) return $defaults;
    return [
        'light' => contrast_ratio($accent, '#ffffff') >= 4.5 ? $accent : $defaults['light'],
        'dark' => contrast_ratio($accent, '#1e293b') >= 4.5 ? $accent : $defaults['dark'],
    ];
}

function normalize_path(string $path, array $extraStrip = []): string
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
        foreach (array_merge($strip, $extraStrip) as $key) {
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

/**
 * Meta's automatic UTM tagging sets utm_source to fb, ig, msg or an. Meta also
 * appends brid (one value per click) to those clicks. It is stripped only for
 * this traffic: elsewhere the name can be a real query parameter. Meta's
 * media_type (video/image) is kept on purpose: it is the only record of the ad
 * format, and post-click behaviour differs by format.
 */
function is_meta_source(?string $source): bool
{
    return in_array(strtolower((string) $source), ['fb', 'ig', 'msg', 'an'], true);
}

function meta_ad_params(): array
{
    return ['brid'];
}

/** Every site that is in a group, as site => group name, groups in name order. */
function site_groups(PDO $db): array
{
    $map = [];
    foreach ($db->query('SELECT site, group_name FROM site_groups ORDER BY group_name, site') as $row) {
        $map[$row['site']] = $row['group_name'];
    }
    return $map;
}

function run_migrations(PDO $db): void
{
    $currentVersion = 31; // Bump this when adding new migrations

    // Use SQLite's built-in PRAGMA user_version (per-database, no external file)
    $version = (int) $db->query('PRAGMA user_version')->fetchColumn();

    // Backward compat: if user_version is 0 but legacy .schema_version file exists
    // in the same directory, seed from it (one-time migration to per-DB versioning).
    $dbFile = $db->query("PRAGMA database_list")->fetch()['file'] ?? '';
    $legacyMarker = dirname($dbFile ?: __DIR__) . '/.schema_version';
    if ($version === 0 && file_exists($legacyMarker)) {
        $version = (int) file_get_contents($legacyMarker);
        $db->exec('PRAGMA user_version = ' . $version);
    }

    if ($version >= $currentVersion) return;

    // One request migrates while the others wait for its write lock (PDO's busy
    // timeout) and then find the schema current. Taking the lock up front matters:
    // a transaction that reads first and writes later fails at once with "database
    // is locked" when another request holds the lock, without waiting.
    $db->exec('BEGIN IMMEDIATE');
    try {
        $version = (int) $db->query('PRAGMA user_version')->fetchColumn();
        if ($version < $currentVersion) migrate_schema($db, $version, $currentVersion);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

/**
 * Bring the schema from $version to $currentVersion. Runs inside the write
 * transaction of run_migrations(), so a migration must not open its own.
 */
function migrate_schema(PDO $db, int $version, int $currentVersion): void
{
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
            referrers TEXT,
            hits INTEGER DEFAULT 1,
            first_seen TEXT NOT NULL,
            last_seen TEXT NOT NULL
        )');
        $db->exec('CREATE UNIQUE INDEX idx_broken_unique ON broken_links (site, path, status)');
        $db->exec('CREATE INDEX idx_broken_site_status ON broken_links (site, status, hits DESC)');
    }

    // v4: clean up scanner noise from broken_links
    if ($version < 4) {
        $db->exec("DELETE FROM broken_links WHERE path LIKE '%.php' OR path LIKE '/cgi-bin%' OR path LIKE '/.well-known%'");
    }

    // v5: broader noise cleanup + decode referrers
    if ($version < 5) {
        $db->exec("DELETE FROM broken_links WHERE path LIKE '%.php%' OR path LIKE '%/wp-%' OR path LIKE '%/wordpress%' OR path LIKE '%/xmlrpc%' OR path LIKE '%/cgi-bin%' OR path LIKE '/.well-known%'");
        // Decode URL-encoded referrers (only if old schema with referrer column)
        $blCols = array_column($db->query("PRAGMA table_info(broken_links)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (in_array('referrer', $blCols, true)) {
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
    }

    // v6: clean up test entries, normalize double slashes, URL-decode stored referrers
    if ($version < 6) {
        $db->exec("DELETE FROM broken_links WHERE path IN ('/endpoint-test', '/test-404', '/test-301', '/test-200', '/denna-sida-finns-inte-abc123', '/finns-inte-test', '/finns-inte-test-xyz', '/testar-broken-link-xyz', '/testar-broken-link-123')");
        $db->exec("UPDATE broken_links SET path = REPLACE(path, '//', '/') WHERE path LIKE '//%'");
        // URL-decode stored referrers (only if old schema with referrer column)
        $blCols = array_column($db->query("PRAGMA table_info(broken_links)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (in_array('referrer', $blCols, true)) {
            $rows = $db->query("SELECT id, referrer FROM broken_links WHERE referrer LIKE '%\%%'")->fetchAll(PDO::FETCH_ASSOC);
            $update = $db->prepare("UPDATE broken_links SET referrer = ? WHERE id = ?");
            foreach ($rows as $row) {
                $decoded = urldecode($row['referrer']);
                if ($decoded !== $row['referrer']) {
                    $update->execute([$decoded, $row['id']]);
                }
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

    // v11: share_tokens table
    if ($version < 11) {
        $db->exec('CREATE TABLE IF NOT EXISTS share_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL UNIQUE,
            site TEXT NOT NULL,
            label TEXT,
            expires_at TEXT,
            created_at TEXT NOT NULL
        )');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_share_token ON share_tokens (token)');
    }

    // v12: events table
    if ($version < 12) {
        $db->exec('CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site TEXT NOT NULL,
            event_name TEXT NOT NULL,
            event_data TEXT,
            page_path TEXT,
            visitor_hash TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_events_site_date ON events (site, created_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_events_name ON events (event_name, site, created_at)');
    }

    // v13: broken_links redesign — aggregate referrers into single column
    if ($version < 13) {
        $hasBL = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='broken_links'")->fetch();
        if ($hasBL) {
            $hasReferrerCol = $db->query("PRAGMA table_info(broken_links)")->fetchAll(PDO::FETCH_ASSOC);
            $colNames = array_column($hasReferrerCol, 'name');
            if (in_array('referrer', $colNames, true)) {
                $db->exec('CREATE TABLE broken_links_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    site TEXT NOT NULL,
                    path TEXT NOT NULL,
                    status INTEGER NOT NULL,
                    referrers TEXT,
                    hits INTEGER DEFAULT 1,
                    first_seen TEXT NOT NULL,
                    last_seen TEXT NOT NULL
                )');
                $db->exec("INSERT INTO broken_links_new (site, path, status, referrers, hits, first_seen, last_seen)
                    SELECT site, path, status,
                        SUBSTR(GROUP_CONCAT(DISTINCT referrer), 1, 2000),
                        SUM(hits),
                        MIN(first_seen),
                        MAX(last_seen)
                    FROM broken_links
                    GROUP BY site, path, status");
                $db->exec('DROP TABLE broken_links');
                $db->exec('ALTER TABLE broken_links_new RENAME TO broken_links');
                $db->exec('CREATE UNIQUE INDEX idx_broken_unique ON broken_links (site, path, status)');
                $db->exec('CREATE INDEX idx_broken_site_status ON broken_links (site, status, hits DESC)');
            }
        }
    }

    // v14: goals table + country column
    if ($version < 14) {
        $db->exec('CREATE TABLE IF NOT EXISTS goals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site TEXT NOT NULL,
            path TEXT NOT NULL,
            label TEXT,
            created_at TEXT NOT NULL
        )');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_goals_unique ON goals (site, path)');
    }

    // v15: country column on pageviews + backfill from language
    if ($version < 15) {
        $cols = array_column($db->query('PRAGMA table_info(pageviews)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('country', $cols)) {
            $db->exec('ALTER TABLE pageviews ADD COLUMN country TEXT');
        }
        // Backfill country from language codes where mapping is unambiguous
        $langToCountry = [
            'sv' => 'SE', 'da' => 'DK', 'nb' => 'NO', 'nn' => 'NO', 'fi' => 'FI',
            'is' => 'IS', 'ja' => 'JP', 'ko' => 'KR', 'zh' => 'CN', 'uk' => 'UA',
            'cs' => 'CZ', 'el' => 'GR', 'he' => 'IL', 'hi' => 'IN', 'th' => 'TH',
            'vi' => 'VN', 'ar' => 'SA', 'fa' => 'IR', 'bn' => 'BD', 'ka' => 'GE',
            'et' => 'EE', 'lv' => 'LV', 'lt' => 'LT', 'sl' => 'SI', 'hr' => 'HR',
            'sk' => 'SK', 'bg' => 'BG', 'ro' => 'RO', 'hu' => 'HU', 'sq' => 'AL',
        ];
        $update = $db->prepare('UPDATE pageviews SET country = ? WHERE language = ? AND country IS NULL');
        foreach ($langToCountry as $lang => $cc) {
            $update->execute([$cc, $lang]);
        }
    }

    // v16: goals can target an event name as well as a page path.
    // `path` keeps the target for both kinds (paths start with "/", event names do not).
    if ($version < 16) {
        $goalCols = array_column($db->query('PRAGMA table_info(goals)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if ($goalCols && !in_array('type', $goalCols, true)) {
            $db->exec("ALTER TABLE goals ADD COLUMN type TEXT NOT NULL DEFAULT 'page'");
        }
    }

    // v17: app version on pageviews and events, plus first-seen date per version
    // so release markers are a small lookup instead of a scan of pageviews.
    if ($version < 17) {
        foreach (['pageviews', 'events'] as $table) {
            $cols = array_column($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
            if ($cols && !in_array('app_version', $cols, true)) {
                $db->exec("ALTER TABLE {$table} ADD COLUMN app_version TEXT");
            }
        }
        $db->exec('CREATE TABLE IF NOT EXISTS app_versions (
            site TEXT NOT NULL,
            version TEXT NOT NULL,
            first_seen TEXT NOT NULL,
            PRIMARY KEY (site, version)
        )');
    }

    // v18: saved breakdowns (event + group key shown as a dashboard card)
    if ($version < 18) {
        $db->exec('CREATE TABLE IF NOT EXISTS breakdowns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site TEXT NOT NULL,
            label TEXT,
            event_name TEXT NOT NULL,
            group_key TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_breakdowns_unique ON breakdowns (site, event_name, group_key)');
    }

    // v19: annotations (hand-set chart markers, optional end_date for a range)
    if ($version < 19) {
        $db->exec('CREATE TABLE IF NOT EXISTS annotations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site TEXT NOT NULL,
            date TEXT NOT NULL,
            end_date TEXT,
            label TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_annotations_site_date ON annotations (site, date)');
    }

    // v20: strip Meta's per-click brid from paths of Meta ad traffic
    if ($version < 20) {
        $rows = $db->query("SELECT id, path FROM pageviews WHERE path LIKE '%brid=%' AND lower(utm_source) IN ('fb', 'ig', 'msg', 'an')")->fetchAll(PDO::FETCH_ASSOC);
        $update = $db->prepare('UPDATE pageviews SET path = ? WHERE id = ?');
        foreach ($rows as $row) {
            $update->execute([normalize_path($row['path'], meta_ad_params()), $row['id']]);
        }
    }

    // v21: IP addresses and ranges whose pageviews and events are not stored (site '*' = all sites)
    if ($version < 21) {
        $db->exec('CREATE TABLE IF NOT EXISTS excluded_ips (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site TEXT NOT NULL,
            ip TEXT NOT NULL,
            label TEXT,
            created_at TEXT NOT NULL
        )');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_excluded_ips_unique ON excluded_ips (site, ip)');
    }

    // v22: daily random salt for the visitor hash. Hashes stored before it were
    // salted with the constant APP_KEY and could be recomputed, so each is replaced
    // with a random value: one per old hash, which keeps a visitor's rows of a day
    // together (unique visitors, bounces, funnels) without a way back to the visitor.
    if ($version < 22) {
        $db->exec('CREATE TABLE IF NOT EXISTS salts (
            date TEXT PRIMARY KEY,
            salt TEXT NOT NULL
        )');
        $db->exec('PRAGMA secure_delete = ON');
        $db->exec('CREATE TEMP TABLE hash_map (old TEXT PRIMARY KEY, new TEXT NOT NULL)');
        $db->exec('INSERT INTO hash_map (old, new)
            SELECT visitor_hash, lower(hex(randomblob(32)))
            FROM (SELECT visitor_hash FROM pageviews UNION SELECT visitor_hash FROM events)');
        foreach (['pageviews', 'events'] as $table) {
            $db->exec("UPDATE {$table} SET visitor_hash = (SELECT new FROM hash_map WHERE old = {$table}.visitor_hash)");
        }
        $db->exec('DROP TABLE hash_map');
    }

    // v23: saved cards can be put in any order per site. Existing cards start at 0
    // and keep their order, which ties on position fall back to (created_at, id).
    if ($version < 23) {
        $bdCols = array_column($db->query('PRAGMA table_info(breakdowns)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('position', $bdCols)) {
            $db->exec('ALTER TABLE breakdowns ADD COLUMN position INTEGER NOT NULL DEFAULT 0');
        }
    }

    // v24: an optional target per goal, a count per day, week or month
    if ($version < 24) {
        $goalCols = array_column($db->query('PRAGMA table_info(goals)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('target', $goalCols)) {
            $db->exec('ALTER TABLE goals ADD COLUMN target INTEGER');
        }
        if (!in_array('target_period', $goalCols)) {
            $db->exec('ALTER TABLE goals ADD COLUMN target_period TEXT');
        }
    }

    // v25: a bot's name is decided when its visit is stored, so visits that came in
    // as "Unknown bot/crawler/spider/client" get the name detect_bot() has for them
    // now. Repeat this step as a new version whenever detect_bot() learns names.
    if ($version < 25) {
        rename_unknown_bots($db);
    }

    // v26: one covering index on pageviews (site, created_at, visitor_hash) replaces
    // idx_site_date (its prefix) and idx_visitor. The dashboard's visitor counts, bounces
    // and sessions filter by site and period and count or group by visitor, so they read
    // only the index now, not the rows. idx_visitor served no query: nothing looks a
    // visitor up by hash, and for All sites the planner walked it over the whole table
    // to avoid sorting, which was most of that view's time. Statistics follow, so the
    // planner knows an install has few sites and many rows per site (refresh_statistics)
    if ($version < 26) {
        $db->exec('CREATE INDEX IF NOT EXISTS idx_site_date_visitor ON pageviews (site, created_at, visitor_hash)');
        $db->exec('DROP INDEX IF EXISTS idx_visitor');
        $db->exec('DROP INDEX IF EXISTS idx_site_date');
        $db->exec('PRAGMA analysis_limit = 1000');
        $db->exec('ANALYZE');
    }

    // v27: a site belongs to one group, or none. No group table: a group exists as
    // long as a site points to it. The CLI creates this table too (sites:group), for
    // a database the web app has not migrated yet
    if ($version < 27) {
        $db->exec('CREATE TABLE IF NOT EXISTS site_groups (
            site TEXT PRIMARY KEY,
            group_name TEXT NOT NULL
        )');
    }

    // v28: detect_bot() learned Meta's ads crawler, AI search indexer and AI fetcher, which
    // were stored as "Unknown crawler" (v25's step again)
    if ($version < 28) {
        rename_unknown_bots($db);
    }

    // v29: the daily summary (create_daily_table). Empty here: days are summed as they are
    // needed, a few at a time, never all at once inside this write lock
    if ($version < 29) {
        create_daily_table($db);
    }

    // v30: bot visits counted per day (create_bot_days), from the rows already stored
    if ($version < 30) {
        create_bot_days($db);
        sum_bot_days($db);
    }

    // v31: a token can read groups (group_names, a JSON list, with site empty) and says when it was
    // last used. The CLI adds the same to a database that has not run this yet (shareTokensDb)
    if ($version < 31) {
        $columns = array_column($db->query('PRAGMA table_info(share_tokens)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('group_names', $columns, true)) $db->exec('ALTER TABLE share_tokens ADD COLUMN group_names TEXT');
        if (!in_array('last_used_at', $columns, true)) $db->exec('ALTER TABLE share_tokens ADD COLUMN last_used_at TEXT');
    }

    $db->exec('PRAGMA user_version = ' . $currentVersion);
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

    // Sanitize: replace invalid UTF-8 sequences (e.g. %C0%AE path traversal attacks)
    if (!mb_check_encoding($path, 'UTF-8')) {
        $path = mb_convert_encoding($path, 'UTF-8', 'UTF-8');
    }

    // Ignore noise paths (scanners probing for vulnerabilities)
    if (preg_match('#\.php\d?|/cgi-bin|^/\.well-known|/wp-|/wordpress|^/\.env|^/\.git|/xmlrpc|/administrator|/etc/passwd#i', $path)) return;

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

    // Upsert: increment hits, append referrer if new
    $stmt = $db->prepare("INSERT INTO broken_links (site, path, status, referrers, hits, first_seen, last_seen)
        VALUES (?, ?, ?, ?, 1, ?, ?)
        ON CONFLICT(site, path, status) DO UPDATE SET
            hits = hits + 1,
            last_seen = excluded.last_seen,
            referrers = CASE
                WHEN excluded.referrers IS NULL THEN referrers
                WHEN referrers IS NULL THEN excluded.referrers
                WHEN INSTR(',' || referrers || ',', ',' || excluded.referrers || ',') > 0 THEN referrers
                WHEN LENGTH(referrers) > 1900 THEN referrers
                ELSE referrers || ',' || excluded.referrers
            END");
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

function cleanup_bot_visits(PDO $db, int $days): void
{
    if ($days <= 0) return;
    $marker = dirname($db->query("PRAGMA database_list")->fetch()['file'] ?? __DIR__) . '/.bot_cleanup';
    if (file_exists($marker) && file_get_contents($marker) === date('Y-m-d')) return;

    // Rowids follow time, so what goes is a range at the start of the table. Deleted in batches:
    // on a busy install that is a whole day's visits, and tracking waits for each write
    $stmt = $db->prepare('SELECT id FROM bot_visits WHERE created_at >= ? ORDER BY id LIMIT 1');
    $stmt->execute([date('Y-m-d H:i:s', strtotime("-{$days} days"))]);
    $keepFrom = $stmt->fetchColumn() ?: PHP_INT_MAX;
    $stmt->closeCursor();
    $delete = $db->prepare('DELETE FROM bot_visits WHERE id < ? AND id < (SELECT MIN(id) FROM bot_visits) + 5000');
    do {
        $delete->execute([$keepFrom]);
    } while ($delete->rowCount() > 0);
    file_put_contents($marker, date('Y-m-d'));
}

/**
 * Bot visits counted per site, day and bot, which the dashboard reads instead of the rows: on a
 * site with many pages, crawlers make hundreds of visits per visitor. A trigger keeps the count,
 * so nothing that stores a visit can forget it and no request has to read the rows. Not in
 * `daily`, which is a cache that can be deleted and summed again: the rows go after
 * BOT_RETENTION_DAYS, and the counts stay.
 */
function create_bot_days(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS bot_days (
        site TEXT NOT NULL,
        date TEXT NOT NULL,
        name TEXT NOT NULL,
        category TEXT NOT NULL,
        visits INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (site, date, name, category)
    ) WITHOUT ROWID');
    $db->exec('CREATE TRIGGER IF NOT EXISTS bot_days_count AFTER INSERT ON bot_visits BEGIN
        INSERT INTO bot_days (site, date, name, category, visits)
        VALUES (NEW.site, substr(NEW.created_at, 1, 10), NEW.bot_name, NEW.bot_category, 1)
        ON CONFLICT (site, date, name, category) DO UPDATE SET visits = visits + 1;
    END');
}

// Counts the stored visits of the days after $after into bot_days
function sum_bot_days(PDO $db, string $after = ''): void
{
    $db->prepare('INSERT OR REPLACE INTO bot_days (site, date, name, category, visits)
        SELECT site, substr(created_at, 1, 10), bot_name, bot_category, COUNT(*) FROM bot_visits
        WHERE substr(created_at, 1, 10) > ? GROUP BY 1, 2, 3, 4')->execute([$after]);
}

function is_ignored_bot(string $ua, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if ($pattern !== '' && stripos($ua, $pattern) !== false) {
            return true;
        }
    }
    return false;
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
        // Meta's other crawlers link to its crawler page, which the generic "crawler" would catch.
        // The fetcher gets a link a person asked Meta AI about, so it may skip robots.txt (as ChatGPT-User)
        ['pattern' => 'meta-webindexer',  'name' => 'Meta AI search',  'category' => 'AI'],
        ['pattern' => 'meta-externalfetcher','name' => 'Meta AI fetcher','category' => 'AI'],
        ['pattern' => 'OAI-SearchBot',    'name' => 'OAI-SearchBot',   'category' => 'AI'],
        ['pattern' => 'Amazonbot',        'name' => 'Amazonbot',       'category' => 'AI'],
        ['pattern' => 'ExaSearchBot',     'name' => 'Exa',             'category' => 'AI'],
        ['pattern' => 'ElevenlabsCrawler','name' => 'ElevenLabs',      'category' => 'AI'],
        ['pattern' => 'Reflectionbot',    'name' => 'Reflection AI',   'category' => 'AI'],
        ['pattern' => 'AzureAI-SearchBot','name' => 'Azure AI Search', 'category' => 'AI'],
        ['pattern' => 'Google-CloudVertexBot','name' => 'Google Vertex AI','category' => 'AI'],
        ['pattern' => 'DuckAssistBot',    'name' => 'DuckAssist',      'category' => 'AI'],
        ['pattern' => 'LinkupBot',        'name' => 'Linkup',          'category' => 'AI'],
        ['pattern' => 'img2dataset',      'name' => 'img2dataset',     'category' => 'AI'],
        // Search engines
        ['pattern' => 'Googlebot',         'name' => 'Googlebot',        'category' => 'Search engine'],
        ['pattern' => 'AdsBot-Google',     'name' => 'AdsBot-Google',    'category' => 'Search engine'],
        ['pattern' => 'bingbot',           'name' => 'Bingbot',          'category' => 'Search engine'],
        ['pattern' => 'YandexBot',         'name' => 'YandexBot',        'category' => 'Search engine'],
        ['pattern' => 'DuckDuckBot',       'name' => 'DuckDuckBot',      'category' => 'Search engine'],
        ['pattern' => 'Baiduspider',       'name' => 'Baiduspider',      'category' => 'Search engine'],
        ['pattern' => 'Applebot',          'name' => 'Applebot',         'category' => 'Search engine'],
        ['pattern' => 'PetalBot',          'name' => 'PetalBot',         'category' => 'Search engine'],
        ['pattern' => 'Sogou web spider',  'name' => 'Sogou',            'category' => 'Search engine'],
        ['pattern' => 'SeznamBot',         'name' => 'SeznamBot',        'category' => 'Search engine'],
        ['pattern' => 'YisouSpider',       'name' => 'Yisou',            'category' => 'Search engine'],
        ['pattern' => 'SeekportBot',       'name' => 'Seekport',         'category' => 'Search engine'],
        // Social
        ['pattern' => 'facebookexternalhit','name' => 'Facebook',        'category' => 'Social'],
        ['pattern' => 'meta-externalads', 'name' => 'Meta Ads',        'category' => 'Social'],
        ['pattern' => 'Twitterbot',        'name' => 'Twitterbot',       'category' => 'Social'],
        ['pattern' => 'LinkedInBot',       'name' => 'LinkedInBot',      'category' => 'Social'],
        ['pattern' => 'Slackbot',          'name' => 'Slackbot',         'category' => 'Social'],
        ['pattern' => 'TelegramBot',       'name' => 'TelegramBot',      'category' => 'Social'],
        ['pattern' => 'KakaoBot',          'name' => 'KakaoTalk',        'category' => 'Social'],
        ['pattern' => 'snapchat',          'name' => 'Snapchat',         'category' => 'Social'],
        // SEO
        ['pattern' => 'AhrefsBot',         'name' => 'AhrefsBot',        'category' => 'SEO'],
        ['pattern' => 'SemrushBot',        'name' => 'SemrushBot',       'category' => 'SEO'],
        ['pattern' => 'SiteAuditBot',      'name' => 'Semrush Site Audit','category' => 'SEO'],
        ['pattern' => 'MJ12bot',           'name' => 'Majestic',         'category' => 'SEO'],
        ['pattern' => 'DotBot',            'name' => 'DotBot',           'category' => 'SEO'],
        ['pattern' => 'SERankingBacklinksBot','name' => 'SE Ranking',    'category' => 'SEO'],
        ['pattern' => 'DataForSeoBot',     'name' => 'DataForSEO',       'category' => 'SEO'],
        ['pattern' => 'Barkrowler',        'name' => 'Babbar',           'category' => 'SEO'],
        ['pattern' => 'serpstatbot',       'name' => 'Serpstat',         'category' => 'SEO'],
        ['pattern' => 'AwarioBot',         'name' => 'Awario',           'category' => 'SEO'],
        ['pattern' => 'DomainStatsBot',    'name' => 'DomainStats',      'category' => 'SEO'],
        ['pattern' => 'SnokaBot',          'name' => 'Snoka',            'category' => 'SEO'],
        // Monitors
        ['pattern' => 'UptimeRobot',       'name' => 'UptimeRobot',      'category' => 'Monitor'],
        ['pattern' => 'CensysInspect',    'name' => 'CensysInspect',   'category' => 'Monitor'],
        ['pattern' => 'uptime checker',   'name' => 'Uptime Checker',  'category' => 'Monitor'],
        ['pattern' => 'Pingdom',          'name' => 'Pingdom',         'category' => 'Monitor'],
        ['pattern' => 'Cookiebot',        'name' => 'Cookiebot',       'category' => 'Monitor'],
        ['pattern' => 'Stripebot',        'name' => 'Stripe',          'category' => 'Monitor'],
        ['pattern' => 'VakaBot',          'name' => 'Vaka',            'category' => 'Monitor'],
        ['pattern' => 'Vaka/',            'name' => 'Vaka',            'category' => 'Monitor'],
        // Other crawlers with a name
        ['pattern' => 'AttractCarsBot',   'name' => 'AttractCarsBot',  'category' => 'Other'],
        ['pattern' => 'Taloustutka',      'name' => 'Taloustutka',     'category' => 'Other'],
        ['pattern' => 'FirmCredBot',      'name' => 'FirmCred',        'category' => 'Other'],
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

/**
 * A bot among the requests the tracking script sends. A program that drives a real browser runs
 * the script and shows a browser's user agent, so detect_bot() cannot see it, but a browser people
 * use sends Accept-Language with every request. Only for ?collect and ?event: a crawler reading a
 * page or the pixel often sends no language and is told apart by its user agent.
 */
function detect_script_bot(string $ua, string $acceptLanguage): ?array
{
    return detect_bot($ua) ?? ($acceptLanguage === '' ? ['name' => 'Automated browser', 'category' => 'Other'] : null);
}

/**
 * Give stored visits under an unknown name the name detect_bot() has for them now.
 * A visit whose user agent still matches nothing keeps its name. Runs inside a
 * migration's write transaction, so it opens none of its own.
 */
function rename_unknown_bots(PDO $db): void
{
    $rows = $db->query("SELECT id, user_agent FROM bot_visits WHERE bot_name LIKE 'Unknown %'")->fetchAll(PDO::FETCH_ASSOC);
    $update = $db->prepare('UPDATE bot_visits SET bot_name = ?, bot_category = ? WHERE id = ?');
    foreach ($rows as $row) {
        $bot = detect_bot((string) $row['user_agent']);
        if ($bot && strpos($bot['name'], 'Unknown ') !== 0) {
            $update->execute([$bot['name'], $bot['category'], $row['id']]);
        }
    }

    // The counts follow, once there are any. The oldest day may be cut off by retention, so it
    // keeps its count rather than getting a smaller one from the rows that are left
    if (!$db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'bot_days'")->fetchColumn()) return;
    $oldest = $db->query('SELECT substr(MIN(created_at), 1, 10) FROM bot_visits')->fetchColumn();
    if (!$oldest) return;
    $db->prepare('DELETE FROM bot_days WHERE date > ?')->execute([$oldest]);
    sum_bot_days($db, $oldest);
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

function detect_country(string $acceptLanguage): ?string
{
    // Extract region from Accept-Language header
    // Examples: "sv-SE,sv;q=0.9" → "SE", "en-US,en;q=0.8" → "US", "de" → "DE"
    $primary = explode(',', $acceptLanguage)[0];
    $primary = explode(';', $primary)[0]; // strip quality

    // Try lang-REGION format first (e.g. sv-SE, en-US, de-AT)
    if (preg_match('/^[a-z]{2}-([A-Z]{2})$/i', trim($primary), $m)) {
        return strtoupper($m[1]);
    }

    // Fallback: map bare language codes to most likely country
    $langToCountry = [
        'sv' => 'SE', 'da' => 'DK', 'nb' => 'NO', 'nn' => 'NO', 'fi' => 'FI',
        'is' => 'IS', 'ja' => 'JP', 'ko' => 'KR', 'zh' => 'CN', 'uk' => 'UA',
        'cs' => 'CZ', 'el' => 'GR', 'he' => 'IL', 'hi' => 'IN', 'th' => 'TH',
        'vi' => 'VN', 'ar' => 'SA', 'fa' => 'IR', 'bn' => 'BD', 'ka' => 'GE',
        'et' => 'EE', 'lv' => 'LV', 'lt' => 'LT', 'sl' => 'SI', 'hr' => 'HR',
        'sk' => 'SK', 'bg' => 'BG', 'ro' => 'RO', 'hu' => 'HU', 'sq' => 'AL',
    ];
    $lang = strtolower(substr(trim($primary), 0, 2));
    return $langToCountry[$lang] ?? null;
}
