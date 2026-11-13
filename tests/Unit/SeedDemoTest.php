<?php

/**
 * The demo seed. Without options it makes the database the README images and the smoke test read.
 * The live demo needs a link that survives the nightly reseed, a token that reads a group of the
 * three sites, a reseed in place (a file replaced under an open WAL database can be broken by the
 * old WAL), and visits topped up through the day.
 */

$seed = function (string $db, array $args = []): array {
    exec(implode(' ', array_map('escapeshellarg', array_merge([PHP_BINARY, __DIR__ . '/../../scripts/seed-demo.php', $db], $args))) . ' 2>&1', $out, $exit);
    return ['exit' => $exit, 'output' => implode("\n", $out)];
};

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/puls_seed_test_' . uniqid();
    mkdir($this->dir);
    $this->db = $this->dir . '/demo.sqlite';
    $this->token = 'puls_' . str_repeat('ab12', 10);
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->dir));
});

it('seeds a fresh demo with a new token for one site, as the README images and smoke test expect', function () use ($seed) {
    $result = $seed($this->db);

    $db = new PDO('sqlite:' . $this->db);
    $tokens = $db->query('SELECT token, site FROM share_tokens')->fetchAll(PDO::FETCH_ASSOC);
    expect($result['exit'])->toBe(0)
        ->and($tokens)->toHaveCount(1)
        ->and($tokens[0]['site'])->toBe('demo.example.com')
        ->and($result['output'])->toContain('Share token: ' . $tokens[0]['token']);
});

it('keeps its link: a given token reads a group of the three sites', function () use ($seed) {
    $result = $seed($this->db, ['--token=' . $this->token, '--group=Demo']);

    $db = new PDO('sqlite:' . $this->db);
    $token = $db->query('SELECT token, site, group_names FROM share_tokens')->fetchAll(PDO::FETCH_ASSOC);
    $groups = $db->query('SELECT site, group_name FROM site_groups ORDER BY site')->fetchAll(PDO::FETCH_KEY_PAIR);
    expect($result['exit'])->toBe(0)
        ->and($token)->toBe([['token' => $this->token, 'site' => '', 'group_names' => '["Demo"]']])
        ->and($groups)->toBe(['blog.example.com' => 'Demo', 'demo.example.com' => 'Demo', 'shop.example.com' => 'Demo'])
        ->and($result['output'])->toContain('Share token: ' . $this->token);

    // A web request migrates the database; the link still reads the group after that
    $migrated = get_db($this->db);
    expect(share_token_sites($migrated, $migrated->query('SELECT * FROM share_tokens')->fetch(PDO::FETCH_ASSOC)))
        ->toBe(['blog.example.com', 'demo.example.com', 'shop.example.com']);
});

it('reseeds in place: the same file, its link kept, every visit new and the summary dropped', function () use ($seed) {
    $seed($this->db, ['--token=' . $this->token, '--group=Demo']);
    $live = get_db($this->db);
    $live->exec("INSERT INTO pageviews (site, path, visitor_hash, created_at) VALUES ('demo.example.com', '/from-yesterday', 'x', '2020-01-01 10:00:00')");
    $live->exec("INSERT INTO daily (site, date, kind, key, views, visitors) VALUES ('demo.example.com', '2020-01-01', 'day', '', 1, 1)");
    $inode = fileinode($this->db);

    $result = $seed($this->db, ['--token=' . $this->token, '--group=Demo', '--refresh']);

    clearstatcache();
    $db = new PDO('sqlite:' . $this->db);
    expect($result['exit'])->toBe(0)
        ->and(fileinode($this->db))->toBe($inode)
        ->and((int) $db->query("SELECT COUNT(*) FROM pageviews WHERE path = '/from-yesterday'")->fetchColumn())->toBe(0)
        ->and((int) $db->query('SELECT COUNT(*) FROM pageviews')->fetchColumn())->toBeGreaterThan(1000)
        ->and((int) $db->query('SELECT COUNT(*) FROM daily')->fetchColumn())->toBe(0)
        ->and($db->query('SELECT token FROM share_tokens')->fetchAll(PDO::FETCH_COLUMN))->toBe([$this->token])
        ->and((int) $db->query('SELECT COUNT(*) FROM goals')->fetchColumn())->toBe(1)
        ->and((int) $db->query('SELECT COUNT(*) FROM posts')->fetchColumn())->toBe(6);
});

it('tops up the visits since the last one, and none after now', function () use ($seed) {
    $seed($this->db);
    $db = get_db($this->db);
    // As if the last top-up was a day ago: a day holds visits at any hour the test runs
    $dayAgo = date('Y-m-d H:i:s', time() - 86400);
    $db->prepare('DELETE FROM pageviews WHERE created_at > ?')->execute([$dayAgo]);
    file_put_contents($this->db . '.topped-up', (string) (time() - 86400));
    $before = (int) $db->query('SELECT COUNT(*) FROM pageviews')->fetchColumn();

    $result = $seed($this->db, ['--top-up']);

    $added = $db->prepare('SELECT COUNT(*) FROM pageviews WHERE created_at > ?');
    $added->execute([$dayAgo]);
    expect($result['exit'])->toBe(0)
        ->and((int) $added->fetchColumn())->toBeGreaterThan(50)
        ->and((int) $db->query('SELECT COUNT(*) FROM pageviews')->fetchColumn())->toBeGreaterThan($before)
        ->and($db->query('SELECT MAX(created_at) FROM pageviews')->fetchColumn())->toBeLessThanOrEqual(date('Y-m-d H:i:s'));

    // Run again at once: the minutes since the last run are none, so nothing is drawn twice
    $total = (int) $db->query('SELECT COUNT(*) FROM pageviews')->fetchColumn();
    $seed($this->db, ['--top-up']);
    expect((int) $db->query('SELECT COUNT(*) FROM pageviews')->fetchColumn())->toBeLessThanOrEqual($total + 3);
});

it('refuses an unknown option and a token too short to keep private', function () use ($seed) {
    $unknown = $seed($this->db, ['--tokn=' . $this->token]);
    $short = $seed($this->db, ['--token=abc']);

    expect($unknown['exit'])->toBe(1)
        ->and($unknown['output'])->toContain('--tokn')
        ->and($short['exit'])->toBe(1)
        ->and($short['output'])->toContain('--token');
});

it('makes its folder, closed to others, and a new file leaves no old WAL behind', function () use ($seed) {
    $db = $this->dir . '/data/demo.sqlite';
    $result = $seed($db);
    expect($result['exit'])->toBe(0)
        ->and(substr(sprintf('%o', fileperms(dirname($db))), -3))->toBe('700');

    // A WAL that is not the new file's own would be replayed into it
    file_put_contents($db . '-wal', 'not this database');
    $again = $seed($db);
    expect($again['exit'])->toBe(0)
        ->and(file_exists($db . '-wal') ? file_get_contents($db . '-wal') : '')->not->toBe('not this database')
        ->and((new PDO('sqlite:' . $db))->query('PRAGMA integrity_check')->fetchColumn())->toBe('ok');
});
