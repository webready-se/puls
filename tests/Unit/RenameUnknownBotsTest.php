<?php

function botTestDb(): PDO
{
    $dir = sys_get_temp_dir() . '/puls-bots-' . uniqid();
    register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg($dir)));
    return get_db($dir . '/puls.sqlite');
}

test('migration v25 renames stored unknown visits that detect_bot() knows now', function () {
    $db = botTestDb();
    $db->exec('PRAGMA user_version = 24');
    $insert = $db->prepare("INSERT INTO bot_visits (site, path, bot_name, bot_category, user_agent, created_at) VALUES ('s', '/', ?, ?, ?, '2026-01-01 10:00:00')");
    $insert->execute(['Unknown bot', 'Other', 'SnokaBot/0.1.0 (+https://github.com/webready-se/snoka)']);
    $insert->execute(['Unknown client', 'Client', 'Vaka/0.2.0 (+https://vaka.example.com)']);
    $insert->execute(['Unknown crawler', 'Other', 'br-crawler/0.5']);
    $insert->execute(['Unknown client', 'Client', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36']);
    $insert->execute(['Googlebot', 'Search engine', 'Mozilla/5.0 (compatible; Googlebot/2.1)']);

    run_migrations($db);

    $rows = $db->query('SELECT bot_name, bot_category FROM bot_visits ORDER BY id')->fetchAll(PDO::FETCH_NUM);
    expect($rows)->toBe([
        ['Snoka', 'SEO'],
        ['Vaka', 'Monitor'],
        ['Unknown crawler', 'Other'],
        ['Unknown client', 'Client'],
        ['Googlebot', 'Search engine'],
    ])->and((int) $db->query('PRAGMA user_version')->fetchColumn())->toBe(25);
});
