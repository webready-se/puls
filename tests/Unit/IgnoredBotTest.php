<?php

test('matches ignored bot patterns case-insensitively', function () {
    expect(is_ignored_bot('Go-http-client/2.0', ['Go-http-client']))->toBeTrue();
    expect(is_ignored_bot('go-HTTP-CLIENT/2.0', ['Go-http-client']))->toBeTrue();
    expect(is_ignored_bot('UptimeRobot/2.0', ['UptimeRobot']))->toBeTrue();
});

test('does not match unrelated user agents', function () {
    expect(is_ignored_bot('Mozilla/5.0 Chrome', ['Go-http-client']))->toBeFalse();
    expect(is_ignored_bot('Googlebot/2.1', ['Go-http-client']))->toBeFalse();
});

test('handles empty patterns', function () {
    expect(is_ignored_bot('Go-http-client/2.0', []))->toBeFalse();
    expect(is_ignored_bot('Go-http-client/2.0', ['']))->toBeFalse();
});

test('matches multiple patterns', function () {
    $patterns = ['Go-http-client', 'UptimeRobot', 'StatusCake'];
    expect(is_ignored_bot('Go-http-client/2.0', $patterns))->toBeTrue();
    expect(is_ignored_bot('UptimeRobot/2.0', $patterns))->toBeTrue();
    expect(is_ignored_bot('Googlebot/2.1', $patterns))->toBeFalse();
});
