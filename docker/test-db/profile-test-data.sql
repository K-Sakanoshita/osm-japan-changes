-- Synthetic data for the local Docker test database only.
-- The shell wrapper imports this file only when no seed marker exists. Reserved
-- high IDs avoid collisions when the local DB already contains imported data.
-- Dates are relative so weekly/monthly profile tests stay useful.

SET @profile_seed_allowed = NOT EXISTS (
  SELECT 1 FROM osm_sync_state
  WHERE state_key = 'profile_test_data_version' AND state_value = '9'
);

SET @current_jst_date = DATE(DATE_ADD(UTC_TIMESTAMP(), INTERVAL 9 HOUR));
SET @current_jst_week = DATE_SUB(
  @current_jst_date,
  INTERVAL WEEKDAY(@current_jst_date) DAY
);

-- A mapper active in each of the last 52 JST Monday-Sunday weeks.
INSERT IGNORE INTO osm_poi (
  osm_type, osm_id, name, category, category_value,
  latitude, longitude, prefecture, tags, osm_timestamp, changeset_id,
  editor_uid, editor_name, change_action,
  created_osm_at, creator_uid, creator_name
)
WITH RECURSIVE week_numbers AS (
  SELECT 0 AS week_number
  UNION ALL
  SELECT week_number + 1 FROM week_numbers WHERE week_number < 51
)
SELECT
  'node',
  8000000000000000 + week_number,
  CONCAT('プロフィールテスト地物 ', week_number + 1),
  CASE week_number % 4
    WHEN 0 THEN 'shop'
    WHEN 1 THEN 'amenity'
    WHEN 2 THEN 'tourism'
    ELSE 'leisure'
  END,
  CASE week_number % 4
    WHEN 0 THEN 'convenience'
    WHEN 1 THEN 'bench'
    WHEN 2 THEN 'information'
    ELSE 'park'
  END,
  35.6800000 + (week_number / 10000),
  139.7600000 + (week_number / 10000),
  CASE week_number % 3
    WHEN 0 THEN '東京都'
    WHEN 1 THEN '神奈川県'
    ELSE '埼玉県'
  END,
  JSON_OBJECT(
    'name', CONCAT('プロフィールテスト地物 ', week_number + 1),
    'test_data', 'profile'
  ),
  TIMESTAMP(DATE_SUB(@current_jst_week, INTERVAL week_number WEEK), '12:00:00')
    - INTERVAL 9 HOUR,
  7000000000000000 + week_number,
  6000000000000001,
  'Test Mapper Weekly',
  IF(week_number % 3 = 0, 'create', 'modify'),
  TIMESTAMP(DATE_SUB(@current_jst_week, INTERVAL week_number WEEK), '12:00:00')
    - INTERVAL 9 HOUR,
  6000000000000001,
  'Test Mapper Weekly'
FROM week_numbers
WHERE @profile_seed_allowed;

-- A mapper with gaps: one active week out of every three in the recent year.
INSERT IGNORE INTO osm_poi (
  osm_type, osm_id, name, category, category_value,
  latitude, longitude, prefecture, tags, osm_timestamp, changeset_id,
  editor_uid, editor_name, change_action,
  created_osm_at, creator_uid, creator_name
)
WITH RECURSIVE activity_numbers AS (
  SELECT 0 AS activity_number
  UNION ALL
  SELECT activity_number + 1 FROM activity_numbers WHERE activity_number < 17
)
SELECT
  'node',
  8010000000000000 + activity_number,
  CONCAT('断続活動テスト地物 ', activity_number + 1),
  'shop',
  IF(activity_number % 2 = 0, 'bakery', 'supermarket'),
  34.6900000 + (activity_number / 10000),
  135.5000000 + (activity_number / 10000),
  '大阪府',
  JSON_OBJECT(
    'name', CONCAT('断続活動テスト地物 ', activity_number + 1),
    'test_data', 'profile'
  ),
  TIMESTAMP(
    DATE_SUB(@current_jst_week, INTERVAL (activity_number * 3) WEEK),
    '15:00:00'
  ) - INTERVAL 9 HOUR,
  7010000000000000 + activity_number,
  6000000000000002,
  'Test Mapper Sometimes',
  IF(activity_number % 4 = 0, 'create', 'modify'),
  TIMESTAMP(
    DATE_SUB(@current_jst_week, INTERVAL (activity_number * 3) WEEK),
    '15:00:00'
  ) - INTERVAL 9 HOUR,
  6000000000000002,
  'Test Mapper Sometimes'
