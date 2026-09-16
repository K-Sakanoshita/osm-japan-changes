<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('OSM_PROFILE_SYNC_ADMIN')) {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/profile-lib.php';
$config = require __DIR__ . '/bootstrap.php';
$db = $config['db'];
$pdo = new PDO(
    "mysql:host={$db['host']};dbname={$db['dbname']};charset={$db['charset']}",
    $db['user'],
    $db['pass'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
if ((int) $pdo->query("SELECT GET_LOCK('osm_profile_sync', 0)")->fetchColumn() !== 1) {
    throw new RuntimeException('Profile aggregation is already running.');
}

$utc = new DateTimeZone('UTC');
$jst = new DateTimeZone('Asia/Tokyo');
$nowUtc = new DateTimeImmutable('now', $utc);
$nowJst = $nowUtc->setTimezone($jst);
$periodStart = $nowUtc->modify('-365 days')->format('Y-m-d H:i:s');
$periodEnd = $nowUtc->format('Y-m-d H:i:s');
$currentMonth = $nowJst->modify('first day of this month')->setTime(0, 0);
$monthStartUtc = $currentMonth->modify('-12 months')->setTimezone($utc)->format('Y-m-d H:i:s');
$calculatedAt = $periodEnd;

$fetchAll = static function (PDO $pdo, string $sql, array $parameters = []): array {
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll();
};

$baseRows = $fetchAll($pdo,
    "SELECT editor_uid,
            MAX(COALESCE(NULLIF(editor_name,''),'不明')) AS editor_name,
            COUNT(*) AS total_count,
            SUM(change_action = 'create') AS create_count,
            SUM(change_action = 'modify') AS modify_count,
            COUNT(DISTINCT changeset_id) AS changeset_count,
            COUNT(DISTINCT DATE(DATE_ADD(osm_timestamp, INTERVAL 9 HOUR))) AS active_day_count,
            COUNT(DISTINCT NULLIF(prefecture,'')) AS prefecture_count,
            COUNT(DISTINCT category) AS category_count,
            MIN(osm_timestamp) AS first_activity_at,
            MAX(osm_timestamp) AS last_activity_at
       FROM osm_poi
      WHERE editor_uid IS NOT NULL
        AND osm_timestamp >= :period_start AND osm_timestamp < :period_end
      GROUP BY editor_uid",
    ['period_start' => $periodStart, 'period_end' => $periodEnd]
);

$avatarCacheRows = $fetchAll($pdo, 'SELECT editor_uid, checked_at FROM mapper_profile_avatars');
$avatarCheckedAt = [];
foreach ($avatarCacheRows as $row) {
    $avatarCheckedAt[(string) $row['editor_uid']] = (string) $row['checked_at'];
}
$avatarRefreshBefore = $nowUtc->modify('-30 days')->format('Y-m-d H:i:s');
$avatarCandidates = [];
foreach ($baseRows as $row) {
    $uid = (string) $row['editor_uid'];
    // Reserved high UIDs belong to the local synthetic data set.
    if ((int) $uid >= 6000000000000000) continue;
    if (!isset($avatarCheckedAt[$uid]) || $avatarCheckedAt[$uid] < $avatarRefreshBefore) {
        $avatarCandidates[] = [
            'uid' => $uid,
            'checkedAt' => $avatarCheckedAt[$uid] ?? '',
        ];
    }
}
usort($avatarCandidates, static function (array $left, array $right): int {
    $checkedOrder = strcmp($left['checkedAt'], $right['checkedAt']);
    return $checkedOrder !== 0 ? $checkedOrder : strnatcmp($left['uid'], $right['uid']);
});
$avatarRefreshLimit = max(0, min(1000, (int) ($config['profile_avatar_refresh_limit'] ?? 200)));
$avatarUids = array_column(array_slice($avatarCandidates, 0, $avatarRefreshLimit), 'uid');

$avatarUpdates = [];
$osmApi = rtrim((string) ($config['osm_api'] ?? 'https://api.openstreetmap.org/api/0.6'), '/');
$osmUserAgent = trim((string) ($config['osm_user_agent'] ?? 'osm-japan-changes/1.0'));
$avatarHttpContext = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Accept: application/json\r\nUser-Agent: {$osmUserAgent}\r\n",
        'timeout' => 15,
        'ignore_errors' => true,
    ],
]);
foreach (array_chunk($avatarUids, 100) as $uidChunk) {
    $url = $osmApi . '/users.json?users=' . rawurlencode(implode(',', $uidChunk));
    $source = @file_get_contents($url, false, $avatarHttpContext);
    $responseHeaders = $http_response_header ?? [];
    $statusLine = implode(' ', array_filter(
        $responseHeaders,
        static fn(string $header): bool => str_starts_with($header, 'HTTP/')
    ));
    $payload = $source === false ? null : json_decode($source, true);
    if (!is_array($payload) || !preg_match('/\s2\d\d\s/', $statusLine)) {
        fwrite(STDERR, "Avatar refresh skipped: OSM user API request failed.\n");
        continue;
    }
    $urlsByUid = [];
    foreach (($payload['users'] ?? []) as $entry) {
        $user = $entry['user'] ?? [];
        $uid = trim((string) ($user['id'] ?? ''));
        $avatarUrl = trim((string) ($user['img']['href'] ?? ''));
        if ($uid === '' || ($avatarUrl !== '' && filter_var($avatarUrl, FILTER_VALIDATE_URL) === false)) continue;
        $scheme = strtolower((string) parse_url($avatarUrl, PHP_URL_SCHEME));
        $urlsByUid[$uid] = in_array($scheme, ['http', 'https'], true) ? $avatarUrl : null;
    }
    foreach ($uidChunk as $uid) {
        $avatarUpdates[$uid] = $urlsByUid[$uid] ?? null;
    }
}

