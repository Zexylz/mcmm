<?php

/**
 * MCMM API Library
 * Contains all helper functions for the MCMM API.
 */

// Resolve host data directory for a container by inspecting the /data mount.
// Falls back to /mnt/user/appdata/<name> if not found.
function getContainerDataDir(string $containerName, string $containerId = '')
{
    if ($containerId) {
        $cmd = "docker inspect -f '{{range .Mounts}}{{if eq .Destination \"/data\"}}{{.Source}}{{end}}{{end}}' " . escapeshellarg($containerId);
        $path = trim((string) shell_exec($cmd));
        if ($path && is_dir($path)) {
            return $path;
        }
    }
    $path = "/mnt/user/appdata/" . $containerName;
    if (is_dir($path)) {
        return $path;
    }
    return null;
}

// Get container stats from docker stats command as a fallback.
function getContainerCgroupStats(string $containerId)
{
    $cmd = '/usr/bin/docker stats --no-stream --format "{{.CPUPerc}}|{{.MemUsage}}" ' . escapeshellarg($containerId);
    $output = shell_exec($cmd . ' 2>/dev/null');
    if ($output) {
        $parts = explode('|', trim($output));
        if (count($parts) === 2) {
            $cpuPercent = floatval(str_replace('%', '', $parts[0]));
            $memParts = explode(' / ', $parts[1]);
            $usedStr = $memParts[0];
            $usedMb = parseMemoryToMB($usedStr);
            $limitStr = $memParts[1] ?? '0';
            $limitMb = parseMemoryToMB($limitStr);
            return [
                'cpu_percent' => $cpuPercent,
                'mem_used_mb' => $usedMb,
                'mem_cap_mb' => $limitMb
            ];
        }
    }
    return [
        'cpu_percent' => 0,
        'mem_used_mb' => 0,
        'mem_cap_mb' => 0
    ];
}

// Read agent metrics from mcmm_metrics.json in the container data dir.
function readAgentMetrics(string $containerName, string $containerId = '', string $dataDir = '')
{
    if (!$dataDir) {
        $dataDir = getContainerDataDir($containerName, $containerId);
    }
    if (!$dataDir) {
        return null;
    }
    $metricsFile = rtrim($dataDir, '/') . '/mcmm_metrics.json';
    if (!file_exists($metricsFile)) {
        return null;
    }
    $content = file_get_contents($metricsFile);
    if (!$content) {
        return null;
    }
    $data = json_decode($content, true);
    if (!$data) {
        return null;
    }
    // Check for mtime to ensure it's not stale (1 minute)
    if (time() - filemtime($metricsFile) > 60) {
        return null;
    }
    return $data;
}

// Ensure metrics agent script exists in data dir and start it inside container
function ensureMetricsAgent(string $containerName, string $containerId, string $dataDir)
{
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
    # 1. RSS
    RSS_KB=$(awk '/VmRSS/ {print $2}' /proc/$PID/status 2>/dev/null)
    # 2. Heap
    HEAP_USED_KB=0
    if command -v jstat >/dev/null 2>&1; then
      STATS=$(jstat -gc "$PID" 1 1 | tail -n 1)
      HEAP_USED_KB=$(echo "$STATS" | awk '{print int($3 + $4 + $6 + $8)}')
    fi
    # 3. CPU
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
  fi
  sleep $INTERVAL
done
BASH;
    if (!file_exists($scriptPath)) {
        file_put_contents($scriptPath, $script);
        chmod($scriptPath, 0755);
    }
    // Try to start it in background if not running
    $checkCmd = "docker exec " . escapeshellarg($containerId) . " ps aux | grep mcmm_agent.sh | grep -v grep";
    $running = shell_exec($checkCmd);
    if (!$running) {
        $startCmd = "docker exec -d " . escapeshellarg($containerId) . " sh -c \"nohup /data/mcmm_agent.sh > /data/mcmm_agent.log 2>&1 &\"";
        shell_exec($startCmd);
    }
}

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
                        for ($i = 0; $i < count($elem2); $i++) {
                            $content .= $key2 . "[] = \"" . $elem2[$i] . "\"\n";
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
                    for ($i = 0; $i < count($elem); $i++) {
                        $content .= $key . "[] = \"" . $elem[$i] . "\"\n";
                    }
                } elseif ($elem == "") {
                    $content .= $key . " = \n";
                } else {
                    $content .= $key . " = \"" . $elem . "\"\n";
                }
            }
        }

        if (!$handle = fopen($path, 'w')) {
            return false;
        }

        $success = fwrite($handle, $content);
        fclose($handle);

        return $success;
    }
}

// Debug log function
function dbg($msg)
{
<<<<<<< HEAD
    $logFile = '/tmp/mcmm.log';
=======
    $logFile = dirname(__DIR__) . '/mcmm.log';
>>>>>>> 1aaf0a4e21e0718a6efba40976e17f83460360f4
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $msg\n", FILE_APPEND);
}

function getRequestData()
{
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if (!$data) {
        $data = $_POST;
    }
    return $data;
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

    return $icon;
}

function jsonResponse($data, int $statusCode = 200): void
{
    http_response_code($statusCode);
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
                    $loaderMapSize = [
                        'forge' => 1,
                        'fabric' => 4,
                        'quilt' => 5,
                        'neoforge' => 6
                    ];
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'User-Agent: MCMM/1.0 (mcmm@v-severe.com)']);

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
    $port = findAvailablePort($port);

    $serverIp = trim($input['server_ip'] ?? $config['default_ip'] ?? $defaults['default_ip'] ?? '0.0.0.0');
    $memory = trim($input['memory'] ?? $config['default_memory'] ?? $defaults['default_memory']);
    $maxPlayers = intval($input['max_players'] ?? $config['default_max_players'] ?? $defaults['default_max_players'] ?? 20);
    $jvmFlags = trim($input['jvm_flags'] ?? $config['jvm_flags'] ?? '');
    $whitelist = trim($input['whitelist'] ?? $config['default_whitelist'] ?? '');
    $iconUrl = trim($input['icon_url'] ?? $config['default_icon_url'] ?? '');
    $javaVer = trim($input['java_version'] ?? '');

    [$resolvedFileId, $downloadUrl] = getModpackDownload($modId, $config['curseforge_api_key'], $fileId ?: null);
    if (!$downloadUrl) {
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
        if ($javaVer !== '8') {
            $env['JVM_OPTS'] = trim(($env['JVM_OPTS'] ?? '') . ' -XX:+UseZGC');
        } else {
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

    $dockerBin = file_exists('/usr/bin/docker') ? '/usr/bin/docker' : 'docker';
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
        @file_put_contents('/tmp/mcmm_deploy_fail.log', "CMD: $cmd\nExit: $exitCode\nOutput:\n" . implode("\n", $output) . "\n\n", FILE_APPEND);
        jsonResponse([
            'success' => false,
            'error' => 'Docker failed to create the server. See output for details.',
            'output' => $output
        ], 500);
    }

    ensureMetricsAgent($containerName, trim($output[0] ?? $containerName), $dataDir);

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
        'logo' => $iconUrl,
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
