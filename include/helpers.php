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
    $serversDir = '/boot/config/plugins/mcmm/servers';

    // Debug logging
    $debugLog = "=== getMinecraftServers Debug ===\n";

    // Ensure servers directory exists
    if (!is_dir($serversDir)) {
        @mkdir($serversDir, 0755, true);
    }

    // First, read all server configs from disk
    $serverConfigs = [];
    if (is_dir($serversDir)) {
        $dirs = @glob($serversDir . '/*', GLOB_ONLYDIR);
        if ($dirs) {
            foreach ($dirs as $dir) {
                $configFile = $dir . '/config.json';
                if (file_exists($configFile)) {
                    $cfg = @json_decode(file_get_contents($configFile), true);
                    if ($cfg && isset($cfg['containerName'])) {
                        $serverConfigs[$cfg['containerName']] = $cfg;
                        $debugLog .= "Loaded config for: {$cfg['containerName']}, icon: " . ($cfg['logo'] ?? 'none') . "\n";
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
                            $icon = backfillServerIconPage($containerId, $containerName, $serversDir, $config);
                            $debugLog .= "Icon from backfill: " . ($icon ?: 'none') . "\n";
                        }
                    }

                    // Try to hydrate basic stats from cached metrics files (extremely fast)
                    $ramUsedMb = 0;
                    $cpuUsage = 0;
                    $mcVer = 'Latest';
                    $loaderVer = 'Vanilla';

                    $dataDir = "/mnt/user/appdata/mcmm/servers/$containerName";
                    if (!is_dir($dataDir)) {
                        $dataDir = "/mnt/user/appdata/binaries/$containerName";
                    }

                    $metricsFile = $dataDir . '/mcmm_metrics.json';
                    if (file_exists($metricsFile)) {
                        $metrics = json_decode(file_get_contents($metricsFile), true);
                        if ($metrics) {
                            $ramUsedMb = $metrics['heap_used_mb'] ?? 0;
                            $cpuUsage = ($metrics['cpu_milli'] ?? 0) / 1000.0;
                        }
                    }

                    // Metadata cache
                    $metaFile = "/boot/config/plugins/mcmm/servers/" . md5($containerName) . "/metadata_v11.json";
                    $modpackVer = '';
                    if (file_exists($metaFile)) {
                        $meta = json_decode(file_get_contents($metaFile), true);
                        if ($meta) {
                            $mcVer = $meta['mcVersion'] ?? 'Latest';
                            $loaderVer = $meta['loader'] ?? 'Vanilla';
                            $modpackVer = $meta['modpackVersion'] ?? '';
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
    @file_put_contents('/tmp/mcmm_debug_page.log', $debugLog);

    return $servers;
}

/**
 * Attempt to backfill server icon from Docker environment variables.
 *
 * Simplified version for page load to avoid timeouts.
 *
 * @param string $containerId   Docker container ID.
 * @param string $containerName Target container name.
 * @param string $serversDir    Base directory for server configs.
 * @param array  $config        Global plugin configuration.
 * @return string The resolved icon URL or empty string if not found.
 */
function backfillServerIconPage($containerId, $containerName, $serversDir, $config)
{
    try {
        // Inspect container to get environment variables
        $inspectCmd = '/usr/bin/docker inspect ' . escapeshellarg($containerId) . ' 2>/dev/null';
        $inspectOutput = @shell_exec($inspectCmd);
        if (!$inspectOutput) {
            return '';
        }

        $inspectData = @json_decode($inspectOutput, true);
        if (!$inspectData || !isset($inspectData[0])) {
            return '';
        }

        $containerInfo = $inspectData[0];
        $env = $containerInfo['Config']['Env'] ?? [];

        // Extract ICON environment variable directly (fastest method)
        foreach ($env as $envVar) {
            if (strpos($envVar, 'ICON=') === 0) {
                $iconEnv = substr($envVar, 5);
                if (!empty($iconEnv)) {
                    // Save to config for future use
                    $serverId = md5($containerName);
                    $serverDir = $serversDir . '/' . $serverId;

                    if (!is_dir($serverDir)) {
                        @mkdir($serverDir, 0755, true);
                    }

                    $serverConfig = [
                        'id' => $serverId,
                        'name' => $containerName,
                        'logo' => $iconEnv,
                        'containerName' => $containerName,
                        'backfilled' => true
                    ];

                    @file_put_contents($serverDir . '/config.json', json_encode($serverConfig, JSON_PRETTY_PRINT));
                    return $iconEnv;
                }
            }
        }

        // If no ICON env var, don't do slow API lookups on page load
        // These will be handled by the API endpoint instead
        return '';
    } catch (Exception $e) {
        // Silently fail to avoid breaking page load
        return '';
    }
}
