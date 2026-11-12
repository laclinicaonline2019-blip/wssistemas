<?php

namespace App\Support;

class Geo
{
    /** Distância em km (fórmula de Haversine). */
    public static function distanceKm(?array $a, ?array $b): ?float
    {
        if (! $a || ! $b || $a[0] === null || $b[0] === null) {
            return null;
        }
        [$lat1, $lng1] = array_map('deg2rad', $a);
        [$lat2, $lng2] = array_map('deg2rad', $b);
        $h = sin(($lat2 - $lat1) / 2) ** 2 + cos($lat1) * cos($lat2) * sin(($lng2 - $lng1) / 2) ** 2;

        return round(2 * 6371 * asin(min(1, sqrt($h))), 2);
    }
}
