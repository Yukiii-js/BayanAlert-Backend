<?php

class Add_external_api_sync_lock
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $dbforge = $this->_lava->dbforge;
        if (!$dbforge->table_exists('system_apis')) {
            throw new RuntimeException('The system_apis table must exist before sync locks can be added.');
        }

        if (!$dbforge->column_exists('system_apis', 'sync_lock_token')) {
            $dbforge->add_column('system_apis', [
                'sync_lock_token' => [
                    'type' => 'VARCHAR',
                    'constraint' => 64,
                    'null' => TRUE,
                    'default' => NULL,
                ],
            ]);
        }
        if (!$dbforge->column_exists('system_apis', 'sync_lock_expires_at')) {
            $dbforge->add_column('system_apis', [
                'sync_lock_expires_at' => [
                    'type' => 'DATETIME',
                    'null' => FALSE,
                    'default' => '2000-01-01 00:00:00',
                ],
            ]);
        }
    }

    public function down()
    {
        $dbforge = $this->_lava->dbforge;
        if ($dbforge->column_exists('system_apis', 'sync_lock_expires_at')) {
            $dbforge->drop_column('system_apis', 'sync_lock_expires_at');
        }
        if ($dbforge->column_exists('system_apis', 'sync_lock_token')) {
            $dbforge->drop_column('system_apis', 'sync_lock_token');
        }
    }
}
