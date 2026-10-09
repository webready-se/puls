<?php

test('fills missing days with zero counts', function () {
    $rows = [['date' => '2026-10-08', 'count' => '3', 'visitors' => '2']];
    $out = fill_days($rows, '2026-10-07', 3);
    expect($out)->toBe([
        ['date' => '2026-10-07', 'count' => 0, 'visitors' => 0],
        ['date' => '2026-10-08', 'count' => 3, 'visitors' => 2],
        ['date' => '2026-10-09', 'count' => 0, 'visitors' => 0],
    ]);
});

test('produces one entry per day across a DST change', function () {
    $tz = date_default_timezone_get();
    date_default_timezone_set('Europe/Stockholm');
    try {
        // DST ends 2026-10-25 in Europe; adding 86400 s per day would repeat a date
        $dates = array_column(fill_days([], '2026-10-23', 5), 'date');
    } finally {
        date_default_timezone_set($tz);
    }
    expect($dates)->toBe(['2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26', '2026-10-27']);
});
