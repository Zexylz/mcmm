<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/mod_manager.php';

use Aternos\CurseForgeApi\Client\CurseForgeAPIClient;
use Aternos\CurseForgeApi\Client\Options\ModSearch\ModSearchOptions;
use Aternos\CurseForgeApi\Client\Options\ModSearch\ModLoaderType;
use Aternos\CurseForgeApi\Client\Options\ModSearch\ModSearchSortField;
use Aternos\CurseForgeApi\Client\Options\ModSearch\SortOrder;
use Aternos\CurseForgeApi\Client\Options\ModFiles\ModFilesOptions;
use Aternos\CurseForgeApi\Client\CursedFingerprintHelper;
use Aternos\ModrinthApi\Client\ModrinthAPIClient;
use Aternos\ModrinthApi\Client\Options\ProjectSearchOptions;
use Aternos\ModrinthApi\Client\Options\Facets\Facet;
use Aternos\ModrinthApi\Client\Options\Facets\FacetANDGroup;
use Aternos\ModrinthApi\Client\Options\Facets\FacetType;
use GuzzleHttp\Client;
use Aternos\ModrinthApi\Client\Options\SearchIndex;

/**
 * Handle mod_list action
 */
/**
 * Handle mod_list action (unified, cached, performant)
 */
function handle_mod_list($id, $config)
{
    require_once __DIR__ . '/mod_manager.php';
    $id = safeContainerName($id);
    $forceRefresh = isset($_GET['refresh']);

    if (!$id)
        jsonResponse(['success' => false, 'error' => 'Missing ID'], 400);

    $metaDir = getServerMetadataDir($id);
    $cacheFile = "$metaDir/mods_cache.json";
    $mods = [];
    $cached = false;

    // 1. Try Cache First
    if (!$forceRefresh && file_exists($cacheFile)) {
        $cacheData = json_decode(file_get_contents($cacheFile), true);
        if ($cacheData && (time() - ($cacheData['timestamp'] ?? 0)) < 3600) {
            $mods = $cacheData['mods'] ?? [];
            $cached = true;
        }
    }

    if (!$cached) {
        // 2. Scan Filesystem (Cache Miss)
        $dataDir = getContainerDataDirById($id);
        if (!$dataDir)
            jsonResponse(['success' => false, 'error' => 'Data directory not found'], 404);

        $mods = scanServerMods($dataDir);

        // 3. Hydrate from Global Metadata (mod_ids.json)
        $globalFile = '/mnt/user/appdata/mcmm/mod_ids.json';
        $globalData = file_exists($globalFile) ? json_decode(file_get_contents($globalFile), true) ?: [] : [];

        // 4. Hydrate from Local Server Metadata (installed_mods.json)
        $localMetaFile = "$metaDir/installed_mods.json";
        $localMetadata = file_exists($localMetaFile) ? (json_decode(file_get_contents($localMetaFile), true) ?: []) : [];
        $localByFile = [];
        foreach ($localMetadata as $key => $m) {
            // New structure keys by filename, old keys by ID but contains fileName property
            $f = (isset($m['fileName']) && !empty($m['fileName'])) ? $m['fileName'] : (is_string($key) && strpos($key, '.jar') !== false ? $key : null);
            if ($f) {
                $localByFile[$f] = $m;
            }
        }

        foreach ($mods as &$mod) {
            $mid = $mod['modId'] ?? null;
            $fileName = $mod['fileName'];

            // Priority 1: Global cache by modId
            if ($mid && isset($globalData[$mid]) && is_array($globalData[$mid])) {
                $mod = array_merge($mod, $globalData[$mid]);
                $mod['identified'] = true;
            }

            // Priority 2: Local metadata by filename (captured during install/batch scan)
            if (isset($localByFile[$fileName])) {
                $info = $localByFile[$fileName];
                $mod['modId'] = $info['modId'] ?? $info['id'] ?? $mod['modId'];
                $mod['name'] = !empty($info['name']) ? $info['name'] : (!empty($mod['name']) ? $mod['name'] : (!empty($mod['displayName']) ? $mod['displayName'] : ''));
                $mod['fileId'] = $info['fileId'] ?? $info['installedFileId'] ?? $mod['fileId'] ?? '';
                $mod['icon'] = $info['logo'] ?? $info['icon'] ?? $mod['icon'] ?? '';
                $mod['author'] = $info['author'] ?? $mod['authors'][0] ?? 'Unknown';
                $mod['summary'] = $info['summary'] ?? $mod['description'] ?? '';
                $mod['mcVersion'] = $info['mcVersion'] ?? $mod['mcVersion'] ?? '';
                $mod['platform'] = $info['platform'] ?? 'curseforge';
                $mod['identified'] = true;
                $mod['updateAvailable'] = $info['updateAvailable'] ?? false;
                if ($mod['updateAvailable']) {
                    $mod['latestFileId'] = $info['latestFileId'] ?? null;
                    $mod['latestFileName'] = $info['latestFileName'] ?? null;
                }
                if (!empty($info['unidentified'])) {
                    $mod['unidentified'] = true;
                }
            }

            $mod['needsIdentification'] = empty($mod['identified']);
        }
        unset($mod);

        // 5. Save Cache
        mcmm_mkdir(dirname($cacheFile), 0755, true);
        @mcmm_file_put_contents($cacheFile, json_encode([
            'timestamp' => time(),
            'serverId' => $id,
            'mods' => $mods
        ], JSON_PRETTY_PRINT));
    }

    jsonResponse(['success' => true, 'data' => $mods, 'count' => count($mods), 'cached' => $cached]);
}

/**
 * Handle check_updates action
 */
