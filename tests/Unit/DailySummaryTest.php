<?php

function summaryTestDb(): PDO
{
    $dir = sys_get_temp_dir() . '/puls-summary-' . uniqid();
    register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg($dir)));
    return get_db($dir . '/puls.sqlite');
}

function summaryPageview(PDO $db, string $site, string $at, string $visitor, string $path, array $more = []): void
{
    $row = ['site' => $site, 'path' => $path, 'visitor_hash' => $visitor, 'created_at' => $at] + $more;
    $db->prepare('INSERT INTO pageviews (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')
        ->execute(array_values($row));
}

function summaryRows(PDO $db, string $site, string $date, string $kind): array
{
    $stmt = $db->prepare('SELECT key, views, visitors, entries, bounces, exits FROM daily WHERE site = ? AND date = ? AND kind = ? ORDER BY key');
    $stmt->execute([$site, $date, $kind]);
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'key');
}

test('a day sums to the same figures as its pageviews', function () {
    $db = summaryTestDb();
    $day = '2026-03-10';
    // a: three pages, the first two in the same second (the one stored first is the entry)
    summaryPageview($db, 's', "{$day} 09:00:00", 'a', '/', ['referrer' => 'Google', 'country' => 'SE', 'language' => 'sv', 'browser' => 'Chrome', 'device' => 'Desktop']);
    summaryPageview($db, 's', "{$day} 09:00:00", 'a', '/b', ['referrer' => 'Google', 'country' => 'SE', 'language' => 'sv', 'browser' => 'Chrome', 'device' => 'Desktop']);
    summaryPageview($db, 's', "{$day} 09:05:30", 'a', '/c', ['referrer' => 'Google', 'country' => 'SE', 'language' => 'sv', 'browser' => 'Chrome', 'device' => 'Desktop']);
    // b: one page from an ad, a bounce
    summaryPageview($db, 's', "{$day} 10:00:00", 'b', '/', ['utm_source' => 'ig', 'utm_medium' => 'paid', 'country' => 'NO', 'language' => 'en', 'browser' => 'Safari', 'device' => 'Mobile']);
    // c: two pages, nothing known about where from
    summaryPageview($db, 's', "{$day} 11:00:00", 'c', '/b', ['browser' => 'Chrome', 'device' => 'Desktop']);
    summaryPageview($db, 's', "{$day} 11:00:10", 'c', '/', ['browser' => 'Chrome', 'device' => 'Desktop']);
    // Neither another day nor another site
    summaryPageview($db, 's', '2026-03-11 00:00:00', 'd', '/');
    summaryPageview($db, 'other', "{$day} 09:00:00", 'e', '/');

    sum_day($db, 's', $day);

    $only = fn (array $rows, string $field) => array_map(fn ($r) => (int) $r[$field], $rows);
    expect(summaryRows($db, 's', $day, 'day')[''])->toMatchArray(['views' => 6, 'visitors' => 3, 'bounces' => 1])
        ->and($only(summaryRows($db, 's', $day, 'hour'), 'views'))->toBe(['10' => 1, '11' => 2, '9' => 3]);

    $paths = summaryRows($db, 's', $day, 'path');
    expect($paths['/'])->toMatchArray(['views' => 3, 'visitors' => 3, 'entries' => 2, 'bounces' => 1, 'exits' => 2])
        ->and($paths['/b'])->toMatchArray(['views' => 2, 'visitors' => 2, 'entries' => 1, 'bounces' => 0, 'exits' => 0])
        ->and($paths['/c'])->toMatchArray(['views' => 1, 'visitors' => 1, 'entries' => 0, 'bounces' => 0, 'exits' => 1]);

    expect($only(summaryRows($db, 's', $day, 'referrer'), 'visitors'))->toBe(['Google' => 1])
        ->and($only(summaryRows($db, 's', $day, 'utm'), 'visitors'))->toBe(['["ig","paid",null,null,null]' => 1])
        ->and(summaryRows($db, 's', $day, 'channel'))->toMatchArray([
            'Organic search' => ['key' => 'Organic search', 'views' => 3, 'visitors' => 1, 'entries' => 0, 'bounces' => 0, 'exits' => 0],
            'Paid' => ['key' => 'Paid', 'views' => 1, 'visitors' => 1, 'entries' => 0, 'bounces' => 0, 'exits' => 0],
            'Direct' => ['key' => 'Direct', 'views' => 2, 'visitors' => 1, 'entries' => 0, 'bounces' => 0, 'exits' => 0],
        ])
        ->and($only(summaryRows($db, 's', $day, 'country'), 'visitors'))->toBe(['NO' => 1, 'SE' => 1])
        ->and($only(summaryRows($db, 's', $day, 'browser'), 'views'))->toBe(['Chrome' => 5, 'Safari' => 1])
        ->and($only(summaryRows($db, 's', $day, 'device'), 'views'))->toBe(['Desktop' => 5, 'Mobile' => 1])
        // No language is kept as '', as the dashboard leaves it out
        ->and($only(summaryRows($db, 's', $day, 'language'), 'views'))->toBe(['' => 2, 'en' => 1, 'sv' => 3])
        // Lengths of visits of more than one page: a 5 min 30 s, c 10 s
        ->and($only(summaryRows($db, 's', $day, 'duration'), 'visitors'))->toBe(['10' => 1, '330' => 1]);

    // Summed again, the day is replaced, not added to
    sum_day($db, 's', $day);
    expect(summaryRows($db, 's', $day, 'day')['']['views'])->toBe(6);
});

