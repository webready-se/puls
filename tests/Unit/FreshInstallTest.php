<?php

test('a fresh database has every table and the current schema version', function () {
    $dir = sys_get_temp_dir() . '/puls-fresh-' . uniqid();
    register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg($dir)));
    $db = get_db($dir . '/puls.sqlite');

    $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    expect($tables)->toContain('pageviews', 'events', 'goals', 'share_tokens', 'app_versions', 'breakdowns', 'annotations', 'excluded_ips', 'salts');

    $pvCols = array_column($db->query('PRAGMA table_info(pageviews)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    expect($pvCols)->toContain('app_version');
    $bdCols = array_column($db->query('PRAGMA table_info(breakdowns)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    expect($bdCols)->toContain('position');
    $goalCols = array_column($db->query('PRAGMA table_info(goals)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    expect($goalCols)->toContain('target', 'target_period');

    // One covering index on pageviews: the dashboard's visitor counts read it, not the rows
    $indexes = $db->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'pageviews' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    expect($indexes)->toBe(['idx_site_date_visitor']);

    // Same version a migrated database ends on, so the first request does not re-run migrations
    preg_match('/\$currentVersion = (\d+);/', file_get_contents(__DIR__ . '/../../public/index.php'), $m);
    expect((int) $db->query('PRAGMA user_version')->fetchColumn())->toBe((int) $m[1]);
});

test('migration v26 replaces idx_site_date and idx_visitor with the covering index and gathers statistics', function () {
    $dir = sys_get_temp_dir() . '/puls-v26-' . uniqid();
    register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg($dir)));
    $db = get_db($dir . '/puls.sqlite');
    // The indexes an install had before v26
    $db->exec('DROP INDEX idx_site_date_visitor');
    $db->exec('CREATE INDEX idx_site_date ON pageviews (site, created_at)');
    $db->exec('CREATE INDEX idx_visitor ON pageviews (visitor_hash)');
    $db->exec('DROP TABLE IF EXISTS sqlite_stat1');
    $db->exec("INSERT INTO pageviews (site, path, visitor_hash, created_at) VALUES ('s', '/', 'a', '2026-01-01 10:00:00'), ('s', '/b', 'a', '2026-01-01 10:01:00')");
    $db->exec('PRAGMA user_version = 25');

    run_migrations($db);

    $indexes = $db->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'pageviews' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    expect($indexes)->toBe(['idx_site_date_visitor'])
        ->and($db->query("SELECT idx FROM sqlite_stat1 WHERE tbl = 'pageviews'")->fetchColumn())->toBe('idx_site_date_visitor')
        ->and((int) $db->query('SELECT COUNT(*) FROM pageviews')->fetchColumn())->toBe(2);
});

test('refresh_statistics gathers planner statistics once a day', function () {
    $dir = sys_get_temp_dir() . '/puls-stats-' . uniqid();
    register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg($dir)));
    $db = get_db($dir . '/puls.sqlite');
    $db->exec("INSERT INTO pageviews (site, path, visitor_hash, created_at) VALUES ('s', '/', 'a', '2026-01-01 10:00:00')");
    $db->exec('DROP TABLE sqlite_stat1');

    refresh_statistics($db);
    expect($db->query("SELECT COUNT(*) FROM sqlite_stat1")->fetchColumn())->toBeGreaterThan(0)
        ->and(file_get_contents($dir . '/.analyze'))->toBe(date('Y-m-d'));

    // Already done today: the statistics are left alone
    $db->exec('DROP TABLE sqlite_stat1');
    refresh_statistics($db);
    expect($db->query("SELECT name FROM sqlite_master WHERE name = 'sqlite_stat1'")->fetchColumn())->toBeFalse();
});

test('migration v23 gives saved cards a position, existing ones at 0 in their old order', function () {
    $dir = sys_get_temp_dir() . '/puls-v23-' . uniqid();
    register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg($dir)));
    $db = get_db($dir . '/puls.sqlite');
    $db->exec('ALTER TABLE breakdowns DROP COLUMN position');
    $db->exec("INSERT INTO breakdowns (site, label, event_name, group_key, created_at) VALUES ('s', '', 'b', 'k', '2026-01-02'), ('s', '', 'a', 'k', '2026-01-01')");
    $db->exec('PRAGMA user_version = 22');

    run_migrations($db);
    expect($db->query('SELECT event_name, position FROM breakdowns ORDER BY position, created_at, id')->fetchAll(PDO::FETCH_NUM))
        ->toBe([['a', 0], ['b', 0]]);
});

test('migration v24 adds an optional target to goals', function () {
    $dir = sys_get_temp_dir() . '/puls-v24-' . uniqid();
    register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg($dir)));
    $db = get_db($dir . '/puls.sqlite');
    $db->exec('ALTER TABLE goals DROP COLUMN target');
    $db->exec('ALTER TABLE goals DROP COLUMN target_period');
    $db->exec("INSERT INTO goals (site, path, label, type, created_at) VALUES ('s', '/thanks', '', 'page', '2026-01-01')");
    $db->exec('PRAGMA user_version = 23');

    run_migrations($db);
    expect($db->query('SELECT path, target, target_period FROM goals')->fetchAll(PDO::FETCH_NUM))->toBe([['/thanks', null, null]]);
});
