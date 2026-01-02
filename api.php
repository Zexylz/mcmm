<?php

/**
 * MCMM API Handler
 * Handles all API requests for the Minecraft Modpack Manager
 */

require_once __DIR__ . '/include/lib.php';


// EMERGENCY DEBUG
// file_put_contents('/tmp/mcmm_debug.txt', date('Y-m-d H:i:s') . " - HIT " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['QUERY_STRING'] . "\n", FILE_APPEND);

header('Content-Type: application/json');

// Prevent HTML errors from corrupting JSON
@ini_set('display_errors', 0);
@ini_set('log_errors', 1);

// Paths
// Standard Unraid config location
$plugin = 'mcmm';
$configDir = "/boot/config/plugins/{$plugin}";
$configPath = "{$configDir}/{$plugin}.cfg";
$defaultConfigPath = __DIR__ . "/default.cfg"; // In plugins/mcmm/default.cfg
$defaultConfigPath = dirname(__DIR__) . "/default.cfg"; // In plugins/mcmm/default.cfg


// Ensure config directory exists
if (!is_dir($configDir)) {
    @mkdir($configDir, 0755, true);
}

// Load default config
$defaults = [];
if (file_exists($defaultConfigPath)) {
    $defaults = parse_ini_file($defaultConfigPath);
}
if (!$defaults) {
    // Fallback if default.cfg is missing or invalid
    $defaults = [
        'curseforge_api_key' => '',
        'default_server_name' => 'My Modpack Server',
        'default_port' => 25565,
        'default_memory' => '4G',
        'default_max_players' => 20,
        'default_ip' => '0.0.0.0',
        'default_whitelist' => '',
        'default_icon_url' => '',
        'default_pvp' => true,
        'default_hardcore' => false,
        'default_allow_flight' => false,
        'default_command_blocks' => true,
        'default_rolling_logs' => true,
        'default_log_timestamp' => true,
        'default_direct_console' => false,
        'default_aikar_flags' => true,
        'default_meowice_flags' => false,
        'default_graalvm_flags' => false,
        'jvm_flags' => ''
    ];
}

// Load user config
$config = [];
if (file_exists($configPath)) {
    $config = parse_ini_file($configPath);
}
// Merge defaults
$config = array_merge($defaults, $config);

// Route action
$action = $_GET['action'] ?? '';



// Ensure nothing is printed before JSON
if (ob_get_length()) {
    ob_clean();
}

dbg("API Loaded. Action: " . ($action ? $action : 'none'));

// Ensure log dir exists (no longer needed for /tmp, but keeping structure safe)
// if (!is_dir('/var/log/plugins')) @mkdir('/var/log/plugins', 0755, true);