$monthRows = $fetchAll($pdo,
    "SELECT editor_uid,
            DATE_FORMAT(DATE_ADD(osm_timestamp, INTERVAL 9 HOUR), '%Y-%m-01') AS period_start,
            COUNT(*) AS total_count,
            SUM(change_action = 'create') AS create_count,
            SUM(change_action = 'modify') AS modify_count,
            COUNT(DISTINCT DATE(DATE_ADD(osm_timestamp, INTERVAL 9 HOUR))) AS active_day_count,
            MIN(osm_timestamp) AS first_activity_at,
            MAX(osm_timestamp) AS last_activity_at
       FROM osm_poi
      WHERE editor_uid IS NOT NULL AND osm_timestamp >= :period_start
      GROUP BY editor_uid, period_start
      ORDER BY editor_uid, period_start",
    ['period_start' => $monthStartUtc]
);

$categories = $fetchAll($pdo,
    "SELECT editor_uid, category, COALESCE(category_value,'') AS category_value,
            COUNT(*) AS total_count,
            SUM(change_action = 'create') AS create_count,
            SUM(change_action = 'modify') AS modify_count
       FROM osm_poi
      WHERE editor_uid IS NOT NULL
        AND osm_timestamp >= :period_start AND osm_timestamp < :period_end
      GROUP BY editor_uid, category, COALESCE(category_value,'')",
    ['period_start' => $periodStart, 'period_end' => $periodEnd]
);

$prefectures = $fetchAll($pdo,
    "SELECT editor_uid, COALESCE(NULLIF(prefecture,''),'日本国外・判定不能') AS prefecture,
            COUNT(*) AS total_count,
            SUM(change_action = 'create') AS create_count,
            SUM(change_action = 'modify') AS modify_count,
            COUNT(DISTINCT DATE(DATE_ADD(osm_timestamp, INTERVAL 9 HOUR))) AS active_day_count
       FROM osm_poi
      WHERE editor_uid IS NOT NULL
        AND osm_timestamp >= :period_start AND osm_timestamp < :period_end
      GROUP BY editor_uid, COALESCE(NULLIF(prefecture,''),'日本国外・判定不能')",
    ['period_start' => $periodStart, 'period_end' => $periodEnd]
);
$prefecturesByUid = [];
$prefectureValuesByUid = [];
$prefectureActiveDayValuesByUid = [];
foreach ($prefectures as $row) {
    $prefecturesByUid[(string) $row['editor_uid']][] = (string) $row['prefecture'];
    $prefectureValuesByUid[(string) $row['editor_uid']][(string) $row['prefecture']] = (int) $row['total_count'];
    $prefectureActiveDayValuesByUid[(string) $row['editor_uid']][(string) $row['prefecture']] = (int) $row['active_day_count'];
}
$categoryValuesByUid = [];
foreach ($categories as $row) {
    $categoryValuesByUid[(string) $row['editor_uid']][(string) $row['category'] . '=' . (string) $row['category_value']] = (int) $row['total_count'];
}

$backfill = $pdo->query(
    "SELECT COUNT(*) = 0 FROM osm_sync_state WHERE state_key = 'profile_badges_initialized'"
)->fetchColumn();
$backfill = (int) $backfill === 1;

