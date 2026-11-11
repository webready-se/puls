<?php

/**
 * Tests for backup: a checked, compressed copy of the database, for a nightly job that can copy
 * the folder off the server.
 */

require_once __DIR__ . '/../Support/helpers.php';

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/puls_backup_test_' . uniqid();
    mkdir($this->tmpDir);
    $this->dbPath = $this->tmpDir . '/test.sqlite';
    $this->dir = $this->tmpDir . '/elsewhere';
    file_put_contents($this->tmpDir . '/users.json', '{}');
    $db = createCliTestDb($this->dbPath);
    $db->prepare('INSERT INTO pageviews (site, path, visitor_hash) VALUES (?, ?, ?)')->execute(['shop.example', '/', 'hash-1']);
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->tmpDir));
});

/** A compressed backup unpacked next to it, to open */
function unpackBackup(string $path): PDO
{
    $copy = dirname($path, 2) . '/unpacked.sqlite';
    copy("compress.zlib://$path", $copy);
    return new PDO('sqlite:' . $copy);
}

it('writes a checked, compressed copy of the database that only its owner can read', function () {
    $result = runCli($this->tmpDir, 'backup', [$this->dir]);
    $files = glob("{$this->dir}/puls-*");

    expect($result['exit'])->toBe(0)
        ->and($files)->toHaveCount(1)
        ->and(basename($files[0]))->toMatch('/^puls-\d{4}-\d{2}-\d{2}-\d{6}\.sqlite\.gz$/')
        ->and($result['output'])->toContain('Backed up to ' . $files[0])
        ->and($result['output'])->toMatch('/\(\d+ (bytes|KB) compressed from \d+ KB, checked\)\.$/')
        ->and((int) unpackBackup($files[0])->query('SELECT COUNT(*) FROM pageviews')->fetchColumn())->toBe(1)
        ->and(fileperms($files[0]) & 0777)->toBe(0600)
        ->and(fileperms($this->dir) & 0777)->toBe(0700);
});

it('writes to backups next to the database without a folder', function () {
    $result = runCli($this->tmpDir, 'backup');

    expect($result['exit'])->toBe(0)
        ->and(glob("{$this->tmpDir}/backups/puls-*.sqlite.gz"))->toHaveCount(1);
});

it('leaves nothing half written behind for a job that copies the folder', function () {
    runCli($this->tmpDir, 'backup', [$this->dir]);

    expect(array_values(array_diff(scandir($this->dir), ['.', '..'])))->toHaveCount(1);
});

it('keeps the newest backups, compressed or not, and leaves other files alone', function () {
    mkdir($this->dir, 0700, true);
    touch("{$this->dir}/puls-2026-10-01-030000.sqlite");
    touch("{$this->dir}/puls-2026-10-02-030000.sqlite");
    touch("{$this->dir}/puls-2026-10-03-030000.sqlite.gz");
    touch("{$this->dir}/notes.txt");

    $result = runCli($this->tmpDir, 'backup', [$this->dir, '--keep=2']);
    $left = array_map('basename', glob("{$this->dir}/*"));

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toContain('Removed 2 older backups, kept the newest 2.')
        ->and($left)->toContain('puls-2026-10-03-030000.sqlite.gz', 'notes.txt')
        ->and($left)->not->toContain('puls-2026-10-01-030000.sqlite', 'puls-2026-10-02-030000.sqlite')
        ->and(glob("{$this->dir}/puls-*"))->toHaveCount(2);
});

it('removes what a backup that was stopped left behind, once it is a day old', function () {
    mkdir($this->dir, 0700, true);
    touch("{$this->dir}/.puls-2026-10-01-030000.sqlite", time() - 90000);
    touch("{$this->dir}/.puls-2026-10-01-030000.sqlite.gz", time() - 90000);
    touch("{$this->dir}/.puls-2026-10-02-030000.sqlite");

    runCli($this->tmpDir, 'backup', [$this->dir]);

    expect(file_exists("{$this->dir}/.puls-2026-10-01-030000.sqlite"))->toBeFalse()
        ->and(file_exists("{$this->dir}/.puls-2026-10-01-030000.sqlite.gz"))->toBeFalse()
        ->and(file_exists("{$this->dir}/.puls-2026-10-02-030000.sqlite"))->toBeTrue();
});

it('says so when it cannot write the backup', function () {
    touch($this->dir);

    $result = runCli($this->tmpDir, 'backup', [$this->dir]);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toBe("Cannot write backups to {$this->dir}.");
});

it('says so when there is no database yet, and writes nothing', function () {
    unlink($this->dbPath);

    $result = runCli($this->tmpDir, 'backup', [$this->dir]);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toBe('No database found. Start tracking to create it.')
        ->and(file_exists($this->dir))->toBeFalse()
        ->and(file_exists($this->dbPath))->toBeFalse();
});
