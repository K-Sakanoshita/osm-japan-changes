<?php
declare(strict_types=1);

final class PrefectureLocator
{
    private array $areas = [];

    public function __construct(string $path)
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException('Prefecture GeoJSON could not be read.');
        }
        $geoJson = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        if (($geoJson['type'] ?? null) !== 'FeatureCollection' || !is_array($geoJson['features'] ?? null)) {
            throw new RuntimeException('Prefecture GeoJSON is invalid.');
        }
        foreach ($geoJson['features'] as $feature) {
            $properties = $feature['properties'] ?? [];
            $geometry = $feature['geometry'] ?? [];
            $name = trim((string) ($properties['name:ja'] ?? $properties['name'] ?? ''));
            $type = $geometry['type'] ?? null;
            $coordinates = $geometry['coordinates'] ?? null;
            if ($name === '' || !in_array($type, ['Polygon', 'MultiPolygon'], true) || !is_array($coordinates)) {
                continue;
            }
            $bounds = [INF, INF, -INF, -INF];
            $visit = static function (array $values) use (&$visit, &$bounds): void {
                if (isset($values[0], $values[1]) && is_numeric($values[0]) && is_numeric($values[1])) {
                    $bounds[0] = min($bounds[0], (float) $values[0]);
                    $bounds[1] = min($bounds[1], (float) $values[1]);
                    $bounds[2] = max($bounds[2], (float) $values[0]);
                    $bounds[3] = max($bounds[3], (float) $values[1]);
                    return;
                }
                foreach ($values as $value) {
                    if (is_array($value)) {
                        $visit($value);
                    }
                }
            };
            $visit($coordinates);
            if (is_finite($bounds[0])) {
                $this->areas[] = [$name, $bounds, $type === 'Polygon' ? [$coordinates] : $coordinates];
            }
        }
        if (count($this->areas) !== 47) {
            throw new RuntimeException('Prefecture GeoJSON must contain 47 usable prefectures.');
        }
    }

    private static function inRing(float $lon, float $lat, array $ring): bool
    {
        $inside = false;
        $count = count($ring);
        if ($count < 3) {
            return false;
        }
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if (($yi > $lat) !== ($yj > $lat)
                && $lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi) {
                $inside = !$inside;
            }
        }
        return $inside;
    }

    private static function inPolygon(float $lon, float $lat, array $polygon): bool
    {
        if (!$polygon || !self::inRing($lon, $lat, $polygon[0])) {
            return false;
        }
        foreach (array_slice($polygon, 1) as $hole) {
            if (self::inRing($lon, $lat, $hole)) {
                return false;
            }
        }
        return true;
    }

    public function locate(float $lat, float $lon): ?string
    {
        foreach ($this->areas as [$name, $bounds, $polygons]) {
            [$west, $south, $east, $north] = $bounds;
            if ($lon < $west || $lon > $east || $lat < $south || $lat > $north) {
                continue;
            }
            foreach ($polygons as $polygon) {
                if (self::inPolygon($lon, $lat, $polygon)) {
                    return $name;
                }
            }
        }
        return null;
    }
}
