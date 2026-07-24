<?php
/**
 * GET /api/checkpoints.php
 * Returns list of configured pickup checkpoints / bus stops.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$checkpointsPath = __DIR__ . '/../src/checkpoints.json';

if (!file_exists($checkpointsPath)) {
    http_response_code(404);
    echo json_encode(['error' => 'Checkpoints data file not found']);
    exit;
}

$checkpoints = json_decode(file_get_contents($checkpointsPath), true) ?? [];

echo json_encode([
    'total' => count($checkpoints),
    'checkpoints' => $checkpoints
]);
