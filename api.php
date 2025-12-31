<?php

/**
 * Resolve host data directory for a container by inspecting the /data mount.
 * Falls back to /mnt/user/appdata/<name> if not found.
 */
function getContainerDataDir(string $containerName, string $containerId = ''): string
{
    $fallbackA = "/mnt/user/appdata/mcmm/servers/$containerName";
    $fallbackB = "/mnt/user/appdata/$containerName";
    $dockerBin = file_exists('/usr/bin/docker') ? '/usr/bin/docker' : 'docker';
    $target = $containerId ?: $containerName;
    $inspectJson = @shell_exec($dockerBin . ' inspect ' . escapeshellarg($target));
    if ($inspectJson) {
        $inspect = json_decode($inspectJson, true);
        if (is_array($inspect) && isset($inspect[0]['Mounts']) && is_array($inspect[0]['Mounts'])) {
            foreach ($inspect[0]['Mounts'] as $mount) {
                if (!empty($mount['Destination']) && !empty($mount['Source'])) {
                    $dest = $mount['Destination'];
                    if ($dest === '/data') {
                        return rtrim($mount['Source'], '/');
                    }
                }
            }
        }
    }
    // Prefer existing fallback dirs in order
    if (is_dir($fallbackA)) {
        return $fallbackA;
    }
    return $fallbackB;
}

/**
 * Get container stats from docker stats command as a fallback.
 */
function getContainerCgroupStats(string $containerId): array
{
    $stats = ['mem_used_mb' => 0, 'mem_cap_mb' => 0, 'cpu_percent' => 0];
    $dockerBin = file_exists('/usr/bin/docker') ? '/usr/bin/docker' : 'docker';
    $cmd = $dockerBin . ' stats --no-stream --format "{{.CPUPerc}}|{{.MemUsage}}" ' . escapeshellarg($containerId) . ' 2>/dev/null';
    $out = trim((string) @shell_exec($cmd));
    if (!$out) {
        return $stats;
    }

    $parts = explode('|', $out);
    if (count($parts) >= 2) {
        $stats['cpu_percent'] = floatval(str_replace('%', '', $parts[0]));
        if (preg_match('/([0-9.]+)([a-zA-Z]*)\s*\/\s*([0-9.]+)([a-zA-Z]*)/', $parts[1], $m)) {
            $uNum = floatval($m[1]);
            $uUnit = strtoupper($m[2]);
            $lNum = floatval($m[3]);
            $lUnit = strtoupper($m[4]);

            $toMb = function ($n, $u) {
                if (strpos($u, 'G') !== false) {
                    return $n * 1024;
                }
                if (strpos($u, 'K') !== false) {
                    return $n / 1024;
                }
                if (strpos($u, 'B') !== false && strpos($u, 'M') === false) {
                    return $n / 1024 / 1024;
                }
                return $n;
            };

            $stats['mem_used_mb'] = $toMb($uNum, $uUnit);
            $stats['mem_cap_mb'] = $toMb($lNum, $lUnit);
        }
    }
    return $stats;
}

/**
 * Read agent metrics from mcmm_metrics.json in the container data dir.
 */
