<?php

test('detects browsers', function (string $ua, string $expected) {
    expect(detect_browser($ua))->toBe($expected);
})->with([
    ['Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/120.0 Safari/537.36', 'Chrome'],
    ['Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 Safari/605.1.15', 'Safari'],
    ['Mozilla/5.0 (Windows; rv:120.0) Gecko/20100101 Firefox/120.0', 'Firefox'],
    ['Mozilla/5.0 (Windows) AppleWebKit/537.36 Chrome/120.0 Edg/120.0', 'Edge'],
    ['Mozilla/5.0 (compatible; SomeRandomAgent/1.0)', 'Övrigt'],
]);
