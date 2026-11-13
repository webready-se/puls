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

test('keeps data in the site root on a Forge site with a user of its own (website isolation)', function () {
    // Forge's zero-downtime layout: releases/<id> and a current link beside them, under any user's home
    $site = sys_get_temp_dir() . '/puls-resolve-' . uniqid() . '/home/sitebuilder/puls.example.com';
    mkdir($site . '/releases/82922431', 0777, true);
    symlink($site . '/releases/82922431', $site . '/current');
    register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg(dirname($site, 3))));

    expect(resolve_path('data/puls.sqlite', $site . '/releases/82922431'))->toBe($site . '/data/puls.sqlite')
        ->and(resolve_path('users.json', $site . '/releases/82922431'))->toBe($site . '/users.json');
});

test('a folder named releases without a current link beside it is not a Forge site', function () {
    $dir = sys_get_temp_dir() . '/puls-resolve-' . uniqid() . '/releases/2';
    mkdir($dir, 0777, true);
    register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg(dirname($dir, 2))));

    expect(resolve_path('data/puls.sqlite', $dir))->toBe($dir . '/data/puls.sqlite');
});