function readAgentMetrics(string $containerName, string $containerId = '', string $dataDir = ''): array
{
    $log = "[" . date('Y-m-d H:i:s') . "] ReadAgentMetrics for $containerName ($containerId)\n";
    if (empty($dataDir)) {
        $dataDir = getContainerDataDir($containerName, $containerId);
        $log .= "  - Derived dataDir: $dataDir\n";
    }
    $basePath = rtrim($dataDir, '/');
    $path = $basePath . '/mcmm_metrics.json';
    $log .= "  - Expected path: $path\n";

    if (!file_exists($path)) {
        $log .= "  - Error: File does not exist\n";
        @file_put_contents(dirname(__FILE__) . '/mcmm_agent_debug.log', $log, FILE_APPEND);
        return [];
    }
    $json = @file_get_contents($path);
    if (!$json) {
        $log .= "  - Error: Failed to read file or file empty\n";
    } else {
        $log .= "  - Success: Read " . strlen($json) . " bytes\n";
    }
    @file_put_contents(dirname(__FILE__) . '/mcmm_agent_debug.log', $log, FILE_APPEND);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

/**
 * Ensure metrics agent script exists in data dir and start it inside container
 */
function ensureMetricsAgent(string $containerName, string $containerId, string $dataDir): void
{
    if (empty($dataDir)) {
        $dataDir = getContainerDataDir($containerName, $containerId);
    }
    $scriptPath = rtrim($dataDir, '/') . '/mcmm_agent.sh';
    if (!file_exists($scriptPath)) {
        $script = "#!/bin/sh\n" .
            "DATA_FILE=\"/data/mcmm_metrics.json\"\n" .
            "INTERVAL=10\n" .
            "CPU_PREV=\"\"\n" .
            "TOTAL_PREV=\"\"\n" .
            "while true; do\n" .
            "  PID=\"\$(pidof java | awk '{print \$1}')\"\n" .
            "  if [ -n \"\$PID\" ] && [ -f \"/proc/\$PID/stat\" ]; then\n" .
            "    RSS_KB=\$(awk '/VmRSS/ {print \$2}' /proc/\$PID/status 2>/dev/null)\n" .
            "    STAT=\$(cat /proc/\$PID/stat)\n" .
            "    PROC_UTIME=\$(echo \"\$STAT\" | awk '{print \$14}')\n" .
            "    PROC_STIME=\$(echo \"\$STAT\" | awk '{print \$15}')\n" .
            "    PROC_TOTAL=\$((PROC_UTIME + PROC_STIME))\n" .
            "    CPU_LINE=\$(head -n1 /proc/stat)\n" .
            "    TOTAL=0\n" .
            "    for v in \$(echo \"\$CPU_LINE\" | cut -d ' ' -f2-); do TOTAL=\$((TOTAL + v)); done\n" .
            "    CPU_PCT=0\n" .
            "    if [ -n \"\$CPU_PREV\" ] && [ -n \"\$TOTAL_PREV\" ]; then\n" .
            "      DPROC=\$((PROC_TOTAL - CPU_PREV))\n" .
            "      DTOTAL=\$((TOTAL - TOTAL_PREV))\n" .
            "      if [ \"\$DTOTAL\" -gt 0 ]; then\n" .
            "        CPU_PCT=\$((DPROC * 100 / DTOTAL))\n" .
            "      fi\n" .
            "    fi\n" .
            "    CPU_PREV=\$PROC_TOTAL\n" .
            "    TOTAL_PREV=\$TOTAL\n" .
            "    # Try JVM native memory (jcmd) first; fallback to RSS\n" .
            "    NMT_KB=\"\"\n" .
            "    if command -v jcmd >/dev/null 2>&1; then\n" .
            "      NMT_LINE=\$(jcmd \"\$PID\" VM.native_memory summary 2>/dev/null | awk '/Total:/ {print \$0; exit}')\n" .
            "      if [ -n \"\$NMT_LINE\" ]; then\n" .
            "        NMT_KB=\$(echo \"\$NMT_LINE\" | sed -n 's/.*committed=\\([0-9]*\\)KB.*/\\1/p')\n" .
            "      fi\n" .
            "    fi\n" .
            "    if [ -n \"\$NMT_KB\" ]; then\n" .
            "      RAM_MB=\$((NMT_KB / 1024))\n" .
            "      NMT_MB=\$RAM_MB\n" .
            "    else\n" .
            "      RAM_MB=0\n" .
            "      NMT_MB=0\n" .
            "      if [ -n \"\$RSS_KB\" ]; then RAM_MB=\$((RSS_KB / 1024)); fi\n" .
            "    fi\n" .
            "    TS=\$(date +%s)\n" .
            "    echo \"{\\\"ts\\\":\$TS,\\\"pid\\\":\$PID,\\\"ram_used_mb\\\":\$RAM_MB,\\\"cpu_percent\\\":\$CPU_PCT,\\\"nmt_mb\\\":\$NMT_MB}\" > \"\$DATA_FILE\"\n" .
            "  fi\n" .
            "  sleep \$INTERVAL\n" .
            "done\n";
        @file_put_contents($scriptPath, $script);
        @chmod($scriptPath, 0755);
    }
    // start agent inside container
    $dockerBin = file_exists('/usr/bin/docker') ? '/usr/bin/docker' : 'docker';
    $cmd = $dockerBin . " exec " . escapeshellarg($containerName) . " sh -c " . escapeshellarg("nohup /data/mcmm_agent.sh >/dev/null 2>&1 &");
    @shell_exec($cmd);
}
/**
 * MCMM API Handler
 * Handles all API requests for the Minecraft Modpack Manager
 */

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
$defaultConfigPath = dirname(__DIR__) . "/default.cfg"; // In plugins/mcmm/default.cfg

// Helper to write INI file
if (!function_exists('write_ini_file')) {
    function write_ini_file($assoc_arr, $path, $has_sections = false)
    {
        $content = "";
        if ($has_sections) {
            foreach ($assoc_arr as $key => $elem) {
                $content .= "[" . $key . "]\n";
                foreach ($elem as $key2 => $elem2) {
                    if (is_array($elem2)) {
                        foreach ($elem2 as $elem3) {
                            $content .= $key2 . "[] = \"" . $elem3 . "\"\n";
                        }
                    } elseif ($elem2 == "") {
                        $content .= $key2 . " = \n";
                    } else {
                        $content .= $key2 . " = \"" . $elem2 . "\"\n";
                    }
                }
            }
        } else {
            foreach ($assoc_arr as $key => $elem) {
                if (is_array($elem)) {
                    foreach ($elem as $elem2) {
                        $content .= $key . "[] = \"" . $elem2 . "\"\n";
                    }
                } elseif ($elem == "") {
                    $content .= $key . " = \n";
                } else {
                    $content .= $key . " = \"" . $elem . "\"\n";
                }
            }
        }
        return file_put_contents($path, $content);
    }
}

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

// Debug log function
function dbg($msg)
{
    $line = date('[Y-m-d H:i:s] ') . print_r($msg, true) . "\n";
    @file_put_contents(dirname(__FILE__) . '/mcmm.log', $line, FILE_APPEND);
    @file_put_contents('/tmp/mcmm.log', $line, FILE_APPEND);
    @error_log("MCMM: " . print_r($msg, true));
}

function getRequestData()
{
    // Check standard POST first
    if (!empty($_POST)) {
        dbg("Using \$_POST data");
        return $_POST;
    }

    // Fallback to JSON input
    $input = file_get_contents('php://input');
    if (!empty($input)) {
        dbg("Using php://input JSON");
        $json = json_decode($input, true);
        if ($json) {
            return $json;
        }
        dbg("Failed to decode JSON input: " . substr($input, 0, 100));
    }

    return [];
}

// Ensure nothing is printed before JSON
if (ob_get_length()) {
    ob_clean();
}

dbg("API Loaded. Action: " . ($action ?? 'none'));

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

                        if ($versions && !empty($versions)) {
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
                                'ram' => round(min(max($ramUsagePercent ?? 0, 0), 100), 1),
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

        case 'backups_list':
            $backupDir = '/mnt/user/appdata/mcmm/backups';
            if (!is_dir($backupDir)) {
                @mkdir($backupDir, 0755, true);
            }
            $files = glob($backupDir . '/*.zip');
            $backups = [];
            foreach ($files as $file) {
                $stat = stat($file);
                $name = basename($file);
                if (preg_match('/backup_(.*)_\d+\.zip/', $name, $m)) {
                    $serverName = $m[1];
                }

                // Load server config for metadata by searching through all server folders
                $cfg = [];
                $serversDir = '/boot/config/plugins/mcmm/servers';
                $serversSearch = glob($serversDir . '/*/config.json');
                if ($serversSearch) {
                    foreach ($serversSearch as $cfgFile) {
                        $tempCfg = json_decode(@file_get_contents($cfgFile), true);
                        if ($tempCfg && isset($tempCfg['containerName']) && $tempCfg['containerName'] === $serverName) {
                            $cfg = $tempCfg;
                            break;
                        }
                    }
                }

                $backups[] = [
                    'name' => $name,
                    'size' => $stat['size'],
                    'date' => $stat['mtime'],
                    'server' => $serverName,
                    'icon' => $cfg['icon'] ?? $cfg['logo'] ?? $cfg['icon_url'] ?? '',
                    'author' => $cfg['author'] ?? 'Unknown',
                    'modpack' => $cfg['modpackName'] ?? $cfg['modpack'] ?? $cfg['name'] ?? $serverName
                ];
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

            $config = @include '/boot/config/plugins/mcmm/config.php' ?: [];

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

/**
 * Deployment handler for creating a new Minecraft container.
 */
function handleDeploy(array $config, array $defaults): void
{
    if (empty($config['curseforge_api_key'])) {
        jsonResponse(['success' => false, 'error' => 'CurseForge API key not configured'], 400);
    }

    // Accept either JSON or form data
    $input = getRequestData();
    if (!$input) {
        jsonResponse(['success' => false, 'error' => 'Invalid input data'], 400);
    }

    $modId = intval($input['modpack_id'] ?? 0);
    $fileId = intval($input['modpack_file_id'] ?? 0);
    if ($modId <= 0) {
        jsonResponse(['success' => false, 'error' => 'Missing modpack_id'], 400);
    }

    // Generate unique server ID
    $serverId = uniqid();

    $serverName = trim($input['server_name'] ?? $config['default_server_name']);
    $modpackName = trim($input['modpack_name'] ?? $serverName);
    $modpackSlug = trim($input['modpack_slug'] ?? '');
    $mcVerSelected = trim($input['mc_version'] ?? '');
    $loaderSelected = trim($input['loader'] ?? '');
    $safeSlug = $modpackSlug ? strtolower(preg_replace('/[^a-z0-9-]/', '-', $modpackSlug)) : '';
    $safeSlug = trim($safeSlug, '-');
    // Match minecraft-modpack-manager style: append short hash for uniqueness
    $resolvedFileId = $fileId ?: ($input['resolved_file_id'] ?? '');
    $uniqueSuffix = substr(md5(($fileId ?: $resolvedFileId ?: '') . '-' . $modId . '-' . $serverId), 0, 6);
    $containerName = safeContainerName(($safeSlug ?: $serverName) . '-' . $uniqueSuffix);
    if (dockerExists($containerName)) {
        jsonResponse(['success' => false, 'error' => 'A container with this name already exists'], 409);
    }

    $port = intval($input['port'] ?? $config['default_port']);
    if ($port < 1 || $port > 65535) {
        $port = 25565;
    }
    // Auto-pick next free port if requested one is busy
    $port = findAvailablePort($port);

    $serverIp = trim($input['server_ip'] ?? $config['default_ip'] ?? $defaults['default_ip'] ?? '0.0.0.0');
    $memory = trim($input['memory'] ?? $config['default_memory'] ?? $defaults['default_memory']);
    $maxPlayers = intval($input['max_players'] ?? $config['default_max_players'] ?? $defaults['default_max_players'] ?? 20);
    $jvmFlags = trim($input['jvm_flags'] ?? $config['jvm_flags'] ?? '');
    $whitelist = trim($input['whitelist'] ?? $config['default_whitelist'] ?? '');
    $iconUrl = trim($input['icon_url'] ?? $config['default_icon_url'] ?? '');
    $javaVer = trim($input['java_version'] ?? '');

    // Resolve modpack download URL (respect selected file if provided)
    [$resolvedFileId, $downloadUrl] = getModpackDownload($modId, $config['curseforge_api_key'], $fileId ?: null);
    if (!$downloadUrl) {
        // Log to the plugin directory (emhttp/plugins/mcmm/deploy_fail.log)
        $logDir = __DIR__;
        $logFile = "{$logDir}/deploy_fail.log";
        @file_put_contents(
            $logFile,
            "Failed to resolve download URL\nmodpack_id: $modId\npreferred_file_id: " . ($fileId ?: 'none') . "\n" .
            "serverPack/main/latest and files list exhausted\n\n",
            FILE_APPEND
        );
        jsonResponse([
            'success' => false,
            'error' => 'Could not resolve modpack download URL. Check CurseForge API key and network, or choose a different version.',
            'output' => []
        ], 502);
    }

    // Place server data directly under appdata/<containerName>
    $dataDir = "/mnt/user/appdata/{$containerName}";
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0775, true);
    }

    $flagPvp = boolInput($input['pvp'] ?? $config['default_pvp'], $config['default_pvp']);
    $flagHardcore = boolInput($input['hardcore'] ?? $config['default_hardcore'], $config['default_hardcore']);
    $flagFlight = boolInput($input['allow_flight'] ?? $config['default_allow_flight'], $config['default_allow_flight']);
    $flagCmdBlocks = boolInput($input['command_blocks'] ?? $config['default_command_blocks'], $config['default_command_blocks']);
    $flagRolling = boolInput($input['rolling_logs'] ?? $config['default_rolling_logs'], $config['default_rolling_logs']);
    $flagLogTs = boolInput($input['log_timestamp'] ?? $config['default_log_timestamp'], $config['default_log_timestamp']);
    $flagAikar = boolInput($input['aikar_flags'] ?? $config['default_aikar_flags'], $config['default_aikar_flags']);
    $flagMeowice = boolInput($input['meowice_flags'] ?? $config['default_meowice_flags'], $config['default_meowice_flags']);
    $flagGraalvm = boolInput($input['graalvm_flags'] ?? $config['default_graalvm_flags'], $config['default_graalvm_flags']);

    $env = [
        'EULA' => 'TRUE',
        // Align with minecraft-modpack-manager: use AUTO_CURSEFORGE with slug + file ID
        'TYPE' => 'AUTO_CURSEFORGE',
        'CF_API_KEY' => $config['curseforge_api_key'],
        'CF_SLUG' => $safeSlug ?: $serverName,
        'CF_FILE_ID' => $resolvedFileId ?: '',
        'MEMORY' => $memory,
        'SERVER_NAME' => $serverName,
        'SERVER_IP' => $serverIp,
        'SERVER_PORT' => $port,
        'ENABLE_QUERY' => 'TRUE',
        'QUERY_PORT' => $port,
        'MAX_PLAYERS' => $maxPlayers,
        'ENABLE_WHITELIST' => $whitelist !== '' ? 'TRUE' : 'FALSE',
        'WHITELIST' => $whitelist,
        'ICON' => $iconUrl,
        'PVP' => $flagPvp ? 'TRUE' : 'FALSE',
        'HARDCORE' => $flagHardcore ? 'TRUE' : 'FALSE',
        'ALLOW_FLIGHT' => $flagFlight ? 'TRUE' : 'FALSE',
        'ENABLE_COMMAND_BLOCK' => $flagCmdBlocks ? 'TRUE' : 'FALSE',
        'ENABLE_ROLLING_LOGS' => $flagRolling ? 'TRUE' : 'FALSE',
        'USE_LOG_TIMESTAMP' => $flagLogTs ? 'TRUE' : 'FALSE',
        'USE_AIKAR_FLAGS' => $flagAikar ? 'TRUE' : 'FALSE',
        'JVM_OPTS' => $jvmFlags
    ];

    if ($flagMeowice) {
        // ZGC is only available in Java 11+ (stable 15+)
        if ($javaVer !== '8') {
            $env['JVM_OPTS'] = trim(($env['JVM_OPTS'] ?? '') . ' -XX:+UseZGC');
        } else {
            // For Java 8, maybe add G1GC or other safe flags if Meowice is on
            $env['JVM_OPTS'] = trim(($env['JVM_OPTS'] ?? '') . ' -XX:+UseG1GC');
        }
    }

    if ($flagGraalvm) {
        $env['USE_GRAALVM_JDK'] = 'TRUE';
    }

    if (empty($env['RCON_PASSWORD'])) {
        $env['RCON_PASSWORD'] = bin2hex(random_bytes(6));
        $env['RCON_PORT'] = $env['RCON_PORT'] ?? 25575;
        $env['ENABLE_RCON'] = 'TRUE';
    }

    $envArgs = buildEnvArgs($env);

    // Use absolute docker path if available
    $dockerBin = file_exists('/usr/bin/docker') ? '/usr/bin/docker' : 'docker';
    // Image reference (match minecraft-modpack-manager)
    $dockerImage = 'itzg/minecraft-server';
    if ($javaVer === '21') {
        $dockerImage .= ':java21';
    } elseif ($javaVer === '17') {
        $dockerImage .= ':java17';
    } elseif ($javaVer === '11') {
        $dockerImage .= ':java11';
    } elseif ($javaVer === '8') {
        $dockerImage .= ':java8';
    }

    // Labels (include icon if provided, match minecraft-modpack-manager behavior)
    $labels = [
        'mcmm' => '1',
        'net.unraid.docker.managed' => 'dockerman',
        'net.unraid.docker.repository' => $dockerImage
    ];
    if (!empty($iconUrl)) {
        $labels['net.unraid.docker.icon'] = $iconUrl;
        $labels['mcmm.icon'] = $iconUrl;
    }
    $labelArgs = '';
    foreach ($labels as $k => $v) {
        $labelArgs .= ' --label ' . escapeshellarg($k . '=' . $v);
    }

    // Write Unraid dockerMan template so container is editable (mimic minecraft-modpack-manager)
    $templateDir = "/boot/config/plugins/dockerMan/templates-user";
    if (!is_dir($templateDir)) {
        @mkdir($templateDir, 0777, true);
    }
    $templateFile = $templateDir . "/my-" . $containerName . ".xml";
    $xmlEnv = '';
    foreach ($env as $k => $v) {
        $xmlEnv .= "<Config Name=\"" . htmlspecialchars($k, ENT_QUOTES) . "\" Target=\"" . htmlspecialchars($k, ENT_QUOTES) . "\" Default=\"" . htmlspecialchars($v, ENT_QUOTES) . "\" Mode=\"\" Description=\"\" Type=\"Variable\" Display=\"advanced\" Required=\"false\"></Config>\n";
    }
    $xmlPorts = '<Config Name="Game Port" Target="25565" Default="' . htmlspecialchars($port, ENT_QUOTES) . '" Mode="tcp" Description="Game Port" Type="Port" Display="always" Required="false" Mask="false"></Config>';
    $xmlBinds = '<Config Name="Server Data" Target="/data" Default="' . htmlspecialchars($dataDir, ENT_QUOTES) . '" Mode="rw" Description="Server files" Type="Path" Display="always" Required="false" Mask="false"></Config>';
    $xmlIcon = $iconUrl ? htmlspecialchars($iconUrl, ENT_QUOTES) : '';
    $xml = <<<XML
<Container>
  <Name>{$containerName}</Name>
  <Repository>{$dockerImage}</Repository>
  <Registry>https://hub.docker.com/r/itzg/minecraft-server</Registry>
  <Network>bridge</Network>
  <MyIP/>
  <Shell>sh</Shell>
  <Privileged>false</Privileged>
  <Support>https://github.com/itzg/docker-minecraft-server</Support>
  <Project/>
  <Overview>Automatically installs modpacks and servers</Overview>
  <Category/>
  <WebUI/>
  <Icon>{$xmlIcon}</Icon>
  <ExtraParams>--restart unless-stopped</ExtraParams>
  <PostArgs></PostArgs>
  <CPUset></CPUset>
  <DateInstalled></DateInstalled>
  <Config Name="Network Type" Target="bridge" Default="bridge" Mode="" Description="" Type="Network" Display="advanced" Required="false" Mask="false"></Config>
  {$xmlPorts}
  {$xmlBinds}
  {$xmlEnv}
</Container>
XML;
    @file_put_contents($templateFile, $xml);

    $cmd = sprintf(
        '%s run -d --restart unless-stopped --name %s -p %s -v %s %s %s %s',
        $dockerBin,
        escapeshellarg($containerName),
        escapeshellarg($port . ':25565'),
        escapeshellarg($dataDir . ':/data'),
        $envArgs,
        $labelArgs,
        escapeshellarg($dockerImage)
    );

    $output = [];
    $exitCode = 0;
    exec($cmd . ' 2>&1', $output, $exitCode);

    if ($exitCode !== 0) {
        // Log for debugging
        @file_put_contents('/tmp/mcmm_deploy_fail.log', "CMD: $cmd\nExit: $exitCode\nOutput:\n" . implode("\n", $output) . "\n\n", FILE_APPEND);
        jsonResponse([
            'success' => false,
            'error' => 'Docker failed to create the server. See output for details.',
            'output' => $output
        ], 500);
    }

    // Start metrics agent
    ensureMetricsAgent($containerName, trim($output[0] ?? $containerName), $dataDir);

    // Save server configuration to file (like minecraft-modpack-manager)
    $serversDir = '/boot/config/plugins/mcmm/servers';
    if (!is_dir($serversDir)) {
        @mkdir($serversDir, 0755, true);
    }
    $serverDir = $serversDir . '/' . $serverId;
    if (!is_dir($serverDir)) {
        @mkdir($serverDir, 0755, true);
    }

    $serverConfig = [
        'id' => $serverId,
        'name' => $serverName,
        'modpack' => $modpackName,
        'author' => trim($input['modpack_author'] ?? 'Unknown'),
        'slug' => $modpackSlug,
        'modpackId' => $modId,
        'modpackFileId' => $fileId ?: $resolvedFileId,
        'mc_version' => $mcVerSelected,
        'loader' => $loaderSelected,
        'icon' => $iconUrl,
        'logo' => $iconUrl, // compatibility
        'port' => $port,
        'maxPlayers' => $maxPlayers,
        'memory' => $memory,
        'containerName' => $containerName
    ];

    file_put_contents($serverDir . '/config.json', json_encode($serverConfig, JSON_PRETTY_PRINT));

    jsonResponse([
        'success' => true,
        'container' => $containerName,
        'id' => trim($output[0] ?? ''),
        'fileId' => $fileId,
        'download' => $downloadUrl
    ]);
}

function jsonResponse($data, int $statusCode = 200): void
{
    // Always return 200 OK so the frontend receives the JSON "success": false payload
    // instead of a generic browser error page or empty response for 500s.
    http_response_code(200);
    echo json_encode($data);
    exit;
}

function fetchCurseForgeModpacks(string $search, string $apiKey): ?array
{
    $query = http_build_query([
        'gameId' => 432,
        'classId' => 4471,
        'searchFilter' => $search,
        'sortField' => 2, // popularity
        'sortOrder' => 'desc',
        'pageSize' => 20
    ]);

    $payload = cfRequest('/mods/search?' . $query, $apiKey);
    if (!$payload || !isset($payload['data'])) {
        return null;
    }

    $modpacks = [];
    foreach ($payload['data'] as $pack) {
        $modpacks[] = [
            'id' => $pack['id'],
            'name' => $pack['name'],
            'slug' => $pack['slug'] ?? '',
            'author' => $pack['authors'][0]['name'] ?? 'Unknown',
            'downloads' => formatDownloads($pack['downloadCount'] ?? 0),
            'img' => $pack['logo']['url'] ?? '',
            'summary' => $pack['summary'] ?? '',
            'tags' => array_slice(array_map(fn($c) => $c['name'], $pack['categories'] ?? []), 0, 3)
        ];
    }

    return $modpacks;
}

function fetchCurseForgeMods(string $search, string $version, string $loader, int $page, int $pageSize, string $apiKey): array
{
    $params = [
        'gameId' => 432,
        'classId' => 6, // 6 = Mods
        'searchFilter' => $search,
        'sortField' => 2, // popularity
        'sortOrder' => 'desc',
        'pageSize' => $pageSize,
        'index' => ($page - 1) * $pageSize
    ];
    if ($version) {
        $params['gameVersion'] = $version;
    }
    if ($loader) {
        $loaderMapSize = [
            'forge' => 1,
            'fabric' => 4,
            'quilt' => 5,
            'neoforge' => 6
        ];
        if (isset($loaderMapSize[strtolower($loader)])) {
            $params['modLoaderType'] = $loaderMapSize[strtolower($loader)];
        }
    }

    $query = http_build_query($params);
    $payload = cfRequest('/mods/search?' . $query, $apiKey);

    if (!$payload || !isset($payload['data'])) {
        return [[], 0];
    }

    $pagination = $payload['pagination'] ?? [];
    $total = intval($pagination['totalCount'] ?? (count($payload['data']) + (($page - 1) * $pageSize)));

    $mods = [];
    foreach ($payload['data'] as $mod) {
        $targetFile = null;
        // Search for a matching file in latestFiles
        if (!empty($mod['latestFiles'])) {
            // Priority: matches both version and loader
            foreach ($mod['latestFiles'] as $file) {
                $versions = $file['gameVersions'] ?? [];
                $hasVersion = !$version || in_array($version, $versions);
                $hasLoader = true;
                if ($loader) {
                    $loaderNames = [1 => 'forge', 4 => 'fabric', 5 => 'quilt', 6 => 'neoforge'];
                    $targetLoaderName = $loaderNames[$loaderMapSize[strtolower($loader)] ?? 0] ?? '';
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

        $targetFileName = $targetFile ? ($targetFile['fileName'] ?? ($targetFile['displayName'] ?? '')) : null;

        $mods[] = [
            'id' => $mod['id'],
            'name' => $mod['name'],
            'author' => $mod['authors'][0]['name'] ?? 'Unknown',
            'downloads' => formatDownloads($mod['downloadCount'] ?? 0),
            'downloadsRaw' => intval($mod['downloadCount'] ?? 0),
            'icon' => $mod['logo']['url'] ?? '',
            'summary' => $mod['summary'] ?? '',
            'latestFiles' => $mod['latestFiles'],
            'latestFileId' => $targetFile['id'] ?? null,
            'latestFileName' => $targetFileName,
            'mcVersion' => $version ?: 'Various'
        ];
    }
    return [$mods, $total];
}

function fetchCurseForgeFiles(int $modId, string $version, string $loader, string $apiKey): array
{
    $url = "https://api.curseforge.com/v1/mods/$modId/files";
    if ($version) {
        $url .= "?gameVersion=" . urlencode($version);
    }

    // Convert loader name to CF's integer type for local filtering
    $loaderInt = null;
    if ($loader) {
        $loaderMapSize = [
            'forge' => 1,
            'fabric' => 4,
            'quilt' => 5,
            'neoforge' => 6
        ];
        $loaderInt = $loaderMapSize[strtolower($loader)] ?? null;
    }

    $payload = cfRequest($url, $apiKey, true); // true to indicate full URL

    if (!$payload || !isset($payload['data'])) {
        return [];
    }

    $files = $payload['data'];

    // Explicit client-side filtering as a safety measure
    if ($version || $loaderInt) {
        $files = array_filter($files, function ($file) use ($version, $loaderInt) {
            // Version check - allow exact match or prefix if needed,
            // but CF usually provides exact game versions in the array.
            $gameVersions = $file['gameVersions'] ?? [];
            if ($version && !in_array($version, $gameVersions)) {
                // Special case: if looking for 1.21.1, also allow 1.21 if it's the only one
                // but usually CF is quite specific.
                return false;
            }

            // Loader check
            if ($loaderInt) {
                $fileLoader = $file['modLoaderType'] ?? null;
                $loaderNames = [1 => 'forge', 4 => 'fabric', 5 => 'quilt', 6 => 'neoforge'];
                $targetName = $loaderNames[$loaderInt] ?? '';
                $versionsLower = array_map('strtolower', $gameVersions);

                // If perfectly matches the requested loader type, it's good
                if ($fileLoader === $loaderInt) {
                    return true;
                }

                // If file has NO loader type (0 or null), it might be universal
                if (!$fileLoader) {
                    // Check if it's explicitly another loader
                    foreach ($loaderNames as $id => $name) {
                        if ($id !== $loaderInt && in_array($name, $versionsLower)) {
                            return false; // Specifically for another loader
                        }
                    }
                    return true; // Likely universal
                }

                // Crossover: Forge and NeoForge often share files or tags
                if (
                    ($loaderInt === 1 || $loaderInt === 6) &&
                    (in_array('forge', $versionsLower) || in_array('neoforge', $versionsLower))
                ) {
                    return true;
                }

                // Final check: is the target loader name in the versions list?
                if ($targetName && in_array($targetName, $versionsLower)) {
                    return true;
                }

                return false;
            }
            return true;
        });
    }

    // Sort by date (newest first)
    usort($files, function ($a, $b) {
        return strtotime($b['fileDate'] ?? '0') - strtotime($a['fileDate'] ?? '0');
    });

    return array_values($files);
}

function fetchModrinthMods(string $search, string $version, string $loader, int $page, int $pageSize): array
{
    $facets = [];
    // Project type: mod
    $facets[] = '["project_type:mod"]';
    if ($version) {
        $facets[] = '["versions:' . addslashes($version) . '"]';
    }
    if ($loader) {
        $loaderLower = strtolower($loader);
        // Map loader names - use OR for forge/neoforge compatibility
        if (stripos($loaderLower, 'forge') !== false || stripos($loaderLower, 'neoforge') !== false) {
            // Include both forge and neoforge mods
            $facets[] = '["categories:forge", "categories:neoforge"]';
        } elseif (stripos($loaderLower, 'fabric') !== false) {
            $facets[] = '["categories:fabric"]';
        } elseif (stripos($loaderLower, 'quilt') !== false) {
            // Include both fabric and quilt for compatibility
            $facets[] = '["categories:quilt", "categories:fabric"]';
        } else {
            $facets[] = '["categories:' . addslashes($loaderLower) . '"]';
        }
    }
    $facetStr = '[' . implode(',', $facets) . ']';

    $query = http_build_query([
        'query' => $search,
        'limit' => $pageSize,
        'offset' => ($page - 1) * $pageSize,
        'index' => 'relevance',
        'facets' => $facetStr
    ]);

    $payload = mrRequest('/search?' . $query);
    if (!$payload || !isset($payload['hits'])) {
        return [[], 0];
    }

    $total = intval($payload['total_hits'] ?? 0);

    $mods = [];
    foreach ($payload['hits'] as $hit) {
        $mods[] = [
            'id' => $hit['project_id'],
            'name' => $hit['title'],
            'author' => $hit['author'] ?? 'Unknown',
            'downloads' => formatDownloads($hit['downloads'] ?? 0),
            'downloadsRaw' => intval($hit['downloads'] ?? 0),
            'icon' => $hit['icon_url'] ?? '',
            'summary' => $hit['description'] ?? '',
            'mcVersion' => $version ?: 'Various'
        ];
    }
    return [$mods, $total];
}

function fetchModrinthFiles(string $projectId, string $version, string $loader): array
{
    $url = "https://api.modrinth.com/v2/project/$projectId/version";
    $params = [];
    if ($version) {
        $params['game_versions'] = json_encode([$version]);
    }
    if ($loader) {
        $loaderLower = strtolower($loader);
        $modrinthLoaders = [];
        if (stripos($loaderLower, 'neoforge') !== false) {
            $modrinthLoaders[] = 'neoforge';
            $modrinthLoaders[] = 'forge';
        } elseif (stripos($loaderLower, 'forge') !== false) {
            $modrinthLoaders[] = 'forge';
            $modrinthLoaders[] = 'neoforge';
        } elseif (stripos($loaderLower, 'fabric') !== false) {
            $modrinthLoaders[] = 'fabric';
        } elseif (stripos($loaderLower, 'quilt') !== false) {
            $modrinthLoaders[] = 'quilt';
        }

        if (!empty($modrinthLoaders)) {
            $params['loaders'] = json_encode($modrinthLoaders);
        } else {
            $params['loaders'] = json_encode([$loaderLower]);
        }
    }
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $payload = mrRequest($url, true); // true to indicate full URL
    if (!$payload || !is_array($payload)) {
        return [];
    }

    // Map to CurseForge-like shape
    $mapped = [];
    foreach ($payload as $ver) {
        $files = $ver['files'] ?? [];
        $primary = null;
        foreach ($files as $f) {
            if (!empty($f['primary'])) {
                $primary = $f;
                break;
            }
        }
        if (!$primary && !empty($files)) {
            // prefer jar
            foreach ($files as $f) {
                if (isset($f['filename']) && substr($f['filename'], -4) === '.jar') {
                    $primary = $f;
                    break;
                }
            }
            if (!$primary) {
                $primary = $files[0];
            }
        }

        $mapped[] = [
            'id' => $ver['id'],
            'displayName' => $ver['name'] ?? ($primary['filename'] ?? 'Version'),
            'fileName' => $primary['filename'] ?? '',
            'releaseType' => ($ver['version_type'] ?? 'release') === 'release' ? 1 : (($ver['version_type'] ?? '') === 'beta' ? 2 : 3),
            'gameVersions' => $ver['game_versions'] ?? [],
            'downloadUrl' => $primary['url'] ?? '',
        ];
    }
    return $mapped;
}

function getModrinthDownloadUrl(string $versionId): ?string
{
    if (!$versionId) {
        return null;
    }
    $payload = mrRequest('/version/' . $versionId);
    if (!$payload || empty($payload['files'])) {
        return null;
    }
    $files = $payload['files'];
    $primary = null;
    foreach ($files as $f) {
        if (!empty($f['primary'])) {
            $primary = $f;
            break;
        }
    }
    if (!$primary) {
        foreach ($files as $f) {
            if (isset($f['filename']) && substr($f['filename'], -4) === '.jar') {
                $primary = $f;
                break;
            }
        }
    }
    if (!$primary) {
        $primary = $files[0];
    }
    return $primary['url'] ?? null;
}

function mrRequest(string $path, bool $isFullUrl = false): ?array
{
    $url = $isFullUrl ? $path : ('https://api.modrinth.com/v2' . $path);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        dbg("MR Curl Error: $error");
        return null;
    }
    if ($httpCode >= 400) {
        dbg("MR HTTP Error $httpCode: $response");
        return null;
    }
    $json = json_decode($response, true);
    if (!$json) {
        dbg("MR JSON Decode Error. Response: $response");
        return null;
    }
    return $json;
}

function getModDownloadUrl(int $modId, string $fileId, string $apiKey): ?string
{
    if ($fileId) {
        $file = cfRequest("/mods/$modId/files/$fileId", $apiKey);
        if ($file && isset($file['data']['downloadUrl'])) {
            return $file['data']['downloadUrl'];
        }
        return null; // Explicit file requested but failed
    }

    // Get mod details to find main file (fallback)
    $details = cfRequest('/mods/' . $modId, $apiKey);
    if (!$details || empty($details['data'])) {
        return null;
    }

    // Ideally we filter by version here, but for MVP just get latest
    $data = $details['data'];
    $files = $data['latestFiles'];

    if (empty($files)) {
        return null;
    }

    // Simple logic: grab first available downloadUrl
    foreach ($files as $file) {
        if (!empty($file['downloadUrl'])) {
            return $file['downloadUrl'];
        }
    }

    return null;
}

function cfRequest(string $path, string $apiKey, bool $isFullUrl = false, string $method = 'GET', $data = null): ?array
{
    $url = $isFullUrl ? $path : ('https://api.curseforge.com/v1' . $path);
    dbg("CF Request ($method): $url");

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    $headers = [
        'Accept: application/json',
        'x-api-key: ' . $apiKey
    ];

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            $jsonData = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($jsonData);
        }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        dbg("CF Curl Error: $error");
        return null;
    }

    if ($httpCode >= 400) {
        dbg("CF HTTP Error $httpCode: $response");
        return null;
    }

    $json = json_decode($response, true);
    if (!$json) {
        dbg("CF JSON Decode Error. Response: $response");
        return null;
    }

    return $json;
}

function getModpackDownload(int $modId, string $apiKey, ?int $preferredFileId = null): array
{
    $details = cfRequest('/mods/' . $modId, $apiKey);
    if (!$details || empty($details['data'])) {
        return [null, null];
    }

    $data = $details['data'];
    $serverPackId = $data['serverPackFileId'] ?? null;

    // Helper to decide if file looks like a server pack
    $isServerPack = function ($file) use ($serverPackId) {
        if (!$file) {
            return false;
        }
        if (!empty($file['isServerPack'])) {
            return true;
        }
        if ($serverPackId && isset($file['id']) && intval($file['id']) === intval($serverPackId)) {
            return true;
        }
        $name = strtolower($file['displayName'] ?? $file['fileName'] ?? '');
        return (strpos($name, 'server') !== false) || (strpos($name, 'serverpack') !== false);
    };

    // Try explicit preferred file first
    if ($preferredFileId) {
        $file = cfRequest('/mods/' . $modId . '/files/' . $preferredFileId, $apiKey);
        if ($file && isset($file['data']['downloadUrl'])) {
            return [$preferredFileId, $file['data']['downloadUrl']];
        }
    }

    // Try serverPackFileId from mod details
    if ($serverPackId) {
        $file = cfRequest('/mods/' . $modId . '/files/' . $serverPackId, $apiKey);
        if ($file && isset($file['data']['downloadUrl'])) {
            return [$serverPackId, $file['data']['downloadUrl']];
        }
    }

    // Inspect latestFiles for a server pack first, otherwise any with downloadUrl
    if (!empty($data['latestFiles'])) {
        foreach ($data['latestFiles'] as $f) {
            if ($isServerPack($f) && !empty($f['downloadUrl'])) {
                return [$f['id'], $f['downloadUrl']];
            }
        }
        foreach ($data['latestFiles'] as $f) {
            if (!empty($f['downloadUrl'])) {
                return [$f['id'], $f['downloadUrl']];
            }
        }
    }

    // Final fallback: query files list and pick a server pack if possible, else first with downloadUrl
    $files = fetchCurseForgeFiles($modId, '', '', $apiKey);
    if (!empty($files)) {
        foreach ($files as $f) {
            if ($isServerPack($f) && !empty($f['downloadUrl'])) {
                return [$f['id'], $f['downloadUrl']];
            }
        }
        foreach ($files as $f) {
            if (!empty($f['downloadUrl'])) {
                return [$f['id'], $f['downloadUrl']];
            }
        }
    }

    return [null, null];
}

function formatDownloads($count): string
{
    if ($count >= 1000000) {
        return round($count / 1000000, 1) . 'M';
    }
    if ($count >= 1000) {
        return round($count / 1000, 1) . 'K';
    }
    return (string) $count;
}

function getModpackLoaders(string $platform, string $slug, string $apiKey, ?string $modpackId = null): array
{
    if ($platform === 'modrinth') {
        $data = mrRequest("/project/" . urlencode($slug));
        return $data['loaders'] ?? [];
    } elseif ($platform === 'curseforge') {
        if (!$modpackId) {
            $search = cfRequest("/mods/search?gameId=432&classId=4471&searchFilter=" . urlencode($slug), $apiKey);
            if (isset($search['data'][0]['id'])) {
                $modpackId = $search['data'][0]['id'];
            } else {
                return [];
            }
        }
        $url = "https://api.curseforge.com/v1/mods/" . urlencode($modpackId) . "/files?pageSize=1";
        $data = cfRequest($url, $apiKey, true);
        if (isset($data['data'][0]['gameVersions'])) {
            $loaders = [];
            foreach ($data['data'][0]['gameVersions'] as $gv) {
                $gvLower = strtolower($gv);
                if (in_array($gvLower, ['fabric', 'forge', 'neoforge', 'quilt'])) {
                    $loaders[] = ucfirst($gvLower);
                }
            }
            return array_unique($loaders);
        }
    }
    return [];
}

function getModpackVersions(string $platform, string $id, string $apiKey = ''): array
{
    if ($platform === 'modrinth') {
        $data = mrRequest("/project/" . urlencode($id) . "/version");
        if (!$data || isset($data['error'])) {
            return [];
        }
        $versions = [];
        foreach ($data as $v) {
            $versions[] = [
                'id' => $v['id'],
                'name' => $v['name'],
                'version_number' => $v['version_number'],
                'game_versions' => $v['game_versions'],
                'loaders' => $v['loaders'],
                'date' => $v['date_published']
            ];
        }
        return $versions;
    } elseif ($platform === 'curseforge') {
        $modId = $id;
        if (!is_numeric($id)) {
            $search = cfRequest("/mods/search?gameId=432&classId=4471&searchFilter=" . urlencode($id), $apiKey);
            if (isset($search['data'][0]['id'])) {
                $modId = $search['data'][0]['id'];
            } else {
                return [];
            }
        }
        $data = cfRequest("/mods/" . urlencode($modId) . "/files?pageSize=50", $apiKey);
        if (!$data || !isset($data['data'])) {
            return [];
        }
        $versions = [];
        foreach ($data['data'] as $file) {
            $mcVersions = [];
            $loaders = [];
            foreach ($file['gameVersions'] as $gv) {
                if (preg_match('/^\d+\.\d+(\.\d+)?$/', $gv)) {
                    $mcVersions[] = $gv;
                } else {
                    $loaders[] = $gv;
                }
            }
            $versions[] = [
                'id' => $file['id'],
                'name' => $file['displayName'] ?? '',
                'game_versions' => $mcVersions,
                'loaders' => $loaders,
                'date' => $file['fileDate']
            ];
        }
        return $versions;
    }
    return [];
}

function getMinecraftVersion(string $platform, string $id, string $versionId, string $apiKey = ''): ?string
{
    $versions = getModpackVersions($platform, $id, $apiKey);
    foreach ($versions as $v) {
        if ((string) $v['id'] === (string) $versionId) {
            foreach ($v['game_versions'] as $gv) {
                if (preg_match('/^\d+\.\d+(\.\d+)?$/', $gv)) {
                    return $gv;
                }
            }
        }
    }
    return null;
}

function getServerMetadata(array $env, array $config, string $containerName, string $apiKey): array
{
    $mcVersion = '';
    $loader = '';
    $debug = [];

    // 1. Local config check
    $serversDir = '/boot/config/plugins/mcmm/servers';
    $srvCfg = null;
    $serversSearch = glob($serversDir . '/*/config.json');
    if ($serversSearch) {
        foreach ($serversSearch as $cfgFile) {
            $cfg = json_decode(@file_get_contents($cfgFile), true);
            if ($cfg && isset($cfg['containerName']) && $cfg['containerName'] === $containerName) {
                $srvCfg = $cfg;
                break;
            }
        }
    }

    if ($srvCfg) {
        $mcVersion = $srvCfg['mc_version'] ?? $srvCfg['gameVersion'] ?? '';
        $loader = $srvCfg['loader'] ?? '';
    }

    $debug['localConfig'] = ['mcVersion' => $mcVersion, 'loader' => $loader];

    // 2. Env check
    if (!$mcVersion) {
        $envVersion = $env['VERSION'] ?? $env['MINECRAFT_VERSION'] ?? $env['SERVER_VERSION'] ?? $env['MODRINTH_VERSION'] ?? '';
        if (preg_match('/\d+\.\d+(\.\d+)?/', $envVersion, $m)) {
            $mcVersion = $m[0];
        }
    }
    if (!$loader) {
        $envType = strtolower($env['TYPE'] ?? $env['GAME_TYPE'] ?? $env['MODRINTH_LOADER'] ?? '');
        if (strpos($envType, 'neoforge') !== false) {
            $loader = 'neoforge';
        } elseif (strpos($envType, 'forge') !== false) {
            $loader = 'forge';
        } elseif (strpos($envType, 'fabric') !== false) {
            $loader = 'fabric';
        } elseif (strpos($envType, 'quilt') !== false) {
            $loader = 'quilt';
        }
    }

    $debug['envCheck'] = ['mcVersion' => $mcVersion, 'loader' => $loader];

    // 3. API Backfill
    $platform = $srvCfg['platform'] ?? ($env['MODRINTH_ID'] ? 'modrinth' : ($env['CF_MODPACK_ID'] ? 'curseforge' : ''));
    $slug = $srvCfg['slug'] ?? '';

    // Guess slug from container name if missing
    if (!$slug && !$mcVersion) {
        // e.g. all-the-mods-10-47a4db -> all-the-mods-10
        if (preg_match('/^(.*?)-[a-f0-9]{6}$/', $containerName, $sm)) {
            $slug = $sm[1];
        } else {
            $slug = $containerName;
        }
    }

    $modpackId = $srvCfg['modpackId'] ?? $srvCfg['id'] ?? null;
    $modpackVersion = $srvCfg['modpackVersion'] ?? $srvCfg['version'] ?? null;

    $cfModpackId = $env['CF_MODPACK_ID'] ?? $modpackId;
    $cfFileId = $env['CF_FILE_ID'] ?? $modpackVersion;
    $mrProjectId = $env['MODRINTH_ID'] ?? $env['MODRINTH_PROJECT'] ?? ($platform === 'modrinth' ? $modpackId : null);
    $mrVersionId = $env['MODRINTH_VERSION'] ?? ($platform === 'modrinth' ? $modpackVersion : null);

    $debug['backfillSource'] = [
        'platform' => $platform,
        'cfModpackId' => $cfModpackId,
        'cfFileId' => $cfFileId,
        'mrProjectId' => $mrProjectId,
        'mrVersionId' => $mrVersionId,
        'slug' => $slug
    ];

    if ((!$mcVersion || !$loader) && $apiKey && ($cfModpackId || $slug)) {
        $targetId = $cfModpackId ?: $slug;
        $targetFile = $cfFileId;

        // If targetId is present but targetFile is missing, get newest version info
        if ($targetId && !$targetFile) {
            $versions = getModpackVersions('curseforge', (string) $targetId, $apiKey);
            if (!empty($versions)) {
                $targetFile = $versions[0]['id'];
                if (!$mcVersion && !empty($versions[0]['game_versions'])) {
                    foreach ($versions[0]['game_versions'] as $gv) {
                        if (preg_match('/^\d+\.\d+(\.\d+)?$/', $gv)) {
                            $mcVersion = $gv;
                            break;
                        }
                    }
                }
                if (!$loader && !empty($versions[0]['loaders'])) {
                    $loader = strtolower($versions[0]['loaders'][0]);
                }
                $debug['cfBackfillLatest'] = ['fileId' => $targetFile, 'mcVersion' => $mcVersion, 'loader' => $loader];
            }
        }

        if ($targetId && $targetFile) {
            if (!$mcVersion) {
                $mcVersion = getMinecraftVersion('curseforge', (string) $targetId, (string) $targetFile, $apiKey);
            }
            if (!$loader) {
                $loaders = getModpackLoaders('curseforge', (string) ($slug ?: $targetId), $apiKey, (string) $targetId);
                $loader = !empty($loaders) ? strtolower($loaders[0]) : '';
            }
            $debug['cfBackfillTarget'] = ['mcVersion' => $mcVersion, 'loader' => $loader];
        }
    }

    if ((!$mcVersion || !$loader) && $mrProjectId) {
        $targetProj = (string) $mrProjectId;
        $targetVer = (string) $mrVersionId;

        if (!$targetVer) {
            $versions = getModpackVersions('modrinth', $targetProj, '');
            if (!empty($versions)) {
                $targetVer = $versions[0]['id'];
                if (!$mcVersion && !empty($versions[0]['game_versions'])) {
                    $mcVersion = $versions[0]['game_versions'][0];
                }
                if (!$loader && !empty($versions[0]['loaders'])) {
                    $loader = strtolower($versions[0]['loaders'][0]);
                }
            }
        }

        if ($targetProj && $targetVer) {
            if (!$mcVersion) {
                $mcVersion = getMinecraftVersion('modrinth', $targetProj, $targetVer);
            }
            if (!$loader) {
                $loaders = getModpackLoaders('modrinth', $targetProj, '');
                $loader = !empty($loaders) ? strtolower($loaders[0]) : '';
            }
            $debug['mrBackfill'] = ['mcVersion' => $mcVersion, 'loader' => $loader];
        }
    }

    return ['mcVersion' => $mcVersion, 'loader' => $loader, '_debug' => $debug];
}

function formatBytes($bytes, $precision = 2)
{
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function safeContainerName(string $name): string
{
    $sanitized = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '-', $name));
    $sanitized = trim($sanitized, '-_');
    if ($sanitized === '') {
        $sanitized = 'mcmm-server-' . substr(md5($name . microtime()), 0, 6);
    }
    return $sanitized;
}

