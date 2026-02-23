<?php

use Aternos\CurseForgeApi\Client\CurseForgeAPIClient;

require_once __DIR__ . '/../vendor/autoload.php';
use Aternos\CurseForgeApi\Client\CursedFingerprintHelper;
use Aternos\ModrinthApi\Client\ModrinthAPIClient;

// Ensure dbg function exists if not included from api.php
if (!function_exists('dbg')) {
    function dbg($msg)
    {
        $logDir = defined('MCMM_LOG_DIR') ? MCMM_LOG_DIR : '/tmp';
        mcmm_mkdir($logDir, 0777, true);
        $logFile = $logDir . '/mcmm_debug.log';
        $entry = date('H:i:s') . " [MOD_MGR] " . print_r($msg, true) . "\n";
        @mcmm_file_put_contents($logFile, $entry, FILE_APPEND);
    }
}

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

    dbg("[SCANNER] Found " . count($files) . " files in $modsDir");

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
            'name' => !empty($modInfo['name']) ? $modInfo['name'] : $fileName, // Robust fallback
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
        'name' => null, // Initialize to null to allow fallback to filename
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

    // BUMP CACHE VERSION to mod_mm5_ to force refresh and capture logs
    $key = 'mod_mm5_' . md5($jarPath . $stat['size'] . $stat['mtime']);
    if ($cached = mcmm_cache_get($key)) {
        return $cached;
    }

    // Debug logging setup
    $logFile = (defined('MCMM_LOG_DIR') ? MCMM_LOG_DIR : '/tmp') . '/mcmm_debug.log';
    $localLogFile = dirname(dirname(__FILE__)) . '/scan_debug.log';

    $log = function ($msg) use ($logFile, $localLogFile, $jarPath) {
        global $MCMM_DEBUG_LOG;
        $entry = date('H:i:s') . " [" . basename($jarPath) . "] " . $msg;
        $MCMM_DEBUG_LOG[] = $entry;

        mcmm_mkdir(dirname($logFile), 0777, true);
        mcmm_file_put_contents($logFile, $entry . "\n", FILE_APPEND);
        @mcmm_file_put_contents($localLogFile, $entry . "\n", FILE_APPEND);
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
                $parsed = parseModsToml($tomlContent);
                if (!empty($parsed['name'])) {
                    $foundMetadata = true;
                }
                $info = array_merge($info, $parsed);
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
            $log("Zip opened but NO valid metadata file found (checked mods.toml, mcmod.info, fabric.mod.json, quilt.mod.json).");
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

    if (preg_match('/modId\s*=\s*["\']([^"\']+)["\']/', $content, $m)) {
        $info['modId'] = $m[1];
    }

    if (preg_match('/version\s*=\s*["\']([^"\']+)["\']/', $content, $m)) {
        $info['version'] = $m[1];
    }

    if (preg_match('/displayName\s*=\s*["\']([^"\']+)["\']/', $content, $m)) {
        $info['name'] = $m[1];
    }

    if (preg_match('/description\s*=\s*["\']([^"\']+)["\']/', $content, $m)) {
        $info['description'] = $m[1];
    }

    if (preg_match('/authors\s*=\s*["\']([^"\']+)["\']/', $content, $m)) {
        $info['authors'] = [$m[1]];
    }

    return $info;
}

/**
 * Strip comments from JSON and decode.
 */
