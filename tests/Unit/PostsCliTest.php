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
    'empty sources' => [aPost(['sources' => []]), 'Not a post: sources is missing.'],
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

function postRowsIfAny(string $dbPath): array
{
    $db = new PDO('sqlite:' . $dbPath);
    $exists = $db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'posts'")->fetchColumn();
    return $exists ? postRows($dbPath) : [];
}
