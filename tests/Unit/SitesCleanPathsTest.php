<?php

/**
 * Tests for the sites:clean-paths CLI command.
 *
 * Runs CLI as subprocess with env overrides for DB_PATH and USERS_FILE.
 */

function createCleanPathsDb(string $path): PDO
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

    $db->exec('CREATE TABLE events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site TEXT NOT NULL,
        event_name TEXT NOT NULL,
        event_data TEXT,
        page_path TEXT,
        visitor_hash TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');

    return $db;
}

function runCleanPaths(string $tmpDir, array $args): array
{
    $puls = realpath(__DIR__ . '/../../puls');

    $wrapper = $tmpDir . '/run.php';
    file_put_contents($wrapper, "<?php\n"
        . "\$_ENV['DB_PATH'] = " . var_export($tmpDir . '/test.sqlite', true) . ";\n"
        . "\$_ENV['USERS_FILE'] = " . var_export($tmpDir . '/users.json', true) . ";\n"
        . "\$argv = " . var_export(array_merge(['puls', 'sites:clean-paths'], $args), true) . ";\n"
        . "\$argc = count(\$argv);\n"
        . "require " . var_export($puls, true) . ";\n"
    );

    exec('php ' . escapeshellarg($wrapper) . ' 2>&1', $output, $exitCode);
    @unlink($wrapper);

    return ['output' => implode("\n", $output), 'exit' => $exitCode];
}

