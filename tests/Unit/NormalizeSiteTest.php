<?php

test('keeps ordinary site names', function (string $site) {
    expect(normalize_site($site))->toBe($site);
})->with(['my-site', 'shop.example', 'localhost:8080', 'blog_2', 'my site', 'at&t', 'åäö.se']);

test('strips markup characters', function () {
    expect(normalize_site('<img src=x onerror=alert(document.domain)>'))->toBe('img src=x onerror=alert(document.domain)')
        ->and(normalize_site("a\"b'c`d\\e"))->toBe('abcde');
});

test('strips control characters', function () {
    expect(normalize_site("site\x00\x1b[31m\x7f\n"))->toBe('site[31m');
});

test('truncates to 100 bytes', function () {
    expect(normalize_site(str_repeat('a', 150)))->toBe(str_repeat('a', 100));
});
