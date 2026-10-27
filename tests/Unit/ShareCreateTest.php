<?php

/**
 * Tests for the share:create CLI command.
 */

require_once __DIR__ . '/../Support/helpers.php';

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/puls_share_test_' . uniqid();
    mkdir($this->tmpDir);
    $this->dbPath = $this->tmpDir . '/test.sqlite';
    file_put_contents($this->tmpDir . '/users.json', '{}');
    $db = createCliTestDb($this->dbPath);
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('shop', '/')");
});

afterEach(function () {
    putenv('TIMEZONE');
    exec('rm -rf ' . escapeshellarg($this->tmpDir));
});

it('share:create stamps the link on the configured clock, not SQLite UTC', function () {
    // Kiritimati is UTC+14, far from UTC's clock
    putenv('TIMEZONE=Pacific/Kiritimati');
    $result = runCli($this->tmpDir, 'share:create', ['shop', '--label=Clock', '--expires=2099-01-01'], "\n");
    expect($result['exit'])->toBe(0);

    $created = (new PDO('sqlite:' . $this->dbPath))->query('SELECT created_at FROM share_tokens')->fetchColumn();
    $at = new DateTimeImmutable($created, new DateTimeZone('Pacific/Kiritimati'));
    expect(abs($at->getTimestamp() - time()))->toBeLessThan(60);
});