function dockerExists(string $name): bool
{
    $out = [];
    $exitCode = 1;
    exec('docker ps -a --format "{{.Names}}" | grep -Fx ' . escapeshellarg($name), $out, $exitCode);
    return $exitCode === 0;
}

function getContainerModsDir(string $containerId): ?string
{
    // Get the /data mount point
    $cmd = "docker inspect -f '{{range .Mounts}}{{if eq .Destination \"/data\"}}{{.Source}}{{end}}{{end}}' " . escapeshellarg($containerId);
    $path = trim(shell_exec($cmd));
    if ($path && is_dir($path)) {
        return $path . "/mods";
    }
    return null;
}

function saveModMetadata($serverId, $modId, $platform, $modName, $fileName, $fileId = null, $logo = null, $extraData = [])
{
    $serversDir = '/boot/config/plugins/mcmm/servers';
    $metaFile = "$serversDir/$serverId/installed_mods.json";

    dbg("Saving metadata for mod $modId ($modName) to $metaFile");

    // Load existing data
    $modsData = [];
    if (file_exists($metaFile)) {
        $content = file_get_contents($metaFile);
        $modsData = json_decode($content, true) ?: [];
    }

    // Base mod info
    $modInfo = [
        'modId' => $modId,
        'name' => $modName,
        'platform' => $platform,
        'fileName' => $fileName,
        'fileId' => $fileId,
        'logo' => $logo,
        'installedAt' => time()
    ];

    // Merge extra data
    if (!empty($extraData)) {
        $modInfo = array_merge($modInfo, $extraData);
    }

    $modsData[$modId] = $modInfo;

    $dir = dirname($metaFile);
    if (!is_dir($dir)) {
        dbg("Creating metadata directory: $dir");
        if (!@mkdir($dir, 0755, true)) {
            dbg("ERROR: Failed to create directory $dir");
        }
    }

    $json = json_encode($modsData, JSON_PRETTY_PRINT);
    if (file_put_contents($metaFile, $json) === false) {
        dbg("ERROR: Failed to write metadata to $metaFile");
    } else {
        dbg("Successfully saved metadata for mod $modId");
    }
}

