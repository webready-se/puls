<?php

beforeAll(function () {
    require_once __DIR__ . '/../Support/helpers.php';
    $GLOBALS['server_pid'] = startServer();
});

afterAll(function () {
    stopServer($GLOBALS['server_pid']);
});

test('health endpoint returns 200', function () {
    $r = http('GET', '/?health');
    expect($r['status'])->toBe(200);

    $data = json_decode($r['body'], true);
    expect($data['status'])->toBe('ok');
});

test('js endpoint returns javascript', function () {
    $r = http('GET', '/?js');
    expect($r['status'])->toBe(200)
        ->and($r['body'])->toContain('sendBeacon')
        ->and($r['body'])->toContain('collect');

    $contentType = collect($r['headers'])->first(fn ($h) => str_contains($h, 'Content-Type'));
    expect($contentType)->toContain('javascript');
});

test('pixel endpoint returns gif', function () {
    $r = http('GET', '/?pixel&s=test&p=/');
    expect($r['status'])->toBe(200);

    $contentType = collect($r['headers'])->first(fn ($h) => str_contains($h, 'Content-Type'));
    expect($contentType)->toContain('image/gif');
});

test('collect endpoint accepts POST', function () {
    $r = http('POST', '/?collect', [
        'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
        'content' => json_encode([
            'u' => '/test-page',
            'r' => '',
            'w' => 1920,
            'site' => 'test',
        ]),
    ]);
    expect($r['status'])->toBe(204);
});

test('collect endpoint stores utm term and content', function () {
    $r = http('POST', '/?collect', [
        'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
        'content' => json_encode([
            'u' => '/test-utm',
            'r' => '',
            'w' => 1920,
            'site' => 'test',
            'utm' => [
                'source' => 'ig',
                'medium' => 'social',
                'campaign' => 'spring',
                'term' => 'keyword',
                'content' => 'link_in_bio',
            ],
        ]),
    ]);
    expect($r['status'])->toBe(204);
});

test('collect endpoint normalizes path with tracking params', function () {
    $r = http('POST', '/?collect', [
        'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
        'content' => json_encode([
            'u' => '/?fbclid=abc123&utm_source=ig',
            'r' => '',
            'w' => 1920,
            'site' => 'test',
            'utm' => ['source' => 'ig'],
        ]),
    ]);
    expect($r['status'])->toBe(204);
});

test('js snippet captures all five utm params', function () {
    $r = http('GET', '/?js');
    expect($r['body'])->toContain("'source','medium','campaign','term','content'");
});

test('collect endpoint rejects empty payload', function () {
    $r = http('POST', '/?collect', [
        'header' => 'Content-Type: application/json',
        'content' => '{}',
    ]);
    expect($r['status'])->toBe(204);
});

test('api endpoint requires auth', function () {
    $r = http('GET', '/?api&days=7');
    expect($r['status'])->toBe(401);

    $data = json_decode($r['body'], true);
    expect($data['error'])->toBe('Unauthorized');
});

test('api sites endpoint requires auth', function () {
    $r = http('GET', '/?api&sites');
    expect($r['status'])->toBe(401);
});

test('login page renders', function () {
    $r = http('GET', '/?login');
    expect($r['status'])->toBe(200)
        ->and($r['body'])->toContain('Logga in')
        ->and($r['body'])->toContain('_token')
        ->and($r['body'])->toContain('_login');
});

test('root without auth shows login', function () {
    $r = http('GET', '/');
    expect($r['status'])->toBe(200)
        ->and($r['body'])->toContain('Logga in');
});

test('manifest endpoint returns valid JSON', function () {
    $r = http('GET', '/?manifest');
    expect($r['status'])->toBe(200);

    $contentType = collect($r['headers'])->first(fn ($h) => str_contains($h, 'Content-Type'));
    expect($contentType)->toContain('manifest+json');

    $data = json_decode($r['body'], true);
    expect($data['name'])->toBe('Puls')
        ->and($data['display'])->toBe('standalone')
        ->and($data['icons'])->toHaveCount(2);
});

test('icon files are served as PNG', function () {
    $r = http('GET', '/icon-192.png');
    expect($r['status'])->toBe(200);

    $contentType = collect($r['headers'])->first(fn ($h) => str_contains($h, 'Content-Type'));
    expect($contentType)->toContain('image/png');
});

test('dashboard includes PWA meta tags', function () {
    $r = http('GET', '/?login');
    expect($r['body'])->toContain('mobile-web-app-capable')
        ->and($r['body'])->toContain('manifest');
});

test('static files return 404', function () {
    $r = http('GET', '/favicon.ico');
    expect($r['status'])->toBe(404);
});

test('nonexistent static file returns 404', function () {
    $r = http('GET', '/robots.txt');
    expect($r['status'])->toBe(404);
});

/**
 * Helper to make collection-like operations work without Laravel.
 */
function collect(array $items): object
{
    return new class($items) {
        public function __construct(private array $items) {}
        public function first(callable $fn): mixed
        {
            foreach ($this->items as $item) {
                if ($fn($item)) return $item;
            }
            return null;
        }
    };
}
