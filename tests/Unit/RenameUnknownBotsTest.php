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
    ]);
    // Through to the current version, whatever later migrations exist
    preg_match('/\$currentVersion = (\d+);/', file_get_contents(__DIR__ . '/../../public/index.php'), $m);
    expect((int) $db->query('PRAGMA user_version')->fetchColumn())->toBe((int) $m[1]);
});

test('migration v28 names Meta\'s crawlers stored as unknown', function () {
    $db = botTestDb();
    $db->exec('PRAGMA user_version = 27');
    $insert = $db->prepare("INSERT INTO bot_visits (site, path, bot_name, bot_category, user_agent, created_at) VALUES ('s', '/', 'Unknown crawler', 'Other', ?, '2026-01-01 10:00:00')");
    $insert->execute(['meta-webindexer/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)']);
    $insert->execute(['meta-externalfetcher/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)']);
    $insert->execute(['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 (compatible; meta-externalads/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler))']);
    $insert->execute(['br-crawler/0.5']);

    run_migrations($db);

    $rows = $db->query('SELECT bot_name, bot_category FROM bot_visits ORDER BY id')->fetchAll(PDO::FETCH_NUM);
    expect($rows)->toBe([
        ['Meta AI search', 'AI'],
        ['Meta AI fetcher', 'AI'],
        ['Meta Ads', 'Social'],
        ['Unknown crawler', 'Other'],
    ]);
});