FROM activity_numbers
WHERE @profile_seed_allowed;

-- A new mapper with only a few activities in the current week.
INSERT IGNORE INTO osm_poi (
  osm_type, osm_id, name, category, category_value,
  latitude, longitude, prefecture, tags, osm_timestamp, changeset_id,
  editor_uid, editor_name, change_action,
  created_osm_at, creator_uid, creator_name
)
SELECT
  'node',
  8020000000000000 + activity_number,
  CONCAT('初心者テスト地物 ', activity_number + 1),
  'amenity',
  CASE activity_number WHEN 0 THEN 'library' WHEN 1 THEN 'cafe' ELSE 'toilets' END,
  35.1700000 + (activity_number / 10000),
  136.8800000 + (activity_number / 10000),
  '愛知県',
  JSON_OBJECT(
    'name', CONCAT('初心者テスト地物 ', activity_number + 1),
    'test_data', 'profile'
  ),
  TIMESTAMP(@current_jst_date, MAKETIME(8 + activity_number, 0, 0)) - INTERVAL 9 HOUR,
  7020000000000000 + activity_number,
  6000000000000003,
  'Test Mapper New',
  IF(activity_number = 0, 'create', 'modify'),
  TIMESTAMP(@current_jst_date, MAKETIME(8 + activity_number, 0, 0)) - INTERVAL 9 HOUR,
  6000000000000003,
  'Test Mapper New'
FROM (
  SELECT 0 AS activity_number
  UNION ALL SELECT 1
  UNION ALL SELECT 2
) AS beginner_activities
WHERE @profile_seed_allowed;

-- JST week boundary: Sunday 23:59 and the following Monday 00:01.
INSERT IGNORE INTO osm_poi (
  osm_type, osm_id, name, category, category_value,
  latitude, longitude, prefecture, tags, osm_timestamp, changeset_id,
  editor_uid, editor_name, change_action,
  created_osm_at, creator_uid, creator_name
)
SELECT
  'node', 8030000000000001, '週境界テスト・日曜日', 'information', 'board',
  43.0618000, 141.3545000, '北海道',
  JSON_OBJECT('name', '週境界テスト・日曜日', 'test_data', 'profile-boundary'),
  TIMESTAMP(DATE_SUB(@current_jst_week, INTERVAL 1 DAY), '23:59:00') - INTERVAL 9 HOUR,
  7030000000000001, 6000000000000004, 'Test Mapper Boundary', 'modify',
  TIMESTAMP(DATE_SUB(@current_jst_week, INTERVAL 1 DAY), '23:59:00') - INTERVAL 9 HOUR,
  6000000000000004, 'Test Mapper Boundary'
WHERE @profile_seed_allowed
UNION ALL
SELECT
  'node', 8030000000000002, '週境界テスト・月曜日', 'information', 'board',
  43.0619000, 141.3546000, '北海道',
  JSON_OBJECT('name', '週境界テスト・月曜日', 'test_data', 'profile-boundary'),
  TIMESTAMP(@current_jst_week, '00:01:00') - INTERVAL 9 HOUR,
  7030000000000002, 6000000000000004, 'Test Mapper Boundary', 'create',
  TIMESTAMP(@current_jst_week, '00:01:00') - INTERVAL 9 HOUR,
  6000000000000004, 'Test Mapper Boundary'
WHERE @profile_seed_allowed;