function buildEnvArgs(array $env): string
{
    $parts = [];
    foreach ($env as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $parts[] = '-e ' . escapeshellarg($key . '=' . $value);
    }
    return implode(' ', $parts);
}

// Normalize boolean-ish inputs (e.g., "false", "0", "", null) to bool
function boolInput($value, $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if ($value === null) {
        return (bool) $default;
    }
    $v = is_string($value) ? strtolower(trim($value)) : $value;
    if ($v === '' || $v === '0' || $v === 0 || $v === 'false' || $v === 'off' || $v === 'no') {
        return false;
    }
    return (bool) $v;
}

// Parse memory strings like "8G", "512M", "1.5GiB" into MB
function parseMemoryToMB($val): float
{
    $v = trim((string) $val);
    if ($v === '') {
        return 0;
    }
    $num = floatval($v);
    $unit = strtolower(preg_replace('/[0-9\.\s]/', '', $v));
    switch ($unit) {
        case 'gib':
        case 'g':
            return $num * 1024;
        case 'mib':
        case 'm':
            return $num;
        case 'kib':
        case 'k':
            return $num / 1024;
        case 'tib':
        case 't':
            return $num * 1024 * 1024;
        default:
            return $num; // assume MB if no unit
    }
}

/**
 * Get Java heap used (MB) via jcmd GC.heap_info inside the container.
 * This matches what users mean by "allocated 12G" (heap cap) and will not exceed Xmx.
 */
