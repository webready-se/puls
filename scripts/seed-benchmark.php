<?php
/**
 * Seed a benchmark database: a year of traffic on one medium site, for measuring the dashboard
 * at scale. Benchmarks run on seeded data, never on an install's own.
 *
 * Usage: php scripts/seed-benchmark.php [db_path] [pageviews]
 *   db_path    default data/benchmark.sqlite (replaced if it exists)
 *   pageviews  default 2000000; about 90% land on the big site, the rest on two small ones
 *
 * The schema comes from index.php (get_db and its migrations), so the database has exactly the
 * tables and indexes an install has. Measure with scripts/benchmark.php.
 */

require __DIR__ . '/../tests/bootstrap.php';

$dbPath = $argv[1] ?? 'data/benchmark.sqlite';
$target = max(1000, (int) ($argv[2] ?? 2_000_000));
foreach ([$dbPath, "$dbPath-wal", "$dbPath-shm"] as $f) {
    if (file_exists($f)) unlink($f);
}

mt_srand(2026); // the same database every run, so before and after compare
$db = get_db($dbPath);
$db->exec('PRAGMA synchronous = OFF'); // a throwaway database: speed over durability while seeding

function pickWeighted(array $items, int $total): mixed
{
    $r = mt_rand(1, $total);
    $cum = 0;
    foreach ($items as $item) {
        $cum += $item[1];
        if ($r <= $cum) return $item[0];
    }
    return $items[0][0];
}

// About 200 paths with a long tail: a few pages take most of the traffic, as on a real site
$paths = [['/', 400], ['/pricing', 120], ['/about', 80], ['/contact', 60], ['/blog', 90], ['/docs', 70], ['/signup', 40], ['/login', 60]];
for ($i = 1; $i <= 120; $i++) $paths[] = ["/blog/post-$i", max(1, (int) (60 / $i))];
for ($i = 1; $i <= 40; $i++) $paths[] = ["/docs/page-$i", max(1, (int) (30 / $i))];
for ($i = 1; $i <= 30; $i++) $paths[] = ["/products/item-$i", max(1, (int) (40 / $i))];
$pathsTotal = array_sum(array_column($paths, 1));

$referrers = [[null, 40], ['Google', 30], ['Bing', 3], ['Twitter/X', 5], ['LinkedIn', 4], ['Facebook', 5], ['Reddit', 3], ['Hacker News', 2], ['GitHub', 4], ['DuckDuckGo', 2], ['Instagram', 2]];
$browsers = [['Chrome', 55], ['Safari', 22], ['Firefox', 12], ['Edge', 8], ['Other', 3]];
$devices = [['Desktop', 58], ['Mobile', 36], ['Tablet', 6]];
$countries = [['SE', 30], ['US', 18], ['GB', 10], ['DE', 8], ['NO', 6], ['DK', 5], ['FI', 4], ['NL', 4], ['FR', 3], ['AU', 3], ['CA', 3], ['JP', 2], ['BR', 2], ['IN', 2]];
$languages = [['sv', 30], ['en', 42], ['de', 8], ['nb', 6], ['da', 5], ['fi', 4], ['nl', 3], ['fr', 2]];
$campaigns = [[['google', 'cpc', 'brand-search'], 4], [['facebook', 'paid_social', 'retargeting'], 3], [['newsletter', 'email', 'weekly'], 4], [['twitter', 'social', 'launch'], 1], [null, 88]];
$sessionPages = [[1, 48], [2, 24], [3, 13], [4, 8], [5, 4], [6, 3]];
$startHours = [[0, 1], [1, 1], [2, 1], [3, 1], [4, 1], [5, 1], [6, 2], [7, 4], [8, 7], [9, 10], [10, 11], [11, 11], [12, 9], [13, 10], [14, 11], [15, 10], [16, 8], [17, 6], [18, 5], [19, 5], [20, 5], [21, 4], [22, 3], [23, 2]];
$total = fn (array $items) => array_sum(array_column($items, 1));
[$refT, $brT, $devT, $coT, $laT, $caT, $spT, $shT] = array_map($total, [$referrers, $browsers, $devices, $countries, $languages, $campaigns, $sessionPages, $startHours]);

// Sites: a medium one that takes most of the traffic and two small ones, so site filters have
// something to filter out. Pageviews per average day, from the target
$days = 365;
$sites = [['bench.example.com', 0.90], ['shop.example', 0.07], ['blog.example', 0.03]];
$now = time();
$today = strtotime('today');