test('missing days are summed newest first, a few at a time, and never today or before the first pageview', function () {
    $db = summaryTestDb();
    $ago = fn (int $n) => date('Y-m-d', strtotime("-{$n} days"));
    $last = last_closed_day();
    summaryPageview($db, 's', $ago(5) . ' 12:00:00', 'a', '/');
    summaryPageview($db, 's', $ago(3) . ' 12:00:00', 'b', '/');
    summaryPageview($db, 's', date('Y-m-d') . ' 00:00:01', 'c', '/');
    $closed = (int) round((strtotime($last) - strtotime($ago(5))) / 86400) + 1;

    expect(sum_missing_days($db, ['s'], $ago(30), '', 2))->toBe($closed - 2);
    $summed = $db->query("SELECT date FROM daily WHERE kind = 'day' ORDER BY date DESC")->fetchAll(PDO::FETCH_COLUMN);
    expect($summed)->toBe([$last, date('Y-m-d', strtotime($last . ' -1 day'))]);

    expect(sum_missing_days($db, ['s'], $ago(30), '', 100))->toBe(0);
    $days = $db->query("SELECT date, views FROM daily WHERE kind = 'day' ORDER BY date")->fetchAll(PDO::FETCH_KEY_PAIR);
    expect(array_key_first($days))->toBe($ago(5))
        ->and(array_key_last($days))->toBe($last)
        ->and($days)->toHaveCount($closed)
        // A day without pageviews is summed too, as zero, so it is not summed again
        ->and((int) $days[$ago(4)])->toBe(0)
        ->and((int) $days[$ago(3)])->toBe(1);

    // A period that ends before today stops at its own end
    $db->exec('DELETE FROM daily');
    expect(sum_missing_days($db, ['s'], $ago(5), $ago(3), 100))->toBe(0)
        ->and($db->query("SELECT date FROM daily WHERE kind = 'day' ORDER BY date")->fetchAll(PDO::FETCH_COLUMN))->toBe([$ago(5), $ago(4)]);
});