function getJavaHeapUsedMb(string $containerId, int $cacheTtlSec = 4): float
{
    $stateDir = '/tmp/mcmm_heap';
    if (!is_dir($stateDir)) {
        @mkdir($stateDir, 0777, true);
    }
    $stateFile = $stateDir . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $containerId) . '.json';
    $now = time();

    if (file_exists($stateFile)) {
        $prev = json_decode(@file_get_contents($stateFile), true);
        if (is_array($prev) && isset($prev['ts'], $prev['used_mb']) && ($now - intval($prev['ts'])) <= $cacheTtlSec) {
            return floatval($prev['used_mb']);
        }
    }

    // Call jcmd and parse in PHP to avoid fragile shell quoting.
    $cmd = 'docker exec ' . escapeshellarg($containerId) . ' sh -c ' . escapeshellarg(
        'PID="$(pidof java 2>/dev/null | awk \'{print $1}\')" ; ' .
        'if [ -z "$PID" ]; then exit 0; fi; ' .
        'command -v jcmd >/dev/null 2>&1 || exit 0; ' .
        'jcmd "$PID" GC.heap_info 2>/dev/null'
    );
    $raw = (string) @shell_exec($cmd . ' 2>/dev/null');

    $usedKb = 0.0;
    $totalKb = 0.0;
    $pid = 0;
    if (preg_match('/pidof java.*?\\s(\\d+)/', $raw, $m)) {
        $pid = intval($m[1]);
    }

    // Typical line: "garbage-first heap   total 4194304K, used 1234567K"
    if (preg_match('/total\\s+([0-9.]+)\\s*([KMG])\\s*,\\s*used\\s+([0-9.]+)\\s*([KMG])/i', $raw, $m)) {
        $tNum = floatval($m[1]);
        $tUnit = strtoupper($m[2]);
        $uNum = floatval($m[3]);
        $uUnit = strtoupper($m[4]);
        $toKb = function (float $n, string $u): float {
            if ($u === 'G') {
                return $n * 1024 * 1024;
            }
            if ($u === 'M') {
                return $n * 1024;
            }
            return $n; // K
        };
        $totalKb = $toKb($tNum, $tUnit);
        $usedKb = $toKb($uNum, $uUnit);
    } elseif (preg_match('/used\\s+([0-9.]+)\\s*([KMG])/i', $raw, $m)) {
        $uNum = floatval($m[1]);
        $uUnit = strtoupper($m[2]);
        if ($uUnit === 'G') {
            $usedKb = $uNum * 1024 * 1024;
        } elseif ($uUnit === 'M') {
            $usedKb = $uNum * 1024;
        } else {
            $usedKb = $uNum;
        }
    }

    $usedMb = $usedKb > 0 ? ($usedKb / 1024.0) : 0.0;

    @file_put_contents($stateFile, json_encode(['ts' => $now, 'used_mb' => $usedMb, 'pid' => $pid]));
    return $usedMb;
}

