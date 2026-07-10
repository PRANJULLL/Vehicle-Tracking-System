<?php
/**
 * Very simple file-based cache: avoids hammering FleetHunt when multiple
 * parents are tracking the same bus at the same time.
 *
 * PHP requests are short-lived (unlike the Node process), so an in-memory
 * Map doesn't persist between requests. A tiny cache file per bus gives us
 * the same effect. Cache lifetime: 5 seconds (adjust CACHE_TTL_SECONDS as
 * needed).
 */

const CACHE_TTL_SECONDS = 5;

function cacheDir(): string
{
    $dir = dirname(__DIR__) . '/cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function cacheFilePath(string $vehicleNo): string
{
    // Sanitize vehicle number so it's always a safe filename
    $safeName = preg_replace('/[^A-Za-z0-9_-]/', '_', $vehicleNo);
    return cacheDir() . "/{$safeName}.json";
}

function cacheGet(string $vehicleNo): ?array
{
    $file = cacheFilePath($vehicleNo);
    if (!is_file($file)) {
        return null;
    }

    $raw = file_get_contents($file);
    $entry = json_decode($raw, true);

    if (!$entry || !isset($entry['expiresAt'], $entry['data'])) {
        return null;
    }

    if (time() > $entry['expiresAt']) {
        @unlink($file);
        return null;
    }

    return $entry['data'];
}

function cacheSet(string $vehicleNo, array $data): void
{
    $file = cacheFilePath($vehicleNo);
    $entry = [
        'data' => $data,
        'expiresAt' => time() + CACHE_TTL_SECONDS,
    ];
    file_put_contents($file, json_encode($entry), LOCK_EX);
}
