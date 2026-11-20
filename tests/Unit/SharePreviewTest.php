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
