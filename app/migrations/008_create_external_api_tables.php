<?php

class Create_external_api_tables
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if (!$this->_lava->dbforge->table_exists('external_api_configs')) {
            $this->_lava->dbforge
                ->add_field([
                    'id' => ['type' => 'INT', 'unsigned' => TRUE, 'auto_increment' => TRUE, 'null' => FALSE],
                    'api_key' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => FALSE],
                    'display_name' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => FALSE],
                    'endpoint_url' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => TRUE],
                    'is_enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1, 'null' => FALSE],
                    'sync_interval_minutes' => ['type' => 'INT', 'default' => 60, 'null' => FALSE],
                    'bounding_box_json' => ['type' => 'TEXT', 'null' => TRUE],
                    'min_magnitude' => ['type' => 'DECIMAL', 'constraint' => '3,1', 'default' => '2.5', 'null' => FALSE],
                    'alert_levels' => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => 'Green,Orange,Red', 'null' => FALSE],
                    'last_synced_at' => ['type' => 'DATETIME', 'null' => TRUE],
                    'last_status' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'idle', 'null' => FALSE],
                    'last_error_message' => ['type' => 'TEXT', 'null' => TRUE],
                    'records_cached' => ['type' => 'INT', 'default' => 0, 'null' => FALSE],
                    'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at' => ['type' => 'DATETIME', 'null' => TRUE],
                ])
                ->add_key('id', primary: TRUE)
                ->add_key('api_key', unique: TRUE, name: 'external_api_configs_api_key_unique')
                ->create_table('external_api_configs');
        }

        if (!$this->_lava->dbforge->table_exists('external_hazard_events')) {
            $this->_lava->dbforge
                ->add_field([
                    'id' => ['type' => 'INT', 'unsigned' => TRUE, 'auto_increment' => TRUE, 'null' => FALSE],
                    'source' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => FALSE],
                    'external_event_id' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => FALSE],
                    'hazard_type' => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => FALSE],
                    'title' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => FALSE],
                    'severity_level' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => TRUE],
                    'magnitude_or_score' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => TRUE],
                    'depth_km' => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => TRUE],
                    'latitude' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => TRUE],
                    'longitude' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => TRUE],
                    'distance_to_municipality_km' => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => TRUE],
                    'affected_population' => ['type' => 'INT', 'null' => TRUE],
                    'event_timestamp' => ['type' => 'DATETIME', 'null' => FALSE],
                    'raw_payload_json' => ['type' => 'TEXT', 'null' => TRUE],
                    'is_published_to_residents' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1, 'null' => FALSE],
                    'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at' => ['type' => 'DATETIME', 'null' => TRUE],
                ])
                ->add_key('id', primary: TRUE)
                ->add_key('external_event_id', unique: TRUE, name: 'external_hazard_events_external_event_id_unique')
                ->add_key('source', name: 'external_hazard_events_source_idx')
                ->create_table('external_hazard_events');
        }

        if (!$this->_lava->dbforge->table_exists('hdx_spatial_datasets')) {
            $this->_lava->dbforge
                ->add_field([
                    'id' => ['type' => 'INT', 'unsigned' => TRUE, 'auto_increment' => TRUE, 'null' => FALSE],
                    'dataset_slug' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => FALSE],
                    'resource_id' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => TRUE],
                    'title' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => FALSE],
                    'category' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => FALSE],
                    'geojson_or_summary_json' => ['type' => 'LONGTEXT', 'null' => TRUE],
                    'source_updated_at' => ['type' => 'DATETIME', 'null' => TRUE],
                    'is_active_layer' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1, 'null' => FALSE],
                    'synced_at' => ['type' => 'DATETIME', 'null' => TRUE],
                    'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
                ])
                ->add_key('id', primary: TRUE)
                ->add_key('dataset_slug', unique: TRUE, name: 'hdx_spatial_datasets_dataset_slug_unique')
                ->create_table('hdx_spatial_datasets');
        }
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('hdx_spatial_datasets');
        $this->_lava->dbforge->drop_table('external_hazard_events');
        $this->_lava->dbforge->drop_table('external_api_configs');
    }
}
