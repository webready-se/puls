<?php

// The name hooks in a git worktree. The list is gitignored, so a worktree has none of its own.

beforeEach(function () {
    $this->tmp = realpath(sys_get_temp_dir()) . '/puls-names-' . getmypid() . '-' . uniqid();
    mkdir("{$this->tmp}/main/.private", 0777, true);
    $this->hooks = realpath(__DIR__ . '/../../scripts/hooks');

    // A clean environment: from the pre-push hook, git's own variables would point every command
    // here at the real repository
    $this->run = function (string $dir, string $command, string $stdin = '', array $env = []): array {
        $process = proc_open(['sh', '-c', $command], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, "{$this->tmp}/{$dir}", $env + [
            'PATH' => getenv('PATH'), 'HOME' => $this->tmp, 'GIT_CONFIG_NOSYSTEM' => '1',
        ]);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        return ['out' => $out, 'err' => $err, 'code' => proc_close($process)];
    };

    $git = 'git -c user.name=Test -c user.email=test@example.com';
    $made = ($this->run)('main', "git init -q . && {$git} commit -q --allow-empty -m start && git worktree add -q -b side ../side");
    expect($made['code'])->toBe(0, $made['err']);
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->tmp));
});

test('a worktree checks names against the main checkout\'s list', function () {
    file_put_contents("{$this->tmp}/main/.private/real-names.txt", "# sites\nsecret.example\n");
    $line = "a visit to secret.example\n";

    expect(($this->run)('main', "{$this->hooks}/real-names", $line)['out'])->toContain('secret.example')
        ->and(($this->run)('side', "{$this->hooks}/real-names", $line)['out'])->toContain('secret.example')
        // As the Claude Code hook calls it, with the worktree as the project
        ->and(($this->run)('main', "{$this->hooks}/real-names", $line, ['PULS_ROOT' => "{$this->tmp}/side"])['out'])->toContain('secret.example')
        ->and(($this->run)('side', "{$this->hooks}/real-names", "a visit to shop.example\n")['out'])->toBe('');
});

test('a worktree with a list of its own is checked against both', function () {
    file_put_contents("{$this->tmp}/main/.private/real-names.txt", "secret.example\n");
    mkdir("{$this->tmp}/side/.private");
    file_put_contents("{$this->tmp}/side/.private/real-names.txt", "other.example\n");

    $out = ($this->run)('side', "{$this->hooks}/real-names", "secret.example\nother.example\nshop.example\n")['out'];
    expect($out)->toContain('secret.example')->toContain('other.example')->not->toContain('shop.example');
});

test('without a list nothing is checked', function () {
    expect(($this->run)('main', "{$this->hooks}/real-names", "secret.example\n"))->toMatchArray(['out' => '', 'code' => 0])
        ->and(($this->run)('side', "{$this->hooks}/real-names", "secret.example\n"))->toMatchArray(['out' => '', 'code' => 0]);
});

test('a commit in a worktree is stopped', function () {
    file_put_contents("{$this->tmp}/main/.private/real-names.txt", "secret.example\n");
    $commit = fn (string $text, string $message) => ($this->run)('side', 'printf %s ' . escapeshellarg("{$text}\n") . ' > note.txt && git add note.txt && '
        . "git -c core.hooksPath={$this->hooks} -c user.name=Test -c user.email=test@example.com commit -q -m " . escapeshellarg($message));

    expect($commit('seen on secret.example', 'note'))->code->toBe(1)->err->toContain('Commit stopped')
        ->and($commit('seen on shop.example', 'about secret.example'))->code->toBe(1)->err->toContain('the message contains names')
        ->and($commit('seen on shop.example', 'note'))->code->toBe(0);
});