function json_decode_clean(string $json): ?array
{
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
 */
function checkModUpdates(array $mods, string $apiKey, string $mcVersion = '', string $loader = 'forge'): array
{
    if (!$apiKey || empty($mods)) {
        return $mods;
    }

    try {
        $cfClient = new CurseForgeAPIClient($apiKey);
        $fingerprints = [];
        $modsByHash = []; // Map hash to mod array index

        // 1. Prepare Fingerprints
        foreach ($mods as $index => $m) {
            if (!empty($m['hash'])) {
                $fingerprints[] = (int) $m['hash'];
                $modsByHash[(int) $m['hash']] = $index;
            }
        }

        $projectIds = [];
        $installedFileIds = []; // Map curseforgeId -> installed file ID

        // 2. Identify Mods via Fingerprints
        if (!empty($fingerprints)) {
            $matchesResult = $cfClient->getFilesByFingerPrintMatches($fingerprints);
            foreach ($matchesResult->getExactMatches() as $match) {
                $file = $match->getFile();
                $hash = $file->getFileFingerprint();
                $idx = $modsByHash[$hash] ?? null;

                if ($idx !== null) {
                    $modId = $match->getId();
                    $fileId = $file->getId();

                    $mods[$idx]['curseforgeId'] = $modId;
                    $mods[$idx]['identified'] = true;
                    // Store installed file ID for comparison
                    $mods[$idx]['installedFileId'] = $fileId;

                    // Deduplicate project IDs for batch fetch
                    $projectIds[$modId] = $modId;
                    $installedFileIds[$modId] = $fileId;
                }
            }
        }

        // Add IDs from mods that were already identified (e.g. from installed_mods.json)
        foreach ($mods as $index => $m) {
            if (!empty($m['curseforgeId'])) {
                $pid = $m['curseforgeId'];
                $projectIds[$pid] = $pid;
                // If we don't have installedFileId from fingerprint, we can't reliably check updates by ID,
                // but we will try if we can match the version string later (omitted for reliability).
            }
        }

        if (empty($projectIds)) {
            return $mods;
        }

        // 3. Batch Fetch Project Details to get Latest Files
        $projects = $cfClient->getMods(array_values($projectIds));

        // 4. Compare Versions
        foreach ($projects as $project) {
            $pId = $project->getData()->getId();

            // Filter compatible files
            $candidateFile = null;

            // getLatestFiles() returns plain array or objects depending on SDK version.
            // Assuming generic object access or array. The Lint feedback suggests SDK is typed.
            // Using standard getters based on usage elsewhere in API.

            foreach ($project->getData()->getLatestFiles() as $file) {
                // Check MC Version compatibility
                $versions = $file->getGameVersions(); // returns array of strings
                if (!in_array($mcVersion, $versions)) {
                    continue;
                }

                // Check Loader compatibility (case-insensitive)
                $loaderMatch = false;
                foreach ($versions as $v) {
                    if (strcasecmp($v, $loader) === 0) {
                        $loaderMatch = true;
                        break;
                    }
                    if ($loader === 'quilt' && strcasecmp($v, 'fabric') === 0) {
                        // Quilt can often run Fabric mods
                        $loaderMatch = true;
                        break;
                    }
                }
                if (!$loaderMatch) {
                    continue;
                }

                // Found a compatible file. Logic: File with largest ID is newest.
                if (!$candidateFile || $file->getId() > $candidateFile->getId()) {
                    $candidateFile = $file;
                }
            }

            if ($candidateFile) {
                // Check against installed mods
                foreach ($mods as &$mod) {
                    if (($mod['curseforgeId'] ?? 0) == $pId) {
                        $mod['latestFileId'] = $candidateFile->getId();
                        $mod['latestFileName'] = $candidateFile->getDisplayName();

                        $installedId = $mod['installedFileId'] ?? 0;
                        if ($installedId > 0 && $candidateFile->getId() > $installedId) {
                            $mod['updateAvailable'] = true;
                        } else {
                            $mod['updateAvailable'] = false;
                        }
                    }
                }
            }
        }

        // 5. Modrinth Update Check
        $mrMods = array_filter($mods, fn($m) => ($m['platform'] ?? '') === 'modrinth' && !empty($m['modId']));
        if (!empty($mrMods)) {
            $mrClient = new ModrinthAPIClient();
            foreach ($mrMods as $idx => $m) {
                try {
                    $pid = $m['modId'];
                    $loaders = $loader ? [strtolower($loader)] : null;
                    if ($loaders && $loaders[0] === 'quilt') {
                        $loaders[] = 'fabric';
                    }
                    $versions = $mrClient->getProjectVersions($pid, $loaders, $mcVersion ? [$mcVersion] : null);
                    if (!empty($versions)) {
                        $latest = $versions[0];
                        $latestId = $latest->getData()->getId();
                        $installedId = $m['installedFileId'] ?? $m['fileId'] ?? '';

                        $mods[$idx]['latestFileId'] = $latestId;
                        $mods[$idx]['latestFileName'] = $latest->getData()->getName() ?? $latest->getData()->getVersionNumber();

                        if ($installedId && $latestId !== $installedId) {
                            $mods[$idx]['updateAvailable'] = true;
                        } else {
                            $mods[$idx]['updateAvailable'] = false;
                        }
                    }
                } catch (\Throwable $e) {
                    dbg("Modrinth Update Error ($pid): " . $e->getMessage());
                }
            }
        }
    } catch (\Throwable $e) {
        dbg("Update Check Error: " . $e->getMessage());
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
    return (int) CursedFingerprintHelper::getFingerprintFromFile($filePath);
}
