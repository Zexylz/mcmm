<?php

declare(strict_types=1);

/**
 * Append file modification time to path for cache busting.
 *
 * @param string $path The relative path to the file.
 * @return string The path with a version query parameter.
 */
function mcmm_autov(string $path): string
{
    $docroot = $_SERVER['DOCUMENT_ROOT'] ?? '/usr/local/emhttp';
    $full = rtrim($docroot, '/') . $path;

    $v = @filemtime($full);
    if (!$v) {
        $v = time();
    }

    return $path . '?v=' . $v;
}

/**
 * Retrieve a list of Minecraft servers from Docker containers and internal configuration.
 *
 * Scans Docker for containers matching 'itzg' or 'mcmm' and merges with local config.
 *
 * @return array List of servers with status, ports, and metadata.
 */
function getMinecraftServers()
{
    global $config;

    $servers = [];
    $serversBase = defined('MCMM_SERVERS_DIR') ? MCMM_SERVERS_DIR : '/mnt/user/appdata/mcmm/servers';

    // Debug logging
    $debugLog = "=== getMinecraftServers Debug ===\n";

    // First, read all server configs from disk to have them ready for matching
    $serverConfigs = [];
    if (is_dir($serversBase)) {
        $dirs = @glob($serversBase . '/*', GLOB_ONLYDIR);
        if ($dirs) {
            foreach ($dirs as $dir) {
                // IMPORTANT: The directory name itself is NOT always the container name anymore.
                // We must rely ongetServerMetadataDir or scan the directories carefully.
                $metaFile = $dir . '/metadata.json';
                if (file_exists($metaFile)) {
                    $meta = @json_decode(file_get_contents($metaFile), true);
                    if ($meta && (isset($meta['containerName']) || isset($meta['serverName']))) {
                        $cName = $meta['containerName'] ?? $meta['serverName'];
                        $serverConfigs[$cName] = $meta;
                        $debugLog .= "Loaded meta for: $cName, icon: " . ($meta['logo'] ?? $meta['icon'] ?? 'none') . "\n";
                    }
                }
            }
        }
    }

    // Then, get running containers from Docker
    $cmd = '/usr/bin/docker ps -a --format "{{.ID}}|{{.Names}}|{{.Status}}|{{.Image}}|{{.Ports}}|{{.Labels}}"';
    $output = @shell_exec($cmd . ' 2>/dev/null');

    $debugLog .= "Docker command: $cmd\n";
    $debugLog .= "Docker output: " . ($output ? "YES" : "EMPTY") . "\n";

    if ($output) {
        $lines = explode("\n", trim($output));
        $debugLog .= "Found " . count($lines) . " lines\n";

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }
            $parts = explode('|', $line);

            $debugLog .= "Processing line: $line\n";
            $debugLog .= "Parts count: " . count($parts) . "\n";

            if (count($parts) >= 4) {
                $image = $parts[3];
                $labels = $parts[5] ?? '';
                $containerName = $parts[1];
                $containerId = $parts[0];

                $debugLog .= "Container: $containerName, Image: $image\n";

                // Match logic: itzg images (by name or label), or mcmm label
                $isItzg = strpos($image, 'itzg/') !== false;
                $isMcmm = strpos($labels, 'mcmm=1') !== false;

                // Also check if labels contain itzg repository (handles image ID case)
                if (!$isItzg && (strpos($labels, 'net.unraid.docker.repository=itzg/') !== false)) {
                    $isItzg = true;
                }

                $debugLog .= "IsItzg: " . ($isItzg ? 'YES' : 'NO') . ", IsMcmm: " . ($isMcmm ? 'YES' : 'NO') . "\n";

                if ($isItzg || $isMcmm) {
                    $isRunning = strpos($parts[2], 'Up') !== false;

                    $port = '25565';
                    if (preg_match('/:(\d+)->25565/', $parts[4] ?? '', $matches) || preg_match('/(\d+)->25565/', $parts[4] ?? '', $matches)) {
                        $port = $matches[1];
                    }

                    // Try to get icon from config file first, then from Docker labels
                    $icon = '';
                    if (isset($serverConfigs[$containerName]['logo'])) {
                        $icon = $serverConfigs[$containerName]['logo'];
                        $debugLog .= "Icon from config: $icon\n";
                    } else {
                        // Parse label value
                        foreach (explode(',', $labels) as $lbl) {
                            $kv = explode('=', $lbl, 2);
                            if (count($kv) === 2 && (trim($kv[0]) === 'net.unraid.docker.icon' || trim($kv[0]) === 'mcmm.icon')) {
                                $icon = trim($kv[1]);
                                $debugLog .= "Icon from label: $icon\n";
                                break;
                            }
                        }

                        // If still no icon, try to backfill from Docker environment variables
                        if (empty($icon)) {
                            $icon = backfillServerIcon($containerId, $containerName, $config);
                            $debugLog .= "Icon from backfill: " . ($icon ?: 'none') . "\n";
                        }
                    }

                    // Try to hydrate basic stats from cached metrics files (extremely fast)
                    $ramUsedMb = 0;
                    $cpuUsage = 0;
                    $mcVer = 'Latest';
                    $loaderVer = 'Vanilla';

                    $metaDir = getServerMetadataDir($containerId);
                    $metricsFile = $metaDir . '/mcmm_metrics.json';
                    if (file_exists($metricsFile)) {
                        $metrics = json_decode(file_get_contents($metricsFile), true);
                        if ($metrics) {
                            $ramUsedMb = $metrics['heap_used_mb'] ?? 0;
                            $cpuUsage = ($metrics['cpu_milli'] ?? 0) / 1000.0;
                        }
                    }

                    // Metadata cache
                    $metaFile = "$metaDir/metadata.json";
                    $modpackVer = '';
                    if (file_exists($metaFile)) {
                        $meta = json_decode(file_get_contents($metaFile), true);
                        if ($meta) {
                            $mcVer = $meta['mc_version'] ?? $meta['mcVersion'] ?? 'Latest';
                            $loaderVer = $meta['loader'] ?? 'Vanilla';
                            $modpackVer = $meta['modpack_version'] ?? $meta['modpackVersion'] ?? '';
                        }
                    }

                    $servers[] = [
                        'id' => $containerId,
                        'name' => $containerName,
                        'status' => $isRunning ? 'Running' : 'Stopped',
                        'isRunning' => $isRunning,
                        'ports' => $port,
                        'image' => $image,
                        'icon' => $icon,
                        'ramUsedMb' => $ramUsedMb,
                        'cpu' => $cpuUsage,
                        'mcVersion' => $mcVer,
                        'loader' => $loaderVer,
                        'modpackVersion' => $modpackVer
                    ];

                    $debugLog .= "Added server: $containerName\n";
                } else {
                    $debugLog .= "Skipped (not itzg/mcmm)\n";
                }
            }
        }
    }

    $debugLog .= "Total servers found: " . count($servers) . "\n";
    @mcmm_file_put_contents('/tmp/mcmm_debug_page.log', $debugLog);

    return $servers;
}
