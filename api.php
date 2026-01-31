<?php

/**
 * MCMM API Handler
 * 
 * This file serves as the central entry point for all AJAX requests 
 * from the Minecraft Modpack Manager (MCMM) web interface.
 * 
 * It handles:
 * - Server lifecycle management (start, stop, delete)
 * - Modpack and mod installation logic
 * - Global and per-server settings persistence
 * - Console log streaming and player management
 * - Backup and restore operations
 * 
 * @version 43
 * @package MCMm
 */

// MCMM API Handler - Force Reload
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
$defaultConfigPath = __DIR__ . '/default.cfg';


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
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$action = $_GET['action'] ?? '';

// Verify CSRF for state-changing actions
$sensitiveActions = ['server_control', 'server_delete', 'mod_install', 'mod_delete', 'console_command', 'backup_create', 'backup_delete', 'backup_restore', 'save_settings'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' || in_array($action, $sensitiveActions)) {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        header('HTTP/1.1 403 Forbidden');
        die(json_encode(['success' => false, 'error' => 'Invalid CSRF token']));
    }
}



// Ensure nothing is printed before JSON
if (ob_get_length()) {
    ob_clean();
}

dbg("API Loaded. Action: " . ($action ? $action : 'none'));

// Ensure log dir exists (no longer needed for /tmp, but keeping structure safe)
// if (!is_dir('/var/log/plugins')) @mkdir('/var/log/plugins', 0755, true);

