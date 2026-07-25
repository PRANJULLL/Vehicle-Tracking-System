<?php
/**
 * Fetch live location for a single bus from FleetHunt with automatic route-based simulation fallback.
 */

require_once __DIR__ . '/env.php';

class FleetHuntException extends \Exception {}

function getBusLocation(array $bus, ?array $routeInfo = null): array
{
    // Check if simulation mode is explicitly enabled or token is missing/placeholder
    $forceSimulate = getenv('FLEETHUNT_SIMULATE') === 'true' || 
                     empty($bus['token']) || 
                     $bus['token'] === 'your-token-here';

    if (!$forceSimulate) {
        try {
            $host = getenv('FLEETHUNT_HOST') ?: 'https://app.fleethunt.in';
            $url = $host . '/api/fleet';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $bus['token'],
                    'Accept: application/json',
                ],
            ]);

            $body = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body !== false && $statusCode >= 200 && $statusCode < 300) {
                $data = json_decode($body, true);
                if (is_array($data) && ($data['status'] ?? 0) === 1 && isset($data['devices']) && is_array($data['devices'])) {
                    $matchedDevice = null;
                    $targetNo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $bus['vehicleNo']));

                    foreach ($data['devices'] as $device) {
                        if (!is_array($device)) continue;
                        $deviceName = isset($device['name']) ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$device['name'])) : '';
                        if ($deviceName === $targetNo) {
                            $matchedDevice = $device;
                            break;
                        }
                    }

                    if ($matchedDevice === null && count($data['devices']) === 1) {
                        $matchedDevice = $data['devices'][0];
                    }

                    if ($matchedDevice !== null) {
                        $lat = $matchedDevice['latitude'] ?? $matchedDevice['lat'] ?? null;
                        $lng = $matchedDevice['longitude'] ?? $matchedDevice['lng'] ?? null;
                        if ($lat !== null && $lng !== null) {
                            return [
                                'vehicleNo' => $bus['vehicleNo'],
                                'lat'       => (float)$lat,
                                'lng'       => (float)$lng,
                                'speed'     => isset($matchedDevice['speed']) ? (float)$matchedDevice['speed'] : 0,
                                'heading'   => isset($matchedDevice['angle']) ? (float)$matchedDevice['angle'] : ($matchedDevice['heading'] ?? 0),
                                'timestamp' => $matchedDevice['device_time'] ?? $matchedDevice['timestamp'] ?? date('c'),
                                'simulated' => false,
                                'raw'       => $matchedDevice,
                            ];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("FleetHunt API call failed for {$bus['vehicleNo']}: " . $e->getMessage());
        }
    }

    // Fallback: Generate dynamic simulated bus movement along assigned route stops + School
    return getSimulatedBusLocation($bus, $routeInfo);
}

function getSimulatedBusLocation(array $bus, ?array $routeInfo = null): array
{
    $schoolLat = 30.167955;
    $schoolLng = 75.845110;

    $waypoints = [];

    // Load checkpoints to resolve stop coordinates
    $checkpointsPath = __DIR__ . '/checkpoints.json';
    $checkpointsData = file_exists($checkpointsPath) ? (json_decode(file_get_contents($checkpointsPath), true) ?? []) : [];

    // Extract stops for this route
    $stopIds = $routeInfo['stops'] ?? [];

    foreach ($stopIds as $stopId) {
        foreach ($checkpointsData as $cp) {
            if ($cp['id'] === $stopId && isset($cp['lat'], $cp['lng']) && $cp['lat'] !== null && $cp['lng'] !== null) {
                $waypoints[] = [(float)$cp['lat'], (float)$cp['lng']];
                break;
            }
        }
    }

    // Always append School to complete the route circuit
    $waypoints[] = [$schoolLat, $schoolLng];

    // If less than 2 waypoints, add a default stop near Sangrur
    if (count($waypoints) < 2) {
        array_unshift($waypoints, [30.2462, 75.8395]); // Hareri Road
    }

    // Compute segment distances & total distance
    $segmentDistances = [];
    $totalDistance = 0.0;
    $n = count($waypoints);

    for ($i = 0; $i < $n; $i++) {
        $from = $waypoints[$i];
        $to = $waypoints[($i + 1) % $n];
        $d = calculateHaversineMeters($from[0], $from[1], $to[0], $to[1]);
        if ($d <= 0) $d = 100.0;
        $segmentDistances[] = $d;
        $totalDistance += $d;
    }

    // Bus speed simulation: ~36 km/h = 10 m/s average speed
    $speedMs = 10.0; 
    $totalLoopSeconds = max(60.0, $totalDistance / $speedMs);

    // Stagger start position by bus ID so buses move independently
    $busOffset = ($bus['id'] ?? 1) * 45.0;
    $currentTime = time() + $busOffset;
    $loopTime = fmod($currentTime, $totalLoopSeconds);
    $distanceTravelled = ($loopTime / $totalLoopSeconds) * $totalDistance;

    // Find current segment
    $accumulated = 0.0;
    $currentSegIdx = 0;
    $segProgress = 0.0;

    for ($i = 0; $i < count($segmentDistances); $i++) {
        if ($accumulated + $segmentDistances[$i] >= $distanceTravelled) {
            $currentSegIdx = $i;
            $segProgress = ($distanceTravelled - $accumulated) / $segmentDistances[$i];
            break;
        }
        $accumulated += $segmentDistances[$i];
    }

    $fromPt = $waypoints[$currentSegIdx];
    $toPt = $waypoints[($currentSegIdx + 1) % $n];

    $lat = $fromPt[0] + ($toPt[0] - $fromPt[0]) * $segProgress;
    $lng = $fromPt[1] + ($toPt[1] - $fromPt[1]) * $segProgress;

    // Calculate direction heading (bearing angle in degrees)
    $dLng = deg2rad($toPt[1] - $fromPt[1]);
    $lat1 = deg2rad($fromPt[0]);
    $lat2 = deg2rad($toPt[0]);
    $y = sin($dLng) * cos($lat2);
    $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLng);
    $heading = fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0);

    // Speed simulation (km/h) with subtle dynamic variation
    $distToWaypoint = min(
        calculateHaversineMeters($lat, $lng, $fromPt[0], $fromPt[1]),
        calculateHaversineMeters($lat, $lng, $toPt[0], $toPt[1])
    );

    if ($distToWaypoint < 30) {
        $speedKm = 0.0; // Stationary at stop/school
    } elseif ($distToWaypoint < 100) {
        $speedKm = 16.0 + sin($currentTime) * 4.0; // Slowing down or picking up
    } else {
        $speedKm = 34.0 + sin($currentTime / 2.0) * 5.0; // Normal transit
    }

    return [
        'vehicleNo' => $bus['vehicleNo'],
        'lat'       => round($lat, 6),
        'lng'       => round($lng, 6),
        'speed'     => round($speedKm, 1),
        'heading'   => round($heading, 1),
        'timestamp' => date('c'),
        'simulated' => true,
    ];
}

function calculateHaversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $R = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}
