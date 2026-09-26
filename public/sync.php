<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("sync.php is available from cron/CLI only.\n");
}

set_time_limit(240);
ini_set('default_socket_timeout', '30');

// Prevent the next cron invocation from starting while this one is active.
$lockPath = sys_get_temp_dir() . '/whatsnew-sync-' . sha1(__DIR__) . '.lock';
$lockHandle = fopen($lockPath, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    exit("sync skipped: another process is still running.\n");
}

$config = require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/municipality-lib.php';
require_once __DIR__ . '/prefecture-lib.php';
$municipalityLocator = new MunicipalityLocator(__DIR__ . '/data/municipalities.min.geojson');
$osmUserAgent = trim((string) ($config['osm_user_agent'] ?? 'osm-japan-changes/1.0'));
$fullResponseMaxBytes = max(262144, (int) ($config['osm_full_max_bytes'] ?? 2 * 1024 * 1024));
$httpContext = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Accept: application/xml\r\nUser-Agent: {$osmUserAgent}\r\n",
        'timeout' => 30,
        'ignore_errors' => true,
    ],
]);

$fetchXml = static function (
    string $url,
    ?int $maxBytes = null,
    bool $allowMissing = false,
    ?bool &$tooLarge = null
) use ($httpContext): ?SimpleXMLElement {
    $tooLarge = false;
    $limit = $maxBytes === null ? null : $maxBytes + 1;
    $source = $limit === null
        ? file_get_contents($url, false, $httpContext)
        : file_get_contents($url, false, $httpContext, 0, $limit);
    $responseHeaders = $http_response_header ?? [];
    $statusLine = '';
    foreach ($responseHeaders as $responseHeader) {
        if (str_starts_with($responseHeader, 'HTTP/')) {
            $statusLine = $responseHeader;
        }
    }
    if ($allowMissing && preg_match('/\s(?:404|410)\s/', $statusLine)) {
        return null;
    }
    if ($source === false || !preg_match('/\s2\d\d\s/', $statusLine)) {
        throw new RuntimeException("OSM API request failed: {$statusLine} {$url}");
    }
    if ($maxBytes !== null && strlen($source) > $maxBytes) {
        $tooLarge = true;
        return null;
    }
    try {
        return new SimpleXMLElement($source);
    } catch (Exception $exception) {
        throw new RuntimeException("Invalid XML returned by OSM API: {$url}", 0, $exception);
    }
};

