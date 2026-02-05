<?php

/**
 * Check Docker Hub for image updates by comparing remote digest with local/running digest.
 *
 * @param string $image Full image name (e.g., 'itzg/minecraft-server:latest')
 * @param string $currentDigest The current digest (sha256:...) of the running container/image
 * @return bool|null True if update available, False if up to date, Null on error
 * @psalm-taint-escape ssrf
 */
function checkDockerHubUpdate(string $image, string $currentDigest): ?bool
{
    // Parse image
    if (strpos($image, '/') === false) {
        $image = "library/$image"; // Official images
    }
    $parts = explode(':', $image);
    $repo = $parts[0];
    $tag = $parts[1] ?? 'latest';

    // 1. Get Auth Token
    $tokenUrl = "https://auth.docker.io/token?service=registry.docker.io&scope=repository:$repo:pull";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $tokenUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    $tokenJson = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$tokenJson) {
        return null;
    }

    $tokenData = json_decode((string)$tokenJson, true);
    $token = $tokenData['token'] ?? null;
    if (!$token) {
        return null;
    }

    // 2. Fetch Manifest Digest
    $manifestUrl = "https://registry-1.docker.io/v2/$repo/manifests/$tag";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $manifestUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true, // We need headers
        CURLOPT_NOBODY => true, // We only need headers (HEAD request)
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Accept: application/vnd.docker.distribution.manifest.v2+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json"
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return null;
    }

    // Extract Docker-Content-Digest header
    if (preg_match('/docker-content-digest:\s*(sha256:[a-f0-9]+)/i', $response, $matches)) {
        $remoteDigest = $matches[1];

        // Compare digests
        // Note: Running digest might be the ImageID (which is a config digest, not manifest digest).
        // However, standard "docker inspect" usage often gives Config Digest (Image ID).
        // The Registry returns Distribution Digest. These are DIFFERENT.
        // CHECK: We need to check if the user is running the image identified by this distribution digest.
        // Actually, "docker images --digests" shows the distribution digest.
        // BUT "docker inspect" returns the config digest as "Id".
        // The "RepoDigests" field in "docker inspect" contains the distribution digest (e.g. repo@sha256:...)

        return $remoteDigest !== $currentDigest;
    }

    return null;
}
