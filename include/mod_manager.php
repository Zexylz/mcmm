<?php

/**
 * Scan a directory for Minecraft mod files (.jar) and extract metadata.
 *
 * @param string $dataDir The base directory containing the mods folder.
 * @return array List of mods with metadata.
 */
function scanServerMods(string $dataDir): array
{
    $modsDir = rtrim($dataDir, '/') . '/mods';
    if (!is_dir($modsDir)) {
        return [];
    }

    $mods = [];
    $cmd = 'find -L ' . escapeshellarg($modsDir) . ' -type f -name "*.jar"';
    exec($cmd, $files);

    // Debug logging setup at global level (tmp only)
    $logFile = '/tmp/mcmm_debug.log';
    file_put_contents($logFile, date('H:i:s') . " [SCANNER] Found " . count($files) . " files in $modsDir\n", FILE_APPEND);

    foreach ($files as $jarPath) {
        if (substr($jarPath, -4) !== '.jar') {
            continue;
        }

        $fileName = basename($jarPath);
        $fileSize = filesize($jarPath);
        $modInfo = extractModInfo($jarPath);

        if (strpos($fileName, 'forge-') === 0 || strpos($fileName, 'fabric-loader') === 0) {
            continue;
        }

        $mods[] = [
            'fileName' => $fileName,
            'filePath' => $jarPath,
            'relativePath' => str_replace($modsDir . '/', '', $jarPath), // Keep track of subfolders
            'fileSize' => $fileSize,
            'modId' => $modInfo['modId'] ?? '',
            'name' => $modInfo['name'] ?? $fileName,
            'version' => $modInfo['version'] ?? 'Unknown',
            'authors' => $modInfo['authors'] ?? [],
            'description' => $modInfo['description'] ?? '',
            'mcVersion' => $modInfo['mcVersion'] ?? '',
            'loader' => $modInfo['loader'] ?? 'forge',
            'curseforgeId' => $modInfo['curseforgeId'] ?? null,
            'modrinthId' => $modInfo['modrinthId'] ?? null,
            'hash' => $modInfo['hash'] ?? '',
        ];
    }

    return $mods;
}

/**
 * Extract mod metadata from a JAR file.
 *
 * Checks mods.toml, mcmod.info, fabric.mod.json, and quilt.mod.json.
 *
 * @param string $jarPath Path to the JAR file.
 * @return array Extracted mod information.
 */
