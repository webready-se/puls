<?php

/**
 * Helper to run HTTP requests against the built-in PHP server for integration tests.
 */

function startServer(int $port = 8089, array $env = []): mixed
{
    $docRoot = escapeshellarg(__DIR__ . '/../../public');
    $cmd = "exec php -S localhost:{$port} -t {$docRoot}";

    $envVars = array_merge($_ENV, $env);

    $process = proc_open($cmd, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ], $pipes, null, $envVars);

    // Wait for server to be ready
    for ($i = 0; $i < 50; $i++) {
        usleep(100_000);
        $sock = @fsockopen('localhost', $port);
        if ($sock) {
            fclose($sock);
            break;
        }
    }

    return $process;
}

function stopServer(mixed $process): void
{
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
}

/**
 * Create a test SQLite database with all tables.
 */
function createCliTestDb(string $path): PDO
{
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec('CREATE TABLE pageviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site TEXT NOT NULL,
        path TEXT NOT NULL,
        visitor_hash TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $db->exec('CREATE TABLE bot_visits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site TEXT NOT NULL,
        path TEXT NOT NULL,
        bot_name TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $db->exec('CREATE TABLE broken_links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site TEXT NOT NULL,
        path TEXT NOT NULL,
        status INTEGER NOT NULL,
        referrers TEXT,
        hits INTEGER DEFAULT 1,
        first_seen TEXT DEFAULT CURRENT_TIMESTAMP,
        last_seen TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $db->exec('CREATE UNIQUE INDEX idx_broken_unique ON broken_links (site, path, status)');
    $db->exec('CREATE TABLE share_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token TEXT NOT NULL UNIQUE,
        site TEXT NOT NULL,
        label TEXT,
        expires_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $db->exec('CREATE TABLE events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site TEXT NOT NULL,
        event_name TEXT NOT NULL,
        event_data TEXT,
        page_path TEXT,
        visitor_hash TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');

    return $db;
}

/**
 * Run a CLI command as subprocess with env overrides. Optional STDIN input.
 */
function runCli(string $tmpDir, string $command, array $args = [], ?string $stdin = null): array
{
    $puls = realpath(__DIR__ . '/../../puls');
    $wrapper = $tmpDir . '/run.php';

    $argsExport = '';
    foreach ($args as $arg) {
        $argsExport .= ", " . var_export($arg, true);
    }

    file_put_contents($wrapper, "<?php\n"
        . "\$_ENV['DB_PATH'] = " . var_export($tmpDir . '/test.sqlite', true) . ";\n"
        . "\$_ENV['USERS_FILE'] = " . var_export($tmpDir . '/users.json', true) . ";\n"
        . "\$_ENV['APP_KEY'] = 'test-key';\n"
        . "\$argv = ['puls', " . var_export($command, true) . $argsExport . "];\n"
        . "\$argc = count(\$argv);\n"
        . "require " . var_export($puls, true) . ";\n"
    );

    $cmd = "php " . escapeshellarg($wrapper) . " 2>&1";
    if ($stdin !== null) {
        $cmd = "echo " . escapeshellarg($stdin) . " | " . $cmd;
    }
    exec($cmd, $output, $exitCode);
    @unlink($wrapper);

    return ['output' => implode("\n", $output), 'exit' => $exitCode];
}

function http(string $method, string $path, array $options = [], int $port = 8089): array
{
    $url = "http://localhost:{$port}" . $path;

    $ctx = stream_context_create(['http' => array_merge([
        'method' => $method,
        'ignore_errors' => true,
        'timeout' => 5,
    ], $options)]);

    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0])) {
        preg_match('/\d{3}/', $http_response_header[0], $m);
        $status = (int) ($m[0] ?? 0);
    }

    return ['status' => $status, 'body' => $body ?: '', 'headers' => $http_response_header ?? []];
}
