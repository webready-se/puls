<?php

/**
 * Tests for CLI interactive fallbacks when called without arguments.
 *
 * Uses the shared runCli() helper from tests/Support/helpers.php.
 */

require_once __DIR__ . '/../Support/helpers.php';

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/puls_cli_test_' . uniqid();
    mkdir($this->tmpDir);
    $this->dbPath = $this->tmpDir . '/test.sqlite';
    $this->usersFile = $this->tmpDir . '/users.json';
    file_put_contents($this->usersFile, '{}');
});

afterEach(function () {
    @unlink($this->dbPath);
    @unlink($this->usersFile);
    @rmdir($this->tmpDir);
});

// =====================================================================
// user:remove
// =====================================================================

it('user:remove shows picker when no arg', function () {
    file_put_contents($this->usersFile, json_encode([
        'admin' => ['password' => '$2y$12$fake', 'sites' => []],
        'client' => ['password' => '$2y$12$fake', 'sites' => ['my-site']],
    ]));
    createCliTestDb($this->dbPath);

    // Select user 1 (admin) then confirm with "y"
    $result = runCli($this->tmpDir, 'user:remove', [], "1\ny");

    expect($result['output'])->toContain('admin')
        ->and($result['output'])->toContain('client');
});

it('user:list and the user pickers say "all sites" for a user with every site', function () {
    file_put_contents($this->usersFile, json_encode([
        'admin' => ['password' => '$2y$12$fake', 'sites' => []],
        'client' => ['password' => '$2y$12$fake', 'sites' => ['my-site']],
    ]));
    createCliTestDb($this->dbPath);

    $list = runCli($this->tmpDir, 'user:list');
    $remove = runCli($this->tmpDir, 'user:remove', [], "0");
    $edit = runCli($this->tmpDir, 'user:edit', [], "0");

    expect($list['output'])->toMatch('/admin\s+all sites/')
        ->and($remove['output'])->toContain('1) admin (all sites)')
        ->and($edit['output'])->toContain('1) admin (all sites)')
        ->and($list['output'] . $remove['output'] . $edit['output'])->not->toContain('alla');
});

it('user:remove shows no users message when empty', function () {
    createCliTestDb($this->dbPath);

    $result = runCli($this->tmpDir, 'user:remove');

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('No users configured');
});

it('user:remove requires confirmation', function () {
    file_put_contents($this->usersFile, json_encode([
        'admin' => ['password' => '$2y$12$fake', 'sites' => []],
    ]));
    createCliTestDb($this->dbPath);

    // Decline confirmation
    $result = runCli($this->tmpDir, 'user:remove', ['admin'], "n");

    expect($result['output'])->toContain('Aborted');

    // User should still exist
    $users = json_decode(file_get_contents($this->usersFile), true);
    expect($users)->toHaveKey('admin');
});

it('user:remove deletes user after confirmation', function () {
    file_put_contents($this->usersFile, json_encode([
        'admin' => ['password' => '$2y$12$fake', 'sites' => []],
    ]));
    createCliTestDb($this->dbPath);

    $result = runCli($this->tmpDir, 'user:remove', ['admin'], "y");

    expect($result['output'])->toContain("removed");

    $users = json_decode(file_get_contents($this->usersFile), true);
    expect($users)->not->toHaveKey('admin');
});

// =====================================================================
// user:add
// =====================================================================

/** The generated password from a CLI run, or null. Four groups of five, no look-alikes. */
function generatedPassword(string $output): ?string
{
    return preg_match('/Generated password: ([a-hj-km-np-z2-9]{5}(?:-[a-hj-km-np-z2-9]{5}){3})\n/', $output, $m) ? $m[1] : null;
}

it('user:add asks for a missing name and generates a password on Enter', function () {
    createCliTestDb($this->dbPath);

    // Name, Enter for a generated password, 0 for all sites
    $result = runCli($this->tmpDir, 'user:add', [], "anna\n\n0\n");
    $users = json_decode(file_get_contents($this->usersFile), true);
    $password = generatedPassword($result['output']);

    expect($users)->toHaveKey('anna')
        ->and($result['output'])->toContain('(Enter for a generated one)')
        ->and($result['output'])->toContain('Copy it now')
        ->and($result['output'])->toContain('Added anna (access: all sites)')
        ->and($password)->not->toBeNull()
        ->and(password_verify($password, $users['anna']['password']))->toBeTrue();
});

it('user:add takes a typed password from a pipe', function () {
    createCliTestDb($this->dbPath);

    $result = runCli($this->tmpDir, 'user:add', ['bob'], "long-enough-1\n0\n");
    $users = json_decode(file_get_contents($this->usersFile), true);

    expect($result['output'])->not->toContain('Generated password')
        ->and(password_verify('long-enough-1', $users['bob']['password']))->toBeTrue();
});

it('user:add refuses a typed password under 10 characters', function () {
    createCliTestDb($this->dbPath);

    $result = runCli($this->tmpDir, 'user:add', ['carl'], "short-1\n");

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('at least 10 characters')
        ->and(json_decode(file_get_contents($this->usersFile), true))->not->toHaveKey('carl');
});