function extractModInfo(string $jarPath): array
{
    $info = [
        'modId' => '',
        'name' => '',
        'version' => '',
        'authors' => [],
        'description' => '',
        'mcVersion' => '',
        'loader' => 'forge',
        'curseforgeId' => null,
        'modrinthId' => null,
        'hash' => '',
    ];

    // Check persistent cache first
    // Use fast file metadata for key (Path + Size + MTime) instead of hashing the path
    $stat = stat($jarPath);

    global $MCMM_DEBUG_LOG;
    if (!isset($MCMM_DEBUG_LOG)) {
        $MCMM_DEBUG_LOG = [];
    }

    // BUMP CACHE VERSION to mod_mm6_ to force refresh and capture logs
    $key = 'mod_mm6_' . md5($jarPath . $stat['size'] . $stat['mtime']);
    if ($cached = mcmm_cache_get($key)) {
        return $cached;
    }

    // Debug logging setup
    $logFile = '/tmp/mcmm_debug.log'; // Use /tmp to ensure writability on Unraid
    $localLogFile = dirname(dirname(__FILE__)) . '/scan_debug.log';

    $log = function ($msg) use ($logFile, $localLogFile, $jarPath) {
        global $MCMM_DEBUG_LOG;
        $entry = date('H:i:s') . " [" . basename($jarPath) . "] " . $msg;
        $MCMM_DEBUG_LOG[] = $entry;

        file_put_contents($logFile, $entry . "\n", FILE_APPEND);
        @file_put_contents($localLogFile, $entry . "\n", FILE_APPEND);
    };

    $log("Cache Miss - Scanning real file...");

    $zip = new ZipArchive();
    $res = $zip->open($jarPath);
    if ($res === true) {
        $foundMetadata = false;

        // Try mods.toml
        if ($zip->locateName('META-INF/mods.toml') !== false) {
            $tomlContent = $zip->getFromName('META-INF/mods.toml');
            if ($tomlContent) {
                // @phpstan-ignore-next-line
                $info = array_merge($info, parseModsToml($tomlContent));
                if (!empty($info['name'])) {
                    $foundMetadata = true;
                }
            }
        } elseif ($zip->locateName('META-INF/neoforge.mods.toml') !== false) {
            $tomlContent = $zip->getFromName('META-INF/neoforge.mods.toml');
            if ($tomlContent) {
                // @phpstan-ignore-next-line
                $info = array_merge($info, parseModsToml($tomlContent));
                if (!empty($info['name'])) {
                    $foundMetadata = true;
                    // NeoForge usually implies neoforge loader, but we keep 'forge' generic or switch if needed
                    $info['loader'] = 'neoforge';
                }
            }
        } elseif ($zip->locateName('mods.toml') !== false) {
            $tomlContent = $zip->getFromName('mods.toml');
            if ($tomlContent) {
                // @phpstan-ignore-next-line
                $info = array_merge($info, parseModsToml($tomlContent));
                if (!empty($info['name'])) {
                    $foundMetadata = true;
                }
            }
        }

        // Try mcmod.info
        if (!$foundMetadata && $zip->locateName('mcmod.info') !== false) {
            $mcmodContent = $zip->getFromName('mcmod.info');
            if ($mcmodContent) {
                $parsed = parseMcmodInfo($mcmodContent);
                if (empty($parsed)) {
                    $log("Found mcmod.info but failed to parse JSON.");
                } else {
                    $item = array_merge($info, $parsed);
                    if (!empty($item['name'])) {
                        $foundMetadata = true;
                        $info = $item;
                    }
                }
            }
        }

        // Try fabric.mod.json
        if (!$foundMetadata && $zip->locateName('fabric.mod.json') !== false) {
            $fabricContent = $zip->getFromName('fabric.mod.json');
            if ($fabricContent) {
                $parsed = parseFabricModJson($fabricContent);
                if (empty($parsed)) {
                    $log("Found fabric.mod.json but failed to parse JSON (Check for syntax errors).");
                } else {
                    $info = array_merge($info, $parsed);
                    $info['loader'] = 'fabric';
                    $foundMetadata = true;
                }
            }
        }

        // Try quilt.mod.json
        if (!$foundMetadata && $zip->locateName('quilt.mod.json') !== false) {
            $quiltContent = $zip->getFromName('quilt.mod.json');
            if ($quiltContent) {
                $parsed = parseQuiltModJson($quiltContent);
                if (empty($parsed)) {
                    $log("Found quilt.mod.json but failed to parse JSON.");
                } else {
                    $info = array_merge($info, $parsed);
                    $info['loader'] = 'quilt';
                    $foundMetadata = true;
                }
            }
        }

        $zip->close();

        if (!$foundMetadata) {
            $log("Zip opened but NO valid metadata file found (checked mods.toml, neoforge.mods.toml, mcmod.info, fabric.mod.json, quilt.mod.json).");
        }
    } else {
        $log("Zip Open Failed. Error Code: $res");
    }

    // Calculate hash AFTER closing zip to avoid file locking conflicts
    $info['hash'] = computeMurmur2Hash($jarPath);

    if (!$info['version'] || $info['version'] === 'Unknown' || $info['version'] === '${file.jarVersion}') {
        $info['version'] = extractVersionFromFilename(basename($jarPath));
    }

    // Verify name
    if (empty($info['name'])) {
        $log("Final status: Unknown Name. Mod ID: " . ($info['modId'] ?? 'None'));
    }

    // Cache the result (long TTL as filemtime is part of key)
    mcmm_cache_set($key, $info, 86400 * 30);

    return $info;
}


/**
 * Parse Forge/NeoForge mods.toml content.
 *
 * @param string $content The raw content of mods.toml.
 * @return array Extracted metadata.
 */
function parseModsToml(string $content): array
{
    $info = [];

    // Improve regex to handle multiline strings (triple quotes) and varying whitespace
    // Note: This is still a heuristic/regex parser, not a full TOML parser.

    // 1. Clean up newlines for easier multiline matching if we want, but simple regex usually suffices for properties

    // Match: modId="example" or modId = "example" or modId='example'
    if (preg_match('/^\s*modId\s*=\s*["\']([^"\']+)["\']/m', $content, $m)) {
        $info['modId'] = $m[1];
    }

    // Match: version="1.0"
    if (preg_match('/^\s*version\s*=\s*["\']([^"\']+)["\']/m', $content, $m)) {
        $info['version'] = $m[1];
    }

    // Match: displayName="Example Mod"
    if (preg_match('/^\s*displayName\s*=\s*["\']([^"\']+)["\']/m', $content, $m)) {
        $info['name'] = $m[1];
    }

    // Match: description='''...''' or description="..."
    // Try triple quotes first
    if (preg_match('/^\s*description\s*=\s*\'\'\'(.*?)\'\'\'/ms', $content, $m)) {
        $info['description'] = trim($m[1]);
    } elseif (preg_match('/^\s*description\s*=\s*"""(.*?)"""/ms', $content, $m)) {
        $info['description'] = trim($m[1]);
    } elseif (preg_match('/^\s*description\s*=\s*["\']([^"\']+)["\']/m', $content, $m)) {
        $info['description'] = $m[1];
    }

    // Match: authors="Author" (Legacy/Single)
    if (preg_match('/^\s*authors\s*=\s*["\']([^"\']+)["\']/m', $content, $m)) {
        $info['authors'] = [$m[1]];
    }

    // If authors wasn't found, NeoForge used to output specific format or just same as above.
    // Sometimes it's inside `[[mods]]` block, but the regex above works if keys are unique enough.

    return $info;
}

