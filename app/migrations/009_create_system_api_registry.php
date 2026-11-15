<?php

class Create_system_api_registry
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $db = $this->_lava->db;
        $columns = $db->raw('SHOW COLUMNS FROM `notifications`')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('target_type', $columns, true)) {
            $db->raw('ALTER TABLE `notifications` ADD COLUMN `target_type` VARCHAR(16) NULL AFTER `notification_type`');
        }
        if (!in_array('advisory_id', $columns, true)) {
            $db->raw('ALTER TABLE `notifications` ADD COLUMN `advisory_id` VARCHAR(128) NULL AFTER `report_id`');
        }
        $db->raw(
            "UPDATE `notifications` SET `target_type` = CASE " .
            "WHEN `report_id` IS NOT NULL THEN 'report' " .
            "WHEN `notification_type` = 'external_hazard' THEN 'advisory' " .
            "ELSE `target_type` END WHERE `target_type` IS NULL"
        );

        $apiColumns = $db->raw('SHOW COLUMNS FROM `external_api_configs`')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('rate_limit_until', $apiColumns, true)) {
            $db->raw('ALTER TABLE `external_api_configs` ADD COLUMN `rate_limit_until` DATETIME NULL AFTER `last_error_message`');
        }

        if (!$this->_lava->dbforge->table_exists('system_apis')) {
            $this->_lava->dbforge
                ->add_field([
                    'id' => ['type' => 'INT', 'unsigned' => TRUE, 'auto_increment' => TRUE, 'null' => FALSE],
                    'api_key' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => FALSE],
                    'api_name' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => FALSE],
                    'category' => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => FALSE],
                    'endpoint_url' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => FALSE],
                    'credential_encrypted' => ['type' => 'TEXT', 'null' => TRUE],
                    'auth_header_type' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'none', 'null' => FALSE],
                    'auth_header_name' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => TRUE],
                    'sync_interval_minutes' => ['type' => 'INT', 'default' => 60, 'null' => FALSE],
                    'custom_parameters_json' => ['type' => 'TEXT', 'null' => TRUE],
                    'is_enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1, 'null' => FALSE],
                    'is_custom' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'null' => FALSE],
                    'last_status' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'idle', 'null' => FALSE],
                    'last_http_status' => ['type' => 'SMALLINT', 'null' => TRUE],
                    'last_latency_ms' => ['type' => 'INT', 'null' => TRUE],
                    'rate_limit_until' => ['type' => 'DATETIME', 'null' => TRUE],
                    'last_synced_at' => ['type' => 'DATETIME', 'null' => TRUE],
                    'last_error_message' => ['type' => 'TEXT', 'null' => TRUE],
                    'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at' => ['type' => 'DATETIME', 'null' => TRUE],
                ])
                ->add_key('id', primary: TRUE)
                ->add_key('api_key', unique: TRUE, name: 'system_apis_api_key_unique')
                ->add_key('category', name: 'system_apis_category_idx')
                ->create_table('system_apis');
        }
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('system_apis');
    }
}
