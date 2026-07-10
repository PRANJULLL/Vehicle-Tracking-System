<?php
/**
 * Reads BUS_n_VEHICLE_NO / BUS_n_TOKEN pairs from .env and builds a lookup
 * list. Add more buses by adding more BUS_n_ rows in .env — no code
 * change needed.
 */

require_once __DIR__ . '/env.php';

function loadBuses(): array
{
    $buses = [];
    $i = 1;

    while (getenv("BUS_{$i}_VEHICLE_NO") !== false) {
        $buses[] = [
            'id' => $i,
            'vehicleNo' => getenv("BUS_{$i}_VEHICLE_NO"),
            'token' => getenv("BUS_{$i}_TOKEN"),
        ];
        $i++;
    }

    return $buses;
}

function getBusById(int $id): ?array
{
    static $buses = null;
    if ($buses === null) {
        $buses = loadBuses();
    }

    foreach ($buses as $bus) {
        if ($bus['id'] === $id) {
            return $bus;
        }
    }

    return null;
}

function getBusByVehicleNo(string $vehicleNo): ?array
{
    static $buses = null;
    if ($buses === null) {
        $buses = loadBuses();
    }

    foreach ($buses as $bus) {
        if ($bus['vehicleNo'] === $vehicleNo) {
            return $bus;
        }
    }

    return null;
}
