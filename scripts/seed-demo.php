<?php
/**
 * Seed a demo database with realistic-looking fake data for screenshots.
 * Usage: php scripts/seed-demo.php [db_path]
 */

$dbPath = $argv[1] ?? 'data/demo.sqlite';
if (file_exists($dbPath)) unlink($dbPath);

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA journal_mode=WAL');

// Create schema (copy from index.php run_migrations end-state)
$db->exec('CREATE TABLE pageviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site TEXT NOT NULL,
    path TEXT NOT NULL,
    referrer TEXT,
    browser TEXT,
    device TEXT,
    visitor_hash TEXT NOT NULL,
    utm_source TEXT,
    utm_medium TEXT,
    utm_campaign TEXT,
    utm_term TEXT,
    utm_content TEXT,
    language TEXT,
    country TEXT,
    created_at TEXT NOT NULL
)');
$db->exec('CREATE INDEX idx_site_date ON pageviews (site, created_at)');
$db->exec('CREATE TABLE bot_visits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site TEXT NOT NULL,
    path TEXT NOT NULL,
    bot_name TEXT NOT NULL,
    bot_category TEXT NOT NULL,
    user_agent TEXT,
    created_at TEXT NOT NULL
)');
$db->exec('CREATE TABLE broken_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site TEXT NOT NULL,
    path TEXT NOT NULL,
    status INTEGER NOT NULL,
    referrers TEXT,
    hits INTEGER DEFAULT 1,
    first_seen TEXT NOT NULL,
    last_seen TEXT NOT NULL
)');
$db->exec('CREATE UNIQUE INDEX idx_broken_unique ON broken_links (site, path, status)');
$db->exec('CREATE TABLE events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site TEXT NOT NULL,
    event_name TEXT NOT NULL,
    event_data TEXT,
    page_path TEXT,
    visitor_hash TEXT NOT NULL,
    created_at TEXT NOT NULL
)');
$db->exec('CREATE TABLE share_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT NOT NULL UNIQUE,
    site TEXT NOT NULL,
    label TEXT,
    expires_at TEXT,
    created_at TEXT NOT NULL
)');
$db->exec('CREATE TABLE audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    action TEXT NOT NULL,
    ip TEXT,
    created_at TEXT NOT NULL
)');
$db->exec('CREATE TABLE goals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site TEXT NOT NULL,
    path TEXT NOT NULL,
    label TEXT,
    created_at TEXT NOT NULL
)');
$db->exec('PRAGMA user_version = 15');

// --- Generate realistic demo data ---
$site = 'demo.example.com';

// Realistic paths with weights (how often they're visited)
$paths = [
    ['/', 40],
    ['/about', 12],
    ['/pricing', 18],
    ['/features', 15],
    ['/blog', 8],
    ['/blog/why-we-built-puls', 6],
    ['/blog/privacy-first-analytics', 5],
    ['/docs', 10],
    ['/docs/getting-started', 7],
    ['/contact', 4],
    ['/signup', 3],
    ['/login', 6],
];

// Referrers
$referrers = [
    [null, 35],
    ['Google', 25],
    ['Twitter/X', 8],
    ['LinkedIn', 6],
    ['Facebook', 5],
    ['Hacker News', 4],
    ['Reddit', 3],
    ['GitHub', 5],
    ['ProductHunt', 2],
    ['Bing', 2],
];

$browsers = [['Chrome', 55], ['Safari', 22], ['Firefox', 12], ['Edge', 8], ['Other', 3]];
$devices = [['Desktop', 62], ['Mobile', 32], ['Tablet', 6]];
$countries = [
    ['SE', 28], ['US', 18], ['GB', 10], ['DE', 8], ['NO', 6], ['DK', 5],
    ['FI', 4], ['NL', 4], ['FR', 3], ['AU', 3], ['CA', 3], ['JP', 2],
    ['BR', 2], ['IN', 2], ['PL', 2],
];
$languages = [
    ['sv', 28], ['en', 40], ['de', 8], ['no', 6], ['da', 5],
    ['fi', 4], ['nl', 4], ['fr', 3], ['ja', 2], ['pt', 2],
];

// UTM campaigns
$campaigns = [
    ['google', 'cpc', 'brand-search'],
    ['facebook', 'paid_social', 'retargeting'],
    ['newsletter', 'email', 'weekly-digest'],
    ['twitter', 'social', 'launch-post'],
    null, null, null, null, null, null, // mostly no utm
];

function pickWeighted($items) {
    $total = array_sum(array_column($items, 1));
    $r = mt_rand(1, $total);
    $cum = 0;
    foreach ($items as $item) {
        $cum += $item[1];
        if ($r <= $cum) return $item[0];
    }
    return $items[0][0];
}

