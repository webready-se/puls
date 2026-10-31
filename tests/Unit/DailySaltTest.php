<?php

function saltTestDb(): PDO
{
    $dir = sys_get_temp_dir() . '/puls-salt-' . uniqid();
    register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg($dir)));
    return get_db($dir . '/puls.sqlite');
}

test('the daily salt is random and stays the same through the day', function () {
    $db = saltTestDb();
    $salt = daily_salt($db);
    expect($salt)->toMatch('/^[0-9a-f]{64}$/')
        ->and(daily_salt($db))->toBe($salt);
});

test('creating today\'s salt deletes the salts of earlier days', function () {
    $db = saltTestDb();
    $db->exec("INSERT INTO salts (date, salt) VALUES ('2020-01-01', 'old')");
    daily_salt($db);
    expect($db->query('SELECT date FROM salts')->fetchAll(PDO::FETCH_COLUMN))->toBe([date('Y-m-d')]);
});

test('the visitor hash depends on site, IP, user agent and the salt only', function () {
    $db = saltTestDb();
    $hash = visitor_hash($db, 'shop.example', '198.51.100.1', 'Mozilla/5.0');
    expect($hash)->toBe(hash('sha256', 'shop.example' . "\0" . '198.51.100.1' . 'Mozilla/5.0' . daily_salt($db)))
        ->and(visitor_hash($db, 'shop.example', '198.51.100.2', 'Mozilla/5.0'))->not->toBe($hash)
        // The same person on another site of the install is another hash, so visits cannot be joined across sites
        ->and(visitor_hash($db, 'blog.example', '198.51.100.1', 'Mozilla/5.0'))->not->toBe($hash);

    // A new salt (the next day) gives the same visitor a hash unrelated to the old one
    $db->exec('UPDATE salts SET salt = ' . $db->quote(str_repeat('0', 64)));
    expect(visitor_hash($db, 'shop.example', '198.51.100.1', 'Mozilla/5.0'))->not->toBe($hash);
});

test('migration v22 replaces old visitor hashes but keeps rows of one visitor together', function () {
    $db = saltTestDb();
    $db->exec('DROP TABLE salts');
    $db->exec('PRAGMA user_version = 21');
    $pv = $db->prepare("INSERT INTO pageviews (site, path, visitor_hash, created_at) VALUES ('s', '/', ?, '2026-01-01 10:00:00')");
    foreach (['aaa', 'aaa', 'bbb'] as $h) $pv->execute([$h]);
    $db->exec("INSERT INTO events (site, event_name, visitor_hash, created_at) VALUES ('s', 'signup', 'aaa', '2026-01-01 10:01:00'), ('s', 'signup', 'ccc', '2026-01-01 10:02:00')");

    run_migrations($db);

    $pvHashes = $db->query('SELECT visitor_hash FROM pageviews ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $evHashes = $db->query('SELECT visitor_hash FROM events ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    expect(array_intersect(array_merge($pvHashes, $evHashes), ['aaa', 'bbb', 'ccc']))->toBe([])
        ->and($pvHashes[0])->toMatch('/^[0-9a-f]{64}$/')
        ->and($pvHashes[0])->toBe($pvHashes[1])
        ->and($pvHashes[2])->not->toBe($pvHashes[0])
        ->and($evHashes[0])->toBe($pvHashes[0])
        ->and($evHashes[1])->not->toBeIn([$pvHashes[0], $pvHashes[2]])
        ->and($db->query("SELECT name FROM sqlite_master WHERE name = 'salts'")->fetchColumn())->toBe('salts');
});
