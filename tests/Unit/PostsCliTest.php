<?php

/**
 * Tests for posts:add, posts:list and posts:remove: a tool on the same server hands each
 * social media post to Puls, which shows it on its day next to the traffic.
 */

require_once __DIR__ . '/../Support/helpers.php';

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/puls_posts_test_' . uniqid();
    mkdir($this->tmpDir);
    $this->dbPath = $this->tmpDir . '/test.sqlite';
    file_put_contents($this->tmpDir . '/users.json', '{}');
    $db = createCliTestDb($this->dbPath);
    $db->prepare('INSERT INTO pageviews (site, path, visitor_hash) VALUES (?, ?, ?)')->execute(['shop.example', '/', 'hash-1']);
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->tmpDir));
});

function addPost(string $tmpDir, array|string $post): array
{
    $json = is_string($post) ? $post : json_encode($post, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return runCli($tmpDir, 'posts:add', ['--json'], $json, ['TIMEZONE' => 'Europe/Stockholm']);
}

function postRows(string $dbPath): array
{
    $db = new PDO('sqlite:' . $dbPath);
    return $db->query('SELECT site, post_id, at, sources, text, url FROM posts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}

function aPost(array $fields = []): array
{
    return array_merge([
        'id' => 'instagram:1',
        'site' => 'shop.example',
        'at' => '2026-11-03T08:15:00+00:00',
        'sources' => ['Instagram', 'facebook'],
        'ids' => ['instagram' => '1', 'facebook' => '2_3'],
        'text' => 'The autumn collection is in',
        'url' => 'https://www.instagram.com/p/EXAMPLE/',
    ], $fields);
}

it('stores a post on its day in the install\'s time zone, creating the table on an unmigrated database', function () {
    $result = addPost($this->tmpDir, aPost());
    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toBe('Added instagram:1 to shop.example on 2026-11-03.')
        ->and(postRows($this->dbPath))->toBe([[
            'site' => 'shop.example',
            'post_id' => 'instagram:1',
            'at' => '2026-11-03 09:15:00',
            'sources' => 'instagram,facebook',
            'text' => 'The autumn collection is in',
            'url' => 'https://www.instagram.com/p/EXAMPLE/',
        ]]);
});

it('puts a post late in the evening UTC on the next day where the install is', function () {
    $result = addPost($this->tmpDir, aPost(['at' => '2026-11-03T23:30:00+00:00']));
    expect($result['output'])->toBe('Added instagram:1 to shop.example on 2026-11-04.')
        ->and(postRows($this->dbPath)[0]['at'])->toBe('2026-11-04 00:30:00');
});

it('takes the same post again as done, and keeps what it has', function () {
    addPost($this->tmpDir, aPost());
    $result = addPost($this->tmpDir, aPost(['text' => 'Edited on the platform']));
    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toBe('Already there: instagram:1 on shop.example.')
        ->and(postRows($this->dbPath))->toHaveCount(1)
        ->and(postRows($this->dbPath)[0]['text'])->toBe('The autumn collection is in');
});

it('stores a caption on one line, cut at 280 characters without breaking a character', function () {
    addPost($this->tmpDir, aPost(['text' => "Höst\n\n🍂 i   butiken " . str_repeat('å', 300)]));
    $text = postRows($this->dbPath)[0]['text'];
    expect($text)->toStartWith('Höst 🍂 i butiken å')
        ->and(mb_strlen($text))->toBe(280)
        ->and(mb_check_encoding($text, 'UTF-8'))->toBeTrue();
});

it('keeps a post without text or url', function () {
    $post = aPost();
    unset($post['text'], $post['url']);
    expect(addPost($this->tmpDir, $post)['exit'])->toBe(0)
        ->and(postRows($this->dbPath)[0])->toMatchArray(['text' => null, 'url' => null]);
});

it('drops a url that is not http or https and keeps the post', function () {
    addPost($this->tmpDir, aPost(['url' => 'javascript:alert(1)']));
    addPost($this->tmpDir, aPost(['id' => 'instagram:2', 'url' => 'HTTPS://example.com/p/1']));
    expect(array_column(postRows($this->dbPath), 'url'))->toBe([null, 'HTTPS://example.com/p/1']);
});

it('refuses what is not a post with one line that says why, and stores nothing', function (array|string $post, string $line) {
    $result = addPost($this->tmpDir, $post);
    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toBe($line)
        ->and(file_exists($this->dbPath) ? postRowsIfAny($this->dbPath) : [])->toBe([]);
})->with([
    'nothing on stdin' => ['', 'No post on stdin: posts:add --json reads one post as JSON.'],
    'broken JSON' => ['{"id": ', 'Not a post: the JSON does not parse (Syntax error).'],
    'a list' => ['[1, 2]', 'Not a post: the JSON is not an object.'],
    'no id' => [array_diff_key(aPost(), ['id' => 1]), 'Not a post: id is missing.'],
    'no site' => [array_diff_key(aPost(), ['site' => 1]), 'Not a post: site is missing.'],
    'no at' => [array_diff_key(aPost(), ['at' => 1]), 'Not a post: at is missing.'],
    'no sources' => [array_diff_key(aPost(), ['sources' => 1]), 'Not a post: sources is missing.'],
    'empty sources' => [aPost(['sources' => []]), 'Not a post: sources is empty.'],
    'an id that is a number' => [aPost(['id' => 17]), 'Not a post: id must be a string.'],
    'sources that is a string' => [aPost(['sources' => 'instagram']), 'Not a post: sources must be a list, as ["instagram"].'],
    'sources that is an object' => [aPost(['sources' => ['instagram' => true]]), 'Not a post: sources must be a list, as ["instagram"].'],
    'a source with a space' => [aPost(['sources' => ['in stagram']]), 'Not a post: "in stagram" is not a source name (a-z, 0-9 and -).'],
    'at without an offset' => [aPost(['at' => '2026-11-03T08:15:00']), 'Not a post: at needs a date, a time and an offset, as 2026-11-03T08:15:00+01:00.'],
    'at that is no date' => [aPost(['at' => 'yesterday+01:00']), 'Not a post: at needs a date, a time and an offset, as 2026-11-03T08:15:00+01:00.'],
    'an unknown site' => [aPost(['site' => 'nope.example']), 'Unknown site: nope.example has no pageview yet. Posts are taken once Puls has seen its first visit (php puls sites:list).'],
]);

it('takes a post for a site that has a group but no pageview yet', function () {
    $db = new PDO('sqlite:' . $this->dbPath);
    $db->exec('CREATE TABLE site_groups (site TEXT PRIMARY KEY, group_name TEXT NOT NULL)');
    $db->exec("INSERT INTO site_groups (site, group_name) VALUES ('new.example', 'Shops')");
    $result = addPost($this->tmpDir, aPost(['site' => 'new.example']));
    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toBe('Added instagram:1 to new.example on 2026-11-03.')
        ->and(array_column(postRows($this->dbPath), 'site'))->toBe(['new.example']);
});

it('says how to use it without --json', function () {
    $result = runCli($this->tmpDir, 'posts:add');
    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('posts:add --json', 'id', 'site', 'at', 'sources', 'text', 'url');
});

it('moves the posts with a renamed site', function () {
    addPost($this->tmpDir, aPost());
    addPost($this->tmpDir, aPost(['id' => 'instagram:2']));
    $result = runCli($this->tmpDir, 'sites:rename', ['shop.example', 'butik.example']);
    expect($result['output'])->toContain('posts: 2 rows updated')
        ->and(array_unique(array_column(postRows($this->dbPath), 'site')))->toBe(['butik.example']);
});

it('removes the posts with a removed site', function () {
    addPost($this->tmpDir, aPost());
    $result = runCli($this->tmpDir, 'sites:remove', ['shop.example', '--yes']);
    expect($result['output'])->toContain('posts: 1 rows deleted')
        ->and(postRows($this->dbPath))->toBe([]);
});

/** An ISO 8601 time with an offset, some days back */
function daysAgo(int $days, string $time = '09:15'): string
{
    return date('Y-m-d', strtotime("-{$days} days")) . "T{$time}:00+01:00";
}

it('lists the latest posts, newest first, for the last 30 days', function () {
    addPost($this->tmpDir, aPost(['id' => 'instagram:old', 'at' => daysAgo(40)]));
    addPost($this->tmpDir, aPost(['id' => 'instagram:1', 'at' => daysAgo(5), 'text' => 'Short']));
    addPost($this->tmpDir, aPost(['id' => 'facebook:2', 'at' => daysAgo(1), 'sources' => ['facebook'], 'text' => str_repeat('Long caption ', 10)]));

    $result = runCli($this->tmpDir, 'posts:list', [], null, ['TIMEZONE' => 'Europe/Stockholm']);
    $lines = explode("\n", $result['output']);
    expect($result['exit'])->toBe(0)
        ->and($lines[0])->toMatch('/^DAY\s+TIME\s+SITE\s+SOURCES\s+TEXT\s+ID$/')
        ->and($lines[1])->toContain(date('Y-m-d', strtotime('-1 day')), 'facebook', '…', 'facebook:2')
        ->and($lines[2])->toContain('instagram,facebook', 'Short', 'instagram:1')
        ->and($result['output'])->not->toContain('instagram:old');
});

it('lists fewer days with --days, and one site when named', function () {
    addPost($this->tmpDir, aPost(['id' => 'instagram:1', 'at' => daysAgo(5)]));
    addPost($this->tmpDir, aPost(['id' => 'instagram:2', 'at' => daysAgo(1)]));
    $db = new PDO('sqlite:' . $this->dbPath);
    $db->exec("INSERT INTO posts (site, post_id, at, sources, created_at) VALUES ('other.example', 'instagram:9', '" . date('Y-m-d H:i:s') . "', 'instagram', '" . date('Y-m-d H:i:s') . "')");

    $recent = runCli($this->tmpDir, 'posts:list', ['--days=2']);
    $one = runCli($this->tmpDir, 'posts:list', ['other.example']);
    expect($recent['output'])->toContain('instagram:2', 'instagram:9')->not->toContain('instagram:1')
        ->and($one['output'])->toContain('instagram:9')->not->toContain('instagram:2');
});

it('finds the site after --days too, and in either spelling of an international domain', function () {
    addPost($this->tmpDir, aPost());
    $db = new PDO('sqlite:' . $this->dbPath);
    $now = date('Y-m-d H:i:s');
    $db->exec("INSERT INTO posts (site, post_id, at, sources, created_at) VALUES ('other.example', 'instagram:9', '{$now}', 'instagram', '{$now}')");
    $db->exec("INSERT INTO posts (site, post_id, at, sources, created_at) VALUES ('café.example', 'instagram:8', '{$now}', 'instagram', '{$now}')");

    $after = runCli($this->tmpDir, 'posts:list', ['--days=7', 'other.example']);
    $puny = runCli($this->tmpDir, 'posts:list', ['xn--caf-dma.example']);
    expect($after['output'])->toContain('instagram:9')->not->toContain('instagram:8')
        ->and($puny['output'])->toContain('instagram:8')->not->toContain('instagram:9');
});

it('prints no control characters a sender put in an id or a site', function () {
    addPost($this->tmpDir, aPost());
    $db = new PDO('sqlite:' . $this->dbPath);
    $now = date('Y-m-d H:i:s');
    $db->prepare('INSERT INTO posts (site, post_id, at, sources, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute(["shop.example\e]0;title\x07", "instagram:\e[31mred\u{9b}2J", $now, 'instagram', $now]);

    $output = runCli($this->tmpDir, 'posts:list')['output'];
    $removed = runCli($this->tmpDir, 'posts:remove', ["instagram:\e[31mred\u{9b}2J", '--yes'])['output'];
    expect($output)->toContain('instagram:[31mred2J')
        ->and(preg_match('/[\x00-\x09\x0b-\x1f\x7f]|\x{9b}/u', $output))->toBe(0)
        ->and($removed)->toBe('Removed instagram:[31mred2J from shop.example]0;title.');
});

it('says when there are no posts', function () {
    $result = runCli($this->tmpDir, 'posts:list');
    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toBe('No posts in the last 30 days.');
});

it('removes a post by its id without asking with --yes', function () {
    addPost($this->tmpDir, aPost());
    $result = runCli($this->tmpDir, 'posts:remove', ['instagram:1', '--yes']);
    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toBe('Removed instagram:1 from shop.example.')
        ->and(postRows($this->dbPath))->toBe([]);
});

it('removes a post that two sites have, and names both', function () {
    addPost($this->tmpDir, aPost());
    $db = new PDO('sqlite:' . $this->dbPath);
    $db->exec("INSERT INTO posts (site, post_id, at, sources, created_at) VALUES ('other.example', 'instagram:1', '2026-11-03 09:15:00', 'instagram', '2026-11-03 09:16:00')");
    $result = runCli($this->tmpDir, 'posts:remove', ['instagram:1', '--yes']);
    expect($result['output'])->toBe('Removed instagram:1 from other.example and shop.example.')
        ->and(postRows($this->dbPath))->toBe([]);
});

it('says when no post has the id', function () {
    $result = runCli($this->tmpDir, 'posts:remove', ['nope', '--yes']);
    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toBe('No post with id nope.');
});

it('asks before it removes, and keeps the post on no', function () {
    addPost($this->tmpDir, aPost());
    $result = runCli($this->tmpDir, 'posts:remove', ['instagram:1'], 'n');
    // readline shows its question on a terminal only, so a pipe sees the answer's result
    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('Aborted.')
        ->and(postRows($this->dbPath))->toHaveCount(1);
});

it('offers the ten latest to pick from without an id', function () {
    foreach (range(1, 12) as $n) addPost($this->tmpDir, aPost(['id' => "instagram:{$n}", 'at' => daysAgo(13 - $n)]));
    $result = runCli($this->tmpDir, 'posts:remove', [], "1\ny");
    expect($result['output'])->toContain('1. ', '10. ', 'instagram:12', 'instagram:3', 'Removed instagram:12 from shop.example.')
        ->not->toContain('11. ')
        ->and(array_column(postRows($this->dbPath), 'post_id'))->not->toContain('instagram:12')
        ->and(postRows($this->dbPath))->toHaveCount(11);
});

function postRowsIfAny(string $dbPath): array
{
    $db = new PDO('sqlite:' . $dbPath);
    $exists = $db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'posts'")->fetchColumn();
    return $exists ? postRows($dbPath) : [];
}
