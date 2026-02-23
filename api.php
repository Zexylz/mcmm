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
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/include/lib.php';
require_once __DIR__ . '/include/docker_hub.php';
require_once __DIR__ . '/include/api_handlers.php';

use Aternos\CurseForgeApi\Client\CurseForgeAPIClient;
use Aternos\CurseForgeApi\Client\Options\ModSearch\ModSearchOptions;
use Aternos\CurseForgeApi\Client\Options\ModSearch\ModLoaderType;
use Aternos\CurseForgeApi\Client\Options\ModFiles\ModFilesOptions;
use Aternos\ModrinthApi\Client\ModrinthAPIClient;
use Aternos\CurseForgeApi\Client\CursedFingerprintHelper;
use Aternos\ModrinthApi\Client\Options\ProjectSearchOptions;
use Aternos\ModrinthApi\Client\Options\SearchIndex;
use Aternos\ModrinthApi\Client\Options\Facets\Facet;
use Aternos\ModrinthApi\Client\Options\Facets\FacetANDGroup;
use Aternos\ModrinthApi\Client\Options\Facets\FacetType;


// EMERGENCY DEBUG
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$hasToken = isset($_SESSION['csrf_token']) ? 'YES' : 'NO';
$sid = session_id();
$inputLen = strlen(file_get_contents('php://input'));
$ctype = $_SERVER['CONTENT_TYPE'] ?? 'NONE';
file_put_contents('/tmp/mcmm_debug.txt', date('Y-m-d H:i:s') . " - HIT " . $_SERVER['REQUEST_METHOD'] . " ACTION:" . ($_GET['action'] ?? 'NONE') . " SID:$sid HAS_CSRF:$hasToken CTYPE:$ctype IN_LEN:$inputLen POST:" . json_encode($_POST) . " GET:" . json_encode($_GET) . "\n", FILE_APPEND);
session_write_close();

header('Content-Type: application/json');

// Prevent HTML errors from corrupting JSON
@ini_set('display_errors', 0);
@ini_set('log_errors', 1);

// Paths
// Standard Unraid config location
$plugin = 'mcmm';

// Centralize Data Storage
define('MCMM_BASE_DIR', '/mnt/user/appdata/mcmm');
define('MCMM_TMP_DIR', MCMM_BASE_DIR . '/tmp');
define('MCMM_LOG_DIR', MCMM_BASE_DIR . '/logs');
define('MCMM_LOG_DIR', MCMM_BASE_DIR . '/logs');
define('MCMM_SERVERS_DIR', MCMM_BASE_DIR . '/servers');
define('MCMM_CONFIG_DIR', '/boot/config/plugins/mcmm');

// Ensure directories exist
if (!is_dir(MCMM_BASE_DIR)) {
    @mkdir(MCMM_BASE_DIR, 0777, true);
}
if (!is_dir(MCMM_TMP_DIR)) {
    @mkdir(MCMM_TMP_DIR, 0777, true);
}
if (!is_dir(MCMM_LOG_DIR)) {
    @mkdir(MCMM_LOG_DIR, 0777, true);
}
if (!is_dir(MCMM_SERVERS_DIR)) {
    @mkdir(MCMM_SERVERS_DIR, 0777, true);
}
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
$sensitiveActions = ['server_control', 'server_delete', 'mod_install', 'mod_delete', 'console_command', 'backup_create', 'backup_delete', 'backup_restore', 'save_settings', 'update_system_image'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' || in_array($action, $sensitiveActions)) {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        // DETAILED DEBUG FOR CSRF FAILURE
        $sid = session_id();
        $debugMsg = date('Y-m-d H:i:s') . " - CSRF FAILURE: Action: $action, Method: " . $_SERVER['REQUEST_METHOD'] .
            " SID: $sid " .
            ", Got Token: " . (empty($token) ? '[EMPTY]' : substr($token, 0, 8) . '...') .
            ", Expected: " . (empty($_SESSION['csrf_token']) ? '[EMPTY]' : substr($_SESSION['csrf_token'], 0, 8) . '...') . "\n";
        file_put_contents('/tmp/mcmm_debug.txt', $debugMsg, FILE_APPEND);

        header('HTTP/1.1 403 Forbidden');
        die(json_encode(['success' => false, 'error' => 'Invalid CSRF token', 'debug_sid' => session_id()]));
    }
}

