<?php
/**
 * TEMPORARY DIAGNOSTIC — delete this file after setup is confirmed.
 * Visit https://yourdomain.com/env.setup.php to check server health.
 */
header('Content-Type: text/plain');

echo "=== PHP Version ===\n";
echo PHP_VERSION . "\n\n";

echo "=== Extensions ===\n";
echo "curl:   " . (extension_loaded('curl')  ? "OK" : "MISSING") . "\n";
echo "json:   " . (extension_loaded('json')  ? "OK" : "MISSING") . "\n\n";

echo "=== .env file ===\n";
$envPath = __DIR__ . '/.env';
echo "Path:   " . $envPath . "\n";
echo "Exists: " . (file_exists($envPath) ? "YES" : "NO — upload your .env file to the server root") . "\n\n";

echo "=== cache/ directory ===\n";
$cacheDir = __DIR__ . '/cache';
echo "Path:     " . $cacheDir . "\n";
echo "Exists:   " . (is_dir($cacheDir)     ? "YES" : "NO") . "\n";
echo "Writable: " . (is_writable($cacheDir) ? "YES" : "NO — chmod 755 the cache/ folder") . "\n\n";

echo "=== src/ files ===\n";
foreach (['env.php','buses.php','cache.php','fleethunt.php','students.json'] as $f) {
    $p = __DIR__ . '/src/' . $f;
    echo $f . ": " . (file_exists($p) ? "OK" : "MISSING") . "\n";
}
echo "\n";

echo "=== BUS tokens from .env ===\n";
if (file_exists($envPath)) {
    require_once __DIR__ . '/src/env.php';
    $i = 1;
    while (getenv("BUS_{$i}_VEHICLE_NO") !== false) {
        echo "BUS_{$i}: " . getenv("BUS_{$i}_VEHICLE_NO") . " (token set: " . (getenv("BUS_{$i}_TOKEN") ? "YES" : "NO") . ")\n";
        $i++;
    }
    if ($i === 1) echo "No BUS_n_VEHICLE_NO vars found — .env may be empty or unreadable.\n";
} else {
    echo "Skipped — .env missing.\n";
}
