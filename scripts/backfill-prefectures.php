<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$dryRun = in_array('--dry-run', array_slice($argv, 1), true);
if (count(array_diff(array_slice($argv, 1), ['--dry-run'])) > 0) {
    fwrite(STDERR, "Usage: php scripts/backfill-prefectures.php [--dry-run]\n");
    exit(2);
}

$publicDir = getenv('OSM_PUBLIC_DIR');
$publicDir = $publicDir !== false && $publicDir !== ''
    ? rtrim($publicDir, '/')
    : __DIR__ . '/../public';
if (!is_file($publicDir . '/bootstrap.php') || !is_file($publicDir . '/prefecture-lib.php')
    || !is_file($publicDir . '/data/prefectures.min.geojson')) {
    throw new RuntimeException('OSM_PUBLIC_DIR must point to the deployed public directory.');
}
$config = require $publicDir . '/bootstrap.php';
require_once $publicDir . '/prefecture-lib.php';
$db = $config['db'];
$pdo = new PDO(
    "mysql:host={$db['host']};dbname={$db['dbname']};charset={$db['charset']}",
    $db['user'],
    $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$locator = new PrefectureLocator($publicDir . '/data/prefectures.min.geojson');
$select = $pdo->prepare(
    "SELECT osm_type, osm_id, latitude, longitude
       FROM osm_poi
      WHERE osm_type = ? AND osm_id > ? AND (prefecture IS NULL OR prefecture = '')
      ORDER BY osm_id LIMIT 500"
);
$update = $pdo->prepare(
    "UPDATE osm_poi SET prefecture = ?
      WHERE osm_type = ? AND osm_id = ? AND (prefecture IS NULL OR prefecture = '')"
);
$checked = 0;
$matched = 0;
$updated = 0;
$unresolved = 0;
foreach (['node', 'way', 'relation'] as $type) {
    $lastId = 0;
    do {
        $select->execute([$type, $lastId]);
        $rows = $select->fetchAll();
        foreach ($rows as $row) {
            $lastId = (int) $row['osm_id'];
            $checked++;
            $prefecture = $locator->locate((float) $row['latitude'], (float) $row['longitude']);
            if ($prefecture === null) {
                $unresolved++;
                continue;
            }
            $matched++;
            if (!$dryRun) {
                $update->execute([$prefecture, $type, $row['osm_id']]);
                $updated += $update->rowCount();
            }
        }
    } while (count($rows) === 500);
}
printf(
    "%s: checked=%d matched=%d updated=%d unresolved=%d\n",
    $dryRun ? 'dry-run' : 'backfill',
    $checked,
    $matched,
    $updated,
    $unresolved
);
