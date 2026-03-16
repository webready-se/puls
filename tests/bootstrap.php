<?php

/**
 * Test bootstrap — load functions from index.php without executing routing.
 */

$source = file_get_contents(__DIR__ . '/../public/index.php');

// Extract all function definitions
preg_match_all('/^function \w+\([^)]*\)(?:: [^\n]+)?\n\{.+?\n\}/ms', $source, $matches);

foreach ($matches[0] as $fn) {
    if (preg_match('/^function (\w+)/', $fn, $m) && !function_exists($m[1])) {
        eval($fn);
    }
}

// Load config resolve_path function
$configSource = file_get_contents(__DIR__ . '/../config.php');
preg_match_all('/^function \w+\([^)]*\)(?:: [^\n]+)?\n\{.+?\n\}/ms', $configSource, $matches);

foreach ($matches[0] as $fn) {
    if (preg_match('/^function (\w+)/', $fn, $m) && !function_exists($m[1])) {
        eval($fn);
    }
}