$stmt = $db->prepare('INSERT INTO pageviews (site, path, referrer, browser, device, visitor_hash, utm_source, utm_medium, utm_campaign, language, country, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

// Generate 30 days of data, ~200-500 pageviews per day
// with a trend: growing traffic over the period
$totalInserted = 0;
for ($daysAgo = 29; $daysAgo >= 0; $daysAgo--) {
    // Traffic grows over time + weekly pattern (weekends lower)
    $dayOfWeek = (int) date('w', strtotime("-{$daysAgo} days"));
    $weekendMult = ($dayOfWeek === 0 || $dayOfWeek === 6) ? 0.5 : 1.0;
    $growthMult = 1 + ((30 - $daysAgo) / 30) * 0.4; // +40% over period
    $pageviewsToday = (int) (250 * $weekendMult * $growthMult * (0.8 + (mt_rand(0, 40) / 100)));

    // Visitors = roughly 70% of pageviews (some view multiple pages)
    $visitorsToday = (int) ($pageviewsToday * 0.7);
    $visitors = [];
    for ($v = 0; $v < $visitorsToday; $v++) {
        $visitors[] = substr(hash('sha256', "visitor-$daysAgo-$v"), 0, 16);
    }

    for ($i = 0; $i < $pageviewsToday; $i++) {
        $visitor = $visitors[mt_rand(0, count($visitors) - 1)];
        $hour = mt_rand(6, 22); // peak hours
        $minute = mt_rand(0, 59);
        $second = mt_rand(0, 59);
        $date = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days " . sprintf('%02d:%02d:%02d', $hour, $minute, $second)));

        $path = pickWeighted($paths);
        $referrer = pickWeighted($referrers);
        $browser = pickWeighted($browsers);
        $device = pickWeighted($devices);
        $country = pickWeighted($countries);
        $lang = pickWeighted($languages);
        $campaign = $campaigns[array_rand($campaigns)];

        $stmt->execute([
            $site, $path, $referrer, $browser, $device, $visitor,
            $campaign[0] ?? null, $campaign[1] ?? null, $campaign[2] ?? null,
            $lang, $country, $date,
        ]);
        $totalInserted++;
    }
}

// Add some bot visits
$bots = [
    ['Googlebot', 'Search engine', 150],
    ['ClaudeBot', 'AI', 80],
    ['GPTBot', 'AI', 60],
    ['Bingbot', 'Search engine', 45],
    ['PerplexityBot', 'AI', 30],
    ['Facebookexternalhit', 'Social', 25],
    ['LinkedInBot', 'Social', 18],
    ['UptimeRobot', 'Monitor', 720],  // every 2 min for a month
];
$botStmt = $db->prepare('INSERT INTO bot_visits (site, path, bot_name, bot_category, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?)');
foreach ($bots as [$name, $cat, $count]) {
    for ($i = 0; $i < $count; $i++) {
        $daysAgo = mt_rand(0, 29);
        $hour = mt_rand(0, 23);
        $date = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days {$hour}:00:00"));
        $botStmt->execute([$site, pickWeighted($paths), $name, $cat, "Mozilla/5.0 $name", $date]);
    }
}

// Add some broken links
$brokenLinks = [
    ['/old-page', 301, 23],
    ['/blog/deprecated-post', 301, 12],
    ['/missing-image.jpg', 404, 8],
    ['/api/v1/legacy', 404, 5],
    ['/docs/old-guide', 301, 4],
];
$blStmt = $db->prepare('INSERT INTO broken_links (site, path, status, hits, first_seen, last_seen) VALUES (?, ?, ?, ?, ?, ?)');
foreach ($brokenLinks as [$path, $status, $hits]) {
    $blStmt->execute([$site, $path, $status, $hits, date('Y-m-d H:i:s', strtotime('-15 days')), date('Y-m-d H:i:s', strtotime('-1 day'))]);
}

// Add some events
$eventTypes = [
    ['cta_click', '{"location":"hero"}', 45],
    ['signup', '{"plan":"pro"}', 18],
    ['download', '{"file":"guide.pdf"}', 32],
    ['phone_click', '{"number":"+46701234567"}', 12],
    ['outbound_click', '{"url":"https://github.com/webready-se/puls"}', 67],
    ['outbound_click', '{"url":"https://twitter.com/puls"}', 28],
];
$evStmt = $db->prepare('INSERT INTO events (site, event_name, event_data, page_path, visitor_hash, created_at) VALUES (?, ?, ?, ?, ?, ?)');
foreach ($eventTypes as [$name, $data, $count]) {
    for ($i = 0; $i < $count; $i++) {
        $daysAgo = mt_rand(0, 29);
        $date = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));
        $evStmt->execute([$site, $name, $data, pickWeighted($paths), substr(hash('sha256', "ev-$i"), 0, 16), $date]);
    }
}

// Add a goal
$db->prepare('INSERT INTO goals (site, path, label, created_at) VALUES (?, ?, ?, ?)')
   ->execute([$site, '/signup', 'Signup conversion', date('Y-m-d H:i:s')]);

// Create a share token for this demo site
$token = bin2hex(random_bytes(16));
$db->prepare('INSERT INTO share_tokens (token, site, label, created_at) VALUES (?, ?, ?, ?)')
   ->execute([$token, $site, 'Demo', date('Y-m-d H:i:s')]);

echo "Seeded $totalInserted pageviews to $dbPath\n";
echo "Share token: $token\n";
echo "URL: http://localhost:8081/?share=$token\n";
