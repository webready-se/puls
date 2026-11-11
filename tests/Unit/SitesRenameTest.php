<?php

/**
 * Tests for the sites:rename CLI command.
 *
 * Runs CLI as subprocess with env overrides for DB_PATH and USERS_FILE.
 */

require_once __DIR__ . '/../Support/helpers.php';

function createTestDb(string $path): PDO
{
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec('CREATE TABLE pageviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site TEXT NOT NULL,
        path TEXT NOT NULL,
        visitor_hash TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');

    $db->exec('CREATE TABLE bot_visits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site TEXT NOT NULL,
        path TEXT NOT NULL,
        bot_name TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');

    $db->exec('CREATE TABLE broken_links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site TEXT NOT NULL,
        path TEXT NOT NULL,
        status INTEGER NOT NULL,
        referrers TEXT,
        hits INTEGER DEFAULT 1,
        first_seen TEXT DEFAULT CURRENT_TIMESTAMP,
        last_seen TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $db->exec('CREATE UNIQUE INDEX idx_broken_unique ON broken_links (site, path, status)');

    $db->exec('CREATE TABLE share_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token TEXT NOT NULL UNIQUE,
        site TEXT NOT NULL,
        label TEXT,
        expires_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');

    $db->exec('CREATE TABLE events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site TEXT NOT NULL,
        event_name TEXT NOT NULL,
        event_data TEXT,
        page_path TEXT,
        visitor_hash TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');

    $db->exec('CREATE TABLE goals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site TEXT NOT NULL,
        path TEXT NOT NULL,
        label TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $db->exec('CREATE UNIQUE INDEX idx_goals_unique ON goals (site, path)');

    return $db;
}

function runRename(string $tmpDir, ?string $old = null, ?string $new = null): array
{
    $args = 'sites:rename';
    if ($old !== null) $args .= ' ' . escapeshellarg($old);
    if ($new !== null) $args .= ' ' . escapeshellarg($new);

    $puls = realpath(__DIR__ . '/../../puls');

    // Write a temp .env in project root dir won't work (would clobber real one).
    // Instead, use a wrapper script that sets $_ENV before loading puls.
    $wrapper = $tmpDir . '/run.php';
    file_put_contents($wrapper, "<?php\n"
        . "\$_ENV['DB_PATH'] = " . var_export($tmpDir . '/test.sqlite', true) . ";\n"
        . "\$_ENV['USERS_FILE'] = " . var_export($tmpDir . '/users.json', true) . ";\n"
        . "// Override \$argv\n"
        . "\$argv = ['puls', 'sites:rename'"
        . ($old !== null ? ", " . var_export($old, true) : "")
        . ($new !== null ? ", " . var_export($new, true) : "")
        . "];\n"
        . "\$argc = count(\$argv);\n"
        . "require " . var_export($puls, true) . ";\n"
    );

    $cmd = "php " . escapeshellarg($wrapper) . " 2>&1";
    exec($cmd, $output, $exitCode);
    @unlink($wrapper);

    return ['output' => implode("\n", $output), 'exit' => $exitCode];
}

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/puls_test_' . uniqid();
    mkdir($this->tmpDir);
    $this->dbPath = $this->tmpDir . '/test.sqlite';
    $this->usersFile = $this->tmpDir . '/users.json';
    file_put_contents($this->usersFile, '{}');
});

afterEach(function () {
    // The merge tests open the database in WAL mode, which leaves -wal and -shm files beside it
    exec('rm -rf ' . escapeshellarg($this->tmpDir));
});

it('renames site in all tables', function () {
    $db = createTestDb($this->dbPath);
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('old-site', '/')");
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('old-site', '/about')");
    $db->exec("INSERT INTO bot_visits (site, path, bot_name) VALUES ('old-site', '/', 'Googlebot')");
    $db->exec("INSERT INTO broken_links (site, path, status) VALUES ('old-site', '/missing', 404)");

    $result = runRename($this->tmpDir, 'old-site', 'new-site');

    expect($result['exit'])->toBe(0);
    expect($result['output'])->toContain('pageviews: 2 rows updated');
    expect($result['output'])->toContain('bot_visits: 1 rows updated');
    expect($result['output'])->toContain('broken_links: 1 rows updated');

    // Verify database state
    $db = new PDO('sqlite:' . $this->dbPath);
    $count = $db->query("SELECT COUNT(*) FROM pageviews WHERE site = 'new-site'")->fetchColumn();
    expect((int) $count)->toBe(2);

    $count = $db->query("SELECT COUNT(*) FROM pageviews WHERE site = 'old-site'")->fetchColumn();
    expect((int) $count)->toBe(0);
});

it('updates users.json site restrictions', function () {
    $db = createTestDb($this->dbPath);
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('old-site', '/')");

    file_put_contents($this->usersFile, json_encode([
        'admin' => ['password' => '$2y$12$fake', 'sites' => []],
        'client' => ['password' => '$2y$12$fake', 'sites' => ['old-site', 'other-site']],
    ]));

    $result = runRename($this->tmpDir, 'old-site', 'new-site');

    expect($result['exit'])->toBe(0);
    expect($result['output'])->toContain('users.json: 1 user(s) updated');

    $users = json_decode(file_get_contents($this->usersFile), true);
    expect($users['client']['sites'])->toBe(['new-site', 'other-site']);
    expect($users['admin']['sites'])->toBe([]);
});

it('rejects rename to existing site', function () {
    $db = createTestDb($this->dbPath);
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('site-a', '/')");
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('site-b', '/')");

    $result = runRename($this->tmpDir, 'site-a', 'site-b');

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain('already exists');
});

it('rejects rename of nonexistent site', function () {
    $db = createTestDb($this->dbPath);
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('real-site', '/')");

    $result = runRename($this->tmpDir, 'ghost', 'new-name');

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain('not found');
});

it('shows no sites message when args missing and db empty', function () {
    createTestDb($this->dbPath);

    $result = runRename($this->tmpDir);

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain('No sites tracked');
});

it('rejects same old and new name', function () {
    $db = createTestDb($this->dbPath);
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('my-site', '/')");

    $result = runRename($this->tmpDir, 'my-site', 'my-site');

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain('same');
});

// Safeguard: ensure sites:rename covers all tables with a site column
it('covers all database tables that have a site column', function () {
    // Extract table names from sites:rename in CLI
    $cliSource = file_get_contents(__DIR__ . '/../../puls');
    // Match: $tables = ['pageviews', 'bot_visits', 'broken_links'];
    preg_match('/\$tables\s*=\s*\[([^\]]+)\]/', $cliSource, $match);
    expect($match)->not->toBeEmpty('Could not find $tables array in puls CLI');
    preg_match_all("/'(\w+)'/", $match[1], $tableMatches);
    $renameTables = $tableMatches[1];
    sort($renameTables);

    // Extract all CREATE TABLE statements from index.php that have a site column
    $indexSource = file_get_contents(__DIR__ . '/../../public/index.php');
    preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?(\w+)\s*\(([^)]+)\)/s', $indexSource, $matches, PREG_SET_ORDER);

    $tablesWithSite = [];
    foreach ($matches as $m) {
        $tableName = $m[1];
        $columns = $m[2];
        // Skip temporary migration tables (e.g. broken_links_new)
        if (str_ends_with($tableName, '_new') || str_ends_with($tableName, '_tmp')) continue;
        if (str_contains($columns, 'site TEXT')) {
            $tablesWithSite[$tableName] = true;
        }
    }
    $tablesWithSite = array_keys($tablesWithSite);
    sort($tablesWithSite);

    expect($renameTables)->toBe($tablesWithSite)
        ->and($renameTables)
        ->not->toBeEmpty();
});

// --merge, on the real schema and its unique keys: the new name already has visits, as when a
// site's script sends its new name before the old one is renamed

function mergeDb(string $path): PDO
{
    $db = get_db($path);
    $db->exec("INSERT INTO pageviews (site, path, visitor_hash, created_at) VALUES
        ('shop', '/', 'a', '2026-11-01 10:00:00'),
        ('shop', '/', 'b', '2026-11-02 10:00:00'),
        ('shop.example', '/', 'c', '2026-11-02 11:00:00')");
    return $db;
}

it('refuses a name with visits, and says how to merge into it', function () {
    mergeDb($this->dbPath);

    $result = runCli($this->tmpDir, 'sites:rename', ['shop', 'shop.example']);

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain('already exists')->toContain('--merge');
});

it('merges a site into one that already has visits with --merge', function () {
    $db = mergeDb($this->dbPath);

    $result = runCli($this->tmpDir, 'sites:rename', ['shop', '--merge', 'shop.example']);

    expect($result['exit'])->toBe(0);
    expect($result['output'])
        ->toContain('pageviews: 2 rows moved')
        ->toContain("Merged 'shop' into 'shop.example'")
        ->toContain('1 day had visits under both names');
    expect($db->query('SELECT site, COUNT(*) FROM pageviews GROUP BY site')->fetchAll(PDO::FETCH_NUM))
        ->toBe([['shop.example', 3]]);
});

it('renames as usual with --merge when the new name has no visits', function () {
    $db = mergeDb($this->dbPath);

    $result = runCli($this->tmpDir, 'sites:rename', ['shop', 'shop.se', '--merge']);

    expect($result['exit'])->toBe(0);
    expect($result['output'])->toContain("Renamed 'shop'");
    expect($db->query("SELECT COUNT(*) FROM pageviews WHERE site = 'shop.se'")->fetchColumn())->toBe(2);
});

it('adds up the bot counts and broken links the two names share', function () {
    $db = mergeDb($this->dbPath);
    $bot = $db->prepare("INSERT INTO bot_visits (site, path, bot_name, bot_category, user_agent, created_at) VALUES (?, '/', ?, 'Search engine', '', '2026-11-01 10:00:00')");
    $bot->execute(['shop', 'Googlebot']);
    $bot->execute(['shop', 'Bingbot']);
    $bot->execute(['shop.example', 'Googlebot']);
    $link = $db->prepare('INSERT INTO broken_links (site, path, status, referrers, hits, first_seen, last_seen) VALUES (?, ?, 404, ?, ?, ?, ?)');
    $link->execute(['shop', '/old', 'a.example,b.example', 3, '2026-10-01 10:00:00', '2026-11-01 10:00:00']);
    $link->execute(['shop.example', '/old', 'b.example,c.example', 2, '2026-11-02 10:00:00', '2026-11-05 10:00:00']);
    $link->execute(['shop', '/gone', null, 1, '2026-10-20 10:00:00', '2026-10-20 10:00:00']);

    $result = runCli($this->tmpDir, 'sites:rename', ['shop', 'shop.example', '--merge']);

    expect($result['exit'])->toBe(0);
    expect($db->query('SELECT site, date, name, visits FROM bot_days ORDER BY name')->fetchAll(PDO::FETCH_NUM))->toBe([
        ['shop.example', '2026-11-01', 'Bingbot', 1],
        ['shop.example', '2026-11-01', 'Googlebot', 2],
    ]);
    expect($db->query('SELECT site, path, referrers, hits, first_seen, last_seen FROM broken_links ORDER BY path')->fetchAll(PDO::FETCH_NUM))->toBe([
        ['shop.example', '/gone', null, 1, '2026-10-20 10:00:00', '2026-10-20 10:00:00'],
        ['shop.example', '/old', 'b.example,c.example,a.example', 5, '2026-10-01 10:00:00', '2026-11-05 10:00:00'],
    ]);
});

it('keeps the settings the new name already has, and moves the rest', function () {
    $db = mergeDb($this->dbPath);
    $db->exec("INSERT INTO goals (site, path, label, type, target, created_at) VALUES
        ('shop', '/thanks', 'Old', 'page', 10, '2026-11-01'), ('shop', '/signup', NULL, 'page', NULL, '2026-11-01'),
        ('shop.example', '/thanks', 'New', 'page', NULL, '2026-11-02')");
    $db->exec("INSERT INTO excluded_ips (site, ip, created_at) VALUES ('shop', '192.0.2.1', '2026-11-01'), ('shop.example', '192.0.2.1', '2026-11-02')");
    $db->exec("INSERT INTO site_groups (site, group_name) VALUES ('shop', 'Shops'), ('shop.example', 'Clients')");
    $db->exec("INSERT INTO breakdowns (site, event_name, group_key, created_at, position) VALUES
        ('shop.example', 'signup', 'plan', '2026-11-02', 0),
        ('shop', 'click', 'button', '2026-11-01', 0), ('shop', 'signup', 'plan', '2026-11-01', 1), ('shop', 'buy', 'item', '2026-11-01', 2)");
    $db->exec("INSERT INTO app_versions (site, version, first_seen) VALUES ('shop', '1.2', '2026-10-01 10:00:00'), ('shop.example', '1.2', '2026-11-02 11:00:00')");
    $db->exec("INSERT INTO annotations (site, date, label, created_at) VALUES ('shop', '2026-11-01', 'Launch', '2026-11-01')");

    $result = runCli($this->tmpDir, 'sites:rename', ['shop', 'shop.example', '--merge']);

    expect($result['exit'])->toBe(0);
    expect($result['output'])->toContain("goals: 1 rows moved, 1 already in 'shop.example', kept as it is");
    expect($db->query("SELECT COUNT(*) FROM goals WHERE site = 'shop'")->fetchColumn())->toBe(0);
    expect($db->query("SELECT path, label FROM goals ORDER BY path")->fetchAll(PDO::FETCH_NUM))->toBe([['/signup', null], ['/thanks', 'New']]);
    expect($db->query("SELECT site, ip FROM excluded_ips")->fetchAll(PDO::FETCH_NUM))->toBe([['shop.example', '192.0.2.1']]);
    expect($db->query("SELECT site, group_name FROM site_groups")->fetchAll(PDO::FETCH_NUM))->toBe([['shop.example', 'Clients']]);
    // The old site's cards follow the new site's own, in their order, without the one it already has
    expect($db->query("SELECT event_name FROM breakdowns WHERE site = 'shop.example' ORDER BY position")->fetchAll(PDO::FETCH_COLUMN))
        ->toBe(['signup', 'click', 'buy']);
    expect($db->query("SELECT site, first_seen FROM app_versions")->fetchAll(PDO::FETCH_NUM))->toBe([['shop.example', '2026-10-01 10:00:00']]);
    expect($db->query("SELECT site FROM annotations")->fetchColumn())->toBe('shop.example');
});

it('keeps one post where the same post is under both names, and moves the rest', function () {
    $db = mergeDb($this->dbPath);
    $db->exec("INSERT INTO posts (site, post_id, at, sources, created_at) VALUES
        ('shop', 'instagram:1', '2026-11-01 09:00:00', 'instagram', '2026-11-01 09:05:00'),
        ('shop', 'instagram:2', '2026-11-02 09:00:00', 'instagram', '2026-11-02 09:05:00'),
        ('shop.example', 'instagram:1', '2026-11-01 09:00:00', 'instagram', '2026-11-01 09:05:00')");

    $result = runCli($this->tmpDir, 'sites:rename', ['shop', 'shop.example', '--merge']);

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toContain("posts: 1 rows moved, 1 already in 'shop.example', kept as it is")
        ->and($db->query('SELECT site, post_id FROM posts ORDER BY post_id')->fetchAll(PDO::FETCH_NUM))
        ->toBe([['shop.example', 'instagram:1'], ['shop.example', 'instagram:2']]);
});

it('clears the daily summary of both names, to be summed again', function () {
    $db = mergeDb($this->dbPath);
    $db->exec("INSERT INTO daily (site, date, kind, key) VALUES ('shop', '2026-11-01', 'day', ''), ('shop.example', '2026-11-02', 'day', ''), ('other.example', '2026-11-02', 'day', '')");

    runCli($this->tmpDir, 'sites:rename', ['shop', 'shop.example', '--merge']);

    expect($db->query('SELECT site FROM daily')->fetchAll(PDO::FETCH_COLUMN))->toBe(['other.example']);
});

it('gives a user with both names the new one once', function () {
    mergeDb($this->dbPath);
    file_put_contents($this->usersFile, json_encode([
        'client' => ['password' => '$2y$12$fake', 'sites' => ['shop', 'shop.example', 'other']],
    ]));

    runCli($this->tmpDir, 'sites:rename', ['shop', 'shop.example', '--merge']);

    $users = json_decode(file_get_contents($this->usersFile), true);
    expect($users['client']['sites'])->toBe(['shop.example', 'other']);
});

it('stores the new name as the tracker would, so the script lands on it', function () {
    $db = mergeDb($this->dbPath);

    runCli($this->tmpDir, 'sites:rename', ['shop', ' xn--rksmrgs-5wao1o.example ']);

    expect($db->query("SELECT DISTINCT site FROM pageviews ORDER BY site")->fetchAll(PDO::FETCH_COLUMN))
        ->toBe(['räksmörgås.example', 'shop.example']);
})->skip(!function_exists('idn_to_utf8'), 'needs the intl extension');
