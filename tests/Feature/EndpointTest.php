<?php

beforeAll(function () {
    require_once __DIR__ . '/../Support/helpers.php';
    // Every run gets its own database and users file, so tests never touch the
    // database configured in .env and never see rows left by an earlier run.
    $GLOBALS['test_dir'] = sys_get_temp_dir() . '/puls-feature-' . getmypid() . '-' . uniqid();
    mkdir($GLOBALS['test_dir']);
    $GLOBALS['test_env'] = [
        'DB_PATH' => $GLOBALS['test_dir'] . '/puls.sqlite',
        'USERS_FILE' => $GLOBALS['test_dir'] . '/users.json',
    ];
    $GLOBALS['server_pid'] = startServer(8089, $GLOBALS['test_env']);
});

afterAll(function () {
    stopServer($GLOBALS['server_pid']);
    if (isset($GLOBALS['restricted_server'])) stopServer($GLOBALS['restricted_server']);
    if (isset($GLOBALS['proxy_server'])) stopServer($GLOBALS['proxy_server']);
    exec('rm -rf ' . escapeshellarg($GLOBALS['test_dir']));
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

test('js endpoint is cached for an hour and revalidated with an etag', function () {
    $r = http('GET', '/?js');
    $header = fn ($name) => collect($r['headers'])->first(fn ($h) => stripos($h, "{$name}:") === 0);
    expect($header('Cache-Control'))->toContain('max-age=3600');

    $etag = trim(substr($header('ETag') ?? '', 5));
    expect($etag)->toStartWith('"');

    $same = http('GET', '/?js', ['header' => "If-None-Match: {$etag}"]);
    expect($same['status'])->toBe(304)
        ->and($same['body'])->toBe('');

    // Nginx gzip turns the ETag weak, so browsers send it back as W/"..."
    $weak = http('GET', '/?js', ['header' => "If-None-Match: W/{$etag}"]);
    expect($weak['status'])->toBe(304);

    $changed = http('GET', '/?js', ['header' => 'If-None-Match: "old"']);
    expect($changed['status'])->toBe(200)
        ->and($changed['body'])->toContain('sendBeacon');
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

test('collect strips brid from Meta ad traffic only and keeps media_type', function () {
    $site = 'meta_' . uniqid();
    foreach ([['ig', '/stock?media_type=video&brid=YWdncwE7'], [null, '/library?brid=abc']] as [$source, $u]) {
        http('POST', '/?collect', [
            'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
            'content' => json_encode(['u' => $u, 'r' => '', 'w' => 400, 'site' => $site, 'utm' => $source ? ['source' => $source, 'medium' => 'paid'] : null]),
        ]);
    }
    $stmt = getTestDb()->prepare('SELECT path FROM pageviews WHERE site = ? ORDER BY id');
    $stmt->execute([$site]);
    expect($stmt->fetchAll(PDO::FETCH_COLUMN))->toBe(['/stock?media_type=video', '/library?brid=abc']);
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
    $dbPath = $GLOBALS['test_env']['DB_PATH'];
    // The dev server creates the database on its first request
    if (!file_exists($dbPath)) http('GET', '/?health');

    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

test('dashboard config is injected once, after the page title', function () {
    // The dashboard's own scripts may contain "</title>" (SVG tooltips); only the head's is the anchor
    $shared = http('GET', '/?share=' . createTestShareToken('test'))['body'];
    $dashboard = http('GET', '/', ['header' => restrictedSession()], 8090)['body'];
    foreach (['PULS_SHARE' => $shared, 'PULS_BRANDING' => $dashboard] as $global => $body) {
        expect(substr_count($body, "window.{$global}="))->toBe(1)
            ->and($body)->toContain("</title><script>window.{$global}=");
        $first = strpos($body, '</title>');
        expect(strpos($body, "window.{$global}="))->toBe($first + strlen('</title><script>'));
    }
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

test('api entry pages include bounces that add up to the site bounce rate', function () {
    $site = 'entry-bounce-' . uniqid();
    $db = getTestDb();
    $stmt = $db->prepare('INSERT INTO pageviews (created_at, site, path, visitor_hash) VALUES (?, ?, ?, ?)');
    $now = time();
    $at = fn(int $minutes) => date('Y-m-d H:i:s', $now - 3600 + $minutes * 60);
    // Start page: 4 entries, 3 leave after one page
    foreach (['a', 'b', 'c'] as $v) {
        $stmt->execute([$at(0), $site, '/', $v]);
    }
    $stmt->execute([$at(0), $site, '/', 'd']);
    $stmt->execute([$at(1), $site, '/kale', 'd']);
    // Crop page: 2 entries, both read on
    foreach (['e', 'f'] as $v) {
        $stmt->execute([$at(0), $site, '/kale', $v]);
        $stmt->execute([$at(2), $site, '/', $v]);
    }

    $token = createTestShareToken($site);
    $data = json_decode(http('GET', '/?api&days=1&share=' . $token)['body'], true);
    $byPath = array_column($data['entryPages'], null, 'path');

    expect($byPath['/']['entries'])->toBe(4)
        ->and($byPath['/']['bounces'])->toBe(3)
        ->and($byPath['/']['bounceRate'])->toEqual(75)
        ->and($byPath['/kale']['entries'])->toBe(2)
        ->and($byPath['/kale']['bounces'])->toBe(0)
        ->and($byPath['/kale']['bounceRate'])->toEqual(0);

    // Same definition as the site-wide figure: 3 of 6 visitors bounced
    $bounced = array_sum(array_column($data['entryPages'], 'bounces'));
    expect(round(100 * $bounced / 6, 1))->toEqual($data['bounceRate']);
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

test('api returns up to 50 event names without expand', function () {
    $site = 'events-limit-' . uniqid();
    $db = getTestDb();
    $insert = $db->prepare('INSERT INTO events (site, event_name, event_data, page_path, visitor_hash, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    for ($i = 1; $i <= 55; $i++) {
        $insert->execute([$site, 'event_' . $i, null, '/', 'hash-' . $i, date('Y-m-d H:i:s')]);
    }

    $token = createTestShareToken($site);
    $data = json_decode(http('GET', '/?api&days=1&share=' . $token)['body'], true);
    expect($data['events'])->toHaveCount(50);
    expect($data['eventsTotal'])->toBe(55);

    $data = json_decode(http('GET', '/?api&days=1&share=' . $token . '&expand=events')['body'], true);
    expect($data['events'])->toHaveCount(55);
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

test('api counts conversions for event goals from the events table', function () {
    $name = 'goal_event_' . uniqid();
    foreach (['Mozilla/5.0 Chrome/120.0', 'Mozilla/5.0 Firefox/130.0'] as $ua) {
        http('POST', '/?event', [
            'header' => "Content-Type: application/json\r\nUser-Agent: {$ua}",
            'content' => json_encode(['event_name' => $name, 'site' => 'test', 'page_path' => '/']),
        ]);
    }
    $db = getTestDb();
    $db->prepare('INSERT OR IGNORE INTO goals (site, path, label, type, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute(['test', $name, 'Feedback sent', 'event', date('Y-m-d H:i:s')]);

    try {
        $token = createTestShareToken('test');
        $r = http('GET', '/?api&days=1&share=' . $token);
        $data = json_decode($r['body'], true);
        $goal = collect($data['goals'])->first(fn ($g) => $g['path'] === $name);
        expect($goal)->not->toBeNull()
            ->and($goal['type'])->toBe('event')
            ->and($goal['label'])->toBe('Feedback sent')
            ->and($goal['conversions'])->toBe(2);
    } finally {
        $db->prepare('DELETE FROM goals WHERE site = ? AND path = ?')->execute(['test', $name]);
    }
});

test('goals carry the previous period, a daily series and their target', function () {
    $name = 'goal_glance_' . uniqid();
    $db = getTestDb();
    $event = $db->prepare("INSERT INTO events (site, event_name, page_path, visitor_hash, created_at) VALUES ('test', ?, '/', ?, ?)");
    $at = fn ($ago) => date('Y-m-d 12:00:00', strtotime("-{$ago} days"));
    // Today two visitors (one of them twice), two days ago one; ten days ago, in the previous period, three
    foreach ([['a', 0], ['a', 0], ['b', 0], ['c', 2], ['d', 10], ['e', 10], ['f', 10]] as [$visitor, $ago]) {
        $event->execute([$name, $visitor . $name, $at($ago)]);
    }
    $db->prepare("INSERT INTO goals (site, path, label, type, created_at, target, target_period) VALUES ('test', ?, '', 'event', ?, 10, 'week')")
        ->execute([$name, date('Y-m-d H:i:s')]);

    try {
        $data = json_decode(http('GET', '/?api&days=7&share=' . createTestShareToken('test'))['body'], true);
        $goal = collect($data['goals'])->first(fn ($g) => $g['path'] === $name);
        expect($goal['conversions'])->toBe(3)
            ->and($goal['previousConversions'])->toBe(3)
            ->and($goal['series'])->toBe([0, 0, 0, 0, 1, 0, 2])
            ->and($goal['target'])->toBe(10)
            ->and($goal['targetPeriod'])->toBe('week');
    } finally {
        $db->prepare('DELETE FROM goals WHERE site = ? AND path = ?')->execute(['test', $name]);
    }
});

test('a goal target can be set and cleared on the user\'s sites', function () {
    $cookie = restrictedSession();
    $post = fn (array $body) => http('POST', '/?goal_target', [
        'header' => "Content-Type: application/json\r\n{$cookie}",
        'content' => json_encode($body),
    ], 8090)['status'];
    $path = '/target-' . uniqid();
    $db = getTestDb();
    $db->prepare("INSERT INTO goals (site, path, label, type, created_at) VALUES ('test', ?, '', 'page', ?)")->execute([$path, date('Y-m-d H:i:s')]);
    $target = fn () => $db->query('SELECT target, target_period FROM goals WHERE path = ' . $db->quote($path))->fetch(PDO::FETCH_NUM);

    try {
        expect(http('POST', '/?goal_target', ['header' => 'Content-Type: application/json', 'content' => json_encode(['site' => 'test', 'path' => $path, 'target' => 5])])['status'])->toBe(401)
            ->and($post(['site' => 'not-my-site', 'path' => $path, 'target' => 5]))->toBe(403)
            ->and($post(['site' => 'test', 'path' => $path, 'target' => 25, 'period' => 'month']))->toBe(200)
            ->and($target())->toBe([25, 'month'])
            ->and($post(['site' => 'test', 'path' => $path, 'target' => 3, 'period' => 'decade']))->toBe(200)
            ->and($target())->toBe([3, 'week'])
            ->and($post(['site' => 'test', 'path' => $path, 'target' => 0]))->toBe(200)
            ->and($target())->toBe([null, null]);
    } finally {
        $db->prepare('DELETE FROM goals WHERE path = ?')->execute([$path]);
    }
});

test('across sites a goal shows its target only when one site has that goal', function () {
    $one = '/one-site-' . uniqid();
    $both = '/two-sites-' . uniqid();
    $db = getTestDb();
    $goal = $db->prepare("INSERT INTO goals (site, path, label, type, created_at, target, target_period) VALUES (?, ?, '', 'page', ?, ?, 'week')");
    $goal->execute(['test', $one, date('Y-m-d H:i:s'), 5]);
    $goal->execute(['test', $both, date('Y-m-d H:i:s'), 5]);
    $goal->execute(['other', $both, date('Y-m-d H:i:s'), 8]);

    try {
        $data = json_decode(http('GET', '/?api&days=7', ['header' => restrictedSession('full')], 8090)['body'], true);
        $target = fn ($path) => collect($data['goals'])->first(fn ($g) => $g['path'] === $path)['target'];
        expect($target($one))->toBe(5)
            ->and($target($both))->toBeNull();
    } finally {
        $db->prepare('DELETE FROM goals WHERE path IN (?, ?)')->execute([$one, $both]);
    }
});

test('a user with several sites can load the all-sites view, limited to those sites', function () {
    foreach (['test', 'other', 'not-theirs'] as $site) collectVia($site, '198.51.100.20');
    $r = http('GET', '/?api&days=7', ['header' => restrictedSession('pair')], 8090);
    $data = json_decode($r['body'], true);
    expect($data)->toBeArray()
        ->and(array_column($data['siteOverview'], 'site'))->toContain('test', 'other')->not->toContain('not-theirs');
});

test('across sites a user sees only the goals of the sites they can access', function () {
    $mine = '/mine-' . uniqid();
    $theirs = '/theirs-' . uniqid();
    $db = getTestDb();
    $goal = $db->prepare("INSERT INTO goals (site, path, label, type, created_at) VALUES (?, ?, 'Goal', 'page', ?)");
    $goal->execute(['other', $mine, date('Y-m-d H:i:s')]);
    $goal->execute(['not-theirs', $theirs, date('Y-m-d H:i:s')]);

    try {
        $data = json_decode(http('GET', '/?api&days=7', ['header' => restrictedSession('pair')], 8090)['body'], true);
        expect(array_column($data['goals'], 'path'))->toContain($mine)->not->toContain($theirs);
    } finally {
        $db->prepare('DELETE FROM goals WHERE path IN (?, ?)')->execute([$mine, $theirs]);
    }
});

test('goals table has a type column after migration', function () {
    $cols = array_column(getTestDb()->query('PRAGMA table_info(goals)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    expect($cols)->toContain('type');
});

test('api event funnel counts visitors who did the second event after the first', function () {
    $a = 'funnel_open_' . uniqid();
    $b = 'funnel_sent_' . uniqid();
    $send = function (string $name, string $ua) {
        http('POST', '/?event', [
            'header' => "Content-Type: application/json\r\nUser-Agent: {$ua}",
            'content' => json_encode(['event_name' => $name, 'site' => 'test', 'page_path' => '/']),
        ]);
    };
    // Chrome opens then sends, Firefox only opens, Safari sends without opening
    $send($a, 'Mozilla/5.0 Chrome/120.0');
    $send($a, 'Mozilla/5.0 Firefox/130.0');
    sleep(1);
    $send($b, 'Mozilla/5.0 Chrome/120.0');
    $send($b, 'Mozilla/5.0 Safari/605.1');

    $token = createTestShareToken('test');
    $r = http('GET', '/?api&days=2&share=' . $token . '&event=' . $a . '&then=' . $b);
    expect($r['status'])->toBe(200);
    $f = json_decode($r['body'], true)['funnel'];

    expect($f['then'])->toBe($b)
        ->and($f['started'])->toBe(2)
        ->and($f['converted'])->toBe(1)
        ->and($f['rate'])->toEqual(50)
        ->and($f['previousStarted'])->toBe(0)
        ->and($f['previousRate'])->toBeNull()
        ->and($f['series'])->toHaveCount(2)
        ->and($f['series'][1])->toBe(['date' => date('Y-m-d'), 'started' => 2, 'converted' => 1]);
});

test('api event funnel does not count the second event when it came first', function () {
    $a = 'funnel_a_' . uniqid();
    $b = 'funnel_b_' . uniqid();
    $opts = fn (string $name) => [
        'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
        'content' => json_encode(['event_name' => $name, 'site' => 'test', 'page_path' => '/']),
    ];
    http('POST', '/?event', $opts($b));
    sleep(1);
    http('POST', '/?event', $opts($a));

    $token = createTestShareToken('test');
    $f = json_decode(http('GET', '/?api&days=1&share=' . $token . '&event=' . $a . '&then=' . $b)['body'], true)['funnel'];
    expect($f['started'])->toBe(1)
        ->and($f['converted'])->toBe(0);
});

test('api event drill-down lists other event names for the funnel picker', function () {
    // Own site: the shared test DB accumulates event names across runs
    $site = 'picker-' . uniqid();
    $a = 'picker_a';
    $b = 'picker_b';
    foreach ([$a, $b] as $name) {
        http('POST', '/?event', [
            'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
            'content' => json_encode(['event_name' => $name, 'site' => $site, 'page_path' => '/']),
        ]);
    }
    $token = createTestShareToken($site);
    $data = json_decode(http('GET', '/?api&days=1&share=' . $token . '&event=' . $a)['body'], true);
    expect($data['eventNames'])->toContain($b)
        ->not->toContain($a)
        ->and($data['funnel'])->toBeNull();
});

test('collect and event store a sanitized app version', function () {
    $tag = uniqid();
    $ua = "Mozilla/5.0 VersionTest/{$tag}";
    http('POST', '/?collect', [
        'header' => "Content-Type: application/json\r\nUser-Agent: {$ua}",
        'content' => json_encode(['u' => '/v-' . $tag, 'r' => '', 'w' => 1200, 'site' => 'test', 'v' => "2.6.0<script>"]),
    ]);
    http('POST', '/?event', [
        'header' => "Content-Type: application/json\r\nUser-Agent: {$ua}",
        'content' => json_encode(['event_name' => 'v_' . $tag, 'site' => 'test', 'page_path' => '/', 'app_version' => 'v2.6.0-beta+1']),
    ]);

    $db = getTestDb();
    $pv = $db->prepare('SELECT app_version FROM pageviews WHERE path = ?');
    $pv->execute(['/v-' . $tag]);
    $ev = $db->prepare('SELECT app_version FROM events WHERE event_name = ?');
    $ev->execute(['v_' . $tag]);
    expect($pv->fetchColumn())->toBe('2.6.0script')
        ->and($ev->fetchColumn())->toBe('v2.6.0-beta+1');
});

test('api lists app versions first seen in the period for release markers', function () {
    $version = 'rel-' . uniqid();
    http('POST', '/?collect', [
        'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
        'content' => json_encode(['u' => '/', 'r' => '', 'w' => 1200, 'site' => 'test', 'v' => $version]),
    ]);
    $token = createTestShareToken('test');
    $data = json_decode(http('GET', '/?api&days=7&share=' . $token)['body'], true);
    $found = collect($data['versions'])->first(fn ($v) => $v['version'] === $version);
    expect($found)->toBe(['version' => $version, 'first_seen' => date('Y-m-d')]);
});

test('api event drill-down groups by app version', function () {
    $name = 'vgroup_' . uniqid();
    foreach ([['1.0.0', 'Chrome/120.0'], ['1.0.0', 'Firefox/130.0'], ['1.1.0', 'Safari/605.1']] as [$v, $ua]) {
        http('POST', '/?event', [
            'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 {$ua}",
            'content' => json_encode(['event_name' => $name, 'site' => 'test', 'page_path' => '/', 'app_version' => $v]),
        ]);
    }
    $token = createTestShareToken('test');
    $data = json_decode(http('GET', '/?api&days=1&share=' . $token . '&event=' . $name . '&group=@version')['body'], true);
    expect(array_column($data['eventKeys'], 'key'))->toContain('@version')
        ->and($data['eventGroupKey'])->toBe('@version')
        ->and(array_map(fn ($g) => [$g['value'], $g['count']], $data['eventGroup']))->toBe([['1.0.0', 2], ['1.1.0', 1]]);
});

test('js snippet sends data-version with pageviews and events', function () {
    $body = http('GET', '/?js')['body'];
    expect($body)->toContain('dataset.version')
        ->and($body)->toContain('app_version');
});

test('js snippet can drop query strings with data-query', function () {
    $body = http('GET', '/?js')['body'];
    expect($body)->toContain('dataset.query')
        ->and($body)->toContain("qk==='none'");
});

test('js snippet lets data-path replace the sent path', function () {
    expect(http('GET', '/?js')['body'])->toContain('sc.dataset.path||location.pathname');
});

test('js snippet can collapse numeric path segments with data-collapse-ids', function () {
    $body = http('GET', '/?js')['body'];
    expect($body)->toContain('dataset.collapseIds')
        ->and($body)->toContain("'/:id'");
});

test('js snippet can replace token path segments with data-collapse-tokens', function () {
    $body = http('GET', '/?js')['body'];
    expect($body)->toContain('dataset.collapseTokens')
        ->and($body)->toContain("'/:token'");

    // The browser pattern must match the same segments as sites:clean-paths --tokens
    preg_match('#var tk=/(.+?)/g;#', $body, $m);
    expect($m)->toHaveKey(1);
    $tokens = fn($path) => preg_replace('#' . $m[1] . '#', '/:token', $path);
    expect($tokens('/app/movement/ckx3abc9def0ghi1jkl2mno3p'))->toBe('/app/movement/:token')
        ->and($tokens('/claim/550e8400-e29b-41d4-a716-446655440000/edit'))->toBe('/claim/:token/edit')
        ->and($tokens('/fordonsprogrammet'))->toBe('/fordonsprogrammet')
        ->and($tokens('/volvo-xc60-2024-recharge'))->toBe('/volvo-xc60-2024-recharge')
        ->and($tokens('/lead/1234'))->toBe('/lead/1234');
});

test('api returns saved breakdowns grouped like the drill-down', function () {
    $name = 'saved_bd_' . uniqid();
    foreach ([['kale', 'Chrome/120.0'], ['kale', 'Firefox/130.0'], ['rhubarb', 'Safari/605.1']] as [$q, $ua]) {
        http('POST', '/?event', [
            'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 {$ua}",
            'content' => json_encode(['event_name' => $name, 'site' => 'test', 'page_path' => '/', 'event_data' => ['query' => $q]]),
        ]);
    }
    $db = getTestDb();
    $db->prepare('INSERT INTO breakdowns (site, label, event_name, group_key, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute(['test', 'Missed searches', $name, 'query', date('Y-m-d H:i:s')]);

    try {
        $token = createTestShareToken('test');
        $data = json_decode(http('GET', '/?api&days=1&share=' . $token)['body'], true);
        $bd = collect($data['breakdowns'])->first(fn ($b) => $b['event'] === $name);
        expect($bd['label'])->toBe('Missed searches')
            ->and($bd['group'])->toBe('query')
            ->and($bd['id'])->toBeInt()
            ->and(array_map(fn ($r) => [$r['value'], $r['count'], $r['new']], $bd['rows']))->toBe([['kale', 2, true], ['rhubarb', 1, true]])
            ->and($bd['total'])->toBe(2);
    } finally {
        $db->prepare('DELETE FROM breakdowns WHERE event_name = ?')->execute([$name]);
    }
});

test('saved breakdowns carry their count, the previous count and a daily series', function () {
    $name = 'glance_bd_' . uniqid();
    $db = getTestDb();
    $event = $db->prepare("INSERT INTO events (site, event_name, page_path, event_data, visitor_hash, created_at) VALUES ('test', ?, '/', ?, ?, ?)");
    $at = fn ($ago) => date('Y-m-d 12:00:00', strtotime("-{$ago} days"));
    // Today: twelve values and one event without the key, which no card row counts
    for ($i = 1; $i <= 12; $i++) $event->execute([$name, json_encode(['where' => "v{$i}"]), "h{$i}", $at(0)]);
    $event->execute([$name, json_encode(['other' => 'x']), 'h0', $at(0)]);
    // Two days ago one more; ten days ago, in the previous 7-day period, two
    $event->execute([$name, json_encode(['where' => 'v1']), 'h1', $at(2)]);
    $event->execute([$name, json_encode(['where' => 'v1']), 'h1', $at(10)]);
    $event->execute([$name, json_encode(['where' => 'v2']), 'h2', $at(10)]);
    $db->prepare('INSERT INTO breakdowns (site, label, event_name, group_key, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute(['test', 'Where', $name, 'where', date('Y-m-d H:i:s')]);

    try {
        $data = json_decode(http('GET', '/?api&days=7&share=' . createTestShareToken('test'))['body'], true);
        $bd = collect($data['breakdowns'])->first(fn ($b) => $b['event'] === $name);
        expect($bd['count'])->toBe(13)
            ->and($bd['previousCount'])->toBe(2)
            ->and($bd['series'])->toBe([0, 0, 0, 0, 1, 0, 12])
            ->and($bd['rows'])->toHaveCount(12)
            ->and($bd['total'])->toBe(12);
    } finally {
        $db->prepare('DELETE FROM breakdowns WHERE event_name = ?')->execute([$name]);
    }
});

test('breakdown add, remove and order require auth', function () {
    $opts = [
        'header' => "Content-Type: application/json",
        'content' => json_encode(['site' => 'test', 'label' => 'x', 'event' => 'e', 'group' => 'k', 'ids' => [1]]),
    ];
    expect(http('POST', '/?breakdown_add', $opts)['status'])->toBe(401)
        ->and(http('POST', '/?breakdown_remove', $opts)['status'])->toBe(401)
        ->and(http('POST', '/?breakdown_order', $opts)['status'])->toBe(401);
});

test('saved cards keep the order set for their site, and a new card goes last', function () {
    $cookie = restrictedSession();
    $post = fn (string $endpoint, array $body) => http('POST', $endpoint, [
        'header' => "Content-Type: application/json\r\n{$cookie}",
        'content' => json_encode($body),
    ], 8090);
    $prefix = 'order_' . uniqid() . '_';
    $db = getTestDb();
    $id = fn (string $n) => (int) $db->query("SELECT id FROM breakdowns WHERE event_name = '{$prefix}{$n}'")->fetchColumn();
    $order = function () use ($prefix) {
        $data = json_decode(http('GET', '/?api&days=1&share=' . createTestShareToken('test'))['body'], true);
        $mine = array_filter($data['breakdowns'], fn ($b) => str_starts_with($b['event'], $prefix));
        return array_values(array_map(fn ($b) => substr($b['event'], -1), $mine));
    };
    foreach (['a', 'b', 'c'] as $n) $post('/?breakdown_add', ['site' => 'test', 'event' => $prefix . $n, 'group' => 'k']);
    // A card on another site, whose id in the list must not move it
    $db->prepare("INSERT INTO breakdowns (site, label, event_name, group_key, created_at) VALUES ('other', '', ?, 'k', ?)")->execute([$prefix . 'x', date('Y-m-d H:i:s')]);

    try {
        expect($order())->toBe(['a', 'b', 'c']);
        expect($post('/?breakdown_order', ['site' => 'test', 'ids' => [$id('c'), $id('x'), $id('a'), $id('b')]])['status'])->toBe(200)
            ->and($order())->toBe(['c', 'a', 'b'])
            ->and((int) $db->query("SELECT position FROM breakdowns WHERE event_name = '{$prefix}x'")->fetchColumn())->toBe(0);
        $post('/?breakdown_add', ['site' => 'test', 'event' => $prefix . 'd', 'group' => 'k']);
        expect($order())->toBe(['c', 'a', 'b', 'd']);
    } finally {
        $db->prepare('DELETE FROM breakdowns WHERE event_name LIKE ?')->execute([$prefix . '%']);
    }
});

/**
 * Log in on a second dev server that reads a throwaway users file: by default as a
 * user restricted to the "test" site, as "pair" with "test" and "other", or as "full"
 * with access to every site.
 * Returns the session cookie header line.
 */
function restrictedSession(string $user = 'limited'): string
{
    if (!isset($GLOBALS['restricted_server'])) {
        $file = $GLOBALS['test_dir'] . '/restricted-users.json';
        file_put_contents($file, json_encode([
            'limited' => ['password' => password_hash('limited-pass', PASSWORD_BCRYPT, ['cost' => 4]), 'sites' => ['test']],
            'pair' => ['password' => password_hash('pair-pass', PASSWORD_BCRYPT, ['cost' => 4]), 'sites' => ['test', 'other']],
            'full' => ['password' => password_hash('full-pass', PASSWORD_BCRYPT, ['cost' => 4]), 'sites' => []],
        ]));
        $GLOBALS['restricted_server'] = startServer(8090, ['USERS_FILE' => $file, 'DB_PATH' => $GLOBALS['test_env']['DB_PATH']]);
    }
    $cookieFrom = function (array $headers): ?string {
        $cookie = null;
        foreach ($headers as $h) {
            if (preg_match('/^Set-Cookie:\s*([^=]+=[^;]+)/i', $h, $m)) $cookie = $m[1];
        }
        return $cookie;
    };
    $r = http('GET', '/?csrf', [], 8090);
    $cookie = $cookieFrom($r['headers']);
    $token = json_decode($r['body'], true)['token'];
    $r = http('POST', '/?login', [
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nCookie: {$cookie}",
        'content' => http_build_query(['_login' => 1, '_token' => $token, 'username' => $user, 'password' => $user . '-pass']),
    ], 8090);
    return 'Cookie: ' . ($cookieFrom($r['headers']) ?? $cookie);
}

test('goal add and remove are limited to the user\'s sites', function () {
    $cookie = restrictedSession();
    $post = fn (string $endpoint, string $site) => http('POST', $endpoint, [
        'header' => "Content-Type: application/json\r\n{$cookie}",
        'content' => json_encode(['site' => $site, 'path' => '/restricted-goal-test']),
    ], 8090)['status'];

    try {
        expect($post('/?goal_add', 'not-my-site'))->toBe(403)
            ->and($post('/?goal_remove', 'not-my-site'))->toBe(403)
            ->and($post('/?goal_add', 'test'))->toBe(200);
    } finally {
        getTestDb()->prepare('DELETE FROM goals WHERE path = ?')->execute(['/restricted-goal-test']);
    }
});

test('breakdown endpoints are limited to the user\'s sites', function () {
    $cookie = restrictedSession();
    $status = http('POST', '/?breakdown_add', [
        'header' => "Content-Type: application/json\r\n{$cookie}",
        'content' => json_encode(['site' => 'not-my-site', 'event' => 'e', 'group' => 'k']),
    ], 8090)['status'];
    expect($status)->toBe(403);
    $status = http('POST', '/?breakdown_order', [
        'header' => "Content-Type: application/json\r\n{$cookie}",
        'content' => json_encode(['site' => 'not-my-site', 'ids' => [1]]),
    ], 8090)['status'];
    expect($status)->toBe(403);
});

test('annotation endpoints require auth', function () {
    $opts = [
        'header' => "Content-Type: application/json",
        'content' => json_encode(['site' => 'test', 'date' => date('Y-m-d'), 'label' => 'x', 'id' => 1]),
    ];
    expect(http('POST', '/?annotation_add', $opts)['status'])->toBe(401)
        ->and(http('POST', '/?annotation_update', $opts)['status'])->toBe(401)
        ->and(http('POST', '/?annotation_remove', $opts)['status'])->toBe(401);
});

test('annotations can be added, relabeled and removed on the user\'s sites', function () {
    $cookie = restrictedSession();
    $post = fn (string $endpoint, array $body) => http('POST', $endpoint, [
        'header' => "Content-Type: application/json\r\n{$cookie}",
        'content' => json_encode($body),
    ], 8090);
    $label = 'film ' . uniqid();
    $db = getTestDb();

    try {
        expect($post('/?annotation_add', ['site' => 'not-my-site', 'date' => '2026-10-03', 'label' => $label])['status'])->toBe(403)
            ->and($post('/?annotation_add', ['site' => 'test', 'date' => 'yesterday', 'label' => $label])['status'])->toBe(400)
            ->and($post('/?annotation_add', ['site' => 'test', 'date' => '2026-10-03', 'end_date' => '2026-10-01', 'label' => $label])['status'])->toBe(400)
            ->and($post('/?annotation_add', ['site' => 'test', 'date' => '2026-10-03', 'label' => '  '])['status'])->toBe(400);

        $r = $post('/?annotation_add', ['site' => 'test', 'date' => '2026-10-03', 'end_date' => '2026-10-09', 'label' => $label . str_repeat('x', 200)]);
        expect($r['status'])->toBe(200);
        $id = json_decode($r['body'], true)['id'];
        $row = $db->query('SELECT * FROM annotations WHERE id = ' . (int) $id)->fetch(PDO::FETCH_ASSOC);
        expect($row['site'])->toBe('test')
            ->and($row['date'])->toBe('2026-10-03')
            ->and($row['end_date'])->toBe('2026-10-09')
            ->and(mb_strlen($row['label']))->toBe(100);

        // Another site's id is not reachable through a site the user has
        expect($post('/?annotation_update', ['site' => 'not-my-site', 'id' => $id, 'label' => 'nope'])['status'])->toBe(403);
        $db->exec("UPDATE annotations SET site = 'other-site' WHERE id = " . (int) $id);
        $post('/?annotation_update', ['site' => 'test', 'id' => $id, 'label' => 'nope']);
        $post('/?annotation_remove', ['site' => 'test', 'id' => $id]);
        expect($db->query('SELECT label FROM annotations WHERE id = ' . (int) $id)->fetchColumn())->not->toBe('nope');
        $db->exec("UPDATE annotations SET site = 'test' WHERE id = " . (int) $id);

        expect($post('/?annotation_update', ['site' => 'test', 'id' => $id, 'label' => 'Film on Facebook and Instagram'])['status'])->toBe(200)
            ->and($db->query('SELECT label FROM annotations WHERE id = ' . (int) $id)->fetchColumn())->toBe('Film on Facebook and Instagram');

        expect($post('/?annotation_remove', ['site' => 'test', 'id' => $id])['status'])->toBe(200)
            ->and($db->query('SELECT COUNT(*) FROM annotations WHERE id = ' . (int) $id)->fetchColumn())->toBe(0);
    } finally {
        $db->prepare('DELETE FROM annotations WHERE label LIKE ?')->execute([$label . '%']);
    }
});

test('api returns annotations that overlap the period, scoped to the site', function () {
    $site = 'annotations-' . uniqid();
    $db = getTestDb();
    $insert = $db->prepare('INSERT INTO annotations (site, date, end_date, label, created_at) VALUES (?, ?, ?, ?, ?)');
    $day = fn (int $ago) => date('Y-m-d', strtotime("-{$ago} days"));
    $insert->execute([$site, $day(2), null, 'single day', date('Y-m-d H:i:s')]);
    $insert->execute([$site, $day(10), $day(5), 'campaign into the period', date('Y-m-d H:i:s')]);
    $insert->execute([$site, $day(20), $day(10), 'before the period', date('Y-m-d H:i:s')]);
    $insert->execute([$site . '-other', $day(2), null, 'other site', date('Y-m-d H:i:s')]);

    $token = createTestShareToken($site);
    $data = json_decode(http('GET', '/?api&days=7&share=' . $token)['body'], true);
    expect(array_column($data['annotations'], 'label'))->toBe(['campaign into the period', 'single day'])
        ->and($data['annotations'][0])->toHaveKeys(['id', 'date', 'end_date', 'label'])
        ->and($data['annotations'][0]['end_date'])->toBe($day(5))
        ->and($data['annotations'][1]['end_date'])->toBeNull();

    // A custom range that ends before the single day leaves it out
    $data = json_decode(http('GET', '/?api&from=' . $day(9) . '&to=' . $day(3) . '&share=' . $token)['body'], true);
    expect(array_column($data['annotations'], 'label'))->toBe(['campaign into the period']);
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

test('login page redirects to the dashboard when already logged in', function () {
    $r = http('GET', '/?login', ['header' => restrictedSession(), 'follow_location' => 0], 8090);
    expect($r['status'])->toBe(302);

    $location = collect($r['headers'])->first(fn ($h) => str_starts_with($h, 'Location:'));
    expect($location)->toBe('Location: /');
});

/**
 * Post a login with a wrong password in a fresh session, $times times in a row.
 * Returns the response to the last attempt.
 */
function failedLogins(int $times): array
{
    restrictedSession(); // starts the server with a known user
    $r = http('GET', '/?csrf', [], 8090);
    preg_match('/Set-Cookie:\s*([^=]+=[^;]+)/i', implode("\n", $r['headers']), $m);
    $cookie = $m[1];
    $token = json_decode($r['body'], true)['token'];
    for ($i = 0; $i < $times; $i++) {
        $r = http('POST', '/?login', [
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nCookie: {$cookie}",
            'content' => http_build_query(['_login' => 1, '_token' => $token, 'username' => 'limited', 'password' => 'wrong']),
        ], 8090);
        if (preg_match('/name="_token" value="([^"]+)"/', $r['body'], $t)) $token = $t[1];
    }
    return $r;
}

test('failed login shows the login form with an error', function () {
    $r = failedLogins(1);
    expect($r['status'])->toBe(200)
        ->and($r['body'])->toContain('name="_login"')
        ->and($r['body'])->toContain('Invalid username or password');
});

test('lockout starts after max_login_attempts failed logins, not before', function () {
    expect(failedLogins(5)['body'])->toContain('Invalid username or password')
        ->and(failedLogins(6)['body'])->toContain('Too many failed attempts');
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

// =====================================================================
// Excluded IPs and the per-browser opt-out
// =====================================================================

test('collect and event drop hits from an excluded IP on that site only', function () {
    $db = getTestDb();
    // The dev server sees the test client as either loopback address
    $db->exec("INSERT INTO excluded_ips (site, ip, created_at) VALUES ('excluded-site', '127.0.0.1', datetime('now')), ('excluded-site', '::1', datetime('now'))");

    $post = fn (string $endpoint, array $body) => http('POST', $endpoint, [
        'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
        'content' => json_encode($body),
    ]);
    foreach (['excluded-site', 'other-site'] as $site) {
        expect($post('/?collect', ['u' => '/x', 'r' => '', 'w' => 1920, 'site' => $site])['status'])->toBe(204);
        expect($post('/?event', ['event_name' => 'excluded_test', 'site' => $site])['status'])->toBe(204);
    }

    $count = fn (string $sql) => (int) $db->query($sql)->fetchColumn();
    expect($count("SELECT COUNT(*) FROM pageviews WHERE site = 'excluded-site'"))->toBe(0)
        ->and($count("SELECT COUNT(*) FROM events WHERE site = 'excluded-site'"))->toBe(0)
        ->and($count("SELECT COUNT(*) FROM pageviews WHERE site = 'other-site'"))->toBe(1)
        ->and($count("SELECT COUNT(*) FROM events WHERE site = 'other-site'"))->toBe(1);
});

test('an excluded range for all sites drops hits on every site', function () {
    $db = getTestDb();
    $db->exec("INSERT INTO excluded_ips (site, ip, created_at) VALUES ('*', '127.0.0.0/8', datetime('now')), ('*', '::1/128', datetime('now'))");

    http('POST', '/?collect', [
        'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 Chrome/120.0",
        'content' => json_encode(['u' => '/x', 'r' => '', 'w' => 1920, 'site' => 'any-site-excluded']),
    ]);
    $db->exec("DELETE FROM excluded_ips WHERE site = '*'");

    expect((int) $db->query("SELECT COUNT(*) FROM pageviews WHERE site = 'any-site-excluded'")->fetchColumn())->toBe(0);
});

test('excluded IP endpoints require auth', function () {
    $opts = ['header' => "Content-Type: application/json", 'content' => json_encode(['site' => 'test', 'ip' => '81.2.3.4'])];
    expect(http('GET', '/?excluded&site=test')['status'])->toBe(401)
        ->and(http('POST', '/?exclude_add', $opts)['status'])->toBe(401)
        ->and(http('POST', '/?exclude_remove', $opts)['status'])->toBe(401);
});

test('excluded IPs can be listed, added and removed on the user\'s sites', function () {
    $cookie = restrictedSession();
    $post = fn (string $endpoint, array $body) => http('POST', $endpoint, [
        'header' => "Content-Type: application/json\r\n{$cookie}",
        'content' => json_encode($body),
    ], 8090);
    $list = fn () => json_decode(http('GET', '/?excluded&site=test', ['header' => $cookie], 8090)['body'], true);
    $db = getTestDb();

    try {
        expect(http('GET', '/?excluded&site=not-my-site', ['header' => $cookie], 8090)['status'])->toBe(403)
            ->and($post('/?exclude_add', ['site' => 'not-my-site', 'ip' => '81.2.3.4'])['status'])->toBe(403)
            ->and($post('/?exclude_add', ['site' => '*', 'ip' => '81.2.3.4'])['status'])->toBe(403)
            ->and($post('/?exclude_add', ['site' => 'test', 'ip' => 'not-an-ip'])['status'])->toBe(400)
            ->and($post('/?exclude_add', ['site' => 'test', 'ip' => '81.2.3.0/33'])['status'])->toBe(400);

        // The caller's own address, as the tracker sees it (the dev server sees loopback)
        $data = $list();
        expect($data['ip'])->toBeIn(['127.0.0.1', '::1'])
            ->and($data['ip_excluded'])->toBeFalse();

        // Stored in the same canonical form as the CLI's exclude:add
        $r = $post('/?exclude_add', ['site' => 'test', 'ip' => ' 2001:DB8:0::/48 ', 'label' => 'Office']);
        expect($r['status'])->toBe(200)
            ->and(json_decode($r['body'], true)['ip'])->toBe('2001:db8::/48');
        expect($post('/?exclude_add', ['site' => 'test', 'ip' => $data['ip']])['status'])->toBe(200);
        $db->exec("INSERT INTO excluded_ips (site, ip, created_at) VALUES ('*', '203.0.113.0/24', datetime('now')), ('other-site', '198.51.100.7', datetime('now'))");

        $data = $list();
        expect($data['ip_excluded'])->toBeTrue()
            ->and(array_column($data['excluded'], 'ip'))->toBe(['2001:db8::/48', $data['ip'], '203.0.113.0/24'])
            ->and($data['excluded'][0]['label'])->toBe('Office')
            ->and($data['excluded'][2]['site'])->toBe('*');

        // The all-sites rows belong to users with every site
        expect($post('/?exclude_remove', ['site' => '*', 'ip' => '203.0.113.0/24'])['status'])->toBe(403);

        expect($post('/?exclude_remove', ['site' => 'test', 'ip' => '2001:db8:0::/48'])['status'])->toBe(200)
            ->and($post('/?exclude_remove', ['site' => 'test', 'ip' => $data['ip']])['status'])->toBe(200)
            ->and(array_column($list()['excluded'], 'ip'))->toBe(['203.0.113.0/24']);
    } finally {
        $db->exec("DELETE FROM excluded_ips WHERE site IN ('test', '*', 'other-site')");
    }
});

test('a user with every site can change the all-sites excluded IPs', function () {
    $cookie = restrictedSession('full');
    $post = fn (string $endpoint) => http('POST', $endpoint, [
        'header' => "Content-Type: application/json\r\n{$cookie}",
        'content' => json_encode(['site' => '*', 'ip' => '203.0.113.9']),
    ], 8090)['status'];
    $count = fn () => (int) getTestDb()->query("SELECT COUNT(*) FROM excluded_ips WHERE site = '*' AND ip = '203.0.113.9'")->fetchColumn();

    expect($post('/?exclude_add'))->toBe(200)
        ->and($count())->toBe(1)
        ->and($post('/?exclude_remove'))->toBe(200)
        ->and($count())->toBe(0);
});

test('js snippet lets a browser opt out with #puls-ignore and back in with #puls-track', function () {
    $r = http('GET', '/?js');
    expect($r['body'])->toContain("'#puls-ignore'")
        ->and($r['body'])->toContain("'#puls-track'")
        ->and($r['body'])->toContain("localStorage.getItem('puls_ignore')")
        ->and($r['body'])->toContain('if(!name||off())return');
});

// =====================================================================
// Visitor IP behind a reverse proxy (TRUSTED_PROXIES)
// =====================================================================

function distinctVisitors(string $site): int
{
    return (int) getTestDb()->query("SELECT COUNT(DISTINCT visitor_hash) FROM pageviews WHERE site = " . getTestDb()->quote($site))->fetchColumn();
}

function collectVia(string $site, string $forwardedFor, int $port = 8089): void
{
    http('POST', '/?collect', [
        'header' => "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 Chrome/120.0\r\nX-Forwarded-For: {$forwardedFor}",
        'content' => json_encode(['u' => '/', 'r' => '', 'w' => 1920, 'site' => $site]),
    ], $port);
}

test('a forged X-Forwarded-For does not create visitors without trusted proxies', function () {
    collectVia('xff-untrusted', '198.51.100.1');
    collectVia('xff-untrusted', '198.51.100.2');
    expect(distinctVisitors('xff-untrusted'))->toBe(1);
});

test('behind a trusted proxy, visitors are told apart by X-Forwarded-For and can be excluded', function () {
    if (!isset($GLOBALS['proxy_server'])) {
        $GLOBALS['proxy_server'] = startServer(8091, $GLOBALS['test_env'] + ['TRUSTED_PROXIES' => '127.0.0.1,::1']);
    }
    getTestDb()->exec("INSERT INTO excluded_ips (site, ip, created_at) VALUES ('xff-trusted', '198.51.100.3', datetime('now'))");

    collectVia('xff-trusted', '198.51.100.1', 8091);
    collectVia('xff-trusted', '198.51.100.2', 8091);
    collectVia('xff-trusted', '198.51.100.3', 8091);

    expect(distinctVisitors('xff-trusted'))->toBe(2)
        ->and((int) getTestDb()->query("SELECT COUNT(*) FROM pageviews WHERE site = 'xff-trusted'")->fetchColumn())->toBe(2);
});
