-- Preserve incident and audit rows when a user account is deleted.
-- Run against the BayanAlert database before deploying the user-delete workflow.

DELIMITER $$

DROP PROCEDURE IF EXISTS `bayanalert_migrate_user_delete_foreign_keys`$$
CREATE PROCEDURE `bayanalert_migrate_user_delete_foreign_keys`()
BEGIN
    DECLARE finished TINYINT DEFAULT 0;
    DECLARE table_name_value VARCHAR(64);
    DECLARE column_name_value VARCHAR(64);
    DECLARE constraint_name_value VARCHAR(64);
    DECLARE column_type_value VARCHAR(255);
    DECLARE nullable_value VARCHAR(3);
    DECLARE delete_rule_value VARCHAR(20);
    DECLARE update_rule_value VARCHAR(20);
    DECLARE new_constraint_value VARCHAR(64);
    DECLARE desired_delete_rule VARCHAR(20);
    DECLARE history_table_value VARCHAR(64);
    DECLARE history_column_value VARCHAR(64);
    DECLARE history_column_type_value VARCHAR(255);
    DECLARE history_nullable_value VARCHAR(3);

    DECLARE foreign_keys CURSOR FOR
        SELECT
            kcu.TABLE_NAME,
            kcu.COLUMN_NAME,
            kcu.CONSTRAINT_NAME,
            c.COLUMN_TYPE,
            c.IS_NULLABLE,
            rc.DELETE_RULE,
            rc.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE AS kcu
        INNER JOIN information_schema.COLUMNS AS c
            ON c.TABLE_SCHEMA = kcu.TABLE_SCHEMA
            AND c.TABLE_NAME = kcu.TABLE_NAME
            AND c.COLUMN_NAME = kcu.COLUMN_NAME
        INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS AS rc
            ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
            AND rc.TABLE_NAME = kcu.TABLE_NAME
            AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
        WHERE kcu.TABLE_SCHEMA = DATABASE()
            AND kcu.REFERENCED_TABLE_NAME = 'users'
            AND kcu.REFERENCED_COLUMN_NAME = 'user_id'
        ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION;

    DECLARE historical_columns CURSOR FOR
        SELECT targets.TABLE_NAME, targets.COLUMN_NAME, c.COLUMN_TYPE, c.IS_NULLABLE
        FROM (
            SELECT 'hazard_reports' AS TABLE_NAME, 'user_id' AS COLUMN_NAME
            UNION ALL SELECT 'report_verifications', 'verified_by'
            UNION ALL SELECT 'report_verifications', 'verifier_id'
            UNION ALL SELECT 'report_verifications', 'user_id'
            UNION ALL SELECT 'report_assignments', 'assigned_by'
            UNION ALL SELECT 'report_assignments', 'assigned_to'
            UNION ALL SELECT 'resolutions', 'resolved_by'
            UNION ALL SELECT 'resolutions', 'confirmed_by'
            UNION ALL SELECT 'resolutions', 'user_id'
            UNION ALL SELECT 'report_status_history', 'changed_by'
            UNION ALL SELECT 'report_status_history', 'user_id'
            UNION ALL SELECT 'report_evidence', 'uploaded_by'
            UNION ALL SELECT 'duplicate_report_checks', 'checked_by'
            UNION ALL SELECT 'report_community_votes', 'user_id'
        ) AS targets
        INNER JOIN information_schema.COLUMNS AS c
            ON c.TABLE_SCHEMA = DATABASE()
            AND c.TABLE_NAME = targets.TABLE_NAME
            AND c.COLUMN_NAME = targets.COLUMN_NAME;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET finished = 1;

    OPEN foreign_keys;

    foreign_key_loop: LOOP
        FETCH foreign_keys INTO
            table_name_value,
            column_name_value,
            constraint_name_value,
            column_type_value,
            nullable_value,
            delete_rule_value,
            update_rule_value;

        IF finished = 1 THEN
            LEAVE foreign_key_loop;
        END IF;

        IF table_name_value IN (
            'notifications',
            'otp_verifications',
            'email_verification_tokens',
            'password_reset_tokens'
        ) THEN
            SET desired_delete_rule = 'CASCADE';
        ELSE
            SET desired_delete_rule = 'SET NULL';
        END IF;

        IF delete_rule_value <> desired_delete_rule
            OR (desired_delete_rule = 'SET NULL' AND nullable_value <> 'YES') THEN
            SET new_constraint_value = CONCAT(
                'fk_user_',
                LEFT(MD5(CONCAT(table_name_value, '.', column_name_value)), 24),
                '_delete'
            );

            SET @bayanalert_sql = CONCAT(
                'ALTER TABLE `', REPLACE(table_name_value, '`', '``'),
                '` DROP FOREIGN KEY `', REPLACE(constraint_name_value, '`', '``'), '`'
            );

            IF desired_delete_rule = 'SET NULL' AND nullable_value <> 'YES' THEN
                SET @bayanalert_sql = CONCAT(
                    @bayanalert_sql,
                    ', MODIFY COLUMN `', REPLACE(column_name_value, '`', '``'), '` ',
                    column_type_value, ' NULL DEFAULT NULL'
                );
            END IF;

            SET @bayanalert_sql = CONCAT(
                @bayanalert_sql,
                ', ADD CONSTRAINT `', new_constraint_value,
                '` FOREIGN KEY (`', REPLACE(column_name_value, '`', '``'),
                '`) REFERENCES `users` (`user_id`) ON DELETE ',
                desired_delete_rule,
                ' ON UPDATE ', update_rule_value
            );

            PREPARE bayanalert_statement FROM @bayanalert_sql;
            EXECUTE bayanalert_statement;
            DEALLOCATE PREPARE bayanalert_statement;
        END IF;
    END LOOP;

    CLOSE foreign_keys;

    SET finished = 0;
    OPEN historical_columns;

    historical_column_loop: LOOP
        FETCH historical_columns INTO
            history_table_value,
            history_column_value,
            history_column_type_value,
            history_nullable_value;

        IF finished = 1 THEN
            LEAVE historical_column_loop;
        END IF;

        IF history_nullable_value <> 'YES' THEN
            SET @bayanalert_sql = CONCAT(
                'ALTER TABLE `', REPLACE(history_table_value, '`', '``'),
                '` MODIFY COLUMN `', REPLACE(history_column_value, '`', '``'), '` ',
                history_column_type_value, ' NULL DEFAULT NULL'
            );
            PREPARE bayanalert_statement FROM @bayanalert_sql;
            EXECUTE bayanalert_statement;
            DEALLOCATE PREPARE bayanalert_statement;
        END IF;
    END LOOP;

    CLOSE historical_columns;
END$$

CALL `bayanalert_migrate_user_delete_foreign_keys`()$$
DROP PROCEDURE `bayanalert_migrate_user_delete_foreign_keys`$$

DELIMITER ;
