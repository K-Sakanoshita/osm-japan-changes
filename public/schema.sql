CREATE TABLE IF NOT EXISTS osm_poi (
  osm_type ENUM('node','way','relation') NOT NULL,
  osm_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NULL,
  category VARCHAR(255) NOT NULL,
  category_value VARCHAR(255) NULL,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  prefecture VARCHAR(64) NULL,
  tags JSON NOT NULL,
  osm_timestamp DATETIME NOT NULL,
  changeset_id BIGINT UNSIGNED NOT NULL,
  editor_uid BIGINT UNSIGNED NULL,
  editor_name VARCHAR(255) NULL,
  change_action ENUM('create','modify') NOT NULL DEFAULT 'modify',
  created_osm_at DATETIME NULL,
  creator_uid BIGINT UNSIGNED NULL,
  creator_name VARCHAR(255) NULL,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (osm_type, osm_id),
  KEY osm_timestamp (osm_timestamp),
  KEY category (category),
  KEY prefecture (prefecture),
  KEY prefecture_timestamp (prefecture, osm_timestamp),
  KEY editor_timestamp (editor_uid, osm_timestamp),
  KEY category_value_timestamp (category, category_value, osm_timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CREATE TABLE IF NOT EXISTS does not update an existing table.
-- Add the prefecture column and index idempotently when importing this file
-- into a database created with an older schema.
SET @prefecture_column_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'osm_poi'
    AND COLUMN_NAME = 'prefecture'
);
SET @prefecture_column_sql = IF(
  @prefecture_column_exists = 0,
  'ALTER TABLE osm_poi ADD COLUMN prefecture VARCHAR(64) NULL AFTER longitude',
  'SELECT 1'
);
PREPARE prefecture_column_statement FROM @prefecture_column_sql;
EXECUTE prefecture_column_statement;
DEALLOCATE PREPARE prefecture_column_statement;

SET @prefecture_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'osm_poi'
    AND INDEX_NAME = 'prefecture'
);
SET @prefecture_index_sql = IF(
  @prefecture_index_exists = 0,
  'ALTER TABLE osm_poi ADD INDEX prefecture (prefecture)',
  'SELECT 1'
);
PREPARE prefecture_index_statement FROM @prefecture_index_sql;
EXECUTE prefecture_index_statement;
DEALLOCATE PREPARE prefecture_index_statement;

-- Composite indexes used by api.php filters and date ranges.
SET @api_index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'osm_poi'
    AND INDEX_NAME = 'prefecture_timestamp'
);
SET @api_index_sql = IF(
  @api_index_exists = 0,
  'ALTER TABLE osm_poi ADD INDEX prefecture_timestamp (prefecture, osm_timestamp)',
  'SELECT 1'
);
PREPARE api_index_statement FROM @api_index_sql;
EXECUTE api_index_statement;
DEALLOCATE PREPARE api_index_statement;

SET @api_index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'osm_poi'
    AND INDEX_NAME = 'editor_timestamp'
);
SET @api_index_sql = IF(
  @api_index_exists = 0,
  'ALTER TABLE osm_poi ADD INDEX editor_timestamp (editor_uid, osm_timestamp)',
  'SELECT 1'
);
PREPARE api_index_statement FROM @api_index_sql;
EXECUTE api_index_statement;
DEALLOCATE PREPARE api_index_statement;

SET @api_index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'osm_poi'
    AND INDEX_NAME = 'category_value_timestamp'
);
SET @api_index_sql = IF(
  @api_index_exists = 0,
  'ALTER TABLE osm_poi ADD INDEX category_value_timestamp (category, category_value, osm_timestamp)',
  'SELECT 1'
);
PREPARE api_index_statement FROM @api_index_sql;
EXECUTE api_index_statement;
DEALLOCATE PREPARE api_index_statement;

