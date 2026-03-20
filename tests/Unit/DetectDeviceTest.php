<?php

test('detects devices by width', function (int $width, string $expected) {
    expect(detect_device($width))->toBe($expected);
})->with([
    [0, 'Unknown'],
    [375, 'Mobile'],
    [768, 'Tablet'],
    [1024, 'Desktop'],
    [1920, 'Desktop'],
]);
