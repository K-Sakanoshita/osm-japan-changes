<?php
declare(strict_types=1);

return [
    'db' => [
        'host' => '127.0.0.1',
        'dbname' => 'osm_japan_changes',
        'user' => 'osm',
        'pass' => 'replace-this-password',
        'charset' => 'utf8mb4',
    ],
    'osm_api' => 'https://api.openstreetmap.org/api/0.6',
    'osm_user_agent' => 'osm-japan-changes/1.0 (contact@example.com)',
    'osm_full_max_bytes' => 2 * 1024 * 1024,
    'profile_avatar_refresh_limit' => 200,
    // Japan and its remote islands; overlap with Korea and Taiwan is intentional.
    'bbox' => '122.0,20.0,154.0,46.0',
    // The API is read-only, so all origins are allowed by default. Replace '*'
    // with exact frontend origins when deployment policy requires a restriction.
    'cors' => [
        'allowed_origins' => [
            '*',
            // 'https://example.github.io',
        ],
    ],
    'admin' => [
        'username' => 'admin',
        'password_hash' => 'replace-with-password_hash-output',
    ],
];
