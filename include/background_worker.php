<?php

// Bootstrap environment
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mod_manager.php';

// Ensure we are running from CLI
if (php_sapi_name() !== 'cli' && !defined('MCMM_DEBUG_FORCE_WORKER')) {
    die("This script must be run from the command line.\n");
}

// Polyfill for parse_plugin_cfg if not available (e.g. running via CLI/Cron without Unraid context)
if (!function_exists('parse_plugin_cfg')) {
    function parse_plugin_cfg($plugin)
    {
        $cfgFile = "/boot/config/plugins/$plugin/$plugin.cfg";
        if (!file_exists($cfgFile)) {
            return [];
        }
        $config = [];
        $lines = file($cfgFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                $config[$key] = $value;
            }
        }
        return $config;
    }
}

function run_background_update_check()
{
    $plugin = 'mcmm';
    $config = @parse_plugin_cfg($plugin) ?: [];

    $apiKey = $config['curseforge_api_key'] ?? '';
    if (empty($apiKey)) {
        dbg("Background Worker: No CurseForge API Key found. Skipping update checks.");
        return;
    }

    $servers = getMinecraftServers();
    dbg("Background Worker: Starting update check for " . count($servers) . " servers.");

    foreach ($servers as $server) {
        $id = $server['id'];
        $name = $server['name'];
        dbg("Background Worker: Processing server: $name ($id)");

        $dataDir = getContainerDataDirById($id);
        if (!$dataDir || !is_dir($dataDir)) {
            dbg("Background Worker: Data directory not found for $id. Skipping.");
            continue;
        }

        $metaDir = getServerMetadataDir($id);
        $metaFile = "$metaDir/metadata.json";
        $metadata = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : [];

        $mcVersion = $metadata['mc_version'] ?? $server['mcVersion'] ?? '';
        $loader = $metadata['loader'] ?? $server['loader'] ?? 'forge';

        // Scan mods on disk
        $mods = scanServerMods($dataDir);

        // Enrich with filename-keyed metadata to get File IDs
        $metaFileMods = "$metaDir/installed_mods.json";
        $localMetadata = [];
        if (file_exists($metaFileMods)) {
            $localMetadata = json_decode(file_get_contents($metaFileMods), true) ?: [];
        }

        foreach ($mods as &$mod) {
            $f = $mod['fileName'];
            if (isset($localMetadata[$f])) {
                $info = $localMetadata[$f];
                $mod['modId'] = $info['modId'] ?? $info['id'] ?? $mod['modId'] ?? null;
                $mod['curseforgeId'] = ($info['platform'] ?? 'curseforge') === 'curseforge' ? $mod['modId'] : null;
                $mod['installedFileId'] = $info['fileId'] ?? $info['installedFileId'] ?? $mod['installedFileId'] ?? null;
                $mod['platform'] = $info['platform'] ?? 'curseforge';
            }
        }
        unset($mod);

        // Check for updates
        dbg("Background Worker: Calling checkModUpdates for $name...");
        $modsWithUpdates = checkModUpdates($mods, $apiKey, $mcVersion, $loader);

        // Persist update status to installed_mods.json atomically
        if (file_exists($metaFileMods)) {
            $fp = fopen($metaFileMods, 'c+');
            if ($fp && flock($fp, LOCK_EX)) {
                $content = stream_get_contents($fp);
                $diskMetadata = json_decode($content, true) ?: [];

                $updatesFound = 0;
                foreach ($modsWithUpdates as $m) {
                    $f = $m['fileName'];
                    if (isset($diskMetadata[$f])) {
                        $updateAvail = $m['updateAvailable'] ?? false;
                        $diskMetadata[$f]['updateAvailable'] = $updateAvail;
                        if ($updateAvail) {
                            $diskMetadata[$f]['latestFileId'] = $m['latestFileId'] ?? null;
                            $diskMetadata[$f]['latestFileName'] = $m['latestFileName'] ?? null;
                            $updatesFound++;
                        }
                    }
                }

                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($diskMetadata, JSON_PRETTY_PRINT));
                fflush($fp);
                flock($fp, LOCK_UN);
                dbg("Background Worker: Finished $name. Found $updatesFound updates.");
            }
            if ($fp)
                fclose($fp);
        }

        // Invalidate server-side cache to ensure fresh data on next mod list call
        $cacheFile = "$metaDir/mods_cache.json";
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }
    }

    dbg("Background Worker: All servers processed.");
}

// Execute
run_background_update_check();