try {
    // Validate config loading
    if (!is_array($config)) {
        dbg("Warning: Config was not an array, resetting to empty.");
        $config = [];
    }
    $config = array_merge($defaults, $config);

    switch ($action) {
        case 'ping':
            jsonResponse([
                'success' => true,
                'message' => 'API is working!',
                'time' => date('Y-m-d H:i:s')
            ]);
            break;

        // ... (rest of the switch cases) ...


        case 'get_log':
            // Ensure clean output for this endpoint too
            if (ob_get_length()) {
                ob_clean();
            }

            $logFile = '/tmp/mcmm.log';
            $debugFile = '/tmp/mcmm_debug.txt';
            $pageDebugFile = '/tmp/mcmm_debug_page.log';
            $apiDebugFile = '/tmp/mcmm_debug_api_servers.log';

            $content = "";
            if (file_exists($logFile)) {
                $content .= "Main Log:\n" . file_get_contents($logFile) . "\n\n";
            }
            if (file_exists($debugFile)) {
                $content .= "Debug Log:\n" . file_get_contents($debugFile) . "\n\n";
            }
            if (file_exists($pageDebugFile)) {
                $content .= "Page Debug Log:\n" . file_get_contents($pageDebugFile) . "\n\n";
            }
            if (file_exists($apiDebugFile)) {
                $content .= "API Servers Log:\n" . file_get_contents($apiDebugFile);
            }

            jsonResponse(['success' => true, 'log' => $content ?: 'No logs found']);
            break;

        case 'settings':
            $response = $config;
            $response['has_curseforge_key'] = !empty($response['curseforge_api_key']);
            $response['curseforge_api_key_masked'] = !empty($response['curseforge_api_key'])
                ? substr($response['curseforge_api_key'], 0, 8) . '...'
                : '';
            unset($response['curseforge_api_key']);

            jsonResponse(['success' => true, 'data' => $response]);
            break;

        case 'save_settings':
            // Clear buffer again just to be sure
            if (ob_get_length()) {
                ob_clean();
            }

            dbg("Action: save_settings");

            $data = getRequestData();
            if (!$data) {
                dbg("Error: No input data found");
                jsonResponse(['success' => false, 'error' => 'No input data received'], 400);
            }

            $existing = $config;

            if (isset($data['curseforge_api_key'])) {
                $existing['curseforge_api_key'] = trim($data['curseforge_api_key']);
            }

            // ... existing field mapping logic ...
            $fields = [
                'default_server_name',
                'default_port',
                'default_memory',
                'default_max_players',
                'default_ip',
                'default_whitelist',
                'default_icon_url',
                'default_pvp',
                'default_hardcore',
                'default_allow_flight',
                'default_command_blocks',
                'default_rolling_logs',
                'default_log_timestamp',
                'default_direct_console',
                'default_aikar_flags',
                'default_meowice_flags',
                'default_graalvm_flags',
                'jvm_flags'
            ];

            foreach ($fields as $field) {
                if (array_key_exists($field, $data)) {
                    $val = $data[$field];
                    if (is_bool($val)) {
                        $val = $val ? "true" : "false";
                    }
                    $existing[$field] = $val;
                }
            }

            // Debug config dir
            if (!is_dir($configDir)) {
                dbg("Creating config dir: $configDir");
                if (!mkdir($configDir, 0777, true)) {
                    dbg("Error: Failed to create config dir");
                    jsonResponse(['success' => false, 'error' => "Failed to create directory: $configDir"], 500);
                }
            }

            // Write to mcmm.cfg
            dbg("Writing config to: $configPath");

            // Temporarily enable error display for debugging this specific operation if needed,
            // but we catch them via return value.
            $writeResult = write_ini_file($existing, $configPath);

            if ($writeResult === false) {
                $err = error_get_last();
                $errMsg = $err['message'] ?? 'Unknown error';
                dbg("Error: write_ini_file failed. PHP Error: $errMsg");
                jsonResponse(['success' => false, 'error' => "Failed to write config file. $errMsg"], 500);
            }

            dbg("Config written successfully ($writeResult bytes)");

            // Re-read config to ensure we have latest state
            // $config = array_merge($defaults, $existing);

            // Explicitly clear buffer just in case
            if (ob_get_length()) {
                ob_clean();
            }

            // Explicitly exit to prevent any further output
            jsonResponse(['success' => true, 'message' => 'Settings saved successfully']);
            exit;

        case 'console_logs':
            $id = $_GET['id'] ?? '';
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Missing ID'], 400);
            }

            $output = shell_exec("docker logs --tail 200 " . escapeshellarg($id) . " 2>&1");

            jsonResponse([
                'success' => true,
                'logs' => $output ?: ''
            ]);
            break;

        case 'console_command':
            $id = $_GET['id'] ?? '';
            $cmd = $_GET['cmd'] ?? '';
            if (!$id || !$cmd) {
                jsonResponse(['success' => false, 'error' => 'Missing parameters'], 400);
            }

            // Execute command via rcon-cli inside container
            $execCmd = "docker exec -i " . escapeshellarg($id) . " rcon-cli " . escapeshellarg($cmd);
            $output = [];
            $exitCode = 0;
            exec($execCmd . ' 2>&1', $output, $exitCode);

            jsonResponse([
                'success' => $exitCode === 0,
                'message' => implode("\n", $output)
            ]);
            break;

        case 'server_control':
            $id = $_GET['id'] ?? '';
            $cmd = $_GET['cmd'] ?? '';

            if (!$id || !$cmd) {
                jsonResponse(['success' => false, 'error' => 'Missing parameters'], 400);
            }

            if (!in_array($cmd, ['start', 'stop', 'restart'], true)) {
                jsonResponse(['success' => false, 'error' => 'Invalid command'], 400);
            }

            $output = shell_exec("docker $cmd " . escapeshellarg($id) . " 2>&1");

            jsonResponse([
                'success' => true,
                'message' => "Server $cmd command executed",
                'output' => $output
            ]);
            break;

        case 'server_delete':
            $id = $_GET['id'] ?? '';
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Missing ID'], 400);
            }

            // Stop then remove container
            $dockerBin = file_exists('/usr/bin/docker') ? '/usr/bin/docker' : 'docker';
            $stopOut = shell_exec("$dockerBin stop " . escapeshellarg($id) . " 2>&1");
            $rmOut = shell_exec("$dockerBin rm " . escapeshellarg($id) . " 2>&1");

            jsonResponse([
                'success' => true,
                'message' => 'Server deleted',
                'output' => [$stopOut, $rmOut]
            ]);
            break;

        case 'check_updates':
            $id = $_GET['id'] ?? '';
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Missing server ID'], 400);
            }

            $metaFile = "/boot/config/plugins/mcmm/servers/$id/installed_mods.json";
            if (!file_exists($metaFile)) {
                jsonResponse(['success' => true, 'updates' => []]);
            }

            $installed = json_decode(file_get_contents($metaFile), true) ?: [];
            if (empty($installed)) {
                jsonResponse(['success' => true, 'updates' => []]);
            }

            // Group by platform
            $cfIds = [];
            $mrIds = [];
            foreach ($installed as $mid => $info) {
                if (($info['platform'] ?? '') === 'modrinth') {
                    $mrIds[] = $mid;
                } else {
                    $cfIds[] = (int) $mid;
                }
            }

            $updates = [];

            // Fetch server config for version/loader
            $cfgFile = "/boot/config/plugins/mcmm/servers/$id/config.json";
            $cfg = file_exists($cfgFile) ? json_decode(file_get_contents($cfgFile), true) : [];
            $mcVer = $cfg['mc_version'] ?? '';
            $loader = $cfg['loader'] ?? '';

            // 1. Check CurseForge
            if (!empty($cfIds) && !empty($config['curseforge_api_key'])) {
                $payload = cfRequest('/mods', $config['curseforge_api_key'], false, 'POST', ['modIds' => $cfIds]);
                if ($payload && isset($payload['data'])) {
                    foreach ($payload['data'] as $mod) {
                        $targetFile = null;
                        if (!empty($mod['latestFiles'])) {
                            foreach ($mod['latestFiles'] as $file) {
                                $versions = $file['gameVersions'] ?? [];
                                $hasVersion = !$mcVer || in_array($mcVer, $versions);
                                $hasLoader = true;
                                if ($loader) {
                                    $loaderNames = [1 => 'forge', 4 => 'fabric', 5 => 'quilt', 6 => 'neoforge'];
                                    $loaderMap = ['forge' => 1, 'fabric' => 4, 'quilt' => 5, 'neoforge' => 6];
                                    $targetLoaderName = $loaderNames[$loaderMap[strtolower($loader)] ?? 0] ?? '';
                                    if ($targetLoaderName && !in_array($targetLoaderName, array_map('strtolower', $versions))) {
                                        $hasLoader = false;
                                    }
                                }
                                if ($hasVersion && $hasLoader) {
                                    $targetFile = $file;
                                    break;
                                }
                            }
                        }
                        if ($targetFile) {
                            $updates[$mod['id']] = [
                                'latestFileId' => $targetFile['id'],
                                'latestFileName' => $targetFile['fileName'] ?? ($targetFile['displayName'] ?? ''),
                                'name' => $mod['name']
                            ];
                        }
                    }
                }
            }

            // 2. Check Modrinth (projects API accepts multiple IDs)
            if (!empty($mrIds)) {
                $idsParam = json_encode($mrIds);
                $projects = mrRequest('/projects?ids=' . urlencode($idsParam));
                if ($projects && is_array($projects)) {
                    foreach ($projects as $proj) {
                        // For Modrinth, we need to fetch the latest version for the specific MC version
                        $fileUrl = "https://api.modrinth.com/v2/project/{$proj['id']}/version";
                        $params = [];
                        if ($mcVer) {
                            $params['game_versions'] = json_encode([$mcVer]);
                        }
                        if ($loader) {
                            $params['loaders'] = json_encode([strtolower($loader)]);
                        }

                        $verQuery = !empty($params) ? '?' . http_build_query($params) : '';
                        $versions = mrRequest('/project/' . $proj['id'] . '/version' . $verQuery);

                        if (!empty($versions)) {
                            $latest = $versions[0];
                            $primaryFile = null;
                            foreach ($latest['files'] as $f) {
                                if ($f['primary']) {
                                    $primaryFile = $f;
                                    break;
                                }
                            }
                            if (!$primaryFile) {
                                $primaryFile = $latest['files'][0] ?? null;
                            }

                            if ($primaryFile) {
                                $updates[$proj['id']] = [
                                    'latestFileId' => $latest['id'],
                                    'latestFileName' => $primaryFile['filename'],
                                    'name' => $proj['title']
                                ];
                            }
                        }
                    }
                }
            }

            jsonResponse(['success' => true, 'updates' => $updates]);
            break;

        case 'servers':
            $servers = [];
            $serversDir = '/boot/config/plugins/mcmm/servers';

            // Ensure servers directory exists
            if (!is_dir($serversDir)) {
                @mkdir($serversDir, 0755, true);
            }

            // First, read all server configs from disk (like minecraft-modpack-manager)
            $serverConfigs = [];
            if (is_dir($serversDir)) {
                $dirs = glob($serversDir . '/*', GLOB_ONLYDIR);
                foreach ($dirs as $dir) {
                    $configFile = $dir . '/config.json';
                    if (file_exists($configFile)) {
                        $cfg = json_decode(file_get_contents($configFile), true);
                        if ($cfg && isset($cfg['containerName'])) {
                            $serverConfigs[$cfg['containerName']] = $cfg;
                        }
                    }
                }
            }

            // Then, get running containers from Docker
            $cmd = '/usr/bin/docker ps -a --format "{{.ID}}|{{.Names}}|{{.Status}}|{{.Image}}|{{.Ports}}|{{.Labels}}"';
            $output = shell_exec($cmd . ' 2>/dev/null');

            if ($output) {
                $lines = explode("\n", trim($output));
                foreach ($lines as $line) {
                    if (empty($line)) {
                        continue;
                    }
                    $parts = explode('|', $line);

                    if (count($parts) >= 4) {
                        $image = $parts[3];
                        $labels = $parts[5] ?? '';
                        $containerName = $parts[1];
                        $containerId = $parts[0];

                        // Match logic: itzg images (by name or label), or mcmm label
                        $isItzg = strpos($image, 'itzg/') !== false;
                        $isMcmm = strpos($labels, 'mcmm=1') !== false;

                        // Also check if labels contain itzg repository (handles image ID case)
                        if (!$isItzg && (strpos($labels, 'net.unraid.docker.repository=itzg/') !== false)) {
                            $isItzg = true;
                        }

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
                            } else {
                                $icon = getLabelValue($labels, 'net.unraid.docker.icon') ?: getLabelValue($labels, 'mcmm.icon') ?: '';

                                // If still no icon, try to backfill from Docker environment variables
                                if (empty($icon)) {
                                    $icon = backfillServerIcon($containerId, $containerName, $serversDir, $config);
                                }
                            }

                            // Players: best effort
                            $playersOnline = null;
                            $playersMax = $serverConfigs[$containerName]['maxPlayers'] ?? null;

                            // Inspect env
                            $inspectJson = shell_exec('/usr/bin/docker inspect ' . escapeshellarg($containerId) . ' 2>/dev/null');
                            $envMemoryMb = null;
                            $env = [];
                            if ($inspectJson) {
                                $inspect = json_decode($inspectJson, true);
                                if ($inspect && isset($inspect[0]['Config']['Env'])) {
                                    foreach ($inspect[0]['Config']['Env'] as $e) {
                                        $partsEnv = explode('=', $e, 2);
                                        if (count($partsEnv) === 2) {
                                            $env[$partsEnv[0]] = $partsEnv[1];
                                            if ($partsEnv[0] === 'MAX_PLAYERS') {
                                                $envMax = intval($partsEnv[1]);
                                                if ($envMax > 0) {
                                                    $playersMax = $playersMax ?? $envMax;
                                                }
                                            } elseif ($partsEnv[0] === 'MEMORY') {
                                                $parsed = parseMemoryToMB($partsEnv[1]);
                                                if ($parsed > 0) {
                                                    $envMemoryMb = $parsed;
                                                }
                                            }
                                        }
                                    }
                                }
                            }

                            // Get MC version and loader via unified function
                            $metadata = getServerMetadata($env, $config, $containerName, $config['curseforge_api_key'] ?? '');
                            $mcVer = $metadata['mcVersion'];
                            $loaderVer = $metadata['loader'];

                            if ($isRunning) {
                                $statusJson = shell_exec('mc-monitor status --json localhost:' . escapeshellarg($port) . ' 2>/dev/null');
                                if ($statusJson) {
                                    $statusData = json_decode($statusJson, true);
                                    if (isset($statusData['players'])) {
                                        $playersOnline = $statusData['players']['online'] ?? null;
                                        $playersMax = $statusData['players']['max'] ?? $playersMax;
                                    }
                                    // Try to get version from live ping if missing
                                    if (!$mcVer && isset($statusData['version']) && preg_match('/\d+\.\d+(\.\d+)?/', $statusData['version'], $mv)) {
                                        $mcVer = $mv[0];
                                    }
                                }
                            } else {
                                $playersOnline = 0;
                            }

                            // Final fallbacks
                            if ($playersOnline === null) {
                                $playersOnline = 0;
                            }
                            if ($playersMax === null) {
                                $playersMax = isset($config['default_max_players']) ? intval($config['default_max_players']) : 0;
                            }

                            // Configured memory from config file if present
                            $configMem = null;
                            if (isset($serverConfigs[$containerName]['memory'])) {
                                $configMem = parseMemoryToMB($serverConfigs[$containerName]['memory']);
                            }

                            // Read metrics from agent file if present; always try to fetch a RAM cap
                            $dataDir = getContainerDataDir($containerName, $containerId);
                            $agentPath = rtrim($dataDir, '/') . '/mcmm_metrics.json';
                            $metrics = readAgentMetrics($containerName, $containerId, $dataDir);
                            $agentExists = file_exists($agentPath);
                            $agentMtime = $agentExists ? @filemtime($agentPath) : null;
                            $agentAgeSec = $agentMtime ? (time() - $agentMtime) : null;
                            $agentTs = isset($metrics['ts']) ? intval($metrics['ts']) : null;

                            // Priority 1: Agent Metrics (Actual objects from inside JVM)
                            // Priority 2: On-demand JCMD (Actual objects)
                            $javaHeapUsedMb = 0;
                            if ($isRunning) {
                                if (isset($metrics['heap_used_mb']) && $metrics['heap_used_mb'] > 0) {
                                    $javaHeapUsedMb = floatval($metrics['heap_used_mb']);
                                } else {
                                    $javaHeapUsedMb = getJavaHeapUsedMb($containerId);
                                }
                            }

                            $memDebugLog = "[" . date('H:i:s') . "] RAM Detection for $containerName:\n";
                            $memDebugLog .= "  - $configMem (config_file), $envMemoryMb (env), " . ($config['default_memory'] ?? 'none') . " (default)\n";
                            $memDebugLog .= "  - javaHeapUsedMb: $javaHeapUsedMb\n";

                            // Determine configured RAM "cap" (what user allocated), independent of cgroup limit
                            $ramLimitMb = $configMem ?? 0;
                            if ($ramLimitMb <= 0 && $envMemoryMb) {
                                $ramLimitMb = $envMemoryMb;
                            }
                            if ($ramLimitMb <= 0 && isset($config['default_memory'])) {
                                $defaultMemMb = parseMemoryToMB($config['default_memory']);
                                if ($defaultMemMb > 0) {
                                    $ramLimitMb = $defaultMemMb;
                                }
                            }
                            $memDebugLog .= "  - Final ramLimitMb: $ramLimitMb\n";

                            // Container cgroup usage (host-side) is still useful for debugging and CPU.
                            $cg = getContainerCgroupStats($containerId);
                            $memDebugLog .= "  - Cgroup: Used=" . ($cg['mem_used_mb'] ?? 0) . ", Cap=" . ($cg['mem_cap_mb'] ?? 0) . "\n";

                            // Displayed RAM logic with fallbacks
                            $ramUsedMb = 0;
                            $ramSource = 'unavailable';

                            if ($javaHeapUsedMb > 0) {
                                $ramUsedMb = $javaHeapUsedMb;
                                $ramSource = 'agent_heap';
                            } elseif (($cg['mem_used_mb'] ?? 0) > 0) {
                                // Fallback to Docker stats if agent is missing, but clamp to cap
                                $ramUsedMb = $cg['mem_used_mb'];
                                $ramSource = 'docker_stats';
                            }

                            $memDebugLog .= "  - Used: $ramUsedMb (Source: $ramSource)\n";
                            dbg($memDebugLog);

                            $cpuUsage = 0;
                            if (isset($metrics['cpu_percent'])) {
                                $cpuUsage = floatval($metrics['cpu_percent']);
                            } elseif (isset($cg['cpu_percent'])) {
                                $cpuUsage = $cg['cpu_percent'];
                            }

                            // Safety clamp for display
                            if ($ramLimitMb > 0 && $ramUsedMb > $ramLimitMb) {
                                if ($ramSource === 'agent_heap') {
                                    $ramUsedMb = $ramLimitMb;
                                }
                                // If docker_stats, we allow it to exceed the limit slightly if configured that way,
                                // but for the percentage bar we will clamp.
                            }

                            $ramUsagePercent = ($ramLimitMb > 0 && $ramUsedMb >= 0)
                                ? (($ramUsedMb / $ramLimitMb) * 100)
                                : 0;

                            $ramDetails = [
                                'usedMb' => $ramUsedMb,
                                'rssMb' => $metrics['rss_mb'] ?? ($cg['mem_used_mb'] ?? 0),
                                'heapUsedMb' => $metrics['heap_used_mb'] ?? $javaHeapUsedMb,
                                'limitMb' => $ramLimitMb,
                                'cpuPercent' => $cpuUsage,
                                'source' => $ramSource,
                                'agent' => [
                                    'exists' => $agentExists,
                                    'mtime' => $agentMtime,
                                    'ageSec' => $agentAgeSec,
                                    'ts' => $agentTs
                                ],
                                'cgroup' => [
                                    'memUsedMb' => $cg['mem_used_mb'] ?? null,
                                    'memCapMb' => $cg['mem_cap_mb'] ?? null,
                                    'cpuPercent' => $cg['cpu_percent'] ?? null
                                ],
                                'configMemMb' => $configMem
                            ];

                            $servers[] = [
                                'id' => $containerId,
                                'name' => $containerName,
                                'status' => $isRunning ? 'Running' : 'Stopped',
                                'isRunning' => $isRunning,
                                'ports' => $port,
                                'image' => $image,
                                'icon' => $icon,
                                'ram' => round(min(max((float)$ramUsagePercent, 0), 100), 1),
                                'ramUsedMb' => round($ramUsedMb, 1),
                                'ramLimitMb' => $ramLimitMb,
                                'ramConfigMb' => $configMem,
                                'cpu' => round($cpuUsage, 1),
                                'ramDetails' => $ramDetails,
                                'debug' => [
                                    'dataDir' => $dataDir,
                                    'agentPath' => $agentPath,
                                    'agentExists' => $agentExists,
                                    'agentTs' => $agentTs,
                                    'cgroupStats' => $cg,
                                    'javaHeapUsedMb' => $javaHeapUsedMb,
                                    'envMemoryMb' => $envMemoryMb,
                                    'configMemMb' => $configMem
                                ],
                                'players' => [
                                    'online' => $playersOnline,
                                    'max' => $playersMax
                                ],
                                'mcVersion' => $mcVer,
                                'loader' => $loaderVer
                            ];
                        }
                    }
                }
            }

            jsonResponse(['success' => true, 'data' => $servers]);
            break;

        case 'server_players':
            $id = $_GET['id'] ?? '';
            $port = $_GET['port'] ?? '25565';
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Missing server ID'], 400);
            }

            // Inspect container env for fallbacks (MAX_PLAYERS, RCON_PASSWORD)
            $envMap = [];
            $inspectJson = shell_exec("docker inspect " . escapeshellarg($id));
            $inspectData = json_decode($inspectJson, true);
            if ($inspectData && isset($inspectData[0]['Config']['Env'])) {
                foreach ($inspectData[0]['Config']['Env'] as $e) {
                    $parts = explode('=', $e, 2);
                    if (count($parts) === 2) {
                        $envMap[$parts[0]] = $parts[1];
                    }
                }
            }

            $players = [];
            $online = 0;
            $max = isset($envMap['MAX_PLAYERS']) ? intval($envMap['MAX_PLAYERS']) : (isset($config['default_max_players']) ? intval($config['default_max_players']) : 0);
            $sanitizeName = function ($n) {
                $n = preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $n);
                return trim($n);
            };

            // Try query first
            $queryJson = shell_exec('mc-monitor query --json localhost:' . escapeshellarg($port) . ' 2>/dev/null');
            if ($queryJson) {
                $q = json_decode($queryJson, true);
                if ($q && isset($q['players'])) {
                    $online = $q['players']['online'] ?? $online;
                    $max = $q['players']['max'] ?? $max;
                    if (!empty($q['players']['list']) && is_array($q['players']['list'])) {
                        foreach ($q['players']['list'] as $p) {
                            $name = $sanitizeName(is_array($p) && isset($p['name']) ? $p['name'] : $p);
                            if ($name !== '') {
                                $players[] = ['name' => $name];
                            }
                        }
                    }
                }
            }

            // If still missing counts, fallback to status
            if (!$online && !$max) {
                $statusJson = shell_exec('mc-monitor status --json localhost:' . escapeshellarg($port) . ' 2>/dev/null');
                if ($statusJson) {
                    $s = json_decode($statusJson, true);
                    if ($s && isset($s['players'])) {
                        $online = $s['players']['online'] ?? $online;
                        $max = $s['players']['max'] ?? $max;
                    }
                }
            }

            // RCON fallback to list players and op status if we still don't have names
            $rconPass = $envMap['RCON_PASSWORD'] ?? '';
            if (empty($players) && $rconPass) {
                $cmd = "docker exec " . escapeshellarg($id) . " rcon-cli --password " . escapeshellarg($rconPass) . " list 2>&1";
                $out = [];
                $exit = 0;
                exec($cmd, $out, $exit);
                if ($exit === 0 && !empty($out)) {
                    $line = implode(' ', $out);
                    if (preg_match('/There are (\d+) of a max of (\d+) players online: (.*)/i', $line, $m)) {
                        $online = intval($m[1]);
                        $max = intval($m[2]);
                        $names = array_map('trim', explode(',', $m[3]));
                        foreach ($names as $n) {
                            $name = $sanitizeName($n);
                            if ($name !== '') {
                                $players[] = ['name' => $name];
                            }
                        }
                    } elseif (preg_match('/There are (\d+) of a max of (\d+) players online/i', $line, $m)) {
                        $online = intval($m[1]);
                        $max = intval($m[2]);
                    }
                }
            }

            // RCON op list to flag operators
            $ops = [];
            if ($rconPass) {
                $opCmd = "docker exec " . escapeshellarg($id) . " rcon-cli --password " . escapeshellarg($rconPass) . " \"op list\" 2>&1";
                $opOut = [];
                $opExit = 0;
                exec($opCmd, $opOut, $opExit);
                if ($opExit === 0 && !empty($opOut)) {
                    $line = implode(' ', $opOut);
                    if (preg_match('/Opped players:? (.*)$/i', $line, $m)) {
                        $names = array_map('trim', explode(',', $m[1]));
                        foreach ($names as $n) {
                            $name = $sanitizeName($n);
                            if ($name !== '') {
                                $ops[] = $name;
                            }
                        }
                    }
                }
            }

            // Attach isOp flag to players
            if (!empty($ops) && !empty($players)) {
                $opsLower = array_map('strtolower', $ops);
                $players = array_map(function ($p) use ($opsLower) {
                    $name = is_array($p) && isset($p['name']) ? $p['name'] : (is_string($p) ? $p : '');
                    $isOp = in_array(strtolower($name), $opsLower);
                    return ['name' => $name, 'isOp' => $isOp];
                }, $players);
            } elseif (!empty($players)) {
                // Normalize shape
                $players = array_map(function ($p) {
                    if (is_array($p) && isset($p['name'])) {
                        return $p;
                    }
                    return ['name' => is_string($p) ? $p : '', 'isOp' => false];
                }, $players);
            }

            jsonResponse(['success' => true, 'data' => ['online' => $online, 'max' => $max, 'players' => $players]]);
            break;

        case 'server_player_action':
            $id = $_GET['id'] ?? '';
            $player = $_GET['player'] ?? '';
            $action = $_GET['action'] ?? '';
            if (!$id || !$player || !$action) {
                jsonResponse(['success' => false, 'error' => 'Missing parameters'], 400);
            }

            // Discover container name for exec
            $inspectJson = shell_exec("docker inspect " . escapeshellarg($id));
            $inspect = json_decode($inspectJson, true);
            if (!$inspect || !isset($inspect[0])) {
                jsonResponse(['success' => false, 'error' => 'Container not found'], 404);
            }
            $envMap = [];
            if (!empty($inspect[0]['Config']['Env'])) {
                foreach ($inspect[0]['Config']['Env'] as $e) {
                    $parts = explode('=', $e, 2);
                    if (count($parts) === 2) {
                        $envMap[$parts[0]] = $parts[1];
                    }
                }
            }
            if (isset($envMap['MAX_PLAYERS'])) {
                $envMap['MAX_PLAYERS'] = intval($envMap['MAX_PLAYERS']);
            }
            $rconPass = $envMap['RCON_PASSWORD'] ?? '';
            if (!$rconPass) {
                jsonResponse(['success' => false, 'error' => 'RCON_PASSWORD not set; cannot run commands. Enable RCON in the server env.'], 400);
            }

            // Build command
            $cmdMap = [
                'kick' => "kick " . escapeshellarg($player),
                'ban' => "ban " . escapeshellarg($player),
                'op' => "op " . escapeshellarg($player),
                'deop' => "deop " . escapeshellarg($player),
            ];
            if (!isset($cmdMap[$action])) {
                jsonResponse(['success' => false, 'error' => 'Unsupported action'], 400);
            }

            $execCmd = "docker exec " . escapeshellarg($id) . " rcon-cli --password " . escapeshellarg($rconPass) . " " . $cmdMap[$action] . " 2>&1";
            $out = [];
            $exit = 0;
            exec($execCmd, $out, $exit);
            if ($exit !== 0) {
                jsonResponse(['success' => false, 'error' => 'Command failed', 'output' => $out], 500);
            }
            jsonResponse(['success' => true, 'message' => 'Command executed', 'output' => $out]);
            break;

        case 'search_modpacks':
        case 'modpacks':
            if (empty($config['curseforge_api_key'])) {
                jsonResponse(['success' => false, 'error' => 'CurseForge API key not configured'], 400);
            }

            $search = $_GET['search'] ?? $_GET['q'] ?? '';
            $modpacks = fetchCurseForgeModpacks($search, $config['curseforge_api_key']);
            if ($modpacks === null) {
                jsonResponse(['success' => false, 'error' => 'Failed to contact CurseForge'], 502);
            }

            jsonResponse(['success' => true, 'data' => $modpacks]);
            break;

        case 'detect_java_version':
            $url = $_POST['modpackUrl'] ?? $_GET['modpackUrl'] ?? '';
            if (!$url) {
                jsonResponse(['success' => false, 'error' => 'Missing modpack URL'], 400);
            }

            // Basic version detection from URL or metadata
            // For now, return a default suggest based on common modpacks if possible,
            // or just return success with a neutral value to be filled by frontend.
            $javaVersion = '17'; // Default for most modern packs
            if (strpos($url, '1.20') !== false || strpos($url, '1.21') !== false) {
                $javaVersion = '21';
            } elseif (strpos($url, '1.16') !== false || strpos($url, '1.12') !== false) {
                $javaVersion = '8';
            }

            jsonResponse([
                'success' => true,
                'javaVersion' => $javaVersion
            ]);
            break;

        case 'deploy':
            handleDeploy($config, $defaults);
            break;

        case 'server_details':
            $id = $_GET['id'] ?? '';
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Missing ID'], 400);
            }

            $json = shell_exec("docker inspect " . escapeshellarg($id));
            $data = json_decode($json, true);
            if (!$data || !isset($data[0])) {
                jsonResponse(['success' => false, 'error' => 'Failed to inspect container'], 500);
            }

            $c = $data[0];
            $containerName = ltrim($c['Name'], '/');
            $imageFull = $c['Config']['Image'] ?? '';
            $javaVerDetected = 'latest';
            if (strpos($imageFull, ':java') !== false) {
                $parts = explode(':java', $imageFull);
                if (count($parts) > 1) {
                    $javaVerDetected = $parts[1];
                }
            }

            // Parse Env
            $env = [];
            if (!empty($c['Config']['Env'])) {
                foreach ($c['Config']['Env'] as $e) {
                    $parts = explode('=', $e, 2);
                    if (count($parts) === 2) {
                        $env[$parts[0]] = $parts[1];
                    }
                }
            }
            $env['JAVA_VERSION_DETECTED'] = $javaVerDetected;
            $maxPlayers = isset($env['MAX_PLAYERS']) ? intval($env['MAX_PLAYERS']) : null;

            // Unified Metadata Detection
            $metadata = getServerMetadata($env, $config, $containerName, $config['curseforge_api_key'] ?? '');
            $mcVersion = $metadata['mcVersion'];
            $loader = $metadata['loader'];

            // Get Port
            $port = 25565;
            if (isset($c['HostConfig']['PortBindings']['25565/tcp'][0]['HostPort'])) {
                $port = $c['HostConfig']['PortBindings']['25565/tcp'][0]['HostPort'];
            }

            jsonResponse([
                'success' => true,
                'data' => [
                    'id' => $c['Id'],
                    'name' => $containerName,
                    'env' => $env,
                    'mcVersion' => $mcVersion,
                    'loader' => $loader,
                    'maxPlayers' => $maxPlayers,
                    'port' => $port,
                    'image' => $c['Config']['Image'],
                    'metadata_debug' => $metadata['_debug'] ?? []
                ]
            ]);
            break;

        case 'server_update':
            if (ob_get_length()) {
                ob_clean();
            }
            $input = getRequestData();
            $id = $input['id'] ?? '';
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Missing ID'], 400);
            }

            // Inspect existing
            $json = shell_exec("docker inspect " . escapeshellarg($id));
            $data = json_decode($json, true);
            if (!$data || !isset($data[0])) {
                jsonResponse(['success' => false, 'error' => 'Container not found'], 404);
            }
            $c = $data[0];

            $oldName = ltrim($c['Name'], '/');
            $image = $c['Config']['Image'];

            // Find /data mount
            $dataDir = '';
            if (!empty($c['Mounts'])) {
                foreach ($c['Mounts'] as $m) {
                    if ($m['Destination'] === '/data') {
                        $dataDir = $m['Source'];
                        break;
                    }
                }
            }
            if (!$dataDir) {
                jsonResponse(['success' => false, 'error' => 'Could not find /data mount'], 500);
            }

            // Merge Env
            $currentEnv = [];
            if (!empty($c['Config']['Env'])) {
                foreach ($c['Config']['Env'] as $e) {
                    $parts = explode('=', $e, 2);
                    if (count($parts) === 2) {
                        $currentEnv[$parts[0]] = $parts[1];
                    }
                }
            }

            // Detect Java version from image for ZGC guard
            $javaVerDetected = 'latest';
            if (strpos($image, ':java') !== false) {
                $parts = explode(':java', $image);
                if (count($parts) > 1) {
                    $javaVerDetected = $parts[1];
                }
            }

            // Apply updates
            if (!empty($input['env'])) {
                foreach ($input['env'] as $k => $v) {
                    if ($k === 'JVM_OPTS' && $javaVerDetected === '8') {
                        // Guard against ZGC on Java 8 during update
                        $v = str_replace('-XX:+UseZGC', '-XX:+UseG1GC', $v);
                    }
                    $currentEnv[$k] = $v;
                }
            }

            // Allow image to auto-select Java version
            unset($currentEnv['JAVA_VERSION']);

            // Port
            $newPort = $input['port'] ?? null;
            if (!$newPort) {
                if (isset($c['HostConfig']['PortBindings']['25565/tcp'][0]['HostPort'])) {
                    $newPort = $c['HostConfig']['PortBindings']['25565/tcp'][0]['HostPort'];
                } else {
                    $newPort = 25565;
                }
            }

            // Ensure query envs exist
            if (!isset($currentEnv['ENABLE_QUERY'])) {
                $currentEnv['ENABLE_QUERY'] = 'TRUE';
            }
            if (!isset($currentEnv['QUERY_PORT'])) {
                $currentEnv['QUERY_PORT'] = $newPort;
            }

            // Recreate
            shell_exec("docker stop " . escapeshellarg($id));
            shell_exec("docker rm " . escapeshellarg($id));

            $envArgs = buildEnvArgs($currentEnv);

            // Labels similar to deploy
            $labels = [
                'mcmm' => '1',
                'net.unraid.docker.managed' => 'dockerman',
                'net.unraid.docker.repository' => $image
            ];
            if (!empty($currentEnv['ICON'])) {
                $labels['net.unraid.docker.icon'] = $currentEnv['ICON'];
                $labels['mcmm.icon'] = $currentEnv['ICON'];
            }
            $labelArgs = '';
            foreach ($labels as $k => $v) {
                $labelArgs .= ' --label ' . escapeshellarg($k . '=' . $v);
            }

            // Ensure RCON is enabled for player listings if not already set
            if (empty($currentEnv['RCON_PASSWORD'])) {
                $currentEnv['RCON_PASSWORD'] = bin2hex(random_bytes(6));
                $currentEnv['RCON_PORT'] = $currentEnv['RCON_PORT'] ?? 25575;
                $currentEnv['ENABLE_RCON'] = 'TRUE';
            }
            if (!isset($currentEnv['ENABLE_QUERY'])) {
                $currentEnv['ENABLE_QUERY'] = 'TRUE';
            }
            if (!isset($currentEnv['QUERY_PORT'])) {
                $currentEnv['QUERY_PORT'] = $newPort;
            }
            $envArgs = buildEnvArgs($currentEnv);

            $cmd = sprintf(
                'docker run -d --restart unless-stopped --name %s -p %s -v %s %s %s %s',
                escapeshellarg($oldName),
                escapeshellarg($newPort . ':25565'),
                escapeshellarg($dataDir . ':/data'),
                $envArgs,
                $labelArgs,
                escapeshellarg($image)
            );

            $output = [];
            $exitCode = 0;
            exec($cmd . ' 2>&1', $output, $exitCode);

            if ($exitCode !== 0) {
                jsonResponse(['success' => false, 'error' => 'Failed to recreate server', 'output' => $output], 500);
            }

            jsonResponse(['success' => true, 'message' => 'Server updated successfully']);
            break;

        // Mod Manager Actions
        case 'mod_search':
            $source = strtolower($_GET['source'] ?? 'curseforge');
            $search = $_GET['search'] ?? '';
            $version = $_GET['version'] ?? '';
            $loader = $_GET['loader'] ?? '';
            $serverId = $_GET['server_id'] ?? '';
            $page = max(1, intval($_GET['page'] ?? 1));
            $pageSize = intval($_GET['page_size'] ?? 20);

            // If version/loader missing, try to auto-detect from server
            if (($version === '' || $loader === '') && $serverId) {
                $inspectJson = shell_exec("docker inspect " . escapeshellarg($serverId));
                $inspectData = json_decode($inspectJson, true);
                if ($inspectData && isset($inspectData[0])) {
                    $c = $inspectData[0];
                    $containerName = ltrim($c['Name'] ?? $serverId, '/');
                    $envMap = [];
                    foreach (($c['Config']['Env'] ?? []) as $e) {
                        $parts = explode('=', $e, 2);
                        if (count($parts) === 2) {
                            $envMap[$parts[0]] = $parts[1];
                        }
                    }
                    $meta = getServerMetadata($envMap, $config, $containerName, $config['curseforge_api_key'] ?? '');
                    if (!$version) {
                        $version = $meta['mcVersion'];
                    }
                    if (!$loader) {
                        $loader = $meta['loader'];
                    }
                }
            }

            if ($source === 'modrinth') {
                [$mods, $total] = fetchModrinthMods($search, $version, $loader, $page, $pageSize);
                $hasMore = ($page * $pageSize) < $total;
                jsonResponse(['success' => true, 'data' => $mods, 'page' => $page, 'pageSize' => $pageSize, 'total' => $total, 'hasMore' => $hasMore, 'version' => $version, 'loader' => $loader]);
            } else {
                if (empty($config['curseforge_api_key'])) {
                    jsonResponse(['success' => false, 'error' => 'CurseForge API key not configured'], 400);
                }
                [$mods, $total] = fetchCurseForgeMods($search, $version, $loader, $page, $pageSize, $config['curseforge_api_key']);
                $hasMore = ($page * $pageSize) < $total;
                jsonResponse(['success' => true, 'data' => $mods, 'page' => $page, 'pageSize' => $pageSize, 'total' => $total, 'hasMore' => $hasMore, 'version' => $version, 'loader' => $loader]);
            }
            break;

        case 'start_agents':
            if (ob_get_length()) {
                ob_clean();
            }
            // Start metrics agents for all running itzg/mcmm containers
            $dockerBin = file_exists('/usr/bin/docker') ? '/usr/bin/docker' : 'docker';
            $cmd = $dockerBin . ' ps --format "{{.ID}}|{{.Names}}|{{.Image}}|{{.Status}}"';
            $out = shell_exec($cmd . ' 2>/dev/null');
            $started = [];
            if ($out) {
                $lines = explode("\n", trim($out));
                foreach ($lines as $line) {
                    if (!$line) {
                        continue;
                    }
                    $parts = explode('|', $line);
                    if (count($parts) < 4) {
                        continue;
                    }
                    $cid = $parts[0];
                    $cname = $parts[1];
                    $image = $parts[2];
                    $status = $parts[3];
                    $isItzg = strpos($image, 'itzg/minecraft-server') !== false;
                    $isRunning = stripos($status, 'Up') !== false;
                    if ($isItzg && $isRunning) {
                        $dataDir = getContainerDataDir($cname, $cid);
                        // Inline ensureMetricsAgent logic
                        $scriptPath = rtrim($dataDir, '/') . '/mcmm_agent.sh';
                        $script = <<<'BASH'
#!/bin/sh
DATA_FILE="/data/mcmm_metrics.json"
INTERVAL=10
CPU_PREV=""
TOTAL_PREV=""

while true; do
  PID=$(pidof java | awk '{print $1}')
  if [ -n "$PID" ] && [ -d "/proc/$PID" ]; then
    # 1. Physicsal footprint (RSS)
    RSS_KB=$(awk '/VmRSS/ {print $2}' /proc/$PID/status 2>/dev/null)
    
    # 2. Actual Heap Usage (Objects)
    HEAP_USED_KB=0
    # Try jstat -gc first (reliable EU + OU + S0U + S1U)
    if command -v jstat >/dev/null 2>&1; then
      STATS=$(jstat -gc "$PID" 1 1 | tail -n 1)
      # Sum fields: 3(S0U) 4(S1U) 6(EU) 8(OU)
      HEAP_USED_KB=$(echo "$STATS" | awk '{print int($3 + $4 + $6 + $8)}')
    fi
    
    # Fallback to jcmd GC.heap_info if jstat fails
    if [ "$HEAP_USED_KB" -le 0 ] && command -v jcmd >/dev/null 2>&1; then
      HINFO=$(jcmd "$PID" GC.heap_info 2>/dev/null)
      # Extract used: "total 4194304K, used 1234567K"
      HEAP_USED_KB=$(echo "$HINFO" | grep -i "used" | head -n 1 | sed 's/.*used \([0-9]*\)K.*/\1/')
    fi

    # 3. CPU Percent
    CPU_LINE=$(head -n1 /proc/stat)
    TOTAL=0
    for v in $(echo "$CPU_LINE" | cut -d ' ' -f2-); do TOTAL=$((TOTAL + v)); done
    
    STAT=$(cat /proc/$PID/stat)
    PROC_UTIME=$(echo "$STAT" | awk '{print $14}')
    PROC_STIME=$(echo "$STAT" | awk '{print $15}')
    PROC_TOTAL=$((PROC_UTIME + PROC_STIME))
    
    CPU_PCT=0
    if [ -n "$CPU_PREV" ] && [ -n "$TOTAL_PREV" ]; then
      DPROC=$((PROC_TOTAL - CPU_PREV))
      DTOTAL=$((TOTAL - TOTAL_PREV))
      if [ "$DTOTAL" -gt 0 ]; then
        CPU_PCT=$((DPROC * 100 / DTOTAL))
      fi
    fi
    CPU_PREV=$PROC_TOTAL
    TOTAL_PREV=$TOTAL
    
    TS=$(date +%s)
    echo "{\"ts\":$TS,\"pid\":\"$PID\",\"heap_used_mb\":$((HEAP_USED_KB / 1024)),\"rss_mb\":$((RSS_KB / 1024)),\"cpu_percent\":$CPU_PCT}" > "$DATA_FILE"
    # Debug log inside container
    echo "$(date) Metrics updated: Heap=$((HEAP_USED_KB / 1024))MB, RSS=$((RSS_KB / 1024))MB, CPU=$CPU_PCT%" >> "/data/mcmm_agent.log"
  else
    echo "$(date) Error: No Java PID found" >> "/data/mcmm_agent.log"
  fi
  sleep $INTERVAL
done
BASH;
                        $logFile = rtrim($dataDir, '/') . '/mcmm_agent.log';
                        @mkdir($dataDir, 0775, true);
                        @file_put_contents($scriptPath, $script);
                        @chmod($scriptPath, 0755);

                        // Start it in background - ensure it is executable and run in its own subshell
                        $dockerBin = file_exists('/usr/bin/docker') ? '/usr/bin/docker' : 'docker';
                        $cmd = "$dockerBin exec -d " . escapeshellarg($cid) . " sh -c \"chmod +x /data/mcmm_agent.sh && /data/mcmm_agent.sh >/data/mcmm_agent.log 2>&1\"";
                        @shell_exec($cmd);
                        $started[] = [
                            'name' => $cname,
                            'cmd' => $cmd
                        ];
                    }
                }
            }
            jsonResponse(['success' => true, 'message' => 'Agents started (for running servers)', 'containers' => $started]);
            break;

        case 'agent_debug':
            $logFile = dirname(__FILE__) . '/mcmm_agent_debug.log';
            $mainFile = dirname(__FILE__) . '/mcmm.log';
            $dockerBin = file_exists('/usr/bin/docker') ? '/usr/bin/docker' : 'docker';
            $testDocker = @shell_exec($dockerBin . ' ps --format "{{.ID}}" 2>&1 | head -n 1');
            $whoami = trim((string) @shell_exec('whoami 2>&1'));
            $data = [
                'agent_read_logs' => file_exists($logFile) ? file_get_contents($logFile) : "No agent debug logs found at $logFile.",
                'plugin_debug_logs' => file_exists($mainFile) ? file_get_contents($mainFile) : "No main debug logs found at $mainFile.",
                'docker_test' => $testDocker ? "Output: $testDocker" : "FAIL (No output or error)",
                'php_user' => $whoami ?: 'Unknown',
                'env' => $_SERVER['PATH'] ?? 'No PATH'
            ];
            jsonResponse(['success' => true, 'logs' => $data]);
            break;

        case 'debug_backups':
            $backupDir = '/mnt/user/appdata/mcmm/backups';
            $files = glob($backupDir . '/*.zip');
            jsonResponse([
                'dir' => $backupDir,
                'exists' => is_dir($backupDir),
                'files' => $files,
                'files_count' => count($files ?: [])
            ]);
            break;

        case 'backups_list':
            $backupDir = '/mnt/user/appdata/mcmm/backups';
            if (!is_dir($backupDir)) {
                @mkdir($backupDir, 0755, true);
            }

            // Fetch live container info as a fallback for icons/metadata
            $liveMetadata = [];
            $dockerOutput = shell_exec('docker ps -a --format "{{.Names}}|{{.Labels}}" 2>/dev/null');
            if ($dockerOutput) {
                foreach (explode("\n", trim($dockerOutput)) as $line) {
                    if (!$line) {
                        continue;
                    }
                    $parts = explode('|', $line, 2);
                    if (count($parts) < 2) {
                        continue;
                    }
                    $cName = $parts[0];
                    $labels = $parts[1];
                    $icon = getLabelValue($labels, 'net.unraid.docker.icon') ?: getLabelValue($labels, 'mcmm.icon') ?: '';
                    if ($icon) {
                        $liveMetadata[$cName] = ['icon' => $icon];
                    }
                }
            }

            $files = glob($backupDir . '/*.zip');
            $backups = [];
            foreach ($files as $file) {
                $serverName = '';
                $stat = stat($file);
                $name = basename($file);
                if (preg_match('/backup_(.*)_(\d+)\.zip/', $name, $m)) {
                    $serverName = $m[1];
                    $timestamp = $m[2];

                    // Identify slug for fallback lookup
                    $slugCandidate = $serverName;
                    if (preg_match('/^(.*?)-[a-f0-9]{6}$/', $serverName, $sm)) {
                        $slugCandidate = $sm[1];
                    }

                    // Load server config for metadata
                    $cfg = [];
                    $serversDir = '/boot/config/plugins/mcmm/servers';
                    $serversSearch = glob($serversDir . '/*/config.json');

                    if ($serversSearch) {
                        // Pass 1: Direct container name match (Best)
                        foreach ($serversSearch as $cfgFile) {
                            $tempCfg = json_decode(@file_get_contents($cfgFile), true);
                            if ($tempCfg && isset($tempCfg['containerName']) && $tempCfg['containerName'] === $serverName) {
                                $cfg = $tempCfg;
                                break;
                            }
                        }

                        // Pass 2: Slug match (Fallback)
                        if (empty($cfg)) {
                            foreach ($serversSearch as $cfgFile) {
                                $tempCfg = json_decode(@file_get_contents($cfgFile), true);
                                if ($tempCfg && ((isset($tempCfg['slug']) && $tempCfg['slug'] === $slugCandidate) || (isset($tempCfg['name']) && safeContainerName($tempCfg['name']) === safeContainerName($serverName)))) {
                                    $cfg = $tempCfg;
                                    break;
                                }
                            }
                        }
                    }

                    // Pass 3: Live Docker match (Fallback if still no icon)
                    $icon = $cfg['icon'] ?? $cfg['logo'] ?? $cfg['icon_url'] ?? '';
                    if (!$icon && isset($liveMetadata[$serverName])) {
                        $icon = $liveMetadata[$serverName]['icon'];
                    }

                    $backups[] = [
                        'name' => $name,
                        'size' => $stat['size'],
                        'date' => (int)$timestamp ?: $stat['mtime'],
                        'server' => $serverName,
                        'icon' => $icon,
                        'author' => $cfg['author'] ?? 'Unknown',
                        'modpack' => $cfg['modpackName'] ?? $cfg['modpack'] ?? $cfg['name'] ?? $serverName,
                        'mc_version' => $cfg['mc_version'] ?? '',
                        'loader' => $cfg['loader'] ?? ''
                    ];
                }
            }
            // Sort by date desc
            usort($backups, function ($a, $b) {
                return $b['date'] - $a['date'];
            });
            jsonResponse(['success' => true, 'data' => $backups]);
            break;

        case 'backup_create':
            $id = $_GET['id'] ?? '';
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Missing ID'], 400);
            }

            // 1. Get container info
            $json = shell_exec("docker inspect " . escapeshellarg($id));
            $containers = json_decode($json, true);
            if (!$containers || !isset($containers[0])) {
                jsonResponse(['success' => false, 'error' => 'Container not found'], 404);
            }
            $c = $containers[0];
            $serverName = ltrim($c['Name'], '/');

            // 2. Locate data directory
            $dataDir = "";
            if (isset($c['Mounts'])) {
                foreach ($c['Mounts'] as $m) {
                    if ($m['Destination'] === '/data') {
                        $dataDir = $m['Source'];
                        break;
                    }
                }
            }
            if (!$dataDir || !is_dir($dataDir)) {
                jsonResponse(['success' => false, 'error' => 'Could not locate /data mount for this server'], 500);
            }

            // 3. Prepare backup
            $backupDir = '/mnt/user/appdata/mcmm/backups';
            if (!is_dir($backupDir)) {
                @mkdir($backupDir, 0755, true);
            }
            $ts = time();
            $backupName = "backup_{$serverName}_{$ts}.zip";
            $backupPath = $backupDir . '/' . $backupName;

            // 4. Create metadata
            $meta = [
                'serverName' => $serverName,
                'timestamp' => $ts,
                'containerConfig' => $c['Config'],
                'hostConfig' => $c['HostConfig']
            ];
            $metaPath = $dataDir . '/mcmm_backup_meta.json';
            @file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT));

            // 5. ZIP it
            $cmd = "cd " . escapeshellarg($dataDir) . " && zip -r " . escapeshellarg($backupPath) . " . -x \"*.log\" \"*.lck\" \"mcmm_metrics.json\"";
            $output = shell_exec($cmd . " 2>&1");
            @unlink($metaPath);

            if (file_exists($backupPath)) {
                jsonResponse(['success' => true, 'message' => 'Backup created', 'name' => $backupName]);
            } else {
                jsonResponse(['success' => false, 'error' => 'Failed to create zip', 'output' => $output], 500);
            }
            break;

        case 'backup_delete':
            $name = $_GET['name'] ?? '';
            if (!$name) {
                jsonResponse(['success' => false, 'error' => 'Missing filename'], 400);
            }
            // Safety check: ensure no path traversal
            $name = basename($name);
            $path = '/mnt/user/appdata/mcmm/backups/' . $name;
            if (file_exists($path)) {
                @unlink($path);
                jsonResponse(['success' => true, 'message' => 'Backup deleted']);
            } else {
                jsonResponse(['success' => false, 'error' => 'Backup not found'], 404);
            }
            break;

        case 'backup_reinstall':
            $name = $_GET['name'] ?? '';
            if (!$name) {
                jsonResponse(['success' => false, 'error' => 'Missing filename'], 400);
            }
            $name = basename($name);
            $backupPath = '/mnt/user/appdata/mcmm/backups/' . $name;
            if (!file_exists($backupPath)) {
                jsonResponse(['success' => false, 'error' => 'Backup file not found'], 404);
            }

            // 1. Peek into ZIP for metadata
            $tempDir = '/tmp/mcmm_restore_' . uniqid();
            @mkdir($tempDir, 0755, true);
            $cmd = "unzip -p " . escapeshellarg($backupPath) . " mcmm_backup_meta.json > " . escapeshellarg($tempDir . '/meta.json');
            shell_exec($cmd);
            $metaJson = @file_get_contents($tempDir . '/meta.json');
            $meta = json_decode($metaJson, true);
            if (!$meta || !isset($meta['serverName'])) {
                @shell_exec("rm -rf " . escapeshellarg($tempDir));
                jsonResponse(['success' => false, 'error' => 'Failed to read backup metadata'], 500);
            }

            $serverName = ltrim($meta['serverName'], '/');
            $dataDir = "/mnt/user/appdata/{$serverName}";

            // 2. Stop/Remove existing container if exists
            $dockerBin = file_exists('/usr/bin/docker') ? '/usr/bin/docker' : 'docker';
            @shell_exec("$dockerBin stop " . escapeshellarg($serverName));
            @shell_exec("$dockerBin rm " . escapeshellarg($serverName));

            // 3. Cycle the data directory
            if (is_dir($dataDir)) {
                $oldDir = $dataDir . '.reinstall_old_' . time();
                @rename($dataDir, $oldDir);
            }
            @mkdir($dataDir, 0775, true);

            // 4. Extract backup
            $cmd = "unzip " . escapeshellarg($backupPath) . " -d " . escapeshellarg($dataDir);
            $output = shell_exec($cmd . " 2>&1");
            @unlink($dataDir . '/mcmm_backup_meta.json');

            // 5. Re-create container (Build docker run command from meta)
            // We use the raw meta to reconstruct the env, ports, and volumes.
            $envArgs = "";
            if (isset($meta['containerConfig']['Env'])) {
                foreach ($meta['containerConfig']['Env'] as $e) {
                    $envArgs .= " -e " . escapeshellarg($e);
                }
            }

            $portArgs = "";
            if (isset($meta['hostConfig']['PortBindings'])) {
                foreach ($meta['hostConfig']['PortBindings'] as $contPort => $hostBindings) {
                    foreach ($hostBindings as $hb) {
                        $portArgs .= " -p " . escapeshellarg($hb['HostPort'] . ":" . $contPort);
                    }
                }
            }

            $labelsArgs = "";
            if (isset($meta['containerConfig']['Labels'])) {
                foreach ($meta['containerConfig']['Labels'] as $k => $v) {
                    $labelsArgs .= " -l " . escapeshellarg("$k=$v");
                }
            }

            $image = $meta['containerConfig']['Image'] ?? 'itzg/minecraft-server';
            $runCmd = "$dockerBin run -d --name " . escapeshellarg($serverName) .
                $envArgs . $portArgs . $labelsArgs .
                " -v " . escapeshellarg($dataDir . ":/data") .
                " --restart unless-stopped " . escapeshellarg($image);

            dbg("Reinstalling server: $serverName");
            dbg("Run CMD: $runCmd");

            $result = shell_exec($runCmd . " 2>&1");
            @shell_exec("rm -rf " . escapeshellarg($tempDir));

            if (strpos($result, 'Error') === false) {
                jsonResponse(['success' => true, 'message' => 'Server reinstalled and started', 'containerId' => trim($result)]);
            } else {
                jsonResponse(['success' => false, 'error' => 'Failed to recreate container', 'output' => $result], 500);
            }
            break;

        case 'mod_list':
            $id = $_GET['id'] ?? '';
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Missing server ID'], 400);
            }

            $modsDir = getContainerModsDir($id);
            if (!$modsDir || !is_dir($modsDir)) {
                jsonResponse(['success' => true, 'data' => []]); // Empty if dir doesn't exist
            }

            $metadata = [];
            $serversDir = '/boot/config/plugins/mcmm/servers';
            $metaFile = "$serversDir/$id/installed_mods.json";
            if (file_exists($metaFile)) {
                $metadata = json_decode(file_get_contents($metaFile), true) ?: [];
            }

            $mods = [];
            foreach (scandir($modsDir) as $file) {
                if (substr($file, -4) === '.jar') {
                    $modInfo = [];
                    // Try to find matching metadata
                    // 1. Exact filename match
                    foreach ($metadata as $mid => $info) {
                        if (($info['fileName'] ?? '') === $file) {
                            $modInfo = $info;
                            break;
                        }
                    }
                    // 2. Fuzzy match if not found (filename contains mod name or vice versa)
                    if (empty($modInfo)) {
                        foreach ($metadata as $mid => $info) {
                            $metaName = strtolower($info['name'] ?? '');
                            $metaFileRef = strtolower($info['fileName'] ?? '');
                            $diskFile = strtolower($file);

                            if (
                                ($metaFileRef && strpos($diskFile, $metaFileRef) !== false) ||
                                ($metaName && strpos($diskFile, str_replace(' ', '', $metaName)) !== false)
                            ) {
                                $modInfo = $info;
                                break;
                            }
                        }
                    }

                    $needsIdentification = empty($modInfo['author']) || empty($modInfo['logo']);

                    $mods[] = array_merge([
                        'id' => $modInfo['modId'] ?? md5($file),
                        'name' => $file,
                        'file' => $file,
                        'size' => formatBytes(filesize("$modsDir/$file")),
                        'needsIdentification' => $needsIdentification
                    ], $modInfo);
                }
            }
            jsonResponse(['success' => true, 'data' => $mods]);
            break;

        case 'mod_files':
            $source = strtolower($_GET['source'] ?? 'curseforge');
            $modId = $_GET['mod_id'] ?? '';
            $mcVersion = $_GET['mc_version'] ?? '';
            $loader = $_GET['loader'] ?? '';
            $serverId = $_GET['server_id'] ?? '';

            if (!$modId) {
                jsonResponse(['success' => false, 'error' => 'Missing mod ID'], 400);
            }

            // Auto-detect version/loader if missing
            if (($mcVersion === '' || $loader === '') && $serverId) {
                $inspectJson = shell_exec("docker inspect " . escapeshellarg($serverId));
                $inspectData = json_decode($inspectJson, true);
                if ($inspectData && isset($inspectData[0])) {
                    $c = $inspectData[0];
                    $containerName = ltrim($c['Name'] ?? $serverId, '/');
                    $envMap = [];
                    foreach (($c['Config']['Env'] ?? []) as $e) {
                        $parts = explode('=', $e, 2);
                        if (count($parts) === 2) {
                            $envMap[$parts[0]] = $parts[1];
                        }
                    }
                    $meta = getServerMetadata($envMap, $config, $containerName, $config['curseforge_api_key'] ?? '');
                    if (!$mcVersion) {
                        $mcVersion = $meta['mcVersion'];
                    }
                    if (!$loader) {
                        $loader = $meta['loader'];
                    }
                }
            }

            if ($source === 'modrinth') {
                $files = fetchModrinthFiles($modId, $mcVersion, $loader);
                jsonResponse(['success' => true, 'data' => $files]);
            } else {
                if (empty($config['curseforge_api_key'])) {
                    jsonResponse(['success' => false, 'error' => 'CurseForge API key not configured'], 400);
                }
                $files = fetchCurseForgeFiles((int) $modId, $mcVersion, $loader, $config['curseforge_api_key']);
                jsonResponse(['success' => true, 'data' => $files]);
            }
            break;


        case 'mod_install':
            $source = strtolower($_REQUEST['source'] ?? 'curseforge');
            $id = $_REQUEST['id'] ?? '';
            $modId = $_REQUEST['mod_id'] ?? '';
            $fileId = $_REQUEST['file_id'] ?? '';

            if (!$id || !$modId) {
                jsonResponse(['success' => false, 'error' => 'Missing parameters'], 400);
            }

            $modsDir = getContainerModsDir($id);
            if (!$modsDir) {
                jsonResponse(['success' => false, 'error' => 'Could not locate server data directory'], 500);
            }
            if (!is_dir($modsDir)) {
                @mkdir($modsDir, 0775, true);
            }

            // Get download URL
            if ($source === 'modrinth') {
                if (!$fileId) {
                    $mrFiles = fetchModrinthFiles($modId, '', '');
                    if (!empty($mrFiles)) {
                        $fileId = $mrFiles[0]['id'] ?? '';
                    }
                }
                $fileUrl = getModrinthDownloadUrl($fileId);
                if (!$fileUrl) {
                    jsonResponse(['success' => false, 'error' => 'Could not get Modrinth download URL'], 502);
                }
            } else {
                if (empty($config['curseforge_api_key'])) {
                    jsonResponse(['success' => false, 'error' => 'CurseForge API key not configured'], 400);
                }
                $fileUrl = getModDownloadUrl((int) $modId, $fileId, $config['curseforge_api_key']);
                if (!$fileUrl) {
                    jsonResponse(['success' => false, 'error' => 'Could not get download URL'], 502);
                }
            }

            // Download
            $fileName = basename(parse_url($fileUrl, PHP_URL_PATH));
            if (!$fileName) {
                $fileName = "mod-$modId" . ($fileId ? "-$fileId" : "") . ".jar";
            }

            if (file_put_contents("$modsDir/$fileName", fopen($fileUrl, 'r'))) {
                // Fix permissions
                @chown("$modsDir/$fileName", 99); // nobody
                @chgrp("$modsDir/$fileName", 100); // users
                @chmod("$modsDir/$fileName", 0664);

                // Clean up old version if it exists
                $serversDir = '/boot/config/plugins/mcmm/servers';
                $metaFile = "$serversDir/$id/installed_mods.json";
                if (file_exists($metaFile)) {
                    $metadata = json_decode(file_get_contents($metaFile), true) ?: [];
                    if (isset($metadata[$modId])) {
                        $oldFileName = $metadata[$modId]['fileName'] ?? '';
                        if ($oldFileName && $oldFileName !== $fileName) {
                            @unlink("$modsDir/$oldFileName");
                        }
                    }
                }

                // Save Metadata
                $extra = [
                    'author' => $_REQUEST['author'] ?? 'Unknown',
                    'summary' => $_REQUEST['summary'] ?? '',
                    'downloads' => $_REQUEST['downloads'] ?? '',
                    'mcVersion' => $_REQUEST['mc_version'] ?? ''
                ];
                saveModMetadata(
                    $id,
                    $modId,
                    $source,
                    $_REQUEST['mod_name'] ?? $fileName,
                    $fileName,
                    $fileId,
                    $_REQUEST['logo'] ?? '',
                    $extra
                );

                jsonResponse(['success' => true, 'message' => 'Mod installed']);
            } else {
                jsonResponse(['success' => false, 'error' => 'Download failed'], 500);
            }
            break;

        case 'identify_mod':
            $id = $_GET['id'] ?? '';
            $filename = $_GET['filename'] ?? '';
            if (!$id || !$filename) {
                jsonResponse(['success' => false, 'error' => 'Missing parameters'], 400);
            }

            // Clean filename to search query
            // Strip .jar, version strings, common prefixes/suffixes
            $query = preg_replace('/\.jar$/i', '', $filename);
            $query = preg_replace('/[-_][vV]?\d+\.?\d+.*$/', '', $query); // Strip -1.20.1 etc
            $query = preg_replace('/[-_]\d{4,}.*$/', '', $query); // Strip long numbers
            $query = trim(preg_replace('/([a-z])([A-Z])/', '$1 $2', $query)); // CamelCase to spaces

            dbg("Identifying mod from filename '$filename' -> Query: '$query'");

            // Try CurseForge first
            $cfResult = [];
            if (!empty($config['curseforge_api_key'])) {
                $cfData = fetchCurseForgeMods($query, '', '', 1, 1, $config['curseforge_api_key']);
                $cfResult = $cfData[0] ?? [];
            }

            // Try Modrinth
            $mrData = fetchModrinthMods($query, '', '', 1, 1);
            $mrResult = $mrData[0] ?? [];

            $found = null;
            $source = '';

            // Heuristic: prefer CurseForge if it's an exact or high-quality match
            if (!empty($cfResult)) {
                $found = $cfResult[0];
                $source = 'curseforge';
            } elseif (!empty($mrResult)) {
                $found = $mrResult[0];
                $source = 'modrinth';
            }

            if ($found) {
                dbg("Matched mod '$filename' to " . ($found['name'] ?? 'unknown') . " via $source");
                $extra = [
                    'author' => $found['author'] ?? 'Unknown',
                    'summary' => $found['summary'] ?? '',
                    'downloads' => $found['downloads'] ?? '',
                    'mcVersion' => $found['mcVersion'] ?? ''
                ];
                saveModMetadata(
                    $id,
                    $found['id'],
                    $source,
                    $found['name'],
                    $filename,
                    $found['latestFileId'] ?? null,
                    $found['icon'] ?? '',
                    $extra
                );
                jsonResponse(['success' => true, 'data' => $found]);
            } else {
                jsonResponse(['success' => false, 'error' => 'No match found']);
            }
            break;

        case 'mod_delete':
            $id = $_GET['id'] ?? '';
            $file = $_GET['file'] ?? '';
            if (!$id || !$file) {
                jsonResponse(['success' => false, 'error' => 'Missing parameters'], 400);
            }

            $modsDir = getContainerModsDir($id);
            $filePath = "$modsDir/" . basename($file); // prevent directory traversal

            if (file_exists($filePath) && unlink($filePath)) {
                // Also remove from metadata
                $serversDir = '/boot/config/plugins/mcmm/servers';
                $metaFile = "$serversDir/$id/installed_mods.json";
                if (file_exists($metaFile)) {
                    $metadata = json_decode(file_get_contents($metaFile), true) ?: [];
                    $found = false;
                    foreach ($metadata as $mid => $info) {
                        if (($info['fileName'] ?? '') === $file) {
                            unset($metadata[$mid]);
                            $found = true;
                        }
                    }
                    if ($found) {
                        file_put_contents($metaFile, json_encode($metadata, JSON_PRETTY_PRINT));
                    }
                }
                jsonResponse(['success' => true, 'message' => 'Mod removed']);
            } else {
                jsonResponse(['success' => false, 'error' => 'Failed to delete file'], 500);
            }
            break;

        default:
            jsonResponse(['success' => false, 'error' => 'Unknown action: ' . $action], 404);
    }
} catch (Throwable $e) {
    dbg("Fatal Error: " . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Server Error: ' . $e->getMessage()], 500);
}