-- Dense recent activity for the normal two-day map/report range. Together
-- with the seven recent rows above this provides 707 recent features.
INSERT INTO osm_poi (
  osm_type, osm_id, name, category, category_value,
  latitude, longitude, prefecture, tags, osm_timestamp, changeset_id,
  editor_uid, editor_name, change_action,
  created_osm_at, creator_uid, creator_name
)
WITH RECURSIVE recent_numbers AS (
  SELECT 0 AS activity_number
  UNION ALL
  SELECT activity_number + 1 FROM recent_numbers WHERE activity_number < 699
), city_anchors AS (
  SELECT 0 AS prefecture_number, 0 AS city_number, 43.0618 AS latitude, 141.3545 AS longitude
  UNION ALL SELECT 0, 1, 43.7707, 142.3650
  UNION ALL SELECT 0, 2, 42.9239, 143.1961
  UNION ALL SELECT 0, 3, 43.8031, 143.8930
  UNION ALL SELECT 0, 4, 43.3420, 142.3832
  UNION ALL SELECT 1, 0, 35.6896, 139.6917
  UNION ALL SELECT 1, 1, 35.6663, 139.3160
  UNION ALL SELECT 1, 2, 35.6689, 139.4777
  UNION ALL SELECT 1, 3, 35.7138, 139.4077
  UNION ALL SELECT 1, 4, 35.7356, 139.6517
  UNION ALL SELECT 2, 0, 35.4658, 139.6223
  UNION ALL SELECT 2, 1, 35.5308, 139.7029
  UNION ALL SELECT 2, 2, 35.5714, 139.3732
  UNION ALL SELECT 2, 3, 35.4431, 139.3625
  UNION ALL SELECT 2, 4, 35.2646, 139.1520
  UNION ALL SELECT 3, 0, 35.1709, 136.8815
  UNION ALL SELECT 3, 1, 35.0824, 137.1563
  UNION ALL SELECT 3, 2, 34.9543, 137.1744
  UNION ALL SELECT 3, 3, 35.3039, 136.8021
  UNION ALL SELECT 3, 4, 35.2476, 136.9722
  UNION ALL SELECT 4, 0, 34.6937, 135.5023
  UNION ALL SELECT 4, 1, 34.6793, 135.6008
  UNION ALL SELECT 4, 2, 34.8143, 135.6507
  UNION ALL SELECT 4, 3, 34.4994, 135.5972
  UNION ALL SELECT 4, 4, 34.8269, 135.4706
  UNION ALL SELECT 5, 0, 34.3853, 132.4553
  UNION ALL SELECT 5, 1, 34.4859, 133.3623
  UNION ALL SELECT 5, 2, 34.4267, 132.7432
  UNION ALL SELECT 5, 3, 34.8057, 132.8517
  UNION ALL SELECT 5, 4, 34.3480, 132.3317
  UNION ALL SELECT 6, 0, 33.5902, 130.4017
  UNION ALL SELECT 6, 1, 33.8835, 130.8752
  UNION ALL SELECT 6, 2, 33.3193, 130.5084
  UNION ALL SELECT 6, 3, 33.6467, 130.6912
  UNION ALL SELECT 6, 4, 33.5128, 130.5239
)
SELECT
  'node',
  8040000000000000 + activity_number,
  CONCAT('直近活動テスト地物 ', activity_number + 1),
  CASE activity_number % 6
    WHEN 0 THEN 'shop'
    WHEN 1 THEN 'amenity'
    WHEN 2 THEN 'tourism'
    WHEN 3 THEN 'leisure'
    WHEN 4 THEN 'information'
    ELSE 'office'
  END,
  CASE activity_number % 6
    WHEN 0 THEN 'convenience'
    WHEN 1 THEN 'cafe'
    WHEN 2 THEN 'museum'
    WHEN 3 THEN 'park'
    WHEN 4 THEN 'board'
    ELSE 'company'
  END,
  city_anchors.latitude
    + (((FLOOR(activity_number / 35) % 5) - 2) / 1000),
  city_anchors.longitude
    + ((FLOOR(FLOOR(activity_number / 35) / 5) - 1.5) / 1000),
  CASE activity_number % 7
    WHEN 0 THEN '北海道'
    WHEN 1 THEN '東京都'
    WHEN 2 THEN '神奈川県'
    WHEN 3 THEN '愛知県'
    WHEN 4 THEN '大阪府'
    WHEN 5 THEN '広島県'
    ELSE '福岡県'
  END,
  JSON_OBJECT(
    'name', CONCAT('直近活動テスト地物 ', activity_number + 1),
    'test_data', 'profile-recent'
  ),
  UTC_TIMESTAMP() - INTERVAL (activity_number * 4) MINUTE,
  7040000000000000 + FLOOR(activity_number / 5),
  6000000000000001 + (activity_number % 4),
  CASE activity_number % 4
    WHEN 0 THEN 'Test Mapper Weekly'
    WHEN 1 THEN 'Test Mapper Sometimes'
    WHEN 2 THEN 'Test Mapper New'
    ELSE 'Test Mapper Boundary'
  END,
  IF(activity_number % 3 = 0, 'create', 'modify'),
  UTC_TIMESTAMP() - INTERVAL (activity_number * 4) MINUTE,
  6000000000000001 + (activity_number % 4),
  CASE activity_number % 4
    WHEN 0 THEN 'Test Mapper Weekly'
    WHEN 1 THEN 'Test Mapper Sometimes'
    WHEN 2 THEN 'Test Mapper New'
    ELSE 'Test Mapper Boundary'
  END