function pathsFor(string $dbPath, string $site): array
{
    $db = new PDO('sqlite:' . $dbPath);
    $stmt = $db->prepare('SELECT path FROM pageviews WHERE site = ? ORDER BY id');
    $stmt->execute([$site]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
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

it('strips query strings from pageviews and events of one site', function () {
    $db = createCleanPathsDb($this->dbPath);
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('intra', '/search?q=abc123&regnr=ABC123')");
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('intra', '/about')");
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('other', '/search?q=keep')");
    $db->exec("INSERT INTO events (site, event_name, page_path, visitor_hash) VALUES ('intra', 'click', '/lead?seller=x', 'h')");

    $result = runCleanPaths($this->tmpDir, ['intra', '--query']);

    expect($result['exit'])->toBe(0);
    expect($result['output'])->toContain('pageviews: 1 rows');
    expect($result['output'])->toContain('events: 1 rows');
    expect(pathsFor($this->dbPath, 'intra'))->toBe(['/search', '/about']);
    expect(pathsFor($this->dbPath, 'other'))->toBe(['/search?q=keep']);

    $db = new PDO('sqlite:' . $this->dbPath);
    expect($db->query("SELECT page_path FROM events")->fetchColumn())->toBe('/lead');
});

it('keeps only the parameters named in --keep', function () {
    $db = createCleanPathsDb($this->dbPath);
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('intra', '/list?page=2&q=secret&tab=open')");
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('intra', '/list?q=secret')");

    $result = runCleanPaths($this->tmpDir, ['intra', '--keep=page,tab']);

    expect($result['exit'])->toBe(0);
    expect(pathsFor($this->dbPath, 'intra'))->toBe(['/list?page=2&tab=open', '/list']);
});

it('changes nothing with --dry-run', function () {
    $db = createCleanPathsDb($this->dbPath);
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('intra', '/search?q=abc')");

    $result = runCleanPaths($this->tmpDir, ['intra', '--query', '--dry-run']);

    expect($result['exit'])->toBe(0);
    expect($result['output'])->toContain('pageviews: 1 rows');
    expect($result['output'])->toContain('Dry run, nothing changed.');
    expect(pathsFor($this->dbPath, 'intra'))->toBe(['/search?q=abc']);
});

it('reports when there is nothing to clean', function () {
    $db = createCleanPathsDb($this->dbPath);
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('intra', '/about')");

    $result = runCleanPaths($this->tmpDir, ['intra', '--query']);

    expect($result['exit'])->toBe(0);
    expect($result['output'])->toContain('Nothing to clean.');
});

it('fails for an unknown site', function () {
    $db = createCleanPathsDb($this->dbPath);
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('intra', '/about')");

    $result = runCleanPaths($this->tmpDir, ['nope', '--query']);

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain("Site 'nope' not found in database.");
});

it('collapses numeric segments with --ids', function () {
    $db = createCleanPathsDb($this->dbPath);
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('intra', '/lead/1234')");
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('intra', '/goal-management/location/8/edit')");
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('intra', '/v2-api/2026-report')");
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('intra', '/lead/77?q=secret')");
    $db->exec("INSERT INTO events (site, event_name, page_path, visitor_hash) VALUES ('intra', 'open', '/lead/55', 'h')");

    $result = runCleanPaths($this->tmpDir, ['intra', '--ids']);

    expect($result['exit'])->toBe(0);
    expect($result['output'])->toContain('pageviews: 3 rows');
    expect(pathsFor($this->dbPath, 'intra'))->toBe(['/lead/:id', '/goal-management/location/:id/edit', '/v2-api/2026-report', '/lead/:id?q=secret']);

    $db = new PDO('sqlite:' . $this->dbPath);
    expect($db->query("SELECT page_path FROM events")->fetchColumn())->toBe('/lead/:id');
});

it('combines --query and --ids in one pass', function () {
    $db = createCleanPathsDb($this->dbPath);
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('intra', '/lead/77?q=secret')");

    $result = runCleanPaths($this->tmpDir, ['intra', '--query', '--ids']);

    expect($result['exit'])->toBe(0);
    expect(pathsFor($this->dbPath, 'intra'))->toBe(['/lead/:id']);
});

it('requires --query, --keep or --ids', function () {
    $db = createCleanPathsDb($this->dbPath);
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('intra', '/lead/1?q=x')");

    $result = runCleanPaths($this->tmpDir, ['intra']);

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain('Nothing selected.');
    expect(pathsFor($this->dbPath, 'intra'))->toBe(['/lead/1?q=x']);
});

it('replaces token and UUID segments with --tokens', function () {
    $db = createCleanPathsDb($this->dbPath);
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('forms', '/aB3dE5fG7hJ9kL1mN2pQ4rS6tU8vW0xY')");
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('forms', '/7c01c74b-9208-4acf-91bc-4a509f150558/done')");
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('forms', '/syab-transportservice-ab')");
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('forms', '/internationalization')");
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('forms', '/short1')");
    $db->exec("INSERT INTO events (site, event_name, page_path, visitor_hash) VALUES ('forms', 'submit', '/aB3dE5fG7hJ9kL1mN2pQ4rS6tU8vW0xY', 'h')");

    $result = runCleanPaths($this->tmpDir, ['forms', '--tokens']);

    expect($result['exit'])->toBe(0);
    expect($result['output'])->toContain('pageviews: 2 rows');
    expect(pathsFor($this->dbPath, 'forms'))->toBe(['/:token', '/:token/done', '/syab-transportservice-ab', '/internationalization', '/short1']);

    $db = new PDO('sqlite:' . $this->dbPath);
    expect($db->query("SELECT page_path FROM events")->fetchColumn())->toBe('/:token');
});

it('drops the daily summary of the site whose paths it cleaned', function () {
    $db = createCleanPathsDb($this->dbPath);
    create_daily_table($db);
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('intra', '/search?q=abc123')");
    $db->exec("INSERT INTO daily (site, date, kind, key, views) VALUES ('intra', '2026-03-10', 'path', '/search?q=abc123', 1), ('other', '2026-03-10', 'path', '/', 1)");

    expect(runCleanPaths($this->tmpDir, ['intra', '--query'])['exit'])->toBe(0);
    $sites = (new PDO('sqlite:' . $this->dbPath))->query('SELECT site FROM daily')->fetchAll(PDO::FETCH_COLUMN);
    // The summary is a cache: the dashboard sums the site's days again from the cleaned paths
    expect($sites)->toBe(['other']);
});
