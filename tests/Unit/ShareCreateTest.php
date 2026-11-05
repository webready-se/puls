<?php

/**
 * Tests for the share:create CLI command.
 */

require_once __DIR__ . '/../Support/helpers.php';

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/puls_share_test_' . uniqid();
    mkdir($this->tmpDir);
    $this->dbPath = $this->tmpDir . '/test.sqlite';
    file_put_contents($this->tmpDir . '/users.json', '{}');
    $db = createCliTestDb($this->dbPath);
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('shop', '/')");
});

afterEach(function () {
    putenv('TIMEZONE');
    exec('rm -rf ' . escapeshellarg($this->tmpDir));
});

it('share:create stamps the link on the configured clock, not SQLite UTC', function () {
    // Kiritimati is UTC+14, far from UTC's clock
    putenv('TIMEZONE=Pacific/Kiritimati');
    $result = runCli($this->tmpDir, 'share:create', ['shop', '--label=Clock', '--expires=2099-01-01'], "\n");
    expect($result['exit'])->toBe(0);

    $created = (new PDO('sqlite:' . $this->dbPath))->query('SELECT created_at FROM share_tokens')->fetchColumn();
    $at = new DateTimeImmutable($created, new DateTimeZone('Pacific/Kiritimati'));
    expect(abs($at->getTimestamp() - time()))->toBeLessThan(60);
});