FROM recent_numbers
JOIN city_anchors
  ON city_anchors.prefecture_number = activity_number % 7
  AND city_anchors.city_number = FLOOR(activity_number / 7) % 5
WHERE @profile_seed_allowed
ON DUPLICATE KEY UPDATE
  latitude = VALUES(latitude),
  longitude = VALUES(longitude),
  prefecture = VALUES(prefecture),
  osm_timestamp = VALUES(osm_timestamp),
  created_osm_at = VALUES(created_osm_at);

-- Six dedicated mappers at each monthly level boundary. All timestamps stay
-- within the current JST calendar month, including when seeded on its first day.
INSERT IGNORE INTO osm_poi (
  osm_type, osm_id, name, category, category_value,
  latitude, longitude, prefecture, tags, osm_timestamp, changeset_id,
  editor_uid, editor_name, change_action,
  created_osm_at, creator_uid, creator_name
)
WITH RECURSIVE activity_numbers AS (
  SELECT 0 AS activity_number
  UNION ALL
  SELECT activity_number + 1 FROM activity_numbers WHERE activity_number < 499
), monthly_mappers AS (
  SELECT 1 AS level_number, 1 AS activity_count, 6000000000000011 AS editor_uid,
         'Monthly Lv1 - Starting' AS editor_name, 35.6812 AS latitude,
         139.7671 AS longitude, '東京都' AS prefecture
  UNION ALL SELECT 2, 10, 6000000000000012, 'Monthly Lv2 - Fair',
         35.4437, 139.6380, '神奈川県'
  UNION ALL SELECT 3, 50, 6000000000000013, 'Monthly Lv3 - Steady',
         35.1709, 136.8815, '愛知県'
  UNION ALL SELECT 4, 100, 6000000000000014, 'Monthly Lv4 - Many',
         34.6937, 135.5023, '大阪府'
  UNION ALL SELECT 5, 250, 6000000000000015, 'Monthly Lv5 - Amazing',
         34.3853, 132.4553, '広島県'
  UNION ALL SELECT 6, 500, 6000000000000016, 'Monthly Lv6 - Incredible',
         33.5902, 130.4017, '福岡県'
)
SELECT
  'node',
  8050000000000000 + monthly_mappers.level_number * 1000 + activity_number,
  CONCAT('月間Lv.', monthly_mappers.level_number, 'テスト地物 ', activity_number + 1),
  IF(activity_number % 2 = 0, 'shop', 'amenity'),
  IF(activity_number % 2 = 0, 'convenience', 'cafe'),
  monthly_mappers.latitude + ((MOD(activity_number, 20) - 10) / 10000),
  monthly_mappers.longitude + ((MOD(FLOOR(activity_number / 20), 20) - 10) / 10000),
  monthly_mappers.prefecture,
  JSON_OBJECT(
    'name', CONCAT('月間Lv.', monthly_mappers.level_number, 'テスト地物 ', activity_number + 1),
    'test_data', 'profile-monthly-level'
  ),
  TIMESTAMP(
    DATE_SUB(@current_jst_date, INTERVAL MOD(activity_number, DAY(@current_jst_date)) DAY),
    MAKETIME(MOD(activity_number, 16) + 6, MOD(activity_number * 7, 60), 0)
  ) - INTERVAL 9 HOUR,
  7050000000000000 + monthly_mappers.level_number * 1000 + FLOOR(activity_number / 5),
  monthly_mappers.editor_uid,
  monthly_mappers.editor_name,
  IF(activity_number % 3 = 0, 'create', 'modify'),
  TIMESTAMP(
    DATE_SUB(@current_jst_date, INTERVAL MOD(activity_number, DAY(@current_jst_date)) DAY),
    MAKETIME(MOD(activity_number, 16) + 6, MOD(activity_number * 7, 60), 0)
  ) - INTERVAL 9 HOUR,
  monthly_mappers.editor_uid,
  monthly_mappers.editor_name
