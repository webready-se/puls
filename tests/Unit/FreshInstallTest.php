<?php

test('a fresh database has every table and the current schema version', function () {
    $path = sys_get_temp_dir() . '/puls-fresh-' . uniqid() . '/puls.sqlite';
    $db = get_db($path);

    $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    expect($tables)->toContain('pageviews', 'events', 'goals', 'share_tokens', 'app_versions', 'breakdowns');

    $pvCols = array_column($db->query('PRAGMA table_info(pageviews)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    expect($pvCols)->toContain('app_version');

    // Same version a migrated database ends on, so the first request does not re-run migrations
    preg_match('/\$currentVersion = (\d+);/', file_get_contents(__DIR__ . '/../../public/index.php'), $m);
    expect((int) $db->query('PRAGMA user_version')->fetchColumn())->toBe((int) $m[1]);
});
