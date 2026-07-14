<?php
/**
 * POST /api/track  { student_id }
 *
 * Looks up which bus a student rides, fetches (or reuses a cached) live
 * GPS location for that bus, and returns it to the parent's browser.
 *
 * Response includes a "busStatus" field:
 *   "at_school"  — bus is within SCHOOL_RADIUS_M of school AND speed == 0
 *   "stopped"    — speed == 0 but bus is NOT near school
 *   "moving"     — bus is in motion (speed > 0)
 */

// ── School location ──────────────────────────────────────────────────────────
define('SCHOOL_LAT',      30.167955);
define('SCHOOL_LNG',      75.845110);
define('SCHOOL_RADIUS_M', 300);        // metres — bus counts as "at school" within this radius

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

    // ── Determine bus status ─────────────────────────────────────────────────
    $speed     = isset($location['speed']) ? (float) $location['speed'] : null;
    $busLat    = isset($location['lat'])   ? (float) $location['lat']   : null;
    $busLng    = isset($location['lng'])   ? (float) $location['lng']   : null;

    $busStatus = 'moving'; // default

    if ($speed !== null && $speed == 0) {
        // Bus is stationary — is it at the school?
        if ($busLat !== null && $busLng !== null) {
            $distToSchool = haversineMeters(SCHOOL_LAT, SCHOOL_LNG, $busLat, $busLng);
            $busStatus = ($distToSchool <= SCHOOL_RADIUS_M) ? 'at_school' : 'stopped';
        } else {
            $busStatus = 'stopped';
        }
    }

    echo json_encode([
        'student'   => ['id' => $key, 'name' => $student['name']],
        'bus'       => ['vehicleNo' => $bus['vehicleNo']],
        'location'  => $location,
        'busStatus' => $busStatus,
    ]);
} catch (FleetHuntException $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()]);
}

// ── Haversine distance helper ─────────────────────────────────────────────────
function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $R    = 6371000; // Earth radius in metres
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a    = sin($dLat / 2) ** 2
          + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}
