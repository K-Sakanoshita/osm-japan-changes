<?php
declare(strict_types=1);

// Production publishes the contents of public/ as ~/www/osm-japan-changes,
// while the private configuration stays at ~/private-osm-config.php. Keep the
// repository-root location as a fallback for local development.
$configuredPath = getenv('OSM_APP_CONFIG');
$configCandidates = $configuredPath !== false && $configuredPath !== ''
    ? [$configuredPath]
    : [
        dirname(__DIR__, 2) . '/private-osm-config.php',
        dirname(__DIR__) . '/private-osm-config.php',
    ];
$configPath = null;
foreach ($configCandidates as $configCandidate) {
    if (is_file($configCandidate)) {
        $configPath = $configCandidate;
        break;
    }
}
if ($configPath === null) {
    throw new RuntimeException('Server configuration is missing.');
}

$config = require $configPath;
if (!is_array($config)) {
    throw new RuntimeException('Server configuration must return an array.');
}

$requiredStringSettings = [
    'db.host',
    'db.dbname',
    'db.user',
    'db.pass',
    'db.charset',
    'osm_api',
    'osm_user_agent',
    'admin.username',
    'admin.password_hash',
    'bbox',
];
foreach ($requiredStringSettings as $settingPath) {
    $value = $config;
    foreach (explode('.', $settingPath) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            throw new RuntimeException("Server configuration is missing {$settingPath}.");
        }
        $value = $value[$key];
    }
    if (!is_string($value) || ($value === '' && $settingPath !== 'db.pass')) {
        throw new RuntimeException("Server configuration {$settingPath} must be a string.");
    }
}

if (!preg_match(
    '/^-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?$/D',
    $config['bbox']
)) {
    throw new RuntimeException('Server configuration bbox must contain four comma-separated numbers.');
}

if (!is_int($config['osm_full_max_bytes'] ?? null)
    || $config['osm_full_max_bytes'] < 262144) {
    throw new RuntimeException(
        'Server configuration osm_full_max_bytes must be an integer of at least 262144.'
    );
}
if (!is_int($config['profile_avatar_refresh_limit'] ?? null)
    || $config['profile_avatar_refresh_limit'] < 0
    || $config['profile_avatar_refresh_limit'] > 1000) {
    throw new RuntimeException(
        'Server configuration profile_avatar_refresh_limit must be an integer from 0 to 1000.'
    );
}
$allowedOrigins = $config['cors']['allowed_origins'] ?? null;
if (!is_array($allowedOrigins) || $allowedOrigins === []) {
    throw new RuntimeException(
        'Server configuration cors.allowed_origins must be a non-empty array.'
    );
}
foreach ($allowedOrigins as $allowedOrigin) {
    if (!is_string($allowedOrigin) || $allowedOrigin === '') {
        throw new RuntimeException(
            'Server configuration cors.allowed_origins must contain non-empty strings.'
        );
    }
}

return $config;
