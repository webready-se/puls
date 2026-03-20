<?php

test('detects AI bots', function (string $ua, string $name) {
    $result = detect_bot($ua);
    expect($result)->not->toBeNull()
        ->and($result['name'])->toBe($name)
        ->and($result['category'])->toBe('AI');
})->with([
    ['Mozilla/5.0 (compatible; GPTBot/1.0)', 'GPTBot'],
    ['Mozilla/5.0 (compatible; ClaudeBot/1.0)', 'ClaudeBot'],
    ['Mozilla/5.0 (compatible; PerplexityBot/1.0)', 'PerplexityBot'],
    ['Mozilla/5.0 (compatible; Bytespider)', 'Bytespider'],
    ['Meta-ExternalAgent/1.0', 'Meta AI'],
]);

test('detects search engine bots', function (string $ua, string $name) {
    $result = detect_bot($ua);
    expect($result)->not->toBeNull()
        ->and($result['name'])->toBe($name)
        ->and($result['category'])->toBe('Search engine');
})->with([
    ['Mozilla/5.0 (compatible; Googlebot/2.1)', 'Googlebot'],
    ['Mozilla/5.0 (compatible; bingbot/2.0)', 'Bingbot'],
]);

test('detects social bots', function (string $ua, string $name) {
    $result = detect_bot($ua);
    expect($result)->not->toBeNull()
        ->and($result['name'])->toBe($name)
        ->and($result['category'])->toBe('Social');
})->with([
    ['facebookexternalhit/1.1', 'Facebook'],
    ['Twitterbot/1.0', 'Twitterbot'],
    ['LinkedInBot/1.0', 'LinkedInBot'],
]);

test('detects SEO bots', function () {
    $result = detect_bot('Mozilla/5.0 (compatible; AhrefsBot/7.0)');
    expect($result['name'])->toBe('AhrefsBot')
        ->and($result['category'])->toBe('SEO');
});

test('detects monitor bots', function (string $ua, string $name) {
    $result = detect_bot($ua);
    expect($result)->not->toBeNull()
        ->and($result['name'])->toBe($name)
        ->and($result['category'])->toBe('Monitor');
})->with([
    ['UptimeRobot/2.0', 'UptimeRobot'],
    ['Mozilla/5.0 CensysInspect/1.1', 'CensysInspect'],
    ['spatie/laravel-uptime-monitor uptime checker', 'Uptime Checker'],
]);

test('detects automated clients', function (string $ua, string $name) {
    $result = detect_bot($ua);
    expect($result)->not->toBeNull()
        ->and($result['name'])->toBe($name)
        ->and($result['category'])->toBe('Client');
})->with([
    ['axios/1.8.4', 'Axios'],
    ['python-requests/2.31.0', 'Python Requests'],
    ['Go-http-client/2.0', 'Go HTTP'],
    ['curl/8.4.0', 'curl'],
    ['wget/1.21', 'Wget'],
    ['node-fetch/1.0', 'Node Fetch'],
    ['HeadlessChrome/120.0', 'Headless Chrome'],
    ['Scrapy/2.11', 'Scrapy'],
]);

test('returns null for regular browsers', function () {
    expect(detect_bot('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120.0'))
        ->toBeNull();
});
