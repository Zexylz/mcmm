<?php

/**
 * MCMM API Library
 *
 * Core library containing helper functions for configuration, Docker interaction,
 * metrics gathering, and caching.
 */
/**
 * Get the total number of logical CPU cores on the system.
 *
 * @return int Number of logical CPU cores.
 */
function getSystemCpuCount(): int
{
    static $cpuCount = null;
    if ($cpuCount === null) {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuinfo) {
            $cpuCount = substr_count($cpuinfo, 'processor');
        } else {
            $cpuCount = intval(shell_exec('nproc 2>/dev/null') ?: 1);
        }
        if ($cpuCount < 1) {
            $cpuCount = 1;
        }
    }
    return $cpuCount;
}

/**
 * Resolve the host data directory for a container.
 *
 * Inspects Docker mounts for the /data destination or falls back to standard paths.
 *
 * @param string $containerName The name of the container.
 * @param string $containerId   Optional container ID for more accurate inspection.
 * @return string|null The resolved host-side path or null if not found.
 */
function getContainerDataDir(string $containerName, string $containerId = '')
{
    $dockerBin = file_exists('/usr/bin/docker') ? '/usr/bin/docker' : 'docker';
    if ($containerId) {
        $cmd = "$dockerBin inspect -f '{{range .Mounts}}{{if eq .Destination \"/data\"}}{{.Source}}{{end}}{{end}}' " . escapeshellarg($containerId);
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

/**
 * Resolve host data directory using only the container ID.
 *
 * @param string $id The container ID (short or long).
 * @return string|null The resolved host-side path or null if not found.
 */
function getContainerDataDirById(string $id): ?string
{
    // First try standard inspection for /data mount
    $dockerBin = file_exists('/usr/bin/docker') ? '/usr/bin/docker' : 'docker';
    $cmd = "$dockerBin inspect -f '{{range .Mounts}}{{if eq .Destination \"/data\"}}{{.Source}}{{end}}{{end}}' " . escapeshellarg($id);
    $path = trim((string) shell_exec($cmd));
    if ($path && is_dir($path)) {
        return $path;
    }

    // If not found, try to get name and infer path
    $nameCmd = "$dockerBin inspect -f '{{.Name}}' " . escapeshellarg($id);
    $name = trim((string) shell_exec($nameCmd), '/');
    if ($name) {
        $path = "/mnt/user/appdata/" . $name;
        if (is_dir($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * Load mod metadata from modpack manifest files.
 *
 * Supports minecraftinstance.json (CurseForge/FTB) and manifest.json.
 *
 * @param string $dataDir The directory containing the manifest files.
 * @return array Mapping of mod IDs to metadata.
 */
function loadModsFromManifest(string $dataDir): array
{
    $mods = [];

    // 1. Try CurseForge/FTB App minecraftinstance.json (Best Source)
    $instFile = rtrim($dataDir, '/') . '/minecraftinstance.json';
    if (file_exists($instFile)) {
        $data = json_decode(file_get_contents($instFile), true);
        if ($data && !empty($data['installedAddons'])) {
            $mcVersion = $data['baseModLoader']['minecraftVersion'] ?? 'Unknown';
            foreach ($data['installedAddons'] as $addon) {
                if (!empty($addon['installedFile'])) {
                    $f = $addon['installedFile'];
                    $modId = $addon['addonID'];
                    $fileName = $f['fileName'] ?? '';
                    $mods[$modId] = [
                        'projectID' => $modId,
                        'fileID' => $f['id'] ?? null,
                        'name' => $addon['name'] ?? '',
                        'fileName' => $fileName,
                        'mcVersion' => $mcVersion
                    ];
                }
            }
            return $mods;
        }
    }

    // 2. Try CurseForge manifest.json (Export format, less likely in running server but possible)
    $manFile = rtrim($dataDir, '/') . '/manifest.json';
    if (file_exists($manFile)) {
        $data = json_decode(file_get_contents($manFile), true);
        if ($data && !empty($data['files'])) {
            $mcVersion = $data['minecraft']['version'] ?? 'Unknown';
            foreach ($data['files'] as $file) {
                // Manifest only has projectID and fileID, no filenames usually.
                // We can only use this to confirm IDs if we fetch details later.
                $modId = $file['projectID'];
                $mods[$modId] = [
                    'projectID' => $modId,
                    'fileID' => $file['fileID'],
                    'fileName' => '', // Unknown in this format
                    'name' => '', // Unknown
                    'mcVersion' => $mcVersion
                ];
            }
        }
    }

    return $mods;
}

/**
 * Get the full 64-character container ID.
 *
 * @param string $idOrName Short ID or container name.
 * @return string|null The full container ID or null on failure.
 */
function getContainerLongId(string $idOrName): ?string
{
    $dockerBin = file_exists('/usr/bin/docker') ? '/usr/bin/docker' : 'docker';
    $cmd = "$dockerBin inspect -f '{{.Id}}' " . escapeshellarg($idOrName);
    $longId = trim((string) @shell_exec($cmd . ' 2>/dev/null'));
    return !empty($longId) ? $longId : null;
}

/**
 * Retrieve container stats using the 'docker stats' command as a fallback.
 *
 * @param string $containerId The container ID.
 * @return array Array containing cpu_percent, mem_used_mb, and mem_cap_mb.
 */
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

/**
 * Read agent metrics from the local metrics JSON file.
 *
 * Tries the host filesystem first, then falls back to direct container access.
 *
 * @param string $containerName Container name.
 * @param string $containerId   Optional container ID.
 * @param string $dataDir       Optional data directory path.
 * @return array|null Decoded metrics or null if not found or stale.
 */
function readAgentMetrics(string $containerName, string $containerId = '', string $dataDir = '')
{
    $dockerBin = file_exists('/usr/bin/docker') ? '/usr/bin/docker' : 'docker';
    if (!$dataDir) {
        $dataDir = getContainerDataDir($containerName, $containerId);
    }

    $data = null;

    // Attempt 1: Host File (Fastest)
    if ($dataDir) {
        $metricsFile = rtrim($dataDir, '/') . '/mcmm_metrics.json';
        if (file_exists($metricsFile)) {
            $content = @file_get_contents($metricsFile);
            if ($content) {
                $decoded = json_decode($content, true);
                // Check if not stale (5 mins)
                if ($decoded && (time() - filemtime($metricsFile) < 300)) {
                    $data = $decoded;
                }
            }
        }
    }

    // Attempt 2: Container Direct (Most reliable, fallback)
    if (!$data && $containerId) {
        $cmd = "$dockerBin exec " . escapeshellarg($containerId) . " cat /tmp/mcmm_metrics.json 2>/dev/null";
        $content = trim((string) @shell_exec($cmd));
        if ($content && strpos($content, '{') === 0) {
            $decoded = json_decode($content, true);
            if ($decoded && isset($decoded['ts'])) {
                // Check if not stale (5 mins)
                if (time() - intval($decoded['ts']) < 300) {
                    $data = $decoded;
                }
            }
        }
    }

    return $data;
}

/**
 * Ensure the metrics agent script exists and is running inside the container.
 *
 * @param string $containerName Container name.
 * @param string $containerId   Full container ID.
 * @param string $dataDir       Host data directory.
 */
function ensureMetricsAgent(string $containerName, string $containerId, string $dataDir)
{
    $scriptPath = rtrim($dataDir, '/') . '/mcmm_agent.sh';
    $script = <<<'BASH'
#!/bin/sh

DATA_FILE="/data/mcmm_metrics.json"
INTERVAL=1

CPU_USAGE_PREV_US=""
TS_PREV_NS=""

# Read total CPU usage for the cgroup in microseconds
get_cgroup_cpu_us() {
  if [ -r /sys/fs/cgroup/cpu.stat ]; then
    while read -r key value _; do
      if [ "$key" = "usage_usec" ]; then
        echo "$value"
        return
      fi
    done < /sys/fs/cgroup/cpu.stat
  elif [ -r /sys/fs/cgroup/cpuacct/cpuacct.usage ]; then
    read -r ns < /sys/fs/cgroup/cpuacct/cpuacct.usage
    echo $((ns / 1000))
    return
  fi
}

# Monotonic timestamp in nanoseconds
get_now_ns() {
  if [ -r /proc/uptime ]; then
    read -r uptime _ < /proc/uptime
    awk -v t="$uptime" 'BEGIN{printf "%.0f", t * 1000000000}'
  else
    date +%s%N
  fi
}

while :; do
  PID=$(pidof java 2>/dev/null | awk '{print $1}')
  [ -z "$PID" ] && PID=$(pgrep -f "java.*\.jar" | head -n 1)
  
  if [ -z "$PID" ] || [ ! -d "/proc/$PID" ]; then
    echo "$(date) Waiting for Java process..." >> /data/mcmm_agent.log
    sleep 5
    continue
  fi

  # 1. Memory Metrics (High Precision)
  RSS_KB=0
  PSS_KB=0
  
  if [ -r "/proc/$PID/smaps_rollup" ]; then
    STATS=$(awk '/^Rss:|^Pss:/ {print $1, $2}' "/proc/$PID/smaps_rollup" 2>/dev/null)
    RSS_KB=$(echo "$STATS" | awk '/^Rss:/ {print $2}')
    PSS_KB=$(echo "$STATS" | awk '/^Pss:/ {print $2}')
  fi

  # Fallback RSS
  if [ -z "$RSS_KB" ] || [ "$RSS_KB" -le 0 ]; then
    RSS_KB=$(awk '/VmRSS:/ {print $2}' "/proc/$PID/status" 2>/dev/null)
    [ -z "$RSS_KB" ] && RSS_KB=0
  fi
  # If PSS failed, use RSS
  if [ -z "$PSS_KB" ] || [ "$PSS_KB" -le 0 ]; then
    PSS_KB=$RSS_KB
  fi

  # 2. Container "Working Set"
  WS_KB=0
  if [ -r "/sys/fs/cgroup/memory.current" ]; then
    CUR=$(cat /sys/fs/cgroup/memory.current)
    INA=$(awk '/inactive_file/ {print $2}' /sys/fs/cgroup/memory.stat 2>/dev/null || echo "0")
    WS_KB=$(( (CUR - INA) / 1024 ))
  elif [ -r "/sys/fs/cgroup/memory/memory.usage_in_bytes" ]; then
    CUR=$(cat /sys/fs/cgroup/memory/memory.usage_in_bytes)
    INA=$(awk '/total_inactive_file/ {print $2}' /sys/fs/cgroup/memory/memory.stat 2>/dev/null || echo "0")
    WS_KB=$(( (CUR - INA) / 1024 ))
  fi

  # 3. Heap Metrics
  HEAP_USED_KB=0
  if command -v jstat >/dev/null 2>&1; then
    HEAP_USED_KB=$(jstat -gc "$PID" 1 1 2>/dev/null | tail -n 1 | awk '{print int($3 + $4 + $6 + $8)}')
  fi

  # 4. CPU (Milli-cores)
  CPU_US=0
  if [ -r "/sys/fs/cgroup/cpu.stat" ]; then
    CPU_US=$(awk '/usage_usec/ {print $2}' /sys/fs/cgroup/cpu.stat)
  elif [ -r "/sys/fs/cgroup/cpuacct/cpuacct.usage" ]; then
    CPU_US=$(( $(cat /sys/fs/cgroup/cpuacct/cpuacct.usage) / 1000 ))
  fi

  NOW_NS=$(date +%s%N)
  CPU_MILLI=0
  if [ -n "$CPU_USAGE_PREV_US" ] && [ "$CPU_USAGE_PREV_US" -gt 0 ]; then
    D_CPU_US=$((CPU_US - CPU_USAGE_PREV_US))
    D_TIME_NS=$((NOW_NS - TS_PREV_NS))
    if [ "$D_TIME_NS" -gt 0 ] && [ "$D_CPU_US" -ge 0 ]; then
       CPU_MILLI=$(( (D_CPU_US * 1000000) / D_TIME_NS ))
    fi
  fi
  CPU_USAGE_PREV_US="$CPU_US"
  TS_PREV_NS="$NOW_NS"

  TS=$(date +%s)
  
  # Prepare JSON
  JSON="{\"ts\":$TS,\"pid\":$PID,\"heap_used_mb\":$((HEAP_USED_KB/1024)),\"rss_mb\":$((RSS_KB/1024)),\"pss_mb\":$((PSS_KB/1024)),\"ws_mb\":$((WS_KB/1024)),\"cpu_milli\":$CPU_MILLI}"
  
  # Method 1: Push to API (Host)
  # Assuming Host IP is strictly 172.17.0.1 (Docker0) or we try to detect it.
  # UNRAID specific: The WebGUI is on the host IP. 
  # We'll try to push to the API endpoint.
  
  # Note: curl might not be installed in all minimal images, but we'll try.
  if command -v curl >/dev/null 2>&1; then
      HOSTNAME=$(hostname)
      # Wrap in payload
      PAYLOAD="{\"id\":\"$HOSTNAME\", \"metrics\":$JSON}"
      # Try standard docker host IP
      curl -s -X POST -H "Content-Type: application/json" -d "$PAYLOAD" "http://172.17.0.1/plugins/mcmm/api.php?action=push_metrics" >/dev/null 2>&1 &
  else
      # Fallback: Write to disk (Old Method)
      echo "$JSON" > "$DATA_FILE.tmp" && mv -f "$DATA_FILE.tmp" "$DATA_FILE"
  fi
  
  sleep "$INTERVAL"
done
BASH;
    // Inject script into container /tmp (Path agnostic)
    $dockerBin = file_exists('/usr/bin/docker') ? '/usr/bin/docker' : 'docker';

    // Use sh -c to write the script file into the container
    $escapedScript = escapeshellarg($script);
    $injectCmd = "$dockerBin exec " . escapeshellarg($containerId) . " sh -c \"printf %s $escapedScript > /tmp/mcmm_agent.sh && chmod +x /tmp/mcmm_agent.sh\"";
    shell_exec($injectCmd);

    // Also write to host-side dataDir for persistence if it exists
    if ($dataDir && is_dir($dataDir)) {
        file_put_contents(rtrim($dataDir, '/') . '/mcmm_agent.sh', $script);
        chmod(rtrim($dataDir, '/') . '/mcmm_agent.sh', 0755);
    }

    // Restart it if it's running
    shell_exec("$dockerBin exec " . escapeshellarg($containerId) . " pkill -f mcmm_agent.sh 2>&1");

    // Start in background
    $startCmd = "$dockerBin exec -d " . escapeshellarg($containerId) . " sh -c \"/tmp/mcmm_agent.sh > /tmp/mcmm_agent.log 2>&1 &\"";
    $startOut = shell_exec($startCmd . " 2>&1");

    // Diagnostic log
    @mkdir('/tmp/mcmm', 0777, true);
    $logMsg = date('[Y-m-d H:i:s]') . " Telemetry Bridge Push: $containerName ($containerId)\n";
    if ($startOut) {
        $logMsg .= "Start Result: $startOut\n";
    }
    @file_put_contents('/tmp/mcmm/agent.log', $logMsg, FILE_APPEND);
}

// Helper to write INI file
if (!function_exists('write_ini_file')) {
    /**
     * Write an associative array to an INI file.
     *
     * @param array  $assoc_arr    The data to write.
     * @param string $path         The target file path.
     * @param bool   $has_sections Whether to support sections.
     * @return int|bool Number of bytes written or false on failure.
     */
    function write_ini_file($assoc_arr, $path, $has_sections = false)
    {
        $content = "";

        $formatValue = function ($val) {
            if (is_bool($val)) {
                return $val ? 'true' : 'false';
            }
            if (is_numeric($val)) {
                return $val;
            }
            if ($val === 'true' || $val === 'false') {
                return $val;
            }
            return '"' . str_replace('"', '\"', (string) $val) . '"';
        };

        if ($has_sections) {
            foreach ($assoc_arr as $key => $elem) {
                $content .= "[" . $key . "]\n";
                foreach ($elem as $key2 => $elem2) {
                    if (is_array($elem2)) {
                        foreach ($elem2 as $val) {
                            $content .= $key2 . "[] = " . $formatValue($val) . "\n";
                        }
                    } elseif ($elem2 === "") {
                        $content .= $key2 . " = \n";
                    } else {
                        $content .= $key2 . " = " . $formatValue($elem2) . "\n";
                    }
                }
            }
        } else {
            foreach ($assoc_arr as $key => $elem) {
                if (is_array($elem)) {
                    foreach ($elem as $val) {
                        $content .= $key . "[] = " . $formatValue($val) . "\n";
                    }
                } elseif ($elem === "") {
                    $content .= $key . " = \n";
                } else {
                    $content .= $key . " = " . $formatValue($elem) . "\n";
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

/**
 * Log a message to the internal debug log.
 *
 * @param string $msg The message to log.
 */
function dbg($msg)
{
    $logFile = dirname(__DIR__) . '/debug.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $msg\n", FILE_APPEND);
}

/**
 * Cache data to the local temporary cache.
 *
 * @param string $key  Cache key.
 * @param mixed  $data Data to cache.
 * @param int    $ttl  Time-to-live in seconds.
 */
function mcmm_cache_set($key, $data, $ttl = 3600)
{
    $cacheDir = '/tmp/mcmm_cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    $file = $cacheDir . '/' . md5($key) . '.json';
    $cacheData = [
        'expires' => time() + $ttl,
        'data' => $data
    ];
    @file_put_contents($file, json_encode($cacheData));
}

/**
 * Retrieve data from the local temporary cache.
 *
 * @param string $key Cache key.
 * @return mixed|null Cached data or null if not found or expired.
 */
function mcmm_cache_get($key)
{
    $cacheDir = '/tmp/mcmm_cache';
    $file = $cacheDir . '/' . md5($key) . '.json';
    if (file_exists($file)) {
        $content = @file_get_contents($file);
        if ($content) {
            $cacheData = json_decode($content, true);
            if ($cacheData && $cacheData['expires'] > time()) {
                return $cacheData['data'];
            }
        }
    }
    return null;
}

/**
 * Parse data from the current request.
 *
 * Supports JSON input and standard POST.
 *
 * @return array The request data.
 */
function getRequestData()
{
    $input = file_get_contents('php://input');
    $data = [];
    if (!empty($input)) {
        $data = json_decode($input, true) ?: [];
    }

    // Merge with $_POST if JSON was empty or partial
    if (empty($data) && !empty($_POST)) {
        $data = $_POST;
    }

    return $data;
}

/**
 * Format bytes into a human-readable string.
 *
 * @param int $bytes     Number of bytes.
 * @param int $precision Number of decimal places.
 * @return string Formatted string (e.g., "1.5 GB").
 */
function formatBytes($bytes, $precision = 2)
{
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Sanitize a string for use as a Docker container name.
 *
 * @param string $name The original name.
 * @return string Sanitized name.
 */
function safeContainerName(string $name): string
{
    $sanitized = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '-', $name));
    $sanitized = trim($sanitized, '-_');
    if ($sanitized === '') {
        $sanitized = 'mcmm-server-' . substr(md5($name . microtime()), 0, 6);
    }
    return $sanitized;
}

/**
 * Check if a Docker container exists by name.
 *
 * @param string $name The container name to check.
 * @return bool True if the container exists.
 */
function dockerExists(string $name): bool
{
    $out = [];
    $exitCode = 1;
    exec('docker ps -a --format "{{.Names}}" | grep -Fx ' . escapeshellarg($name), $out, $exitCode);
    return $exitCode === 0;
}

/**
 * Get the path to the mods directory for a container.
 *
 * @param string $containerId The container ID.
 * @return string|null Path to the mods directory or null if not found.
 */
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

/**
 * Save metadata for an installed mod.
 *
 * Persists data to both server-specific and global metadata files.
 *
 * @param string $serverId  Unique ID for the server.
 * @param string $modId     ID of the mod.
 * @param string $platform  Source platform (e.g., 'curseforge').
 * @param string $modName   Display name of the mod.
 * @param string $fileName  The filename of the mod JAR.
 * @param int|null $fileId  Optional file ID from the platform.
 * @param string|null $logo Optional logo URL.
 * @param array $extraData  Optional additional metadata.
 */
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
    }

    // --- GLOBAL DICTIONARY UPDATE ---
    $globalFile = '/mnt/user/appdata/mcmm/mod_ids.json';
    $globalData = [];
    $globalDir = dirname($globalFile);
    if (!is_dir($globalDir)) {
        @mkdir($globalDir, 0777, true);
    }

    if (file_exists($globalFile)) {
        $globalData = json_decode(file_get_contents($globalFile), true) ?: [];
    }

    // Only update if missing or newer info
    if (!isset($globalData[$modId])) {
        $globalData[$modId] = [
            'name' => $modName,
            'source' => $platform,
            'icon' => $logo,
            'description' => $extraData['summary'] ?? '',
        ];
        file_put_contents($globalFile, json_encode($globalData, JSON_PRETTY_PRINT));
    } else {
        dbg("Successfully saved metadata for mod $modId");
    }
}

/**
 * Build Docker environment arguments string from an associative array.
 *
 * @param array $env Key-value pairs of environment variables.
 * @return string The formatted arguments string.
 */
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

/**
 * Normalize boolean-ish inputs to a boolean value.
 *
 * Handles "true"/"false", "1"/"0", "on"/"off", "yes"/"no", and nulls.
 *
 * @param mixed $value   The value to normalize.
 * @param bool  $default Default value if input is null.
 * @return bool The normalized boolean value.
 */
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

/**
 * Parse memory strings with units into MB.
 *
 * Supports units like G, GiB, M, MiB, K, KiB, T, TiB.
 *
 * @param mixed $val The memory string or number.
 * @return float Memory in MB.
 */
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
 * Get accurate 'Working Set' RAM from host Cgroups.
 *
 * Calculates Usage - Inactive Cache for Cgroup v1 or v2.
 *
 * @param string      $containerId The container ID.
 * @param string|null $longId      Optional long ID if already known.
 * @return float|null RAM usage in MB or null if unavailable.
 */
function getContainerCgroupRamMb(string $containerId, ?string $longId = null): ?float
{
    if (!$longId) {
        $longId = getContainerLongId($containerId);
    }
    if (!$longId) {
        return null;
    }

    // Try Cgroup v1
    $v1Path = "/sys/fs/cgroup/memory/docker/$longId/memory.usage_in_bytes";
    $v1Stat = "/sys/fs/cgroup/memory/docker/$longId/memory.stat";

    if (file_exists($v1Path)) {
        $usage = (float) @file_get_contents($v1Path);
        $statContent = (string) @file_get_contents($v1Stat);
        $cache = 0.0;
        // Search for total_cache or sum of (in)active
        if (preg_match('/total_cache\s+(\d+)/', $statContent, $m)) {
            $cache = (float) $m[1];
        } else {
            if (preg_match('/total_inactive_file\s+(\d+)/', $statContent, $m)) {
                $cache += (float) $m[1];
            }
            if (preg_match('/total_active_file\s+(\d+)/', $statContent, $m)) {
                $cache += (float) $m[1];
            }
        }
        return ($usage - $cache) / (1024 * 1024);
    }

    // Try Cgroup v2 (Various possible root paths on Unraid/Linux)
    $v2Roots = [
        "/sys/fs/cgroup/system.slice/docker-$longId.scope",
        "/sys/fs/cgroup/docker/$longId",
        "/sys/fs/cgroup/$longId"
    ];

    foreach ($v2Roots as $root) {
        if (file_exists("$root/memory.current")) {
            $usage = (float) @file_get_contents("$root/memory.current");
            $statContent = (string) @file_get_contents("$root/memory.stat");
            $cache = 0.0;
            // In v2, 'file' is the general cache term
            if (preg_match('/\binactive_file\s+(\d+)/', $statContent, $m)) {
                $cache += (float) $m[1];
            }
            if (preg_match('/\bactive_file\s+(\d+)/', $statContent, $m)) {
                $cache += (float) $m[1];
            }
            // If still high, subtract anything marked as 'file' cache
            if (preg_match('/\bfile\s+(\d+)/', $statContent, $m)) {
                $fileCache = (float) $m[1];
                if ($fileCache > $cache) {
                    $cache = $fileCache;
                }
            }
            return ($usage - $cache) / (1024 * 1024);
        }
    }

    return null;
}

/**
 * Get Java heap usage via jcmd inside the container.
 *
 * @param string $containerId The container ID.
 * @param int    $cacheTtlSec Cache duration for the result.
 * @return float Heap usage in MB.
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
 * Read cgroup statistics for a container without using 'docker stats'.
 *
 * Supports both cgroup v1 and v2 layouts.
 *
 * @param string      $cid              Container ID.
 * @param float|null  $configuredMemMb  Optional manually configured memory limit.
 * @return array Memory and CPU statistics.
 */
function getCgroupStats(string $cid, ?float $configuredMemMb = null): array
{
    $cpuCount = getSystemCpuCount();

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
 * Find the next available host port starting from a baseline.
 *
 * Checks existing Docker port mappings to avoid collisions.
 *
 * @param int $start Starting port number (default 25565).
 * @return int The first available port found.
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

/**
 * Extract a specific value from a Docker labels string.
 *
 * @param string $labels Comma-separated labels (key=value).
 * @param string $key    The key to look for.
 * @return string|null The value or null if not found.
 */
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

/**
 * Backfill server icon for existing containers without config.
 *
 * Scans container environment for modpack info and queries CurseForge.
 *
 * @param string $containerId   Container ID.
 * @param string $containerName Container name.
 * @param string $serversDir    Path to server configurations.
 * @param array  $config        Global plugin configuration.
 * @return string The resolved icon URL.
 */
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

/**
 * Send a JSON response and terminate execution.
 *
 * @param mixed $data       The data to encode.
 * @param int   $statusCode HTTP status code.
 */
function jsonResponse($data, int $statusCode = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);

    // Use flags to handle potentially invalid UTF-8 and ensure HTML safety for Psalm
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
    $json = json_encode($data, $flags);

    if ($json === false) {
        // Fallback for extreme cases
        echo json_encode([
            'success' => false,
            'error' => 'Internal JSON encoding error: ' . json_last_error_msg()
        ], $flags);
    } else {
        // Output to stream to avoid direct echo taint issues (headers are already set)
        file_put_contents('php://output', $json);
    }
    exit;
}

/**
 * Fetch modpacks from CurseForge.
 *
 * @param string $search   Search filter.
 * @param string $apiKey   CurseForge API Key.
 * @param string $sort     Sort field (popularity, newest, updated, name, downloads).
 * @param int    $page     Page number.
 * @param int    $pageSize Number of results per page.
 * @return array|null List of modpacks or null on failure.
 */
function fetchCurseForgeModpacks(string $search, string $apiKey, string $sort = 'popularity', int $page = 1, int $pageSize = 20): ?array
{
    $sortMap = [
        'popularity' => 2,
        'newest' => 3,
        'updated' => 3,
        'name' => 4,
        'downloads' => 6
    ];
    $sortField = $sortMap[$sort] ?? 2;

    $query = http_build_query([
        'gameId' => 432,
        'classId' => 4471,
        'searchFilter' => $search,
        'sortField' => $sortField,
        'sortOrder' => 'desc',
        'index' => ($page - 1) * $pageSize,
        'pageSize' => $pageSize
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
            'tags' => array_slice(array_map(fn($c) => $c['name'], $pack['categories'] ?? []), 0, 3),
            'source' => 'curseforge'
        ];
    }

    return $modpacks;
}

/**
 * Fetch modpacks from Modrinth.
 *
 * @param string $search   Search query.
 * @param string $sort     Sort algorithm.
 * @param int    $page     Page number.
 * @param int    $pageSize Number of results per page.
 * @return array|null List of modpacks or null on failure.
 */
function fetchModrinthModpacks(string $search, string $sort = 'popularity', int $page = 1, int $pageSize = 20): ?array
{
    $sortMap = [
        'popularity' => 'downloads',
        'newest' => 'newest',
        'updated' => 'updated',
        'name' => 'relevance'
    ];
    $index = $sortMap[$sort] ?? 'downloads';

    $offset = ($page - 1) * $pageSize;
    $query = http_build_query([
        'query' => $search,
        'facets' => '[["project_type:modpack"]]',
        'offset' => $offset,
        'limit' => $pageSize,
        'index' => $index
    ]);

    $payload = mrRequest('/search?' . $query);
    if (!$payload || !isset($payload['hits'])) {
        return null;
    }

    $modpacks = [];
    foreach ($payload['hits'] as $pack) {
        $modpacks[] = [
            'id' => $pack['project_id'],
            'name' => $pack['title'],
            'slug' => $pack['slug'] ?? '',
            'author' => $pack['author'] ?? 'Unknown',
            'downloads' => formatDownloads($pack['downloads'] ?? 0),
            'img' => $pack['icon_url'] ?? '',
            'summary' => $pack['description'] ?? '',
            'tags' => array_slice($pack['categories'] ?? [], 0, 3),
            'source' => 'modrinth'
        ];
    }

    return $modpacks;
}

/**
 * Robustly extract the Minecraft version from FTB modpack data.
 *
 * @param array $pack The modpack data from FTB API.
 * @return string|null The extracted version or null if not found.
 */
function extractFtbVersion(array $pack): ?string
{
    // 1. Direct field
    if (!empty($pack['mcversion'])) {
        return $pack['mcversion'];
    }

    // 2. Scan tags for negative IDs (standard FTB)
    if (!empty($pack['tags'])) {
        foreach ($pack['tags'] as $tag) {
            $name = $tag['name'] ?? '';
            // FTB usually uses negative IDs for Minecraft version tags
            if (isset($tag['id']) && (int) $tag['id'] < 0) {
                // Remove non-numeric/dot chars (e.g. "Minecraft 1.20.1" -> "1.20.1")
                $v = trim(preg_replace('/[^0-9.]/', '', $name));
                if (preg_match('/^\d+\.\d+(\.\d+)?$/', $v)) {
                    return $v;
                }
            }
        }
        // 3. Fallback: Scan tag names for version patterns
        foreach ($pack['tags'] as $tag) {
            $name = $tag['name'] ?? '';
            if (preg_match('/\b\d+\.\d+(\.\d+)?\b/', $name, $matches)) {
                return $matches[0];
            }
        }
    }
    return null;
}

/**
 * Fetch modpacks from the FTB API.
 *
 * @param string $search   Search term.
 * @param int    $page     Page number.
 * @param int    $pageSize Number of results per page.
 * @return array|null List of modpacks or null on failure.
 */
function fetchFTBModpacks(string $search, int $page = 1, int $pageSize = 20): ?array
{
    // Search endpoint: returns IDs
    // We fetch up to 100 to support pagination accurately from the ID list
    $cacheKey = "ftb_search_" . md5($search);
    $allPacks = mcmm_cache_get($cacheKey);

    if ($allPacks === null) {
        $url = "https://api.modpacks.ch/public/modpack/search/100?term=" . urlencode($search ?: 'FTB');

        // Strict Host Validation for SSRF Protection
        $host = parse_url($url, PHP_URL_HOST);
        if ($host !== 'api.modpacks.ch') {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 || !$response) {
            dbg("FTB Search API failed with code $httpCode");
            return null;
        }

        $data = json_decode($response, true);
        if (!isset($data['packs']) || empty($data['packs'])) {
            return [];
        }

        $allPacks = $data['packs'];
        // Cache the list of IDs for 10 minutes
        mcmm_cache_set($cacheKey, $allPacks, 600);
    }

    if (empty($allPacks)) {
        return [];
    }

    $offset = ($page - 1) * $pageSize;
    $ids = array_slice($allPacks, $offset, $pageSize);
    $modpacks = [];
    $toFetch = [];
    $results = [];

    // Step 1: Check cache for each ID (Bust with v3)
    foreach ($ids as $id) {
        $cacheKey = "ftb_pack_det_v3_" . $id;
        $cached = mcmm_cache_get($cacheKey);
        if ($cached) {
            $results[$id] = $cached;
        } else {
            $toFetch[] = $id;
        }
    }

    // Step 2: Fetch missing details in parallel using curl_multi
    if (!empty($toFetch)) {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($toFetch as $id) {
            $detUrl = "https://api.modpacks.ch/public/modpack/" . $id;
            $ch = curl_init($detUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_multi_add_handle($mh, $ch);
            $handles[$id] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
        } while ($running);

        foreach ($handles as $id => $ch) {
            $res = curl_multi_getcontent($ch);
            if ($res) {
                $pack = json_decode($res, true);
                if ($pack && isset($pack['name'])) {
                    $results[$id] = $pack;
                    mcmm_cache_set("ftb_pack_det_v3_" . $id, $pack, 3600);
                }
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }

    // Step 3: Format the results in the original order
    foreach ($ids as $id) {
        if (!isset($results[$id])) {
            continue;
        }

        $pack = $results[$id];
        $mcVer = extractFtbVersion($pack);

        // Extract Author name
        $author = 'FTB Team';
        if (!empty($pack['authors']) && is_array($pack['authors'])) {
            $author = $pack['authors'][0]['name'] ?? $author;
        }

        $logoUrl = '';
        if (!empty($pack['art'])) {
            foreach ($pack['art'] as $art) {
                if ($art['type'] === 'logo' || $art['type'] === 'square') {
                    $logoUrl = $art['url'];
                    if ($art['type'] === 'logo') {
                        break; // Prefer logo
                    }
                }
            }
            if (!$logoUrl && !empty($pack['art'])) {
                $logoUrl = $pack['art'][0]['url'];
            }
        }

        $modpacks[] = [
            'id' => $pack['id'],
            'name' => $pack['name'],
            'slug' => $pack['slug'] ?? '',
            'author' => $author,
            'downloads' => formatDownloads($pack['installs'] ?? 0),
            'img' => $logoUrl,
            'summary' => $pack['synopsis'] ?? '',
            'tags' => ['FTB'],
            'source' => 'ftb',
            'mcVersion' => $mcVer
        ];
    }

    return $modpacks;
}

/**
 * Fetch available versions (files) for an FTB modpack.
 *
 * @param int $modpackId The FTB modpack ID.
 * @return array List of versions.
 */
function fetchFTBModpackFiles(int $modpackId): array
{
    $cacheKey = "ftb_pack_vers_v3_" . $modpackId;
    $cached = mcmm_cache_get($cacheKey);
    if ($cached) {
        return $cached;
    }

    $url = "https://api.modpacks.ch/public/modpack/" . $modpackId;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        return [];
    }
    $data = json_decode($response, true);
    if (!isset($data['versions']) || empty($data['versions'])) {
        return [];
    }

    $baseMcVer = extractFtbVersion($data);

    $files = [];
    foreach ($data['versions'] as $v) {
        $mcVer = $v['mcversion'] ?? $baseMcVer ?? 'Unknown';
        $files[] = [
            'id' => $v['id'],
            'displayName' => $v['name'],
            'releaseType' => 1,
            'gameVersions' => [$mcVer],
            'source' => 'ftb'
        ];
    }
    $files = array_reverse($files);
    mcmm_cache_set($cacheKey, $files, 3600);
    return $files;
}

/**
 * Search for mods on CurseForge with version and loader filtering.
 *
 * @param string $search   Search filter.
 * @param string $version  Minecraft version.
 * @param string $loader   Mod loader (forge, fabric, etc.).
 * @param int    $page     Page number.
 * @param int    $pageSize Results per page.
 * @param string $apiKey   CurseForge API Key.
 * @return array [mods_list, total_count].
 */
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
            'slug' => $mod['slug'] ?? '',
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

/**
 * Fetch multiple CurseForge mods by their IDs in a single request.
 *
 * @param array  $modIds List of mod IDs.
 * @param string $apiKey CurseForge API Key.
 * @return array List of mod data.
 */
function fetchCurseForgeModsBatch(array $modIds, string $apiKey): array
{
    if (empty($modIds)) {
        return [];
    }

    $chunks = array_chunk($modIds, 200);
    $allMods = [];

    foreach ($chunks as $chunk) {
        $result = cfRequest('/mods', $apiKey, false, 'POST', ['modIds' => $chunk]);
        if ($result && isset($result['data'])) {
            $allMods = array_merge($allMods, $result['data']);
        }
    }

    return $allMods;
}

/**
 * Fetch available files for a specific CurseForge mod.
 *
 * @param int    $modId   The mod ID.
 * @param string $version Filter by Minecraft version.
 * @param string $loader  Filter by mod loader.
 * @param string $apiKey  CurseForge API Key.
 * @return array List of files.
 */
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

/**
 * Search for mods on Modrinth.
 *
 * @param string $search   Search term.
 * @param string $version  Minecraft version.
 * @param string $loader   Mod loader.
 * @param int    $page     Page number.
 * @param int    $pageSize Results per page.
 * @return array [mods_list, total_count].
 */
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

/**
 * Fetch available versions for a Modrinth mod.
 *
 * @param string $projectId Modrinth project ID or slug.
 * @param string $version   Filter by Minecraft version.
 * @param string $loader    Filter by mod loader.
 * @return array List of versions.
 */
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

/**
 * Get the direct download URL for a Modrinth version.
 *
 * @param string $versionId Modrinth version ID.
 * @return string|null The download URL or null if not found.
 */
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

/**
 * Perform a request to the Modrinth API.
 *
 * @param string $path      API path or full URL.
 * @param bool   $isFullUrl Whether $path is a full URL.
 * @return array|null Decoded JSON response or null on failure.
 */
function mrRequest(string $path, bool $isFullUrl = false): ?array
{
    $cacheKey = "mr_" . ($isFullUrl ? $path : $path);
    $cached = mcmm_cache_get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }

    $url = $isFullUrl ? $path : ('https://api.modrinth.com/v2' . $path);

    // SSRF Check for Modrinth
    $host = parse_url($url, PHP_URL_HOST);
    if (!empty($host) && !preg_match('/(modrinth\.com|modrinth-production\.s3\.amazonaws\.com)$/', $host)) {
         dbg("Blocked SSRF attempt in mrRequest: $url");
         return null;
    }

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

    mcmm_cache_set($cacheKey, $json, 3600); // 1 hour cache
    return $json;
}

/**
 * Get the download URL for a specific CurseForge mod file.
 *
 * @param int    $modId  The mod ID.
 * @param string $fileId The file ID.
 * @param string $apiKey CurseForge API Key.
 * @return string|null The download URL or null if not found.
 */
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

/**
 * Perform a request to the CurseForge API.
 *
 * @param string $path      API path or full URL.
 * @param string $apiKey    CurseForge API Key.
 * @param bool   $isFullUrl Whether $path is a full URL.
 * @param string $method    HTTP method (GET or POST).
 * @param mixed  $data      Optional data for POST requests.
 * @return array|null Decoded JSON response or null on failure.
 */
function cfRequest(string $path, string $apiKey, bool $isFullUrl = false, string $method = 'GET', $data = null): ?array
{
    $method = strtoupper($method);
    $cacheKey = "cf_" . ($isFullUrl ? $path : $path);
    if ($method === 'GET') {
        $cached = mcmm_cache_get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
    }

    $url = $isFullUrl ? $path : ('https://api.curseforge.com/v1' . $path);
    dbg("CF Request ($method): $url");

    // SSRF Check for CurseForge
    $host = parse_url($url, PHP_URL_HOST);
    if (!empty($host) && !str_ends_with($host, 'curseforge.com')) {
         dbg("Blocked SSRF attempt in cfRequest: $url");
         return null;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    $headers = [
        'Accept: application/json',
        'User-Agent: MCMM/1.0 (mcmm@v-severe.com)',
        'x-api-key: ' . $apiKey
    ];

    if ($method === 'POST') {
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
        dbg("CF HTTP Error $httpCode for $url. Response: " . substr($response, 0, 200));
        return null;
    }

    $json = json_decode($response, true);
    if (!$json) {
        dbg("CF JSON Decode Error for $url. Response: " . substr($response, 0, 200));
        return null;
    }

    if ($method === 'GET') {
        mcmm_cache_set($cacheKey, $json, 3600); // 1 hour cache
    }

    return $json;
}

/**
 * Resolve the best download file for a modpack (server pack preferred).
 *
 * @param int      $modId           The modpack ID on CurseForge.
 * @param string   $apiKey          CurseForge API Key.
 * @param int|null $preferredFileId Optional specific file ID.
 * @return array [download_url, filename].
 */
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

/**
 * Format a download count into a human-readable string (e.g., 1M, 50K).
 *
 * @param int|float $count The raw download count.
 * @return string The formatted count.
 */
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

/**
 * Retrieve supported mod loaders for a specific modpack.
 *
 * @param string      $platform  Platform name ('curseforge' or 'modrinth').
 * @param string      $slug      Modpack slug or ID.
 * @param string      $apiKey    CurseForge API Key.
 * @param string|null $modpackId Optional numeric modpack ID for CurseForge.
 * @return array List of supported loaders (e.g., ['Forge', 'Fabric']).
 */
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

/**
 * Retrieve all available versions for a modpack.
 *
 * @param string $platform Platform name.
 * @param string $id       Modpack ID or slug.
 * @param string $apiKey   Optional CurseForge API Key.
 * @return array List of versions with metadata.
 */
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

/**
 * Resolve the Minecraft version for a specific file/version ID of a modpack.
 *
 * @param string $platform  Platform name.
 * @param string $id        Modpack ID or slug.
 * @param string $versionId Specific version/file ID.
 * @param string $apiKey    Optional CurseForge API Key.
 * @return string|null The Minecraft version string or null if not found.
 */
function getMinecraftVersion(string $platform, string $id, string $versionId, string $apiKey = ''): ?string
{
    if ($platform === 'curseforge') {
        $modId = $id;
        // Resolve slug to ID if needed
        if (!is_numeric($id)) {
            $search = cfRequest("/mods/search?gameId=432&classId=4471&searchFilter=" . urlencode($id), $apiKey);
            file_put_contents(__DIR__ . '/debug.log', "Search '$id': Found " . count($search['data'] ?? []) . " matches.\n", FILE_APPEND);
            if (isset($search['data'][0]['id'])) {
                $modId = $search['data'][0]['id'];
                file_put_contents(__DIR__ . '/debug.log', "Resolved ModID: $modId named '" . ($search['data'][0]['name'] ?? '') . "'\n", FILE_APPEND);
            } else {
                file_put_contents(__DIR__ . '/debug.log', "Failed to resolve slug '$id'\n", FILE_APPEND);
                return null; // Slug not found
            }
        }

        // Direct File Lookup (Much faster and reliable than paging through 50 versions)
        if (is_numeric($versionId)) {
            $file = cfRequest("/mods/" . urlencode((string) $modId) . "/files/" . urlencode((string) $versionId), $apiKey);

            if (isset($file['data']['gameVersions'])) {
                foreach ($file['data']['gameVersions'] as $gv) {
                    if (preg_match('/^\d+\.\d+(\.\d+)?$/', $gv)) {
                        return $gv;
                    }
                }
            } else {
                file_put_contents(__DIR__ . '/debug.log', "File $versionId lookup failed for Mod $modId. Resp: " . json_encode($file) . "\n", FILE_APPEND);
            }
        }
    }

    // Fallback / Modrinth
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

/**
 * Retrieve Minecraft server status using the 1.7+ Handshake protocol.
 *
 * @param string $host    Server hostname or IP.
 * @param int    $port    Server port.
 * @param int    $timeout Connection timeout in seconds.
 * @return array|null Online stats (players, max, version) or null on failure.
 */
function getMinecraftStatusModern(string $host, int $port, int $timeout = 2): ?array
{
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        return null;
    }

    stream_set_timeout($socket, $timeout);

    // 1. Handshake Packet (Protocol -1 (auto), Host, Port, NextState 1)
    $hostLen = strlen($host);
    $handshake = pack('C', 0x00); // Packet ID
    $handshake .= "\x80\x05"; // VarInt: -1 (approx 763+)
    $handshake .= pack('C', $hostLen) . $host;
    $handshake .= pack('n', $port);
    $handshake .= pack('C', 0x01); // Next state: Status

    $packet = pack('C', strlen($handshake)) . $handshake;
    fwrite($socket, $packet);

    // 2. Status Request Packet
    fwrite($socket, "\x01\x00");

    // 3. Status Response
    $sizeVarInt = 0;
    $shift = 0;
    while (true) {
        $byte = ord(fread($socket, 1));
        $sizeVarInt |= ($byte & 0x7F) << $shift;
        if (($byte & 0x80) !== 0x80) {
            break;
        }
        $shift += 7;
    }

    $data = '';
    $remaining = $sizeVarInt;
    while ($remaining > 0) {
        $chunk = fread($socket, min($remaining, 8192));
        if (!$chunk) {
            break;
        }
        $data .= $chunk;
        $remaining -= strlen($chunk);
    }
    @fclose($socket);

    if (!$data || ord($data[0]) !== 0x00) {
        return null;
    }

    // JSON starts after the ID VarInt
    $jsonStr = substr($data, 1);
    // There is another VarInt for string length
    $shift = 0;
    $jsonLen = 0;
    $pos = 1;
    while (true) {
        $byte = ord($data[$pos++]);
        $jsonLen |= ($byte & 0x7F) << $shift;
        if (($byte & 0x80) !== 0x80) {
            break;
        }
        $shift += 7;
    }
    $json = json_decode(substr($data, $pos), true);

    if ($json && isset($json['players'])) {
        return [
            'online' => intval($json['players']['online']),
            'max' => intval($json['players']['max']),
            'version' => $json['version']['name'] ?? '',
            'sample' => $json['players']['sample'] ?? []
        ];
    }

    return null;
}

/**
 * Get Minecraft server status with automatic protocol fallback.
 *
 * Tries the modern (1.7+) protocol first, then falls back to legacy (1.6.1).
 *
 * @param string $host    Server hostname or IP.
 * @param int    $port    Server port.
 * @param int    $timeout Connection timeout in seconds.
 * @return array|null Online stats or null on failure.
 */
function getMinecraftStatus(string $host, int $port, int $timeout = 1): ?array
{
    if ($port <= 0) {
        return null;
    }

    // Try Modern first (1.7+)
    $res = getMinecraftStatusModern($host, $port, $timeout);
    if ($res) {
        return $res;
    }

    // Fallback to Legacy (1.6.1)
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        return null;
    }

    stream_set_timeout($socket, $timeout);

    // Minecraft 1.6.1+ Legacy Ping (UTF-16BE)
    $channel = "MC|PingHost";
    $protocol = 74; // 1.7.2
    // Robust 1.6.1 compatible ping payload
    $payload = "\xFE\x01\xFA" .
        pack('n', 11) . mb_convert_encoding("MC|PingHost", "UTF-16BE") .
        pack('n', 7 + (strlen($host) * 2)) .
        pack('C', $protocol) .
        pack('n', strlen($host)) . mb_convert_encoding($host, "UTF-16BE") .
        pack('N', $port);

    fwrite($socket, $payload);
    $data = fread($socket, 4096);
    @fclose($socket);

    if (!$data || ord($data[0]) !== 0xFF) {
        return null;
    }

    $response = substr($data, 3);
    $response = @mb_convert_encoding($response, 'UTF-8', 'UTF-16BE');

    if (strpos($response, "\x00") !== false) {
        $parts = explode("\x00", $response);
        if (count($parts) >= 6) {
            return [
                'online' => intval($parts[4]),
                'max' => intval($parts[5]),
                'version' => $parts[2]
            ];
        }
    }

    return null;
}

/**
 * Detect Minecraft version and mod loader by scanning server files.
 *
 * Checks common JSON manifests, libraries, and JAR filenames.
 *
 * @param string $dir The server's data directory.
 * @return array ['mcVersion' => string, 'loader' => string].
 */
function detectVersionFromFiles(string $dir): array
{
    $ver = '';
    $loader = '';

    // 1. Check root JSON files (Common in some modern packs)
    $jsonFiles = ['version.json', 'versions.json', 'modpack.json', 'minecraftinstance.json', 'manifest.json'];
    foreach ($jsonFiles as $jf) {
        $path = "$dir/$jf";
        if (file_exists($path)) {
            $data = @json_decode(@file_get_contents($path), true);
            if ($data) {
                // MC Version markers
                $mc = $data['mc_version'] ?? $data['minecraft'] ?? $data['minecraft_version'] ?? '';
                if (is_array($mc)) {
                    $mc = $mc['version'] ?? '';
                }
                if ($mc && preg_match('/^\d+\.\d+(\.\d+)?$/', (string) $mc)) {
                    $ver = (string) $mc;
                    break;
                }
            }
        }
    }

    // 2. Check libraries for MC version (The most reliable for installed servers)
    if (!$ver) {
        $libPaths = [
            "$dir/libraries/net/minecraft/server",
            "$dir/libraries/net/minecraft/client",
            "$dir/libraries/net/minecraft",
            "$dir/libraries/com/mojang/minecraft"
        ];
        foreach ($libPaths as $lp) {
            if (is_dir($lp)) {
                $dirs = glob("$lp/*", GLOB_ONLYDIR);
                if ($dirs) {
                    usort($dirs, 'version_compare');
                    $bestDir = end($dirs);
                    $v = basename($bestDir);
                    if (preg_match('/^\d+\.\d+(\.\d+)?$/', $v)) {
                        $ver = $v;
                        break;
                    }
                }
            }
        }
    }

    // 3. JAR Filenames in root: minecraft_server.1.21.1.jar or server-1.20.1.jar
    if (!$ver) {
        $jars = glob("$dir/*.jar");
        if ($jars) {
            foreach ($jars as $jar) {
                $base = basename($jar);
                if (preg_match('/(?:minecraft_server|server)[.-](\d+\.\d+(\.\d+)?)/i', $base, $m)) {
                    $ver = $m[1];
                    break;
                }
            }
        }
    }

    // 4. Mod Loader detection
    if (file_exists("$dir/fabric-server-launch.jar") || is_dir("$dir/libraries/net/fabricmc")) {
        $loader = 'fabric';
    } elseif (is_dir("$dir/libraries/net/neoforged")) {
        $loader = 'neoforge';
    } elseif (is_dir("$dir/libraries/net/minecraftforge") || count(glob("$dir/forge-*.jar")) > 0) {
        $loader = 'forge';
    } elseif (file_exists("$dir/quilt-server-launch.jar") || is_dir("$dir/libraries/org/quiltmc")) {
        $loader = 'quilt';
    }

    return ['mcVersion' => $ver, 'loader' => $loader];
}

/**
 * Extract comprehensive metadata for a Minecraft server container.
 *
 * Resolves Minecraft version, loader type, and modpack info from env,
 * install files, and directory scanning.
 *
 * @param array  $env           Container environment variables.
 * @param array  $config        Global plugin configuration.
 * @param string $containerName Name of the container.
 * @param string $apiKey        CurseForge API Key.
 * @param string $image         Optional Docker image tag.
 * @param int    $port          Optional host port.
 * @param string $containerId   Optional Container ID.
 * @param string|null $explicitDataDir Optional pre-calculated data directory.
 * @return array Metadata including versions, loader, and debug info.
 */
function getServerMetadata(array $env, array $config, string $containerName, string $apiKey, string $image = '', int $port = 0, string $containerId = '', ?string $explicitDataDir = null): array
{
    $mcVersion = '';
    $loader = '';
    $modpackVersion = '';
    $modpackId = $env['CF_PROJECT_ID'] ?? $env['FTB_MODPACK_ID'] ?? '';
    $modpackFileId = $env['CF_FILE_ID'] ?? $env['FTB_MODPACK_VERSION_ID'] ?? '';
    $debug = [];

    $dataDir = $explicitDataDir ?: getContainerDataDir($containerName, $containerId);
    $debug['dataDir'] = $dataDir;

    // 1. Check for install environment files (The Absolute Source of Truth for itzg installers)
    if ($dataDir) {
        $envFiles = glob(rtrim($dataDir, '/') . '/.install-*.env');
        foreach ($envFiles as $envFile) {
            $content = @file_get_contents($envFile);
            if ($content) {
                $cleanVal = function ($v) {
                    return trim($v, " \n\r\t\v\0\"'");
                };

                // Detect MC Version
                if (preg_match('/^MINECRAFT_VERSION=(.*)$/m', $content, $m)) {
                    $mcVersion = $cleanVal($m[1]);
                }
                if ((!$mcVersion || strtoupper($mcVersion) === 'LATEST') && preg_match('/^VERSION=(.*)$/m', $content, $m)) {
                    $val = $cleanVal($m[1]);
                    if (preg_match('/^\d+\.\d+(\.\d+)?$/', $val)) {
                        $mcVersion = $val;
                    }
                }

                // Detect Loader Type
                if (preg_match('/^TYPE=(.*)$/m', $content, $m)) {
                    $val = strtolower($cleanVal($m[1]));
                    if (in_array($val, ['forge', 'fabric', 'quilt', 'neoforge'])) {
                        $loader = $val;
                    }
                }

                // Detect Modpack Meta
                if (preg_match('/^MODPACK_VERSION=(.*)$/m', $content, $m)) {
                    $modpackVersion = $cleanVal($m[1]);
                }
                if (!$modpackId) {
                    if (preg_match('/^CF_PROJECT_ID=(.*)$/m', $content, $m)) {
                        $modpackId = $cleanVal($m[1]);
                    } elseif (preg_match('/^FTB_MODPACK_ID=(.*)$/m', $content, $m)) {
                        $modpackId = $cleanVal($m[1]);
                    }
                }
                if (!$modpackFileId) {
                    if (preg_match('/^CF_FILE_ID=(.*)$/m', $content, $m)) {
                        $modpackFileId = $cleanVal($m[1]);
                    } elseif (preg_match('/^FTB_MODPACK_VERSION_ID=(.*)$/m', $content, $m)) {
                        $modpackFileId = $cleanVal($m[1]);
                    }
                }
            }
        }
    }

    // 2. Try JSON and Text files if env file didn't give us everything
    if ($dataDir && (!$mcVersion || strtoupper($mcVersion) === 'LATEST' || !$modpackVersion)) {
        // A. Check for simple version files first
        $textFiles = ['version', 'version.txt', 'instance.txt', 'modpack.txt'];
        foreach ($textFiles as $tf) {
            $path = rtrim($dataDir, '/') . '/' . $tf;
            if (file_exists($path)) {
                $line = trim(@file_get_contents($path));
                if ($line && strlen($line) < 30 && preg_match('/^\d+/', $line)) {
                    $modpackVersion = $line;
                    break;
                }
            }
        }

        // B. Deep Scan JSON Files (Including dot-prefixed ones found in FTB)
        $jsonFiles = [
            'version.json',
            'versions.json',
            'modpack.json',
            'minecraftinstance.json',
            'manifest.json',
            '.manifest.json',
            'ftb-modpack.json',
            'instance.json'
        ];
        foreach ($jsonFiles as $jf) {
            $path = rtrim($dataDir, '/') . '/' . $jf;
            if (file_exists($path)) {
                $jsonData = @json_decode(@file_get_contents($path), true);
                if ($jsonData) {
                    if (!$modpackVersion) {
                        $modpackVersion = $jsonData['versionName'] ?? $jsonData['version'] ?? $jsonData['modpack_version'] ?? $jsonData['pack_version'] ?? $jsonData['packVersion'] ?? '';
                    }
                    if (!$mcVersion || strtoupper($mcVersion) === 'LATEST') {
                        $mcVersion = $jsonData['mc_version'] ?? $jsonData['minecraft'] ?? $jsonData['minecraft_version'] ?? '';
                        // Support FTB ModPackTargets structure
                        if (!$mcVersion && isset($jsonData['modPackTargets']['mcVersion'])) {
                            $mcVersion = $jsonData['modPackTargets']['mcVersion'];
                        }
                        if (!$mcVersion && isset($jsonData['minecraft']['version'])) {
                            $mcVersion = $jsonData['minecraft']['version'];
                        }
                        if (!$mcVersion && $jf === 'minecraftinstance.json') {
                            $mcVersion = $jsonData['baseModLoader']['minecraftVersion'] ?? '';
                        }
                    }
                    if (!$loader) {
                        if (isset($jsonData['baseModLoader']['name'])) {
                            $loader = $jsonData['baseModLoader']['name'];
                        } elseif (isset($jsonData['modPackTargets']['modLoader']['name'])) {
                            $loader = $jsonData['modPackTargets']['modLoader']['name'];
                        } elseif (isset($jsonData['minecraft']['modLoaders'][0]['id'])) {
                            $id = $jsonData['minecraft']['modLoaders'][0]['id'];
                            if (strpos($id, 'forge') !== false) {
                                $loader = 'forge';
                            } elseif (strpos($id, 'fabric') !== false) {
                                $loader = 'fabric';
                            }
                        }
                    }
                }
            }
        }
    }

    // 3. Fallback to file-based MC/Loader detection (scanning libraries/ folder)
    if (!$mcVersion || strtoupper($mcVersion) === 'LATEST' || !$loader) {
        if ($dataDir) {
            $guessed = detectVersionFromFiles($dataDir);
            if ((!$mcVersion || strtoupper($mcVersion) === 'LATEST') && $guessed['mcVersion']) {
                $mcVersion = $guessed['mcVersion'];
            }
            if (!$loader && $guessed['loader']) {
                $loader = $guessed['loader'];
            }
        }
    }

    // 4. Final Fallback to Env vars from Docker Container
    if ((!$mcVersion || strtoupper($mcVersion) === 'LATEST') && isset($env['VERSION'])) {
        $mcVersion = $env['VERSION'];
    }
    if (!$loader && isset($env['TYPE'])) {
        $lt = strtolower($env['TYPE']);
        if (in_array($lt, ['forge', 'fabric', 'quilt', 'neoforge'])) {
            $loader = $lt;
        }
        if ($lt === 'ftba' || $lt === 'auto_curseforge') {
            $loader = $lt === 'ftba' ? 'FTB' : 'Modded';
        }
    }

    // 5. Final fallback for Modpack Version if we have the custom env var
    if (!$modpackVersion && isset($env['MODPACK_VERSION'])) {
        $modpackVersion = $env['MODPACK_VERSION'];
    }

    return [
        'mcVersion' => ($mcVersion && strtoupper($mcVersion) !== 'LATEST') ? $mcVersion : 'Latest',
        'loader' => $loader ?: 'Vanilla',
        'modpackVersion' => $modpackVersion,
        'modpackId' => $modpackId,
        'modpackFileId' => $modpackFileId,
        'cache_ver' => 'v11',
        '_debug' => $debug
    ];
}

/**
 * Deployment handler for creating and starting a new Minecraft container.
 *
 * Handles directory creation, port selection, XML template generation,
 * and docker run execution.
 *
 * @param array $config   Current plugin configuration.
 * @param array $defaults Default server settings.
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
    $fileId = $input['modpack_file_id'] ?? '';
    if ($modId === 0) {
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

    $dataDir = "/mnt/user/appdata/" . $containerName;
    if (!is_dir($dataDir)) {
        if (!@mkdir($dataDir, 0777, true)) {
            jsonResponse(['success' => false, 'error' => "Failed to create data directory: $dataDir"], 500);
        }
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

    // ---------------------------
    // FIX: Ensure $downloadUrl is always defined
    // Optional behavior: only include it in the response if non-null/non-empty
    // ---------------------------
    $downloadUrl = null;
    $source = strtolower($input['source'] ?? 'curseforge');

    if ($modId === -1) {
        // Vanilla path: keep $downloadUrl as null
        $resolvedFileId = $fileId ?: 'LATEST';
        $downloadUrl = null; // explicit (optional)
    } elseif ($source === 'ftb') {
        // FTB path
        $resolvedFileId = $fileId;
        $downloadUrl = "https://api.modpacks.ch/public/modpack/$modId"; // Indicator for success
    } else {
        // CurseForge path
        [$resolvedFileId, $downloadUrl] = getModpackDownload(
            $modId,
            $config['curseforge_api_key'],
            $fileId ?: null
        );

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

        // AUTO-RESOLVE VERSION: If user selected "Latest" or empty, try to resolve the actual MC version from the file ID for metadata
        if (!$mcVerSelected && $modId !== -1 && $resolvedFileId) {
            $mcVerSelected = getMinecraftVersion('curseforge', (string) $modId, (string) $resolvedFileId, $config['curseforge_api_key'] ?? '');
            if ($mcVerSelected) {
                dbg("Resolved MC Version for Modpack $modId / File $resolvedFileId: $mcVerSelected");
            }
        }
    }

    // --- GLOBAL DICTIONARY PATH ---
    $globalIdsFile = "/mnt/user/appdata/mcmm/mod_ids.json";
    if (!is_dir(dirname($globalIdsFile))) {
        @mkdir(dirname($globalIdsFile), 0777, true);
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
        'SERVER_NAME' => $serverName,
        'SERVER_IP' => $serverIp,
        'SERVER_PORT' => 25565,
        'QUERY_PORT' => $port,
        'MEMORY' => $memory,
        'MAX_PLAYERS' => $maxPlayers,
        'ENABLE_WHITELIST' => $whitelist !== '' ? 'TRUE' : 'FALSE',
        'WHITELIST' => $whitelist,
        'PVP' => $flagPvp ? 'TRUE' : 'FALSE',
        'HARDCORE' => $flagHardcore ? 'TRUE' : 'FALSE',
        'ALLOW_FLIGHT' => $flagFlight ? 'TRUE' : 'FALSE',
        'ENABLE_COMMAND_BLOCK' => $flagCmdBlocks ? 'TRUE' : 'FALSE',
        'ENABLE_ROLLING_LOGS' => $flagRolling ? 'TRUE' : 'FALSE',
        'USE_LOG_TIMESTAMP' => $flagLogTs ? 'TRUE' : 'FALSE',
        'USE_AIKAR_FLAGS' => $flagAikar ? 'TRUE' : 'FALSE',
        'JVM_OPTS' => $jvmFlags,
        'MODPACK_VERSION' => $input['modpack_version_name'] ?? ''
    ];

    if ($modId !== -1) {
        $env['CF_PROJECT_ID'] = $modId;
        if ($resolvedFileId) {
            $env['CF_FILE_ID'] = $resolvedFileId;
        }
    }

    if ($modId === -1) {
        $env['TYPE'] = 'VANILLA';
        $env['VERSION'] = $mcVerSelected ?: 'LATEST';
    } elseif ($source === 'ftb') {
        $env['TYPE'] = 'FTBA';
        $env['FTB_MODPACK_ID'] = $modId;
        if ($fileId) {
            $env['FTB_MODPACK_VERSION_ID'] = $fileId;
        }
    } else {
        $env['TYPE'] = 'AUTO_CURSEFORGE';
        if (!empty($config['curseforge_api_key'])) {
            $env['CF_API_KEY'] = $config['curseforge_api_key'];
        }
        $env['CF_SLUG'] = $safeSlug ?: $serverName;
        if ($resolvedFileId) {
            $env['CF_FILE_ID'] = $resolvedFileId;
        }
    }

    if ($iconUrl !== '') {
        $env['ICON'] = $iconUrl;
    }

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

    // If Vanilla (no modpack), generate the tracking file so we can detect version
    if (empty($modpackName) || $modpackName === 'Vanilla' || empty($downloadUrl)) {
        $envContent = "MINECRAFT_VERSION=" . ($mcVerSelected ?: 'LATEST') . "\n";
        $envContent .= "TYPE=vanilla\n";
        $envContent .= "LOADER=vanilla\n";
        // Optionally add MODPACK_NAME if we want to treat it as a "Vanilla Pack"
        $envContent .= "MODPACK_NAME=Vanilla\n";

        file_put_contents($dataDir . '/.install-curseforge.env', $envContent);
    }

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

    // ---------------------------
    // OPTIONAL: Only include download in response when present
    // ---------------------------
    $response = [
        'success' => true,
        'container' => $containerName,
        'id' => trim($output[0] ?? ''),
        'fileId' => $fileId,
    ];

    if ($downloadUrl !== null && $downloadUrl !== '') {
        $response['download'] = $downloadUrl;
    }

    jsonResponse($response);
}

/**
 * Get live server statistics (online players, max players, sample).
 *
 * Tries multiple methods in order: mc-monitor, RCON, and Host/Bridge pings.
 *
 * @param string $containerId The container ID or name.
 * @param int|string $hostPort Host port for external ping fallback.
 * @param array $env          Container environment variables (for RCON/MaxPlayers).
 * @return array Player statistics.
 */
function getMinecraftLiveStats($containerId, $hostPort, $env)
{
    $cacheKey = "live_stats_" . $containerId;
    $cached = mcmm_cache_get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }

    $result = _getMinecraftLiveStatsUncached($containerId, $hostPort, $env);
    mcmm_cache_set($cacheKey, $result, 10);
    return $result;
}

function _getMinecraftLiveStatsUncached($containerId, $hostPort, $env)
{
    $online = 0;
    $max = isset($env['MAX_PLAYERS']) ? intval($env['MAX_PLAYERS']) : 20;
    $log = "--- Player Ping Diagnostics for $containerId (" . date('H:i:s') . ") ---\n";

    // 1. Try Internal mc-monitor (TCP) - Fastest and very reliable
    $internalPort = isset($env['SERVER_PORT']) ? intval($env['SERVER_PORT']) : 25565;
    $cmd = "docker exec " . escapeshellarg($containerId) . " mc-monitor status --json 127.0.0.1:$internalPort 2>/dev/null";
    $statusRes = shell_exec($cmd);
    $log .= "Method 1 (mc-monitor): " . ($statusRes ? "SUCCESS" : "FAILED") . "\n";
    if ($statusRes) {
        $jd = json_decode($statusRes, true);
        if (isset($jd['players'])) {
            @file_put_contents('/tmp/mcmm_ping.log', $log . "Result: " . $jd['players']['online'] . "/" . $jd['players']['max'] . "\n\n", FILE_APPEND);
            return [
                'online' => intval($jd['players']['online']),
                'max' => intval($jd['players']['max']),
                'sample' => $jd['players']['sample'] ?? []
            ];
        }
    }

    // 2. Try RCON (Authoritative)
    $rconPass = $env['RCON_PASSWORD'] ?? '';
    if ($rconPass) {
        $rconPort = isset($env['RCON_PORT']) ? intval($env['RCON_PORT']) : 25575;
        $rconCmd = "docker exec " . escapeshellarg($containerId) . " rcon-cli --port $rconPort --password " . escapeshellarg($rconPass) . " list 2>/dev/null";
        $rconOut = shell_exec($rconCmd);
        $log .= "Method 2 (RCON): " . ($rconOut ? "SUCCESS" : "FAILED") . "\n";

        $players = [];
        if ($rconOut && preg_match('/(?:online|players): (.*)$/i', trim($rconOut), $pm)) {
            $names = explode(', ', $pm[1]);
            foreach ($names as $name) {
                $clean = trim(preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $name));
                if ($clean !== '') {
                    $players[] = ['name' => $clean];
                }
            }
        }

        // Flexible regex for both Vanilla and Modded "list" outputs
        if ($rconOut && preg_match('/(?:There are|online:?) (\d+) (?:of|out of|\/) a max of (\d+)/i', $rconOut, $m)) {
            @file_put_contents('/tmp/mcmm_ping.log', $log . "Result: " . $m[1] . "/" . $m[2] . "\n\n", FILE_APPEND);
            return [
                'online' => intval($m[1]),
                'max' => intval($m[2]),
                'sample' => $players
            ];
        }
        // Fallback for just the number if regex above is too strict
        if ($rconOut && preg_match('/There are (\d+) /i', $rconOut, $m)) {
            @file_put_contents('/tmp/mcmm_ping.log', $log . "Result: " . $m[1] . "/20 (partial match)\n\n", FILE_APPEND);
            return [
                'online' => intval($m[1]),
                'max' => $max,
                'sample' => $players
            ];
        }
    }

    // 3. Try Host-Level Ping (Native PHP) - Fallback for networking issues
    $log .= "Method 3 (Host Ping): Pinging 127.0.0.1:$hostPort\n";
    $hostPing = getMinecraftStatus('127.0.0.1', intval($hostPort));
    if ($hostPing) {
        $log .= "Result: SUCCESS (Host Loopback)\n";
        @file_put_contents('/tmp/mcmm_ping.log', $log . "\n", FILE_APPEND);
        return $hostPing;
    }

    // 4. Try Container IP Ping (Native PHP) - Bypass bridge mapping issues
    $inspectJson = shell_exec("docker inspect " . escapeshellarg($containerId) . " 2>/dev/null");
    $inspect = json_decode((string) $inspectJson, true);
    if ($inspect && isset($inspect[0]['NetworkSettings'])) {
        $cIp = $inspect[0]['NetworkSettings']['IPAddress'] ?? '';
        if (!$cIp) {
            foreach ($inspect[0]['NetworkSettings']['Networks'] ?? [] as $nw) {
                if (!empty($nw['IPAddress'])) {
                    $cIp = $nw['IPAddress'];
                    break;
                }
            }
        }
        if ($cIp) {
            $log .= "Method 4 (Bridge IP): Pinging $cIp:$internalPort\n";
            $bridgePing = getMinecraftStatus($cIp, $internalPort);
            if ($bridgePing) {
                $log .= "Result: SUCCESS (Bridge IP)\n";
                @file_put_contents('/tmp/mcmm_ping.log', $log . "\n", FILE_APPEND);
                return $bridgePing;
            }
        }
    }

    $log .= "Result: FINAL FAIL (Returning 0/$max)\n";
    @file_put_contents('/tmp/mcmm_ping.log', $log . "\n", FILE_APPEND);
    return ['online' => 0, 'max' => $max];
}

/**
 * Retrieve environment variables for a container.
 *
 * @param string $id Container ID or Name.
 * @return array Associative array of environment variables.
 */
function getContainerEnv(string $id): array
{
    $json = shell_exec("docker inspect " . escapeshellarg($id));
    $data = json_decode((string) $json, true);
    $env = [];
    if ($data && isset($data[0]['Config']['Env'])) {
        foreach ($data[0]['Config']['Env'] as $e) {
            $parts = explode('=', $e, 2);
            if (count($parts) === 2) {
                $env[$parts[0]] = $parts[1];
            }
        }
    }
    return $env;
}

/**
 * Get online players list and stats.
 *
 * @param string $id Container ID.
 * @param int $port Server port.
 * @param array $env Container environment.
 * @return array ['online' => int, 'max' => int, 'players' => array]
 */
function getOnlinePlayers(string $id, int $port, array $env): array
{
    $rconPass = $env['RCON_PASSWORD'] ?? '';
    // $rconPort = isset($env['RCON_PORT']) ? intval($env['RCON_PORT']) : 25575;
    $rconPort = isset($env['RCON_PORT']) ? intval($env['RCON_PORT']) : 25575;

    $stats = getMinecraftLiveStats($id, $port, $env);
    $online = $stats['online'];
    $max = $stats['max'];
    $players = [];

    $sanitizeName = function ($n) {
        return trim(preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $n));
    };

    // 1. From Sample
    if (!empty($stats['sample'])) {
        foreach ($stats['sample'] as $p) {
            if (!empty($p['name'])) {
                $players[] = ['name' => $sanitizeName($p['name'])];
            }
        }
    }

    // 2. From RCON
    if (empty($players) && $rconPass && $online > 0) {
        $rconOut = shell_exec("docker exec " . escapeshellarg($id) . " rcon-cli --port $rconPort --password " . escapeshellarg($rconPass) . " list 2>/dev/null");
        if ($rconOut) {
            $rconOut = trim((string) $rconOut);
            if (preg_match('/(?:online|players): (.*)$/i', $rconOut, $m)) {
                $names = explode(', ', $m[1]);
                foreach ($names as $name) {
                    $clean = $sanitizeName($name);
                    if ($clean !== '') {
                        $players[] = ['name' => $clean];
                    }
                }
            } elseif (!empty($rconOut)) {
                $cleanOut = preg_replace('/^There are .* online: /i', '', $rconOut);
                $names = preg_split('/[,\s]+/', $cleanOut);
                foreach ($names as $name) {
                    $clean = $sanitizeName($name);
                    if ($clean !== '' && !in_array(strtolower($clean), ['there', 'are', 'players', 'online', 'max'])) {
                        $players[] = ['name' => $clean];
                    }
                }
            }
        }
    }

    // 3. From mc-monitor fallback
    if (empty($players) && $online > 0) {
        $internalPort = isset($env['SERVER_PORT']) ? intval($env['SERVER_PORT']) : 25565;
        $statusRes = shell_exec("docker exec " . escapeshellarg($id) . " mc-monitor status --json 127.0.0.1:$internalPort 2>/dev/null");
        if ($statusRes) {
            $jd = json_decode((string) $statusRes, true);
            if (!empty($jd['players']['sample'])) {
                foreach ($jd['players']['sample'] as $p) {
                    if (!empty($p['name'])) {
                        $players[] = ['name' => $p['name']];
                    }
                }
            }
        }
    }

    // Identify Operators
    $ops = [];
    if ($rconPass) {
        $opOut = [];
        exec("docker exec " . escapeshellarg($id) . " rcon-cli --port $rconPort --password " . escapeshellarg($rconPass) . " \"op list\" 2>&1", $opOut);
        $fullOpOut = implode(' ', $opOut);
        if (preg_match('/(?:opped players|opped player|operators):? (.*)$/i', $fullOpOut, $m)) {
            $names = explode(',', $m[1]);
            foreach ($names as $n) {
                $ops[] = $sanitizeName($n);
            }
        }
    }

    // Fallback OPS
    if (empty($ops)) {
        $opsJsonRaw = shell_exec("docker exec " . escapeshellarg($id) . " cat ops.json 2>/dev/null");
        if ($opsJsonRaw) {
            $opsData = json_decode((string) $opsJsonRaw, true);
            if (is_array($opsData)) {
                foreach ($opsData as $entry) {
                    if (isset($entry['name'])) {
                        $ops[] = $entry['name'];
                    }
                }
            }
        }
    }

    // Set isOp
    if (!empty($ops)) {
        $opsLower = array_map('strtolower', $ops);
        foreach ($players as &$p) {
            $p['isOp'] = in_array(strtolower($p['name']), $opsLower);
        }
    } else {
        foreach ($players as &$p) {
            $p['isOp'] = false;
        }
    }

    return ['online' => $online, 'max' => $max, 'players' => $players];
}

/**
 * Get banned players list.
 *
 * @param string $id Container ID.
 * @param array $env Container environment.
 * @return array List of banned players.
 */
function getBannedPlayers(string $id, array $env): array
{
    $rconPass = $env['RCON_PASSWORD'] ?? '';
    $rconPort = isset($env['RCON_PORT']) ? intval($env['RCON_PORT']) : 25575;

    $banned = [];

    if ($rconPass) {
        $out = shell_exec("docker exec " . escapeshellarg($id) . " rcon-cli --port $rconPort --password " . escapeshellarg($rconPass) . " \"banlist players\" 2>&1");
        $out = trim((string) $out);
        if ($out && stripos($out, 'no banned players') === false && preg_match('/:\s*(.*)$/i', $out, $m)) {
            $names = explode(', ', $m[1]);
            foreach ($names as $n) {
                $name = trim(preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $n));
                $name = trim(explode(' ', $name)[0]);
                if ($name && !in_array(strtolower($name), ['no', 'banned'])) {
                    $banned[] = ['name' => $name];
                }
            }
        }
    }

    if (empty($banned)) {
        $jsonContent = shell_exec("docker exec " . escapeshellarg($id) . " cat banned-players.json 2>/dev/null");
        if (!$jsonContent) {
            $jsonContent = shell_exec("docker exec " . escapeshellarg($id) . " cat /data/banned-players.json 2>/dev/null");
        }
        if ($jsonContent) {
            $jd = json_decode((string) $jsonContent, true);
            if (is_array($jd)) {
                foreach ($jd as $entry) {
                    if (!empty($entry['name'])) {
                        $banned[] = ['name' => $entry['name']];
                    }
                }
            }
        }
    }

    return array_values(array_unique($banned, SORT_REGULAR));
}
