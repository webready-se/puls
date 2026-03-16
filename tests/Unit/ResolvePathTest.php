<?php

test('returns absolute paths unchanged', function () {
    expect(resolve_path('/home/forge/puls/data/puls.sqlite'))
        ->toBe('/home/forge/puls/data/puls.sqlite');
});

test('resolves relative paths from __DIR__', function () {
    $result = resolve_path('data/puls.sqlite');
    expect($result)->toEndWith('data/puls.sqlite')
        ->and($result[0])->toBe('/');
});
