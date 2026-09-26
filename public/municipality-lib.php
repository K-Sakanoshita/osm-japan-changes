<?php
declare(strict_types=1);

final class MunicipalityLocator
{
    private array $areas = [];

    public function __construct(string $path)
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException('Municipality GeoJSON is missing');
        }
        $data = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        if (($data['type'] ?? null) !== 'FeatureCollection' || !is_array($data['features'] ?? null)) {
            throw new RuntimeException('Invalid municipality GeoJSON');
        }
        foreach ($data['features'] as $feature) {
            $p = $feature['properties'] ?? [];
            $g = $feature['geometry'] ?? [];
            if (!in_array($g['type'] ?? '', ['Polygon', 'MultiPolygon'], true) || count($feature['bbox'] ?? []) !== 4) {
                continue;
            }
            $this->areas[$p['prefecture_name'] ?? ''][] = [$p, $feature['bbox'], $g['type'] === 'Polygon' ? [$g['coordinates']] : $g['coordinates']];
        }
    }

    private static function inRing(float $lon, float $lat, array $ring): bool
    {
        if (count($ring) < 3) {
            return false;
        }
        $inside = false;
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if (($yi > $lat) !== ($yj > $lat) && $lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi) {
                $inside = !$inside;
            }
        }
        return $inside;
    }

    private static function contains(float $lon, float $lat, array $polygons): bool
    {
        foreach ($polygons as $polygon) {
            if (!$polygon || !self::inRing($lon, $lat, $polygon[0])) {
                continue;
            }
            $inHole = false;
            foreach (array_slice($polygon, 1) as $hole) {
                if (self::inRing($lon, $lat, $hole)) {
                    $inHole = true;
                    break;
                }
            }
            if (!$inHole) {
                return true;
            }
        }
        return false;
    }

    public function locate(float $lat, float $lon, ?string $prefecture): array
    {
        return $this->locateWithPrefecture($lat, $lon, $prefecture)['municipality'];
    }

    public function locateWithPrefecture(float $lat, float $lon, ?string $prefecture): array
    {
        $matches = [];
        $usedNationwideFallback = false;
        $groups = $prefecture !== null ? [$this->areas[$prefecture] ?? []] : array_values($this->areas);
        foreach ($groups as $group) {
            foreach ($group as [$p, $bbox, $polygons]) {
                [$west, $south, $east, $north] = $bbox;
                if ($lon >= $west - 0.00001 && $lon <= $east + 0.00001 && $lat >= $south - 0.00001 && $lat <= $north + 0.00001 && self::contains($lon, $lat, $polygons)) {
                    $matches[(int) $p['admin_level']][] = $p;
                }
            }
        }
        if ($prefecture !== null && count($matches[7] ?? []) === 0 && count($matches[8] ?? []) === 0) {
            $usedNationwideFallback = true;
            foreach ($this->areas as $name => $group) {
                if ($name === $prefecture) {
                    continue;
                }
                foreach ($group as [$p, $bbox, $polygons]) {
                    [$west, $south, $east, $north] = $bbox;
                    if ($lon >= $west - 0.00001 && $lon <= $east + 0.00001 && $lat >= $south - 0.00001 && $lat <= $north + 0.00001 && self::contains($lon, $lat, $polygons)) {
                        $matches[(int) $p['admin_level']][] = $p;
                    }
                }
            }
        }
        $municipality = count($matches[7] ?? []) === 1 ? $matches[7][0] : null;
        if (($municipality['name'] ?? '') === '所属未定地') {
            $municipality = null;
        }
        $ward = count($matches[8] ?? []) === 1 ? $matches[8][0] : null;
        if ($municipality === null && $ward !== null && !empty($ward['parent_osm_id'])) {
            $municipality = ['name' => $ward['parent_name'] ?? null, 'osm_id' => $ward['parent_osm_id'], 'local_government_code' => $ward['parent_local_government_code'] ?? null];
        }
        if ($usedNationwideFallback) {
            $matchedCode = $municipality['local_government_code'] ?? $ward['local_government_code'] ?? null;
            $matchedPrefectureCode = $municipality['prefecture_code'] ?? $ward['prefecture_code'] ?? null;
            $matchedPrefectureName = $municipality['prefecture_name'] ?? $ward['prefecture_name'] ?? null;
            if (is_string($matchedCode) && substr($matchedCode, 0, 2) === $matchedPrefectureCode && is_string($matchedPrefectureName)) {
                $prefecture = $matchedPrefectureName;
            }
        }
        return [
            'prefecture' => $prefecture,
            'municipality' => [$municipality['local_government_code'] ?? null, $municipality['name'] ?? null, $municipality['osm_id'] ?? null, $ward['local_government_code'] ?? null, $ward['name'] ?? null, $ward['osm_id'] ?? null],
        ];
    }
}

function ensureMunicipalityColumns(PDO $pdo): void
{
    $columns = ['municipality_code' => 'CHAR(6) NULL', 'municipality_name' => 'VARCHAR(255) NULL', 'municipality_osm_id' => 'BIGINT UNSIGNED NULL', 'ward_code' => 'CHAR(6) NULL', 'ward_name' => 'VARCHAR(255) NULL', 'ward_osm_id' => 'BIGINT UNSIGNED NULL'];
    $existing = array_column($pdo->query('SHOW COLUMNS FROM osm_poi')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    foreach ($columns as $name => $definition) {
        if (!in_array($name, $existing, true)) {
            $pdo->exec("ALTER TABLE osm_poi ADD COLUMN {$name} {$definition}");
        }
    }
    foreach (['municipality_code', 'ward_code'] as $name) {
        if (!$pdo->query("SHOW INDEX FROM osm_poi WHERE Key_name = '{$name}'")->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE osm_poi ADD INDEX {$name} ({$name})");
        }
    }
}
