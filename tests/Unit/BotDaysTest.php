<?php

function botDaysDb(): PDO
{
    $dir = sys_get_temp_dir() . '/puls-bot-days-' . uniqid();
    register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg($dir)));
    return get_db($dir . '/puls.sqlite');
}

function addBotVisit(PDO $db, string $site, string $name, string $category, string $at, string $ua = ''): void
{
    $db->prepare('INSERT INTO bot_visits (site, path, bot_name, bot_category, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$site, '/', $name, $category, $ua, $at]);
}

function botDayRows(PDO $db): array
{
    return $db->query('SELECT site, date, name, category, visits FROM bot_days ORDER BY site, date, name')->fetchAll(PDO::FETCH_NUM);
}

test('a bot visit is counted per site, day and bot as it is stored', function () {
    $db = botDaysDb();
    addBotVisit($db, 'a.example', 'Googlebot', 'Search engine', '2026-11-01 10:00:00');
    addBotVisit($db, 'a.example', 'Googlebot', 'Search engine', '2026-11-01 23:59:59');
    addBotVisit($db, 'a.example', 'Googlebot', 'Search engine', '2026-11-02 00:00:00');
    addBotVisit($db, 'a.example', 'Bingbot', 'Search engine', '2026-11-01 11:00:00');
    addBotVisit($db, 'b.example', 'Googlebot', 'Search engine', '2026-11-01 12:00:00');

    expect(botDayRows($db))->toBe([
        ['a.example', '2026-11-01', 'Bingbot', 'Search engine', 1],
        ['a.example', '2026-11-01', 'Googlebot', 'Search engine', 2],
        ['a.example', '2026-11-02', 'Googlebot', 'Search engine', 1],
        ['b.example', '2026-11-01', 'Googlebot', 'Search engine', 1],
    ]);
});

test('migration v30 sums the visits already stored, and the count goes on from there', function () {
    $db = botDaysDb();
    $db->exec('DROP TRIGGER bot_days_count');
    $db->exec('DROP TABLE bot_days');
    $db->exec('PRAGMA user_version = 29');
    addBotVisit($db, 'a.example', 'Googlebot', 'Search engine', '2026-11-01 10:00:00');
    addBotVisit($db, 'a.example', 'Googlebot', 'Search engine', '2026-11-01 11:00:00');

    run_migrations($db);
    expect(botDayRows($db))->toBe([['a.example', '2026-11-01', 'Googlebot', 'Search engine', 2]]);

    addBotVisit($db, 'a.example', 'Googlebot', 'Search engine', '2026-11-01 12:00:00');
    expect(botDayRows($db))->toBe([['a.example', '2026-11-01', 'Googlebot', 'Search engine', 3]]);
});

test('renaming stored unknown visits moves their counts too', function () {
    $db = botDaysDb();
    addBotVisit($db, 'a.example', 'Unknown bot', 'Other', '2026-10-31 10:00:00', 'Something/1.0');
    addBotVisit($db, 'a.example', 'Unknown bot', 'Other', '2026-11-01 10:00:00', 'Mozilla/5.0 (compatible; GPTBot/1.0)');
    addBotVisit($db, 'a.example', 'Unknown bot', 'Other', '2026-11-01 11:00:00', 'Something/1.0');

    rename_unknown_bots($db);

    // The oldest day may be cut off by retention, so its count is left as it is
    expect(botDayRows($db))->toBe([
        ['a.example', '2026-10-31', 'Unknown bot', 'Other', 1],
        ['a.example', '2026-11-01', 'GPTBot', 'AI', 1],
        ['a.example', '2026-11-01', 'Unknown bot', 'Other', 1],
    ]);
});

test('retention removes old visits and keeps their counts', function () {
    $db = botDaysDb();
    $old = date('Y-m-d H:i:s', strtotime('-40 days'));
    $new = date('Y-m-d H:i:s', strtotime('-20 days'));
    addBotVisit($db, 'a.example', 'Googlebot', 'Search engine', $old);
    addBotVisit($db, 'a.example', 'Googlebot', 'Search engine', $old);
    addBotVisit($db, 'a.example', 'Googlebot', 'Search engine', $new);

    cleanup_bot_visits($db, 30);

    expect((int) $db->query('SELECT COUNT(*) FROM bot_visits')->fetchColumn())->toBe(1)
        ->and((int) $db->query('SELECT SUM(visits) FROM bot_days')->fetchColumn())->toBe(3);
});

test('retention takes every old visit, in batches, and stops at the first it keeps', function () {
    $db = botDaysDb();
    $db->beginTransaction();
    $old = date('Y-m-d H:i:s', strtotime('-40 days'));
    for ($i = 0; $i < 12050; $i++) addBotVisit($db, 'a.example', 'Googlebot', 'Search engine', $old);
    addBotVisit($db, 'a.example', 'Googlebot', 'Search engine', date('Y-m-d H:i:s', strtotime('-1 day')));
    $db->commit();

    cleanup_bot_visits($db, 30);

    expect((int) $db->query('SELECT COUNT(*) FROM bot_visits')->fetchColumn())->toBe(1);
});

test('retention removes everything when every visit is old', function () {
    $db = botDaysDb();
    addBotVisit($db, 'a.example', 'Googlebot', 'Search engine', date('Y-m-d H:i:s', strtotime('-40 days')));

    cleanup_bot_visits($db, 30);

    expect((int) $db->query('SELECT COUNT(*) FROM bot_visits')->fetchColumn())->toBe(0);
});

test('a retention of 0 keeps every visit', function () {
    $db = botDaysDb();
    addBotVisit($db, 'a.example', 'Googlebot', 'Search engine', date('Y-m-d H:i:s', strtotime('-400 days')));

    cleanup_bot_visits($db, 0);

    expect((int) $db->query('SELECT COUNT(*) FROM bot_visits')->fetchColumn())->toBe(1);
});

test('sites:rename and sites:remove take the counts along', function () {
    $dir = sys_get_temp_dir() . '/puls-bot-days-cli-' . uniqid();
    mkdir($dir);
    register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg($dir)));
    $db = get_db($dir . '/test.sqlite');
    addBotVisit($db, 'old.example', 'Googlebot', 'Search engine', '2026-11-01 10:00:00');
    addBotVisit($db, 'gone.example', 'Googlebot', 'Search engine', '2026-11-01 10:00:00');
    $db->prepare("INSERT INTO pageviews (site, path, visitor_hash, created_at) VALUES (?, '/', 'v', '2026-11-01 10:00:00')")->execute(['old.example']);
    $db->prepare("INSERT INTO pageviews (site, path, visitor_hash, created_at) VALUES (?, '/', 'v', '2026-11-01 10:00:00')")->execute(['gone.example']);

    runCli($dir, 'sites:rename', ['old.example', 'new.example'], "y");
    runCli($dir, 'sites:remove', ['gone.example'], 'gone.example');

    expect(botDayRows($db))->toBe([['new.example', '2026-11-01', 'Googlebot', 'Search engine', 1]]);
});
