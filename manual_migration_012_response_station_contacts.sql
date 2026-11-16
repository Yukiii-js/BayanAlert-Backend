-- Add the four department contacts used by the report notification workflow.
-- Run against the BayanAlert database after the application schema is imported.

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stations' AND COLUMN_NAME = 'department_code') = 0,
    'ALTER TABLE stations ADD COLUMN department_code VARCHAR(12) NULL',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stations' AND COLUMN_NAME = 'contact_email') = 0,
    'ALTER TABLE stations ADD COLUMN contact_email VARCHAR(254) NULL',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stations' AND COLUMN_NAME = 'station_info') = 0,
    'ALTER TABLE stations ADD COLUMN station_info TEXT NULL',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stations' AND COLUMN_NAME = 'latitude') = 0,
    'ALTER TABLE stations ADD COLUMN latitude DECIMAL(10,7) NULL',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stations' AND COLUMN_NAME = 'longitude') = 0,
    'ALTER TABLE stations ADD COLUMN longitude DECIMAL(10,7) NULL',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stations' AND INDEX_NAME = 'stations_department_code_unique') = 0,
    'ALTER TABLE stations ADD UNIQUE KEY stations_department_code_unique (department_code)',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

DROP TEMPORARY TABLE IF EXISTS response_station_seed;
CREATE TEMPORARY TABLE response_station_seed (
    department_code VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
    station_name VARCHAR(100) NOT NULL,
    address_details VARCHAR(255) NOT NULL,
    contact_number VARCHAR(80) NOT NULL,
    contact_email VARCHAR(254) NOT NULL,
    station_info TEXT NOT NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL
);

INSERT INTO response_station_seed
    (department_code, station_name, address_details, contact_number, contact_email, station_info, latitude, longitude)
VALUES
    (
        'DOH',
        'Department of Health - Calapan',
        'Calapan City, Oriental Mindoro',
        '',
        'doh.calapan@gmail.com',
        'Medical and public health response contact for injury, illness, and health incidents.',
        NULL,
        NULL
    ),
    (
        'BFP',
        'Bureau of Fire Protection - Calapan City Fire Station',
        'Roxas Drive, Brgy. Guinobatan / City Hall Complex, Calapan City, Oriental Mindoro',
        '043-288-7777',
        'calapan.firedepartment@gmail.com',
        'Fire response and rescue. Additional hotline: 0915-603-1561.',
        13.3853000,
        121.1806000
    ),
    (
        'CDRRMO',
        'Calapan City DRRMD / Public Safety Rescue & Fire Unit',
        'Calapan City, Oriental Mindoro',
        '043-288-6111',
        'calapan.disastermanagement@gmail.com',
        'City disaster risk reduction, public safety, and rescue response. Additional hotline: 0999-735-6447.',
        NULL,
        NULL
    ),
    (
        'PCG',
        'Philippine Coast Guard - Calapan',
        'Calapan City, Oriental Mindoro',
        '',
        'coastguard.calapan@gmail.com',
        'Maritime, coastal, boat, drowning, and sea-rescue response contact.',
        NULL,
        NULL
    );

-- Retire only the known demo record; preserve its ID in case reports reference it.
UPDATE stations
SET is_active = 0
WHERE LOWER(TRIM(station_name)) = 'central response station'
  AND LOWER(TRIM(address_details)) = 'sample barangay'
  AND contact_number = '000-000-0000'
  AND department_code IS NULL;

INSERT INTO stations
    (department_code, station_name, address_details, contact_number, contact_email, station_info, latitude, longitude, is_active)
SELECT
    seed.department_code,
    seed.station_name,
    seed.address_details,
    seed.contact_number,
    seed.contact_email,
    seed.station_info,
    seed.latitude,
    seed.longitude,
    1
FROM response_station_seed AS seed
WHERE NOT EXISTS (
    SELECT 1
    FROM stations AS existing
    WHERE CONVERT(UPPER(TRIM(existing.department_code)) USING utf8mb4) COLLATE utf8mb4_unicode_ci
        = CONVERT(UPPER(TRIM(seed.department_code)) USING utf8mb4) COLLATE utf8mb4_unicode_ci
);

DROP TEMPORARY TABLE response_station_seed;
