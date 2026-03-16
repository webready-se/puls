<?php

/**
 * Helper to run HTTP requests against the built-in PHP server for integration tests.
 */

function startServer(): mixed
{
    $port = 8089;
    $docRoot = escapeshellarg(__DIR__ . '/../../public');
    $cmd = "exec php -S localhost:{$port} -t {$docRoot}";

    $process = proc_open($cmd, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ], $pipes);

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

function http(string $method, string $path, array $options = []): array
{
    $url = 'http://localhost:8089' . $path;

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
