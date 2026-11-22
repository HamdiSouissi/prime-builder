<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

/** Generates OpenStreetMap URLs exclusively from validated coordinates. */
final class Map
{
    public static function coordinate(mixed $value, bool $latitude): ?float
    {
        if (!is_string($value) || preg_match('/^-?[0-9]{1,3}(?:\.[0-9]{1,6})?$/D', trim($value)) !== 1) {
            return null;
        }
        $number = (float) trim($value);
        return abs($number) <= ($latitude ? 85.0 : 180.0) ? $number : null;
    }

    /** @return array{frame: string, link: string}|null */
    public static function urls(mixed $latitude, mixed $longitude, mixed $zoom): ?array
    {
        $lat = self::coordinate($latitude, true);
        $lon = self::coordinate($longitude, false);
        if ($lat === null || $lon === null) {
            return null;
        }
        $level = is_int($zoom) ? max(3, min(18, $zoom)) : 14;
        $lonDelta = 360.0 / (2 ** $level);
        $latDelta = $lonDelta * 0.6 * max(0.087, cos(deg2rad($lat)));
        $format = static fn (float $value): string => number_format($value, 6, '.', '');
        $bbox = implode(',', [
            $format(max(-180.0, $lon - $lonDelta)),
            $format(max(-85.051128, $lat - $latDelta)),
            $format(min(180.0, $lon + $lonDelta)),
            $format(min(85.051128, $lat + $latDelta)),
        ]);
        $marker = $format($lat) . ',' . $format($lon);
        $query = http_build_query(['bbox' => $bbox, 'layer' => 'mapnik', 'marker' => $marker], '', '&', PHP_QUERY_RFC3986);
        $linkQuery = http_build_query(['mlat' => $format($lat), 'mlon' => $format($lon)], '', '&', PHP_QUERY_RFC3986);

        return [
            'frame' => 'https://www.openstreetmap.org/export/embed.html?' . $query,
            'link' => 'https://www.openstreetmap.org/?' . $linkQuery . '#map=' . $level . '/' . $format($lat) . '/' . $format($lon),
        ];
    }
}