test('the day that is over is summed once a day for the sites that had a pageview in it', function () {
    $db = summaryTestDb();
    $day = last_closed_day();
    summaryPageview($db, 'a', "{$day} 12:00:00", 'x', '/');
    summaryPageview($db, 'b', "{$day} 13:00:00", 'y', '/');
    summaryPageview($db, 'c', date('Y-m-d', strtotime($day . ' -3 days')) . ' 12:00:00', 'z', '/');

    sum_closed_day($db);
    expect($db->query("SELECT site FROM daily WHERE kind = 'day' AND date = '{$day}' ORDER BY site")->fetchAll(PDO::FETCH_COLUMN))->toBe(['a', 'b'])
        ->and((int) $db->query("SELECT COUNT(*) FROM daily WHERE site = 'c'")->fetchColumn())->toBe(0);

    // Once a day: a second call the same day leaves it alone
    $db->exec('DELETE FROM daily');
    sum_closed_day($db);
    expect((int) $db->query('SELECT COUNT(*) FROM daily')->fetchColumn())->toBe(0);
});

test('every figure of the dashboard reads the same from the summary as from the pageviews', function (int $seed) {
    $db = summaryTestDb();
    mt_srand($seed);
    $paths = ['/', '/pricing', '/about', '/blog/a', '/blog/b', '/contact', '/docs', '/docs/start'];
    $referrers = [null, null, 'Google', 'Facebook', 'Instagram', 'news.example', 'Bing'];
    $utms = [null, null, null, ['ig', 'paid', 'autumn', null, null], ['newsletter', 'email', 'nov', null, 'top'], ['google', 'cpc', 'brand', 'puls', null]];
    $now = time();
    foreach (['a.example' => 14, 'b.example' => 5] as $site => $perDay) {
        for ($ago = 40; $ago >= 0; $ago--) {
            $date = date('Y-m-d', strtotime("-{$ago} days"));
            for ($v = 0; $v < $perDay + mt_rand(0, 6); $v++) {
                // A visitor's hash is its own per site and day, as the daily salt makes it
                $visitor = "{$site}-{$date}-{$v}";
                $start = strtotime($date) + mt_rand(0, 86000);
                if ($ago === 0 && $start > $now) $start = $now - mt_rand(0, 60);
                $utm = $utms[mt_rand(0, count($utms) - 1)];
                $more = [
                    'referrer' => $referrers[mt_rand(0, count($referrers) - 1)],
                    'country' => ['SE', 'NO', 'DK', null][mt_rand(0, 3)],
                    'language' => ['sv', 'en', 'nb', null][mt_rand(0, 3)],
                    'browser' => ['Chrome', 'Safari', 'Firefox'][mt_rand(0, 2)],
                    'device' => ['Desktop', 'Mobile'][mt_rand(0, 1)],
                ] + ($utm ? array_combine(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'], $utm) : []);
                $at = $start;
                // A visit ends at midnight: the salt changes with the day, and so does the hash
                $dayEnd = strtotime($date . ' 23:59:59');
                for ($n = mt_rand(1, 4); $n > 0 && $at <= $dayEnd; $n--) {
                    summaryPageview($db, $site, date('Y-m-d H:i:s', min($at, $ago === 0 ? $now : $at)), $visitor, $paths[mt_rand(0, count($paths) - 1)], $more);
                    // Now and then the next page in the same second
                    $at += mt_rand(0, 3) === 0 ? 0 : mt_rand(5, 400);
                }
            }
        }
    }
    $db->prepare("INSERT INTO goals (site, path, label, type, created_at) VALUES ('a.example', '/pricing', 'Pricing', 'page', ?)")->execute([date('Y-m-d H:i:s')]);
    $config = ['db_path' => $db->query('PRAGMA database_list')->fetch()['file']];

    // More missing than one window: the dashboard answers from the rows meanwhile, the same
    $_GET = ['days' => 90];
    $before = json_decode(get_api_data($config, ['sites' => []]), true);
    $rows = json_decode(get_api_data($config + ['daily_summary' => false], ['sites' => []]), true);
    unset($before['now'], $rows['now']);
    expect($before)->toBe($rows)
        ->and((int) $db->query("SELECT COUNT(DISTINCT date) FROM daily WHERE kind = 'day'")->fetchColumn())->toBe(31);
    // Then every day is summed, so every request below reads the summary
    sum_missing_days($db, tracked_sites($db), '2000-01-01', '', PHP_INT_MAX);

    $requests = [
        ['days' => 1, 'site' => 'a.example'],
        ['days' => 7, 'site' => 'a.example'],
        ['days' => 30, 'site' => 'a.example'],
        ['days' => 90, 'site' => 'b.example'],
        ['from' => date('Y-m-d', strtotime('-20 days')), 'to' => date('Y-m-d', strtotime('-5 days')), 'site' => 'a.example'],
        ['days' => 30],
        ['days' => 30, 'site' => 'a.example', 'expand' => 'pages'],
        ['days' => 30, 'site' => 'a.example', 'expand' => 'referrers'],
        ['days' => 30, 'site' => 'b.example', 'expand' => 'utm'],
    ];
    foreach ($requests as $get) {
        $_GET = $get;
        $rows = json_decode(get_api_data($config + ['daily_summary' => false], ['sites' => []]), true);
        $summed = json_decode(get_api_data($config, ['sites' => []]), true);
        unset($rows['now'], $summed['now']);
        expect($summed)->toBe($rows, 'for ' . http_build_query($get));
    }
    $_GET = [];
    // The summary was read, not only written: every day that is over is in it
    expect((int) $db->query("SELECT COUNT(DISTINCT date) FROM daily WHERE kind = 'day' AND site = 'a.example'")->fetchColumn())
        ->toBeGreaterThanOrEqual(39);
})->with([20, 7, 1234]);

test('stats:build sums every finished day of every site, and says when there is nothing left', function () {
    $db = summaryTestDb();
    $file = $db->query('PRAGMA database_list')->fetch()['file'];
    $ago = fn (int $n) => date('Y-m-d', strtotime("-{$n} days"));
    summaryPageview($db, 'a.example', $ago(10) . ' 12:00:00', 'x', '/');
    summaryPageview($db, 'b.example', $ago(10) . ' 12:00:00', 'y', '/');
    $run = function () use ($file) {
        $wrapper = dirname($file) . '/run.php';
        file_put_contents($wrapper, "<?php\n\$_ENV['DB_PATH'] = " . var_export($file, true) . ";\n\$argv = ['puls', 'stats:build'];\n\$argc = 2;\nrequire " . var_export(realpath(__DIR__ . '/../../puls'), true) . ";\n");
        exec('php ' . escapeshellarg($wrapper) . ' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    };
    $days = (int) round((strtotime(last_closed_day()) - strtotime($ago(10))) / 86400) + 1;

    [$code, $out] = $run();
    expect($code)->toBe(0)
        ->and($out)->toContain("a.example: {$days} days summed")
        ->and($out)->toContain("b.example: {$days} days summed")
        ->and($out)->toContain('Summed ' . (2 * $days) . ' days.');
    [$code, $out] = $run();
    expect($out)->toContain('a.example: up to date')->and($out)->toContain('Every finished day was summed already.');
});

test('a busy database means the rows are read and the day summed later, not an error', function () {
    $db = summaryTestDb();
    $file = $db->query('PRAGMA database_list')->fetch()['file'];
    $day = last_closed_day();
    summaryPageview($db, 's', "{$day} 12:00:00", 'a', '/');
    // Another connection holds the write lock, and this one does not wait for it
    $other = new PDO('sqlite:' . $file);
    $other->exec('BEGIN IMMEDIATE');
    $db->exec('PRAGMA busy_timeout = 0');

    expect(summary_figures($db, ['s'], $day, date('Y-m-d', strtotime('+1 day')), $day, ['pages' => 10, 'referrers' => 10, 'utm' => 10]))->toBeNull();
    sum_closed_day($db);
    expect(file_exists(dirname($file) . '/.summed'))->toBeFalse();

    $other->exec('ROLLBACK');
    sum_closed_day($db);
    expect(file_get_contents(dirname($file) . '/.summed'))->toBe($day);
});
