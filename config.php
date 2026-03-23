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
        $_ENV[trim($key)] ??= trim($value);
    }
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

$appKey = $_ENV['APP_KEY'] ?? '';
if (empty($appKey) && file_exists($envFile)) {
    if (PHP_SAPI !== 'cli') http_response_code(500);
    exit('APP_KEY is not set. Run: php puls key:generate');
}

return [
    'app_key' => $appKey,

    'db_path' => resolve_path($_ENV['DB_PATH'] ?? 'data/puls.sqlite'),

    'users_file' => resolve_path($_ENV['USERS_FILE'] ?? 'users.json'),

    'allowed_origins' => array_filter(explode(',', $_ENV['ALLOWED_ORIGINS'] ?? '')),

    'session_lifetime' => (int) ($_ENV['SESSION_LIFETIME'] ?? 2592000),

    'max_login_attempts' => (int) ($_ENV['MAX_LOGIN_ATTEMPTS'] ?? 5),

    'lockout_minutes' => (int) ($_ENV['LOCKOUT_MINUTES'] ?? 15),
];
