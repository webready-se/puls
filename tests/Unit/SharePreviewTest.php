<?php

test('a token names its site, or its groups', function () {
    expect(share_scope_name(['site' => 'shop.example', 'group_names' => null]))->toBe('shop.example')
        ->and(share_scope_name(['site' => '', 'group_names' => '["Shops","Private"]']))->toBe('Shops, Private');
});

test('a changed name or accent is white-label', function () {
    expect(is_white_labelled(['app_name' => 'Puls', 'app_accent' => '#F16272']))->toBeFalse()
        ->and(is_white_labelled(['app_name' => 'Acme Stats', 'app_accent' => '#f16272']))->toBeTrue()
        ->and(is_white_labelled(['app_name' => 'Puls', 'app_accent' => '#1d4ed8']))->toBeTrue();
});

test('the public origin is APP_URL, else the request host when it is a plain host', function () {
    expect(public_origin(['app_url' => 'https://puls.example.com'], ['HTTP_HOST' => 'other.example']))->toBe('https://puls.example.com')
        ->and(public_origin(['app_url' => null], ['HTTP_HOST' => 'puls.example.com', 'HTTPS' => 'on']))->toBe('https://puls.example.com')
        ->and(public_origin(['app_url' => null], ['HTTP_HOST' => 'localhost:8080']))->toBe('http://localhost:8080')
        ->and(public_origin(['app_url' => null], ['HTTP_HOST' => 'puls.example.com', 'HTTPS' => 'off']))->toBe('http://puls.example.com')
        ->and(public_origin(['app_url' => null], ['HTTP_HOST' => 'evil.example/"><x']))->toBeNull()
        ->and(public_origin(['app_url' => null], []))->toBeNull();
});

test('behind a trusted proxy, the scheme comes from X-Forwarded-Proto', function () {
    $config = ['app_url' => null, 'trusted_proxies' => ['10.0.0.0/8']];
    $proxied = ['HTTP_HOST' => 'puls.example.com', 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https'];
    expect(public_origin($config, $proxied))->toBe('https://puls.example.com')
        // Anyone can send the header, so it counts only from a proxy named in TRUSTED_PROXIES
        ->and(public_origin($config, ['REMOTE_ADDR' => '203.0.113.10'] + $proxied))->toBe('http://puls.example.com')
        ->and(public_origin(['app_url' => null, 'trusted_proxies' => []], $proxied))->toBe('http://puls.example.com');
});

test('an install with only an accent of its own gets no image, and is not named Puls', function () {
    $html = '<meta name="description" content="x"><title>Puls</title>';
    $share = ['site' => 'shop.example', 'group_names' => null];
    $accent = share_preview_head($html, ['app_name' => 'Puls', 'app_accent' => '#1d4ed8'], $share, 'https://puls.example.com');
    expect($accent)->not->toContain('og:image')->not->toContain('og:site_name')
        ->toContain('<meta name="twitter:card" content="summary">');
    $named = share_preview_head($html, ['app_name' => 'Acme Stats', 'app_accent' => '#f16272'], $share, 'https://puls.example.com');
    expect($named)->toContain('<meta property="og:site_name" content="Acme Stats">')->not->toContain('og:image');
});
