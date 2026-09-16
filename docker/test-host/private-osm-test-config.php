<?php
$databasePort = getenv('OSM_TEST_DB_PORT') ?: '3307';

return [
    'db' => [
        'host' => "127.0.0.1;port={$databasePort}",
        'dbname' => 'osm_whatnew',
        'user' => 'osm',
        'pass' => 'osm-local-test',
        'charset' => 'utf8mb4',
    ],
    'osm_api' => 'https://api.openstreetmap.org/api/0.6',
    'osm_user_agent' => 'osm-japan-changes/1.0 local-test',
    'osm_full_max_bytes' => 2 * 1024 * 1024,
    'bbox' => '122.0,20.0,154.0,46.0',
    'cors' => [
        'allowed_origins' => ['*'],
    ],
    'admin' => [
        'username' => 'admin',
        'password_hash' => '$2y$12$WaaWcTCVHbXchIzQyE074OmEKdRUOBVF96.cA.C.HhOtXAIGLOTkG',
    ],
];
