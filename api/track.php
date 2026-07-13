<?php
/**
 * POST /api/track  { student_id }
 *
 * Looks up which bus a student rides, fetches (or reuses a cached) live
 * GPS location for that bus, and returns it to the parent's browser.
 */

require_once __DIR__ . '/../src/buses.php';
require_once __DIR__ . '/../src/cache.php';
require_once __DIR__ . '/../src/fleethunt.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$studentId = isset($input['student_id']) ? trim((string) $input['student_id']) : '';

if ($studentId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'student_id is required']);
    exit;
}

$studentsPath = __DIR__ . '/../src/students.json';
$students = json_decode(file_get_contents($studentsPath), true) ?? [];

$key = strtoupper($studentId);
if (!isset($students[$key])) {
    http_response_code(404);
    echo json_encode(['error' => 'No student found with this ID']);
    exit;
}

$student = $students[$key];

$bus = getBusById((int) $student['busId']);
if (!$bus) {
    http_response_code(404);
    echo json_encode(['error' => 'No bus mapped to this student']);
    exit;
}

try {
    // Serve from cache if fresh, else hit FleetHunt
    $location = cacheGet($bus['vehicleNo']);
    if ($location === null) {
        $location = getBusLocation($bus);
        cacheSet($bus['vehicleNo'], $location);
    }

    echo json_encode([
        'student' => ['id' => $key, 'name' => $student['name']],
        'bus' => ['vehicleNo' => $bus['vehicleNo']],
        'location' => $location,
    ]);
} catch (FleetHuntException $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()]);
}