$pdo->beginTransaction();
try {
    foreach (['mapper_profile_categories', 'mapper_profile_prefectures', 'mapper_activity_weeks', 'mapper_activity_months', 'mapper_profile_stats'] as $table) {
        $pdo->exec("DELETE FROM {$table}");
    }

    $insertMonth = $pdo->prepare(
        'INSERT INTO mapper_activity_months VALUES (?,?,?,?,?,?,?,?,?)'
    );
    foreach ($monthRows as $row) {
        $insertMonth->execute([
            $row['editor_uid'], $row['period_start'], $row['total_count'],
            $row['create_count'], $row['modify_count'], $row['active_day_count'],
            $row['first_activity_at'], $row['last_activity_at'], $calculatedAt,
        ]);
    }

    $insertCategory = $pdo->prepare(
        'INSERT INTO mapper_profile_categories VALUES (?,?,?,?,?,?,?)'
    );
    foreach ($categories as $row) {
        $insertCategory->execute([
            $row['editor_uid'], $row['category'], $row['category_value'],
            $row['total_count'], $row['create_count'], $row['modify_count'], $calculatedAt,
        ]);
    }

    $insertPrefecture = $pdo->prepare(
        'INSERT INTO mapper_profile_prefectures
         (editor_uid,prefecture,total_count,create_count,modify_count,active_day_count,calculated_at)
         VALUES (?,?,?,?,?,?,?)'
    );
    foreach ($prefectures as $row) {
        $insertPrefecture->execute([
            $row['editor_uid'], $row['prefecture'], $row['total_count'],
            $row['create_count'], $row['modify_count'], $row['active_day_count'], $calculatedAt,
        ]);
    }

    $insertStats = $pdo->prepare(
        'INSERT INTO mapper_profile_stats VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $upsertAvatar = $pdo->prepare(
        'INSERT INTO mapper_profile_avatars (editor_uid, avatar_url, checked_at)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE avatar_url=VALUES(avatar_url), checked_at=VALUES(checked_at)'
    );
    foreach ($avatarUpdates as $uid => $avatarUrl) {
        $upsertAvatar->execute([$uid, $avatarUrl, $calculatedAt]);
    }
    $badgeSelect = $pdo->prepare(
        'SELECT progress_value, badge_version, revoked_at
           FROM mapper_badges WHERE editor_uid = ? AND badge_key = ?'
    );
    $badgeInsert = $pdo->prepare(
        'INSERT INTO mapper_badges
         (editor_uid,badge_key,earned_at,progress_updated_at,progress_value,badge_version,acquisition_source)
         VALUES (?,?,?,?,?,?,?)'
    );
    $badgeUpdate = $pdo->prepare(
        'UPDATE mapper_badges
            SET progress_value = ?, progress_updated_at = ?, badge_version = ?,
                revoked_at = NULL, revoked_reason = NULL
          WHERE editor_uid = ? AND badge_key = ?'
    );
    $badgeRevoke = $pdo->prepare(
        'UPDATE mapper_badges
            SET progress_value = ?, progress_updated_at = ?, badge_version = ?,
                revoked_at = ?, revoked_reason = ?
          WHERE editor_uid = ? AND badge_key = ?'
    );

    foreach ($baseRows as $stats) {
        $uid = (string) $stats['editor_uid'];
        $stats = array_map(static fn($value) => is_numeric($value) ? (int) $value : $value, $stats);
        $stats['prefectures'] = $prefecturesByUid[$uid] ?? [];
        $stats['prefecture_values'] = $prefectureValuesByUid[$uid] ?? [];
        $stats['prefecture_active_day_values'] = $prefectureActiveDayValuesByUid[$uid] ?? [];
        $stats['category_values'] = $categoryValuesByUid[$uid] ?? [];
        $insertStats->execute([
            $uid, $stats['editor_name'], $periodStart, $periodEnd,
            $stats['total_count'], $stats['create_count'], $stats['modify_count'],
            $stats['changeset_count'], $stats['active_day_count'], 0, 0,
            $stats['prefecture_count'], $stats['category_count'],
            0, 0,
            0, 0,
            $stats['first_activity_at'], $stats['last_activity_at'], $calculatedAt,
        ]);

        foreach (profileBadgeDefinitions() as $key => $definition) {
            $progress = profileBadgeProgress($stats, $definition);
            $version = (int) ($definition['version'] ?? 1);
            $badgeSelect->execute([$uid, $key]);
            $existing = $badgeSelect->fetch();
            if ($progress < $definition['threshold']) {
                if ($existing !== false && (int) $existing['badge_version'] < $version && $existing['revoked_at'] === null) {
                    $badgeRevoke->execute([
                        $progress, $calculatedAt, $version, $calculatedAt,
                        '獲得条件の変更', $uid, $key,
                    ]);
                }
            } elseif ($existing === false) {
                $badgeInsert->execute([
                    $uid, $key, $calculatedAt, $calculatedAt, $progress,
                    $version,
                    $backfill ? 'backfill' : 'live',
                ]);
            } elseif ($progress > (int) $existing['progress_value']
                || (int) $existing['badge_version'] < $version
                || $existing['revoked_at'] !== null) {
                $badgeUpdate->execute([$progress, $calculatedAt, $version, $uid, $key]);
            }
        }
    }

    $state = $pdo->prepare(
        "INSERT INTO osm_sync_state (state_key,state_value) VALUES ('profile_badges_initialized',?)
         ON DUPLICATE KEY UPDATE state_value=VALUES(state_value)"
    );
    $state->execute([$calculatedAt]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

printf(
    "Profile aggregation completed: %d mappers, %d months, %d categories, %d prefectures, %d avatar checks.\n",
    count($baseRows), count($monthRows), count($categories), count($prefectures), count($avatarUids)
);
$pdo->query("SELECT RELEASE_LOCK('osm_profile_sync')");
