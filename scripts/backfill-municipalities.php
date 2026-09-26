<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$publicDir = getenv('OSM_PUBLIC_DIR');
$publicDir = $publicDir !== false && $publicDir !== ''
    ? rtrim($publicDir, '/')
    : __DIR__ . '/../public';
if (!is_file($publicDir . '/bootstrap.php') || !is_file($publicDir . '/municipality-lib.php')
    || !is_file($publicDir . '/data/municipalities.min.geojson')) {
    throw new RuntimeException('OSM_PUBLIC_DIR must point to the deployed public directory.');
}
$config = require $publicDir . '/bootstrap.php';
require_once $publicDir . '/municipality-lib.php';
$db = $config['db'];
$pdo = new PDO(
    "mysql:host={$db['host']};dbname={$db['dbname']};charset={$db['charset']}",
    $db['user'],
    $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
ensureMunicipalityColumns($pdo);
$locator = new MunicipalityLocator($publicDir . '/data/municipalities.min.geojson');
$lastType = '';
$lastId = 0;
$updated = 0;
$types = ['node', 'way', 'relation'];
$select = $pdo->prepare('SELECT osm_type, osm_id, latitude, longitude, prefecture FROM osm_poi WHERE osm_type = ? AND osm_id > ? ORDER BY osm_id LIMIT 500');
$update = $pdo->prepare('UPDATE osm_poi SET prefecture=?, municipality_code=?, municipality_name=?, municipality_osm_id=?, ward_code=?, ward_name=?, ward_osm_id=? WHERE osm_type=? AND osm_id=?');
foreach ($types as $type) {
    $lastId = 0;
    do {
        $select->execute([$type, $lastId]);
        $rows = $select->fetchAll();
        foreach ($rows as $row) {
            $location = $locator->locateWithPrefecture((float) $row['latitude'], (float) $row['longitude'], $row['prefecture']);
            $update->execute([$location['prefecture'], ...$location['municipality'], $type, $row['osm_id']]);
            $lastId = (int) $row['osm_id'];
            $updated++;
        }
        if ($rows) {
            fprintf(STDERR, "%s: %d processed\n", $type, $updated);
        }
    } while (count($rows) === 500);
}
echo "Updated {$updated} POIs.\n";
