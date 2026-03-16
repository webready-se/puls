#!/usr/bin/env php
<?php
/**
 * One-time migration: normalize paths and backfill UTM data in existing pageviews.
 * Strips tracking params (fbclid, utm_*, etc.) that were stored before normalize_path existed.
 * Fills in missing utm_term/utm_content from query strings in path.
 *
 * Usage: php scripts/normalize-paths.php
 * Safe to run multiple times — only updates rows that actually change.
 */

$config = require __DIR__ . '/../config.php';
$dbPath = $config['db_path'];

if (!file_exists($dbPath)) {
    echo "Database not found: {$dbPath}\n";
    exit(1);
}

function normalize_path(string $path): string
{
    if (str_contains($path, '?')) {
        [$pathPart, $query] = explode('?', $path, 2);
        parse_str($query, $params);
        $strip = ['fbclid', 'gclid', 'gclsrc', 'dclid', 'msclkid', 'mc_eid',
                   'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
                   'ref', 'hsa_cam', 'hsa_grp', 'hsa_mt', 'hsa_src', 'hsa_ad', 'hsa_acc',
                   'hsa_net', 'hsa_ver', 'hsa_la', 'hsa_ol', 'hsa_kw'];
        foreach ($strip as $key) {
            unset($params[$key]);
        }
        $path = $params ? $pathPart . '?' . http_build_query($params) : $pathPart;
    }
    if ($path !== '/' && str_ends_with($path, '/')) {
        $path = rtrim($path, '/');
    }
    return $path;
}

function extract_utm(string $path): array
{
    if (!str_contains($path, '?')) return [];
    [, $query] = explode('?', $path, 2);
    parse_str($query, $params);
    $utm = [];
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
        if (!empty($params[$key])) {
            $utm[$key] = substr($params[$key], 0, 200);
        }
    }
    return $utm;
}

$db = new PDO("sqlite:{$dbPath}");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Ensure utm_term and utm_content columns exist
$cols = array_column($db->query('PRAGMA table_info(pageviews)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('utm_term', $cols)) {
    $db->exec('ALTER TABLE pageviews ADD COLUMN utm_term TEXT');
    $db->exec('ALTER TABLE pageviews ADD COLUMN utm_content TEXT');
    echo "Added utm_term and utm_content columns.\n";
}

// 1. Normalize paths in pageviews
$rows = $db->query("SELECT id, path FROM pageviews WHERE path LIKE '%?%'")->fetchAll(PDO::FETCH_ASSOC);
$pathsUpdated = 0;
$utmBackfilled = 0;

$stmtPath = $db->prepare("UPDATE pageviews SET path = ? WHERE id = ?");
$stmtUtm = $db->prepare("UPDATE pageviews SET utm_source = COALESCE(utm_source, ?), utm_medium = COALESCE(utm_medium, ?), utm_campaign = COALESCE(utm_campaign, ?), utm_term = COALESCE(utm_term, ?), utm_content = COALESCE(utm_content, ?) WHERE id = ?");

foreach ($rows as $row) {
    // Backfill UTM from path before we strip it
    $utm = extract_utm($row['path']);
    if ($utm) {
        $stmtUtm->execute([
            $utm['utm_source'] ?? null,
            $utm['utm_medium'] ?? null,
            $utm['utm_campaign'] ?? null,
            $utm['utm_term'] ?? null,
            $utm['utm_content'] ?? null,
            $row['id'],
        ]);
        $utmBackfilled++;
    }

    // Normalize path
    $clean = normalize_path($row['path']);
    if ($clean !== $row['path']) {
        $stmtPath->execute([$clean, $row['id']]);
        $pathsUpdated++;
    }
}

echo "pageviews: {$pathsUpdated} paths normalized, {$utmBackfilled} rows UTM-backfilled (of " . count($rows) . " with query strings)\n";

// 2. Normalize paths in bot_visits
$rows = $db->query("SELECT id, path FROM bot_visits WHERE path LIKE '%?%'")->fetchAll(PDO::FETCH_ASSOC);
$botUpdated = 0;
$stmtBot = $db->prepare("UPDATE bot_visits SET path = ? WHERE id = ?");

foreach ($rows as $row) {
    $clean = normalize_path($row['path']);
    if ($clean !== $row['path']) {
        $stmtBot->execute([$clean, $row['id']]);
        $botUpdated++;
    }
}

echo "bot_visits: {$botUpdated} paths normalized (of " . count($rows) . " with query strings)\n";
echo "\nDone.\n";
