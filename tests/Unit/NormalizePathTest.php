<?php

test('strips trailing slash', function () {
    expect(normalize_path('/about/'))->toBe('/about');
});

test('keeps root slash', function () {
    expect(normalize_path('/'))->toBe('/');
});

// The script sends a path, but a hand-made or server-side collect can send the whole URL;
// the site is named apart, so the host is dropped instead of becoming the first segment
test('keeps the path and query of a full URL', function () {
    expect(normalize_path('https://shop.example/test'))->toBe('/test')
        ->and(normalize_path('http://shop.example/search?q=boots'))->toBe('/search?q=boots')
        ->and(normalize_path('HTTPS://shop.example:8443/a/b'))->toBe('/a/b');
});

test('a full URL without a path is the root', function () {
    expect(normalize_path('https://shop.example'))->toBe('/')
        ->and(normalize_path('https://shop.example/?q=1'))->toBe('/?q=1');
});

test('a full URL is cleaned as a path is', function () {
    expect(normalize_path('https://shop.example/page/?fbclid=abc#reviews'))->toBe('/page')
        ->and(normalize_path('https://shop.example/page/?fbclid=abc&q=1'))->toBe(normalize_path('/page/?fbclid=abc&q=1'));
});

test('a URL in the query is not taken for the page', function () {
    expect(normalize_path('/go?to=https://example.com/x'))->toStartWith('/go?to=');
});

test('strips fbclid', function () {
    expect(normalize_path('/page?fbclid=abc123'))->toBe('/page');
});

test('strips gclid', function () {
    expect(normalize_path('/page?gclid=abc123'))->toBe('/page');
});

test('strips utm params', function () {
    expect(normalize_path('/page?utm_source=ig&utm_medium=social&utm_campaign=spring'))
        ->toBe('/page');
});

test('keeps non-tracking query params', function () {
    expect(normalize_path('/search?q=hello'))->toBe('/search?q=hello');
});

test('strips tracking but keeps other params', function () {
    $result = normalize_path('/page?q=hello&fbclid=abc&utm_source=ig');
    expect($result)->toBe('/page?q=hello');
});

test('handles path with fbclid and trailing slash', function () {
    expect(normalize_path('/page/?fbclid=abc123'))->toBe('/page');
});

test('handles complex facebook urls', function () {
    $path = '/garden-guide/?utm_source=ig&utm_medium=social&utm_content=link_in_bio&fbclid=PAdGRleAQc';
    expect(normalize_path($path))->toBe('/garden-guide');
});

test('returns path unchanged when no query string', function () {
    expect(normalize_path('/about/team'))->toBe('/about/team');
});

test('strips long facebook fbclid from root', function () {
    $path = '/?fbclid=IwZXh0bgNhZW0CMTEAc3J0YwZhcHBfaWQMMjU2MjgxMDQwNTU4AAEeBPJwZ_0Fg4MJJDooOXw2sP93D4l0S2yb0UBToRxEbLzCB-USUvmSc-PkpgY_aem_MFxrV8gz5m77PZqdNcJHbw';
    expect(normalize_path($path))->toBe('/');
});

test('strips all utm params including term and content', function () {
    $path = '/page?utm_source=ig&utm_medium=social&utm_campaign=spring&utm_term=keyword&utm_content=link_in_bio';
    expect(normalize_path($path))->toBe('/page');
});

test('strips utm_id from Meta Ads', function () {
    expect(normalize_path('/varumarken/volkswagen?utm_id=120240651630670599'))->toBe('/varumarken/volkswagen');
});

test('strips utm_id but keeps other params', function () {
    expect(normalize_path('/page?model=golf&utm_id=120240651630670599'))->toBe('/page?model=golf');
});

test('strips fbclid with utm params from subpath', function () {
    $path = '/garden-guide/?fbclid=IwZXh0bgNhZW0CMTEAc3J0YwZhcHBfaWQKNjYyODU2ODM3OQABHlijNEh0JKXI85QilabUiYJE_XInh2gPyfjOV8-pqiZqcOCuRUY7JhN5gnNl_aem_ZwpzPSX_ONy9Ei9syt8R5A';
    expect(normalize_path($path))->toBe('/garden-guide');
});

test('strips Google Ads params (gad_source, gad_campaignid, gbraid)', function () {
    $path = '/?gad_source=1&gad_campaignid=21124256144&gbraid=0AAAAA9fQM27DWZymnDr5tqd7ZEnWiaCVU';
    expect(normalize_path($path))->toBe('/');
});

test('strips wbraid param', function () {
    expect(normalize_path('/page?wbraid=abc123'))->toBe('/page');
});

test('strips Google Ads params but keeps other params', function () {
    $path = '/dackhotell?size=205&gad_source=1&gad_campaignid=21828512064';
    expect(normalize_path($path))->toBe('/dackhotell?size=205');
});

test('strips _gl cross-domain linker param', function () {
    $path = '/?_gl=1*z2sgze*_gcl_au*MTkxMTk3NjAzOC4xNzczOTQzMjQz';
    expect(normalize_path($path))->toBe('/');
});

test('strips ved Google Search param', function () {
    $path = '/?ved=2ahUKEwjNts6us6yTAxV6FBAIHYSBI2kQgU96BAgdEAQ';
    expect(normalize_path($path))->toBe('/');
});

test('strips extra params only when asked', function () {
    expect(normalize_path('/stock?brid=abc&page=2', ['brid']))->toBe('/stock?page=2')
        ->and(normalize_path('/stock?brid=abc'))->toBe('/stock?brid=abc');
});

test('recognises Meta ad sources', function () {
    expect(is_meta_source('ig'))->toBeTrue()
        ->and(is_meta_source('fb'))->toBeTrue()
        ->and(is_meta_source('msg'))->toBeTrue()
        ->and(is_meta_source('an'))->toBeTrue()
        ->and(is_meta_source('google'))->toBeFalse()
        ->and(is_meta_source(null))->toBeFalse();
});
