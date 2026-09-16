<?php
declare(strict_types=1);

// Set OSM_APP_CONFIG to an absolute path outside the web document root.
$configPath = getenv('OSM_APP_CONFIG') ?: dirname(__DIR__) . '/private-osm-config.php';
if (!is_file($configPath)) {
    throw new RuntimeException('Server configuration is missing.');
}
return require $configPath;
