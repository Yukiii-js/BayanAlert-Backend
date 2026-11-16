-- Provision registration OTP storage and pre-registration verification state.
-- Run against the BayanAlert database before deploying OTP registration.

CREATE TABLE IF NOT EXISTS `otp_verifications` (
    `otp_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NULL DEFAULT NULL,
    `email` VARCHAR(150) NULL DEFAULT NULL,
    `code_hash` VARCHAR(255) NOT NULL,
    `verification_type` VARCHAR(32) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL DEFAULT NULL,
    `attempt_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`otp_id`),
    KEY `otp_verifications_email_type_created_idx` (`email`, `verification_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @bayanalert_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_verifications' AND COLUMN_NAME = 'email') = 0,
    'ALTER TABLE `otp_verifications` ADD COLUMN `email` VARCHAR(150) NULL DEFAULT NULL AFTER `user_id`',
    'SELECT 1'
);
PREPARE bayanalert_stmt FROM @bayanalert_sql;
EXECUTE bayanalert_stmt;
DEALLOCATE PREPARE bayanalert_stmt;

CREATE TABLE IF NOT EXISTS `registration_email_verifications` (
    `verification_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(150) NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `verified_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`verification_id`),
    UNIQUE KEY `registration_email_verification_email_unique` (`email`),
    UNIQUE KEY `registration_email_verification_token_unique` (`token_hash`),
    KEY `registration_email_verification_expiry_idx` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @bayanalert_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_verifications' AND COLUMN_NAME = 'used_at') = 0,
    'ALTER TABLE `otp_verifications` ADD COLUMN `used_at` DATETIME NULL DEFAULT NULL AFTER `expires_at`',
    'SELECT 1'
);
PREPARE bayanalert_stmt FROM @bayanalert_sql;
EXECUTE bayanalert_stmt;
DEALLOCATE PREPARE bayanalert_stmt;

SET @bayanalert_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_verifications' AND COLUMN_NAME = 'attempt_count') = 0,
    'ALTER TABLE `otp_verifications` ADD COLUMN `attempt_count` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `used_at`',
    'SELECT 1'
);
PREPARE bayanalert_stmt FROM @bayanalert_sql;
EXECUTE bayanalert_stmt;
DEALLOCATE PREPARE bayanalert_stmt;

SET @bayanalert_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_verifications' AND COLUMN_NAME = 'created_at') = 0,
    'ALTER TABLE `otp_verifications` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'SELECT 1'
);
PREPARE bayanalert_stmt FROM @bayanalert_sql;
EXECUTE bayanalert_stmt;
DEALLOCATE PREPARE bayanalert_stmt;

ALTER TABLE `otp_verifications`
    MODIFY `user_id` INT UNSIGNED NULL DEFAULT NULL,
    MODIFY `code_hash` VARCHAR(255) NOT NULL,
    MODIFY `verification_type` VARCHAR(32) NOT NULL;

SET @bayanalert_sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_verifications'
       AND INDEX_NAME = 'otp_verifications_email_type_created_idx') = 0,
    'ALTER TABLE `otp_verifications` ADD KEY `otp_verifications_email_type_created_idx` (`email`, `verification_type`, `created_at`)',
    'SELECT 1'
);
PREPARE bayanalert_stmt FROM @bayanalert_sql;
EXECUTE bayanalert_stmt;
DEALLOCATE PREPARE bayanalert_stmt;
