<?php

/**
 * Tests for sites:group and sites:ungroup, and for the group in sites:list,
 * sites:rename and sites:remove.
 */

require_once __DIR__ . '/../Support/helpers.php';

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/puls_groups_test_' . uniqid();
    mkdir($this->tmpDir);
    $this->dbPath = $this->tmpDir . '/test.sqlite';
    $this->usersFile = $this->tmpDir . '/users.json';
    file_put_contents($this->usersFile, '{}');
    $db = createCliTestDb($this->dbPath);
    $stmt = $db->prepare('INSERT INTO pageviews (site, path, visitor_hash) VALUES (?, ?, ?)');
    foreach (['shop-a.example', 'shop-b.example', 'blog.example'] as $i => $site) {
        $stmt->execute([$site, '/', 'hash-' . $i]);
    }
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->tmpDir));
});

function groupRows(string $dbPath): array
{
    $db = new PDO('sqlite:' . $dbPath);
    return $db->query('SELECT site, group_name FROM site_groups ORDER BY site')->fetchAll(PDO::FETCH_KEY_PAIR);
}

it('puts several sites in a group in one command, creating the table on an unmigrated database', function () {
    $result = runCli($this->tmpDir, 'sites:group', ['Shops', 'shop-a.example', 'shop-b.example']);
    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toContain('shop-a.example → Shops', 'shop-b.example → Shops', '2 sites in \'Shops\'.')
        ->and(groupRows($this->dbPath))->toBe(['shop-a.example' => 'Shops', 'shop-b.example' => 'Shops']);
});

it('moves a site to another group and says where it was', function () {
    runCli($this->tmpDir, 'sites:group', ['Shops', 'shop-a.example']);
    $result = runCli($this->tmpDir, 'sites:group', ['Archive', 'shop-a.example']);
    expect($result['output'])->toContain("shop-a.example → Archive (was in 'Shops')")
        ->and(groupRows($this->dbPath))->toBe(['shop-a.example' => 'Archive']);
});

it('refuses a site that is not tracked, and changes nothing', function () {
    $result = runCli($this->tmpDir, 'sites:group', ['Shops', 'shop-a.example', 'nope.example']);
    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('Not a tracked site: nope.example')
        ->and(groupRows($this->dbPath))->toBe([]);
});

it('refuses an empty group name', function () {
    $result = runCli($this->tmpDir, 'sites:group', ['  ', 'shop-a.example']);
    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('Group name cannot be empty');
});

it('asks for a group and sites when called without arguments', function () {
    // No group exists yet: a name is asked for, then the sites by number
    $result = runCli($this->tmpDir, 'sites:group', [], "Shops\n1, 2");
    expect($result['output'])->toContain("Sites to put in 'Shops':", '1) blog.example', '2) shop-a.example', '3) shop-b.example')
        ->and(groupRows($this->dbPath))->toBe(['blog.example' => 'Shops', 'shop-a.example' => 'Shops']);
    // With a group in place, it is offered by number and the sites show their group
    $result = runCli($this->tmpDir, 'sites:group', [], "1\n3");
    expect($result['output'])->toContain('1) Shops (2 sites)', '1) blog.example  (Shops)', '3) shop-b.example')
        ->and(groupRows($this->dbPath))->toHaveCount(3);
});

it('takes sites out of their group, and says when a site was in none', function () {
    runCli($this->tmpDir, 'sites:group', ['Shops', 'shop-a.example', 'shop-b.example']);
    $result = runCli($this->tmpDir, 'sites:ungroup', ['shop-a.example', 'blog.example']);
    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toContain("shop-a.example: no longer in 'Shops'", 'blog.example: not in a group')
        ->and(groupRows($this->dbPath))->toBe(['shop-b.example' => 'Shops']);
});

it('lists sites under their group, the ones without a group last', function () {
    runCli($this->tmpDir, 'sites:group', ['Shops', 'shop-a.example', 'shop-b.example']);
    $out = runCli($this->tmpDir, 'sites:list')['output'];
    expect($out)->toMatch('/Shops\n  shop-a\.example.*\n  shop-b\.example.*\nNo group\n  blog\.example/');
    // Without any group the list is flat, as before
    runCli($this->tmpDir, 'sites:ungroup', ['shop-a.example', 'shop-b.example']);
    $out = runCli($this->tmpDir, 'sites:list')['output'];
    expect($out)->not->toContain('No group')->toMatch('/\nblog\.example/');
});

it('keeps the group through a rename and drops it with the site', function () {
    runCli($this->tmpDir, 'sites:group', ['Shops', 'shop-a.example', 'shop-b.example']);
    runCli($this->tmpDir, 'sites:rename', ['shop-a.example', 'store-a.example']);
    expect(groupRows($this->dbPath))->toBe(['shop-b.example' => 'Shops', 'store-a.example' => 'Shops']);
    runCli($this->tmpDir, 'sites:remove', ['shop-b.example'], 'shop-b.example');
    expect(groupRows($this->dbPath))->toBe(['store-a.example' => 'Shops']);
});
