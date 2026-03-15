<?php

// Load .env file
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

return [
    'app_key' => $_ENV['APP_KEY'] ?? '',

    'db_path' => __DIR__ . '/' . ($_ENV['DB_PATH'] ?? 'data/puls.sqlite'),

    'users_file' => __DIR__ . '/users.json',

    'allowed_origins' => array_filter(explode(',', $_ENV['ALLOWED_ORIGINS'] ?? '')),

    'session_lifetime' => (int) ($_ENV['SESSION_LIFETIME'] ?? 2592000),

    'max_login_attempts' => (int) ($_ENV['MAX_LOGIN_ATTEMPTS'] ?? 5),

    'lockout_minutes' => (int) ($_ENV['LOCKOUT_MINUTES'] ?? 15),
];