function handle_check_updates($id, $config)
{
    $id = safeContainerName($id);
    if (!$id)
        jsonResponse(['success' => false, 'error' => 'Missing server ID'], 400);

    $metaDir = getServerMetadataDir($id);
    $metaFile = "$metaDir/metadata.json";
    $metadata = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : [];

    $mcVer = $_GET['mc_version'] ?? ($metadata['mc_version'] ?? '');
    $loader = strtolower($_GET['loader'] ?? ($metadata['loader'] ?? ''));
    $apiKey = $config['curseforge_api_key'] ?? '';

    dbg("[UPDATES] Check for $id. MC: $mcVer, Loader: $loader. " . (empty($apiKey) ? "NO API KEY!" : "API Key present."));

    $updateCacheKey = "srv_updates_$id";
    $cachedUpdates = mcmm_cache_get($updateCacheKey);
    // Bypass cache if refresh is requested or while debugging
    if ($cachedUpdates !== null && !isset($_GET['refresh']))
        jsonResponse(['success' => true, 'updates' => $cachedUpdates]);

    $metaFile = "$metaDir/installed_mods.json";
    if (!file_exists($metaFile)) {
        $dataDir = getContainerDataDirById($id);
        if ($dataDir) {
            $metaFile = rtrim($dataDir, '/') . '/installed_mods.json';
        }
    }

    if (!file_exists($metaFile))
        jsonResponse(['success' => true, 'updates' => []]);

    $installed = json_decode(file_get_contents($metaFile), true) ?: [];
    if (empty($installed))
        jsonResponse(['success' => true, 'updates' => []]);

    $cfIds = [];
    $mrIds = [];
    foreach ($installed as $fileName => $info) {
        $mId = $info['modId'] ?? null;
        if (!$mId)
            continue;

        if (($info['platform'] ?? '') === 'modrinth')
            $mrIds[] = $mId;
        else
            $cfIds[] = (int) $mId;
    }

    $updates = [];
    // 1. Check CurseForge
    if (!empty($cfIds) && !empty($apiKey)) {
        try {
            $cfClient = new CurseForgeAPIClient($apiKey);
            $cfMods = $cfClient->getMods($cfIds);
            $loaderInt = 0;
            switch (strtolower($loader)) {
                case 'forge':
                    $loaderInt = 1;
                    break;
                case 'fabric':
                    $loaderInt = 4;
                    break;
                case 'quilt':
                    $loaderInt = 5;
                    break;
                case 'neoforge':
                    $loaderInt = 6;
                    break;
            }

            foreach ($cfMods as $m) {
                $latestId = null;
                $latestName = null;

                foreach ($m->getData()->getLatestFileIndexes() as $idx) {
                    $idxLoader = $idx->getModLoader();
                    $loaderMatch = (!$loaderInt || $idxLoader === $loaderInt || $idxLoader === 0);

                    if ((!$mcVer || $idx->getGameVersion() === $mcVer) && $loaderMatch) {
                        if (!$latestId || $idx->getFileId() > $latestId) {
                            $latestId = $idx->getFileId();
                            $latestName = $idx->getFilename();
                        }
                    }
                }

                if (!$latestId) {
                    foreach ($m->getData()->getLatestFiles() as $file) {
                        $vers = $file->getData()->getGameVersions();
                        $loaderMatch = (!$loader || in_array(strtolower($loader), array_map('strtolower', $vers)) || in_array('any', array_map('strtolower', $vers)));

                        if ((!$mcVer || in_array($mcVer, $vers)) && $loaderMatch) {
                            $latestId = $file->getData()->getId();
                            $latestName = $file->getData()->getFileName();
                            break;
                        }
                    }
                }

                if ($latestId) {
                    $updates[$m->getData()->getId()] = [
                        'latestFileId' => $latestId,
                        'latestFileName' => $latestName,
                        'name' => $m->getData()->getName()
                    ];
                }
            }

            // Enhanced Modpack check for CurseForge
            if (!empty($metadata['modpack_id'])) {
                $mpId = (int) $metadata['modpack_id'];
                $mp = $cfClient->getMod($mpId);
                if ($mp) {
                    $latestMpId = null;
                    $latestMpName = null;

                    // Try to find compatible modpack file
                    foreach ($mp->getData()->getLatestFileIndexes() as $idx) {
                        $idxLoader = $idx->getModLoader();
                        $loaderMatch = (!$loaderInt || $idxLoader === $loaderInt || $idxLoader === 0);

                        if ((!$mcVer || $idx->getGameVersion() === $mcVer) && $loaderMatch) {
                            if (!$latestMpId || $idx->getFileId() > $latestMpId) {
                                $latestMpId = $idx->getFileId();
                                $latestMpName = $idx->getFilename();
                            }
                        }
                    }

                    if (!$latestMpId) {
                        $latestMpId = $mp->getData()->getMainFileId();
                    }

                    if ($latestMpId) {
                        $updates[$mpId] = [
                            'latestFileId' => $latestMpId,
                            'latestFileName' => $latestMpName,
                            'name' => $mp->getData()->getName(),
                            'is_modpack' => true
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            dbg("[UPDATES] CF Error: " . $e->getMessage());
        }
    }

    // 2. Check Modrinth
    if (!empty($mrIds) || !empty($metadata['modpack_id_modrinth'])) {
        try {
            $mrClient = new ModrinthAPIClient();

            // Collect all IDs to check
            $idsToCheck = $mrIds;
            $modpackMrId = $metadata['modpack_id_modrinth'] ?? $metadata['modpack_id'] ?? null;
            // If modpackId is alphanumeric, it might be Modrinth
            if ($modpackMrId && !is_numeric($modpackMrId)) {
                $idsToCheck[] = $modpackMrId;
            }

            foreach ($idsToCheck as $mid) {
                // If ID is numeric, it's likely CF, skip in Modrinth check
                if (is_numeric($mid))
                    continue;

                $loaders = $loader ? [strtolower($loader)] : null;
                if ($loaders && $loaders[0] === 'quilt')
                    $loaders[] = 'fabric';

                $versions = $mrClient->getProjectVersions($mid, $loaders, $mcVer ? [$mcVer] : null);
                if (!empty($versions)) {
                    $latest = $versions[0]->getData();
                    $updates[$mid] = [
                        'latestFileId' => $latest->getId(),
                        'latestFileName' => $latest->getName() ?? $latest->getVersionNumber(),
                        'name' => $mid,
                        'is_modpack' => ($mid === $modpackMrId)
                    ];
                }
            }
        } catch (\Throwable $e) {
            dbg("[UPDATES] MR Error: " . $e->getMessage());
        }
    }

    // 3. PERSIST to installed_mods.json so indicators survive refresh
    $updated = false;
    foreach ($installed as $f => &$info) {
        $mId = $info['modId'] ?? null;
        if ($mId && isset($updates[$mId])) {
            $update = $updates[$mId];
            $installedFileId = $info['fileId'] ?? '';
            $latestFileId = $update['latestFileId'] ?? '';

            if ($latestFileId && (string) $latestFileId !== (string) $installedFileId) {
                $info['updateAvailable'] = true;
                $info['latestFileId'] = $latestFileId;
                $info['latestFileName'] = $update['latestFileName'] ?? '';
                $updated = true;
            } else {
                $info['updateAvailable'] = false;
            }
        }
    }
    unset($info);

    if ($updated) {
        dbg("[UPDATES] Found updates for " . count(array_filter($installed, fn($m) => $m['updateAvailable'] ?? false)) . " mods. Persisting and invalidating list cache.");
        @file_put_contents($metaFile, json_encode($installed, JSON_PRETTY_PRINT));
        @unlink($metaDir . '/mods_cache.json');
    }

    mcmm_cache_set($updateCacheKey, $updates, 3600);
    jsonResponse(['success' => true, 'updates' => $updates]);
}

/**
 * Handle mod_search action
 */
function handle_mod_search($config)
{
    $source = strtolower($_GET['source'] ?? 'curseforge');
    $search = $_GET['search'] ?? $_GET['q'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $pageSize = max(1, min(100, intval($_GET['page_size'] ?? 20)));
    $mcVer = $_GET['mc_version'] ?? '';
    $loader = $_GET['loader'] ?? '';

    try {
        if ($source === 'modrinth') {
            $mrClient = new ModrinthAPIClient();
            $facetList = [new Facet(FacetType::PROJECT_TYPE, 'mod')];
            if ($mcVer) {
                $facetList[] = new Facet(FacetType::VERSIONS, $mcVer);
            }
            if ($loader) {
                $facetList[] = new Facet(FacetType::CATEGORIES, $loader);
            }

            $opts = new ProjectSearchOptions(
                query: $search,
                facets: new FacetANDGroup($facetList),
                offset: ($page - 1) * $pageSize,
                limit: $pageSize
            );
            $res = $mrClient->searchProjects($opts);
            $results = [];
            foreach ($res->getResults() as $hit) {
                $results[] = [
                    'id' => $hit->getData()->getProjectId(),
                    'name' => $hit->getData()->getTitle(),
                    'author' => $hit->getData()->getAuthor(),
                    'summary' => $hit->getData()->getDescription(),
                    'icon' => $hit->getData()->getIconUrl()
                ];
            }
            jsonResponse(['success' => true, 'data' => $results, 'total' => 1000]); // Modrinth doesn't give exact total easily
        } else {
            $apiKey = $config['curseforge_api_key'] ?? '';
            if (empty($apiKey)) {
                jsonResponse(['success' => false, 'error' => 'API Key missing'], 400);
            }

            $cfClient = new CurseForgeAPIClient($apiKey);
            $opts = new ModSearchOptions(432);
            $opts->setSearchFilter($search);
            $opts->setPageSize($pageSize);
            $opts->setOffset(($page - 1) * $pageSize);
            if ($mcVer)
                $opts->setGameVersion($mcVer);
            if ($loader) {
                switch (strtolower($loader)) {
                    case 'forge':
                        $opts->setModLoaderType(ModLoaderType::FORGE);
                        break;
                    case 'fabric':
                        $opts->setModLoaderType(ModLoaderType::FABRIC);
                        break;
                    case 'quilt':
                        $opts->setModLoaderType(ModLoaderType::QUILT);
                        break;
                    case 'neoforge':
                        $opts->setModLoaderType(ModLoaderType::NEOFORGE);
                        break;
                }
            }

            $res = $cfClient->searchMods($opts);
            $results = [];
            foreach ($res->getResults() as $m) {
                $logo = $m->getData()->getLogo();
                $authors = $m->getData()->getAuthors();
                $results[] = [
                    'id' => $m->getData()->getId(),
                    'name' => $m->getData()->getName(),
                    'author' => !empty($authors) ? $authors[0]->getName() : 'Unknown',
                    'summary' => $m->getData()->getSummary(),
                    'icon' => $logo ? ($logo->getThumbnailUrl() ?: $logo->getUrl()) : ''
                ];
            }
            jsonResponse(['success' => true, 'data' => $results, 'total' => $res->getPagination()->getTotalCount()]);
        }
    } catch (\Throwable $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

/**
 * Handle mod_identify_batch action
 */
function handle_mod_identify_batch($id, $config)
{
    try {
        @set_time_limit(600);
        $id = safeContainerName($id);
        $files = json_decode(file_get_contents('php://input'), true) ?: [];
        if (empty($files) && !empty($_GET['files']))
            $files = json_decode($_GET['files'], true) ?: [];

        if (!$id || empty($files))
            jsonResponse(['success' => false, 'error' => 'Missing parameters'], 400);

        $results = [];
        $newMetadata = []; // Results from this batch, merged into disk at end
        $metaDir = getServerMetadataDir($id);
        $metaFile = "$metaDir/installed_mods.json";

        require_once __DIR__ . '/mod_manager.php';
        $dataDir = getContainerDataDirById($id);
        $manifestMetadata = $dataDir ? loadModsFromManifest($dataDir) : [];

        $apiKey = $config['curseforge_api_key'] ?? '';
        $cfClient = !empty($apiKey) ? new CurseForgeAPIClient($apiKey) : null;
        $mrClient = new ModrinthAPIClient();

        $manifestMap = [];
        $unknownFiles = [];

        // Pass 1: Categorize files via Manifest
        foreach ($files as $filename) {
            $foundId = null;
            foreach ($manifestMetadata as $mid => $info) {
                if (($info['fileName'] ?? '') === $filename) {
                    $foundId = $mid;
                    break;
                }
            }
            if (!$foundId) {
                // Try to find by filename in manifest
                foreach ($manifestMetadata as $mid => $info) {
                    if (isset($info['fileName']) && strcasecmp($info['fileName'], $filename) === 0) {
                        $foundId = $mid;
                        break;
                    }
                }
            }

            if (!$foundId) {
                $diskBase = strtolower(pathinfo($filename, PATHINFO_FILENAME));
                // Remove version numbers for broader search
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

            if ($foundId && $cfClient) {
                $manifestMap[$filename] = (int) $foundId;
            } else {
                $unknownFiles[] = $filename;
            }
        }

        dbg("[IDENTIFY] Categorized: " . count($manifestMap) . " manifest matches, " . count($unknownFiles) . " unknown files.");

        // Pass 2: Batch fetch CurseForge details for manifest mods
        if (!empty($manifestMap) && $cfClient) {
            try {
                $projectIds = array_unique(array_values($manifestMap));
                $cfMods = $cfClient->getMods($projectIds);
                $cfById = [];
                foreach ($cfMods as $m)
                    $cfById[$m->getData()->getId()] = $m;

                foreach ($manifestMap as $filename => $pid) {
                    if (isset($cfById[$pid])) {
                        $m = $cfById[$pid];
                        $authors = $m->getData()->getAuthors();
                        $logo = $m->getData()->getLogo();
                        $details = [
                            'id' => $m->getData()->getId(),
                            'name' => $m->getData()->getName(),
                            'author' => !empty($authors) ? $authors[0]->getName() : 'Unknown',
                            'summary' => $m->getData()->getSummary(),
                            'icon' => $logo ? ($logo->getThumbnailUrl() ?: $logo->getUrl()) : '',
                            'latestFileId' => $m->getData()->getMainFileId(),
                            'platform' => 'curseforge'
                        ];
                        $results[$filename] = $details;
                        $newMetadata[$filename] = array_merge([
                            'modId' => $pid,
                            'name' => !empty($details['name']) ? $details['name'] : $filename,
                            'platform' => 'curseforge',
                            'fileName' => $filename,
                            'fileId' => $manifestMetadata[$pid]['fileID'] ?? $details['latestFileId'],
                            'logo' => $details['icon'],
                            'author' => $details['author'],
                            'summary' => $details['summary'],
                            'mcVersion' => $manifestMetadata[$pid]['mcVersion'] ?? 'Various',
                            'installedAt' => time()
                        ], $newMetadata[$filename] ?? []);
                    }
                }
            } catch (\Throwable $e) {
                dbg("CF Batch Error: " . $e->getMessage());
            }
        }

        // Pass 3: Fingerprint Matching
        $modsDir = $dataDir ? rtrim($dataDir, '/') . '/mods' : null;
        if (!empty($unknownFiles) && $cfClient && $modsDir && is_dir($modsDir)) {
            $hashes = [];
            $fingerprints = [];
            foreach ($unknownFiles as $file) {
                $fullPath = "$modsDir/$file";
                if (file_exists($fullPath)) {
                    $hash = (int) CursedFingerprintHelper::getFingerprintFromFile($fullPath);
                    if ($hash > 0) {
                        $hashes[$hash] = $file;
                        $fingerprints[] = $hash;
                    }
                }
            }
            if (!empty($fingerprints)) {
                try {
                    $matchesResult = $cfClient->getFilesByFingerPrintMatches($fingerprints);
                    foreach ($matchesResult->getExactMatches() as $match) {
                        $projId = $match->getId();
                        $fileObj = $match->getFile();
                        $matchedHash = $fileObj->getFileFingerprint();

                        $foundFile = $hashes[$matchedHash] ?? null;
                        if ($foundFile) {
                            $manifestMap[$foundFile] = $projId;
                            $ukey = array_search($foundFile, $unknownFiles);
                            if ($ukey !== false)
                                unset($unknownFiles[$ukey]);

                            $mRes = $cfClient->getMods([$projId]);
                            if (!empty($mRes)) {
                                $m = $mRes[0];
                                $authors = $m->getData()->getAuthors();
                                $logo = $m->getData()->getLogo();
                                $details = [
                                    'id' => $m->getData()->getId(),
                                    'name' => $m->getData()->getName(),
                                    'author' => !empty($authors) ? $authors[0]->getName() : 'Unknown',
                                    'summary' => $m->getData()->getSummary(),
                                    'icon' => $logo ? ($logo->getThumbnailUrl() ?: $logo->getUrl()) : '',
                                    'latestFileId' => $m->getData()->getMainFileId(),
                                    'platform' => 'curseforge'
                                ];
                                $results[$foundFile] = $details;
                                $newMetadata[$foundFile] = array_merge([
                                    'modId' => $projId,
                                    'name' => $details['name'],
                                    'platform' => 'curseforge',
                                    'fileName' => $foundFile,
                                    'fileId' => $fileObj->getId(),
                                    'logo' => $details['icon'],
                                    'author' => $details['author'],
                                    'summary' => $details['summary'],
                                    'mcVersion' => 'Various',
                                    'installedAt' => time()
                                ], $newMetadata[$foundFile] ?? []);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    dbg("Fingerprint Error: " . $e->getMessage());
                }
            }
        }

        // Pass 4: Heuristic Search fallbacks
        foreach ($unknownFiles as $filename) {
            $query = preg_replace('/\.jar$/i', '', $filename);
            $searchQuery = preg_replace('/[-_][vV]?\d+\.?\d+.*$/', '', $query);
            $searchQuery = trim(preg_replace('/([a-z])([A-Z])/', '$1 $2', $searchQuery));
            if (strlen($searchQuery) < 2)
                continue;

            try {
                $mrOpts = new ProjectSearchOptions(
                    query: $searchQuery,
                    facets: new FacetANDGroup([
                        new Facet(FacetType::PROJECT_TYPE, 'mod')
                    ]),
                    limit: 1
                );
                $mrRes = $mrClient->searchProjects($mrOpts);
                $hits = $mrRes->getResults();
                if (!empty($hits)) {
                    $hit = $hits[0];
                    $results[$filename] = [
                        'id' => $hit->getData()->getProjectId(),
                        'name' => $hit->getData()->getTitle(),
                        'author' => $hit->getData()->getAuthor(),
                        'summary' => $hit->getData()->getDescription(),
                        'icon' => $hit->getData()->getIconUrl(),
                        'platform' => 'modrinth'
                    ];
                    $newMetadata[$filename] = [
                        'modId' => $hit->getData()->getProjectId(),
                        'name' => $hit->getData()->getTitle(),
                        'platform' => 'modrinth',
                        'fileName' => $filename,
                        'logo' => $hit->getData()->getIconUrl(),
                        'author' => $hit->getData()->getAuthor(),
                        'summary' => $hit->getData()->getDescription(),
                        'installedAt' => time()
                    ];
                    continue;
                }
            } catch (\Throwable $e) {
            }

            if ($cfClient) {
                try {
                    $cfOpts = new ModSearchOptions(432);
                    $cfOpts->setSearchFilter($searchQuery);
                    $cfOpts->setPageSize(1);
                    $cfSearch = $cfClient->searchMods($cfOpts);
                    $mods = $cfSearch->getResults();
                    if (!empty($mods)) {
                        $m = $mods[0];
                        $authors = $m->getData()->getAuthors();
                        $logo = $m->getData()->getLogo();
                        $results[$filename] = [
                            'id' => $m->getData()->getId(),
                            'name' => $m->getData()->getName(),
                            'author' => !empty($authors) ? $authors[0]->getName() : 'Unknown',
                            'summary' => $m->getData()->getSummary(),
                            'icon' => $logo ? ($logo->getThumbnailUrl() ?: $logo->getUrl()) : '',
                            'platform' => 'curseforge'
                        ];
                        $newMetadata[$filename] = [
                            'modId' => $m->getData()->getId(),
                            'name' => $m->getData()->getName(),
                            'platform' => 'curseforge',
                            'fileName' => $filename,
                            'logo' => $results[$filename]['icon'],
                            'author' => $results[$filename]['author'],
                            'summary' => $m->getData()->getSummary(),
                            'installedAt' => time()
                        ];
                    }
                } catch (\Throwable $e) {
                }
            }
        }

        if (!empty($results) || !empty($unknownFiles)) {
            mcmm_mkdir($metaDir, 0755, true);

            // ATOMIC MERGE-ON-WRITE WITH LOCKING
            $fp = @fopen($metaFile, 'c+');
            if ($fp) {
                if (flock($fp, LOCK_EX)) {
                    $content = stream_get_contents($fp);
                    $diskMetadata = json_decode($content, true) ?: [];

                    // 1. Record UNIDENTIFIED mods to prevent infinite retry loops
                    foreach ($unknownFiles as $filename) {
                        if (!isset($results[$filename])) {
                            $uid = 'unidentified_' . md5($filename);
                            $diskMetadata[$filename] = array_merge($diskMetadata[$filename] ?? [], [
                                'modId' => $uid,
                                'fileName' => $filename,
                                'unidentified' => true,
                                'lastAttempt' => time()
                            ]);
                        }
                    }

                    // 2. Merge NEW IDENTIFICATION results from this batch
                    foreach ($newMetadata as $filename => $info) {
                        $diskMetadata[$filename] = array_merge($diskMetadata[$filename] ?? [], $info);
                    }

                    // 3. Write back atomically
                    ftruncate($fp, 0);
                    rewind($fp);
                    fwrite($fp, json_encode($diskMetadata, JSON_PRETTY_PRINT));
                    fflush($fp);
                    flock($fp, LOCK_UN);
                }
                fclose($fp);
            }

            // 4. Invalidate scan cache so next mod_list call sees the new metadata
            $cacheFile = "$metaDir/mods_cache.json";
            if (file_exists($cacheFile)) {
                @unlink($cacheFile);
            }
        }
        jsonResponse(['success' => true, 'data' => $results]);
    } catch (\Throwable $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

/**
 * Handle identify_mod action
 */
function handle_identify_mod($id, $config)
{
    try {
        $id = safeContainerName($id);
        $fileName = $_GET['file'] ?? '';
        if (!$id || !$fileName)
            jsonResponse(['success' => false, 'error' => 'Missing parameters'], 400);

        require_once __DIR__ . '/mod_manager.php';
        $dataDir = getContainerDataDirById($id);
        $modsDir = $dataDir ? rtrim($dataDir, '/') . '/mods' : null;
        if (!$modsDir || !is_dir($modsDir))
            jsonResponse(['success' => false, 'error' => 'Mods directory not found'], 404);

        $fullPath = "$modsDir/$fileName";
        if (!file_exists($fullPath))
            jsonResponse(['success' => false, 'error' => 'File not found'], 404);

        $apiKey = $config['curseforge_api_key'] ?? '';
        if (empty($apiKey))
            jsonResponse(['success' => false, 'error' => 'API Key missing'], 400);

        $cfClient = new CurseForgeAPIClient($apiKey);
        $hash = (int) CursedFingerprintHelper::getFingerprintFromFile($fullPath);
        if ($hash <= 0)
            jsonResponse(['success' => false, 'error' => 'Could not generate fingerprint'], 500);

        $matchesResult = $cfClient->getFilesByFingerPrintMatches([$hash]);
        $matches = $matchesResult->getExactMatches();
        if (empty($matches))
            jsonResponse(['success' => false, 'error' => 'No match found'], 404);

        $match = $matches[0];
        $projId = $match->getId();
        $fileObj = $match->getFile();

        $mRes = $cfClient->getMods([$projId]);
        if (empty($mRes))
            jsonResponse(['success' => false, 'error' => 'Could not fetch mod details'], 500);

        $m = $mRes[0];
        $authors = $m->getData()->getAuthors();
        $logo = $m->getData()->getLogo();
        $details = [
            'id' => $m->getData()->getId(),
            'name' => $m->getData()->getName(),
            'author' => !empty($authors) ? $authors[0]->getName() : 'Unknown',
            'summary' => $m->getData()->getSummary(),
            'icon' => $logo ? ($logo->getThumbnailUrl() ?: $logo->getUrl()) : '',
            'platform' => 'curseforge'
        ];

        // Persist
        $metaDir = getServerMetadataDir($id);
        $metaFile = "$metaDir/installed_mods.json";
        $metadata = file_exists($metaFile) ? (json_decode(file_get_contents($metaFile), true) ?: []) : [];
        $metadata[$projId] = [
            'modId' => $projId,
            'name' => $details['name'],
            'platform' => 'curseforge',
            'fileName' => $fileName,
            'fileId' => $fileObj->getId(),
            'logo' => $details['icon'],
            'author' => $details['author'],
            'summary' => $details['summary'],
            'installedAt' => time()
        ];
        mcmm_mkdir($metaDir, 0755, true);
        @mcmm_file_put_contents($metaFile, json_encode($metadata, JSON_PRETTY_PRINT));

        jsonResponse(['success' => true, 'data' => $details]);
    } catch (\Throwable $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

/**
 * Handle mod_files action
 */
function handle_mod_files($id, $config)
{
    $source = strtolower($_GET['source'] ?? 'curseforge');
    $modId = $_GET['mod_id'] ?? '';
    $apiKey = $config['curseforge_api_key'] ?? '';
    $mcVer = $_GET['mc_version'] ?? '';
    $loader = $_GET['loader'] ?? '';

    if (!$modId)
        jsonResponse(['success' => false, 'error' => 'Missing Mod ID'], 400);

    try {
        if ($source === 'modrinth') {
            $mrClient = new ModrinthAPIClient();
            $versions = $mrClient->getProjectVersions($modId);
            $results = [];
            foreach ($versions as $v) {
                $vLoader = array_map('strtolower', $v->getData()->getLoaders());
                $vVers = $v->getData()->getGameVersions();

                if ($mcVer && !in_array($mcVer, $vVers))
                    continue;
                if ($loader && !in_array(strtolower($loader), $vLoader))
                    continue;

                $results[] = [
                    'id' => $v->getData()->getId(),
                    'fileName' => $v->getData()->getName(),
                    'displayName' => $v->getData()->getName(),
                    'mcVersion' => implode(', ', $vVers),
                    'gameVersions' => array_merge($vVers, $vLoader), // Combine for JS inference
                    'releaseType' => 1, // Modrinth doesn't natively map to CF release types 1=Release easily, assume release or map 'version_type' if available
                    'date' => $v->getData()->getDatePublished()
                ];
            }
            jsonResponse(['success' => true, 'data' => $results]);
        } else {
            if (empty($apiKey))
                jsonResponse(['success' => false, 'error' => 'CF API key missing'], 400);
            $cfClient = new CurseForgeAPIClient($apiKey);
            $opts = new ModFilesOptions((int) $modId);
            if ($mcVer)
                $opts->setGameVersion($mcVer);
            if ($loader) {
                switch (strtolower($loader)) {
                    case 'forge':
                        $opts->setModLoaderType(ModLoaderType::FORGE);
                        break;
                    case 'fabric':
                        $opts->setModLoaderType(ModLoaderType::FABRIC);
                        break;
                    case 'quilt':
                        $opts->setModLoaderType(ModLoaderType::QUILT);
                        break;
                    case 'neoforge':
                        $opts->setModLoaderType(ModLoaderType::NEOFORGE);
                        break;
                }
            }
            $files = $cfClient->getModFiles($opts);
            $results = [];
            foreach ($files as $f) {
                $results[] = [
                    'id' => $f->getData()->getId(),
                    'fileName' => $f->getData()->getFileName(),
                    'displayName' => $f->getData()->getDisplayName(),
                    'mcVersion' => implode(', ', $f->getData()->getGameVersions()),
                    'gameVersions' => $f->getData()->getGameVersions(),
                    'releaseType' => $f->getData()->getReleaseType(),
                    'date' => $f->getData()->getFileDate()
                ];
            }
            jsonResponse(['success' => true, 'data' => $results]);
        }
    } catch (\Throwable $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

/**
 * Handle mod_install action
 */
function handle_mod_install($id, $config)
{
    $source = strtolower($_REQUEST['source'] ?? 'curseforge');
    $id = safeContainerName($_REQUEST['id'] ?? $id);
    $modId = $_REQUEST['mod_id'] ?? '';
    $fileId = $_REQUEST['file_id'] ?? '';
    $apiKey = $config['curseforge_api_key'] ?? '';

    if (!$id || !$modId)
        jsonResponse(['success' => false, 'error' => 'Missing parameters'], 400);

    $modsDir = getContainerModsDir($id);
    if (!$modsDir)
        jsonResponse(['success' => false, 'error' => 'Could not locate mods directory'], 500);
    mcmm_mkdir($modsDir, 0775, true);

    try {
        $fileUrl = '';
        if ($source === 'modrinth') {
            $mrClient = new ModrinthAPIClient();
            if (!$fileId) {
                $versions = $mrClient->getProjectVersions($modId);
                if (!empty($versions))
                    $fileId = $versions[0]->getData()->getId();
            }
            if ($fileId) {
                $version = $mrClient->getVersion($fileId);
                $files = $version->getData()->getFiles();
                if (!empty($files))
                    $fileUrl = $files[0]->getUrl();
            }
        } else {
            if (empty($apiKey))
                jsonResponse(['success' => false, 'error' => 'CF API key missing'], 400);
            $cfClient = new CurseForgeAPIClient($apiKey);
            if (!$fileId) {
                $mod = $cfClient->getMod((int) $modId);
                $fileId = $mod->getData()->getMainFileId();
            }
            if ($fileId) {
                $fileUrl = $cfClient->getModFileDownloadURL((int) $modId, (int) $fileId);
            }
        }

        if (!$fileUrl)
            jsonResponse(['success' => false, 'error' => 'Could not get download URL'], 502);

        $fileName = basename(parse_url($fileUrl, PHP_URL_PATH)) ?: "mod-$modId.jar";
        if (downloadMod($fileUrl, "$modsDir/$fileName")) {
            @chown("$modsDir/$fileName", 99);
            @chgrp("$modsDir/$fileName", 100);
            @chmod("$modsDir/$fileName", 0664);

            $metaDir = getServerMetadataDir($id);
            $metaFile = "$metaDir/installed_mods.json";
            if (file_exists($metaFile)) {
                $metadata = json_decode(file_get_contents($metaFile), true) ?: [];
                $oldFile = '';
                foreach ($metadata as $key => $info) {
                    if (($info['modId'] ?? $key) == $modId) {
                        $oldFile = $info['fileName'] ?? (strpos($key, '.jar') !== false ? $key : '');
                        if ($oldFile)
                            break;
                    }
                }
                if ($oldFile && $oldFile !== $fileName && file_exists("$modsDir/$oldFile")) {
                    @unlink("$modsDir/$oldFile");
                }
            }

            saveModMetadata($id, $modId, $source, $_REQUEST['mod_name'] ?? $fileName, $fileName, $fileId, $_REQUEST['logo'] ?? '', [
                'author' => $_REQUEST['author'] ?? 'Unknown',
                'summary' => $_REQUEST['summary'] ?? '',
                'mcVersion' => $_REQUEST['mc_version'] ?? ''
            ]);
            jsonResponse(['success' => true, 'message' => 'Mod installed']);
        } else {
            jsonResponse(['success' => false, 'error' => 'Download failed'], 500);
        }
    } catch (\Throwable $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

/**
 * Handle modpacks and search_modpacks actions
 */
function handle_modpacks($config)
{
    $source = strtolower($_GET['source'] ?? 'curseforge');
    $search = $_GET['search'] ?? $_GET['q'] ?? '';
    $sort = $_GET['sort'] ?? 'popularity';
    $page = max(1, intval($_GET['page'] ?? 1));
    $pageSize = max(1, min(100, intval($_GET['page_size'] ?? 20)));

    // Add version/loader filters support if passed
    $mcVer = $_GET['mc_version'] ?? '';
    // Loader could be numeric (int 0-5) or string (forge/fabric)
    $loader = $_GET['loader'] ?? '';

    dbg("Action: modpacks | Source: $source | Sort: $sort | Search: $search | Page: $page");

    // Create Guzzle client with SSL verification disabled for development/local environments
    $guzzleClient = new Client(['verify' => false]);

    try {
        if ($source === 'modrinth') {
            $mrClient = new ModrinthAPIClient(null, null, $guzzleClient);

            // Default Index Mapping
            $index = SearchIndex::RELEVANCE;

            if ($sort === 'popularity' || $sort === 'downloads') {
                $index = SearchIndex::DOWNLOADS;
            } elseif ($sort === 'newest') {
                $index = SearchIndex::NEWEST;
            } elseif ($sort === 'updated') {
                $index = SearchIndex::UPDATED;
            } else {
                // Fallback: If search is empty, default to downloads, otherwise relevance
                if (empty($search) && $sort !== 'relevance') {
                    $index = SearchIndex::DOWNLOADS;
                }
            }

            $facets = [new Facet(FacetType::PROJECT_TYPE, 'modpack')];

            // Add optional filters
            if ($mcVer) {
                $facets[] = new Facet(FacetType::VERSIONS, $mcVer);
            }
            if ($loader) {
                // Modrinth uses "categories" for loader
                $facets[] = new Facet(FacetType::CATEGORIES, strtolower($loader));
            }

            $opts = new ProjectSearchOptions(
                query: $search,
                facets: new FacetANDGroup($facets),
                index: $index,
                offset: ($page - 1) * $pageSize,
                limit: $pageSize
            );

            $res = $mrClient->searchProjects($opts);
            $modpacks = [];
            foreach ($res->getResults() as $hit) {
                $modpacks[] = [
                    'id' => $hit->getData()->getProjectId(),
                    'name' => $hit->getData()->getTitle(),
                    'author' => $hit->getData()->getAuthor(),
                    'summary' => $hit->getData()->getDescription(),
                    'icon' => $hit->getData()->getIconUrl(),
                    'img' => $hit->getData()->getIconUrl(),
                    'downloads' => $hit->getData()->getDownloads(),
                    'slug' => $hit->getData()->getSlug(),
                    'date' => $hit->getData()->getDateModified(), // Useful for UI
                    'source' => 'modrinth'
                ];
            }
            jsonResponse(['success' => true, 'data' => $modpacks, 'total' => 1000]); // Modrinth total is hard to get
        } else {
            // CurseForge
            $apiKey = $config['curseforge_api_key'] ?? '';
            if (empty($apiKey)) {
                dbg("Error: CF API key missing");
                jsonResponse(['success' => false, 'error' => 'CF API key missing'], 400);
            }

            $cfClient = new CurseForgeAPIClient($apiKey, null, $guzzleClient);
            $opts = new ModSearchOptions(432); // Game ID for Minecraft
            $opts->setClassId(4471); // Class ID for Modpacks

            // Sort Mapping
            // Using ModSearchSortField constants
            $sortField = ModSearchSortField::FEATURED; // Default

            // If explicit sort requested
            switch ($sort) {
                case 'popularity':
                case 'downloads':
                    $sortField = ModSearchSortField::POPULARITY;
                    break;
                case 'updated':
                    $sortField = ModSearchSortField::LAST_UPDATED;
                    break;
                case 'newest':
                    // Closest is Last Updated or Total Downloads sometimes, but let's check if there is a creation match?
                    // Usually Last Updated is best proxy for newest activity. 
                    // Or possibly there is a "Name" sort.
                    $sortField = ModSearchSortField::LAST_UPDATED;
                    break;
                case 'name':
                    $sortField = ModSearchSortField::NAME;
                    break;
                case 'rating':
                    $sortField = ModSearchSortField::POPULARITY; // Fallback
                    break;
                case 'total_downloads':
                    $sortField = ModSearchSortField::TOTAL_DOWNLOADS;
                    break;
                default:
                    // If search query exists, default to relevance (which is handled by search filter + default sort usually)
                    // But if no search, default to Featured or Popularity
                    $sortField = ModSearchSortField::FEATURED;
                    break;
            }

            // If user specifically asked for 'relevance', stay on Featured/Relevance
            if ($sort === 'relevance') {
                // Typically sorting by relevance is implied by default options + search query
            }

            $opts->setSortField($sortField);
            $opts->setSortOrder(SortOrder::DESCENDING); // Usually descending is what we want (most popular, newest, etc)

            // Exception: Name should be asc
            if ($sortField === ModSearchSortField::NAME) {
                $opts->setSortOrder(SortOrder::ASCENDING);
            }

            $opts->setSearchFilter($search);
            $opts->setOffset(($page - 1) * $pageSize);
            $opts->setPageSize($pageSize);

            if ($mcVer) {
                $opts->setGameVersion($mcVer);
            }

            // Loader Type mapping
            if ($loader) {
                // Try to map string loader to ModLoaderType enum
                $loaderType = ModLoaderType::ANY;
                switch (strtolower($loader)) {
                    case 'forge':
                        $loaderType = ModLoaderType::FORGE;
                        break;
                    case 'fabric':
                        $loaderType = ModLoaderType::FABRIC;
                        break;
                    case 'quilt':
                        $loaderType = ModLoaderType::QUILT;
                        break;
                    case 'neoforge':
                        $loaderType = ModLoaderType::NEOFORGE;
                        break;
                    case 'cauldron':
                        $loaderType = ModLoaderType::CAULDRON;
                        break;
                    case 'liteloader':
                        $loaderType = ModLoaderType::LITELOADER;
                        break;
                }
                if ($loaderType !== ModLoaderType::ANY) {
                    $opts->setModLoaderType($loaderType);
                }
            }

            dbg("Search Options: Sort=" . ($sortField->name ?? 'unknown') . ", Search=$search, Page=$page");

            $res = $cfClient->searchMods($opts);
            $modpacks = [];
            foreach ($res->getResults() as $m) {
                $logo = $m->getData()->getLogo();
                $authors = $m->getData()->getAuthors();
                $iconUrl = $logo ? ($logo->getThumbnailUrl() ?: $logo->getUrl()) : '';

                // Format nicely for frontend
                $modpacks[] = [
                    'id' => $m->getData()->getId(),
                    'name' => $m->getData()->getName(),
                    'author' => !empty($authors) ? $authors[0]->getName() : 'Unknown',
                    'summary' => $m->getData()->getSummary(),
                    'icon' => $iconUrl,
                    'img' => $iconUrl,
                    'downloads' => $m->getData()->getDownloadCount(),
                    'slug' => $m->getData()->getSlug(),
                    'date' => $m->getData()->getDateModified(),
                    'categories' => array_map(fn($c) => $c->getName(), $m->getData()->getCategories()),
                    'source' => 'curseforge'
                ];
            }
            dbg("Found " . count($modpacks) . " modpacks");
            jsonResponse(['success' => true, 'data' => $modpacks, 'total' => $res->getPagination()->getTotalCount()]);
        }
    } catch (\Throwable $e) {
        dbg("Modpacks API Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

/**
 * Handle import_manifest action
 */
function handle_import_manifest($id, $config)
{
    require_once __DIR__ . '/mod_manager.php';
    $id = safeContainerName($id);
    $json = $_REQUEST['manifest_json'] ?? '';
    $apiKey = $config['curseforge_api_key'] ?? '';

    if (!$json) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (is_array($input)) {
            $json = $input['manifest_json'] ?? ($input['json'] ?? '');
            if (is_array($json))
                $json = json_encode($json);
        }
    }

    if (!$id || !$json)
        jsonResponse(['success' => false, 'error' => 'Missing ID or manifest JSON'], 400);

    $data = json_decode($json, true);
    if (!$data)
        jsonResponse(['success' => false, 'error' => 'Invalid JSON'], 400);

    $modsToFetch = [];
    $manifestMap = [];
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

    if (empty($modsToFetch))
        jsonResponse(['success' => false, 'error' => 'No mods found in manifest'], 400);
    if (empty($apiKey))
        jsonResponse(['success' => false, 'error' => 'CF API Key required'], 400);

    try {
        $cfClient = new CurseForgeAPIClient($apiKey);
        $cfMods = $cfClient->getMods($modsToFetch);

        $metaDir = getServerMetadataDir($id);
        $metaFile = "$metaDir/installed_mods.json";
        $metadata = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) ?: [] : [];

        $doDownload = isset($_REQUEST['download']) && ($_REQUEST['download'] === 'true' || $_REQUEST['download'] === '1');
        $modsDir = $doDownload ? getContainerModsDir($id) : null;

        foreach ($cfMods as $mod) {
            $pid = $mod->getData()->getId();
            $fid = $manifestMap[$pid] ?? null;
            $logo = $mod->getData()->getLogo();
            $authors = $mod->getData()->getAuthors();

            $fileName = $mod->getData()->getSlug() . '.jar';
            if ($fid) {
                foreach ($mod->getData()->getLatestFiles() as $lf) {
                    if ($lf->getId() == $fid) {
                        $fileName = $lf->getFileName();
                        break;
                    }
                }
            }

            if ($doDownload && $fid && $modsDir) {
                $targetPath = "$modsDir/$fileName";
                if (!file_exists($targetPath)) {
                    $dUrl = $cfClient->getModFileDownloadURL((int) $pid, (int) $fid);
                    if ($dUrl) {
                        @file_put_contents($targetPath, @fopen($dUrl, 'r'));
                    }
                }
            }

            $metadata[$pid] = [
                'modId' => $pid,
                'name' => $mod->getData()->getName(),
                'platform' => 'curseforge',
                'fileName' => $fileName,
                'fileId' => $fid,
                'logo' => $logo ? ($logo->getThumbnailUrl() ?: $logo->getUrl()) : '',
                'author' => !empty($authors) ? $authors[0]->getName() : 'Unknown',
                'summary' => $mod->getData()->getSummary(),
                'installedAt' => time()
            ];
        }

        $metaDir = getServerMetadataDir($id);
        if (!is_dir($metaDir))
            @mkdir($metaDir, 0755, true);
        @file_put_contents($metaFile, json_encode($metadata, JSON_PRETTY_PRINT));

        jsonResponse(['success' => true, 'count' => count($cfMods)]);
    } catch (\Throwable $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

/**
 * Handle mod_delete action
 */
function handle_mod_delete($id)
{
    $id = safeContainerName($id);
    $file = $_GET['file'] ?? '';
    if (!$id || !$file)
        jsonResponse(['success' => false, 'error' => 'Missing parameters'], 400);

    $modsDir = getContainerModsDir($id);
    if (!$modsDir)
        jsonResponse(['success' => false, 'error' => 'Could not locate mods directory'], 500);

    $filePath = "$modsDir/" . basename($file);
    if (file_exists($filePath) && unlink($filePath)) {
        $metaDir = getServerMetadataDir($id);
        $metaFile = "$metaDir/installed_mods.json";
        if (file_exists($metaFile)) {
            $metadata = json_decode(file_get_contents($metaFile), true) ?: [];
            $found = false;
            foreach ($metadata as $mid => $info) {
                if (($info['fileName'] ?? '') === $file) {
                    unset($metadata[$mid]);
                    $found = true;
                }
            }
            if ($found)
                @file_put_contents($metaFile, json_encode($metadata, JSON_PRETTY_PRINT));
        }
        jsonResponse(['success' => true, 'message' => 'Mod removed']);
    } else {
        jsonResponse(['success' => false, 'error' => 'Failed to delete file'], 500);
    }
}





/**
 * Handle mods_check_updates action
 */
function handle_mods_check_updates($id, $config)
{
    require_once __DIR__ . '/mod_manager.php';
    $id = safeContainerName($id);
    $apiKey = $config['curseforge_api_key'] ?? '';
    if (!$id || !$apiKey)
        jsonResponse(['success' => false, 'error' => 'Missing ID or API Key'], 400);

    $dataDir = getContainerDataDirById($id);
    if (!$dataDir)
        jsonResponse(['success' => false, 'error' => 'Data directory not found'], 404);

    $mods = scanServerMods($dataDir);
    $metaDir = getServerMetadataDir($id);
    $metaFile = "$metaDir/metadata.json";
    $metadata = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : [];
    $mcVersion = $metadata['mc_version'] ?? '';
    $loader = $metadata['loader'] ?? 'forge';

    // Enrich with known metadata (installed_mods.json) to retrieve installedFileId
    $metaFileMods = "$metaDir/installed_mods.json";
    if (file_exists($metaFileMods)) {
        $metadataMods = json_decode(file_get_contents($metaFileMods), true) ?: [];
        $metaByFile = [];
        foreach ($metadataMods as $key => $m) {
            $f = (isset($m['fileName']) && !empty($m['fileName'])) ? $m['fileName'] : (is_string($key) && strpos($key, '.jar') !== false ? $key : null);
            if ($f) {
                $metaByFile[$f] = $m;
            }
        }
        foreach ($mods as &$mod) {
            $fileName = $mod['fileName'];
            if (isset($metaByFile[$fileName])) {
                $info = $metaByFile[$fileName];
                if (empty($mod['curseforgeId']) && !empty($info['modId']))
                    $mod['curseforgeId'] = $info['modId'];
                if (empty($mod['installedFileId']) && !empty($info['fileId']))
                    $mod['installedFileId'] = $info['fileId'];
            }
        }
        unset($mod);
    }

    $modsWithUpdates = checkModUpdates($mods, $apiKey, $mcVersion, $loader);
    $cacheFile = "$metaDir/mods_updates.json";
    mcmm_mkdir(dirname($cacheFile), 0755, true);
    @mcmm_file_put_contents($cacheFile, json_encode(['timestamp' => time(), 'mods' => $modsWithUpdates], JSON_PRETTY_PRINT));

    $updatesAvailable = array_filter($modsWithUpdates, fn($m) => $m['updateAvailable'] ?? false);
    jsonResponse(['success' => true, 'data' => $modsWithUpdates, 'updatesAvailable' => count($updatesAvailable)]);
}

/**
 * Handle mod_update action
 */
function handle_mod_update($id, $config)
{
    $id = safeContainerName($id);
    $modId = $_REQUEST['mod_id'] ?? '';
    $source = strtolower($_REQUEST['source'] ?? 'curseforge');
    $mcVersion = $_REQUEST['mc_version'] ?? '';
    $loader = $_REQUEST['loader'] ?? '';

    if (!$id || !$modId)
        jsonResponse(['success' => false, 'error' => 'Missing parameters'], 400);

    $modsDir = getContainerModsDir($id);
    if (!$modsDir)
        jsonResponse(['success' => false, 'error' => 'Could not locate mods directory'], 500);

    try {
        $fileUrl = '';
        $latestFileId = '';
        if ($source === 'modrinth') {
            $mrClient = new ModrinthAPIClient();
            $vers = $mrClient->getProjectVersions($modId, $loader ? [strtolower($loader)] : null, $mcVersion ? [$mcVersion] : null);
            if (!empty($vers)) {
                $latest = $vers[0];
                $latestFileId = $latest->getData()->getId();
                $files = $latest->getData()->getFiles();
                if (!empty($files))
                    $fileUrl = $files[0]->getUrl();
            }
        } else {
            $apiKey = $config['curseforge_api_key'] ?? '';
            if (!$apiKey)
                jsonResponse(['success' => false, 'error' => 'CF API key missing'], 400);
            $cfClient = new CurseForgeAPIClient($apiKey);
            $mod = $cfClient->getMod((int) $modId);
            foreach ($mod->getData()->getLatestFiles() as $f) {
                $vers = $f->getData()->getGameVersions();
                if ((!$mcVersion || in_array($mcVersion, $vers)) && (!$loader || in_array(strtolower($loader), array_map('strtolower', $vers)))) {
                    $latestFileId = $f->getData()->getId();
                    break;
                }
            }
            if (!$latestFileId)
                $latestFileId = $mod->getData()->getMainFileId();
            if ($latestFileId)
                $fileUrl = $cfClient->getModFileDownloadURL((int) $modId, (int) $latestFileId);
        }

        if (!$fileUrl)
            jsonResponse(['success' => false, 'error' => 'Could not get update URL'], 404);

        $fileName = basename(parse_url($fileUrl, PHP_URL_PATH)) ?: "mod-$modId.jar";
        $metaDir = getServerMetadataDir($id);
        $metaFile = "$metaDir/installed_mods.json";
        if (file_exists($metaFile)) {
            $metadata = json_decode(file_get_contents($metaFile), true) ?: [];
            $oldFile = '';

            // Look by modId property (since key is now filename)
            foreach ($metadata as $key => $info) {
                if (($info['modId'] ?? $key) == $modId) {
                    $oldFile = $info['fileName'] ?? (strpos($key, '.jar') !== false ? $key : '');
                    if ($oldFile)
                        break;
                }
            }

            if ($oldFile && $oldFile !== $fileName && file_exists("$modsDir/$oldFile")) {
                @unlink("$modsDir/$oldFile");
            }
        }

        if (downloadMod($fileUrl, "$modsDir/$fileName")) {
            @chown("$modsDir/$fileName", 99);
            @chgrp("$modsDir/$fileName", 100);
            @chmod("$modsDir/$fileName", 0664);

            require_once __DIR__ . '/mod_manager.php';
            saveModMetadata($id, $modId, $source, $_REQUEST['mod_name'] ?? $fileName, $fileName, $latestFileId, $_REQUEST['logo'] ?? '', [
                'author' => $_REQUEST['author'] ?? 'Unknown',
                'summary' => $_REQUEST['summary'] ?? '',
                'mcVersion' => $mcVersion
            ]);
            jsonResponse(['success' => true, 'message' => 'Mod updated']);
        } else {
            jsonResponse(['success' => false, 'error' => 'Update failed'], 500);
        }
    } catch (\Throwable $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

/**
 * Handle settings get
 */
function handle_settings_get($config)
{
    $response = $config;
    $response['has_curseforge_key'] = !empty($response['curseforge_api_key']);
    $response['curseforge_api_key_masked'] = !empty($response['curseforge_api_key'])
        ? substr($response['curseforge_api_key'], 0, 8) . '...'
        : '';
    unset($response['curseforge_api_key']);

    jsonResponse(['success' => true, 'data' => $response]);
}

/**
 * Handle settings save
 */
function handle_settings_save($config, $defaults, $configPath, $configDir)
{
    // Clear buffer again just to be sure
    if (ob_get_length())
        ob_clean();

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

    // Text/Number Fields
    $textFields = [
        'default_server_name',
        'default_port',
        'default_memory',
        'default_max_players',
        'default_ip',
        'default_whitelist',
        'default_icon_url',
        'jvm_flags',
        'mc_router_port',
        'mc_router_api_port',
        'mc_router_default'
    ];

    // Boolean Fields
    $boolFields = [
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
        'mc_router_enabled'
    ];

    foreach ($textFields as $field) {
        if (array_key_exists($field, $data)) {
            $existing[$field] = trim($data[$field]);
        }
    }

    foreach ($boolFields as $field) {
        if (array_key_exists($field, $data)) {
            $existing[$field] = boolInput($data[$field]);
        }
    }

    // Debug config dir
    mcmm_mkdir($configDir, 0777, true);
    dbg("Config dir verified: $configDir");

    // Write to mcmm.cfg
    dbg("Writing config to: $configPath");

    $writeResult = write_ini_file($existing, $configPath);

    if ($writeResult === false) {
        $err = error_get_last();
        $errMsg = $err['message'] ?? 'Unknown error';
        dbg("Error: write_ini_file failed. PHP Error: $errMsg");
        jsonResponse(['success' => false, 'error' => "Failed to write config file. $errMsg"], 500);
    }

    dbg("Config written successfully ($writeResult bytes)");

    // Explicitly clear buffer just in case
    if (ob_get_length())
        ob_clean();

    // Ensure MC Router state matches new config
    ensure_mc_router_container();

    jsonResponse(['success' => true, 'message' => 'Settings saved successfully']);
}

/**
 * Handle console logs
 */
function handle_console_logs()
{
    $id = safeContainerName($_GET['id'] ?? '');
    if (!$id) {
        jsonResponse(['success' => false, 'error' => 'Missing ID'], 400);
    }

    $output = shell_exec("docker logs --tail 200 " . escapeshellarg($id) . " 2>&1");

    jsonResponse([
        'success' => true,
        'logs' => $output ?: ''
    ]);
}

/**
 * Handle console command
 */
function handle_console_command()
{
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
}

/**
 * Handle server control
 */
function handle_server_control()
{
    $req = getRequestData();
    $id = safeContainerName($req['id'] ?? $_POST['id'] ?? $_GET['id'] ?? '');
    $cmd = $req['cmd'] ?? $_POST['cmd'] ?? $_GET['cmd'] ?? '';

    if (!$id || !$cmd) {
        jsonResponse(['success' => false, 'error' => 'Missing parameters'], 400);
    }

    if (!in_array($cmd, ['start', 'stop', 'restart'], true)) {
        jsonResponse(['success' => false, 'error' => 'Invalid command'], 400);
    }

    $output = shell_exec("docker " . escapeshellarg($cmd) . " " . escapeshellarg($id) . " 2>&1");

    jsonResponse([
        'success' => true,
        'message' => "Server $cmd command executed",
        'output' => $output
    ]);
}

/**
 * Handle server delete
 */
function handle_server_delete()
{
    $id = safeContainerName($_GET['id'] ?? '');
    if (!$id) {
        jsonResponse(['success' => false, 'error' => 'Missing ID'], 400);
    }

    // 1. Resolve paths BEFORE deleting container (so inspect works)
    $dataDir = getContainerDataDirById($id);

    // Try to find the MCMM metadata directory
    $mcmmServerDir = getServerMetadataDir($id);
    if (!is_dir($mcmmServerDir)) {
        $mcmmServerDir = null;
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
}

/**
 * Handle players list
 */
function handle_players()
{
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
}

/**
 * Handle server players detailed
 */
function handle_server_players()
{
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

    // Add player listing if sample available from stats
    if (!empty($stats['sample'])) {
        foreach ($stats['sample'] as $p) {
            if (!empty($p['name'])) {
                $players[] = ['name' => $sanitizeName($p['name'])];
            }
        }
    }

    // Try authoritative RCON list
    if (empty($players) && $rconPass && $online > 0) {
        $rconOut = shell_exec("docker exec " . escapeshellarg($id) . " rcon-cli --port $rconPort --password " . escapeshellarg($rconPass) . " list 2>/dev/null");
        $rconOut = trim($rconOut);

        if (preg_match('/(?:online|players): (.*)$/i', $rconOut, $m)) {
            $names = explode(', ', $m[1]);
            foreach ($names as $name) {
                $clean = $sanitizeName($name);
                if ($clean !== '')
                    $players[] = ['name' => $clean];
            }
        }
        if (empty($players) && !empty($rconOut)) {
            $cleanOut = preg_replace('/^There are .* online: /i', '', $rconOut);
            $cleanOut = preg_replace('/^Players online: /i', '', $cleanOut);
            $names = preg_split('/[,\s]+/', $cleanOut);
            foreach ($names as $name) {
                $clean = $sanitizeName($name);
                if ($clean !== '' && !in_array(strtolower($clean), ['there', 'are', 'players', 'online', 'max', 'out', 'of'])) {
                    $players[] = ['name' => $clean];
                }
            }
        }
    }

    // Fallback: mc-monitor
    if (empty($players) && $online > 0) {
        $internalPort = isset($envMap['SERVER_PORT']) ? intval($envMap['SERVER_PORT']) : 25565;
        $cmd = "docker exec " . escapeshellarg($id) . " mc-monitor status --json 127.0.0.1:$internalPort 2>/dev/null";
        $statusRes = shell_exec($cmd);
        if ($statusRes) {
            $jd = json_decode($statusRes, true);
            if (!empty($jd['players']['sample'])) {
                foreach ($jd['players']['sample'] as $p) {
                    if (!empty($p['name']))
                        $players[] = ['name' => $p['name']];
                }
            }
        }
    }

    // RCON op list
    $ops = [];
    if ($rconPass) {
        $opCmd = "docker exec " . escapeshellarg($id) . " rcon-cli --port $rconPort --password " . escapeshellarg($rconPass) . " \"op list\" 2>&1";
        $opOut = [];
        $opExit = 0; // Fixed: was $exit
        exec($opCmd, $opOut, $opExit);
        $fullOpOut = implode(' ', $opOut);

        if ($opExit === 0 && preg_match('/(?:opped players|opped player|operators):? (.*)$/i', $fullOpOut, $m)) {
            $names = array_map('trim', explode(',', $m[1]));
            foreach ($names as $n) {
                $name = $sanitizeName($n);
                if ($name !== '')
                    $ops[] = $name;
            }
        }

        if (empty($ops)) {
            $opsJsonCmd = "docker exec " . escapeshellarg($id) . " cat ops.json 2>/dev/null";
            $opsJsonRaw = shell_exec($opsJsonCmd);
            if ($opsJsonRaw) {
                $opsData = json_decode($opsJsonRaw, true);
                if (is_array($opsData)) {
                    foreach ($opsData as $entry) {
                        if (isset($entry['name']))
                            $ops[] = $entry['name'];
                    }
                }
            }
        }
    }

    // Attach isOp flag
    if (!empty($ops) && !empty($players)) {
        $opsLower = array_map('strtolower', $ops);
        foreach ($players as &$p) {
            $p['isOp'] = in_array(strtolower($p['name']), $opsLower);
        }
    } else {
        foreach ($players as &$p) {
            if (!isset($p['isOp']))
                $p['isOp'] = false;
        }
    }

    jsonResponse(['success' => true, 'data' => ['online' => $online, 'max' => $max, 'players' => $players]]);
}

/**
 * Handle server banned players
 */
function handle_server_banned_players()
{
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
            if (count($ev) === 2)
                $envMap[$ev[0]] = $ev[1];
        }
    }
    $rconPass = $envMap['RCON_PASSWORD'] ?? '';
    $rconPort = isset($envMap['RCON_PORT']) ? intval($envMap['RCON_PORT']) : 25575;
    $banned = [];

    if ($rconPass) {
        $out = shell_exec("docker exec " . escapeshellarg($id) . " rcon-cli --port $rconPort --password " . escapeshellarg($rconPass) . " \"banlist players\" 2>&1");
        $out = trim($out);

        if ($out && stripos($out, 'no banned players') === false) {
            if (preg_match('/:\s*(.*)$/i', $out, $m)) {
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
                $jd = json_decode($jsonContent, true);
                if (is_array($jd)) {
                    foreach ($jd as $entry) {
                        if (!empty($entry['name']))
                            $banned[] = ['name' => $entry['name']];
                    }
                }
            }
        }
    }
    jsonResponse(['success' => true, 'data' => array_values(array_unique($banned, SORT_REGULAR))]);
}

/**
 * Handle server player action
 */
function handle_server_player_action()
{
    $id = $_GET['id'] ?? '';
    $player = $_GET['player'] ?? '';
    $player_action = $_GET['player_action'] ?? '';
    if (!$id || !$player || !$player_action) {
        jsonResponse(['success' => false, 'error' => 'Missing parameters'], 400);
    }

    $inspectJson = shell_exec("docker inspect " . escapeshellarg($id));
    $inspect = json_decode($inspectJson, true);
    if (!$inspect || !isset($inspect[0])) {
        jsonResponse(['success' => false, 'error' => 'Container not found'], 404);
    }
    $envMap = [];
    if (!empty($inspect[0]['Config']['Env'])) {
        foreach ($inspect[0]['Config']['Env'] as $e) {
            $parts = explode('=', $e, 2);
            if (count($parts) === 2)
                $envMap[$parts[0]] = $parts[1];
        }
    }
    $rconPass = $envMap['RCON_PASSWORD'] ?? '';
    if (!$rconPass) {
        jsonResponse(['success' => false, 'error' => 'RCON_PASSWORD not set'], 400);
    }

    $cmdMap = [
        'kick' => "kick " . escapeshellarg($player),
        'ban' => "ban " . escapeshellarg($player),
        'unban' => "pardon " . escapeshellarg($player),
        'op' => "op " . escapeshellarg($player),
        'deop' => "deop " . escapeshellarg($player),
        'whisper' => "tell " . escapeshellarg($player) . " " . escapeshellarg($_GET['message'] ?? ''),
    ];
    if (!isset($cmdMap[$player_action])) {
        jsonResponse(['success' => false, 'error' => 'Unsupported action'], 400);
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
}

/**
 * Handle list servers
 */
function handle_list_servers($config)
{
    $servers = [];
    $serversDir = MCMM_SERVERS_DIR;

    // Ensure servers directory exists
    if (!is_dir($serversDir)) {
        @mkdir($serversDir, 0755, true);
    }

    // First, read all server configs from disk
    $serverConfigs = [];
    if (is_dir($serversDir)) {
        $dirs = glob($serversDir . '/*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            $metaFile = $dir . '/metadata.json';
            if (file_exists($metaFile)) {
                $meta = json_decode(file_get_contents($metaFile), true);
                if ($meta && isset($meta['containerName'])) {
                    $serverConfigs[$meta['containerName']] = $meta;
                }
            }
        }
    }

    // Then, get running containers from Docker
    $cmd = '/usr/bin/docker ps -a --format "{{.ID}}|{{.Names}}|{{.Status}}|{{.Image}}|{{.Ports}}|{{.Labels}}"';

    // Build map of local image tags to IDs
    $localImages = [];
    $fastMode = isset($_GET['fast']) && $_GET['fast'] === 'true';

    // Cache 'docker images'
    $imgCache = MCMM_TMP_DIR . '/images.cache';
    $psCache = MCMM_TMP_DIR . '/ps.cache';
    $statsCache = MCMM_TMP_DIR . '/stats.cache';
    $inspectCache = MCMM_TMP_DIR . '/inspect.cache';
    $imgOutput = null;
    $cacheAge = file_exists($imgCache) ? (time() - filemtime($imgCache)) : 999;

    if ($cacheAge < 60) {
        $imgOutput = file_get_contents($imgCache);
    }

    if (!$imgOutput && (!$fastMode || ($fastMode && file_exists($imgCache)))) {
        if (!$fastMode || $cacheAge < 300) {
            if (!$fastMode) {
                $imgCmd = '/usr/bin/docker images --no-trunc --format "{{.Repository}}:{{.Tag}}|{{.ID}}"';
                $imgOutput = shell_exec($imgCmd . ' 2>/dev/null');
                if ($imgOutput) {
                    @file_put_contents($imgCache, $imgOutput);
                }
            } elseif (file_exists($imgCache)) {
                $imgOutput = file_get_contents($imgCache);
            }
        }
    }

    if ($imgOutput) {
        foreach (explode("\n", trim($imgOutput)) as $imgLine) {
            $parts = explode('|', $imgLine);
            if (count($parts) === 2) {
                $localImages[$parts[0]] = $parts[1];
            }
        }
    }

    // Cache 'docker ps'

    if (file_exists($psCache) && (time() - filemtime($psCache) < 2)) {
        $output = file_get_contents($psCache);
    } else {
        $output = shell_exec($cmd . ' 2>/dev/null');
        if ($output) {
            @file_put_contents($psCache, $output);
        }
    }

    // Batch gather docker stats
    $allStats = [];
    if (!$fastMode) {

        $statsOutput = null;
        if (file_exists($statsCache) && (time() - filemtime($statsCache) < 2)) {
            $statsOutput = file_get_contents($statsCache);
        } else {
            $statsCmd = '/usr/bin/docker stats --no-stream --format "{{.ID}}|{{.Name}}|{{.CPUPerc}}|{{.MemUsage}}"';
            $statsOutput = shell_exec($statsCmd . ' 2>/dev/null');
            if ($statsOutput) {
                @file_put_contents($statsCache, $statsOutput);
            }
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
    }

    // Batch gather docker inspect
    $allInspect = [];
    $inspectIds = trim((string) shell_exec('/usr/bin/docker ps -a -q | tr "\n" " "'));
    if (!empty($inspectIds)) {

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
                    $shortId = substr($item['Id'], 7, 12);
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
            if (empty($line))
                continue;
            $parts = explode('|', $line);
            if (count($parts) >= 6) {
                $containerId = $parts[0];
                $containerName = $parts[1];
                $status = $parts[2];
                $image = $parts[3];
                $port = $parts[4];
                $labels = $parts[5];

                $isItzg = strpos($image, 'itzg/') !== false;
                $isMcmm = strpos($labels, 'mcmm=1') !== false;
                if (!$isItzg && (strpos($labels, 'net.unraid.docker.repository=itzg/') !== false)) {
                    $isItzg = true;
                }

                // Exclude infrastructure containers that happen to use itzg/ images
                $isInfraContainer = in_array($containerName, ['mc-router'], true)
                    || strpos($containerName, 'mc-router') !== false
                    || strpos($image, 'itzg/mc-router') !== false;

                if (($isItzg || $isMcmm) && !$isInfraContainer) {
                    $isRunning = strpos($parts[2], 'Up') !== false;

                    // Trigger/Ensure metadata directory migration to Name - ShortID format
                    getServerMetadataDir($containerId);

                    // Port logic
                    $displayPort = '25565';
                    if (preg_match('/:(\d+)->25565/', $parts[4] ?? '', $matches) || preg_match('/(\d+)->25565/', $parts[4] ?? '', $matches)) {
                        $displayPort = $matches[1];
                    }

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

                    $metadata = getServerMetadata($env, $config, $containerName, $config['curseforge_api_key'] ?? '', $image, (int) $displayPort, $containerId);
                    $mcVer = $metadata['mcVersion'];
                    $loaderVer = $metadata['loader'];
                    $modpackVer = $metadata['modpackVersion'] ?? '';

                    $playersOnline = 0;
                    $playersMax = intval($env['MAX_PLAYERS'] ?? $serverConfigs[$containerName]['maxPlayers'] ?? $config['default_max_players'] ?? 20);

                    $icon = $serverConfigs[$containerName]['logo'] ?? getLabelValue($labels, 'net.unraid.docker.icon') ?: getLabelValue($labels, 'mcmm.icon') ?: $env['ICON'] ?? '';

                    $configMem = isset($serverConfigs[$containerName]['memory']) ? parseMemoryToMB($serverConfigs[$containerName]['memory']) : $envMemoryMb;

                    // Metrics & Telemetry
                    $dataDir = getContainerDataDirById($containerId);
                    $metrics = null;
                    $agentPath = '';

                    if ($dataDir) {
                        $agentPath = rtrim($dataDir, '/') . '/mcmm_metrics.json';
                        $metrics = readAgentMetrics($containerName, $containerId, $dataDir);

                        $lastAgentAttempt = $serverConfigs[$containerName]['_last_agent_start'] ?? 0;
                        if ($isRunning && !$metrics && (time() - $lastAgentAttempt > 300)) {
                            ensureMetricsAgent($containerName, $containerId, $dataDir);
                            $serverConfigs[$containerName]['_last_agent_start'] = time();
                        }
                    }

                    $agentExists = file_exists($agentPath);
                    $agentMtime = $agentExists ? @filemtime($agentPath) : null;
                    $agentTs = isset($metrics['ts']) ? intval($metrics['ts']) : null;

                    $javaHeapUsedMb = ($isRunning && isset($metrics['heap_used_mb'])) ? floatval($metrics['heap_used_mb']) : 0;

                    $ramLimitMb = $configMem ?? 0;
                    if ($ramLimitMb <= 0 && isset($config['default_memory'])) {
                        $ramLimitMb = parseMemoryToMB($config['default_memory']);
                    }

                    $cg = $allStats[$containerId] ?? $allStats[$containerName] ?? ['cpu_percent' => 0, 'mem_used_mb' => 0, 'mem_cap_mb' => 0];

                    $ramUsedMb = 0;
                    $longId = isset($allInspect[$containerId]['Id']) ? $allInspect[$containerId]['Id'] : null;
                    $cgroupWsMb = getContainerCgroupRamMb($containerId, $longId);

                    if (isset($metrics['pss_mb']) && floatval($metrics['pss_mb']) > 0) {
                        $ramUsedMb = floatval($metrics['pss_mb']);
                    } elseif (isset($metrics['rss_mb']) && floatval($metrics['rss_mb']) > 0) {
                        $ramUsedMb = floatval($metrics['rss_mb']);
                    } elseif ($cgroupWsMb !== null && $cgroupWsMb > 0) {
                        $ramUsedMb = $cgroupWsMb;
                    } elseif (isset($metrics['ws_mb']) && floatval($metrics['ws_mb']) > 0) {
                        $ramUsedMb = floatval($metrics['ws_mb']);
                    } elseif ($cg['mem_used_mb'] > 0) {
                        $ramUsedMb = $cg['mem_used_mb'];
                    }

                    $ramUsagePercent = ($ramLimitMb > 0) ? ($ramUsedMb / $ramLimitMb) * 100 : 0;
                    $cpuUsage = $cg['cpu_percent'];
                    $ramDetails = "Used: " . round($ramUsedMb) . "MB / Limit: " . round($ramLimitMb) . "MB";

                    $plStats = getOnlinePlayers($containerId, (int) $displayPort, $env);
                    $playersOnline = $plStats['online'];
                    $playersMax = $plStats['max'];

                    // Update checks removed for global handling
                    $updateAvailable = false;

                    $servers[] = [
                        // 'containerUpdate' => $updateAvailable, // Removed

                        'id' => $containerId,
                        'name' => $containerName,
                        'status' => $isRunning ? 'Running' : 'Stopped',
                        'isRunning' => $isRunning,
                        'ports' => $displayPort,
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

                    // Persistent metadata cache
                    $metaDir = getServerMetadataDir($containerName);
                    $metaFile = "$metaDir/metadata.json";
                    mcmm_mkdir($metaDir, 0755, true);

                    $metadataCache = file_exists($metaFile) ? (json_decode(file_get_contents($metaFile), true) ?: []) : [];
                    $metadataCache = array_merge($metadataCache, [
                        'containerName' => $containerName,
                        'mc_version' => $mcVer ?: 'Unknown',
                        'loader' => $loaderVer ?: 'Vanilla',
                        'modpack_version' => $modpackVer ?? '',
                        'last_updated' => time()
                    ]);
                    @mcmm_file_put_contents($metaFile, json_encode($metadataCache, JSON_PRETTY_PRINT));
                }
            }
        }
    }

    jsonResponse(['success' => true, 'data' => $servers]);
}

/**
 * Handle server details
 */
function handle_server_details($config)
{
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
    $port = isset($c['HostConfig']['PortBindings']['25565/tcp'][0]['HostPort']) ? $c['HostConfig']['PortBindings']['25565/tcp'][0]['HostPort'] : 25565;

    // Unified Metadata Detection
    $dataDir = getContainerDataDirById($id);

    // Unified Metadata Detection
    $metadata = getServerMetadata($env, $config, $containerName, $config['curseforge_api_key'] ?? '', $c['Config']['Image'] ?? '', 0, $c['Id'], $dataDir);
    $mcVersion = $metadata['mcVersion'];
    $loader = $metadata['loader'];

    // Persist detected metadata to metadata.json for future offline use
    $metaDir = getServerMetadataDir($id);
    $metaFile = "$metaDir/metadata.json";
    $meta = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : [];
    $changed = false;

    if ($mcVersion && ($meta['mc_version'] ?? '') !== $mcVersion) {
        $meta['mc_version'] = $mcVersion;
        $changed = true;
    }
    if ($loader && ($meta['loader'] ?? '') !== $loader) {
        $meta['loader'] = $loader;
        $changed = true;
    }
    if ($containerName && ($meta['containerName'] ?? '') !== $containerName) {
        $meta['containerName'] = $containerName;
        $changed = true;
    }

    if ($changed) {
        @mcmm_file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT));
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
}

/**
 * Handle detect java version
 */
function handle_detect_java_version()
{
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
}

/**
 * Handle push metrics
 */
function handle_push_metrics()
{
    // High-performance endpoint for agents to push metrics
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit;
    }

    // Allow raw POST body or form data
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (!$data || !isset($data['id'])) {
        // Try $_POST
        $id = $_POST['id'] ?? '';
        $metrics = $_POST['metrics'] ?? '';
        if ($id && $metrics) {
            $data = ['id' => $id, 'metrics' => json_decode($metrics, true)];
        } else {
            jsonResponse(['success' => false, 'error' => 'Invalid data'], 400);
        }
    }

    $containerId = $data['id'];
    $metricsData = $data['metrics'] ?? [];

    $cacheFile = MCMM_TMP_DIR . "/metrics_" . md5($containerId) . ".json";
    file_put_contents($cacheFile, json_encode($metricsData));

    jsonResponse(['success' => true]);
}

/**
 * Helper to stop, remove and recreate a container with new specs.
 *
 * @param string $id       Container ID or name
 * @param string $image    Image to use
 * @param array $env       Environment variables
 * @param int $port        Host port to map to 25565
 * @param string $oldName  Original name to restore
 * @param string $dataDir  Host data directory
 * @return array Result with success, message, and command output
 */
function mcmm_recreate_container($id, $image, $env, $port, $oldName, $dataDir, $shouldStart = true)
{
    // 0. Extract full config BEFORE stopping/removing
    $config = extractContainerConfig($id);

    // 1. Stop and Remove
    shell_exec("docker stop " . escapeshellarg($id) . " 2>&1");
    shell_exec("docker rm " . escapeshellarg($id) . " 2>&1");

    if ($config) {
        dbg("[RECREATE] Using extracted config for " . ($config['name'] ?? $id));

        // Prepare final configuration, allowing overrides
        $name = $config['name'] ?: $oldName;
        $targetImage = $image ?: $config['image'];
        $finalEnv = array_merge($config['env'], (array) $env);

        // Ensure standard ports/env are synced if passed explicitly
        if ($port) {
            $finalEnv['SERVER_PORT'] = $port;
            // Port binding for 25565 is handled via buildPortArgs below, 
            // but if we want to override it, we'd need more logic. 
            // For now, we trust the extracted bindings.
        }

        $envArgs = buildEnvArgs($finalEnv);
        $portArgs = buildPortArgs($config['ports']);
        $volumeArgs = buildVolumeArgs($config['mounts']);

        // Merge Labels
        $labels = $config['labels'];
        $labels['mcmm'] = '1';
        $labels['mcmm.server'] = 'true';
        $labels['net.unraid.docker.managed'] = 'dockerman';
        $labels['net.unraid.docker.repository'] = $targetImage;

        // MC Router Labels
        // NOTE: mc-router.port must be the INTERNAL container port (always 25565),
        // NOT the host-mapped port (SERVER_PORT). mc-router connects to the container
        // via its internal Docker bridge IP.
        if (!empty($finalEnv['MC_ROUTER_HOST'])) {
            $labels['mc-router.host'] = $finalEnv['MC_ROUTER_HOST'];
            $labels['mc-router.port'] = '25565';
            dbg("[RECREATE] Injecting mc-router.host=" . $finalEnv['MC_ROUTER_HOST']);
        } else {
            dbg("[RECREATE] MC_ROUTER_HOST not set in finalEnv, skipping label injection.");
        }

        if (!empty($finalEnv['ICON'])) {
            $labels['net.unraid.docker.icon'] = $finalEnv['ICON'];
            $labels['mcmm.icon'] = $finalEnv['ICON'];
        }
        $labelArgs = buildLabelArgs($labels);

        $restart = $config['restart_policy'] ?: 'unless-stopped';
        $network = $config['network_mode'] ?: 'bridge';

        $cmd = sprintf(
            'docker create --restart %s --name %s --net %s %s %s %s %s %s',
            escapeshellarg($restart),
            escapeshellarg($name),
            escapeshellarg($network),
            $portArgs,
            $volumeArgs,
            $envArgs,
            $labelArgs,
            escapeshellarg($targetImage)
        );
    } else {
        dbg("[RECREATE] Fallback: Extraction failed for $id. Using provided specs.");

        // Fallback to legacy behavior if extraction failed or container was already gone
        $labels = [
            'mcmm' => '1',
            'mcmm.server' => 'true',
            'net.unraid.docker.managed' => 'dockerman',
            'net.unraid.docker.repository' => $image
        ];
        if (!empty($env['ICON'])) {
            $labels['net.unraid.docker.icon'] = $env['ICON'];
            $labels['mcmm.icon'] = $env['ICON'];
        }
        // MC Router Labels (fallback path)
        // NOTE: mc-router.port must be the INTERNAL container port (always 25565),
        // NOT the host-mapped port (SERVER_PORT).
        if (!empty($env['MC_ROUTER_HOST'])) {
            $labels['mc-router.host'] = $env['MC_ROUTER_HOST'];
            $labels['mc-router.port'] = '25565';
            dbg("[RECREATE-FALLBACK] Injecting mc-router.host=" . $env['MC_ROUTER_HOST']);
        } else {
            dbg("[RECREATE-FALLBACK] MC_ROUTER_HOST not set in env, skipping label injection.");
        }

        $labelArgs = buildLabelArgs($labels);
        $env['SERVER_PORT'] = $port;
        if (empty($env['RCON_PASSWORD'])) {
            $env['RCON_PASSWORD'] = bin2hex(random_bytes(6));
            $env['RCON_PORT'] = $env['RCON_PORT'] ?? 25575;
            $env['ENABLE_RCON'] = 'TRUE';
        }
        $envArgs = buildEnvArgs($env);

        $cmd = sprintf(
            'docker create --restart unless-stopped --name %s -p %s -v %s %s %s %s',
            escapeshellarg($oldName),
            escapeshellarg($port . ':25565'),
            escapeshellarg($dataDir . ':/data'),
            $envArgs,
            $labelArgs,
            escapeshellarg($image)
        );
    }

    $out = shell_exec($cmd . " 2>&1");
    // A successful create returns the new container ID (long hex string)
    $created = preg_match('/^[a-f0-9]{64}$/i', trim($out));

    if ($created) {
        $newId = trim($out);
        if ($shouldStart) {
            shell_exec("docker start " . escapeshellarg($newId));
        }
        return ['success' => true, 'id' => $newId, 'output' => $out];
    }

    return ['success' => false, 'output' => $out];
}

/**
 * Handle server update
 */
function handle_server_update()
{
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

    $dataDir = getContainerDataDirById($id);
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

    // Process MC Router Host update
    if (isset($input['mc_router_host'])) {
        $routerHost = trim($input['mc_router_host']);
        if ($routerHost) {
            $currentEnv['MC_ROUTER_HOST'] = $routerHost;
        } else {
            unset($currentEnv['MC_ROUTER_HOST']);
        }
    }

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

    // Recreate using the helper
    $result = mcmm_recreate_container($id, $image, $currentEnv, $newPort, $oldName, $dataDir);

    if ($result['success']) {
        mcmm_unraid_recheck();

        // Update metadata.json with new port if changed
        $metaDir = getServerMetadataDir($id);
        $metaFile = "$metaDir/metadata.json";
        if (file_exists($metaFile)) {
            $meta = json_decode(file_get_contents($metaFile), true);
            if ($meta) {
                $meta['port'] = $newPort;
                @file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT));
            }
        }

        jsonResponse([
            'success' => true,
            'message' => 'Server updated and restarted',
            'debug_cmd' => $result['cmd'],
            'output' => $result['output']
        ]);
    } else {
        jsonResponse([
            'success' => false,
            'error' => 'Failed to recreate container',
            'output' => $result['output']
        ], 500);
    }
}
function handle_ping()
{
    jsonResponse([
        'success' => true,
        'message' => 'API is working!',
        'time' => date('Y-m-d H:i:s')
    ]);
}

function handle_get_log()
{
    // Ensure clean output for this endpoint too
    if (ob_get_length()) {
        ob_clean();
    }

    $logFile = MCMM_LOG_DIR . '/mcmm.log';
    $debugFile = MCMM_LOG_DIR . '/debug.txt';
    $pageDebugFile = MCMM_LOG_DIR . '/debug_page.log';
    $apiDebugFile = MCMM_LOG_DIR . '/debug_api_servers.log';

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
}

function handle_start_agents()
{
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
}

function handle_agent_debug()
{
    $logFile = dirname(__DIR__) . '/mcmm_agent_debug.log';
    $mainFile = dirname(__DIR__) . '/mcmm.log';
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
}

function handle_debug_backups()
{
    $backupDir = '/mnt/user/appdata/backups';
    $files = glob($backupDir . '/*.zip');
    jsonResponse([
        'dir' => $backupDir,
        'exists' => is_dir($backupDir),
        'files' => $files,
        'files_count' => count($files ?: [])
    ]);
}

function handle_backups_list()
{
    $backupDir = '/mnt/user/appdata/backups';
    if (!is_dir($backupDir)) {
        mcmm_mkdir($backupDir, 0755, true);
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

    $serversDir = MCMM_SERVERS_DIR;
    $allConfigsByContainer = [];
    $allConfigsBySlug = [];
    $allConfigsByName = [];
    if (is_dir($serversDir)) {
        $dirs = glob($serversDir . '/*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            $metaFile = $dir . '/metadata.json';
            if (file_exists($metaFile)) {
                $meta = json_decode(@file_get_contents($metaFile), true);
                if ($meta) {
                    if (isset($meta['containerName'])) {
                        $allConfigsByContainer[$meta['containerName']] = $meta;
                    }
                    if (isset($meta['slug'])) {
                        $allConfigsBySlug[$meta['slug']] = $meta;
                    }
                    if (isset($meta['name'])) {
                        $allConfigsByName[safeContainerName($meta['name'])] = $meta;
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
            $meta = $allConfigsByContainer[$serverName] ??
                $allConfigsBySlug[$slugCandidate] ??
                $allConfigsByName[safeContainerName($serverName)] ??
                [];

            $icon = $meta['icon'] ?? $meta['logo'] ?? $meta['icon_url'] ?? '';
            if (!$icon && isset($liveMetadata[$serverName])) {
                $icon = $liveMetadata[$serverName]['icon'];
            }

            $backups[] = [
                'name' => $name,
                'size' => $stat['size'],
                'date' => (int) $timestamp ?: $stat['mtime'],
                'server' => $serverName,
                'icon' => $icon,
                'author' => $meta['author'] ?? 'Unknown',
                'modpack' => $meta['modpack_version_name'] ?? $meta['modpack'] ?? $meta['name'] ?? $serverName,
                'mc_version' => $meta['mc_version'] ?? '',
                'loader' => $meta['loader'] ?? ''
            ];
        }
    }

    // Sort by date desc
    usort($backups, function ($a, $b) {
        return $b['date'] - $a['date'];
    });

    jsonResponse(['success' => true, 'data' => $backups]);
}

function handle_backup_create()
{
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
}

function handle_backup_delete()
{
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
}

function handle_backup_reinstall()
{
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
    $tempDir = MCMM_TMP_DIR . '/restore_' . uniqid();
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
        @mcmm_rename($dataDir, $oldDir);
    }
    mcmm_mkdir($dataDir, 0775, true);

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
}


/**
 * Force run the background update worker for debugging.
 */
function handle_force_update_check()
{
    if (!defined('MCMM_DEBUG_FORCE_WORKER')) {
        define('MCMM_DEBUG_FORCE_WORKER', true);
    }

    // Capture output
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    ignore_user_abort(true);

    ob_start();
    try {
        require_once __DIR__ . '/background_worker.php';
    } catch (Throwable $e) {
        echo "Error: " . $e->getMessage();
    }
    $output = ob_get_clean();

    jsonResponse(['success' => true, 'output' => $output]);
}

/**
 * Handle global image update check
 */
function handle_check_global_image()
{
    set_update_progress('Scanning for itzg/minecraft-server images...', 5, 100);


    // 1. Detect ALL local itzg/minecraft-server tags AND images used by managed containers


    // a. Get tagged images
    $rawImages = shell_exec("docker images itzg/minecraft-server --format \"{{.Tag}}\" 2>&1");
    $tags = array_filter(explode("\n", trim($rawImages ?? '')));
    $imagesToCheck = [];
    foreach ($tags as $tag) {
        if ($tag !== '<none>') {
            $imagesToCheck[] = "itzg/minecraft-server:$tag";
        }
    }

    // b. Get images used by managed containers
    $psCmd = "docker ps -a --format '{{.Image}}' {{.Labels}}"; // We need to check labels too
    // Simplify: just get all itzg images from ps -a
    $psImages = shell_exec("docker ps -a --format '{{.Image}}' 2>&1");
    $psImageLines = array_filter(explode("\n", trim($psImages ?? '')));
    foreach ($psImageLines as $img) {
        if (strpos($img, 'itzg/minecraft-server') !== false) {
            $imagesToCheck[] = $img;
        }
    }

    $imagesToCheck = array_unique($imagesToCheck);

    if (empty($imagesToCheck)) {
        $msg = "MCMM Global Update Check: No 'itzg/minecraft-server' images found locally or in use.";
        set_update_progress('No images found', 100, 100);
        jsonResponse(['success' => true, 'updateAvailable' => false, 'message' => $msg]);
    }

    $updatesFound = [];

    // 2. Check each image for updates
    $force = isset($_GET['force']) && $_GET['force'] == '1';

    $totalImages = count($imagesToCheck);
    foreach ($imagesToCheck as $index => $image) {
        $currentProgress = 10 + (($index / $totalImages) * 85); // 10% to 95%
        set_update_progress("Checking image: $image", $currentProgress, 100);


        // Get local digest
        $cmd = "docker inspect " . escapeshellarg($image) . " 2>&1";
        $inspectJson = shell_exec($cmd);
        $inspectData = json_decode($inspectJson, true);

        $localDigests = [];
        if (is_array($inspectData) && isset($inspectData[0]['RepoDigests'])) {
            foreach ($inspectData[0]['RepoDigests'] as $rd) {
                $parts = explode('@', $rd);
                if (isset($parts[1])) {
                    $localDigests[] = $parts[1];
                }
            }
        }

        if (empty($localDigests)) {

            continue;
        }

        $cacheKey = "global_ver_chk_" . md5($image . implode(',', $localDigests));
        $cached = $force ? null : mcmm_cache_get($cacheKey);

        $updateAvailable = false;
        $remoteDigest = null;

        if ($cached !== null) {
            $updateAvailable = (bool) $cached;

        } else {
            $remoteDigest = getRemoteImageDigest($image);
            if ($remoteDigest === null) {

                $updateAvailable = null;
            } else {
                // Check if ANY of our local digests match the remote one
                $matchFound = false;
                foreach ($localDigests as $ld) {
                    if ($ld === $remoteDigest) {
                        $matchFound = true;
                        break;
                    }
                }
                $updateAvailable = !$matchFound;

                mcmm_cache_set($cacheKey, $updateAvailable, 3600);
            }
        }



        if ($updateAvailable) {
            $updatesFound[] = $image;
        }
    }

    set_update_progress('Check complete', 100, 100);

    if (!empty($updatesFound)) {
        // Return list of tags that need updates
        jsonResponse([
            'success' => true,
            'updateAvailable' => true,
            'targetImage' => 'Universal Update (' . count($updatesFound) . ' images)', // Display text
            'updateTags' => $updatesFound
        ]);
    } else {
        jsonResponse(['success' => true, 'updateAvailable' => false]);
    }

}

/**
 * Handle system image update
 * Stops all servers and pulls ALL requested images.
 */
function handle_update_system_image()
{
    @set_time_limit(0);
    ignore_user_abort(true);

    dbg("[UPDATE] System update started");
    set_update_progress('Identifying containers...', 5, 100);

    // 1. Identify all managed containers (running or stopped)
    // We use the same matching logic as handle_list_servers
    $psCmd = "docker ps -a --format '{{.ID}}|{{.Names}}|{{.Image}}|{{.Labels}}|{{.Status}}'";
    $psOutput = shell_exec($psCmd);
    $containers = [];
    foreach (explode("\n", trim($psOutput)) as $line) {
        if (!$line)
            continue;
        $parts = explode('|', $line);
        if (count($parts) < 5)
            continue;
        list($id, $name, $image, $labels, $status) = $parts;

        $isItzg = (strpos($image, 'itzg/') !== false) || (strpos($labels, 'net.unraid.docker.repository=itzg/') !== false);
        $isMcmm = (strpos($labels, 'mcmm=1') !== false) || (strpos($labels, 'mcmm.server=true') !== false);

        if ($isItzg || $isMcmm) {
            $containers[] = [
                'id' => trim($id),
                'name' => trim($name),
                'isRunning' => (strpos($status, 'Up') !== false)
            ];
        }
    }

    dbg("[UPDATE] Found " . count($containers) . " managed containers");

    // 2. Stop running containers
    $runningCount = 0;
    foreach ($containers as $c)
        if ($c['isRunning'])
            $runningCount++;

    if ($runningCount > 0) {
        $stopped = 0;
        foreach ($containers as $con) {
            if ($con['isRunning']) {
                $stopped++;
                set_update_progress('Stopping servers...', 5 + (($stopped / $runningCount) * 15), 100, "Stopping {$con['name']} ($stopped/$runningCount)");
                shell_exec("docker stop " . escapeshellarg($con['id']));
            }
        }
        sleep(2);
    }

    // 3. Collect images from managed containers and pull updates
    set_update_progress('Scanning for images to pull...', 20, 100);
    $imagesToPull = [];
    foreach ($containers as $c) {
        $id = $c['id'];
        $json = shell_exec("docker inspect " . escapeshellarg($id));
        $inspect = json_decode($json, true);
        if (!$inspect || !isset($inspect[0]))
            continue;

        $cData = $inspect[0];
        $image = $cData['Config']['Image'];

        // Support for Unraid's pinned images (SHA256) - resolve back to its repository tag for pulling
        if (strpos($image, 'sha256:') === 0 && isset($cData['Config']['Labels']['net.unraid.docker.repository'])) {
            $image = $cData['Config']['Labels']['net.unraid.docker.repository'];
        }

        if ($image) {
            $imagesToPull[] = $image;
        }
    }
    $imagesToPull = array_unique(array_filter($imagesToPull));

    $pullOutput = "";
    $pullCount = count($imagesToPull);
    if ($pullCount > 0) {
        foreach ($imagesToPull as $index => $image) {
            $currentProgress = 25 + (($index / $pullCount) * 45); // Range: 25 -> 70
            set_update_progress("Pulling images...", $currentProgress, 100, "Updating $image (" . ($index + 1) . "/$pullCount)");

            $pullOutput .= "--- Pulling $image ---\n";
            $pullCmd = "docker pull " . escapeshellarg($image) . " 2>&1";
            $res = shell_exec($pullCmd);
            $pullOutput .= $res . "\n\n";
            dbg("[UPDATE] Pull $image: " . substr(trim($res), 0, 100));
        }
        file_put_contents(MCMM_BASE_DIR . '/update_pull_results.log', "Update at " . date('Y-m-d H:i:s') . "\n" . $pullOutput);
    }

    // 4. Recreate phase (Applies the pulled images)
    $totalContainers = count($containers);
    if ($totalContainers > 0) {
        foreach ($containers as $index => $con) {
            $id = $con['id'];
            $name = $con['name'];
            $wasRunning = $con['isRunning'];

            $currentProgress = 70 + (($index / $totalContainers) * 30); // Range: 70 -> 100
            set_update_progress('Updating containers...', $currentProgress, 100, "Applying to $name (" . ($index + 1) . "/$totalContainers)");

            // Inspect to get full config for recreation
            $json = shell_exec("docker inspect " . escapeshellarg($id));
            $inspect = json_decode($json, true);
            if (!$inspect || !isset($inspect[0])) {
                dbg("[UPDATE] Failed to inspect $name during recreation phase");
                continue;
            }
            $cData = $inspect[0];

            $image = $cData['Config']['Image'];
            // Support for Unraid's pinned images (SHA256) - resolve back to its repository tag
            // This is critical because if it's pinned to a sha256, it will never "update" to latest layers
            if (strpos($image, 'sha256:') === 0 && isset($cData['Config']['Labels']['net.unraid.docker.repository'])) {
                $image = $cData['Config']['Labels']['net.unraid.docker.repository'];
                dbg("[UPDATE] Resolved pinned image SHA to repository tag: $image for container $name");
            }
            $dataDir = getContainerDataDirById($id);
            if (!$dataDir) {
                dbg("[UPDATE] Failed to find data directory for $name");
                continue;
            }

            $env = [];
            if (!empty($cData['Config']['Env'])) {
                foreach ($cData['Config']['Env'] as $e) {
                    $parts = explode('=', $e, 2);
                    if (count($parts) === 2)
                        $env[$parts[0]] = $parts[1];
                }
            }

            $port = 25565;
            if (isset($cData['HostConfig']['PortBindings']['25565/tcp'][0]['HostPort'])) {
                $port = $cData['HostConfig']['PortBindings']['25565/tcp'][0]['HostPort'];
            }

            $res = mcmm_recreate_container($id, $image, $env, $port, $name, $dataDir, $wasRunning);
            if ($res['success']) {
                dbg("[UPDATE] Successfully updated container $name (wasRunning: " . ($wasRunning ? 'YES' : 'NO') . ")");
            } else {
                dbg("[UPDATE] Failed to update container $name: " . $res['output']);
            }
        }
    }

    set_update_progress('Update complete', 100, 100);
    mcmm_unraid_recheck();

    jsonResponse(['success' => true, 'output' => $pullOutput]);
}

/**
 * Get the current progress of the system update.
 */
function handle_get_update_progress()
{
    $file = MCMM_BASE_DIR . '/mcmm_update.json';
    if (!file_exists($file)) {
        jsonResponse(['success' => true, 'progress' => ['status' => 'idle', 'current' => 0, 'total' => 0]]);
    }

    $data = json_decode(file_get_contents($file), true);

    // If progress is old (e.g. > 1 min), consider it stale/idle
    if (isset($data['timestamp']) && (time() - $data['timestamp'] > 60)) {
        jsonResponse(['success' => true, 'progress' => ['status' => 'idle', 'current' => 0, 'total' => 0]]);
    }

    jsonResponse(['success' => true, 'progress' => $data]);
}

/**
 * Helper to write update progress to a temporary file.
 */
function set_update_progress($status, $current = 0, $total = 0, $details = '')
{
    $progress = [
        'status' => $status,
        'current' => round($current, 1),
        'total' => $total,
        'details' => $details,
        'timestamp' => time()
    ];
    @file_put_contents(MCMM_BASE_DIR . '/mcmm_update.json', json_encode($progress));
}

/**
 * Reconciliation step for Unraid's Docker Manager.
 * Clears stale update status and triggers a refresh.
 */
function mcmm_unraid_recheck()
{
    dbg("[UNRAID] Triggering update reconciliation...");

    // 1. Clear Unraid's Docker update status cache (check multiple common paths)
    $unraidStatusPaths = [
        '/var/lib/docker/unraid-update-status.json',
        '/mnt/user/system/docker/unraid-update-status.json'
    ];
    foreach ($unraidStatusPaths as $path) {
        if (file_exists($path)) {
            dbg("[UNRAID] Removing stale cache: $path");
            @unlink($path);
        }
    }

    // 2. Clear MCMM's own update check cache (version check json files)
    $cacheDir = defined('MCMM_TMP_DIR') ? MCMM_TMP_DIR : '/mnt/user/appdata/mcmm/tmp';
    if (is_dir($cacheDir)) {
        $files = glob($cacheDir . '/*.json');
        if ($files) {
            foreach ($files as $f) {
                if (basename($f) !== 'mcmm_update.json') { // Don't delete the progress file
                    @unlink($f);
                }
            }
            dbg("[UNRAID] Cleared MCMM version check cache in $cacheDir");
        }
    }

    // 3. Trigger Unraid's background update check
    // Preference 1: The official dynamix tool
    $dynamixScript = '/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/docker';
    if (file_exists($dynamixScript)) {
        dbg("[UNRAID] Triggering dynamix docker update check...");
        @shell_exec("/usr/bin/php -q $dynamixScript update check > /dev/null 2>&1 &");
    } else {
        // Preference 2: Fallback to dockerupdate script if found
        $updateScript = '/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/dockerupdate';
        if (file_exists($updateScript)) {
            dbg("[UNRAID] Triggering native dockerupdate scan (fallback)...");
            @shell_exec("/usr/bin/php -q $updateScript > /dev/null 2>&1 &");
        }
    }
}


/**
 * Ensure MC Router container is running with current settings
 */
function ensure_mc_router_container()
{
    $configPath = MCMM_CONFIG_DIR . '/mcmm.cfg';
    $config = @parse_ini_file($configPath);
    
    $enabled = !empty($config['mc_router_enabled']);
    $port = $config['mc_router_port'] ?? 25565;
    $apiPort = $config['mc_router_api_port'] ?? 25564;
    
    $containerName = "mc-router";
    $diagFile = MCMM_BASE_DIR . '/logs/router_diag.log';
    $log = date('Y-m-d H:i:s') . " - Checking MC Router\n";
    
    // Check if running
    $state = shell_exec("docker inspect -f '{{.State.Running}}' " . escapeshellarg($containerName) . " 2>/dev/null");
    $isRunning = trim($state) === 'true';
    $log .= "Is running: " . ($isRunning ? 'YES' : 'NO') . "\n";
    $log .= "Config Enabled: " . ($enabled ? 'YES' : 'NO') . "\n";

    if (!$enabled) {
        if ($isRunning) {
             // Stop and remove if disabled to free up ports
             shell_exec("docker stop " . escapeshellarg($containerName) . " && docker rm " . escapeshellarg($containerName));
        }
        return;
    }
    
    if ($isRunning) {
        // ideally check if ports match, but for now we assume if it runs it is correct
        // we can force recreation if needed by stopping it manually
        return;
    }
    
    // Remove if exists but stopped
    shell_exec("docker rm " . escapeshellarg($containerName) . " 2>/dev/null");
    
    $cmd = sprintf(
        "docker run -d --name %s " .
        "--restart unless-stopped " .
        "-v /var/run/docker.sock:/var/run/docker.sock " .
        "-p %s:25565 " .
        "-p %s:25564 " .
        "itzg/mc-router " .
        "--api-binding :25564 " .
        "--in-docker",
        escapeshellarg($containerName),
        escapeshellarg($port),
        escapeshellarg($apiPort)
    );
    
    $log .= "Running: $cmd\n";
    $res = shell_exec($cmd . " 2>&1");
    $log .= "Result: $res\n";
    file_put_contents($diagFile, $log, FILE_APPEND);
}
