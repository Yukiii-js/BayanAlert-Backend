<?php

class Add_user_moderation_columns
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
        if (!$dbforge->table_exists('users')) {
            throw new RuntimeException('The users table must exist before moderation columns can be added.');
        }

        $columns = [
            'warning_strikes' => [
                'type' => 'INT',
                'unsigned' => TRUE,
                'null' => FALSE,
                'default' => 0,
            ],
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'null' => FALSE,
                'default' => 'active',
            ],
            'suspension_reason' => [
                'type' => 'TEXT',
                'null' => TRUE,
                'default' => NULL,
            ],
        ];

        foreach ($columns as $name => $definition) {
            if (!$dbforge->column_exists('users', $name)) {
                $dbforge->add_column('users', [$name => $definition]);
            }
        }
    }

    public function down()
    {
        // Existing deployments may already contain data in these optional columns.
    }
}
