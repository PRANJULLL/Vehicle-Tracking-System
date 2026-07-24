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

$input       = json_decode(file_get_contents('php://input'), true);
$studentId   = isset($input['student_id'])   ? trim((string) $input['student_id'])   : '';
$studentName = isset($input['student_name']) ? trim((string) $input['student_name']) : '';
$classFilter = isset($input['class'])        ? trim((string) $input['class'])        : '';

$studentsPath = __DIR__ . '/../src/students.json';
$students = json_decode(file_get_contents($studentsPath), true) ?? [];

$student = null;
$key = null;

// 1. Direct ID lookup if student_id is provided
if ($studentId !== '') {
    $lookupKey = strtoupper($studentId);
    if (isset($students[$lookupKey])) {
        $key = $lookupKey;
        $student = $students[$lookupKey];
    } else {
        // Fallback: If student_id input was actually a student name
        $studentName = $studentId;
    }
}

// 2. Name & Class search
if ($student === null && $studentName !== '') {
    $matches = [];
    $nameLower  = strtolower($studentName);
    $classLower = strtolower($classFilter);

    foreach ($students as $sId => $sData) {
        $sNameLower  = strtolower($sData['name']);
        $sClassLower = strtolower($sData['class'] ?? '');

        if (strpos($sNameLower, $nameLower) !== false || strtolower($sId) === $nameLower) {
            if ($classLower !== '' && $classLower !== 'all') {
                if ($sClassLower !== $classLower) {
                    continue;
                }
            }
            $matches[$sId] = $sData;
        }
    }

    if (count($matches) === 1) {
        $key = array_key_first($matches);
        $student = $matches[$key];
    } else if (count($matches) > 1) {
        // Multiple students matched! Return options list for parent selection
        $resultList = [];
        foreach ($matches as $mId => $mData) {
            $resultList[] = [
                'id'      => $mId,
                'name'    => $mData['name'],
                'class'   => $mData['class'] ?? '',
                'address' => $mData['address'] ?? '',
                'stop'    => $mData['stop'] ?? '',
            ];
        }
        echo json_encode([
            'multipleMatches' => true,
            'students'        => $resultList
        ]);
        exit;
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'No student found matching this name and class.']);
        exit;
    }
}

if ($student === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a student name or ID to track.']);
    exit;
}

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

    // ── Load route info ───────────────────────────────────────────────────────
    $routeInfo = null;
    $checkpointsData = null;
    $routesPath = __DIR__ . '/../src/routes.json';
    if (file_exists($routesPath)) {
        $allRoutes  = json_decode(file_get_contents($routesPath), true) ?? [];
        // Senior students have a routeId field (e.g. "1S"); junior students fall back to busId
        $routeIdStr = isset($student['routeId']) ? $student['routeId'] : (string) $student['busId'];
        if (isset($allRoutes[$routeIdStr])) {
            $routeInfo = $allRoutes[$routeIdStr];
        }
    }

    // ── Find nearest checkpoint to the bus ─────────────────────────────────
    $nearestCheckpoint = null;
    if ($busLat !== null && $busLng !== null && file_exists(__DIR__ . '/../src/checkpoints.json')) {
        $checkpointsData = json_decode(file_get_contents(__DIR__ . '/../src/checkpoints.json'), true) ?? [];
        $minDist = null;
        $routeStopIds = $routeInfo ? ($routeInfo['stops'] ?? []) : [];

        foreach ($checkpointsData as $cp) {
            if (($cp['status'] ?? 'Active') !== 'Active') continue;
            if ($cp['lat'] === null || $cp['lng'] === null) continue;
            // Only search stops on this bus route
            if (!empty($routeStopIds) && !in_array($cp['id'], $routeStopIds)) continue;

            $dist = haversineMeters($busLat, $busLng, (float)$cp['lat'], (float)$cp['lng']);
            if ($minDist === null || $dist < $minDist) {
                $minDist = $dist;
                $nearestCheckpoint = [
                    'id'             => $cp['id'],
                    'name'           => $cp['name'],
                    'lat'            => (float)$cp['lat'],
                    'lng'            => (float)$cp['lng'],
                    'fee'            => $cp['fee'],
                    'landmark'       => $cp['landmark'],
                    'distanceMeters' => (int)round($dist),
                    'distanceKm'     => round($dist / 1000, 2),
                ];
            }
        }
    }

    // ── Get student's assigned stop info ─────────────────────────────────────
    $studentStop = null;
    $studentStopId = $student['stop'] ?? null;
    if ($studentStopId && file_exists(__DIR__ . '/../src/checkpoints.json')) {
        $checkpointsData = $checkpointsData ?? json_decode(file_get_contents(__DIR__ . '/../src/checkpoints.json'), true) ?? [];
        foreach ($checkpointsData as $cp) {
            if ($cp['id'] === $studentStopId) {
                $studentStop = [
                    'id'       => $cp['id'],
                    'name'     => $cp['name'],
                    'lat'      => $cp['lat'],
                    'lng'      => $cp['lng'],
                    'fee'      => $cp['fee'],
                    'landmark' => $cp['landmark'],
                ];
                break;
            }
        }
    }

    // ── Build full route stops list with coordinates for map path ─────────────
    $routeStops = [];
    $checkpointsData = $checkpointsData ?? json_decode(file_get_contents(__DIR__ . '/../src/checkpoints.json'), true) ?? [];
    $routeStopIds = $routeInfo ? ($routeInfo['stops'] ?? []) : [];
    
    foreach ($routeStopIds as $idx => $stopId) {
        foreach ($checkpointsData as $cp) {
            if ($cp['id'] === $stopId) {
                $routeStops[] = [
                    'seq'      => $idx + 1,
                    'id'       => $cp['id'],
                    'name'     => $cp['name'],
                    'lat'      => $cp['lat'] !== null ? (float)$cp['lat'] : null,
                    'lng'      => $cp['lng'] !== null ? (float)$cp['lng'] : null,
                    'landmark' => $cp['landmark'],
                    'fee'      => $cp['fee'],
                ];
                break;
            }
        }
    }

    echo json_encode([
        'student' => [
            'id'      => $key,
            'name'    => $student['name'],
            'class'   => $student['class']   ?? null,
            'address' => $student['address'] ?? null,
            'stop'    => $studentStop,
        ],
        'bus' => [
            'vehicleNo'  => $bus['vehicleNo'],
            'routeName'  => $routeInfo['name']       ?? null,
            'routeLabel' => $routeInfo['routeLabel'] ?? null,
            'stops'      => $routeStops,
        ],
        'school' => [
            'name' => 'Maple International School, Sangrur',
            'lat'  => SCHOOL_LAT,
            'lng'  => SCHOOL_LNG,
        ],
        'location'          => $location,
        'busStatus'         => $busStatus,
        'nearestCheckpoint' => $nearestCheckpoint,
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
