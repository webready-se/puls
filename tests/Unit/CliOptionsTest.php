<?php

/**
 * An option a command does not take stops it, with the option and the ones it takes: dropped
 * without a word, it answers a question nobody asked or writes where a dry run was meant. An
 * unknown command exits with 1 too, so a script with a misspelt one does not look like it ran.
 */

require_once __DIR__ . '/../Support/helpers.php';

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/puls_options_test_' . uniqid();
    mkdir($this->tmpDir);
    $this->dbPath = $this->tmpDir . '/test.sqlite';
    file_put_contents($this->tmpDir . '/users.json', '{}');
    $db = createCliTestDb($this->dbPath);
    $db->prepare('INSERT INTO pageviews (site, path, visitor_hash) VALUES (?, ?, ?)')->execute(['shop.example', '/boots?colour=red', 'hash-1']);
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->tmpDir));
});

it('stops a dry run with a typo before it writes', function () {
    $result = runCli($this->tmpDir, 'sites:clean-paths', ['shop.example', '--query', '--dry_run']);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('--dry_run')->toContain('Did you mean --dry-run?');
    $path = (new PDO('sqlite:' . $this->dbPath))->query('SELECT path FROM pageviews')->fetchColumn();
    expect($path)->toBe('/boots?colour=red');
});

it('names the options a command takes', function () {
    $result = runCli($this->tmpDir, 'posts:list', ['shop.example', '--from=2026-10-01']);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('posts:list does not take --from')->toContain('--days=30')
        ->not->toContain('Did you mean');
});

it('says when an option needs a value', function () {
    $result = runCli($this->tmpDir, 'backup', ['--keep', '3']);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('--keep needs a value')->toContain('--keep=7');
    expect(is_dir($this->tmpDir . '/backups'))->toBeFalse();
});

it('says when an option takes no value', function () {
    $result = runCli($this->tmpDir, 'sites:clean-paths', ['shop.example', '--query', '--dry-run=yes']);

    expect($result['exit'])->toBe(1)->and($result['output'])->toContain('--dry-run takes no value');
});

it('says when a command takes no options', function () {
    $result = runCli($this->tmpDir, 'sites:list', ['--all']);

    expect($result['exit'])->toBe(1)->and($result['output'])->toContain('sites:list takes no options');
});

it('takes a single dash as an option too, and points -y to --yes', function () {
    $result = runCli($this->tmpDir, 'posts:remove', ['12', '-y']);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('posts:remove does not take -y. It takes --yes.');
});

it('suggests the closest option when a command takes several', function () {
    $result = runCli($this->tmpDir, 'share:create', ['shop.example', '--expire=never']);

    expect($result['exit'])->toBe(1)->and($result['output'])->toContain('Did you mean --expires?');
});

it('stops an unknown command with the help, and the closest command last', function () {
    $result = runCli($this->tmpDir, 'sites:lst');

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('Usage:')
        ->toEndWith('Unknown command sites:lst. Did you mean sites:list?');
    expect(runCli($this->tmpDir, 'nothing-like-it')['output'])->toEndWith('Unknown command nothing-like-it.');
});

it('shows the help without an error when it is asked for', function () {
    foreach (['help', '--help', '-h'] as $asked) {
        $result = runCli($this->tmpDir, $asked);
        expect($result['exit'])->toBe(0)->and($result['output'])->toContain('Usage:')->not->toContain('Unknown command');
    }
});

it('lets --yes stand anywhere, and runs with the options a command takes', function () {
    expect(runCli($this->tmpDir, 'sites:list', ['--yes'])['exit'])->toBe(0)
        ->and(runCli($this->tmpDir, 'posts:list', ['shop.example', '--days=7'])['exit'])->toBe(0)
        ->and(runCli($this->tmpDir, 'sites:clean-paths', ['shop.example', '--query', '--dry-run'])['exit'])->toBe(0);
});