/**
 * Read cgroup stats for a container without invoking docker stats.
 * Supports cgroup v2 and v1 layouts.
 */
function getCgroupStats(string $cid, ?float $configuredMemMb = null): array
{
    static $cpuCount = null;
    if ($cpuCount === null) {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        $cpuCount = $cpuinfo ? substr_count($cpuinfo, 'processor') : 1;
        if ($cpuCount < 1) {
            $cpuCount = 1;
        }
    }

    $paths = [
        "/sys/fs/cgroup/docker/$cid/",
        "/sys/fs/cgroup/system.slice/docker-$cid.scope/",
        "/sys/fs/cgroup/$cid/",
    ];

    $base = null;
    foreach ($paths as $p) {
        if (is_dir($p)) {
            $base = $p;
            break;
        }
    }
    if (!$base) {
        $matches = glob("/sys/fs/cgroup/docker/$cid*");
        if ($matches && is_dir($matches[0])) {
            $base = rtrim($matches[0], '/') . '/';
        }
    }

    $memUsedMb = 0;
    $memCapMb = 0;
    $memPercent = 0;

    if ($base) {
        // cgroup v2
        $memCurrent = @file_get_contents($base . 'memory.current');
        $memMax = @file_get_contents($base . 'memory.max');
        if ($memCurrent !== false) {
            $memUsedMb = floatval($memCurrent) / (1024 * 1024);
        }
        if ($memMax !== false && trim($memMax) !== 'max') {
            $memCapMb = floatval($memMax) / (1024 * 1024);
        }
        // cgroup v1 fallback
        if ($memUsedMb <= 0) {
            $memUsage = @file_get_contents($base . 'memory.usage_in_bytes');
            if ($memUsage !== false) {
                $memUsedMb = floatval($memUsage) / (1024 * 1024);
            }
        }
        if ($memCapMb <= 0) {
            $memLimit = @file_get_contents($base . 'memory.limit_in_bytes');
            if ($memLimit !== false && trim($memLimit) !== 'max') {
                $memCapMb = floatval($memLimit) / (1024 * 1024);
            }
        }
    }

    if ($configuredMemMb && $configuredMemMb > 0) {
        $memCapMb = $configuredMemMb;
    }
    if ($memCapMb > 0 && $memUsedMb >= 0) {
        $memPercent = ($memUsedMb / $memCapMb) * 100;
    }

    // CPU usage via cgroup cpu.stat (v2) or cpuacct.usage (v1) with delta
    $cpuPercent = 0;
    $usageVal = 0;
    $isV2 = false;
    if ($base) {
        $cpuStat = @file_get_contents($base . 'cpu.stat');
        if ($cpuStat !== false) {
            $isV2 = true;
            foreach (explode("\n", trim($cpuStat)) as $line) {
                if (strpos($line, 'usage_usec') === 0) {
                    $parts = explode(' ', $line);
                    if (isset($parts[1])) {
                        $usageVal = floatval($parts[1]) * 1000; // to ns
                    }
                }
            }
        }
        if (!$isV2) {
            $cpuAcct = @file_get_contents($base . 'cpuacct.usage');
            if ($cpuAcct !== false) {
                $usageVal = floatval(trim($cpuAcct)); // ns
            }
        }
    }

    if ($usageVal > 0) {
        $stateDir = '/tmp/mcmm_cpu';
        if (!is_dir($stateDir)) {
            @mkdir($stateDir, 0777, true);
        }
        $stateFile = $stateDir . '/' . $cid . '.json';
        $now = microtime(true);
        $prev = null;
        if (file_exists($stateFile)) {
            $prev = json_decode(file_get_contents($stateFile), true);
        }
        file_put_contents($stateFile, json_encode(['ts' => $now, 'usage' => $usageVal]));
        if ($prev && isset($prev['ts'], $prev['usage'])) {
            $dt = $now - $prev['ts'];
            $du = $usageVal - $prev['usage']; // ns
            if ($dt > 0 && $du > 0) {
                $cpuPercent = ($du / 1e9) / $dt * 100 / $cpuCount;
            }
        }
    }

    return [
        'mem_used_mb' => $memUsedMb,
        'mem_cap_mb' => $memCapMb,
        'mem_percent' => $memPercent,
        'cpu_percent' => $cpuPercent
    ];
}

