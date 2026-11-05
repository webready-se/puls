<?php

function releaseTree(): string
{
    $site = sys_get_temp_dir() . '/puls-releases-' . uniqid();
    mkdir($site . '/releases/1', 0777, true);
    mkdir($site . '/releases/2', 0777, true);
    symlink($site . '/releases/2', $site . '/current');
    register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg($site)));
    return realpath($site);
}

test('an old release gets a warning that says where the live one is', function () {
    $site = releaseTree();

    expect(old_release_warning($site . '/releases/1'))->toContain('Old release')->toContain($site . '/current');
});

test('the live release gets no warning', function () {
    $site = releaseTree();

    expect(old_release_warning($site . '/releases/2'))->toBeNull();
});

test('a folder that is not a release gets no warning', function () {
    $site = releaseTree();

    expect(old_release_warning($site))->toBeNull()
        ->and(old_release_warning('/usr/local/puls'))->toBeNull();
});

test('releases without a current link get no warning', function () {
    $site = releaseTree();
    unlink($site . '/current');

    expect(old_release_warning($site . '/releases/1'))->toBeNull();
});

test('php puls warns from an old release and still runs, on stderr so its output stays clean', function () {
    $site = releaseTree();
    foreach ([1, 2] as $release) {
        copy(__DIR__ . '/../../puls', "{$site}/releases/{$release}/puls");
        copy(__DIR__ . '/../../config.php', "{$site}/releases/{$release}/config.php");
    }
    $run = function (string $release, string $streams) use ($site) {
        exec('php ' . escapeshellarg("{$site}/releases/{$release}/puls") . " help {$streams} < /dev/null", $output);
        return implode("\n", $output);
    };

    expect($run('1', '2>&1 >/dev/null'))->toContain('Old release')
        ->and($run('1', '2>/dev/null'))->toContain('share:create')->not->toContain('Old release')
        ->and($run('2', '2>&1 >/dev/null'))->toBe('');
});