CREATE TABLE IF NOT EXISTS osm_sync_state (
  state_key VARCHAR(64) PRIMARY KEY,
  state_value VARCHAR(255) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Mapper profiles cover only features collected in osm_poi. The batch process
-- rebuilds these rolling one-year summaries; they are not lifetime OSM totals.
CREATE TABLE IF NOT EXISTS mapper_profile_stats (
  editor_uid BIGINT UNSIGNED NOT NULL,
  editor_name VARCHAR(255) NOT NULL,
  period_start DATETIME NOT NULL,
  period_end DATETIME NOT NULL,
  total_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  create_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  modify_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  changeset_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  active_day_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  active_week_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  active_month_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  prefecture_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  category_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  current_week_streak TINYINT UNSIGNED NOT NULL DEFAULT 0,
  longest_week_streak TINYINT UNSIGNED NOT NULL DEFAULT 0,
  current_month_streak TINYINT UNSIGNED NOT NULL DEFAULT 0,
  longest_month_streak TINYINT UNSIGNED NOT NULL DEFAULT 0,
  first_activity_at DATETIME NULL,
  last_activity_at DATETIME NULL,
  calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (editor_uid),
  KEY editor_name (editor_name),
  KEY last_activity (last_activity_at),
  KEY calculated_at (calculated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @profile_name_index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mapper_profile_stats'
    AND INDEX_NAME = 'editor_name'
);
SET @profile_name_index_sql = IF(
  @profile_name_index_exists = 0,
  'ALTER TABLE mapper_profile_stats ADD INDEX editor_name (editor_name)',
  'SELECT 1'
);
PREPARE profile_name_index_statement FROM @profile_name_index_sql;
EXECUTE profile_name_index_statement;
DEALLOCATE PREPARE profile_name_index_statement;

-- Public OSM profile image URLs are refreshed by profile-sync.php. Keeping
-- this cache separate prevents the regular profile-stat rebuild from causing
-- another OSM API request for every mapper.
CREATE TABLE IF NOT EXISTS mapper_profile_avatars (
  editor_uid BIGINT UNSIGNED NOT NULL,
  avatar_url VARCHAR(2048) NULL,
  checked_at DATETIME NOT NULL,
  PRIMARY KEY (editor_uid),
  KEY checked_at (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- week_start is a Monday in Japan time. Keep 53-54 rows per mapper so the
-- batch can recalculate the boundary while the API exposes at most 52 weeks.
CREATE TABLE IF NOT EXISTS mapper_activity_weeks (
  editor_uid BIGINT UNSIGNED NOT NULL,
  week_start DATE NOT NULL,
  total_count INT UNSIGNED NOT NULL DEFAULT 0,
  create_count INT UNSIGNED NOT NULL DEFAULT 0,
  modify_count INT UNSIGNED NOT NULL DEFAULT 0,
  active_day_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  first_activity_at DATETIME NOT NULL,
  last_activity_at DATETIME NOT NULL,
  calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (editor_uid, week_start),
  KEY week_start (week_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- month_start is the first day of a calendar month in Japan time. Keep 13
-- rows per mapper so the batch can recalculate the boundary while the API
-- exposes at most 12 months.
CREATE TABLE IF NOT EXISTS mapper_activity_months (
  editor_uid BIGINT UNSIGNED NOT NULL,
  month_start DATE NOT NULL,
  total_count INT UNSIGNED NOT NULL DEFAULT 0,
  create_count INT UNSIGNED NOT NULL DEFAULT 0,
  modify_count INT UNSIGNED NOT NULL DEFAULT 0,
  active_day_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  first_activity_at DATETIME NOT NULL,
  last_activity_at DATETIME NOT NULL,
  calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (editor_uid, month_start),
  KEY month_start (month_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Top categories and prefectures are materialized by the profile batch so a
-- profile request does not need GROUP BY queries over osm_poi.
CREATE TABLE IF NOT EXISTS mapper_profile_categories (
  editor_uid BIGINT UNSIGNED NOT NULL,
  category VARCHAR(255) NOT NULL,
  category_value VARCHAR(255) NOT NULL DEFAULT '',
  total_count INT UNSIGNED NOT NULL DEFAULT 0,
  create_count INT UNSIGNED NOT NULL DEFAULT 0,
  modify_count INT UNSIGNED NOT NULL DEFAULT 0,
  calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (editor_uid, category, category_value),
  KEY editor_count (editor_uid, total_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mapper_profile_prefectures (
  editor_uid BIGINT UNSIGNED NOT NULL,
  prefecture VARCHAR(64) NOT NULL,
  total_count INT UNSIGNED NOT NULL DEFAULT 0,
  create_count INT UNSIGNED NOT NULL DEFAULT 0,
  modify_count INT UNSIGNED NOT NULL DEFAULT 0,
  active_day_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (editor_uid, prefecture),
  KEY editor_count (editor_uid, total_count),
  KEY prefecture_count (prefecture, total_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @profile_prefecture_active_days_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mapper_profile_prefectures'
    AND COLUMN_NAME = 'active_day_count'
);
SET @profile_prefecture_active_days_sql = IF(
  @profile_prefecture_active_days_exists = 0,
  'ALTER TABLE mapper_profile_prefectures ADD COLUMN active_day_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER modify_count',
  'SELECT 1'
);
PREPARE profile_prefecture_active_days_statement FROM @profile_prefecture_active_days_sql;
EXECUTE profile_prefecture_active_days_statement;
DEALLOCATE PREPARE profile_prefecture_active_days_statement;

SET @profile_prefecture_count_index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mapper_profile_prefectures'
    AND INDEX_NAME = 'prefecture_count'
);
SET @profile_prefecture_count_index_sql = IF(
  @profile_prefecture_count_index_exists = 0,
  'ALTER TABLE mapper_profile_prefectures ADD INDEX prefecture_count (prefecture, total_count)',
  'SELECT 1'
);
PREPARE profile_prefecture_count_index_statement FROM @profile_prefecture_count_index_sql;
EXECUTE profile_prefecture_count_index_statement;
DEALLOCATE PREPARE profile_prefecture_count_index_statement;

-- Badge definitions live in backend code. Earned badges remain here even if a
-- definition is later retired or hidden. earned_at never changes; progress is
-- updated separately. Backfilled awards use acquisition_source='backfill' so
-- the UI can label their date as "confirmed" rather than "earned".
CREATE TABLE IF NOT EXISTS mapper_badges (
  editor_uid BIGINT UNSIGNED NOT NULL,
  badge_key VARCHAR(64) NOT NULL,
  earned_at DATETIME NOT NULL,
  progress_updated_at DATETIME NOT NULL,
  progress_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
  badge_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  acquisition_source ENUM('live','backfill','manual') NOT NULL DEFAULT 'live',
  revoked_at DATETIME NULL,
  revoked_reason VARCHAR(255) NULL,
  PRIMARY KEY (editor_uid, badge_key),
  KEY badge_earned (badge_key, earned_at),
  KEY earned_at (earned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