/**
 * Find the next available host port starting at $start (inclusive).
 * Checks docker ps for any mapping to 25565/tcp and avoids those host ports.
 */
function findAvailablePort(int $start = 25565): int
{
    $used = [];
    $dockerBin = file_exists('/usr/bin/docker') ? '/usr/bin/docker' : 'docker';
    $output = shell_exec($dockerBin . ' ps --format "{{.Ports}}" 2>/dev/null');
    if ($output) {
        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            if (preg_match_all('/(\d+)->25565\/tcp/', $line, $matches)) {
                foreach ($matches[1] as $p) {
                    $used[(int) $p] = true;
                }
            }
        }
    }

    $port = $start;
    for ($i = 0; $i < 100; $i++) { // search up to 100 increments
        if ($port >= 1 && $port <= 65535 && !isset($used[$port])) {
            return $port;
        }
        $port++;
    }
    // Fallback: return original even if busy
    return $start;
}

// Parse label value from docker ps labels string: key1=val1,key2=val2
function getLabelValue(string $labels, string $key): ?string
{
    if ($labels === '') {
        return null;
    }
    foreach (explode(',', $labels) as $pair) {
        $parts = explode('=', $pair, 2);
        if (count($parts) === 2 && trim($parts[0]) === $key) {
            return trim($parts[1]);
        }
    }
    return null;
}

