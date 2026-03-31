<?php

/**
 * Tests for the sites:rename CLI command.
 *
 * Runs CLI as subprocess with env overrides for DB_PATH and USERS_FILE.
 */

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
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');

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
        . "\$_ENV['APP_KEY'] = 'test-key';\n"
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
    if (file_exists($this->dbPath)) @unlink($this->dbPath);
    if (file_exists($this->usersFile)) @unlink($this->usersFile);
    if (is_dir($this->tmpDir)) @rmdir($this->tmpDir);
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
