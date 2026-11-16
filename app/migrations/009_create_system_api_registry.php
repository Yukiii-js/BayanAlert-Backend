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
        $dbforge = $this->_lava->dbforge;
        if ($dbforge->table_exists('notifications')) {
            if (!$dbforge->column_exists('notifications', 'target_type')) {
                $dbforge->add_column('notifications', [
                    'target_type' => [
                        'type' => 'VARCHAR',
                        'constraint' => 16,
                        'null' => TRUE,
                        'after' => 'notification_type',
                    ],
                ]);
            }
            if (!$dbforge->column_exists('notifications', 'advisory_id')) {
                $dbforge->add_column('notifications', [
                    'advisory_id' => [
                        'type' => 'VARCHAR',
                        'constraint' => 128,
                        'null' => TRUE,
                        'after' => 'report_id',
                    ],
                ]);
            }

            $db->table('notifications')
                ->where_null('target_type')
                ->where_not_null('report_id')
                ->update(['target_type' => 'report']);
            $db->table('notifications')
                ->where_null('target_type')
                ->where('notification_type', 'external_hazard')
                ->update(['target_type' => 'advisory']);
        }

        if ($dbforge->table_exists('external_api_configs')
            && !$dbforge->column_exists('external_api_configs', 'rate_limit_until')) {
            $dbforge->add_column('external_api_configs', [
                'rate_limit_until' => [
                    'type' => 'DATETIME',
                    'null' => TRUE,
                    'after' => 'last_error_message',
                ],
            ]);
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