/**
 * Strip comments from JSON and decode.
 */
function json_decode_clean(string $json): ?array
{
    // Remove BOM if present
    $json = preg_replace('/^\xEF\xBB\xBF/', '', $json);

    // Remove block comments
    $json = preg_replace('!/\*.*?\*/!s', '', $json);
    // Remove line comments
    $json = preg_replace('/\/\/.*$/m', '', $json);
    // Remove trailing commas (common in lenient json)
    $json = preg_replace('/,\s*([\]}])/s', '$1', $json);

    return json_decode($json, true);
}


/**
 * Parse legacy Forge mcmod.info content.
 *
 * @param string $content The raw JSON content of mcmod.info.
 * @return array Extracted metadata.
 */
function parseMcmodInfo(string $content): array
{
    $info = [];
    $data = json_decode_clean($content);

    if (!$data) {
        return $info;
    }

    $modData = is_array($data) && isset($data[0]) ? $data[0] : $data;

    if (isset($modData['modid'])) {
        $info['modId'] = $modData['modid'];
    }
    if (isset($modData['name'])) {
        $info['name'] = $modData['name'];
    }
    if (isset($modData['version'])) {
        $info['version'] = $modData['version'];
    }
    if (isset($modData['description'])) {
        $info['description'] = $modData['description'];
    }
    if (isset($modData['authorList']) && is_array($modData['authorList'])) {
        $info['authors'] = $modData['authorList'];
    }
    if (isset($modData['mcversion'])) {
        $info['mcVersion'] = $modData['mcversion'];
    }

    return $info;
}
/**
 * Parse Fabric fabric.mod.json content.
 *
 * @param string $content The raw JSON content of fabric.mod.json.
 * @return array Extracted metadata.
 */
function parseFabricModJson(string $content): array
{
    $info = [];
    $data = json_decode_clean($content);

    if (!$data) {
        return $info;
    }

    if (isset($data['id'])) {
        $info['modId'] = $data['id'];
    }
    if (isset($data['name'])) {
        $info['name'] = $data['name'];
    }
    if (isset($data['version'])) {
        $info['version'] = $data['version'];
    }
    if (isset($data['description'])) {
        $info['description'] = $data['description'];
    }
    if (isset($data['authors']) && is_array($data['authors'])) {
        $info['authors'] = array_map(function ($a) {
            return is_string($a) ? $a : ($a['name'] ?? 'Unknown');
        }, $data['authors']);
    }

    return $info;
}
/**
 * Parse Quilt quilt.mod.json content.
 *
 * @param string $content The raw JSON content of quilt.mod.json.
 * @return array Extracted metadata.
 */
function parseQuiltModJson(string $content): array
{
    $info = [];
    $data = json_decode_clean($content);

    if (!$data || !isset($data['quilt_loader'])) {
        return $info;
    }

    $loader = $data['quilt_loader'];

    if (isset($loader['id'])) {
        $info['modId'] = $loader['id'];
    }
    if (isset($loader['metadata']['name'])) {
        $info['name'] = $loader['metadata']['name'];
    }
    if (isset($loader['version'])) {
        $info['version'] = $loader['version'];
    }
    if (isset($loader['metadata']['description'])) {
        $info['description'] = $loader['metadata']['description'];
    }

    return $info;
}

/**
 * Extract version number from a mod filename as a fallback.
 *
 * @param string $filename The filename to parse.
 * @return string The extracted version or 'Unknown'.
 */
function extractVersionFromFilename(string $filename): string
{
    $name = preg_replace('/\.jar$/i', '', $filename);

    if (preg_match('/-(\d+\.\d+(?:\.\d+)?(?:\.\d+)?)(?:-|$)/', $name, $m)) {
        return $m[1];
    }
    if (preg_match('/_(\d+\.\d+(?:\.\d+)?(?:\.\d+)?)(?:_|$)/', $name, $m)) {
        return $m[1];
    }

    return 'Unknown';
}
/**
 * Check for updates for a list of mods via CurseForge.
 *
 * @param array  $mods      List of mod data with hashes.
 * @param string $apiKey    CurseForge API Key.
 * @param string $mcVersion Minecraft version for filtering.
 * @return array Updated mod list with update status.
 */
