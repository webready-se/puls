<?php

test('detects devices by width', function (int $width, string $expected) {
    expect(detect_device($width))->toBe($expected);
})->with([
    [0, 'Okänd'],
    [375, 'Mobil'],
    [768, 'Surfplatta'],
    [1024, 'Desktop'],
    [1920, 'Desktop'],
]);
