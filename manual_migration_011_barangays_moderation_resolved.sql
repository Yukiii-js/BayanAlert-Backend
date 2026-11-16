-- Calapan barangay locations and report/user moderation fields.
-- Run against the BayanAlert database after the existing application schema is imported.

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barangays' AND COLUMN_NAME = 'latitude') = 0,
    'ALTER TABLE barangays ADD COLUMN latitude DECIMAL(10,7) NULL',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barangays' AND COLUMN_NAME = 'longitude') = 0,
    'ALTER TABLE barangays ADD COLUMN longitude DECIMAL(10,7) NULL',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'warning_strikes') = 0,
    'ALTER TABLE users ADD COLUMN warning_strikes INT NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hazard_reports' AND COLUMN_NAME = 'latitude') = 0,
    'ALTER TABLE hazard_reports ADD COLUMN latitude DECIMAL(10,7) NULL',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hazard_reports' AND COLUMN_NAME = 'longitude') = 0,
    'ALTER TABLE hazard_reports ADD COLUMN longitude DECIMAL(10,7) NULL',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hazard_reports' AND COLUMN_NAME = 'severity_level') = 0,
    'ALTER TABLE hazard_reports ADD COLUMN severity_level VARCHAR(16) NULL DEFAULT NULL',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'status') = 0,
    'ALTER TABLE users ADD COLUMN status ENUM(''active'',''suspended'') NOT NULL DEFAULT ''active''',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'suspension_reason') = 0,
    'ALTER TABLE users ADD COLUMN suspension_reason TEXT NULL',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hazard_reports' AND COLUMN_NAME = 'resolved_at') = 0,
    'ALTER TABLE hazard_reports ADD COLUMN resolved_at DATETIME NULL',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
INTO @report_status_type, @report_status_nullable, @report_status_default
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'hazard_reports'
  AND COLUMN_NAME = 'status';

SET @migration_sql = IF(
    LOWER(@report_status_type) LIKE 'enum(%'
        AND LOCATE('resolved', LOWER(@report_status_type)) = 0,
    CONCAT(
        'ALTER TABLE hazard_reports MODIFY status ',
        LEFT(@report_status_type, CHAR_LENGTH(@report_status_type) - 1),
        ',''resolved'')',
        IF(@report_status_nullable = 'YES', ' NULL', ' NOT NULL'),
        IF(@report_status_default IS NULL, '', CONCAT(' DEFAULT ', QUOTE(@report_status_default)))
    ),
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

DROP TEMPORARY TABLE IF EXISTS calapan_barangay_seed;
CREATE TEMPORARY TABLE calapan_barangay_seed (
    barangay_name VARCHAR(100) NOT NULL PRIMARY KEY,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL
);

INSERT INTO calapan_barangay_seed (barangay_name, latitude, longitude) VALUES
('Balingayan', 13.3284678, 121.1399523),
('Balite', 13.4069843, 121.1578252),
('Baruyan', 13.3876277, 121.1340704),
('Batino', 13.3560373, 121.2199172),
('Bayanan I', 13.3673557, 121.1702601),
('Bayanan II', 13.3529639, 121.1698264),
('Biga', 13.3200417, 121.1741510),
('Bondoc', 13.3843938, 121.1953169),
('Bucayao', 13.3089623, 121.1864217),
('Buhuan', 13.3113549, 121.2197567),
('Bulusan', 13.4033126, 121.1971425),
('Calero', 13.4159276, 121.1823090),
('Camansihan', 13.3344193, 121.2271941),
('Camilmil', 13.4060967, 121.1744147),
('Canubing I', 13.3598566, 121.1355484),
('Canubing II', 13.3165691, 121.1227196),
('Comunal', 13.3141250, 121.1582518),
('Guinobatan', 13.3859836, 121.1803017),
('Gulod', 13.3477529, 121.2085092),
('Gutad', 13.3643443, 121.2382162),
('Ibaba East', 13.4146264, 121.1789600),
('Ibaba West', 13.4140004, 121.1755944),
('Ilaya', 13.4128489, 121.1867898),
('Lalud', 13.4000425, 121.1706879),
('Lazareto', 13.4305008, 121.1989270),
('Libis', 13.4146851, 121.1835875),
('Lumang Bayan', 13.3992930, 121.1802346),
('Mahal na Pangalan', 13.4018771, 121.1509732),
('Maidlang', 13.3843787, 121.2313903),
('Malad', 13.3396497, 121.1588359),
('Malamig', 13.3436372, 121.1442130),
('Managpi', 13.3301340, 121.1963774),
('Masipit', 13.3883764, 121.1562155),
('Nag-iba I', 13.3444779, 121.2759363),
('Nag-iba II', 13.3503514, 121.2620124),
('Navotas', 13.3738681, 121.2486196),
('Pachoca', 13.4088818, 121.1658955),
('Palhi', 13.3794582, 121.2109053),
('Panggalaan', 13.3077362, 121.2027606),
('Parang', 13.4000125, 121.2131443),
('Patas', 13.3418543, 121.1209818),
('Personas', 13.3065072, 121.1408265),
('Putingtubig', 13.3470195, 121.1887185),
('Salong', 13.4192312, 121.1907996),
('San Antonio', 13.4258353, 121.1958725),
('San Vicente Central', 13.4120187, 121.1785322),
('San Vicente East', 13.4098693, 121.1800890),
('San Vicente North', 13.4131391, 121.1789648),
('San Vicente South', 13.4097574, 121.1778505),
('San Vicente West', 13.4122502, 121.1766916),
('Santa Cruz', 13.3238549, 121.2402355),
('Santa Isabel', 13.3653512, 121.1576924),
('Santa Maria Village', 13.4091916, 121.1734839),
('Santa Rita', 13.3437098, 121.1316771),
('Santo Niño', 13.4066283, 121.1847293),
('Sapul', 13.3651286, 121.1861761),
('Silonay', 13.3995117, 121.2251417),
('Suqui', 13.4161586, 121.2026132),
('Tawagan', 13.3725954, 121.1476435),
('Tawiran', 13.3914634, 121.1680619),
('Tibag', 13.4121624, 121.1738954),
('Wawa', 13.4029753, 121.1451075);

UPDATE barangays AS b
INNER JOIN calapan_barangay_seed AS s
    ON CONVERT(LOWER(TRIM(b.barangay_name)) USING utf8mb4) COLLATE utf8mb4_unicode_ci
        = CONVERT(LOWER(TRIM(s.barangay_name)) USING utf8mb4) COLLATE utf8mb4_unicode_ci
SET b.latitude = s.latitude,
    b.longitude = s.longitude,
    b.is_active = 1;

INSERT INTO barangays (barangay_name, is_active, latitude, longitude)
SELECT s.barangay_name, 1, s.latitude, s.longitude
FROM calapan_barangay_seed AS s
WHERE NOT EXISTS (
    SELECT 1
    FROM barangays AS b
    WHERE CONVERT(LOWER(TRIM(b.barangay_name)) USING utf8mb4) COLLATE utf8mb4_unicode_ci
        = CONVERT(LOWER(TRIM(s.barangay_name)) USING utf8mb4) COLLATE utf8mb4_unicode_ci
);

DROP TEMPORARY TABLE calapan_barangay_seed;
