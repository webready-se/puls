<?php

/**
 * Tests for the table sites:list prints: a long site name must not run into the
 * dates, and every column must stay under its header, with and without groups.
 */

require_once __DIR__ . '/../Support/helpers.php';

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/puls_list_test_' . uniqid();
    mkdir($this->tmpDir);
    file_put_contents($this->tmpDir . '/users.json', '{}');
    $db = createCliTestDb($this->tmpDir . '/test.sqlite');
    $stmt = $db->prepare("INSERT INTO pageviews (site, path, visitor_hash, created_at) VALUES (?, '/', ?, '2026-10-01 10:00:00')");
    foreach (['a.example', 'kökstillbehörsbutiken-and-more.example'] as $i => $site) {
        $stmt->execute([$site, 'hash-' . $i]);
    }
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->tmpDir));
});

/** The column (in characters, not bytes) where each data row's first date starts, and the header's SINCE. */
function listColumns(string $output): array
{
    $lines = explode("\n", $output);
    $header = array_values(array_filter($lines, fn($l) => str_starts_with($l, 'SITE')))[0];
    $since = mb_strpos($header, 'SINCE');
    $rows = [];
    foreach ($lines as $line) {
        if (preg_match('/^(.*?)(\d{4}-\d{2}-\d{2})/u', $line, $m)) {
            $rows[trim($m[1])] = ['date' => mb_strlen($m[1]), 'gap' => mb_strlen($m[1]) - mb_strlen(rtrim($m[1]))];
        }
    }
    return [$since, $rows];
}

it('keeps a long site name apart from its dates, with every date under SINCE', function () {
    [$since, $rows] = listColumns(runCli($this->tmpDir, 'sites:list')['output']);
    expect($rows)->toHaveCount(2);
    foreach ($rows as $site => $row) {
        expect($row['date'])->toBe($since, "{$site}: the date starts under SINCE")
            ->and($row['gap'])->toBeGreaterThanOrEqual(2, "{$site}: at least two spaces before the date");
    }
});

it('keeps the columns aligned under group headers too', function () {
    runCli($this->tmpDir, 'sites:group', ['Shops', 'kökstillbehörsbutiken-and-more.example']);
    [$since, $rows] = listColumns(runCli($this->tmpDir, 'sites:list')['output']);
    foreach ($rows as $site => $row) {
        expect($row['date'])->toBe($since, "{$site}: the date starts under SINCE")
            ->and($row['gap'])->toBeGreaterThanOrEqual(2, "{$site}: at least two spaces before the date");
    }
});