function checkModUpdates(array $mods, string $apiKey, string $mcVersion = ''): array
{
    if (!$apiKey) {
        return $mods;
    }

    $hashes = array_filter(array_column($mods, 'hash'));

    if (empty($hashes)) {
        return $mods;
    }

    $fingerprintData = queryCurseForgeFingerprintAPI($hashes, $apiKey);

    foreach ($mods as &$mod) {
        if (!$mod['hash']) {
            continue;
        }

        if (isset($fingerprintData[$mod['hash']])) {
            $cfData = $fingerprintData[$mod['hash']];
            $mod['curseforgeId'] = $cfData['id'];
            $mod['updateAvailable'] = $cfData['hasUpdate'] ?? false;
            $mod['latestVersion'] = $cfData['latestVersion'] ?? null;
            $mod['latestFileId'] = $cfData['latestFileId'] ?? null;
        }
    }

    return $mods;
}
/**
 * Query CurseForge Fingerprint API to identify mods by hash.
 *
 * @param array  $hashes List of SHA1 hashes.
 * @param string $apiKey CurseForge API Key.
 * @return array Mapping of hashes to mod data.
 */
function queryCurseForgeFingerprintAPI(array $hashes, string $apiKey): array
{
    $url = 'https://api.curseforge.com/v1/fingerprints';

    $postData = json_encode([
        'fingerprints' => array_map('intval', $hashes)
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return [];
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['data'])) {
        return [];
    }

    $results = [];
    foreach ($data['data']['exactMatches'] as $match) {
        $hash = $match['file']['fileFingerprint'] ?? null;
        if ($hash) {
            $results[$hash] = [
                'id' => $match['id'],
                'name' => $match['name'],
                'hasUpdate' => false,
            ];
        }
    }

    return $results;
}


/**
 * Download a mod file from a URL.
 *
 * @param string $downloadUrl  The URL to download from.
 * @param string $targetPath   Where to save the file.
 * @param string $expectedHash Optional SHA1 hash for verification.
 * @return bool True on success.
 */
function downloadMod(string $downloadUrl, string $targetPath, string $expectedHash = ''): bool
{
    $ch = curl_init($downloadUrl);
    $fp = fopen($targetPath, 'w');

    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);

    $success = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);
    fclose($fp);

    if (!$success || $httpCode !== 200) {
        @unlink($targetPath);
        return false;
    }

    if ($expectedHash) {
        $actualHash = sha1_file($targetPath);
        if ($actualHash !== $expectedHash) {
            @unlink($targetPath);
            return false;
        }
    }

    return true;
}

/**
 * Compute MurmurHash2 for a file (CurseForge specific implementation).
 *
 * @param string $filePath Path to the file.
 * @return int The calculated hash.
 */
function computeMurmur2Hash(string $filePath): int
{
    $seed = 1;
    $m = 0x5bd1e995;
    $r = 24;

    $fp = fopen($filePath, 'rb');
    if (!$fp) {
        return 0;
    }

    $len = filesize($filePath);
    $h = $seed ^ $len;

    // Process in chunks to save memory
    $bufferSize = 1024 * 64; // 64KB
    $remainder = '';

    while (!feof($fp)) {
        $chunk = fread($fp, $bufferSize);
        if ($chunk === false) {
            break;
        }

        // Prepend remainder from previous chunk
        if ($remainder !== '') {
            $chunk = $remainder . $chunk;
            $remainder = '';
        }

        $chunkLen = strlen($chunk);

        // If we have less than 4 bytes and end of file, this is the final tail
        if ($chunkLen < 4) {
            $remainder = $chunk;
            break;
        }

        // Process 4-byte blocks
        $processLen = $chunkLen - ($chunkLen % 4);
        $remainder = substr($chunk, $processLen);
        $toProcess = substr($chunk, 0, $processLen);

        $words = array_values(unpack('V*', $toProcess));
        foreach ($words as $k) {
            $k = ($k * $m) & 0xFFFFFFFF;
            $k ^= ($k >> $r);
            $k = ($k * $m) & 0xFFFFFFFF;

            $h = ($h * $m) & 0xFFFFFFFF;
            $h ^= $k;
        }
    }

    // Handle tail (0-3 bytes)
    if ($remainder !== '') {
        $tail = $remainder;
        $tailLen = strlen($tail);
        switch ($tailLen) {
            case 3:
                $h ^= (ord($tail[2]) << 16);
            // fallthrough
            case 2:
                $h ^= (ord($tail[1]) << 8);
            // fallthrough
            case 1:
                $h ^= ord($tail[0]);
                $h = ($h * $m) & 0xFFFFFFFF;
        }
    }

    fclose($fp);

    $h ^= ($h >> 13);
    $h = ($h * $m) & 0xFFFFFFFF;
    $h ^= ($h >> 15);

    return $h;
}
