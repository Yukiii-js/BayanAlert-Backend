-- Idempotent MySQL schema setup for the external API feed on an existing database.
-- Run against the BayanAlert database before deploying the external hazard feed.

CREATE TABLE IF NOT EXISTS `notifications` (
    `notification_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NULL DEFAULT NULL,
    `report_id` INT UNSIGNED NULL DEFAULT NULL,
    `advisory_id` VARCHAR(128) NULL,
    `title` VARCHAR(200) NOT NULL,
    `message` TEXT NOT NULL,
    `notification_type` VARCHAR(50) NOT NULL DEFAULT 'system',
    `target_type` VARCHAR(16) NULL,
    `is_read` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`notification_id`),
    KEY `notifications_user_id_idx` (`user_id`),
    KEY `notifications_report_id_idx` (`report_id`),
    KEY `notifications_created_at_idx` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `notifications`
    MODIFY `notification_type` VARCHAR(50) NOT NULL DEFAULT 'system';

SET @bayanalert_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'advisory_id') = 0,
    'ALTER TABLE `notifications` ADD COLUMN `advisory_id` VARCHAR(128) NULL AFTER `report_id`',
    'SELECT 1'
);
PREPARE bayanalert_stmt FROM @bayanalert_sql;
EXECUTE bayanalert_stmt;
DEALLOCATE PREPARE bayanalert_stmt;

SET @bayanalert_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'target_type') = 0,
    'ALTER TABLE `notifications` ADD COLUMN `target_type` VARCHAR(16) NULL AFTER `notification_type`',
    'SELECT 1'
);
PREPARE bayanalert_stmt FROM @bayanalert_sql;
EXECUTE bayanalert_stmt;
DEALLOCATE PREPARE bayanalert_stmt;

UPDATE `notifications`
SET `target_type` = CASE
    WHEN `report_id` IS NOT NULL THEN 'report'
    WHEN `notification_type` = 'external_hazard' THEN 'advisory'
    ELSE `target_type`
END
WHERE `target_type` IS NULL;

CREATE TABLE IF NOT EXISTS `external_api_configs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `api_key` VARCHAR(32) NOT NULL,
    `display_name` VARCHAR(120) NOT NULL,
    `endpoint_url` VARCHAR(255) NULL,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `sync_interval_minutes` INT NOT NULL DEFAULT 60,
    `bounding_box_json` TEXT NULL,
    `min_magnitude` DECIMAL(3,1) NOT NULL DEFAULT 2.5,
    `alert_levels` VARCHAR(64) NOT NULL DEFAULT 'Green,Orange,Red',
    `last_synced_at` DATETIME NULL,
    `last_status` VARCHAR(16) NOT NULL DEFAULT 'idle',
    `last_error_message` TEXT NULL,
    `rate_limit_until` DATETIME NULL,
    `records_cached` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `external_api_configs_api_key_unique` (`api_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @bayanalert_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'external_api_configs' AND COLUMN_NAME = 'rate_limit_until') = 0,
    'ALTER TABLE `external_api_configs` ADD COLUMN `rate_limit_until` DATETIME NULL AFTER `last_error_message`',
    'SELECT 1'
);
PREPARE bayanalert_stmt FROM @bayanalert_sql;
EXECUTE bayanalert_stmt;
DEALLOCATE PREPARE bayanalert_stmt;

CREATE TABLE IF NOT EXISTS `external_hazard_events` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source` VARCHAR(16) NOT NULL,
    `external_event_id` VARCHAR(128) NOT NULL,
    `hazard_type` VARCHAR(24) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `severity_level` VARCHAR(16) NULL,
    `magnitude_or_score` DECIMAL(5,2) NULL,
    `depth_km` DECIMAL(6,2) NULL,
    `latitude` DECIMAL(10,7) NULL,
    `longitude` DECIMAL(10,7) NULL,
    `distance_to_municipality_km` DECIMAL(8,2) NULL,
    `affected_population` INT NULL,
    `event_timestamp` DATETIME NOT NULL,
    `raw_payload_json` TEXT NULL,
    `is_published_to_residents` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `external_hazard_events_external_event_id_unique` (`external_event_id`),
    KEY `external_hazard_events_source_idx` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `external_hazard_events`
    MODIFY `hazard_type` VARCHAR(24) NOT NULL;

CREATE TABLE IF NOT EXISTS `hdx_spatial_datasets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `dataset_slug` VARCHAR(120) NOT NULL,
    `resource_id` VARCHAR(120) NULL,
    `title` VARCHAR(255) NOT NULL,
    `category` VARCHAR(40) NOT NULL,
    `geojson_or_summary_json` LONGTEXT NULL,
    `source_updated_at` DATETIME NULL,
    `is_active_layer` TINYINT(1) NOT NULL DEFAULT 1,
    `synced_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `hdx_spatial_datasets_dataset_slug_unique` (`dataset_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `system_apis` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `api_key` VARCHAR(64) NOT NULL,
    `api_name` VARCHAR(120) NOT NULL,
    `category` VARCHAR(24) NOT NULL,
    `endpoint_url` VARCHAR(500) NOT NULL,
    `credential_encrypted` TEXT NULL,
    `auth_header_type` VARCHAR(20) NOT NULL DEFAULT 'none',
    `auth_header_name` VARCHAR(80) NULL,
    `sync_interval_minutes` INT NOT NULL DEFAULT 60,
    `custom_parameters_json` TEXT NULL,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `is_custom` TINYINT(1) NOT NULL DEFAULT 0,
    `last_status` VARCHAR(16) NOT NULL DEFAULT 'idle',
    `last_http_status` SMALLINT NULL,
    `last_latency_ms` INT NULL,
    `rate_limit_until` DATETIME NULL,
    `last_synced_at` DATETIME NULL,
    `last_error_message` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `system_apis_api_key_unique` (`api_key`),
    KEY `system_apis_category_idx` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
