<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$stage = 'initialization';

try {
    $config = require __DIR__ . '/bootstrap.php';

    $requestOrigin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    $allowedOrigins = $config['cors']['allowed_origins'] ?? ['*'];
    if (!is_array($allowedOrigins)) {
        throw new RuntimeException('cors.allowed_origins must be an array.');
    }
    $corsOrigin = null;
    if ($requestOrigin !== '' && !preg_match('/[\r\n]/', $requestOrigin)) {
        if (in_array('*', $allowedOrigins, true)) {
            $corsOrigin = '*';
        } elseif (in_array($requestOrigin, $allowedOrigins, true)) {
            $corsOrigin = $requestOrigin;
            header('Vary: Origin');
        }
    }
    if ($corsOrigin !== null) {
        header('Access-Control-Allow-Origin: ' . $corsOrigin);
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Accept, Content-Type');
        header('Access-Control-Max-Age: 86400');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        if ($requestOrigin !== '' && $corsOrigin === null) {
            http_response_code(403);
        } else {
            http_response_code(204);
        }
        exit;
    }

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

    $mode = strtolower(trim((string) ($_GET['mode'] ?? 'pois')));
    $allowedModes = ['pois', 'japan', 'prefectures', 'facets', 'profile', 'mapper_search', 'profile_region_mappers', 'badge_mappers'];
    if (!in_array($mode, $allowedModes, true)) {
        throw new InvalidArgumentException('Unsupported mode.');
    }

    $validDate = static fn(string $value): bool =>
        (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
        && DateTimeImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value;

    $allowedDays = [1, 2, 7, 14, 30, 90, 183, 365];
    $days = (int) ($_GET['days'] ?? 14);
    if (!in_array($days, $allowedDays, true)) {
        $days = 14;
    }

    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));
    if (($from === '') !== ($to === '')) {
        throw new InvalidArgumentException('from and to must be specified together.');
    }

    if ($from !== '') {
        if (!$validDate($from) || !$validDate($to) || $from > $to) {
            throw new InvalidArgumentException('The date range is invalid.');
        }
        // Date inputs represent calendar days in Japan, while osm_timestamp is UTC.
        $japanTimeZone = new DateTimeZone('Asia/Tokyo');
        $utcTimeZone = new DateTimeZone('UTC');
        $periodStartDate = new DateTimeImmutable("{$from} 00:00:00", $japanTimeZone);
        $periodEndExclusiveDate = (new DateTimeImmutable(
            "{$to} 00:00:00",
            $japanTimeZone
        ))->modify('+1 day');
        $periodStart = $periodStartDate->setTimezone($utcTimeZone)->format('Y-m-d H:i:s');
        $periodEndExclusive = $periodEndExclusiveDate
            ->setTimezone($utcTimeZone)
            ->format('Y-m-d H:i:s');
        $periodEnd = $periodEndExclusiveDate
            ->modify('-1 second')
            ->setTimezone($utcTimeZone)
            ->format('Y-m-d H:i:s');
    } else {
        $stage = 'default period';
        $periodStatement = $pdo->query(
            "SELECT UTC_TIMESTAMP() - INTERVAL {$days} DAY AS period_start,
                    UTC_TIMESTAMP() AS period_end"
        );
        $period = $periodStatement->fetch();
        $periodStart = (string) $period['period_start'];
        $periodEnd = (string) $period['period_end'];
        $periodEndExclusive = (new DateTimeImmutable(
            $periodEnd,
            new DateTimeZone('UTC')
        ))->modify('+1 second')->format('Y-m-d H:i:s');
    }

    $conditions = [
        'osm_timestamp >= :period_start',
        'osm_timestamp < :period_end_exclusive',
    ];
    $parameters = [
        'period_start' => $periodStart,
        'period_end_exclusive' => $periodEndExclusive,
    ];
    $filters = [];

    $readTextFilter = static function (string $name, int $maximumLength): string {
        $value = trim((string) ($_GET[$name] ?? ''));
        if (mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException("{$name} is too long.");
        }
        return $value;
    };

    $prefecture = $readTextFilter('prefecture', 64);
    if (array_key_exists('prefecture_code', $_GET)) {
        $prefectureCode = $_GET['prefecture_code'];
        if (!is_string($prefectureCode)
            || !preg_match('/^(0[1-9]|[1-3][0-9]|4[0-7])$/D', $prefectureCode)) {
            throw new InvalidArgumentException('prefecture_code must be a two-digit code from 01 to 47.');
        }
        // Standard prefecture code order. Keep the existing name-based DB filter.
        $prefectureNames = [
            '北海道', '青森県', '岩手県', '宮城県', '秋田県', '山形県', '福島県',
            '茨城県', '栃木県', '群馬県', '埼玉県', '千葉県', '東京都', '神奈川県',
            '新潟県', '富山県', '石川県', '福井県', '山梨県', '長野県', '岐阜県',
            '静岡県', '愛知県', '三重県', '滋賀県', '京都府', '大阪府', '兵庫県',
            '奈良県', '和歌山県', '鳥取県', '島根県', '岡山県', '広島県', '山口県',
            '徳島県', '香川県', '愛媛県', '高知県', '福岡県', '佐賀県', '長崎県',
            '熊本県', '大分県', '宮崎県', '鹿児島県', '沖縄県',
        ];
        $resolvedPrefecture = $prefectureNames[(int) $prefectureCode - 1];
        if ($prefecture !== '' && $prefecture !== $resolvedPrefecture) {
            throw new InvalidArgumentException('prefecture and prefecture_code must identify the same prefecture.');
        }
        $prefecture = $resolvedPrefecture;
        $filters['prefecture_code'] = $prefectureCode;
    }
    if ($prefecture !== '') {
        $conditions[] = 'prefecture = :prefecture';
        $parameters['prefecture'] = $prefecture;
        $filters['prefecture'] = $prefecture;
    }

    $editorUidSource = $_GET['editor_uid'] ?? $_GET['editorUid'] ?? '';
    $editorUidText = trim((string) $editorUidSource);
    if ($editorUidText !== '') {
        if (!preg_match('/^[1-9]\d*$/', $editorUidText)) {
            throw new InvalidArgumentException('editor_uid must be a positive integer.');
        }
        $conditions[] = 'editor_uid = :editor_uid';
        $parameters['editor_uid'] = $editorUidText;
        $filters['editor_uid'] = $editorUidText;
    }

    $editorName = $readTextFilter('editor_name', 255);
    if ($editorName !== '') {
        $conditions[] = 'editor_name = :editor_name';
        $parameters['editor_name'] = $editorName;
        $filters['editor_name'] = $editorName;
    }

    $category = $readTextFilter('category', 255);
    $categoryValue = $readTextFilter('category_value', 255);
    if ($categoryValue !== '' && $category === '') {
        throw new InvalidArgumentException('category_value requires category.');
    }
    if ($category !== '') {
        $conditions[] = 'category = :category';
        $parameters['category'] = $category;
        $filters['category'] = $category;
    }
    if ($categoryValue !== '') {
        $conditions[] = 'category_value = :category_value';
        $parameters['category_value'] = $categoryValue;
        $filters['category_value'] = $categoryValue;
    }

    $action = strtolower($readTextFilter('action', 16));
    if ($action !== '') {
        if (!in_array($action, ['create', 'modify'], true)) {
            throw new InvalidArgumentException('action must be create or modify.');
        }
        $conditions[] = 'change_action = :action';
        $parameters['action'] = $action;
        $filters['action'] = $action;
    }

    if (array_key_exists('tag_key', $_GET) || array_key_exists('tag_value', $_GET)) {
        if ($mode !== 'pois') {
            throw new InvalidArgumentException('tag_key and tag_value are supported only in pois mode.');
        }
        $tagKey = $_GET['tag_key'] ?? null;
        if (!is_string($tagKey) || $tagKey === ''
            || !mb_check_encoding($tagKey, 'UTF-8') || mb_strlen($tagKey) > 255) {
            throw new InvalidArgumentException('tag_key must be a non-empty UTF-8 string of at most 255 characters.');
        }
        // Quote the entire key as one JSONPath member; dots and wildcards are literal.
        $tagPath = '$.' . json_encode($tagKey, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $conditions[] = "osm_type = 'node'";
        $filters['tag_key'] = $tagKey;
        if (array_key_exists('tag_value', $_GET)) {
            $tagValue = $_GET['tag_value'];
            if (!is_string($tagValue) || !mb_check_encoding($tagValue, 'UTF-8')
                || mb_strlen($tagValue) > 255) {
                throw new InvalidArgumentException('tag_value must be a UTF-8 string of at most 255 characters.');
            }
            // A missing key yields SQL NULL and cannot match even an empty string.
            // Binary comparison preserves case and trailing spaces.
            $conditions[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(tags, :tag_value_path)) AS BINARY)'
                . ' = CAST(:tag_value AS BINARY)';
            $parameters['tag_value_path'] = $tagPath;
            $parameters['tag_value'] = $tagValue;
            $filters['tag_value'] = $tagValue;
        } else {
            $conditions[] = "JSON_CONTAINS_PATH(tags, 'one', :tag_path) = 1";
            $parameters['tag_path'] = $tagPath;
        }
    }

    $where = implode(' AND ', $conditions);
    $meta = [
        'mode' => $mode,
        'days' => $days,
        'periodStart' => $periodStart,
        'periodEnd' => $periodEnd,
        'filters' => $filters,
    ];

    $fetchAll = static function (
        PDO $pdo,
        string $sql,
        array $parameters
    ): array {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    };

    $castCounts = static function (array $rows, array $fields = ['count']): array {
        foreach ($rows as &$row) {
            foreach ($fields as $field) {
                if (array_key_exists($field, $row)) {
                    $row[$field] = (int) $row[$field];
                }
            }
        }
        unset($row);
        return $rows;
    };

    if ($mode === 'mapper_search') {
        $stage = 'mapper search';
        $query = $readTextFilter('q', 255);
        if ($query === '') {
            throw new InvalidArgumentException('mapper_search mode requires q.');
        }
        $escapedQuery = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $mappers = $fetchAll(
            $pdo,
            'SELECT editor_uid AS uid, editor_name AS name,
                    total_count AS total, last_activity_at AS lastActivityAt
               FROM mapper_profile_stats
              WHERE editor_name LIKE :name_prefix ESCAPE \'\\\\\'
              ORDER BY (editor_name = :exact_name) DESC,
                       last_activity_at DESC, total_count DESC, editor_name
              LIMIT 10',
            ['name_prefix' => $escapedQuery . '%', 'exact_name' => $query]
        );
        $mappers = $castCounts($mappers, ['total']);
        echo json_encode(
            ['meta' => ['mode' => 'mapper_search', 'query' => $query], 'mappers' => $mappers],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        exit;
    }

    if ($mode === 'profile_region_mappers') {
        $stage = 'profile region mappers';
        if ($prefecture === '') {
            $mappers = $fetchAll(
                $pdo,
                'SELECT editor_uid AS uid, editor_name AS name,
                        total_count AS total, create_count AS creates,
                        modify_count AS `modifies`, last_activity_at AS lastActivityAt
                  FROM mapper_profile_stats
                  ORDER BY last_activity_at DESC, total_count DESC, editor_name, editor_uid
                  LIMIT 100',
                []
            );
        } else {
            $mappers = $fetchAll(
                $pdo,
                'SELECT area.editor_uid AS uid, stats.editor_name AS name,
                        area.total_count AS total, area.create_count AS creates,
                        area.modify_count AS `modifies`, stats.last_activity_at AS lastActivityAt
                   FROM mapper_profile_prefectures area
                   JOIN mapper_profile_stats stats ON stats.editor_uid = area.editor_uid
                  WHERE area.prefecture = :prefecture
                  ORDER BY stats.last_activity_at DESC, area.total_count DESC,
                           stats.editor_name, area.editor_uid
                  LIMIT 100',
                ['prefecture' => $prefecture]
            );
        }
        echo json_encode(
            [
                'meta' => ['mode' => 'profile_region_mappers', 'prefecture' => $prefecture],
                'mappers' => $castCounts($mappers, ['total', 'creates', 'modifies']),
            ],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        exit;
    }

    if ($mode === 'badge_mappers') {
        $stage = 'badge mappers';
        $badgeKey = $readTextFilter('badge_key', 64);
        if ($badgeKey === '') {
            throw new InvalidArgumentException('badge_mappers mode requires badge_key.');
        }
        $mappers = $fetchAll(
            $pdo,
            'SELECT badges.editor_uid AS uid,
                    COALESCE(stats.editor_name, \'\') AS name,
                    avatars.avatar_url AS avatarUrl,
                    badges.earned_at AS earnedAt
               FROM mapper_badges badges
               LEFT JOIN mapper_profile_stats stats ON stats.editor_uid = badges.editor_uid
               LEFT JOIN mapper_profile_avatars avatars ON avatars.editor_uid = badges.editor_uid
              WHERE badges.badge_key = :badge_key AND badges.revoked_at IS NULL
              ORDER BY badges.earned_at DESC, stats.editor_name, badges.editor_uid',
            ['badge_key' => $badgeKey]
        );
        echo json_encode(
            [
                'meta' => ['mode' => 'badge_mappers', 'badgeKey' => $badgeKey],
                'total' => count($mappers),
                'mappers' => $mappers,
            ],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        exit;
    }

    if ($mode === 'profile') {
        $stage = 'profile';
        if ($editorUidText === '') {
            throw new InvalidArgumentException('profile mode requires editor_uid.');
        }
        require_once __DIR__ . '/profile-lib.php';

        $profileStatement = $pdo->prepare(
            'SELECT stats.*, avatars.avatar_url
               FROM mapper_profile_stats stats
               LEFT JOIN mapper_profile_avatars avatars ON avatars.editor_uid = stats.editor_uid
              WHERE stats.editor_uid = :editor_uid'
        );
        $profileStatement->execute(['editor_uid' => $editorUidText]);
        $profile = $profileStatement->fetch();
        if (!$profile) {
            echo json_encode(
                ['meta' => ['mode' => 'profile', 'editorUid' => $editorUidText], 'profile' => null],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            exit;
        }

        $numericProfileFields = [
            'total_count', 'create_count', 'modify_count', 'changeset_count',
            'active_day_count', 'active_week_count', 'active_month_count',
            'prefecture_count', 'category_count', 'current_week_streak',
            'longest_week_streak', 'current_month_streak', 'longest_month_streak',
        ];
        foreach ($numericProfileFields as $field) $profile[$field] = (int) $profile[$field];
        unset(
            $profile['active_week_count'],
            $profile['current_week_streak'],
            $profile['longest_week_streak'],
            $profile['active_month_count'],
            $profile['current_month_streak'],
            $profile['longest_month_streak']
        );
        $currentMonth = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))
            ->modify('first day of this month')->format('Y-m-d');
        $monthlyStatement = $pdo->prepare(
            'SELECT total_count, create_count, modify_count, active_day_count
               FROM mapper_activity_months
              WHERE editor_uid = :editor_uid AND month_start = :month_start'
        );
        $monthlyStatement->execute(['editor_uid' => $editorUidText, 'month_start' => $currentMonth]);
        $monthlySummary = $monthlyStatement->fetch() ?: [
            'total_count' => 0,
            'create_count' => 0,
            'modify_count' => 0,
            'active_day_count' => 0,
        ];
        foreach ($monthlySummary as $field => $value) $monthlySummary[$field] = (int) $value;
        $monthlyTotal = $monthlySummary['total_count'];
        $profile['monthlySummary'] = $monthlySummary;
        $profile['monthlyLevel'] = profileMonthlyLevel($monthlyTotal);
        $profile['cumulativeLevel'] = profileCumulativeLevel($profile['total_count']);

        $categories = $fetchAll(
            $pdo,
            'SELECT category, category_value AS value, total_count AS total,
                    create_count AS creates, modify_count AS `modifies`
               FROM mapper_profile_categories WHERE editor_uid = :editor_uid
              ORDER BY total_count DESC, category, category_value LIMIT 10',
            ['editor_uid' => $editorUidText]
        );
        $categories = $castCounts($categories, ['total', 'creates', 'modifies']);

        $prefectures = $fetchAll(
            $pdo,
            'SELECT prefecture, total_count AS total,
                    create_count AS creates, modify_count AS `modifies`
               FROM mapper_profile_prefectures WHERE editor_uid = :editor_uid
              ORDER BY total_count DESC, prefecture LIMIT 10',
            ['editor_uid' => $editorUidText]
        );
        $prefectures = $castCounts($prefectures, ['total', 'creates', 'modifies']);

        $awards = $fetchAll(
            $pdo,
            'SELECT badge_key AS badgeKey, earned_at AS earnedAt,
                    progress_updated_at AS progressUpdatedAt,
                    progress_value AS progressValue,
                    badge_version AS badgeVersion,
                    acquisition_source AS acquisitionSource
               FROM mapper_badges
              WHERE editor_uid = :editor_uid AND revoked_at IS NULL
              ORDER BY earned_at DESC, badge_key',
            ['editor_uid' => $editorUidText]
        );
        $definitions = profileBadgeDefinitions();
        $badgeAwardCountRows = $pdo->query(
            'SELECT badge_key, COUNT(*) AS award_count
               FROM mapper_badges
              WHERE revoked_at IS NULL
              GROUP BY badge_key'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $badgeDefinitions = [];
        foreach ($definitions as $key => $definition) {
            $badgeDefinitions[] = [
                'badgeKey' => $key,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'icon' => $definition['icon'],
                'awardCount' => (int) ($badgeAwardCountRows[$key] ?? 0),
                'badgeGroup' => $definition['badge_group'] ?? null,
                'badgeLevel' => $definition['badge_level'] ?? null,
            ];
        }
        $badgePrefectureStatement = $pdo->prepare(
            'SELECT prefecture, total_count, active_day_count
               FROM mapper_profile_prefectures WHERE editor_uid = :editor_uid'
        );
        $badgePrefectureStatement->execute(['editor_uid' => $editorUidText]);
        $badgeStats = $profile;
        $badgeStats['prefectures'] = [];
        $badgeStats['prefecture_values'] = [];
        $badgeStats['prefecture_active_day_values'] = [];
        foreach ($badgePrefectureStatement->fetchAll() as $row) {
            $badgeStats['prefectures'][] = (string) $row['prefecture'];
            $badgeStats['prefecture_values'][(string) $row['prefecture']] = (int) $row['total_count'];
            $badgeStats['prefecture_active_day_values'][(string) $row['prefecture']] = (int) $row['active_day_count'];
        }
        $badgeCategoryStatement = $pdo->prepare(
            'SELECT category, category_value, total_count
               FROM mapper_profile_categories WHERE editor_uid = :editor_uid'
        );
        $badgeCategoryStatement->execute(['editor_uid' => $editorUidText]);
        $badgeStats['category_values'] = [];
        foreach ($badgeCategoryStatement->fetchAll() as $row) {
            $badgeStats['category_values'][(string) $row['category'] . '=' . (string) $row['category_value']] = (int) $row['total_count'];
        }
        $earnedKeys = [];
        foreach ($awards as &$award) {
            $award['progressValue'] = (int) $award['progressValue'];
            $award['badgeVersion'] = (int) $award['badgeVersion'];
            $award += $definitions[$award['badgeKey']] ?? [
                'name' => $award['badgeKey'], 'description' => '', 'icon' => '🏅',
                'metric' => '', 'threshold' => 0,
            ];
            $award['badgeGroup'] = $award['badge_group'] ?? null;
            $award['badgeLevel'] = $award['badge_level'] ?? null;
            $earnedKeys[$award['badgeKey']] = true;
        }
        unset($award);

        $nextBadges = [];
        foreach ($definitions as $key => $definition) {
            if (isset($earnedKeys[$key])) continue;
            $progress = profileBadgeProgress($badgeStats, $definition);
            $nextBadges[] = ['badgeKey' => $key, 'progressValue' => $progress] + $definition;
        }
        usort($nextBadges, static fn($a, $b) =>
            (($b['progressValue'] / max(1, $b['threshold'])) <=> ($a['progressValue'] / max(1, $a['threshold'])))
        );
        $nextBadges = array_slice($nextBadges, 0, 3);

        $recent = $fetchAll(
            $pdo,
            'SELECT osm_type AS type, osm_id AS id, name, category,
                    category_value AS categoryValue, prefecture,
                    osm_timestamp AS date, changeset_id AS changeset,
                    change_action AS action, latitude AS lat, longitude AS lon
               FROM osm_poi
              WHERE editor_uid = :editor_uid
              ORDER BY osm_timestamp DESC LIMIT 18',
            ['editor_uid' => $editorUidText]
        );

        $related = $fetchAll(
            $pdo,
            'SELECT candidate.editor_uid AS uid, candidate.editor_name AS name,
                    candidate_avatar.avatar_url AS avatarUrl,
                    candidate.total_count AS total, candidate.create_count AS creates,
                    candidate.modify_count AS `modifies`,
                    SUM(LEAST(candidate_pref.total_count, own_pref.total_count)) AS sharedScore,
                    candidate.last_activity_at AS lastActivityAt
               FROM mapper_profile_prefectures own_pref
               JOIN mapper_profile_prefectures candidate_pref
                 ON candidate_pref.prefecture = own_pref.prefecture
                AND candidate_pref.editor_uid <> own_pref.editor_uid
               JOIN mapper_profile_stats candidate ON candidate.editor_uid = candidate_pref.editor_uid
               LEFT JOIN mapper_profile_avatars candidate_avatar
                 ON candidate_avatar.editor_uid = candidate.editor_uid
              WHERE own_pref.editor_uid = :editor_uid
              GROUP BY candidate.editor_uid, candidate.editor_name, candidate.total_count,
                       candidate.create_count, candidate.modify_count, candidate.last_activity_at,
                       candidate_avatar.avatar_url
              ORDER BY sharedScore DESC,
                       CRC32(CONCAT(candidate.editor_uid, CURDATE(), :editor_uid_sort))
              LIMIT 9',
            ['editor_uid' => $editorUidText, 'editor_uid_sort' => $editorUidText]
        );
        $related = $castCounts($related, ['total', 'creates', 'modifies', 'sharedScore']);

        echo json_encode(
            [
                'meta' => [
                    'mode' => 'profile',
                    'editorUid' => $editorUidText,
                    'scope' => 'OSM What’s New Japanの収集対象地物・直近1年間',
                    'calculatedAt' => $profile['calculated_at'],
                ],
                'profile' => $profile,
                'badges' => $awards,
                'nextBadges' => $nextBadges,
                'badgeDefinitions' => $badgeDefinitions,
                'categories' => $categories,
                'prefectures' => $prefectures,
                'recent' => $recent,
                'relatedMappers' => $related,
            ],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        exit;
    }

    if ($mode === 'pois') {
        $stage = 'pois';
        $limit = max(1, min(5000, (int) ($_GET['limit'] ?? 1000)));
        $cursor = trim((string) ($_GET['cursor'] ?? ''));
        $poiConditions = $conditions;
        $poiParameters = $parameters;

        if ($cursor !== '') {
            $encoded = strtr($cursor, '-_', '+/');
            $padding = strlen($encoded) % 4;
            if ($padding !== 0) {
                $encoded .= str_repeat('=', 4 - $padding);
            }
            $decoded = base64_decode($encoded, true);
            $cursorValues = $decoded === false ? null : json_decode($decoded, true);
            if (!is_array($cursorValues)
                || count($cursorValues) !== 3
                || !is_string($cursorValues[0])
                || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cursorValues[0])
                || !in_array($cursorValues[1], ['node', 'way', 'relation'], true)
                || !is_int($cursorValues[2])) {
                throw new InvalidArgumentException('cursor is invalid.');
            }

            $typeOrder = ['node' => 1, 'way' => 2, 'relation' => 3];
            $poiConditions[] =
                '(osm_timestamp < :cursor_timestamp_before'
                . ' OR (osm_timestamp = :cursor_timestamp_equal'
                . ' AND (FIELD(osm_type,\'node\',\'way\',\'relation\') > :cursor_type_order'
                . ' OR (osm_type = :cursor_type AND osm_id > :cursor_id))))';
            $poiParameters['cursor_timestamp_before'] = $cursorValues[0];
            $poiParameters['cursor_timestamp_equal'] = $cursorValues[0];
            $poiParameters['cursor_type_order'] = $typeOrder[$cursorValues[1]];
            $poiParameters['cursor_type'] = $cursorValues[1];
            $poiParameters['cursor_id'] = $cursorValues[2];
        }

        $poiWhere = implode(' AND ', $poiConditions);
        $queryLimit = $limit + 1;
        $rows = $fetchAll(
            $pdo,
            'SELECT osm_type AS type, osm_id AS id, name,'
            . ' category AS type2, category_value AS kind, tags,'
            . ' latitude AS lat, longitude AS lon, prefecture,'
            . ' osm_timestamp AS date, changeset_id AS changeset,'
            . ' editor_uid AS editorUid, editor_name AS editorName,'
            . ' change_action AS action'
            . " FROM osm_poi WHERE {$poiWhere}"
            . " ORDER BY osm_timestamp DESC, osm_type, osm_id"
            . " LIMIT {$queryLimit}",
            $poiParameters
        );

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $nextCursor = null;
        if ($hasMore && $rows) {
            $last = $rows[array_key_last($rows)];
            $cursorJson = json_encode([
                (string) $last['date'],
                (string) $last['type'],
                (int) $last['id'],
            ], JSON_THROW_ON_ERROR);
            $nextCursor = rtrim(strtr(base64_encode($cursorJson), '+/', '-_'), '=');
        }

        $meta['limit'] = $limit;
        $meta['nextCursor'] = $nextCursor;
        echo json_encode(
            ['meta' => $meta, 'items' => $rows],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        exit;
    }

    if ($mode === 'prefectures') {
        $stage = 'prefectures';
        $rows = $fetchAll(
            $pdo,
            'SELECT COALESCE(NULLIF(prefecture,\'\'),\'日本国外・判定不能\') AS prefecture,'
            . " COUNT(*) AS count FROM osm_poi WHERE {$where}"
            . ' GROUP BY COALESCE(NULLIF(prefecture,\'\'),\'日本国外・判定不能\')'
            . ' ORDER BY count DESC, prefecture',
            $parameters
        );
        echo json_encode(
            ['meta' => $meta, 'items' => $castCounts($rows)],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        exit;
    }

    if ($mode === 'facets') {
        $stage = 'facets';
        $mappers = $fetchAll(
            $pdo,
            'SELECT editor_uid AS uid,'
            . " COALESCE(NULLIF(editor_name,''),'不明') AS name,"
            . " SUM(CASE WHEN change_action = 'create' THEN 1 ELSE 0 END) AS creates,"
            . " SUM(CASE WHEN change_action = 'create' THEN 0 ELSE 1 END) AS `modifies`,"
            . " COUNT(*) AS count FROM osm_poi WHERE {$where}"
            . ' GROUP BY editor_uid, editor_name ORDER BY count DESC, name LIMIT 100',
            $parameters
        );
        $categories = $fetchAll(
            $pdo,
            'SELECT category, category_value, COUNT(*) AS count'
            . " FROM osm_poi WHERE {$where}"
            . ' GROUP BY category, category_value'
            . ' ORDER BY count DESC, category, category_value LIMIT 500',
            $parameters
        );
        echo json_encode(
            [
                'meta' => $meta,
                'mappers' => $castCounts($mappers, ['creates', 'modifies', 'count']),
                'categories' => $castCounts($categories),
            ],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        exit;
    }

    $stage = 'japan total';
    $totalStatement = $pdo->prepare("SELECT COUNT(*) FROM osm_poi WHERE {$where}");
    $totalStatement->execute($parameters);
    $total = (int) $totalStatement->fetchColumn();

    $stage = 'japan changeset count';
    $changesetCountStatement = $pdo->prepare(
        "SELECT COUNT(DISTINCT changeset_id) FROM osm_poi WHERE {$where}"
    );
    $changesetCountStatement->execute($parameters);
    $changesetCount = (int) $changesetCountStatement->fetchColumn();

    $stage = 'japan mapper count';
    $mapperCountStatement = $pdo->prepare(
        "SELECT COUNT(DISTINCT CASE"
        . " WHEN editor_uid IS NOT NULL THEN CONCAT('uid:', editor_uid)"
        . " ELSE CONCAT('name:', COALESCE(NULLIF(editor_name,''),'不明'))"
        . " END) FROM osm_poi WHERE {$where}"
    );
    $mapperCountStatement->execute($parameters);
    $mapperCount = (int) $mapperCountStatement->fetchColumn();

    $stage = 'japan mappers';
    $mappers = $fetchAll(
        $pdo,
        'SELECT editor_uid AS uid,'
        . " COALESCE(NULLIF(editor_name,''),'不明') AS name,"
        . " SUM(CASE WHEN change_action = 'create' THEN 1 ELSE 0 END) AS creates,"
        . " SUM(CASE WHEN change_action = 'create' THEN 0 ELSE 1 END) AS `modifies`,"
        . " COUNT(*) AS count FROM osm_poi WHERE {$where}"
        . ' GROUP BY editor_uid, editor_name ORDER BY count DESC, name LIMIT 100',
        $parameters
    );

    $stage = 'japan changesets';
    $changesets = $fetchAll(
        $pdo,
        'SELECT changeset_id AS changeset,'
        . " MIN(COALESCE(NULLIF(editor_name,''),'不明')) AS editorName,"
        . " SUM(CASE WHEN change_action = 'create' THEN 1 ELSE 0 END) AS creates,"
        . " SUM(CASE WHEN change_action = 'create' THEN 0 ELSE 1 END) AS `modifies`,"
        . ' COUNT(*) AS count'
        . " FROM osm_poi WHERE {$where} AND changeset_id IS NOT NULL"
        . ' GROUP BY changeset_id ORDER BY count DESC, changeset_id DESC LIMIT 100',
        $parameters
    );

    $stage = 'japan categories';
    $categories = $fetchAll(
        $pdo,
        'SELECT category AS type, category_value AS value,'
        . " SUM(CASE WHEN change_action = 'create' THEN 1 ELSE 0 END) AS creates,"
        . " SUM(CASE WHEN change_action = 'create' THEN 0 ELSE 1 END) AS `modifies`,"
        . ' COUNT(*) AS count'
        . " FROM osm_poi WHERE {$where}"
        . ' GROUP BY category, category_value'
        . ' ORDER BY count DESC, type, value LIMIT 100',
        $parameters
    );

    $stage = 'japan daily';
    $daily = $fetchAll(
        $pdo,
        'SELECT DATE(osm_timestamp) AS ranking_date,'
        . " SUM(CASE WHEN change_action = 'create' THEN 1 ELSE 0 END) AS creates,"
        . " SUM(CASE WHEN change_action = 'create' THEN 0 ELSE 1 END) AS `modifies`, "
        . " COUNT(DISTINCT CASE WHEN editor_uid IS NOT NULL"
        . " THEN CONCAT('uid:', editor_uid)"
        . " ELSE CONCAT('name:', COALESCE(NULLIF(editor_name,''),'不明')) END) AS mapperCount,"
        . ' COUNT(*) AS count'
        . " FROM osm_poi WHERE {$where}"
        . ' GROUP BY DATE(osm_timestamp)'
        . ' ORDER BY ranking_date',
        $parameters
    );

    $stage = 'japan prefectures';
    $prefectures = $fetchAll(
        $pdo,
        'SELECT COALESCE(NULLIF(prefecture,\'\'),\'日本国外・判定不能\') AS name,'
        . " SUM(CASE WHEN change_action = 'create' THEN 1 ELSE 0 END) AS creates,"
        . " SUM(CASE WHEN change_action = 'create' THEN 0 ELSE 1 END) AS `modifies`,"
        . " COUNT(*) AS count FROM osm_poi WHERE {$where}"
        . ' GROUP BY COALESCE(NULLIF(prefecture,\'\'),\'日本国外・判定不能\')'
        . ' ORDER BY count DESC, name LIMIT 100',
        $parameters
    );

    echo json_encode(
        [
            'meta' => $meta,
            'total' => $total,
            'changesetCount' => $changesetCount,
            'mapperCount' => $mapperCount,
            'mappers' => $castCounts($mappers, ['creates', 'modifies', 'count']),
            'changesets' => $castCounts($changesets, ['creates', 'modifies', 'count']),
            'categories' => $castCounts($categories, ['creates', 'modifies', 'count']),
            'daily' => $castCounts($daily, ['creates', 'modifies', 'mapperCount', 'count']),
            'prefectures' => $castCounts($prefectures, ['creates', 'modifies', 'count']),
        ],
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
} catch (Throwable $e) {
    error_log("whatsnew api [{$stage}]: " . $e->getMessage());
    $isInputError = $e instanceof InvalidArgumentException;
    http_response_code($isInputError ? 400 : 500);

    $message = $isInputError
        ? $e->getMessage()
        : 'サーバー内部エラーが発生しました。';
    if ($e instanceof RuntimeException
        && $e->getMessage() === 'Server configuration is missing.') {
        $message = 'DB設定ファイルが見つかりません。';
    } elseif ($e instanceof PDOException) {
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        if ($e->getCode() === '42S02' || $driverCode === 1146) {
            $message = 'DBテーブルがありません。schema.sqlをDBへ適用してください。';
        } elseif ($driverCode === 1045) {
            $message = 'DB認証に失敗しました。ユーザー名またはDB接続用パスワードを確認してください。';
        } elseif ($driverCode === 1049) {
            $message = '指定したDBが存在しません。DB名を確認してください。';
        } elseif (in_array($driverCode, [2002, 2003, 2005], true)) {
            $message = 'DBサーバーへ到達できません。ホスト名を確認してください。';
        } else {
            $message = 'DBへ接続できません（MySQLエラー ' . $driverCode . '）。';
        }
    }

    echo json_encode(
        ['error' => $message, 'stage' => $stage],
        JSON_UNESCAPED_UNICODE
    );
}