// Backfill server icon for existing containers without config files
function backfillServerIcon(string $containerId, string $containerName, string $serversDir, array $config): string
{
    // Inspect container to get environment variables
    $inspectCmd = '/usr/bin/docker inspect ' . escapeshellarg($containerId) . ' 2>/dev/null';
    $inspectOutput = shell_exec($inspectCmd);
    if (!$inspectOutput) {
        return '';
    }

    $inspectData = json_decode($inspectOutput, true);
    if (!$inspectData || !isset($inspectData[0])) {
        return '';
    }

    $containerInfo = $inspectData[0];
    $env = $containerInfo['Config']['Env'] ?? [];

    // Extract relevant environment variables
    $cfSlug = '';
    $cfFileId = '';
    $modpackId = '';

    foreach ($env as $envVar) {
        if (strpos($envVar, 'CF_SLUG=') === 0) {
            $cfSlug = substr($envVar, 8);
        } elseif (strpos($envVar, 'CF_FILE_ID=') === 0) {
            $cfFileId = substr($envVar, 11);
        } elseif (strpos($envVar, 'CF_MODPACK_ID=') === 0) {
            $modpackId = substr($envVar, 14);
        }
    }

    // If we don't have a slug or modpack ID, we can't fetch the icon
    if (empty($cfSlug) && empty($modpackId)) {
        return '';
    }

    // Try to fetch modpack info from CurseForge
    $apiKey = $config['curseforge_api_key'] ?? '';
    if (empty($apiKey)) {
        return '';
    }

    $icon = '';
    $modpackName = '';

    // If we have a slug, search for the modpack
    if ($cfSlug) {
        $searchUrl = 'https://api.curseforge.com/v1/mods/search?gameId=432&classId=4471&slug=' . urlencode($cfSlug);
        $response = cfRequest('/mods/search?gameId=432&classId=4471&slug=' . urlencode($cfSlug), $apiKey);

        if ($response && isset($response['data'][0])) {
            $modpack = $response['data'][0];
            $icon = $modpack['logo']['url'] ?? '';
            $modpackName = $modpack['name'] ?? '';
            $modpackId = $modpack['id'] ?? '';
        }
    }

    // If we have a modpack ID but no icon yet, fetch by ID
    if (empty($icon) && $modpackId) {
        $response = cfRequest('/mods/' . intval($modpackId), $apiKey);

        if ($response && isset($response['data'])) {
            $modpack = $response['data'];
            $icon = $modpack['logo']['url'] ?? '';
            $modpackName = $modpack['name'] ?? '';
        }
    }

    // If we found an icon, save the config file for future use
    if ($icon) {
        $serverId = md5($containerName); // Generate a deterministic ID based on container name
        $serverDir = $serversDir . '/' . $serverId;

        if (!is_dir($serverDir)) {
            @mkdir($serverDir, 0755, true);
        }

        $serverConfig = [
            'id' => $serverId,
            'name' => $containerName,
            'modpack' => $modpackName,
            'slug' => $cfSlug,
            'modpackId' => $modpackId,
            'logo' => $icon,
            'containerName' => $containerName,
            'backfilled' => true
        ];

        @file_put_contents($serverDir . '/config.json', json_encode($serverConfig, JSON_PRETTY_PRINT));
    }

    return $icon;d
}
