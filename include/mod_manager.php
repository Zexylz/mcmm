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

    foreach ($files as $jarPath) {
        if (substr($jarPath, -4) !== '.jar')
            continue;

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

    $info['hash'] = sha1_file($jarPath);

    $tomlContent = shell_exec('unzip -p ' . escapeshellarg($jarPath) . ' META-INF/mods.toml 2>/dev/null');
    if ($tomlContent) {
        $info = array_merge($info, parseModsToml($tomlContent));
    }

    if (!$info['modId']) {
        $mcmodContent = shell_exec('unzip -p ' . escapeshellarg($jarPath) . ' mcmod.info 2>/dev/null');
        if ($mcmodContent) {
            $info = array_merge($info, parseMcmodInfo($mcmodContent));
        }
    }

    if (!$info['modId']) {
        $fabricContent = shell_exec('unzip -p ' . escapeshellarg($jarPath) . ' fabric.mod.json 2>/dev/null');
        if ($fabricContent) {
            $info = array_merge($info, parseFabricModJson($fabricContent));
            $info['loader'] = 'fabric';
        }
    }

    if (!$info['modId']) {
        $quiltContent = shell_exec('unzip -p ' . escapeshellarg($jarPath) . ' quilt.mod.json 2>/dev/null');
        if ($quiltContent) {
            $info = array_merge($info, parseQuiltModJson($quiltContent));
            $info['loader'] = 'quilt';
        }
    }

    if (!$info['version'] || $info['version'] === 'Unknown') {
        $info['version'] = extractVersionFromFilename(basename($jarPath));
    }

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
 * Parse legacy Forge mcmod.info content.
 *
 * @param string $content The raw JSON content of mcmod.info.
 * @return array Extracted metadata.
 */
function parseMcmodInfo(string $content): array
{
    $info = [];
    $data = @json_decode($content, true);

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
    $data = @json_decode($content, true);

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
    $data = @json_decode($content, true);

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
