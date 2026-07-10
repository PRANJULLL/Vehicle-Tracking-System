<?php
/**
 * Fetch live location for a single bus from FleetHunt.
 *
 * ⚠️ TODO: Replace the URL / auth style below once you confirm the real
 * endpoint from the Postman collection (Import > paste the documenter URL,
 * then open the actual request to see method + path + auth type).
 *
 * Common patterns for this kind of API — try these in Postman first:
 *   A) GET {HOST}/api/v1/vehicle/location?vehicle_no=PB13BR1060
 *      Header: Authorization: Bearer <token>
 *
 *   B) GET {HOST}/api/v1/vehicle/location
 *      Header: token: <token>
 *
 *   C) GET {HOST}/api/v1/track?imei=<device_imei>
 *      Query: token=<token>
 *
 * Whichever one actually returns JSON with lat/lng, plug it in below.
 */

require_once __DIR__ . '/env.php';

class FleetHuntException extends \Exception {}

function getBusLocation(array $bus): array
{
    $host = getenv('FLEETHUNT_HOST') ?: 'https://app.fleethunt.in';
    $url = $host . '/api/fleet';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $bus['token'],
            'Accept: application/json',
        ],
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        error_log("FleetHunt API error for {$bus['vehicleNo']}: {$curlError}");
        throw new FleetHuntException("Could not fetch location for bus {$bus['vehicleNo']}");
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        error_log("FleetHunt API error for {$bus['vehicleNo']}: HTTP {$statusCode}");
        throw new FleetHuntException("Could not fetch location for bus {$bus['vehicleNo']}");
    }

    $data = json_decode($body, true);
    if (!is_array($data) || ($data['status'] ?? 0) !== 1 || !isset($data['devices']) || !is_array($data['devices'])) {
        error_log("FleetHunt API error for {$bus['vehicleNo']}: invalid JSON response");
        throw new FleetHuntException("Could not fetch location for bus {$bus['vehicleNo']}");
    }

    // Find the device matching the vehicle number
    $matchedDevice = null;
    $targetNo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $bus['vehicleNo']));

    foreach ($data['devices'] as $device) {
        if (!is_array($device)) {
            continue;
        }
        $deviceName = isset($device['name']) ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$device['name'])) : '';
        if ($deviceName === $targetNo) {
            $matchedDevice = $device;
            break;
        }
    }

    // Fallback: if only one device is returned, use it
    if ($matchedDevice === null && count($data['devices']) === 1) {
        $matchedDevice = $data['devices'][0];
    }

    if ($matchedDevice === null) {
        error_log("FleetHunt API error for {$bus['vehicleNo']}: vehicle not found in device list");
        throw new FleetHuntException("Could not find matching device for bus {$bus['vehicleNo']}");
    }

    return [
        'vehicleNo' => $bus['vehicleNo'],
        'lat' => $matchedDevice['latitude'] ?? $matchedDevice['lat'] ?? null,
        'lng' => $matchedDevice['longitude'] ?? $matchedDevice['lng'] ?? null,
        'speed' => $matchedDevice['speed'] ?? null,
        'heading' => $matchedDevice['angle'] ?? $matchedDevice['heading'] ?? $matchedDevice['course'] ?? null,
        'timestamp' => $matchedDevice['device_time'] ?? $matchedDevice['timestamp'] ?? $matchedDevice['updated_at'] ?? date('c'),
        'raw' => $matchedDevice,
    ];
}
