<?php

/**
 * Tests for the distinct-site lookup behind the CLI site pickers.
 *
 * The query is a recursive loose index scan rather than SELECT DISTINCT,
 * so the edge cases worth pinning are an empty table (MIN() yields NULL and
 * the recursion must terminate) and ordering/deduplication across many rows.
 *
 * Driven through `sites:remove`, which prints the picker before touching
 * anything. Feeding it "0" aborts on an invalid selection, so no data is
 * ever removed.
 */

require_once __DIR__ . '/../Support/helpers.php';

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/puls_sites_test_' . uniqid();
    mkdir($this->tmpDir);
    $this->dbPath = $this->tmpDir . '/test.sqlite';
    $this->usersFile = $this->tmpDir . '/users.json';
    file_put_contents($this->usersFile, '{}');
});

afterEach(function () {
    @unlink($this->dbPath);
    @unlink($this->usersFile);
    @rmdir($this->tmpDir);
});

function seedSites(string $dbPath, array $sites): void
{
    $db = createCliTestDb($dbPath);
    $stmt = $db->prepare('INSERT INTO pageviews (site, path, visitor_hash) VALUES (?, ?, ?)');
    foreach ($sites as $i => $site) {
        $stmt->execute([$site, '/', 'hash-' . $i]);
    }
}

it('lists distinct sites in alphabetical order', function () {
    // Inserted out of order and with repeats; the picker must show each once.
    seedSites($this->dbPath, [
        'zebra-site', 'alpha-site', 'zebra-site', 'mid-site', 'alpha-site', 'zebra-site',
    ]);

    $result = runCli($this->tmpDir, 'sites:remove', [], "0\n");

    expect($result['output'])->toContain('1) alpha-site')
        ->and($result['output'])->toContain('2) mid-site')
        ->and($result['output'])->toContain('3) zebra-site')
        ->and($result['output'])->toContain('Invalid selection');
});

it('terminates on an empty pageviews table', function () {
    createCliTestDb($this->dbPath);

    $result = runCli($this->tmpDir, 'sites:remove', [], "0\n");

    expect($result['output'])->toContain('No sites tracked yet.')
        ->and($result['exit'])->toBe(1);
});

it('lists a single site exactly once', function () {
    seedSites($this->dbPath, ['only-site', 'only-site', 'only-site']);

    $result = runCli($this->tmpDir, 'sites:remove', [], "0\n");

    expect($result['output'])->toContain('1) only-site')
        ->and($result['output'])->not->toContain('2)');
});

it('orders sites by binary collation, matching the previous DISTINCT query', function () {
    seedSites($this->dbPath, ['Beta', 'alpha', 'Alpha', 'beta']);

    $result = runCli($this->tmpDir, 'sites:remove', [], "0\n");

    // Uppercase sorts before lowercase under SQLite's default BINARY collation.
    expect($result['output'])->toContain("1) Alpha")
        ->and($result['output'])->toContain("2) Beta")
        ->and($result['output'])->toContain("3) alpha")
        ->and($result['output'])->toContain("4) beta");
});