function shareRow(string $dbPath): array
{
    $db = new PDO('sqlite:' . $dbPath);
    return $db->query('SELECT * FROM share_tokens ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
}

function groupSites(string $dbPath, array $sitesByGroup): void
{
    $db = new PDO('sqlite:' . $dbPath);
    $db->exec('CREATE TABLE IF NOT EXISTS site_groups (site TEXT PRIMARY KEY, group_name TEXT NOT NULL)');
    foreach ($sitesByGroup as $group => $sites) {
        foreach ($sites as $site) {
            $db->prepare("INSERT INTO pageviews (site, path) VALUES (?, '/')")->execute([$site]);
            $db->prepare('INSERT INTO site_groups (site, group_name) VALUES (?, ?)')->execute([$site, $group]);
        }
    }
}

it('share:create gives a prefixed token, for 90 days unless told otherwise', function () {
    runCli($this->tmpDir, 'share:create', ['shop', '--label=Reader'], "\n");
    $row = shareRow($this->dbPath);
    expect($row['token'])->toMatch('/^puls_[a-f0-9]{40}$/')
        ->and(substr($row['expires_at'], 0, 10))->toBe(date('Y-m-d', strtotime('+90 days')));
});

it('share:create takes a last day or never', function () {
    runCli($this->tmpDir, 'share:create', ['shop', '--label=Reader', '--expires=2099-01-31']);
    expect(shareRow($this->dbPath)['expires_at'])->toBe('2099-01-31 23:59:59');

    runCli($this->tmpDir, 'share:create', ['shop', '--label=Reader', '--expires=never']);
    expect(shareRow($this->dbPath)['expires_at'])->toBeNull();

    $bad = runCli($this->tmpDir, 'share:create', ['shop', '--label=Reader', '--expires=soon']);
    expect($bad['exit'])->not->toBe(0)->and($bad['output'])->toContain('YYYY-MM-DD');
});

it('share:create prints the lines for the reading project, in the kit\'s order', function () {
    $out = runCli($this->tmpDir, 'share:create', ['shop', '--label=Reader', '--expires=never'])['output'];
    $token = shareRow($this->dbPath)['token'];

    $at = fn (string $text) => strpos($out, $text);
    expect($out)->toContain("PULS_SHARE_TOKEN={$token}")->toContain('PULS_URL=')
        ->and($at('PULS_SHARE_TOKEN='))->toBeLessThan($at('git check-ignore .env'))
        ->and($at('git check-ignore .env'))->toBeLessThan($at('NEXT_PUBLIC_'))
        ->and($at('NEXT_PUBLIC_'))->toBeLessThan($at('set -a; . ./.env'))
        ->and($at('set -a; . ./.env'))->toBeLessThan($at('share:edit'))
        ->and($at('share:edit'))->toBeLessThan($at('share:revoke'));
});

it('share:create reads a group by its name, and a site first when both have it', function () {
    groupSites($this->dbPath, ['Shops' => ['a.example', 'b.example'], 'shop' => ['c.example']]);

    runCli($this->tmpDir, 'share:create', ['Shops', '--label=Reader', '--expires=never']);
    $row = shareRow($this->dbPath);
    expect($row['site'])->toBe('')->and(json_decode($row['group_names'], true))->toBe(['Shops']);

    runCli($this->tmpDir, 'share:create', ['shop', '--label=Reader', '--expires=never']);
    $row = shareRow($this->dbPath);
    expect($row['site'])->toBe('shop')->and($row['group_names'])->toBeNull();
});

it('share:create --groups takes several, and refuses one that does not exist', function () {
    groupSites($this->dbPath, ['One' => ['a.example'], 'Two' => ['b.example']]);

    $ok = runCli($this->tmpDir, 'share:create', ['--groups=One,Two', '--label=Reader', '--expires=never']);
    expect(json_decode(shareRow($this->dbPath)['group_names'], true))->toBe(['One', 'Two'])
        ->and($ok['output'])->toContain('2 sites');

    $bad = runCli($this->tmpDir, 'share:create', ['--groups=One,Nope', '--label=Reader', '--expires=never']);
    expect($bad['exit'])->not->toBe(0)->and($bad['output'])->toContain('Nope');
});

it('share:create refuses a name that is neither a site nor a group', function () {
    $bad = runCli($this->tmpDir, 'share:create', ['nothing.example', '--label=Reader', '--expires=never']);
    expect($bad['exit'])->not->toBe(0)->and($bad['output'])->toContain('nothing.example');
});

it('share:create offers groups and sites when asked with nothing', function () {
    groupSites($this->dbPath, ['One' => ['a.example'], 'Two' => ['b.example']]);

    $out = runCli($this->tmpDir, 'share:create', ['--label=Reader', '--expires=never'], '1,2')['output'];
    expect($out)->toContain('One (1 site)')->toContain('Two (1 site)')->toContain('shop')
        ->and(json_decode(shareRow($this->dbPath)['group_names'], true))->toBe(['One', 'Two']);
});

it('share:create asked with nothing refuses a mix of a site and a group', function () {
    groupSites($this->dbPath, ['One' => ['a.example']]);

    // 1 is the group, and the last number a site
    $out = runCli($this->tmpDir, 'share:create', ['--label=Reader', '--expires=never'], '1,2');
    expect($out['exit'])->not->toBe(0)->and($out['output'])->toContain('one site or some groups');
});

it('share:edit changes the last day, the label and the groups, and the token stays', function () {
    groupSites($this->dbPath, ['One' => ['a.example'], 'Two' => ['b.example']]);
    runCli($this->tmpDir, 'share:create', ['One', '--label=Old', '--expires=2020-01-01']);
    $token = shareRow($this->dbPath)['token'];

    // Also a token that has expired
    $out = runCli($this->tmpDir, 'share:edit', [substr($token, 0, 12), '--expires=2099-12-31', '--label=New', '--groups=One,Two'])['output'];
    $row = shareRow($this->dbPath);
    expect($row['token'])->toBe($token)
        ->and($row['expires_at'])->toBe('2099-12-31 23:59:59')
        ->and($row['label'])->toBe('New')
        ->and(json_decode($row['group_names'], true))->toBe(['One', 'Two'])
        ->and($out)->toContain('2 sites');

    runCli($this->tmpDir, 'share:edit', [substr($token, 0, 12), '--expires=never', '--label=none']);
    $row = shareRow($this->dbPath);
    expect($row['expires_at'])->toBeNull()->and($row['label'])->toBeNull();
});

it('share:edit keeps a token for one site as one', function () {
    groupSites($this->dbPath, ['One' => ['a.example']]);
    runCli($this->tmpDir, 'share:create', ['shop', '--label=Reader', '--expires=never']);
    $token = shareRow($this->dbPath)['token'];

    $out = runCli($this->tmpDir, 'share:edit', [substr($token, 0, 12), '--groups=One']);
    expect($out['exit'])->not->toBe(0)
        ->and($out['output'])->toContain('another token')
        ->and(shareRow($this->dbPath)['group_names'])->toBeNull();
});

it('share:list says what each token reads, and when one reads nothing', function () {
    groupSites($this->dbPath, ['One' => ['a.example', 'b.example']]);
    runCli($this->tmpDir, 'share:create', ['One', '--label=Reader', '--expires=never']);
    (new PDO('sqlite:' . $this->dbPath))->prepare("INSERT INTO share_tokens (token, site, group_names, label, created_at) VALUES ('puls_gone', '', ?, 'Left', '2026-01-01')")->execute([json_encode(['Gone'])]);

    $out = runCli($this->tmpDir, 'share:list', [], "\n")['output'];
    expect($out)->toContain('One (2 sites)')->toContain('Gone (no sites, reads nothing)');
});

it('sites:group says which tokens read the sites now', function () {
    groupSites($this->dbPath, ['One' => ['a.example']]);
    runCli($this->tmpDir, 'share:create', ['One', '--label=Report bot', '--expires=never']);

    $out = runCli($this->tmpDir, 'sites:group', ['One', 'shop'])['output'];
    expect($out)->toContain('Report bot');
});

it('share:list finishes without a terminal, with its input left open', function () {
    // Over ssh without a terminal, or in a script: input stays open and nobody answers
    runCli($this->tmpDir, 'share:create', ['shop', '--label=Reader', '--expires=never']);
    $wrapper = $this->tmpDir . '/list.php';
    file_put_contents($wrapper, "<?php\n"
        . "\$_ENV['DB_PATH'] = " . var_export($this->dbPath, true) . ";\n"
        . "\$_ENV['USERS_FILE'] = " . var_export($this->tmpDir . '/users.json', true) . ";\n"
        . "\$argv = ['puls', 'share:list'];\n\$argc = 2;\n"
        . "require " . var_export(realpath(__DIR__ . '/../../puls'), true) . ";\n");

    $process = proc_open(['php', $wrapper], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    $deadline = microtime(true) + 5;
    while (proc_get_status($process)['running'] && microtime(true) < $deadline) usleep(50_000);
    $finished = !proc_get_status($process)['running'];
    if (!$finished) proc_terminate($process);
    $out = stream_get_contents($pipes[1]);
    foreach ($pipes as $pipe) fclose($pipe);
    proc_close($process);

    expect($finished)->toBeTrue()
        ->and($out)->toContain('1 token(s)')->not->toContain('Get share URL');
});

it('share:create says how a token for groups reads one site, and only then', function () {
    groupSites($this->dbPath, ['One' => ['a.example', 'b.example']]);

    expect(runCli($this->tmpDir, 'share:create', ['One', '--label=Reader', '--expires=never'])['output'])->toContain('&site=');
    expect(runCli($this->tmpDir, 'share:create', ['shop', '--label=Reader', '--expires=never'])['output'])->not->toContain('&site=');
});

it('bots:unknown lists the user agents behind unknown bots, most visits first', function () {
    $db = new PDO('sqlite:' . $this->dbPath);
    $db->exec('DROP TABLE IF EXISTS bot_visits');
    $db->exec('CREATE TABLE bot_visits (id INTEGER PRIMARY KEY, site TEXT, path TEXT, bot_name TEXT, bot_category TEXT, user_agent TEXT, created_at TEXT)');
    $insert = $db->prepare("INSERT INTO bot_visits (site, path, bot_name, bot_category, user_agent, created_at) VALUES (?, '/', ?, 'Other', ?, ?)");
    $now = date('Y-m-d H:i:s');
    foreach ([['shop', 'Unknown bot', 'Oddbot/1.0'], ['shop', 'Unknown bot', 'Oddbot/1.0'], ['shop', 'Unknown crawler', 'odd-crawler/2'], ['shop', 'GPTBot', 'GPTBot/1.0'], ['other', 'Unknown bot', 'Elsewhere/1.0']] as [$site, $bot, $ua]) {
        $insert->execute([$site, $bot, $ua, $now]);
    }

    $all = runCli($this->tmpDir, 'bots:unknown')['output'];
    expect($all)->toContain('Oddbot/1.0')->toContain('odd-crawler/2')->toContain('Elsewhere/1.0')->not->toContain('GPTBot')
        ->and(strpos($all, 'Oddbot/1.0'))->toBeLessThan(strpos($all, 'odd-crawler/2'));

    $one = runCli($this->tmpDir, 'bots:unknown', ['shop'])['output'];
    expect($one)->toContain('Oddbot/1.0')->not->toContain('Elsewhere/1.0');
});
