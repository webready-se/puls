<?php
/**
 * Time the dashboard API on a database, the way the dashboard asks for it. Pair with
 * scripts/seed-benchmark.php; never point it at an install's own database.
 *
 * Usage: php scripts/benchmark.php [db_path] [--runs=3] [--explain]
 *   db_path    default data/benchmark.sqlite
 *   --runs     times each request is made; the median is reported (the first run warms the cache)
 *   --explain  also print EXPLAIN QUERY PLAN for the bounce and session queries
 *
 * get_api_data() is called in-process (no web server), so the numbers are query time plus
 * PHP's work on the rows, without HTTP.
 */

require __DIR__ . '/../tests/bootstrap.php';

$args = array_slice($argv, 1);
$dbPath = 'data/benchmark.sqlite';
$runs = 3;
$explain = false;
foreach ($args as $arg) {
    if ($arg === '--explain') $explain = true;
    elseif (preg_match('/^--runs=(\d+)$/', $arg, $m)) $runs = max(1, (int) $m[1]);
    else $dbPath = $arg;
}
if (!file_exists($dbPath)) {
    fwrite(STDERR, "No database at $dbPath. Seed one: php scripts/seed-benchmark.php $dbPath\n");
    exit(1);
}

$config = ['db_path' => $dbPath];
$user = ['sites' => []];
$db = get_db($dbPath);
$site = $db->query('SELECT site FROM pageviews GROUP BY site ORDER BY COUNT(*) DESC LIMIT 1')->fetchColumn();
$total = (int) $db->query('SELECT COUNT(*) FROM pageviews')->fetchColumn();
$indexes = $db->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'pageviews' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
printf("%s: %s pageviews, schema v%d, pageviews indexes: %s\n\n", $dbPath, number_format($total), $db->query('PRAGMA user_version')->fetchColumn(), implode(', ', $indexes));

// What the dashboard asks for: the default view, a site, the period picker, a channel, a page
$requests = [
    'All sites, 30 days'        => ['days' => 30],
    "$site, 7 days"             => ['days' => 7, 'site' => $site],
    "$site, 30 days"            => ['days' => 30, 'site' => $site],
    "$site, 90 days"            => ['days' => 90, 'site' => $site],
    "$site, 365 days"           => ['days' => 365, 'site' => $site],
    "$site, 30 days, organic"   => ['days' => 30, 'site' => $site, 'channel' => 'organic-search'],
    "$site, 30 days, one page"  => ['days' => 30, 'site' => $site, 'path' => '/'],
];
foreach ($requests as $label => $get) {
    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        $_GET = $get;
        $t = microtime(true);
        $json = get_api_data($config, $user);
        $times[] = microtime(true) - $t;
    }
    sort($times);
    $median = $times[intdiv(count($times), 2)];
    $data = json_decode($json, true);
    printf("%-34s %7.0f ms   (%s views, bounce %s%%, median session %ss)\n", $label, $median * 1000,
        number_format($data['totals']['views'] ?? 0), $data['bounceRate'] ?? '-', $data['medianSessionSeconds'] ?? '-');
}
$_GET = [];

if ($explain) {
    $since = date('Y-m-d', strtotime('-29 days'));
    $plans = [
        'bounce'  => "SELECT COUNT(*), SUM(CASE WHEN cnt = 1 THEN 1 ELSE 0 END) FROM (SELECT visitor_hash, COUNT(*) as cnt FROM pageviews WHERE created_at >= ? AND site = ? GROUP BY visitor_hash)",
        'session' => "SELECT (julianday(MAX(created_at)) - julianday(MIN(created_at))) * 86400 as duration FROM pageviews WHERE created_at >= ? AND site = ? GROUP BY visitor_hash HAVING COUNT(*) > 1 ORDER BY duration",
    ];
    foreach ($plans as $name => $sql) {
        echo "\nEXPLAIN QUERY PLAN ($name):\n";
        $stmt = $db->prepare("EXPLAIN QUERY PLAN $sql");
        $stmt->execute([$since, $site]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            echo '  ', $row['detail'], "\n";
        }
    }
}