FROM monthly_mappers
JOIN activity_numbers ON activity_number < monthly_mappers.activity_count
WHERE @profile_seed_allowed;

-- Nationwide directory mappers. These rows provide enough distinct profiles
-- to exercise the full Japan top-100 list without flooding the default
-- two-day map range. Coordinates use established city centers on land.
INSERT IGNORE INTO osm_poi (
  osm_type, osm_id, name, category, category_value,
  latitude, longitude, prefecture, tags, osm_timestamp, changeset_id,
  editor_uid, editor_name, change_action,
  created_osm_at, creator_uid, creator_name
)
WITH RECURSIVE mapper_numbers AS (
  SELECT 1 AS mapper_number
  UNION ALL
  SELECT mapper_number + 1 FROM mapper_numbers WHERE mapper_number < 110
), activity_numbers AS (
  SELECT 0 AS activity_number
  UNION ALL
  SELECT activity_number + 1 FROM activity_numbers WHERE activity_number < 168
), city_anchors AS (
  SELECT 0 AS anchor_number, 43.0618 AS latitude, 141.3545 AS longitude, '北海道' AS prefecture
  UNION ALL SELECT 1, 35.6896, 139.6917, '東京都'
  UNION ALL SELECT 2, 35.4658, 139.6223, '神奈川県'
  UNION ALL SELECT 3, 35.1709, 136.8815, '愛知県'
  UNION ALL SELECT 4, 34.6937, 135.5023, '大阪府'
  UNION ALL SELECT 5, 34.3853, 132.4553, '広島県'
  UNION ALL SELECT 6, 33.5902, 130.4017, '福岡県'
)
SELECT
  'node',
  8070000000000000 + mapper_numbers.mapper_number * 1000 + activity_numbers.activity_number,
  CONCAT('全国ランキングテスト地物 ', LPAD(mapper_numbers.mapper_number, 3, '0'), '-', activity_numbers.activity_number + 1),
  CASE activity_numbers.activity_number % 4
    WHEN 0 THEN 'shop'
    WHEN 1 THEN 'amenity'
    WHEN 2 THEN 'tourism'
    ELSE 'leisure'
  END,
  CASE activity_numbers.activity_number % 4
    WHEN 0 THEN 'convenience'
    WHEN 1 THEN 'cafe'
    WHEN 2 THEN 'information'
    ELSE 'park'
  END,
  city_anchors.latitude + ((MOD(mapper_numbers.mapper_number, 9) - 4) / 10000)
    + ((MOD(activity_numbers.activity_number, 3) - 1) / 20000),
  city_anchors.longitude + ((MOD(FLOOR(mapper_numbers.mapper_number / 9), 9) - 4) / 10000)
    + ((MOD(activity_numbers.activity_number, 4) - 1.5) / 20000),
  city_anchors.prefecture,
  JSON_OBJECT(
    'name', CONCAT('全国ランキングテスト地物 ', LPAD(mapper_numbers.mapper_number, 3, '0'), '-', activity_numbers.activity_number + 1),
    'test_data', 'profile-directory'
  ),
  UTC_TIMESTAMP()
    - INTERVAL (MOD(mapper_numbers.mapper_number * 7, 330) + 2) DAY
    - INTERVAL activity_numbers.activity_number HOUR,
  7060000000000000 + mapper_numbers.mapper_number * 10 + activity_numbers.activity_number,
  6000000000000100 + mapper_numbers.mapper_number,
  CONCAT('Sample Mapper ', LPAD(mapper_numbers.mapper_number, 3, '0')),
  IF(activity_numbers.activity_number % 3 = 0, 'create', 'modify'),
  UTC_TIMESTAMP()
    - INTERVAL (MOD(mapper_numbers.mapper_number * 7, 330) + 2) DAY
    - INTERVAL activity_numbers.activity_number HOUR,
  6000000000000100 + mapper_numbers.mapper_number,
  CONCAT('Sample Mapper ', LPAD(mapper_numbers.mapper_number, 3, '0'))
