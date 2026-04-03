<?php

test('detects country from Accept-Language with region', function (string $header, string $expected) {
    expect(detect_country($header))->toBe($expected);
})->with([
    ['sv-SE,sv;q=0.9,en-US;q=0.8', 'SE'],
    ['en-US,en;q=0.9', 'US'],
    ['en-GB,en;q=0.8', 'GB'],
    ['de-AT,de;q=0.9', 'AT'],
    ['fr-CA,fr;q=0.8', 'CA'],
    ['pt-BR,pt;q=0.9', 'BR'],
]);

test('detects country from bare language code', function (string $header, string $expected) {
    expect(detect_country($header))->toBe($expected);
})->with([
    ['sv', 'SE'],
    ['da', 'DK'],
    ['nb', 'NO'],
    ['fi', 'FI'],
    ['ja', 'JP'],
    ['ko', 'KR'],
]);

test('returns null for unmapped bare language', function () {
    expect(detect_country('en'))->toBeNull();
});
