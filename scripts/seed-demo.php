<?php
/**
 * Seed a demo database with realistic-looking fake data, for the README images, the smoke test and
 * the live demo.
 * Usage: php scripts/seed-demo.php [db_path] [--token=<token>] [--group=<name>] [--refresh | --top-up]
 *
 *   --token=<token>  the share token to make, so a link stays the same from one reseed to the next
 *   --group=<name>   the three sites in a group, and the token reads the group (site picker, All sites)
 *   --refresh        reseed an existing database in place, in one transaction, rather than a new file:
 *                    a file replaced under an open WAL database can be broken by the old one's WAL, and
 *                    in place a reader sees the old demo or the new one, never half of either
 *   --top-up         add the visits, events and bot visits since the latest visit, up to now (at most a
 *                    day back), so today looks like a site in use between two reseeds
 */

$opts = ['token' => null, 'group' => null, 'refresh' => false, 'top-up' => false];
$dbPath = 'data/demo.sqlite';
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '-')) {
        $dbPath = $arg;
        continue;
    }
    [$name, $value] = array_pad(explode('=', ltrim($arg, '-'), 2), 2, null);
    $takesValue = in_array($name, ['token', 'group'], true);
    if (!array_key_exists($name, $opts) || $takesValue !== ($value !== null)) {
        fwrite(STDERR, "Unknown option {$arg}. The seed takes --token=<token>, --group=<name>, --refresh and --top-up\n");
        exit(1);
    }
    $opts[$name] = $value ?? true;
}
// The token is the demo's whole lock: a short one could be guessed
if ($opts['token'] !== null && !preg_match('/^[A-Za-z0-9_]{16,}$/', $opts['token'])) {
    fwrite(STDERR, "--token needs at least 16 letters, digits or _\n");
    exit(1);
}
if ($opts['refresh'] && $opts['top-up']) {
    fwrite(STDERR, "--refresh and --top-up are separate runs: reseed at night, top up through the day\n");
    exit(1);
}
if ($opts['top-up'] && !file_exists($dbPath)) {
    fwrite(STDERR, "Nothing to top up: {$dbPath} does not exist. Seed it first\n");
    exit(1);
}

$fresh = !($opts['refresh'] || $opts['top-up']) || !file_exists($dbPath);
if ($fresh && file_exists($dbPath)) unlink($dbPath);

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA journal_mode=WAL');
// A live demo is read while it is topped up or reseeded: wait for a reader rather than fail
$db->exec('PRAGMA busy_timeout = 5000');