$readTags = static function (SimpleXMLElement $element): array {
    $tags = [];
    foreach ($element->tag as $tag) {
        $tags[(string) $tag['k']] = (string) $tag['v'];
    }
    return $tags;
};
$targetAreaKeys = ['amenity', 'shop', 'tourism', 'leisure', 'playground'];
$findTargetAreaCategory = static function (array $tags) use ($targetAreaKeys): ?string {
    foreach ($targetAreaKeys as $key) {
        if (array_key_exists($key, $tags)) {
            return $key;
        }
    }
    return ($tags['landuse'] ?? null) === 'recreation_ground' ? 'landuse' : null;
};
$nodeLocationsFromXml = static function (SimpleXMLElement $xml): array {
    $locations = [];
    foreach ($xml->xpath('//node') ?: [] as $node) {
        if (isset($node['lat'], $node['lon'])) {
            $locations[(string) $node['id']] = [(float) $node['lon'], (float) $node['lat']];
        }
    }
    return $locations;
};
$waysFromXml = static function (SimpleXMLElement $xml): array {
    $ways = [];
    foreach ($xml->xpath('//way') ?: [] as $way) {
        $ways[(string) $way['id']] = $way;
    }
    return $ways;
};
$pointFromCoordinates = static function (array $coordinates): ?array {
    if (!$coordinates) {
        return null;
    }
    $lons = array_column($coordinates, 0);
    $lats = array_column($coordinates, 1);
    return [(min($lons) + max($lons)) / 2, (min($lats) + max($lats)) / 2];
};
$pointForWay = static function (SimpleXMLElement $way, array $nodeLocations) use ($pointFromCoordinates): ?array {
    $coordinates = [];
    foreach ($way->nd as $nodeReference) {
        $reference = (string) $nodeReference['ref'];
        if (!isset($nodeLocations[$reference])) {
            return null;
        }
        $coordinates[] = $nodeLocations[$reference];
    }
    return $pointFromCoordinates($coordinates);
};
$pointForRelation = static function (SimpleXMLElement $relation, array $waysById, array $nodeLocations) use ($pointForWay, $pointFromCoordinates): ?array {
    $coordinates = [];
    foreach ($relation->member as $member) {
        $memberType = (string) $member['type'];
        $reference = (string) $member['ref'];
        if ($memberType === 'relation') {
            return null;
        }
        if ($memberType === 'node') {
            if (!isset($nodeLocations[$reference])) {
                return null;
            }
            $coordinates[] = $nodeLocations[$reference];
            continue;
        }
        if ($memberType !== 'way' || !isset($waysById[$reference])) {
            return null;
        }
        $memberPoint = $pointForWay($waysById[$reference], $nodeLocations);
        if ($memberPoint === null) {
            return null;
        }
        foreach ($waysById[$reference]->nd as $nodeReference) {
            $coordinates[] = $nodeLocations[(string) $nodeReference['ref']];
        }
    }
    return $pointFromCoordinates($coordinates);
};
$db = $config['db'];
$pdo = new PDO(
    "mysql:host={$db['host']};dbname={$db['dbname']};charset={$db['charset']}",
    $db['user'],
    $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// The changeset API accepts a rectangular bbox, so the Japan-wide bbox also
// overlaps nearby countries. Reject those land areas before saving while
// preserving Japan's western islands, Hokkaido and the remote Pacific islands.
$isExcludedForeignArea = static function (float $lat, float $lon): bool {
    $isKoreanMainland = $lat >= 34.7 && $lat <= 43.1 && $lon >= 124.0 && $lon <= 130.8;
    $isKoreanSouthernIslands = $lat >= 32.8 && $lat < 34.7 && $lon >= 124.5 && $lon < 128.3;
    $isChinaEastCoast = $lat >= 25.0 && $lat < 39.0 && $lon < 123.0;
    $isChinaOrRussianMainland = $lat >= 39.0 && $lon < 139.2;
    $isRussianNorth = $lat > 45.6;
    $isRussianKurilsNorth = $lat >= 44.5 && $lon >= 146.0;
    $isRussianKurilsSouth = $lat >= 43.5 && $lon >= 146.5;

    return $isKoreanMainland
        || $isKoreanSouthernIslands
        || $isChinaEastCoast
        || $isChinaOrRussianMainland
        || $isRussianNorth
        || $isRussianKurilsNorth
        || $isRussianKurilsSouth;
};

$prefectureLocator = new PrefectureLocator(__DIR__ . '/data/prefectures.min.geojson');
$findPrefecture = static fn(float $lat, float $lon): ?string => $prefectureLocator->locate($lat, $lon);
// Older installations limited category to ENUM('amenity','shop').  Any OSM
// tag key can now be used as the representative category, so widen the two
// display columns once when this version is first run.
$categoryColumn = $pdo->query("SHOW COLUMNS FROM osm_poi LIKE 'category'")->fetch(PDO::FETCH_ASSOC);
if ($categoryColumn && str_starts_with(strtolower((string) $categoryColumn['Type']), 'enum(')) {
    $pdo->exec('ALTER TABLE osm_poi MODIFY category VARCHAR(255) NOT NULL, MODIFY category_value VARCHAR(255) NULL');
}
$prefectureColumn = $pdo->query("SHOW COLUMNS FROM osm_poi LIKE 'prefecture'")->fetch(PDO::FETCH_ASSOC);
if (!$prefectureColumn) {
    $pdo->exec('ALTER TABLE osm_poi ADD prefecture VARCHAR(64) NULL AFTER longitude, ADD KEY prefecture (prefecture)');
}

ensureMunicipalityColumns($pdo);

// Remove foreign rows stored by older versions once. The state flag avoids a
// full-table DELETE check on every six-minute cron invocation.
$foreignFilterVersion = '2026-07-14-1';
$savedForeignFilterVersion = $pdo->query("SELECT state_value FROM osm_sync_state WHERE state_key='foreign_filter_version'")->fetchColumn();
$foreignRemoved = 0;
if ($savedForeignFilterVersion !== $foreignFilterVersion) {
    $foreignRemoved = $pdo->exec(
        'DELETE FROM osm_poi WHERE '
        . '(latitude >= 34.7 AND latitude <= 43.1 AND longitude >= 124.0 AND longitude <= 130.8) OR '
        . '(latitude >= 32.8 AND latitude < 34.7 AND longitude >= 124.5 AND longitude < 128.3) OR '
        . '(latitude >= 25.0 AND latitude < 39.0 AND longitude < 123.0) OR '
        . '(latitude >= 39.0 AND longitude < 139.2) OR '
        . '(latitude > 45.6) OR '
        . '(latitude >= 44.5 AND longitude >= 146.0) OR '
        . '(latitude >= 43.5 AND longitude >= 146.5)'
    );
    $pdo->prepare("INSERT INTO osm_sync_state (state_key,state_value) VALUES ('foreign_filter_version',?) ON DUPLICATE KEY UPDATE state_value=VALUES(state_value)")
        ->execute([$foreignFilterVersion]);
}

$cursorValue = $pdo->query("SELECT state_value FROM osm_sync_state WHERE state_key='sync_cursor_at'")->fetchColumn();
if (!$cursorValue) {
    // Migrate the cursor written by the previous version under a misleading key.
    $cursorValue = $pdo->query("SELECT state_value FROM osm_sync_state WHERE state_key='last_sync_at'")->fetchColumn();
    if ($cursorValue) {
        $pdo->prepare("INSERT INTO osm_sync_state (state_key,state_value) VALUES ('sync_cursor_at',?) ON DUPLICATE KEY UPDATE state_value=VALUES(state_value)")->execute([$cursorValue]);
        $pdo->exec("DELETE FROM osm_sync_state WHERE state_key='last_sync_at'");
    }
}
$upsert = $pdo->prepare('INSERT INTO osm_poi (osm_type,osm_id,name,category,category_value,latitude,longitude,prefecture,municipality_code,municipality_name,municipality_osm_id,ward_code,ward_name,ward_osm_id,tags,osm_timestamp,changeset_id,editor_uid,editor_name,change_action,created_osm_at,creator_uid,creator_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),category=VALUES(category),category_value=VALUES(category_value),latitude=VALUES(latitude),longitude=VALUES(longitude),prefecture=VALUES(prefecture),municipality_code=VALUES(municipality_code),municipality_name=VALUES(municipality_name),municipality_osm_id=VALUES(municipality_osm_id),ward_code=VALUES(ward_code),ward_name=VALUES(ward_name),ward_osm_id=VALUES(ward_osm_id),tags=VALUES(tags),osm_timestamp=VALUES(osm_timestamp),changeset_id=VALUES(changeset_id),editor_uid=VALUES(editor_uid),editor_name=VALUES(editor_name),change_action=VALUES(change_action),created_osm_at=COALESCE(created_osm_at,VALUES(created_osm_at)),creator_uid=COALESCE(creator_uid,VALUES(creator_uid)),creator_name=COALESCE(creator_name,VALUES(creator_name))');
$deletePoi = $pdo->prepare('DELETE FROM osm_poi WHERE osm_type=? AND osm_id=?');
$saveCursor = $pdo->prepare("INSERT INTO osm_sync_state (state_key,state_value) VALUES ('sync_cursor_at',?) ON DUPLICATE KEY UPDATE state_value=VALUES(state_value)");
$saveCompleted = $pdo->prepare("INSERT INTO osm_sync_state (state_key,state_value) VALUES ('last_sync_at',?) ON DUPLICATE KEY UPDATE state_value=VALUES(state_value)");

// Use only the main OSM API. The saved cursor lets cron resume the backfill.
$cursor = $cursorValue ? strtotime($cursorValue) : time() - 14 * 24 * 60 * 60;
$target = time();
$startedAt = microtime(true);
$minimumWindows = 3;
$normalTimeLimit = 180;
$hardTimeLimit = 230;
$windows = 0;
$changesets = 0;
$saved = 0;
$savedByType = ['node' => 0, 'way' => 0, 'relation' => 0];
$removed = 0;
$fullRequests = 0;
$excludedLarge = 0;
$excludedNestedRelations = 0;
$unresolvedGeometry = 0;
$bbox = array_map('floatval', explode(',', $config['bbox']));
[$minLon, $minLat, $maxLon, $maxLat] = $bbox;
$categoryPriority = [
    'amenity', 'shop', 'tourism', 'historic', 'leisure', 'playground', 'golf', 'office',
    'craft', 'man_made', 'public_transport', 'healthcare',
    'emergency', 'sport', 'highway', 'traffic_calming', 'railway', 'aeroway', 'place',
    'natural', 'landuse', 'building', 'power', 'waterway', 'barrier',
    'route', 'entrance', 'name',
];

$removePoi = static function (string $type, int $osmId) use ($deletePoi, &$removed): void {
    $deletePoi->execute([$type, $osmId]);
    $removed += $deletePoi->rowCount();
};
$savePoi = static function (
    string $type,
    SimpleXMLElement $element,
    array $tags,
    string $category,
    array $point,
    SimpleXMLElement $changeset,
    string $changeAction
) use ($upsert, $findPrefecture, $municipalityLocator, &$saved, &$savedByType): void {
    [$lon, $lat] = $point;
    $timestampSource = (string) $element['timestamp'];
    if ($timestampSource === '') {
        $timestampSource = (string) ($changeset['closed_at'] ?? 'now');
    }
    $timestamp = (new DateTimeImmutable($timestampSource))->format('Y-m-d H:i:s');
    $editorUid = isset($element['uid'])
        ? (int) $element['uid']
        : (isset($changeset['uid']) ? (int) $changeset['uid'] : null);
    $editorName = (string) ($element['user'] ?? $changeset['user'] ?? '');
    $prefecture = $findPrefecture($lat, $lon);
    $municipality = $municipalityLocator->locate($lat, $lon, $prefecture);
    $upsert->execute([
        $type,
        (int) $element['id'],
        $tags['name'] ?? $tags['name:ja'] ?? null,
        $category,
        mb_substr($tags[$category], 0, 255),
        $lat,
        $lon,
        $prefecture,
        ...$municipality,
        json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        $timestamp,
        (int) $changeset['id'],
        $editorUid,
        $editorName !== '' ? $editorName : null,
        $changeAction,
        $changeAction === 'create' ? $timestamp : null,
        $changeAction === 'create' ? $editorUid : null,
        $changeAction === 'create' && $editorName !== '' ? $editorName : null,
    ]);
    $saved++;
    $savedByType[$type]++;
};
$isInsideTarget = static function (array $point) use (
    $minLon,
    $minLat,
    $maxLon,
    $maxLat,
    $isExcludedForeignArea
): bool {
    [$lon, $lat] = $point;
    return $lon >= $minLon
        && $lon <= $maxLon
        && $lat >= $minLat
        && $lat <= $maxLat
        && !$isExcludedForeignArea($lat, $lon);
};
$findElementById = static function (SimpleXMLElement $xml, string $type, int $osmId): ?SimpleXMLElement {
    foreach ($xml->{$type} as $element) {
        if ((int) $element['id'] === $osmId) {
            return $element;
        }
    }
    return null;
};

while (
    $cursor < $target
    && microtime(true) - $startedAt < $hardTimeLimit
    && ($windows < $minimumWindows || microtime(true) - $startedAt < $normalTimeLimit)
) {
    $windowEnd = min($cursor + 15 * 60, $target);
    $from = gmdate('c', $cursor);
    $to = gmdate('c', $windowEnd);
    $url = $config['osm_api'] . '/changesets?' . http_build_query([
        'bbox' => $config['bbox'],
        'time' => "$from,$to",
        'closed' => 'true',
        'limit' => 100,
    ]);
    $xml = $fetchXml($url);
    if ($xml === null) {
        throw new RuntimeException('Unexpected empty changeset response.');
    }

    foreach ($xml->changeset as $changeset) {
        $changesetId = (int) $changeset['id'];
        $diff = $fetchXml("{$config['osm_api']}/changeset/{$changesetId}/download");
        if ($diff === null) {
            throw new RuntimeException("Unexpected empty changeset download: {$changesetId}");
        }
        $diffNodeLocations = $nodeLocationsFromXml($diff);
        $diffWays = $waysFromXml($diff);

        $createdObjects = ['node' => [], 'way' => [], 'relation' => []];
        foreach (array_keys($createdObjects) as $type) {
            foreach ($diff->xpath("//create/{$type}") ?: [] as $createdObject) {
                $createdObjects[$type][(string) $createdObject['id']] = true;
            }
            foreach ($diff->xpath("//delete/{$type}") ?: [] as $deletedObject) {
                $removePoi($type, (int) $deletedObject['id']);
            }
        }

        foreach ($diff->xpath('//create/node | //modify/node') ?: [] as $node) {
            $tags = $readTags($node);
            $nodeId = (int) $node['id'];
            if (!$tags || !isset($node['lat'], $node['lon'])) {
                $removePoi('node', $nodeId);
                continue;
            }

            // Nodes retain the original behavior: every tagged node is saved.
            $category = null;
            foreach ($categoryPriority as $candidate) {
                if (array_key_exists($candidate, $tags)) {
                    $category = $candidate;
                    break;
                }
            }
            $category ??= (string) array_key_first($tags);
            $point = [(float) $node['lon'], (float) $node['lat']];
            if (!$isInsideTarget($point)) {
                $removePoi('node', $nodeId);
                continue;
            }
            $action = isset($createdObjects['node'][(string) $nodeId]) ? 'create' : 'modify';
            $savePoi('node', $node, $tags, $category, $point, $changeset, $action);
        }

        foreach ($diff->xpath('//create/way | //modify/way') ?: [] as $way) {
            $wayId = (int) $way['id'];
            $tags = $readTags($way);
            $category = $findTargetAreaCategory($tags);
            if ($category === null) {
                $removePoi('way', $wayId);
                continue;
            }

            $point = $pointForWay($way, $diffNodeLocations);
            if ($point === null) {
                $fullRequests++;
                $tooLarge = false;
                $full = $fetchXml(
                    "{$config['osm_api']}/way/{$wayId}/full",
                    $fullResponseMaxBytes,
                    true,
                    $tooLarge
                );
                usleep(100000);
                if ($full === null) {
                    $removePoi('way', $wayId);
                    if ($tooLarge) {
                        $excludedLarge++;
                    } else {
                        $unresolvedGeometry++;
                    }
                    continue;
                }
                $fullWay = $findElementById($full, 'way', $wayId);
                $point = $fullWay === null ? null : $pointForWay($fullWay, $nodeLocationsFromXml($full));
            }
            if ($point === null) {
                $removePoi('way', $wayId);
                $unresolvedGeometry++;
                continue;
            }
            if (!$isInsideTarget($point)) {
                $removePoi('way', $wayId);
                continue;
            }

            $action = isset($createdObjects['way'][(string) $wayId]) ? 'create' : 'modify';
            $savePoi('way', $way, $tags, $category, $point, $changeset, $action);
        }

        foreach ($diff->xpath('//create/relation | //modify/relation') ?: [] as $relation) {
            $relationId = (int) $relation['id'];
            $tags = $readTags($relation);
            $category = $findTargetAreaCategory($tags);
            if ($category === null || (($tags['type'] ?? '') !== 'multipolygon'
                && !array_key_exists('playground', $tags)
                && ($tags['landuse'] ?? null) !== 'recreation_ground')) {
                $removePoi('relation', $relationId);
                continue;
            }

            $hasRelationMember = false;
            foreach ($relation->member as $member) {
                if ((string) $member['type'] === 'relation') {
                    $hasRelationMember = true;
                    break;
                }
            }
            if ($hasRelationMember) {
                $removePoi('relation', $relationId);
                $excludedNestedRelations++;
                continue;
            }

            $point = $pointForRelation($relation, $diffWays, $diffNodeLocations);
            if ($point === null) {
                $fullRequests++;
                $tooLarge = false;
                $full = $fetchXml(
                    "{$config['osm_api']}/relation/{$relationId}/full",
                    $fullResponseMaxBytes,
                    true,
                    $tooLarge
                );
                usleep(100000);
                if ($full === null) {
                    $removePoi('relation', $relationId);
                    if ($tooLarge) {
                        $excludedLarge++;
                    } else {
                        $unresolvedGeometry++;
                    }
                    continue;
                }
                $fullRelation = $findElementById($full, 'relation', $relationId);
                if ($fullRelation !== null) {
                    foreach ($fullRelation->member as $member) {
                        if ((string) $member['type'] === 'relation') {
                            $removePoi('relation', $relationId);
                            $excludedNestedRelations++;
                            continue 2;
                        }
                    }
                }
                $point = $fullRelation === null
                    ? null
                    : $pointForRelation($fullRelation, $waysFromXml($full), $nodeLocationsFromXml($full));
            }
            if ($point === null) {
                $removePoi('relation', $relationId);
                $unresolvedGeometry++;
                continue;
            }
            if (!$isInsideTarget($point)) {
                $removePoi('relation', $relationId);
                continue;
            }

            $action = isset($createdObjects['relation'][(string) $relationId]) ? 'create' : 'modify';
            $savePoi('relation', $relation, $tags, $category, $point, $changeset, $action);
        }

        $changesets++;
        usleep(100000);
    }

    $cursor = $windowEnd;
    $saveCursor->execute([gmdate('c', $cursor)]);
    $windows++;
}

$status = $cursor >= $target ? 'complete' : 'partial';
if ($status === 'complete') {
    $saveCompleted->execute([gmdate('c', $cursor)]);
}
echo "sync {$status}: windows={$windows} changesets={$changesets}"
    . " saved={$saved} nodes={$savedByType['node']} ways={$savedByType['way']} relations={$savedByType['relation']}"
    . " removed={$removed} full_requests={$fullRequests} excluded_large={$excludedLarge}"
    . " excluded_nested_relations={$excludedNestedRelations} unresolved_geometry={$unresolvedGeometry}"
    . " foreign_removed={$foreignRemoved} cursor=" . gmdate('c', $cursor) . "\n";
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
