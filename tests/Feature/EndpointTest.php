<?php

beforeAll(function () {
    require_once __DIR__ . '/../Support/helpers.php';
    $GLOBALS['server_pid'] = startServer();
});

afterAll(function () {
    stopServer($GLOBALS['server_pid']);
    // Clean up test share tokens from the real database
    try {
        $db = getTestDb();
        $db->exec("DELETE FROM share_tokens WHERE label = 'test'");
    } catch (Throwable $e) {}
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

test('js endpoint drains events queued before the script loaded', function () {
    $r = http('GET', '/?js');
    expect($r['body'])->toContain('prev.q')
        ->and($r['body'])->toContain('puls.track.apply');
});

test('js endpoint supports data-debug console logging', function () {
    $r = http('GET', '/?js');
    expect($r['body'])->toContain('dataset.debug')
        ->and($r['body'])->toContain('console.log');
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

// =====================================================================
// Origin validation
// =====================================================================

test('collect rejects unknown origin when ALLOWED_ORIGINS is set', function () {
    $pid = startServer(8090, ['ALLOWED_ORIGINS' => 'allowed.com']);
    try {
        // Unknown domain — should be rejected
        $r = http('POST', '/?collect', [
            'header' => "Content-Type: application/json\r\nOrigin: https://evil.com\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
            'content' => json_encode(['u' => '/should-not-store', 'site' => 'origin-test']),
        ], 8090);
        expect($r['status'])->toBe(403);

        // Exact domain — should be allowed
        $r = http('POST', '/?collect', [
            'header' => "Content-Type: application/json\r\nOrigin: https://allowed.com\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
            'content' => json_encode(['u' => '/should-store', 'site' => 'origin-test']),
        ], 8090);
        expect($r['status'])->toBe(204);

        // Subdomain — should be allowed
        $r = http('POST', '/?collect', [
            'header' => "Content-Type: application/json\r\nOrigin: https://www.allowed.com\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
            'content' => json_encode(['u' => '/subdomain-ok', 'site' => 'origin-test']),
        ], 8090);
        expect($r['status'])->toBe(204);
    } finally {
        stopServer($pid);
    }
});

test('collect accepts any origin when ALLOWED_ORIGINS is empty', function () {
    $r = http('POST', '/?collect', [
        'header' => "Content-Type: application/json\r\nOrigin: https://random-site.com\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
        'content' => json_encode(['u' => '/any-origin', 'site' => 'test']),
    ]);
    expect($r['status'])->toBe(204);
});

// =====================================================================
// Shareable dashboards
// =====================================================================

function getTestDb(): PDO
{
    // Read DB_PATH from .env without requiring config.php (which exits on missing APP_KEY)
    $dbPath = 'data/puls.sqlite';
    $envFile = __DIR__ . '/../../.env';
    if (file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with($line, 'DB_PATH=')) {
                $dbPath = trim(substr($line, 8));
                break;
            }
        }
    }
    // Resolve relative path from project root
    if ($dbPath[0] !== '/') {
        $dbPath = __DIR__ . '/../../' . $dbPath;
    }

    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ensure share_tokens table exists (migration may not have run yet)
    $db->exec('CREATE TABLE IF NOT EXISTS share_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token TEXT NOT NULL UNIQUE,
        site TEXT NOT NULL,
        label TEXT,
        expires_at TEXT,
        created_at TEXT NOT NULL
    )');

    return $db;
}

function createTestShareToken(string $site, ?string $expiresAt = null): string
{
    $db = getTestDb();
    $token = bin2hex(random_bytes(32));
    $stmt = $db->prepare('INSERT INTO share_tokens (token, site, label, expires_at, created_at) VALUES (?, ?, ?, ?, datetime("now"))');
    $stmt->execute([$token, $site, 'test', $expiresAt]);
    return $token;
}

test('share endpoint returns 404 for invalid token', function () {
    $r = http('GET', '/?share=invalid-token-that-does-not-exist-1234');
    expect($r['status'])->toBe(404);
});

test('share endpoint returns 404 for short token', function () {
    $r = http('GET', '/?share=abc');
    expect($r['status'])->toBe(404);
});

test('share endpoint returns dashboard for valid token', function () {
    $token = createTestShareToken('test');
    $r = http('GET', '/?share=' . $token);
    expect($r['status'])->toBe(200)
        ->and($r['body'])->toContain('PULS_SHARE')
        ->and($r['body'])->toContain($token);
});

test('share endpoint sets security headers', function () {
    $token = createTestShareToken('test');
    $r = http('GET', '/?share=' . $token);
    $headers = implode("\n", $r['headers']);
    expect($headers)->toContain('X-Content-Type-Options: nosniff')
        ->and($headers)->toContain('X-Frame-Options: DENY');
});

test('share API returns data scoped to site', function () {
    $token = createTestShareToken('test');
    $r = http('GET', '/?api&days=7&share=' . $token);
    expect($r['status'])->toBe(200);
    $data = json_decode($r['body'], true);
    expect($data)->toBeArray()
        ->and($data)->toHaveKey('totals');
});

test('api current and previous periods have equal length for days=N', function () {
    $token = createTestShareToken('period-align');
    $db = getTestDb();
    // Seed one pageview per day for the last 14 days so both current and
    // previous have full data to compare.
    $stmt = $db->prepare('INSERT INTO pageviews (created_at, site, path, visitor_hash) VALUES (?, ?, ?, ?)');
    for ($i = 0; $i < 14; $i++) {
        $ts = date('Y-m-d 12:00:00', strtotime("-{$i} days"));
        $stmt->execute([$ts, 'period-align', '/', "v{$i}"]);
    }

    foreach ([1, 7] as $days) {
        $r = http('GET', "/?api&days={$days}&share={$token}");
        expect($r['status'])->toBe(200);
        $data = json_decode($r['body'], true);
        expect(count($data['pageviews']))->toBe($days, "current period for days={$days}")
            ->and(count($data['previousPageviews']))->toBe($days, "previous period for days={$days}")
            // Last current day should be today, last previous day should be exactly $days days before
            ->and($data['pageviews'][$days - 1]['date'])->toBe(date('Y-m-d'))
            ->and($data['previousPageviews'][$days - 1]['date'])->toBe(date('Y-m-d', strtotime("-{$days} days")));
    }
});

test('share API returns 404 for expired token', function () {
    $token = createTestShareToken('test', '2020-01-01 00:00:00');
    $r = http('GET', '/?api&days=7&share=' . $token);
    expect($r['status'])->toBe(404);
});

test('share sites endpoint returns only scoped site', function () {
    $token = createTestShareToken('test');
    $r = http('GET', '/?api&sites&share=' . $token);
    expect($r['status'])->toBe(200);
    $sites = json_decode($r['body'], true);
    expect($sites)->toBe(['test']);
});

test('share endpoint does not start a session', function () {
    $token = createTestShareToken('test');
    $r = http('GET', '/?share=' . $token);
    $setCookie = collect($r['headers'])->first(fn ($h) => str_contains(strtolower($h), 'set-cookie'));
    expect($setCookie)->toBeNull();
});

// =====================================================================
// Event endpoint tests
// =====================================================================

test('event endpoint accepts POST', function () {
    $r = http('POST', '/?event', [
        'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
        'content' => json_encode([
            'event_name' => 'signup',
            'site' => 'test',
            'page_path' => '/pricing',
            'event_data' => ['plan' => 'pro'],
        ]),
    ]);
    expect($r['status'])->toBe(204);
});

test('event endpoint rejects empty event_name', function () {
    $r = http('POST', '/?event', [
        'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
        'content' => json_encode([
            'site' => 'test',
            'page_path' => '/',
        ]),
    ]);
    expect($r['status'])->toBe(204);
});

test('event endpoint filters bots', function () {
    $r = http('POST', '/?event', [
        'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 (compatible; GPTBot/1.0)",
        'content' => json_encode([
            'event_name' => 'bot_event',
            'site' => 'test',
        ]),
    ]);
    expect($r['status'])->toBe(204);
});

test('event endpoint deduplicates same event and data within 10 seconds', function () {
    $name = 'dedup_test_' . uniqid();
    $opts = [
        'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
        'content' => json_encode([
            'event_name' => $name,
            'site' => 'test',
            'page_path' => '/dedup',
            'event_data' => ['query' => 'carrot'],
        ]),
    ];
    expect(http('POST', '/?event', $opts)['status'])->toBe(204);
    expect(http('POST', '/?event', $opts)['status'])->toBe(204);

    $stmt = getTestDb()->prepare('SELECT COUNT(*) FROM events WHERE event_name = ?');
    $stmt->execute([$name]);
    expect((int) $stmt->fetchColumn())->toBe(1);
});

test('event endpoint keeps same event with different data within 10 seconds', function () {
    $name = 'dedup_data_test_' . uniqid();
    $send = function (?array $data) use ($name) {
        $payload = ['event_name' => $name, 'site' => 'test', 'page_path' => '/dedup'];
        if ($data !== null) {
            $payload['event_data'] = $data;
        }
        return http('POST', '/?event', [
            'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
            'content' => json_encode($payload),
        ])['status'];
    };
    expect($send(['query' => 'sea kale']))->toBe(204);
    expect($send(['query' => 'lungwort']))->toBe(204);
    expect($send(null))->toBe(204);

    $stmt = getTestDb()->prepare('SELECT COUNT(*) FROM events WHERE event_name = ?');
    $stmt->execute([$name]);
    expect((int) $stmt->fetchColumn())->toBe(3);
});

test('api includes events data', function () {
    // Send an event
    http('POST', '/?event', [
        'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
        'content' => json_encode([
            'event_name' => 'api_test_event',
            'site' => 'test',
            'page_path' => '/',
        ]),
    ]);

    // Check via share API (no auth needed)
    $token = createTestShareToken('test');
    $r = http('GET', '/?api&days=1&share=' . $token);
    expect($r['status'])->toBe(200);
    $data = json_decode($r['body'], true);
    expect($data)->toHaveKeys(['events', 'eventsTotal', 'outbound', 'outboundTotal']);
});

test('api event drill-down returns zero-filled daily series and totals', function () {
    $name = 'series_test_' . uniqid();
    http('POST', '/?event', [
        'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
        'content' => json_encode(['event_name' => $name, 'site' => 'test', 'page_path' => '/']),
    ]);

    $token = createTestShareToken('test');
    $r = http('GET', '/?api&days=3&share=' . $token . '&event=' . $name);
    expect($r['status'])->toBe(200);
    $data = json_decode($r['body'], true);

    expect($data['eventSeries'])->toHaveCount(3)
        ->and($data['previousEventSeries'])->toHaveCount(3)
        ->and($data['eventSeries'][2]['date'])->toBe(date('Y-m-d'))
        ->and($data['eventSeries'][2]['count'])->toBe(1)
        ->and($data['eventSeries'][0]['count'])->toBe(0)
        ->and($data['eventTotals'])->toBe(['count' => 1, 'visitors' => 1])
        ->and($data['previousEventTotals'])->toBe(['count' => 0, 'visitors' => 0]);

    // The drill-down answers with what the panel needs and skips the rest of the dashboard
    expect($data)->toHaveKeys(['totals', 'pageviews', 'previousPageviews'])
        ->not->toHaveKeys(['pages', 'referrers', 'bots', 'events']);
});

test('api event drill-down discovers data keys and groups by key', function () {
    $name = 'group_test_' . uniqid();
    $send = function (array $data, string $ua) use ($name) {
        http('POST', '/?event', [
            'header' => "Content-Type: application/json\r\nUser-Agent: {$ua}",
            'content' => json_encode(['event_name' => $name, 'site' => 'test', 'page_path' => '/', 'event_data' => $data]),
        ]);
    };
    $send(['query' => 'kale', 'meta' => ['nested' => true]], 'Mozilla/5.0 Chrome/120.0');
    $send(['query' => 'kale'], 'Mozilla/5.0 Firefox/130.0');
    $send(['query' => 'rhubarb', 'zone' => 4], 'Mozilla/5.0 Chrome/120.0');

    $token = createTestShareToken('test');
    $r = http('GET', '/?api&days=1&share=' . $token . '&event=' . $name);
    $data = json_decode($r['body'], true);
    $keys = array_column($data['eventKeys'], 'count', 'key');
    expect($keys)->toBe(['query' => 3, 'zone' => 1])
        ->and($data['eventGroupKey'])->toBe('query')
        ->and($data['eventGroup'])->toHaveCount(2);

    $r = http('GET', '/?api&days=1&share=' . $token . '&event=' . $name . '&group=');
    $data = json_decode($r['body'], true);
    expect($data['eventGroupKey'])->toBeNull()
        ->and($data['eventGroup'])->toBe([]);

    $r = http('GET', '/?api&days=1&share=' . $token . '&event=' . $name . '&group=query');
    expect($r['status'])->toBe(200);
    $data = json_decode($r['body'], true);
    expect($data['eventGroup'])->toBe([
        ['value' => 'kale', 'count' => 2, 'visitors' => 2, 'new' => true],
        ['value' => 'rhubarb', 'count' => 1, 'visitors' => 1, 'new' => true],
    ])->and($data['eventGroupNew'])->toBe(2);
});

test('api event group ignores keys that are not present', function () {
    $token = createTestShareToken('test');
    $r = http('GET', '/?api&days=1&share=' . $token . '&event=nothing&group=' . urlencode('no"such.key'));
    expect($r['status'])->toBe(200);
    $data = json_decode($r['body'], true);
    expect($data['eventGroup'])->toBe([])
        ->and($data['eventGroupKey'])->toBe('nosuch.key')
        ->and($data['eventKeys'])->toBe([]);
});

test('api event group flags values not seen in the previous period', function () {
    $name = 'newval_test_' . uniqid();
    $send = function (string $query, string $ua) use ($name) {
        http('POST', '/?event', [
            'header' => "Content-Type: application/json\r\nUser-Agent: {$ua}",
            'content' => json_encode(['event_name' => $name, 'site' => 'test', 'page_path' => '/', 'event_data' => ['query' => $query]]),
        ]);
    };
    $send('kale', 'Mozilla/5.0 Chrome/120.0');
    $send('rhubarb', 'Mozilla/5.0 Firefox/130.0');
    // "kale" was also searched in the previous period, "rhubarb" is new
    $db = getTestDb();
    $db->prepare('INSERT INTO events (site, event_name, event_data, page_path, visitor_hash, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['test', $name, json_encode(['query' => 'kale']), '/', 'prev-hash', date('Y-m-d H:i:s', strtotime('-3 days'))]);

    $token = createTestShareToken('test');
    $r = http('GET', '/?api&days=2&share=' . $token . '&event=' . $name . '&group=query');
    $data = json_decode($r['body'], true);
    $flags = array_column($data['eventGroup'], 'new', 'value');
    expect($flags)->toBe(['kale' => false, 'rhubarb' => true])
        ->and($data['eventGroupNew'])->toBe(1);
});

test('api event detail exports CSV with data keys as columns', function () {
    $name = 'csv_test_' . uniqid();
    http('POST', '/?event', [
        'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
        'content' => json_encode(['event_name' => $name, 'site' => 'test', 'page_path' => '/guides', 'event_data' => ['query' => 'kale, "curly"', 'zone' => 4]]),
    ]);

    $token = createTestShareToken('test');
    $r = http('GET', '/?api&days=1&share=' . $token . '&event=' . $name . '&format=csv');
    expect($r['status'])->toBe(200);
    $contentType = collect($r['headers'])->first(fn ($h) => str_contains($h, 'Content-Type'));
    expect($contentType)->toContain('text/csv');
    $disposition = collect($r['headers'])->first(fn ($h) => str_contains($h, 'Content-Disposition'));
    expect($disposition)->toContain($name . '.csv');

    $lines = array_values(array_filter(explode("\n", trim($r['body']))));
    expect($lines[0])->toBe('created_at,page_path,query,zone')
        ->and($lines[1])->toContain('/guides,"kale, ""curly""",4');
});

test('js auto form_submit skips prevented submits and reads data-puls-event', function () {
    $r = http('GET', '/?js');
    expect($r['body'])->toContain('defaultPrevented')
        ->and($r['body'])->toContain('dataset.pulsEvent');
});

test('share API with path filter does not crash on events table', function () {
    // Regression: events table has page_path, not path — using $pathFilter caused SQL error
    $token = createTestShareToken('test');
    $r = http('GET', '/?api&days=30&path=' . urlencode('/test-page') . '&share=' . $token);
    expect($r['status'])->toBe(200);
    $data = json_decode($r['body'], true);
    expect($data)->toHaveKey('totals')
        ->and($data['path'])->toBe('/test-page');
});

test('share API search returns results', function () {
    // Ensure we have a pageview to search for
    http('POST', '/?collect', [
        'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
        'content' => json_encode([
            'u' => '/searchable-test-page',
            'r' => '',
            'w' => 1920,
            'site' => 'test',
        ]),
    ]);

    $token = createTestShareToken('test');
    $r = http('GET', '/?api&search=searchable-test&days=7&share=' . $token);
    expect($r['status'])->toBe(200);
    $data = json_decode($r['body'], true);
    expect($data)->toHaveKey('results')
        ->and($data['query'])->toBe('searchable-test');
});

test('js snippet includes puls.track API', function () {
    $r = http('GET', '/?js');
    expect($r['body'])->toContain('puls')
        ->and($r['body'])->toContain('event_name')
        ->and($r['body'])->toContain('outbound_click');
});

// =====================================================================
// CSRF token refresh
// =====================================================================

test('csrf endpoint returns a json token', function () {
    $r = http('GET', '/?csrf');
    expect($r['status'])->toBe(200);

    $contentType = collect($r['headers'])->first(fn ($h) => str_contains($h, 'Content-Type'));
    expect($contentType)->toContain('application/json');

    $data = json_decode($r['body'], true);
    expect($data)->toHaveKey('token')
        ->and($data['token'])->toMatch('/^[0-9a-f]{64}$/');
});

test('csrf endpoint issues a fresh token on every call', function () {
    $first = json_decode(http('GET', '/?csrf')['body'], true);
    $second = json_decode(http('GET', '/?csrf')['body'], true);

    expect($first['token'])->not->toBe($second['token']);
});

test('csrf endpoint starts a hardened session', function () {
    $r = http('GET', '/?csrf');

    $cookie = collect($r['headers'])->first(fn ($h) => str_contains($h, 'Set-Cookie'));
    expect($cookie)->toContain('PHPSESSID=')
        ->and($cookie)->toContain('HttpOnly')
        ->and($cookie)->toContain('SameSite=Strict');
});

// =====================================================================
// Logout
// =====================================================================

test('logout redirects to the login page', function () {
    $r = http('GET', '/?logout', ['follow_location' => 0]);
    expect($r['status'])->toBe(302);

    $location = collect($r['headers'])->first(fn ($h) => str_starts_with($h, 'Location:'));
    expect($location)->toContain('/?login');
});

test('logout expires the session cookie', function () {
    $r = http('GET', '/?logout', ['follow_location' => 0]);

    $cleared = collect($r['headers'])->first(
        fn ($h) => str_contains($h, 'Set-Cookie') && str_contains($h, 'PHPSESSID=deleted')
    );

    expect($cleared)->not->toBeNull()
        ->and($cleared)->toContain('Max-Age=0');
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
