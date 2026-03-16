<?php

test('strips trailing slash', function () {
    expect(normalize_path('/about/'))->toBe('/about');
});

test('keeps root slash', function () {
    expect(normalize_path('/'))->toBe('/');
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
    $path = '/odlingsguiden/?utm_source=ig&utm_medium=social&utm_content=link_in_bio&fbclid=PAdGRleAQc';
    expect(normalize_path($path))->toBe('/odlingsguiden');
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

test('strips fbclid with utm params from subpath', function () {
    $path = '/odlingsguiden/?fbclid=IwZXh0bgNhZW0CMTEAc3J0YwZhcHBfaWQKNjYyODU2ODM3OQABHlijNEh0JKXI85QilabUiYJE_XInh2gPyfjOV8-pqiZqcOCuRUY7JhN5gnNl_aem_ZwpzPSX_ONy9Ei9syt8R5A';
    expect(normalize_path($path))->toBe('/odlingsguiden');
});
