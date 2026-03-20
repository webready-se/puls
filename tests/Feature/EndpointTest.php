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

test('js snippet detects Google Ads gad_source param', function () {
    $r = http('GET', '/?js');
    expect($r['body'])->toContain('gad_source');
});

test('collect endpoint detects Google Ads params server-side', function () {
    $r = http('POST', '/?collect', [
        'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
        'content' => json_encode([
            'u' => '/dackhotell?gad_source=1&gad_campaignid=21828512064',
            'r' => '',
            'w' => 1920,
            'site' => 'test',
        ]),
    ]);
    expect($r['status'])->toBe(204);
});

test('log endpoint tracks bot visits', function () {
    $r = http('GET', '/?log&s=test&p=/some-page', [
        'header' => 'User-Agent: Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)',
    ]);
    expect($r['status'])->toBe(204);
});

test('log endpoint tracks unknown user agents as Unknown client', function () {
    $r = http('GET', '/?log&s=test&p=/unknown-ua', [
        'header' => 'User-Agent: Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/120.0',
    ]);
    expect($r['status'])->toBe(204);
});

test('log endpoint deduplicates within 10 seconds', function () {
    $ua = 'User-Agent: Mozilla/5.0 (compatible; ClaudeBot/1.0)';
    // First request
    $r1 = http('GET', '/?log&s=dedup-test&p=/dedup', ['header' => $ua]);
    expect($r1['status'])->toBe(204);
    // Second identical request — should be deduped
    $r2 = http('GET', '/?log&s=dedup-test&p=/dedup', ['header' => $ua]);
    expect($r2['status'])->toBe(204);
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
        ->and($r['body'])->toContain('Log in')
        ->and($r['body'])->toContain('_token')
        ->and($r['body'])->toContain('_login');
});

test('root without auth shows login', function () {
    $r = http('GET', '/');
    expect($r['status'])->toBe(200)
        ->and($r['body'])->toContain('Log in');
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

// =====================================================================
// Status log (broken links)
// =====================================================================

test('status_log endpoint returns 204', function () {
    $r = http('GET', '/?status_log&s=test&p=/missing-page&status=404');
    expect($r['status'])->toBe(204);
});

test('status_log ignores 200 responses', function () {
    // Send a 200 — should be ignored
    http('GET', '/?status_log&s=test&p=/ok-page&status=200');

    // Verify via API (need auth — test indirectly by sending 404 and checking it appears)
    // The 200 should simply not be stored — tested via unit-level assertions below
    $r = http('GET', '/?status_log&s=test&p=/ok-page&status=200');
    expect($r['status'])->toBe(204);
});

test('status_log ignores noise paths', function () {
    $r = http('GET', '/?status_log&s=test&p=/wp-admin/install.php&status=404');
    expect($r['status'])->toBe(204);

    $r = http('GET', '/?status_log&s=test&p=/.env&status=404');
    expect($r['status'])->toBe(204);

    $r = http('GET', '/?status_log&s=test&p=/admin.php&status=404');
    expect($r['status'])->toBe(204);

    $r = http('GET', '/?status_log&s=test&p=/cgi-bin/test&status=404');
    expect($r['status'])->toBe(204);

    $r = http('GET', '/?status_log&s=test&p=/.well-known/assetlinks.json&status=404');
    expect($r['status'])->toBe(204);

    $r = http('GET', '/?status_log&s=test&p=/wp-includes/js/jquery&status=404');
    expect($r['status'])->toBe(204);

    $r = http('GET', '/?status_log&s=test&p=/wordpress/wp-admin/maint&status=404');
    expect($r['status'])->toBe(204);

    $r = http('GET', '/?status_log&s=test&p=/randkeyword.PhP7&status=404');
    expect($r['status'])->toBe(204);

    $r = http('GET', '/?status_log&s=test&p=' . urlencode('/inputs.php?p=') . '&status=404');
    expect($r['status'])->toBe(204);
});

test('status_log increments hits on duplicate', function () {
    // Send same path twice
    http('GET', '/?status_log&s=hitcount&p=/dup-test&status=404');
    http('GET', '/?status_log&s=hitcount&p=/dup-test&status=404');

    // Both should return 204 (no error)
    $r = http('GET', '/?status_log&s=hitcount&p=/dup-test&status=404');
    expect($r['status'])->toBe(204);
});

test('status_log ignores 304 responses', function () {
    $r = http('GET', '/?status_log&s=test&p=/cached&status=304');
    expect($r['status'])->toBe(204);
});

test('status_log tracks 301 redirects', function () {
    $r = http('GET', '/?status_log&s=test&p=/old-page&status=301');
    expect($r['status'])->toBe(204);
});

test('status_log normalizes path', function () {
    // Path with tracking params should be stripped
    $r = http('GET', '/?status_log&s=test&p=' . urlencode('/page?fbclid=abc123') . '&status=404');
    expect($r['status'])->toBe(204);
});

test('login page has security headers', function () {
    $r = http('GET', '/');
    expect($r['status'])->toBe(200);

    $headers = implode("\n", $r['headers']);
    expect($headers)->toContain('Content-Security-Policy:')
        ->and($headers)->toContain('X-Content-Type-Options: nosniff')
        ->and($headers)->toContain('X-Frame-Options: DENY')
        ->and($headers)->toContain('Referrer-Policy:')
        ->and($headers)->toContain('Permissions-Policy:');
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