$stmt = $db->prepare('INSERT INTO pageviews (site, path, referrer, browser, device, visitor_hash, utm_source, utm_medium, utm_campaign, language, country, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$eventStmt = $db->prepare('INSERT INTO events (site, event_name, event_data, page_path, visitor_hash, created_at) VALUES (?, ?, ?, ?, ?, ?)');
$botStmt = $db->prepare('INSERT INTO bot_visits (site, path, bot_name, bot_category, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?)');
// Weight at index 1 (pickWeighted), the event's data or the bot's category at index 2
$eventNames = [['cta_click', 5, '{"location":"hero"}'], ['signup', 1, '{"plan":"pro"}'], ['download', 2, '{"file":"guide.pdf"}'], ['outbound_click', 4, '{"url":"https://github.com/example/repo"}']];
$evT = $total($eventNames);
$bots = [['Googlebot', 30, 'Search engine'], ['Bingbot', 10, 'Search engine'], ['GPTBot', 15, 'AI'], ['ClaudeBot', 15, 'AI'], ['PerplexityBot', 5, 'AI'], ['facebookexternalhit', 5, 'Social'], ['UptimeRobot', 20, 'Monitor']];
$botT = $total($bots);

$inserted = 0;
$events = 0;
$botVisits = 0;
$started = microtime(true);
$db->beginTransaction();
// Day by day, oldest first, so rowids follow time as they do in an install
for ($daysAgo = $days - 1; $daysAgo >= 0; $daysAgo--) {
    $dayStart = $today - $daysAgo * 86400;
    $dow = (int) date('w', $dayStart);
    $weekend = ($dow === 0 || $dow === 6) ? 0.55 : 1.0;
    $growth = 0.7 + 0.6 * (($days - $daysAgo) / $days); // +60% over the year
    foreach ($sites as [$site, $share]) {
        // 1.15 makes up for the weekend dip, so the total lands near the target
        $pageviewsToday = (int) ($target * $share / $days * 1.15 * $weekend * $growth * (0.85 + mt_rand(0, 30) / 100));
        // A visit is about 2.1 pages on average; one hash per visitor per day, like the real one
        $visitors = max(1, (int) ($pageviewsToday / 2.1));
        for ($v = 0; $v < $visitors; $v++) {
            $hash = hash('sha256', "$site|$daysAgo|$v");
            $referrer = pickWeighted($referrers, $refT);
            $browser = pickWeighted($browsers, $brT);
            $device = pickWeighted($devices, $devT);
            $country = pickWeighted($countries, $coT);
            $lang = pickWeighted($languages, $laT);
            $campaign = pickWeighted($campaigns, $caT);
            $t = $dayStart + pickWeighted($startHours, $shT) * 3600 + mt_rand(0, 3599);
            for ($p = pickWeighted($sessionPages, $spT); $p > 0 && $t <= $now; $p--) {
                $path = pickWeighted($paths, $pathsTotal);
                $created = date('Y-m-d H:i:s', $t);
                $stmt->execute([$site, $path, $referrer, $browser, $device, $hash, $campaign[0] ?? null, $campaign[1] ?? null, $campaign[2] ?? null, $lang, $country, $created]);
                $inserted++;
                // About one event per 40 pageviews
                if (mt_rand(1, 40) === 1) {
                    $name = pickWeighted($eventNames, $evT);
                    $data = null;
                    foreach ($eventNames as $e) if ($e[0] === $name) $data = $e[2];
                    $eventStmt->execute([$site, $name, $data, $path, $hash, $created]);
                    $events++;
                }
                $t += mt_rand(15, 240);
            }
        }
        // Bots: about one visit per 25 pageviews, spread over the day
        for ($b = (int) ($pageviewsToday / 25); $b > 0; $b--) {
            $t = $dayStart + mt_rand(0, 86399);
            if ($t > $now) continue;
            $name = pickWeighted($bots, $botT);
            $cat = 'Other';
            foreach ($bots as $bot) if ($bot[0] === $name) $cat = $bot[2];
            $botStmt->execute([$site, pickWeighted($paths, $pathsTotal), $name, $cat, "Mozilla/5.0 (compatible; $name/1.0)", date('Y-m-d H:i:s', $t)]);
            $botVisits++;
        }
    }
    if ($daysAgo % 30 === 0) {
        $db->commit();
        fwrite(STDERR, sprintf("  %3d days left, %s pageviews, %ds\r", $daysAgo, number_format($inserted), (int) (microtime(true) - $started)));
        $db->beginTransaction();
    }
}
$db->commit();
$db->exec('PRAGMA synchronous = FULL');
$db->exec('ANALYZE');
fwrite(STDERR, str_repeat(' ', 60) . "\r");

$size = round((filesize($dbPath) + (file_exists("$dbPath-wal") ? filesize("$dbPath-wal") : 0)) / 1048576);
echo sprintf("Seeded %s pageviews, %s events and %s bot visits over %d days to %s (%d MB) in %ds\n",
    number_format($inserted), number_format($events), number_format($botVisits), $days, $dbPath, $size, (int) (microtime(true) - $started));
echo "Measure: php scripts/benchmark.php $dbPath\n";