try {
    // Validate config loading
    // Validate config loading
    // Config is guaranteed to be an array from array_merge above
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
            // If strictly empty (missing/invalid), return error.
            if (empty($data) && empty($_POST)) {
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
                    $existing[$field] = boolInput($data[$field], isset($existing[$field]) ? boolInput($existing[$field]) : false);
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

            // ID Validation
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
                jsonResponse(['success' => false, 'error' => 'Invalid Server ID'], 400);
            }

            // Command Whitelist check
            $allowedCommands = ['say', 'list', 'whitelist', 'ban', 'kick', 'op', 'deop', 'stop', 'restart', 'save-all', 'weather', 'time', 'gamerule', 'pardon', 'ban-ip', 'pardon-ip'];
            $cmdClean = trim($cmd);
            if (strpos($cmdClean, '/') === 0) {
                $cmdClean = substr($cmdClean, 1);
            }
            $parts = explode(' ', $cmdClean);
            $baseCmd = strtolower($parts[0]);

            if (!in_array($baseCmd, $allowedCommands)) {
                // Log attempt?
                // error_log("MCMM: Blocked command '$baseCmd' from web console.");
                jsonResponse(['success' => false, 'error' => 'Command not allowed via web console: ' . htmlspecialchars($baseCmd)], 403);
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

            // 1. Resolve paths BEFORE deleting container (so inspect works)
            $dataDir = getContainerDataDirById($id);
            $serverId = $id; // Sometimes $id is a name, sometimes a long hash

            // Try to find the MCMM server ID if $id is container ID
            $mcmmServerDir = null;
            $serversBase = '/boot/config/plugins/mcmm/servers';
            if (is_dir($serversBase)) {
                $dirs = array_diff(scandir($serversBase), ['.', '..']);
                foreach ($dirs as $d) {
                    $cfgFile = "$serversBase/$d/config.json";
                    if (file_exists($cfgFile)) {
                        $cfg = json_decode(file_get_contents($cfgFile), true);
                        if ($cfg && (($cfg['id'] ?? '') === $id || ($cfg['containerName'] ?? '') === $id)) {
                            $mcmmServerDir = "$serversBase/$d";
                            break;
                        }
                    }
                }
            }

            // 2. Stop then remove container
            $dockerBin = file_exists('/usr/bin/docker') ? '/usr/bin/docker' : 'docker';
            $stopOut = shell_exec("$dockerBin stop " . escapeshellarg($id) . " 2>&1");
            $rmOut = shell_exec("$dockerBin rm " . escapeshellarg($id) . " 2>&1");

            // 3. Clean up AppData
            $dataDeleted = false;
            if ($dataDir && is_dir($dataDir) && strpos($dataDir, 'appdata') !== false) {
                // Safety check: ensure we are in appdata
                shell_exec("rm -rf " . escapeshellarg($dataDir));
                $dataDeleted = true;
            }

            // 4. Clean up MCMM Internal Config
            if ($mcmmServerDir && is_dir($mcmmServerDir)) {
                shell_exec("rm -rf " . escapeshellarg($mcmmServerDir));
            }

            jsonResponse([
                'success' => true,
                'message' => 'Server and data deleted',
                'data_deleted' => $dataDeleted,
                'output' => [$stopOut, $rmOut]
            ]);
            break;

        case 'check_updates':
            $id = $_GET['id'] ?? '';
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Missing server ID'], 400);
            }

            $updateCacheKey = "srv_updates_" . $id;
            $cachedUpdates = mcmm_cache_get($updateCacheKey);
            if ($cachedUpdates !== null) {
                jsonResponse(['success' => true, 'updates' => $cachedUpdates]);
            }

            $metaFile = "/boot/config/plugins/mcmm/servers/$id/installed_mods.json";
            if (!file_exists($metaFile)) {
                jsonResponse(['success' => true, 'updates' => []]);
            }

            $installed = json_decode(file_get_contents($metaFile), true) ?: [];
            if (empty($installed)) {
                jsonResponse(['success' => true, 'updates' => []]);
            }

            // Fetch server config and env
            $cfgFile = "/boot/config/plugins/mcmm/servers/$id/config.json";
            $srvCfg = file_exists($cfgFile) ? json_decode(file_get_contents($cfgFile), true) : [];
            $env = []; // In check_updates we primarily rely on stored config, but initialize to avoid errors

            // Group by platform
            $cfIds = [];
            $mrIds = [];
            foreach ($installed as $mid => $info) {
                $platform = $info['platform'] ?? $srvCfg['platform'] ?? '';
                if ($platform === 'modrinth') {
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
                $batchData = fetchCurseForgeModsBatch($cfIds, $config['curseforge_api_key']);
                if ($batchData) {
                    foreach ($batchData as $mod) {
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
                if ($projects) {
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

            mcmm_cache_set($updateCacheKey, $updates, 3600); // 1 hour cache for updates
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

            // Build map of local image tags to IDs to check for updates
            $localImages = [];
            // Cache 'docker images' for 30 seconds (rarely changes)
            $imgCache = '/tmp/mcmm_images.cache';
            $imgOutput = null;
            if (file_exists($imgCache) && (time() - filemtime($imgCache) < 30)) {
                $imgOutput = file_get_contents($imgCache);
            } else {
                $imgCmd = '/usr/bin/docker images --no-trunc --format "{{.Repository}}:{{.Tag}}|{{.ID}}"';
                $imgOutput = shell_exec($imgCmd . ' 2>/dev/null');
                if ($imgOutput)
                    @file_put_contents($imgCache, $imgOutput);
            }

            if ($imgOutput) {
                foreach (explode("\n", trim($imgOutput)) as $imgLine) {
                    $parts = explode('|', $imgLine);
                    if (count($parts) === 2) {
                        $localImages[$parts[0]] = $parts[1]; // "itzg/minecraft-server:latest" -> "sha256:..."
                    }
                }
            }

            // Cache 'docker ps' output for 2s (high frequency)
            $psCache = '/tmp/mcmm_ps.cache';
            if (file_exists($psCache) && (time() - filemtime($psCache) < 2)) {
                $output = file_get_contents($psCache);
            } else {
                $output = shell_exec($cmd . ' 2>/dev/null');
                if ($output)
                    @file_put_contents($psCache, $output);
            }

            // Batch gather docker stats for ALL containers (authoritative & fast)
            $allStats = [];

            // Cache 'docker stats' for 2 seconds (high frequency)
            $statsCache = '/tmp/mcmm_stats.cache';
            $statsOutput = null;
            if (file_exists($statsCache) && (time() - filemtime($statsCache) < 2)) {
                $statsOutput = file_get_contents($statsCache);
            } else {
                $statsCmd = '/usr/bin/docker stats --no-stream --format "{{.ID}}|{{.Name}}|{{.CPUPerc}}|{{.MemUsage}}"';
                $statsOutput = shell_exec($statsCmd . ' 2>/dev/null');
                if ($statsOutput)
                    @file_put_contents($statsCache, $statsOutput);
            }

            if ($statsOutput) {
                $statsLines = explode("\n", trim($statsOutput));
                foreach ($statsLines as $sLine) {
                    $sParts = explode('|', $sLine);
                    if (count($sParts) >= 3) {
                        $sId = $sParts[0];
                        $sName = $sParts[1];
                        $sCpuStr = str_replace('%', '', $sParts[2]);
                        $sMemParts = explode(' / ', $sParts[3]);
                        $sUsedMb = parseMemoryToMB($sMemParts[0]);
                        $sCapMb = isset($sMemParts[1]) ? parseMemoryToMB($sMemParts[1]) : 0;

                        $statsData = [
                            'cpu_percent' => floatval($sCpuStr) / getSystemCpuCount(),
                            'mem_used_mb' => $sUsedMb,
                            'mem_cap_mb' => $sCapMb
                        ];
                        $allStats[$sId] = $statsData;
                        $allStats[$sName] = $statsData;
                    }
                }
            }

            // Batch gather docker inspect for ALL containers (extremely fast compared to loop)
            $allInspect = [];
            $inspectIds = trim((string) shell_exec('/usr/bin/docker ps -a -q | tr "\n" " "'));
            if (!empty($inspectIds)) {
                // Cache 'docker inspect' for 2 seconds to prevent cpu spikes on concurrent requests
                $inspectCache = '/tmp/mcmm_inspect.cache';
                $inspectOutput = null;
                if (file_exists($inspectCache) && (time() - filemtime($inspectCache) < 2)) {
                    $inspectOutput = file_get_contents($inspectCache);
                } else {
                    $inspectOutput = shell_exec("/usr/bin/docker inspect $inspectIds 2>/dev/null");
                    if ($inspectOutput) {
                        @file_put_contents($inspectCache, $inspectOutput);
                    }
                }

                if ($inspectOutput) {
                    $inspectData = json_decode($inspectOutput, true);
                    if ($inspectData) {
                        foreach ($inspectData as $item) {
                            $shortId = substr($item['Id'], 7, 12); // standard short ID
                            $name = ltrim($item['Name'], '/');
                            $allInspect[$item['Id']] = $item;
                            $allInspect[$shortId] = $item;
                            $allInspect[$name] = $item;
                        }
                    }
                }
            }

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

                            // Use pre-batched inspect data (massive speedup)
                            $inspect = $allInspect[$containerId] ?? $allInspect[$containerName] ?? null;
                            $env = [];
                            $envMemoryMb = null;
                            if ($inspect && isset($inspect['Config']['Env'])) {
                                foreach ($inspect['Config']['Env'] as $e) {
                                    $partsEnv = explode('=', $e, 2);
                                    if (count($partsEnv) === 2) {
                                        $env[$partsEnv[0]] = $partsEnv[1];
                                        if ($partsEnv[0] === 'MEMORY') {
                                            $envMemoryMb = parseMemoryToMB($partsEnv[1]);
                                        }
                                    }
                                }
                            }

                            // Get metadata from batched env, image tag, or live ping
                            $metadata = getServerMetadata(
                                $env,
                                $config,
                                $containerName,
                                $config['curseforge_api_key'] ?? '',
                                $image,
                                (int) $port,
                                $containerId
                            );
                            $mcVer = $metadata['mcVersion'];
                            $loaderVer = $metadata['loader'];
                            $modpackVer = $metadata['modpackVersion'] ?? '';

                            // Players: Default to 0, will be updated asynchronously by JS to keep this list instant
                            $playersOnline = 0;
                            $playersMax = intval(
                                $env['MAX_PLAYERS'] ??
                                $serverConfigs[$containerName]['maxPlayers'] ??
                                $config['default_max_players'] ??
                                20
                            );

                            // Icon handling
                            $icon = $serverConfigs[$containerName]['logo'] ?? getLabelValue($labels, 'net.unraid.docker.icon') ?: getLabelValue($labels, 'mcmm.icon') ?: $env['ICON'] ?? '';

                            // Configured memory from config file or env
                            $configMem = isset($serverConfigs[$containerName]['memory']) ? parseMemoryToMB($serverConfigs[$containerName]['memory']) : $envMemoryMb;

                            // Read metrics from agent file if present
                            $dataDir = getContainerDataDir($containerName, $containerId);
                            $agentPath = rtrim($dataDir, '/') . '/mcmm_metrics.json';
                            $metrics = readAgentMetrics($containerName, $containerId, $dataDir);

                            // Self-Healing Telemetry: If running but metrics are missing/stale, restart agent
                            // Throttle to once every 5 minutes to avoid spamming docker exec
                            $lastAgentAttempt = $serverConfigs[$containerName]['_last_agent_start'] ?? 0;
                            if ($isRunning && !$metrics && (time() - $lastAgentAttempt > 300)) {
                                ensureMetricsAgent($containerName, $containerId, $dataDir);
                                $serverConfigs[$containerName]['_last_agent_start'] = time();
                                // Note: $serverConfigs is usually local-only here,
                                // but it serves as a per-request throttle if nothing else.
                                // Realistically, readAgentMetrics has a 5min window now anyway.
                            }

                            $agentExists = file_exists($agentPath);
                            $agentMtime = $agentExists ? @filemtime($agentPath) : null;
                            $agentAgeSec = $agentMtime ? (time() - $agentMtime) : null;
                            $agentTs = isset($metrics['ts']) ? intval($metrics['ts']) : null;

                            // RAM Used: Use agent if healthy, else batch stats (zero shell_exec in loop)
                            $javaHeapUsedMb = ($isRunning && isset($metrics['heap_used_mb'])) ? floatval($metrics['heap_used_mb']) : 0;

                            $ramLimitMb = $configMem ?? 0;
                            if ($ramLimitMb <= 0 && isset($config['default_memory'])) {
                                $ramLimitMb = parseMemoryToMB($config['default_memory']);
                            }

                            $cg = $allStats[$containerId] ?? $allStats[$containerName] ?? ['cpu_percent' => 0, 'mem_used_mb' => 0, 'mem_cap_mb' => 0];

                            $ramUsedMb = 0;
                            $ramSource = 'unavailable';

                            // Deep Telemetry RAM Priority:
                            // 1. Agent PSS (Proportional Set Size - Most Accurate)
                            // 2. Agent RSS (Resident Set Size - Accurate physical)
                            // 3. Host-Side Cgroup Working Set (Bypasses container, authoritative cache-subtraction)
                            // 4. Agent WS (Working Set - Container minus inactive cache)
                            // 5. Docker Stats (Total container usage - least accurate fallback)

                            // Optimization: Pass pre-fetched long ID to avoid internal shell_exec
                            $longId = isset($allInspect[$containerId]['Id']) ? $allInspect[$containerId]['Id'] : null;
                            $cgroupWsMb = getContainerCgroupRamMb($containerId, $longId);

                            if (isset($metrics['pss_mb']) && floatval($metrics['pss_mb']) > 0) {
                                $ramUsedMb = floatval($metrics['pss_mb']);
                                $ramSource = 'agent_pss';
                            } elseif (isset($metrics['rss_mb']) && floatval($metrics['rss_mb']) > 0) {
                                $ramUsedMb = floatval($metrics['rss_mb']);
                                $ramSource = 'agent_rss';
                            } elseif ($cgroupWsMb !== null && $cgroupWsMb > 0) {
                                $ramUsedMb = $cgroupWsMb;
                                $ramSource = 'host_cgroup_ws';
                            } elseif (isset($metrics['ws_mb']) && floatval($metrics['ws_mb']) > 0) {
                                $ramUsedMb = floatval($metrics['ws_mb']);
                                $ramSource = 'agent_ws';
                            } elseif ($cg['mem_used_mb'] > 0) {
                                $ramUsedMb = $cg['mem_used_mb'];
                                $ramSource = 'docker_stats';
                            }

                            $cpuUsage = 0;
                            // Priority 1: Official Docker stats (matches Dozzle/Unraid)
                            if (isset($allStats[$containerId])) {
                                $cpuUsage = $allStats[$containerId]['cpu_percent'];
                            } elseif (isset($allStats[$containerName])) {
                                $cpuUsage = $allStats[$containerName]['cpu_percent'];
                                // Fallback to agent if docker stats failed to return this container
                                $cpuUsage = ($metrics['cpu_milli'] / 10.0) / getSystemCpuCount();
                            } elseif (isset($metrics['cpu_percent'])) {
                                $cpuUsage = floatval($metrics['cpu_percent']) / getSystemCpuCount();
                            }

                            // Safety clamp for display
                            if ($ramLimitMb > 0 && $ramUsedMb > $ramLimitMb) {
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

                            $updateAvailable = false;
                            if (isset($inspect['Config']['Image'])) {
                                $tag = $inspect['Config']['Image'];
                                if (isset($localImages[$tag])) {
                                    $latestId = $localImages[$tag];
                                    $runningId = $inspect['Image'];
                                    if ($latestId !== $runningId) {
                                        $updateAvailable = true;
                                    }
                                }
                            }

                            $servers[] = [
                                'containerUpdate' => $updateAvailable,
                                'id' => $containerId,
                                'name' => $containerName,
                                'status' => $isRunning ? 'Running' : 'Stopped',
                                'isRunning' => $isRunning,
                                'ports' => $port,
                                'image' => $image,
                                'icon' => $icon,
                                'ram' => round(min(max((float) $ramUsagePercent, 0), 100), 1),
                                'ramUsedMb' => round($ramUsedMb, 1),
                                'ramLimitMb' => $ramLimitMb,
                                'ramConfigMb' => $configMem,
                                'cpu' => round($cpuUsage, 2),
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
                                'loader' => $loaderVer,
                                'modpackVersion' => $modpackVer
                            ];

                            // Persistent metadata cache for helpers.php (Initial Page Load)
                            $metaDir = $serversDir . '/' . md5($containerName);
                            if (!is_dir($metaDir)) {
                                @mkdir($metaDir, 0755, true);
                            }
                            $metaFile = $metaDir . '/metadata_v11.json';
                            $metadataCache = [
                                'mcVersion' => $mcVer ?: 'Unknown',
                                'loader' => $loaderVer ?: 'Vanilla',
                                'modpackVersion' => $modpackVer ?? '',
                                'cache_ver' => $metadata['cache_ver'] ?? 'v11',
                                'lastUpdated' => time()
                            ];
                            @file_put_contents($metaFile, json_encode($metadataCache));
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

            $inspectJson = shell_exec("docker inspect " . escapeshellarg($id));
            $inspectData = json_decode($inspectJson, true);
            $envMap = [];
            if ($inspectData && isset($inspectData[0]['Config']['Env'])) {
                foreach ($inspectData[0]['Config']['Env'] as $e) {
                    $ev = explode('=', $e, 2);
                    if (count($ev) === 2) {
                        $envMap[$ev[0]] = $ev[1];
                    }
                }
            }

            $rconPass = $envMap['RCON_PASSWORD'] ?? '';
            $rconPort = isset($envMap['RCON_PORT']) ? intval($envMap['RCON_PORT']) : 25575;
            $sanitizeName = function ($n) {
                return trim(preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $n));
            };

            $stats = getMinecraftLiveStats($id, intval($port), $envMap);
            $online = $stats['online'];
            $max = $stats['max'];
            $players = [];

            // Add player listing if sample available from stats (Modern Ping / mc-monitor)
            if (!empty($stats['sample'])) {
                foreach ($stats['sample'] as $p) {
                    if (!empty($p['name'])) {
                        $players[] = ['name' => $sanitizeName($p['name'])];
                    }
                }
            }

            // If still empty and RCON is available, try authoritative RCON list
            if (empty($players) && $rconPass && $online > 0) {
                $rconOut = shell_exec("docker exec " . escapeshellarg($id) . " rcon-cli --port $rconPort --password " . escapeshellarg($rconPass) . " list 2>/dev/null");
                $rconOut = trim($rconOut);

                // Pattern 1 & 2: Standard "online: Name1, Name2" or "players: Name1, Name2"
                if (preg_match('/(?:online|players): (.*)$/i', $rconOut, $m)) {
                    $names = explode(', ', $m[1]);
                    foreach ($names as $name) {
                        $clean = $sanitizeName($name);
                        if ($clean !== '') {
                            $players[] = ['name' => $clean];
                        }
                    }
                }
                // Pattern 3: If no colon but we have text and online > 0, try to treat the whole thing as names
                // This handles Forge/Fabric servers that might just return "Name1 Name2" or "Name1, Name2"
                if (empty($players) && !empty($rconOut)) {
                    // Remove "There are X/Y players online" prefix if it exists but regex missed it
                    $cleanOut = preg_replace('/^There are .* online: /i', '', $rconOut);
                    $cleanOut = preg_replace('/^Players online: /i', '', $cleanOut);

                    // Split by comma or space
                    $names = preg_split('/[,\s]+/', $cleanOut);
                    foreach ($names as $name) {
                        $clean = $sanitizeName($name);
                        // Filter out common words that aren't names if they leaked in
                        if ($clean !== '' && !in_array(strtolower($clean), ['there', 'are', 'players', 'online', 'max', 'out', 'of'])) {
                            $players[] = ['name' => $clean];
                        }
                    }

                    // If we somehow got more players than reported online, this heuristic failed, but better than nothing
                }
            }

            // Fallback: If RCON failed to get names but we know people are online, try mc-monitor JSON
            if (empty($players) && $online > 0) {
                $internalPort = isset($envMap['SERVER_PORT']) ? intval($envMap['SERVER_PORT']) : 25565;
                $cmd = "docker exec " . escapeshellarg($id) . " mc-monitor status --json 127.0.0.1:$internalPort 2>/dev/null";
                $statusRes = shell_exec($cmd);
                if ($statusRes) {
                    $jd = json_decode($statusRes, true);
                    if (!empty($jd['players']['sample'])) {
                        foreach ($jd['players']['sample'] as $p) {
                            if (!empty($p['name'])) {
                                $players[] = ['name' => $p['name']];
                            }
                        }
                    }
                }
            }

            // RCON op list to flag operators
            $ops = [];
            if ($rconPass) {
                $opCmd = "docker exec " . escapeshellarg($id) . " rcon-cli --port $rconPort --password " . escapeshellarg($rconPass) . " \"op list\" 2>&1";
                $opOut = [];
                $opExit = 0;
                exec($opCmd, $opOut, $opExit);
                $fullOpOut = implode(' ', $opOut);

                // Flexible regex for Spigot/Paper/Forge/Vanilla variations
                if ($opExit === 0 && preg_match('/(?:opped players|opped player|operators):? (.*)$/i', $fullOpOut, $m)) {
                    $names = array_map('trim', explode(',', $m[1]));
                    foreach ($names as $n) {
                        $name = $sanitizeName($n);
                        if ($name !== '') {
                            $ops[] = $name;
                        }
                    }
                }

                // Fallback: If RCON list failed or returned nothing, try reading ops.json directly from disk
                if (empty($ops)) {
                    $opsJsonCmd = "docker exec " . escapeshellarg($id) . " cat ops.json 2>/dev/null";
                    $opsJsonRaw = shell_exec($opsJsonCmd);
                    if ($opsJsonRaw) {
                        $opsData = json_decode($opsJsonRaw, true);
                        if (is_array($opsData)) {
                            foreach ($opsData as $entry) {
                                if (isset($entry['name'])) {
                                    $ops[] = $entry['name'];
                                }
                            }
                        }
                    }
                }
            }

            // Attach isOp flag to players
            if (!empty($ops) && !empty($players)) {
                $opsLower = array_map('strtolower', $ops);
                foreach ($players as &$p) {
                    $p['isOp'] = in_array(strtolower($p['name']), $opsLower);
                }
            } else {
                foreach ($players as &$p) {
                    if (!isset($p['isOp'])) {
                        $p['isOp'] = false;
                    }
                }
            }

            jsonResponse(['success' => true, 'data' => ['online' => $online, 'max' => $max, 'players' => $players]]);
            break;

        case 'server_banned_players':
            $id = $_GET['id'] ?? '';
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Missing ID'], 400);
            }
            $inspectJson = shell_exec("docker inspect " . escapeshellarg($id));
            $inspectData = json_decode($inspectJson, true);
            $envMap = [];
            if ($inspectData && isset($inspectData[0]['Config']['Env'])) {
                foreach ($inspectData[0]['Config']['Env'] as $e) {
                    $ev = explode('=', $e, 2);
                    if (count($ev) === 2) {
                        $envMap[$ev[0]] = $ev[1];
                    }
                }
            }
            $rconPass = $envMap['RCON_PASSWORD'] ?? '';
            $rconPort = isset($envMap['RCON_PORT']) ? intval($envMap['RCON_PORT']) : 25575;
            $banned = [];

            if ($rconPass) {
                // Method 1: RCON Parsing (More compatible with external RCON providers)
                $out = shell_exec("docker exec " . escapeshellarg($id) . " rcon-cli --port $rconPort --password " . escapeshellarg($rconPass) . " \"banlist players\" 2>&1");
                $out = trim($out);

                if ($out && stripos($out, 'no banned players') === false) {
                    // Minecraft list commands typically end with ": name1, name2, ..."
                    if (preg_match('/:\s*(.*)$/i', $out, $m)) {
                        $names = explode(', ', $m[1]);
                        foreach ($names as $n) {
                            $name = trim(preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $n));
                            // Ignore reasons if present (e.g. "Name (Reason)")
                            $name = trim(explode(' ', $name)[0]);
                            if ($name && !in_array(strtolower($name), ['no', 'banned'])) {
                                $banned[] = ['name' => $name];
                            }
                        }
                    }
                }

                // Method 2: Authoritative JSON Fallback (Reads file directly from container)
                if (empty($banned)) {
                    $jsonContent = shell_exec("docker exec " . escapeshellarg($id) . " cat banned-players.json 2>/dev/null");
                    if (!$jsonContent) {
                        $jsonContent = shell_exec("docker exec " . escapeshellarg($id) . " cat /data/banned-players.json 2>/dev/null");
                    }
                    if ($jsonContent) {
                        $jd = json_decode($jsonContent, true);
                        if (is_array($jd)) {
                            foreach ($jd as $entry) {
                                if (!empty($entry['name'])) {
                                    $banned[] = ['name' => $entry['name']];
                                }
                            }
                        }
                    }
                }
            }
            jsonResponse(['success' => true, 'data' => array_values(array_unique($banned, SORT_REGULAR))]);
            break;

        case 'server_player_action':
            $id = $_GET['id'] ?? '';
            $player = $_GET['player'] ?? '';
            $player_action = $_GET['player_action'] ?? '';
            if (!$id || !$player || !$player_action) {
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
                'unban' => "pardon " . escapeshellarg($player),
                'op' => "op " . escapeshellarg($player),
                'deop' => "deop " . escapeshellarg($player),
                'whisper' => "tell " . escapeshellarg($player) . " " . ($_GET['message'] ?? ''),
            ];
            if (!isset($cmdMap[$player_action])) {
                jsonResponse(['success' => false, 'error' => 'Unsupported action: ' . $player_action], 400);
            }

            $rconPort = isset($envMap['RCON_PORT']) ? intval($envMap['RCON_PORT']) : 25575;
            $execCmd = "docker exec " . escapeshellarg($id) . " rcon-cli --port $rconPort --password " . escapeshellarg($rconPass) . " " . $cmdMap[$player_action] . " 2>&1";
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
            $source = strtolower($_GET['source'] ?? 'curseforge');
            $search = $_GET['search'] ?? $_GET['q'] ?? '';
            $sort = $_GET['sort'] ?? 'popularity';
            $page = max(1, intval($_GET['page'] ?? 1));
            $pageSize = max(1, min(100, intval($_GET['page_size'] ?? 20)));

            if ($source === 'ftb') {
                $modpacks = fetchFTBModpacks($search, $page, $pageSize);
                if ($modpacks === null) {
                    jsonResponse(['success' => false, 'error' => 'Failed to contact FTB API'], 502);
                }
            } elseif ($source === 'modrinth') {
                $modpacks = fetchModrinthModpacks($search, $sort, $page, $pageSize);
                if ($modpacks === null) {
                    jsonResponse(['success' => false, 'error' => 'Failed to contact Modrinth'], 502);
                }
            } else {
                if (empty($config['curseforge_api_key'])) {
                    jsonResponse(['success' => false, 'error' => 'CurseForge API key not configured'], 400);
                }

                $modpacks = fetchCurseForgeModpacks($search, $config['curseforge_api_key'], $sort, $page, $pageSize);
                if ($modpacks === null) {
                    jsonResponse(['success' => false, 'error' => 'Failed to contact CurseForge'], 502);
                }
            }

            jsonResponse(['success' => true, 'data' => $modpacks, 'source' => $source, 'page' => $page]);
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

        case 'server_get':
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

            // Sync internal port variable with public port
            $currentEnv['SERVER_PORT'] = $newPort;
            $currentEnv['QUERY_PORT'] = $newPort;

            // Ensure query envs exist
            if (!isset($currentEnv['ENABLE_QUERY'])) {
                $currentEnv['ENABLE_QUERY'] = 'TRUE';
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
                $metaCacheKey = "srv_meta_det_" . $serverId;
                $cachedMeta = mcmm_cache_get($metaCacheKey);
                if ($cachedMeta && is_array($cachedMeta)) {
                    $version = $version ?: ($cachedMeta['version'] ?? '');
                    $loader = $loader ?: ($cachedMeta['loader'] ?? '');
                } else {
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
                        // Cache for 10 minutes
                        mcmm_cache_set($metaCacheKey, ['version' => $version, 'loader' => $loader], 600);
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
                        ensureMetricsAgent($cname, $cid, $dataDir);
                        $started[] = $cname;
                    }
                }
            }
            jsonResponse(['success' => true, 'started' => $started]);
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
            $backupDir = '/mnt/user/appdata/backups';
            $files = glob($backupDir . '/*.zip');
            jsonResponse([
                'dir' => $backupDir,
                'exists' => is_dir($backupDir),
                'files' => $files,
                'files_count' => count($files ?: [])
            ]);
            break;

        case 'backups_list':
            $backupDir = '/mnt/user/appdata/backups';
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

            $serversDir = '/boot/config/plugins/mcmm/servers';
            $allConfigsByContainer = [];
            $allConfigsBySlug = [];
            $allConfigsByName = [];
            if (is_dir($serversDir)) {
                $dirs = glob($serversDir . '/*', GLOB_ONLYDIR);
                foreach ($dirs as $dir) {
                    $configFile = $dir . '/config.json';
                    if (file_exists($configFile)) {
                        $c = json_decode(@file_get_contents($configFile), true);
                        if ($c) {
                            if (isset($c['containerName'])) {
                                $allConfigsByContainer[$c['containerName']] = $c;
                            }
                            if (isset($c['slug'])) {
                                $allConfigsBySlug[$c['slug']] = $c;
                            }
                            if (isset($c['name'])) {
                                $allConfigsByName[safeContainerName($c['name'])] = $c;
                            }
                        }
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

                    $slugCandidate = $serverName;
                    if (preg_match('/^(.*?)-[a-f0-9]{6}$/', $serverName, $sm)) {
                        $slugCandidate = $sm[1];
                    }

                    // Use batched configs
                    $cfg = $allConfigsByContainer[$serverName] ??
                        $allConfigsBySlug[$slugCandidate] ??
                        $allConfigsByName[safeContainerName($serverName)] ??
                        [];

                    $icon = $cfg['icon'] ?? $cfg['logo'] ?? $cfg['icon_url'] ?? '';
                    if (!$icon && isset($liveMetadata[$serverName])) {
                        $icon = $liveMetadata[$serverName]['icon'];
                    }

                    $backups[] = [
                        'name' => $name,
                        'size' => $stat['size'],
                        'date' => (int) $timestamp ?: $stat['mtime'],
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
            $backupDir = '/mnt/user/appdata/backups';
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
            $path = '/mnt/user/appdata/backups/' . $name;
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
            $backupPath = '/mnt/user/appdata/backups/' . $name;
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
            $mcVersion = $_GET['mc_version'] ?? '';
            $loader = $_GET['loader'] ?? '';
            $checkUpdates = isset($_GET['check_updates']) && $_GET['check_updates'] === 'true';

            // If we need to check updates, we'll need mcVersion and loader
            if ($checkUpdates && (!$mcVersion || !$loader)) {
                $inspectJson = shell_exec("docker inspect " . escapeshellarg($id));
                $inspectData = json_decode($inspectJson, true);
                if ($inspectData && isset($inspectData[0])) {
                    $c = $inspectData[0];
                    $containerName = ltrim($c['Name'] ?? $id, '/');
                    $envMap = [];
                    foreach (($c['Config']['Env'] ?? []) as $e) {
                        $parts = explode('=', $e, 2);
                        if (count($parts) === 2) {
                            $envMap[$parts[0]] = $parts[1];
                        }
                    }
                    $meta = getServerMetadata($envMap, $config, $containerName, $config['curseforge_api_key'] ?? '');
                    $mcVersion = $mcVersion ?: $meta['mcVersion'];
                    $loader = $loader ?: $meta['loader'];
                }
            }

            $mods = [];
            foreach (scandir($modsDir) as $file) {
                if (substr($file, -4) === '.jar') {
                    $modInfo = [];
                    // 1. Exact filename match
                    foreach ($metadata as $mid => $info) {
                        if (($info['fileName'] ?? '') === $file) {
                            $modInfo = $info;
                            break;
                        }
                    }
                    // 2. Fuzzy match
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

            // --- Update Checking Logic ---
            if ($checkUpdates && !empty($mods)) {
                $cfIds = [];
                $mrIds = [];
                foreach ($mods as $idx => $m) {
                    if (isset($m['modId']) && isset($m['platform'])) {
                        if ($m['platform'] === 'curseforge') {
                            $cfIds[] = $m['modId'];
                        } elseif ($m['platform'] === 'modrinth') {
                            $mrIds[] = $m['modId'];
                        }
                    }
                }

                $updates = [];
                // CurseForge Batch
                if (!empty($cfIds) && !empty($config['curseforge_api_key'])) {
                    $batchResult = fetchCurseForgeModsBatch($cfIds, $config['curseforge_api_key']);
                    foreach ($batchResult as $cfMod) {
                        $targetFile = null;
                        foreach ($cfMod['latestFiles'] as $f) {
                            $gv = $f['gameVersions'] ?? [];
                            $hasVer = in_array($mcVersion, $gv);
                            $hasLoader = false;
                            $loaderMap = ['forge' => 1, 'fabric' => 4, 'quilt' => 5, 'neoforge' => 6];
                            $li = $loaderMap[strtolower($loader)] ?? 0;
                            if (isset($f['modLoaderType']) && (int) $f['modLoaderType'] === $li) {
                                $hasLoader = true;
                            }
                            if ($hasVer && $hasLoader) {
                                $targetFile = $f;
                                break;
                            }
                        }
                        if ($targetFile) {
                            $updates['curseforge_' . $cfMod['id']] = [
                                'latestFileId' => $targetFile['id'],
                                'latestFileName' => $targetFile['fileName'],
                                'latestVersion' => $targetFile['displayName']
                            ];
                        }
                    }
                }

                // Modrinth updates (one by one for now as MR batch is project_id based but complex to filter)
                // We'll just do minimal for MR to avoid slowness

                foreach ($mods as &$m) {
                    $key = ($m['platform'] ?? '') . '_' . ($m['modId'] ?? '');
                    if (isset($updates[$key])) {
                        $up = $updates[$key];
                        $m['update_available'] = (string) $up['latestFileId'] !== (string) ($m['fileId'] ?? '');
                        $m['latest_version'] = $up['latestVersion'] ?? '';
                        $m['latest_file_id'] = $up['latestFileId'];
                    }
                }
            }

            jsonResponse(['success' => true, 'data' => $mods]);
            break;

        case 'mod_identify_batch':
            try {
                @set_time_limit(600);
                $id = $_GET['id'] ?? '';
                $files = json_decode(file_get_contents('php://input'), true) ?: [];
                // Fallback to GET for WAF bypass
                if (empty($files) && !empty($_GET['files'])) {
                    $decoded = urldecode($_GET['files']);
                    $files = json_decode($decoded, true);
                    if (!$files) {
                        $files = json_decode($_GET['files'], true);
                    }
                }

                if (!$id || empty($files)) {
                    jsonResponse(['success' => false, 'error' => 'Missing parameters'], 400);
                }

                $results = [];
                $metaDir = '/boot/config/plugins/mcmm/servers/' . $id;
                $metaFile = $metaDir . '/installed_mods.json';

                // Load existing metadata once
                $metadata = [];
                if (file_exists($metaFile)) {
                    $metadata = json_decode(file_get_contents($metaFile), true) ?: [];
                }

                // Pre-load manifest metadata
                $manifestMetadata = [];
                $dataDir = getContainerDataDirById($id);
                if ($dataDir) {
                    $manifestMetadata = loadModsFromManifest($dataDir);
                }

                $apiKey = $config['curseforge_api_key'] ?? '';
                $manifestMap = []; // filename -> projectID
                $unknownFiles = [];

                // Pass 1: Categorize files
                foreach ($files as $filename) {
                    // Check existing metadata first to verify validity but we usually only send unknown ones

                    $foundId = null;
                    foreach ($manifestMetadata as $mid => $info) {
                        if (($info['fileName'] ?? '') === $filename) {
                            $foundId = $mid;
                            break;
                        }
                    }

                    if (!$foundId) {
                        $diskBase = strtolower(pathinfo($filename, PATHINFO_FILENAME));
                        $diskBaseGeneric = preg_replace('/[-_][vV]?\d+\.?\d+.*$/', '', $diskBase);
                        foreach ($manifestMetadata as $mid => $info) {
                            $mFileName = !empty($info['fileName']) ? strtolower($info['fileName']) : '';
                            $mName = !empty($info['name']) ? strtolower(str_replace(' ', '', $info['name'])) : '';
                            if (
                                ($mFileName && strpos($diskBase, strtolower(pathinfo($mFileName, PATHINFO_FILENAME))) !== false) ||
                                ($mName && strpos($diskBaseGeneric, $mName) !== false)
                            ) {
                                $foundId = $mid;
                                break;
                            }
                        }
                    }

                    if ($foundId) {
                        $manifestMap[$filename] = (int) $foundId;
                    } else {
                        $unknownFiles[] = $filename;
                    }
                }

                // Pass 2: Batch fetch CourseForge details for manifest mods
                if (!empty($manifestMap) && !empty($apiKey)) {
                    try {
                        $projectIds = array_unique(array_values($manifestMap));
                        $cfMods = fetchCurseForgeModsBatch($projectIds, $apiKey);
                        $cfById = [];
                        foreach ($cfMods as $mod) {
                            $cfById[$mod['id']] = $mod;
                        }

                        foreach ($manifestMap as $filename => $pid) {
                            if (isset($cfById[$pid])) {
                                $m = $cfById[$pid];
                                $author = !empty($m['authors']) ? $m['authors'][0]['name'] : 'Unknown';
                                $details = [
                                    'id' => $m['id'],
                                    'name' => $m['name'],
                                    'author' => $author,
                                    'summary' => $m['summary'] ?? '',
                                    'icon' => $m['logo']['thumbnailUrl'] ?? $m['logo']['url'] ?? '',
                                    'latestFileId' => $m['mainFileId'] ?? null,
                                    'platform' => 'curseforge'
                                ];

                                $results[$filename] = $details;

                                // Save to metadata
                                $metadata[$pid] = array_merge([
                                    'modId' => $pid,
                                    'name' => $details['name'],
                                    'platform' => 'curseforge',
                                    'fileName' => $filename,
                                    'fileId' => $details['latestFileId'],
                                    'logo' => $details['icon'],
                                    'author' => $author,
                                    'summary' => $details['summary'],
                                    'mcVersion' => $manifestMetadata[$pid]['mcVersion'] ?? 'Various',
                                    'installedAt' => time()
                                ], $metadata[$pid] ?? []);
                            }
                        }
                    } catch (\Throwable $e) {
                        // Non-fatal, just log if possible
                    }
                }

                // Pass 3: Heuristic search for truly unknown mods
                foreach ($unknownFiles as $filename) {
                    try {
                        $query = preg_replace('/\.jar$/i', '', $filename);
                        // Be less aggressive with stripping - keep the name part intact
                        $simpleName = preg_replace('/[-_][vV]?\d.*/', '', $query); // Just the name part like "jei"
                        $query = preg_replace('/[-_][vV]?\d+\.?\d+.*$/', '', $query);
                        $query = trim(preg_replace('/([a-z])([A-Z])/', '$1 $2', $query));

                        // Prevent empty or single-char queries
                        if (strlen($query) < 2) {
                            continue;
                        }

                        $found = null;
                        $source = '';

                        if (!empty($apiKey)) {
                            [$cfData, $total] = fetchCurseForgeMods($query, '', '', 1, 1, $apiKey);
                            if (!empty($cfData)) {
                                $candidate = $cfData[0];
                                // Verification: Candidate name must strongly resemble query or filename
                                $cName = strtolower(str_replace(' ', '', $candidate['name'])); // ClothConfigAPI
                                $cSlug = $candidate['slug'] ?? '';
                                $qName = strtolower(str_replace(' ', '', $query)); // clothconfig
                                $fName = strtolower($simpleName);

                                // Clean up slug and query for comparison
                                $cleanSlug = str_replace('-', '', $cSlug);
                                $cleanQuery = str_replace(['-', '_', ' '], '', strtolower($query));
                                $cleanSimple = str_replace(['-', '_', ' '], '', strtolower($simpleName));

                                // Match conditions:
                                // 1. Substring match of clean names
                                // 2. Levenshtein distance is low
                                // 3. Slug match (exact or containing), vital for acronyms like JEI
                                if (
                                    strpos($cName, $fName) !== false || strpos($fName, $cName) !== false ||
                                    levenshtein($cName, $qName) < 4 ||
                                    ($cSlug && ($cleanSlug === $cleanSimple || $cleanSlug === $cleanQuery))
                                ) {
                                    $found = $candidate;
                                    $source = 'curseforge';
                                }
                            }
                        }

                        if (!$found) {
                            [$mrData, $total] = fetchModrinthMods($query, '', '', 1, 1);
                            if (!empty($mrData)) {
                                $found = $mrData[0];
                                $source = 'modrinth';
                            }
                        }

                        if ($found) {
                            $extra = [
                                'author' => $found['author'] ?? 'Unknown',
                                'summary' => $found['summary'] ?? '',
                                'downloads' => $found['downloads'] ?? '',
                                'mcVersion' => $found['mcVersion'] ?? ''
                            ];
                            $pid = $found['id'];

                            $results[$filename] = array_merge($found, ['platform' => $source]);

                            $metadata[$pid] = array_merge([
                                'modId' => $pid,
                                'name' => $found['name'],
                                'platform' => $source,
                                'fileName' => $filename,
                                'fileId' => $found['latestFileId'] ?? null,
                                'logo' => $found['icon'] ?? '',
                                'author' => $extra['author'],
                                'summary' => $extra['summary'],
                                'mcVersion' => $extra['mcVersion'],
                                'installedAt' => time()
                            ], $metadata[$pid] ?? []);
                        }
                    } catch (\Throwable $e) {
                        // heuristic error
                    }
                }

                // Save metadata
                if (!empty($results)) {
                    if (!is_dir($metaDir)) {
                        @mkdir($metaDir, 0755, true);
                    }
                    @file_put_contents($metaFile, json_encode($metadata, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRETTY_PRINT));
                }

                jsonResponse(['success' => true, 'data' => $results]);
            } catch (\Throwable $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
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

            if ($source === 'ftb') {
                $files = fetchFTBModpackFiles((int) $modId);
                jsonResponse(['success' => true, 'data' => $files]);
            } elseif ($source === 'modrinth') {
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

            // Cache Check
            $cacheKey = 'identify_mod_' . md5($filename);
            $cached = mcmm_cache_get($cacheKey);
            if ($cached) {
                // Determine if we need to re-save metadata locally for this specific server ID
                // (The cache remembers the *match*, but we still need to apply it to *this* server's metadata file)
                $found = $cached['data'];
                $source = $cached['source'];
                if ($found) {
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
                }
                jsonResponse(['success' => true, 'data' => $found]);
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
                // Extract author/summary if available
                $extra = [
                    'author' => $found['author'] ?? 'Unknown',
                    'summary' => $found['summary'] ?? '',
                    'downloads' => $found['downloads'] ?? '',
                    'mcVersion' => $found['mcVersion'] ?? ''
                ];

                // Save!
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

                // Cache for 24h
                mcmm_cache_set($cacheKey, ['data' => $found, 'source' => $source], 86400);

                jsonResponse(['success' => true, 'data' => $found]);
            } else {
                jsonResponse(['success' => false, 'error' => 'Mod not found'], 404);
            }
            break;

        case 'import_manifest':
            require_once __DIR__ . '/include/mod_manager.php';
            $id = $_REQUEST['id'] ?? '';
            $json = $_REQUEST['manifest_json'] ?? '';

            // Handle raw POST body if param is missing
            if (!$json) {
                $input = json_decode(file_get_contents('php://input'), true);
                if (is_array($input)) {
                    $id = $input['id'] ?? $id;
                    $json = $input['manifest_json'] ?? ($input['json'] ?? '');
                    if (is_array($json)) {
                        $json = json_encode($json);
                    }
                }
            }

            if (!$id || !$json) {
                jsonResponse(['success' => false, 'error' => 'Missing ID or manifest JSON'], 400);
            }

            $data = json_decode($json, true);
            if (!$data) {
                jsonResponse(['success' => false, 'error' => 'Invalid JSON'], 400);
            }

            // Extract mods
            $modsToFetch = [];
            $manifestMap = []; // mid => fileId

            // Format 1: CurseForge manifest.json
            if (isset($data['files']) && is_array($data['files'])) {
                foreach ($data['files'] as $f) {
                    if (isset($f['projectID'])) {
                        $pid = (int) $f['projectID'];
                        $fid = (int) ($f['fileID'] ?? 0);
                        $modsToFetch[] = $pid;
                        $manifestMap[$pid] = $fid;
                    }
                }
            }
            // Format 2: FTB / Instance (minecraftinstance.json)
            elseif (isset($data['installedAddons']) && is_array($data['installedAddons'])) {
                foreach ($data['installedAddons'] as $addon) {
                    if (isset($addon['addonID'])) {
                        $pid = (int) $addon['addonID'];
                        $fid = (int) ($addon['installedFile']['id'] ?? 0);
                        $modsToFetch[] = $pid;
                        $manifestMap[$pid] = $fid;
                    }
                }
            }

            if (empty($modsToFetch)) {
                jsonResponse(['success' => false, 'error' => 'No mods found in manifest'], 400);
            }

            if (empty($config['curseforge_api_key'])) {
                jsonResponse(['success' => false, 'error' => 'CurseForge API Key required'], 400);
            }

            // Batch Fetch details
            $cfMods = fetchCurseForgeModsBatch($modsToFetch, $config['curseforge_api_key']);

            // Update Metadata
            $serversDir = '/boot/config/plugins/mcmm/servers';
            $metaFile = "$serversDir/$id/installed_mods.json";

            $metadata = [];
            if (file_exists($metaFile)) {
                $metadata = json_decode(file_get_contents($metaFile), true) ?: [];
            }

            $doDownload = isset($_REQUEST['download']) && ($_REQUEST['download'] === 'true' || $_REQUEST['download'] === '1');
            $modsDir = $doDownload ? getContainerModsDir($id) : null;
            if ($doDownload && !$modsDir) {
                $doDownload = false;
            }
            if ($doDownload && !is_dir($modsDir)) {
                @mkdir($modsDir, 0775, true);
            }

            $count = 0;
            $downloaded = 0;

            foreach ($cfMods as $mod) {
                $pid = $mod['id'];
                $fid = $manifestMap[$pid] ?? null;

                $author = !empty($mod['authors']) ? $mod['authors'][0]['name'] : 'Unknown';
                $logo = $mod['logo']['thumbnailUrl'] ?? $mod['logo']['url'] ?? '';

                // Try to resolve filename from fileID
                $fileName = $mod['slug'] . '.jar';
                if ($fid && !empty($mod['latestFiles'])) {
                    foreach ($mod['latestFiles'] as $lf) {
                        if ($lf['id'] == $fid) {
                            $fileName = $lf['fileName'];
                            break;
                        }
                    }
                }

                // Download if requested
                if ($doDownload && $fid) {
                    $targetPath = "$modsDir/$fileName";
                    if (!file_exists($targetPath)) {
                        $dUrl = getModDownloadUrl((int) $pid, (string) $fid, $config['curseforge_api_key']);
                        if ($dUrl) {
                            if (downloadMod($dUrl, $targetPath)) {
                                @chown($targetPath, 99);
                                @chgrp($targetPath, 100);
                                @chmod($targetPath, 0664);
                                $downloaded++;
                            } else {
                                // Log failure but continue
                                dbg("Failed to download mod: $fileName from $dUrl");
                            }
                        }
                    }
                }

                $metadata[$pid] = array_merge([
                    'modId' => $pid,
                    'name' => $mod['name'],
                    'platform' => 'curseforge',
                    'fileName' => $fileName,
                    'fileId' => $fid,
                    'logo' => $logo,
                    'author' => $author,
                    'summary' => $mod['summary'] ?? '',
                    'installedAt' => time()
                ], $metadata[$pid] ?? []);
                $count++;
            }

            // Make dir if needed
            if (!is_dir(dirname($metaFile))) {
                @mkdir(dirname($metaFile), 0755, true);
            }

            // Save updated metadata
            file_put_contents($metaFile, json_encode($metadata, JSON_PRETTY_PRINT));

            jsonResponse(['success' => true, 'count' => $count, 'message' => "Imported metadata for $count mods"]);
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

        case 'mods_scan':
            // Scan a server's mods folder and cache the results
            require_once __DIR__ . '/include/mod_manager.php';

            $id = $_GET['id'] ?? '';
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Missing server ID'], 400);
            }

            $dataDir = getContainerDataDirById($id);
            if (!$dataDir) {
                jsonResponse(['success' => false, 'error' => 'Could not locate server data directory'], 404);
            }

            $mods = scanServerMods($dataDir);

            // Cache the results
            $serversDir = '/boot/config/plugins/mcmm/servers';
            $serverHash = md5($id);
            $cacheDir = "$serversDir/$serverHash";
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0755, true);
            }

            $cacheFile = "$cacheDir/mods_cache.json";
            file_put_contents($cacheFile, json_encode([
                'timestamp' => time(),
                'serverId' => $id,
                'mods' => $mods
            ], JSON_PRETTY_PRINT));

            jsonResponse([
                'success' => true,
                'data' => $mods,
                'count' => count($mods)
            ]);
            break;

        case 'mods_list':
            // List mods for a server (from cache or fresh scan)
            require_once __DIR__ . '/include/mod_manager.php';

            $id = $_GET['id'] ?? '';
            $forceRefresh = isset($_GET['refresh']);

            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Missing server ID'], 400);
            }

            $serversDir = '/boot/config/plugins/mcmm/servers';
            $serverHash = md5($id);
            $cacheFile = "$serversDir/$serverHash/mods_cache.json";

            $mods = [];
            $cached = false;

            // Try to use cache if not forcing refresh
            if (!$forceRefresh && file_exists($cacheFile)) {
                $cacheData = json_decode(file_get_contents($cacheFile), true);
                if ($cacheData && isset($cacheData['mods'])) {
                    // Cache is valid for 1 hour
                    if ((time() - ($cacheData['timestamp'] ?? 0)) < 3600) {
                        $mods = $cacheData['mods'];
                        $cached = true;
                    }
                }
            }

            // Fresh scan if no valid cache
            if (!$cached) {
                $dataDir = getContainerDataDirById($id);
                if (!$dataDir) {
                    jsonResponse(['success' => false, 'error' => 'Could not locate server data directory'], 404);
                }

                $mods = scanServerMods($dataDir);

                // Hydrate with Global Dictionary
                $globalFile = '/mnt/user/appdata/mcmm/mod_ids.json';
                $globalData = [];
                // Ensure directory exists
                if (!is_dir(dirname($globalFile))) {
                    @mkdir(dirname($globalFile), 0777, true);
                }

                if (file_exists($globalFile)) {
                    $globalData = json_decode(file_get_contents($globalFile), true) ?: [];
                }

                // Collect missing IDs for batch fetch
                $missingIds = [];
                foreach ($mods as &$mod) {
                    // If modId known but no pretty name/icon in scan, check global
                    if (!empty($mod['modId']) && isset($globalData[$mod['modId']])) {
                        $g = $globalData[$mod['modId']];
                        $mod['name'] = $g['name'] ?? $mod['name'];
                        $mod['icon'] = $g['icon'] ?? '';
                        $mod['description'] = $g['description'] ?? '';
                        $mod['source'] = $g['source'] ?? 'curseforge';
                    } elseif (!empty($mod['modId']) && is_numeric($mod['modId'])) {
                        $missingIds[] = (int) $mod['modId'];
                    }
                }
                unset($mod); // break reference

                // Batch fetch from API if we have missing IDs and an API key
                if (!empty($missingIds) && !empty($config['curseforge_api_key'])) {
                    $missingIds = array_unique($missingIds);
                    $fetched = fetchCurseForgeModsBatch($missingIds, $config['curseforge_api_key']);

                    // Update mods array and global dictionary
                    foreach ($fetched as $f) {
                        $fid = $f['id'];
                        // Add to global
                        $globalData[$fid] = [
                            'name' => $f['name'],
                            'source' => 'curseforge',
                            'icon' => $f['logo']['thumbnailUrl'] ?? $f['logo']['url'] ?? '',
                            'description' => $f['summary'] ?? ''
                        ];

                        // Update current list
                        foreach ($mods as &$m) {
                            if (isset($m['modId']) && (int) $m['modId'] === $fid) {
                                $m['name'] = $f['name'];
                                $m['icon'] = $f['logo']['thumbnailUrl'] ?? $f['logo']['url'] ?? '';
                                $m['description'] = $f['summary'] ?? '';
                                $m['source'] = 'curseforge';
                            }
                        }
                    }
                    // Save updated global dictionary
                    file_put_contents($globalFile, json_encode($globalData, JSON_PRETTY_PRINT));
                }

                // Update cache
                $cacheDir = dirname($cacheFile);
                if (!is_dir($cacheDir)) {
                    @mkdir($cacheDir, 0755, true);
                }
                file_put_contents($cacheFile, json_encode([
                    'timestamp' => time(),
                    'serverId' => $id,
                    'mods' => $mods
                ], JSON_PRETTY_PRINT));
            }

            jsonResponse([
                'success' => true,
                'data' => $mods,
                'count' => count($mods),
                'cached' => $cached
            ]);
            break;

        case 'mods_check_updates':
            // Check for updates to installed mods
            require_once __DIR__ . '/include/mod_manager.php';

            $id = $_GET['id'] ?? '';
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Missing server ID'], 400);
            }

            $apiKey = $config['curseforge_api_key'] ?? '';
            if (!$apiKey) {
                jsonResponse(['success' => false, 'error' => 'CurseForge API key not configured'], 400);
            }

            // Get mods list
            $dataDir = getContainerDataDirById($id);
            if (!$dataDir) {
                jsonResponse(['success' => false, 'error' => 'Could not locate server data directory'], 404);
            }

            $mods = scanServerMods($dataDir);

            // Get server MC version for filtering
            $mcVersion = '';
            $serverHash = md5($id);
            $metaFile = "/boot/config/plugins/mcmm/servers/$serverHash/metadata_v11.json";
            if (file_exists($metaFile)) {
                $meta = json_decode(file_get_contents($metaFile), true);
                $mcVersion = $meta['mcVersion'] ?? '';
            }

            // Check for updates
            $modsWithUpdates = checkModUpdates($mods, $apiKey, $mcVersion);

            // Cache the results
            $cacheFile = "/boot/config/plugins/mcmm/servers/$serverHash/mods_updates.json";
            file_put_contents($cacheFile, json_encode([
                'timestamp' => time(),
                'mods' => $modsWithUpdates
            ], JSON_PRETTY_PRINT));

            $updatesAvailable = array_filter($modsWithUpdates, function ($m) {
                return $m['updateAvailable'] ?? false;
            });

            jsonResponse([
                'success' => true,
                'data' => $modsWithUpdates,
                'updatesAvailable' => count($updatesAvailable),
                'totalMods' => count($modsWithUpdates)
            ]);
            break;

        case 'mod_update':
            // Update a specific mod to latest version
            require_once __DIR__ . '/include/mod_manager.php';

            $id = $_GET['id'] ?? '';
            $modFile = $_GET['file'] ?? '';

            if (!$id || !$modFile) {
                jsonResponse(['success' => false, 'error' => 'Missing parameters'], 400);
            }

            $apiKey = $config['curseforge_api_key'] ?? '';
            if (!$apiKey) {
                jsonResponse(['success' => false, 'error' => 'CurseForge API key not configured'], 400);
            }

            $dataDir = getContainerDataDirById($id);
            if (!$dataDir) {
                jsonResponse(['success' => false, 'error' => 'Could not locate server data directory'], 404);
            }

            $modsDir = rtrim($dataDir, '/') . '/mods';
            $oldModPath = $modsDir . '/' . basename($modFile);

            if (!file_exists($oldModPath)) {
                jsonResponse(['success' => false, 'error' => 'Mod file not found'], 404);
            }

            // Get mod info
            $modInfo = extractModInfo($oldModPath);

            if (!isset($modInfo['curseforgeId']) || !isset($modInfo['latestFileId'])) {
                jsonResponse(['success' => false, 'error' => 'Update information not available'], 400);
            }

            // Download new version
            $downloadUrl = "https://api.curseforge.com/v1/mods/{$modInfo['curseforgeId']}/files/{$modInfo['latestFileId']}/download";
            $tempPath = $modsDir . '/' . uniqid('temp_') . '.jar';

            $success = downloadMod($downloadUrl, $tempPath, $modInfo['latestHash'] ?? '');

            if (!$success) {
                jsonResponse(['success' => false, 'error' => 'Failed to download update'], 500);
            }

            // Backup old mod
            $backupPath = $oldModPath . '.backup';
            @rename($oldModPath, $backupPath);

            // Move new mod into place
            $newModName = $modInfo['latestFileName'] ?? basename($tempPath);
            $newModPath = $modsDir . '/' . $newModName;
            rename($tempPath, $newModPath);

            // Clear mods cache
            $serverHash = md5($id);
            @unlink("/boot/config/plugins/mcmm/servers/$serverHash/mods_cache.json");
            @unlink("/boot/config/plugins/mcmm/servers/$serverHash/mods_updates.json");

            jsonResponse([
                'success' => true,
                'message' => 'Mod updated successfully',
                'oldFile' => basename($modFile),
                'newFile' => $newModName
            ]);
            break;

        default:
            jsonResponse(['success' => false, 'error' => 'Unknown action: ' . $action], 404);
    }
} catch (Throwable $e) {
    dbg("Fatal Error: " . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Server Error: ' . $e->getMessage()], 500);
}
