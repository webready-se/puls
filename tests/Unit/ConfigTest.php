<?php

// Load config.php from a copy in its own directory, so its .env is the test's
function loadConfigWithEnv(string $env): string
{
    $dir = sys_get_temp_dir() . '/puls-config-' . uniqid();
    mkdir($dir);
    register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg($dir)));
    copy(__DIR__ . '/../../config.php', $dir . '/config.php');
    file_put_contents($dir . '/.env', $env);
    $code = '$c = require ' . var_export($dir . '/config.php', true) . '; echo $c["app_name"];';
    return (string) shell_exec('env -u APP_KEY -u APP_NAME php -r ' . escapeshellarg($code) . ' 2>&1');
}

test('config loads from a .env without APP_KEY', function () {
    expect(loadConfigWithEnv("APP_NAME=Test Puls\n"))->toBe('Test Puls');
});

test('a leftover APP_KEY in .env is ignored', function () {
    expect(loadConfigWithEnv("APP_KEY=base64:abc\nAPP_NAME=Test Puls\n"))->toBe('Test Puls');
});

test('the footer credit is on unless FOOTER_CREDIT turns it off', function () {
    $credit = function (string $env): string {
        $dir = sys_get_temp_dir() . '/puls-config-' . uniqid();
        mkdir($dir);
        register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg($dir)));
        copy(__DIR__ . '/../../config.php', $dir . '/config.php');
        file_put_contents($dir . '/.env', $env);
        $code = '$c = require ' . var_export($dir . '/config.php', true) . '; echo json_encode($c["footer_credit"]);';
        return (string) shell_exec('env -u FOOTER_CREDIT php -r ' . escapeshellarg($code) . ' 2>&1');
    };
    expect($credit(''))->toBe('true')
        ->and($credit("FOOTER_CREDIT=false\n"))->toBe('false')
        ->and($credit("FOOTER_CREDIT=0\n"))->toBe('false')
        ->and($credit("FOOTER_CREDIT=true\n"))->toBe('true');
});

// Run config.php from a copy with this .env and print one PHP expression ($c is the config)
function configValue(string $env, string $expr): string
{
    $dir = sys_get_temp_dir() . '/puls-config-' . uniqid();
    mkdir($dir);
    register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg($dir)));
    copy(__DIR__ . '/../../config.php', $dir . '/config.php');
    file_put_contents($dir . '/.env', $env);
    $code = '$c = require ' . var_export($dir . '/config.php', true) . '; echo ' . $expr . ';';
    return (string) shell_exec('env -u TIMEZONE -u TIME_FORMAT php -r ' . escapeshellarg($code) . ' 2>&1');
}

test('TIMEZONE sets the time zone; unset or unknown keeps php.ini, an unknown one with a warning', function () {
    $default = trim((string) shell_exec('php -r ' . escapeshellarg('echo date_default_timezone_get();')));
    expect(configValue("TIMEZONE=Europe/Stockholm\n", 'date_default_timezone_get() . "|" . $c["timezone"]'))->toBe('Europe/Stockholm|Europe/Stockholm')
        ->and(configValue('', '$c["timezone"]'))->toBe($default)
        ->and(configValue("TIMEZONE=Mars/Olympus\n", '$c["timezone"]'))->toContain("unknown TIMEZONE 'Mars/Olympus'")->toEndWith($default);
});

test('TIME_FORMAT is 24h unless set to 12h', function () {
    expect(configValue('', '$c["time_format"]'))->toBe('24h')
        ->and(configValue("TIME_FORMAT=12h\n", '$c["time_format"]'))->toBe('12h')
        ->and(configValue("TIME_FORMAT=12\n", '$c["time_format"]'))->toBe('12h')
        ->and(configValue("TIME_FORMAT=ampm\n", '$c["time_format"]'))->toBe('24h');
});
