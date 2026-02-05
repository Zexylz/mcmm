<?php
// debug_scan.php

require_once __DIR__ . '/include/lib.php';
require_once __DIR__ . '/include/mod_manager.php';

// Mocking GET parameters for simulation if needed, but we'll specific hardcoded or first server found.
// Find a server to test.

$serversDir = '/boot/config/plugins/mcmm/servers';
$servers = glob("$serversDir/*", GLOB_ONLYDIR);

if (empty($servers)) {
    echo "No servers found in $serversDir\n";
    exit;
}

$serverId = basename($servers[0]);
echo "Testing with Server ID: $serverId\n";

$dataDir = getContainerDataDirById($serverId);
if (!$dataDir) {
    // Try to find any directory with mods
    echo "Could not resolve data dir for $serverId via Docker. Trying heuristic...\n";
    // This might fail if docker is not available in this context (e.g. CLI vs Web).
    // Let's assume user visits this via browser: /plugins/mcmm/debug_scan.php
}

echo "Data Dir: " . ($dataDir ?: 'Not Found') . "\n";

if ($dataDir) {
    echo "Scanning mods directory...\n";
    $mods = scanServerMods($dataDir);

    echo "Found " . count($mods) . " mods.\n";

    // Output first 5 mods details
    $count = 0;
    foreach ($mods as $mod) {
        if ($count++ >= 5)
            break;
        echo "\n------------------------------------------------\n";
        echo "File: " . $mod['fileName'] . "\n";
        echo "Name: " . $mod['name'] . "\n";
        echo "Version: " . $mod['version'] . "\n";
        echo "Author (Raw): " . ($mod['author'] ?? 'N/A') . "\n";
        echo "Authors (Array): " . json_encode($mod['authors']) . "\n";
        echo "Description: " . substr($mod['description'], 0, 100) . "...\n";
        echo "Has Metadata (Loader)? " . $mod['loader'] . "\n";
    }
} else {
    echo "Cannot scan, no data directory.\n";
}
