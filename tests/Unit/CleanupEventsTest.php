<?php

function eventsRetentionDb(): PDO
{
    $dir = sys_get_temp_dir() . '/puls-retention-' . uniqid();
    mkdir($dir);
    register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg($dir)));
    $db = new PDO('sqlite:' . $dir . '/puls.sqlite');
    $db->exec('CREATE TABLE events (id INTEGER PRIMARY KEY, created_at TEXT NOT NULL)');
    foreach ([100, 500] as $age) {
        $db->prepare('INSERT INTO events (created_at) VALUES (?)')
            ->execute([date('Y-m-d H:i:s', strtotime("-{$age} days"))]);
    }
    return $db;
}

function eventAges(PDO $db): int
{
    return (int) $db->query('SELECT COUNT(*) FROM events')->fetchColumn();
}

test('keeps events inside the retention window and deletes older ones', function () {
    $db = eventsRetentionDb();
    cleanup_events($db, 400);
    expect(eventAges($db))->toBe(1)
        ->and((int) $db->query("SELECT COUNT(*) FROM events WHERE created_at < date('now', '-400 days')")->fetchColumn())->toBe(0);
});

test('retention of zero keeps events forever', function () {
    $db = eventsRetentionDb();
    cleanup_events($db, 0);
    expect(eventAges($db))->toBe(2);
});