// Release session lock to prevent blocking concurrent requests (e.g., long polling vs settings load)
session_write_close();



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
    if (!isset($config) || !is_array($config)) {
        dbg("Warning: Config was not an array, resetting to empty.");
        $config = [];
    }
    $config = array_merge($defaults, $config);

    switch ($action) {
        case 'router_diag':
            $res = [
                'router_status' => trim(shell_exec("docker ps -a --filter name=mc-router --format '{{.Status}}'") ?? ''),
                'router_inspect' => json_decode(shell_exec("docker inspect mc-router 2>/dev/null") ?? '[]', true),
                'config' => @parse_ini_file(MCMM_CONFIG_DIR . '/mcmm.cfg'),
                'labeled_containers' => trim(shell_exec("docker ps --filter label=mc-router.host --format '{{.Names}} labels: {{.Labels}}'") ?? '(none)'),
                'port_conflict_docker' => trim(shell_exec("docker ps --format '{{.Names}}: {{.Ports}}'") ?? ''),
                'port_conflict_netstat' => trim(shell_exec("netstat -tulpn | grep :25565") ?? '(no listener on 25565)'),
                'logs' => shell_exec("docker logs --tail 50 mc-router 2>&1") ?? '(no logs)'
            ];
            jsonResponse(['success' => true, 'data' => $res]);
            break;

        case 'router_status':
            $apiPort = intval($config['mc_router_api_port'] ?? 25564);
            $psOut = shell_exec("docker ps --filter name=mc-router --filter status=running --format '{{.Names}}'");
            $routerRunning = trim($psOut ?? '') !== '';
            $routesRaw = @file_get_contents("http://localhost:{$apiPort}/routes");
            $routes = $routesRaw ? json_decode($routesRaw, true) : [];
            $payload = ['running' => $routerRunning, 'routes' => $routes ?: new stdClass()];
            jsonResponse(['success' => true, 'data' => $payload]);
            break;

        case 'ping':
            handle_ping();
            break;

        case 'get_log':
            handle_get_log();
            break;

        case 'settings':
            handle_settings_get($config);
            break;

        case 'save_settings':
            handle_settings_save($config, $defaults, $configPath, $configDir);
            break;

        case 'console_logs':
            handle_console_logs();
            break;

        case 'console_command':
            handle_console_command();
            break;

        case 'server_control':
            handle_server_control();
            break;

        case 'server_delete':
            handle_server_delete();
            break;

        case 'push_metrics':
            handle_push_metrics();
            break;

        case 'force_update_check':
            handle_force_update_check();
            break;

        case 'check_updates':
            handle_check_updates($_GET['id'] ?? '', $config);
            break;

        case 'check_global_image_update':
            handle_check_global_image();
            break;

        case 'update_system_image':
            handle_update_system_image();
            break;

        case 'get_update_progress':
            handle_get_update_progress();
            break;

        case 'run_diag':
            include __DIR__ . '/server_diag.php';
            jsonResponse(['success' => true, 'message' => 'Diagnostics executed']);
            break;

        case 'servers':
            handle_list_servers($config);
            break;

        case 'players':
            $id = $_GET['id'] ?? '';
            $port = intval($_GET['port'] ?? 25565);
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Missing ID'], 400);
            }

            $env = getContainerEnv($id);
            $onlineData = getOnlinePlayers($id, $port, $env);
            $bannedList = getBannedPlayers($id, $env);

            jsonResponse([
                'success' => true,
                'data' => [
                    'online' => $onlineData['players'], // List of players for "online" tab
                    'banned' => $bannedList,
                    'history' => [] // Todo: history implementation
                ]
            ]);
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
            handle_server_banned_players();
            break;

        case 'server_player_action':
            handle_server_player_action();
            break;

        case 'search_modpacks':
        case 'modpacks':
            handle_modpacks($config);
            break;

        case 'detect_java_version':
            handle_detect_java_version();
            break;

        case 'deploy':
            handleDeploy($config, $defaults);
            break;

        case 'server_get':
        case 'server_details':
            handle_server_details($config);
            break;

        case 'server_update':
            handle_server_update();
            break;

        // Mod Manager Actions
        case 'mod_search':
            handle_mod_search($config);
            break;

        case 'start_agents':
            handle_start_agents();
            break;

        case 'agent_debug':
            handle_agent_debug();
            break;

        case 'debug_backups':
            handle_debug_backups();
            break;

        case 'backups_list':
            handle_backups_list();
            break;

        case 'backup_create':
            handle_backup_create();
            break;

        case 'backup_delete':
            handle_backup_delete();
            break;

        case 'backup_reinstall':
            handle_backup_reinstall();
            break;

        case 'mod_list':
        case 'mods_list':
            handle_mod_list($_GET['id'] ?? '', $config);
            break;

        case 'mod_identify_batch':
            handle_mod_identify_batch($_GET['id'] ?? '', $config);
            break;


        case 'mod_files':
            handle_mod_files($_GET['id'] ?? '', $config);
            break;


        case 'mod_install':
            handle_mod_install($_GET['id'] ?? '', $config);
            break;

        case 'identify_mod':
            handle_identify_mod($_GET['id'] ?? '', $config);
            break;
        case 'import_manifest':
            handle_import_manifest($_REQUEST['id'] ?? '', $config);
            break;


        case 'mod_delete':
            handle_mod_delete($_GET['id'] ?? '');
            break;



        case 'mods_check_updates':
            handle_mods_check_updates($_GET['id'] ?? '', $config);
            break;

        case 'mod_update':
            handle_mod_update($_REQUEST['id'] ?? '', $config);
            break;

        default:
            jsonResponse(['success' => false, 'error' => 'Unknown action: ' . $action], 404);
    }
} catch (Throwable $e) {
    dbg("Fatal Error: " . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Server Error: ' . $e->getMessage()], 500);
}
