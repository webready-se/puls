<?php

test('groups facebook domains', function (string $host) {
    expect(normalize_referrer($host))->toBe('Facebook');
})->with([
    'm.facebook.com',
    'l.facebook.com',
    'lm.facebook.com',
    'www.facebook.com',
    'facebook.com',
]);

test('groups instagram domains', function (string $host) {
    expect(normalize_referrer($host))->toBe('Instagram');
})->with([
    'l.instagram.com',
    'www.instagram.com',
    'instagram.com',
]);

test('groups twitter/x domains', function (string $host) {
    expect(normalize_referrer($host))->toBe('Twitter/X');
})->with([
    't.co',
    'x.com',
    'www.x.com',
]);

test('groups google domains', function () {
    expect(normalize_referrer('www.google.com'))->toBe('Google');
    expect(normalize_referrer('www.google.se'))->toBe('Google');
});

test('passes through unknown domains', function () {
    expect(normalize_referrer('example.com'))->toBe('example.com');
    expect(normalize_referrer('officeoutlaw.com'))->toBe('officeoutlaw.com');
});

test('decodes punycode domains', function () {
    if (!function_exists('idn_to_utf8')) {
        $this->markTestSkipped('intl extension required');
    }
    expect(normalize_referrer('xn--snittrnta-02a.se'))->toBe('snittränta.se');
});
