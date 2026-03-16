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
        ->and($result['category'])->toBe('Sökmotor');
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

test('returns null for regular browsers', function () {
    expect(detect_bot('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120.0'))
        ->toBeNull();
});