// Create schema (copy from index.php run_migrations end-state), on a new file only: Puls migrates
// it on the first request, and a database it has migrated keeps its own
if ($fresh) {
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
        created_at TEXT NOT NULL,
        engaged_ms INTEGER
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
        created_at TEXT NOT NULL,
        group_names TEXT,
        last_used_at TEXT
    )');
    // Groups, as migration v27 makes the table, for a token that reads one
    $db->exec('CREATE TABLE IF NOT EXISTS site_groups (
        site TEXT PRIMARY KEY,
        group_name TEXT NOT NULL
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
}

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
$shopPaths = [
    ['/', 30],
    ['/products', 20],
    ['/products/linen-shirt', 12],
    ['/products/wool-socks', 9],
    ['/cart', 8],
    ['/checkout', 4],
    ['/shipping', 3],
    ['/about', 2],
];
$blogPaths = [
    ['/', 25],
    ['/posts/slow-mornings', 14],
    ['/posts/winter-garden', 10],
    ['/posts/sourdough-starter', 8],
    ['/archive', 4],
    ['/about', 3],
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
    ['ig', 'paid', 'autumn-sale'],
    ['fb', 'paid', 'autumn-sale'],
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

$stmt = $db->prepare('INSERT INTO pageviews (site, path, referrer, browser, device, visitor_hash, utm_source, utm_medium, utm_campaign, language, country, created_at, engaged_ms) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

// Generate 30 days of data, ~200-500 pageviews per day
// with a trend: growing traffic over the period
$totalInserted = 0;
$now = time();
// A visit is one to five pages, 20 s to 3 min apart, so session lengths look real.
// Visits start between 06 and 23, most of them in office hours.
$sessionPages = [[1, 45], [2, 25], [3, 15], [4, 10], [5, 5]];
$startHours = [[6, 2], [7, 4], [8, 7], [9, 10], [10, 11], [11, 11], [12, 9], [13, 10], [14, 11], [15, 10], [16, 8], [17, 6], [18, 5], [19, 5], [20, 5], [21, 4], [22, 3]];
$addVisit = function (string $site, array $paths, string $visitor, int $start) use ($stmt, $referrers, $browsers, $devices, $countries, $languages, $campaigns, $sessionPages, $now, &$totalInserted) {
    // Source, browser and place belong to the visitor, not to each page
    $referrer = pickWeighted($referrers);
    $browser = pickWeighted($browsers);
    $device = pickWeighted($devices);
    $country = pickWeighted($countries);
    $lang = pickWeighted($languages);
    $campaign = $campaigns[array_rand($campaigns)];
    $t = $start;
    for ($p = pickWeighted($sessionPages); $p > 0 && $t <= $now; $p--) {
        // Engaged time: most of the wait before the next page, or for the last one anything from
        // under a second to four minutes
        $gap = mt_rand(20, 180);
        $engaged = $p > 1 ? (int) ($gap * mt_rand(40, 95) * 10) : (mt_rand(0, 5) === 0 ? 0 : mt_rand(3, 240) * 1000);
        $stmt->execute([
            $site, pickWeighted($paths), $referrer, $browser, $device, $visitor,
            $campaign[0] ?? null, $campaign[1] ?? null, $campaign[2] ?? null,
            $lang, $country, date('Y-m-d H:i:s', $t), $engaged,
        ]);
        $totalInserted++;
        $t += $gap;
    }
};
// The demo site and two smaller ones with their own pages and trends, so the site picker,
// the site overview and All sites have something to show. Per site: pages, pageviews on a
// weekday at the start of the period, growth over the period, visitors online now.
// The demo site grows +90% with modest noise, so its last 7 days beat the 7 before once about
// half of today has passed: before midday today's few visits pull the 7-day trend below zero
// (README images show it, so readme-media.mjs warns before 12:00); the blog declines.
$trafficSites = [
    [$site, $paths, 220, 0.9, 4],
    ['shop.example.com', $shopPaths, 70, 0.5, 1],
    ['blog.example.com', $blogPaths, 25, -0.3, 0],
];
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
$eventTypes = [
    ['cta_click', '{"location":"hero"}', 45],
    ['cta_click', '{"location":"pricing"}', 26],
    ['cta_click', '{"location":"footer"}', 12],
    ['cta_click', '{"location":"blog"}', 7],
    ['signup', '{"plan":"pro"}', 18],
    ['signup', '{"plan":"starter"}', 11],
    ['download', '{"file":"guide.pdf"}', 32],
    ['phone_click', '{"number":"+46701234567"}', 12],
    ['outbound_click', '{"url":"https://github.com/webready-se/puls"}', 67],
    ['outbound_click', '{"url":"https://twitter.com/puls"}', 28],
];
$evStmt = $db->prepare('INSERT INTO events (site, event_name, event_data, page_path, visitor_hash, created_at) VALUES (?, ?, ?, ?, ?, ?)');
$someone = fn () => substr(hash('sha256', random_bytes(8)), 0, 16);
// When the demo was last seeded or topped up, so the next top-up starts there
$toppedUp = $dbPath . '.topped-up';

// One transaction: a reader of the live demo sees it before or after, and a seed is quick
$db->exec('BEGIN IMMEDIATE');

if ($opts['top-up']) {
    // From the last top-up (or seed), at most a day back, to now, a minute at a time: a minute holds
    // as many visits as the site's last day of the seed would give it at that hour, events and bots
    // alike. Not from the latest visit, which can be minutes before the last run in a quiet hour:
    // those minutes would be drawn again, and the day would fill up faster than it should
    $last = is_file($toppedUp) ? (int) file_get_contents($toppedUp) : (strtotime((string) $db->query('SELECT MAX(created_at) FROM pageviews')->fetchColumn()) ?: 0);
    $from = max($last + 1, $now - 86400);
    $hourWeights = array_column($startHours, 1, 0);
    $weightSum = array_sum($hourWeights);
    $chance = fn (float $expected) => (int) floor($expected) + (mt_rand() / mt_getrandmax() < fmod($expected, 1) ? 1 : 0);
    $eventsPerMinute = array_sum(array_column($eventTypes, 2)) / 30 / 1440;
    $botsPerMinute = array_sum(array_column($bots, 2)) / 30 / 1440;
    $events = $botVisits = 0;
    for ($t = $from; $t <= $now; $t += 60) {
        $weekend = in_array((int) date('w', $t), [0, 6], true) ? 0.5 : 1.0;
        $hourShare = ($hourWeights[(int) date('G', $t)] ?? 0) / $weightSum;
        foreach ($trafficSites as [$name, $sitePaths, $perDay, $growth]) {
            for ($n = $chance($perDay * (1 + $growth) * $weekend / 2 * $hourShare / 60); $n > 0; $n--) {
                $addVisit($name, $sitePaths, $someone(), min($now, $t + mt_rand(0, 59)));
            }
        }
        for ($n = $chance($eventsPerMinute); $n > 0; $n--) {
            [$name, $data] = $eventTypes[array_rand($eventTypes)];
            $evStmt->execute([$site, $name, $data, pickWeighted($paths), $someone(), date('Y-m-d H:i:s', $t)]);
            $events++;
        }
        for ($n = $chance($botsPerMinute); $n > 0; $n--) {
            [$name, $cat] = pickWeighted(array_map(fn ($b) => [$b, $b[2]], $bots));
            $botStmt->execute([$site, pickWeighted($paths), $name, $cat, "Mozilla/5.0 $name", date('Y-m-d H:i:s', $t)]);
            $botVisits++;
        }
    }
    $db->exec('COMMIT');
    file_put_contents($toppedUp, (string) $now);
    echo "Topped up $totalInserted pageviews, $events events and $botVisits bot visits since " . date('Y-m-d H:i:s', $from) . " in $dbPath\n";
    exit(0);
}

if (!$fresh) {
    // Reseed in place: what the seed makes goes, and so does the summary summed from it, which
    // Puls sums again as it is read. Share tokens, groups and the rest stay
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['pageviews', 'bot_visits', 'bot_days', 'events', 'broken_links', 'posts', 'goals', 'daily'] as $table) {
        if (in_array($table, $tables, true)) $db->exec("DELETE FROM {$table}");
    }
}

foreach ($trafficSites as [$name, $sitePaths, $perDay, $growth, $online]) {
    for ($daysAgo = 29; $daysAgo >= 0; $daysAgo--) {
        // Weekends lower
        $dayOfWeek = (int) date('w', strtotime("-{$daysAgo} days"));
        $weekendMult = ($dayOfWeek === 0 || $dayOfWeek === 6) ? 0.5 : 1.0;
        $growthMult = 1 + ((30 - $daysAgo) / 30) * $growth;
        $pageviewsToday = (int) ($perDay * $weekendMult * $growthMult * (0.9 + (mt_rand(0, 20) / 100)));

        // About two pages per visit, never after now
        $visitorsToday = (int) ($pageviewsToday / 2);
        for ($v = 0; $v < $visitorsToday; $v++) {
            $start = strtotime("-{$daysAgo} days " . sprintf('%02d:%02d:%02d', pickWeighted($startHours), mt_rand(0, 59), mt_rand(0, 59)));
            $addVisit($name, $sitePaths, substr(hash('sha256', "visitor-$name-$daysAgo-$v"), 0, 16), $start);
        }
    }
    // A few visitors in the last minutes, for the online-now badge
    for ($v = 0; $v < $online; $v++) {
        $addVisit($name, $sitePaths, substr(hash('sha256', "visitor-live-$name-$v"), 0, 16), $now - mt_rand(30, 240));
    }
}

// Add some bot visits
foreach ($bots as [$name, $cat, $count]) {
    for ($i = 0; $i < $count; $i++) {
        // Any moment in the last 30 days, never after now
        $date = date('Y-m-d H:i:s', $now - mt_rand(0, 30 * 86400));
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

// Posts a tool on the same server would hand over (php puls posts:add), a dot on their day. The
// table as migration v34 makes it, since this database starts on the old schema. All over a week
// ago: the smoke test seeds its own in the last seven days and counts them
$db->exec('CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site TEXT NOT NULL,
    post_id TEXT NOT NULL,
    at TEXT NOT NULL,
    sources TEXT NOT NULL,
    text TEXT,
    url TEXT,
    created_at TEXT NOT NULL
)');
$postStmt = $db->prepare('INSERT INTO posts (site, post_id, at, sources, text, url, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
foreach ([
    [26, '08:30', 'instagram,facebook', 'Spring collection: a first look'],
    [22, '19:10', 'facebook', 'Open late on Thursday'],
    [18, '12:05', 'instagram', 'Behind the scenes in the workshop'],
    [15, '08:45', 'instagram,facebook', '20% off linen this weekend'],
    [11, '17:30', 'rss', 'New on the blog: how we choose our fabrics'],
    [8, '09:15', 'instagram', 'Back in stock'],
] as $n => [$daysAgo, $time, $sources, $text]) {
    $at = date('Y-m-d', strtotime("-{$daysAgo} days")) . " {$time}:00";
    $postStmt->execute([$site, "demo:{$n}", $at, $sources, $text, "https://example.com/p/{$n}", $at]);
}

// The three sites in a group, for a token that reads it
if ($opts['group'] !== null) {
    $groupStmt = $db->prepare('INSERT OR REPLACE INTO site_groups (site, group_name) VALUES (?, ?)');
    foreach ($trafficSites as [$name]) $groupStmt->execute([$name, $opts['group']]);
}

// A share token: the one given, kept across reseeds, or a new one for the demo site. A token that
// reads a group has no site of its own
$token = $opts['token'] ?? bin2hex(random_bytes(16));
$existing = $db->prepare('SELECT COUNT(*) FROM share_tokens WHERE token = ?');
$existing->execute([$token]);
if (!$existing->fetchColumn()) {
    $db->prepare('INSERT INTO share_tokens (token, site, label, created_at, group_names) VALUES (?, ?, ?, ?, ?)')
       ->execute([$token, $opts['group'] !== null ? '' : $site, 'Demo', date('Y-m-d H:i:s'), $opts['group'] !== null ? json_encode([$opts['group']]) : null]);
}

$db->exec('COMMIT');
file_put_contents($toppedUp, (string) $now);

echo ($fresh ? 'Seeded' : 'Reseeded') . " $totalInserted pageviews on " . count($trafficSites) . " sites to $dbPath\n";
echo "Share token: $token\n";
echo "URL: http://localhost:8081/?share=$token\n";