it('user:edit can set a generated password', function () {
    file_put_contents($this->usersFile, json_encode([
        'admin' => ['password' => '$2y$12$fake', 'sites' => []],
    ]));
    createCliTestDb($this->dbPath);

    // "0" for all sites, "y" to change the password, Enter for a generated one
    $result = runCli($this->tmpDir, 'user:edit', [], "0\ny\n\n");
    $users = json_decode(file_get_contents($this->usersFile), true);
    $password = generatedPassword($result['output']);

    expect($password)->not->toBeNull()
        ->and(password_verify($password, $users['admin']['password']))->toBeTrue()
        ->and($result['output'])->toContain('(access: all sites)');
});

// =====================================================================
// user:edit
// =====================================================================

it('user:edit shows picker when no arg', function () {
    file_put_contents($this->usersFile, json_encode([
        'admin' => ['password' => '$2y$12$fake', 'sites' => []],
        'client' => ['password' => '$2y$12$fake', 'sites' => ['my-site']],
    ]));
    createCliTestDb($this->dbPath);

    // Select user 1, then "0" for all sites, then "n" for password change
    $result = runCli($this->tmpDir, 'user:edit', [], "1\n0\nn");

    expect($result['output'])->toContain('Select user to edit')
        ->and($result['output'])->toContain('admin')
        ->and($result['output'])->toContain('client');
});

it('user:edit auto-selects when only one user', function () {
    file_put_contents($this->usersFile, json_encode([
        'admin' => ['password' => '$2y$12$fake', 'sites' => []],
    ]));
    createCliTestDb($this->dbPath);

    // No user selection needed — "0" for all sites, "n" for password
    $result = runCli($this->tmpDir, 'user:edit', [], "0\nn");

    expect($result['output'])->toContain("Editing user 'admin'")
        ->and($result['output'])->toContain('updated');
});

it('user:edit shows no users message when empty', function () {
    createCliTestDb($this->dbPath);

    $result = runCli($this->tmpDir, 'user:edit');

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('No users configured');
});

// =====================================================================
// sites:rename
// =====================================================================

it('sites:rename shows picker when no arg and sites exist', function () {
    $db = createCliTestDb($this->dbPath);
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('alpha', '/')");
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('beta', '/')");

    // Select site 1, then enter new name
    $result = runCli($this->tmpDir, 'sites:rename', [], "1\nnew-alpha");

    expect($result['output'])->toContain('Select site to rename')
        ->and($result['output'])->toContain('alpha')
        ->and($result['output'])->toContain('beta')
        ->and($result['output'])->toContain("Renamed 'alpha'");
});

it('sites:rename auto-selects when only one site', function () {
    $db = createCliTestDb($this->dbPath);
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('only-site', '/')");

    // Just enter new name
    $result = runCli($this->tmpDir, 'sites:rename', [], "renamed-site");

    expect($result['output'])->toContain("Renamed 'only-site'");
});

// =====================================================================
// sites:remove
// =====================================================================

it('sites:remove shows picker when no arg', function () {
    $db = createCliTestDb($this->dbPath);
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('alpha', '/')");
    $db->exec("INSERT INTO pageviews (site, path) VALUES ('beta', '/')");

    // Select site 1, but don't confirm (type wrong name)
    $result = runCli($this->tmpDir, 'sites:remove', [], "1\nwrong");

    expect($result['output'])->toContain('Select site to remove')
        ->and($result['output'])->toContain('alpha')
        ->and($result['output'])->toContain('Aborted');
});

it('sites:remove shows no sites message when db empty', function () {
    createCliTestDb($this->dbPath);

    $result = runCli($this->tmpDir, 'sites:remove');

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('No sites tracked');
});

// =====================================================================
// share:revoke
// =====================================================================

it('share:revoke shows picker when no arg', function () {
    $db = createCliTestDb($this->dbPath);
    $token = bin2hex(random_bytes(32));
    $db->exec("INSERT INTO share_tokens (token, site, label, created_at) VALUES ('{$token}', 'my-site', 'Test label', datetime('now'))");

    // Select token 1, but decline
    $result = runCli($this->tmpDir, 'share:revoke', [], "1\nn");

    expect($result['output'])->toContain('Select token to revoke')
        ->and($result['output'])->toContain('my-site')
        ->and($result['output'])->toContain('Test label')
        ->and($result['output'])->toContain('Aborted');
});

it('share:revoke shows no tokens message when empty', function () {
    createCliTestDb($this->dbPath);

    $result = runCli($this->tmpDir, 'share:revoke');

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('No share tokens');
});

it('share:revoke deletes token after confirmation', function () {
    $db = createCliTestDb($this->dbPath);
    $token = bin2hex(random_bytes(32));
    $db->exec("INSERT INTO share_tokens (token, site, label, created_at) VALUES ('{$token}', 'my-site', 'Revoke me', datetime('now'))");

    // Select token 1, confirm
    $result = runCli($this->tmpDir, 'share:revoke', [], "1\ny");

    expect($result['output'])->toContain('Token revoked');

    $count = $db->query("SELECT COUNT(*) FROM share_tokens")->fetchColumn();
    expect((int) $count)->toBe(0);
});
