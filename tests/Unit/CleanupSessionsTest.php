<?php

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/puls_sessions_' . uniqid();
    mkdir($this->dir);
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->dir));
});

test('cleanup_sessions deletes session files unused for a session lifetime, once a day', function () {
    touch($this->dir . '/sess_old', time() - 7200);
    touch($this->dir . '/sess_recent', time() - 60);

    cleanup_sessions($this->dir, 3600);
    expect(file_exists($this->dir . '/sess_old'))->toBeFalse()
        ->and(file_exists($this->dir . '/sess_recent'))->toBeTrue();

    // Already done today: a file that goes stale later waits for tomorrow
    touch($this->dir . '/sess_later', time() - 7200);
    cleanup_sessions($this->dir, 3600);
    expect(file_exists($this->dir . '/sess_later'))->toBeTrue();
});