FROM mapper_numbers
JOIN activity_numbers
  ON activity_numbers.activity_number < 60 + MOD(110 - mapper_numbers.mapper_number, 110)
JOIN city_anchors
  ON city_anchors.anchor_number = MOD(mapper_numbers.mapper_number - 1, 7)
WHERE @profile_seed_allowed;

-- Generic tag-search fixtures. The way row confirms that the first version of
-- tag search stays node-only, as required by issue #1.
INSERT IGNORE INTO osm_poi (
  osm_type, osm_id, name, category, category_value,
  latitude, longitude, prefecture, tags, osm_timestamp, changeset_id,
  editor_uid, editor_name, change_action,
  created_osm_at, creator_uid, creator_name
) VALUES
  ('node', 8080000000000001, 'タグ検索テスト・ブランコ', 'leisure', 'playground',
   34.6937, 135.5023, '大阪府',
   JSON_OBJECT('name', 'タグ検索テスト・ブランコ', 'playground', 'swing', 'test_data', 'generic-tag-search'),
   UTC_TIMESTAMP() - INTERVAL 1 HOUR, 7080000000000001,
   6000000000000001, 'Test Mapper Weekly', 'create',
   UTC_TIMESTAMP() - INTERVAL 1 HOUR, 6000000000000001, 'Test Mapper Weekly'),
  ('node', 8080000000000002, 'タグ検索テスト・すべり台', 'leisure', 'playground',
   34.6940, 135.5026, '大阪府',
   JSON_OBJECT('name', 'タグ検索テスト・すべり台', 'playground', 'slide', 'test_data', 'generic-tag-search'),
   UTC_TIMESTAMP() - INTERVAL 2 HOUR, 7080000000000002,
   6000000000000002, 'Test Mapper Sometimes', 'modify',
   UTC_TIMESTAMP() - INTERVAL 2 DAY, 6000000000000002, 'Test Mapper Sometimes'),
  ('node', 8080000000000003, 'タグ検索テスト・史跡', 'historic', 'memorial',
   35.0116, 135.7681, '京都府',
   JSON_OBJECT('name', 'タグ検索テスト・史跡', 'historic', 'memorial', 'test_data', 'generic-tag-search'),
   UTC_TIMESTAMP() - INTERVAL 3 HOUR, 7080000000000003,
   6000000000000003, 'Test Mapper New', 'create',
   UTC_TIMESTAMP() - INTERVAL 3 HOUR, 6000000000000003, 'Test Mapper New'),
  ('way', 8080000000000004, 'タグ検索テスト・way', 'leisure', 'playground',
   34.6943, 135.5029, '大阪府',
   JSON_OBJECT('name', 'タグ検索テスト・way', 'playground', 'swing', 'test_data', 'generic-tag-search'),
   UTC_TIMESTAMP() - INTERVAL 4 HOUR, 7080000000000004,
   6000000000000004, 'Test Mapper Boundary', 'create',
   UTC_TIMESTAMP() - INTERVAL 4 HOUR, 6000000000000004, 'Test Mapper Boundary');

-- These three fixtures are in Osaka-shi Kita-ku.
UPDATE osm_poi
SET municipality_code = '271004', municipality_name = '大阪市',
    municipality_osm_id = 358674, ward_code = '271276',
    ward_name = '北区', ward_osm_id = 358681
WHERE (osm_type = 'node' AND osm_id IN (8080000000000001, 8080000000000002))
   OR (osm_type = 'way' AND osm_id = 8080000000000004);

INSERT INTO osm_sync_state (state_key, state_value)
SELECT 'profile_test_data_version', '9'
WHERE @profile_seed_allowed
ON DUPLICATE KEY UPDATE state_value = VALUES(state_value);
