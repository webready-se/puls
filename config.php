<?php

// Ensure OS environment variables are in $_ENV (needed when variables_order lacks 'E')
foreach (getenv() as $k => $v) $_ENV[$k] ??= $v;

// Load .env file (does not override existing values)
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === '"' && $value[-1] === '"') {
            $value = substr($value, 1, -1);
        }
        $_ENV[trim($key)] ??= $value;
    }
}

// The time zone that stored times, days and hours are in (TIMEZONE=Europe/Stockholm). Unset keeps
// php.ini's. An unknown name keeps it too, with a warning, so a typo never stops the tracking
$timezone = trim($_ENV['TIMEZONE'] ?? '');
if ($timezone !== '' && !@date_default_timezone_set($timezone)) {
    error_log("Puls: unknown TIMEZONE '{$timezone}', using " . date_default_timezone_get());
}

function resolve_path(string $path): string
{
    if ($path[0] === '/') return $path;

    // Detect Forge zero-deploy: __DIR__ is inside releases/, resolve to site root
    $dir = __DIR__;
    if (preg_match('#(/home/forge/[^/]+)/releases/\d+#', $dir, $m)) {
        return $m[1] . '/' . $path;
    }

    return $dir . '/' . $path;
}

return [
    'app_name' => $_ENV['APP_NAME'] ?? 'Puls',

    'app_tagline' => $_ENV['APP_TAGLINE'] ?? 'See your traffic. Respect their privacy.',

    'app_accent' => $_ENV['APP_ACCENT'] ?? '#f16272',

    // "Made by Webready in Sweden" in the dashboard footer; FOOTER_CREDIT=false turns it off
    'footer_credit' => !in_array(strtolower(trim($_ENV['FOOTER_CREDIT'] ?? 'true')), ['false', '0', 'no', 'off'], true),

    'timezone' => date_default_timezone_get(),

    // Clock times in the dashboard: 24h (14:00) unless TIME_FORMAT=12h (2 PM). Not the browser's
    // locale, since Chrome formats by its UI language, not the OS region
    'time_format' => in_array(strtolower(trim($_ENV['TIME_FORMAT'] ?? '')), ['12h', '12'], true) ? '12h' : '24h',

    'db_path' => resolve_path($_ENV['DB_PATH'] ?? 'data/puls.sqlite'),

    'users_file' => resolve_path($_ENV['USERS_FILE'] ?? 'users.json'),

    'allowed_origins' => array_filter(explode(',', $_ENV['ALLOWED_ORIGINS'] ?? '')),

    'session_lifetime' => (int) ($_ENV['SESSION_LIFETIME'] ?? 2592000),

    'max_login_attempts' => (int) ($_ENV['MAX_LOGIN_ATTEMPTS'] ?? 5),

    'lockout_minutes' => (int) ($_ENV['LOCKOUT_MINUTES'] ?? 15),

    // Days to keep custom events; 0 keeps them forever
    'events_retention_days' => max(0, (int) ($_ENV['EVENTS_RETENTION_DAYS'] ?? 400)),

    'ignored_bots' => array_filter(array_map('trim', explode(',', $_ENV['IGNORED_BOTS'] ?? ''))),

    // Reverse proxies (IPs or CIDR ranges) whose X-Forwarded-For / X-Real-IP is trusted
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', $_ENV['TRUSTED_PROXIES'] ?? '')))),
];
