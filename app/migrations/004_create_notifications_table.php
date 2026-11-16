<?php

class Create_notifications_table
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if (!$this->_lava->dbforge->table_exists('notifications')) {
            $this->_lava->dbforge
                ->add_field([
                    'notification_id' => [
                        'type' => 'INT',
                        'unsigned' => TRUE,
                        'auto_increment' => TRUE,
                        'null' => FALSE,
                    ],
                    'user_id' => [
                        'type' => 'INT',
                        'unsigned' => TRUE,
                        'null' => TRUE,
                        'default' => NULL,
                    ],
                    'report_id' => [
                        'type' => 'INT',
                        'unsigned' => TRUE,
                        'null' => TRUE,
                        'default' => NULL,
                    ],
                    'title' => [
                        'type' => 'VARCHAR',
                        'constraint' => 200,
                        'null' => FALSE,
                    ],
                    'message' => [
                        'type' => 'TEXT',
                        'null' => FALSE,
                    ],
                    'notification_type' => [
                        'type' => 'VARCHAR',
                        'constraint' => 50,
                        'null' => FALSE,
                        'default' => 'system',
                    ],
                    'is_read' => [
                        'type' => 'TINYINT',
                        'constraint' => 1,
                        'unsigned' => TRUE,
                        'null' => FALSE,
                        'default' => 0,
                    ],
                    'created_at' => [
                        'type' => 'DATETIME',
                        'null' => FALSE,
                        'default' => 'CURRENT_TIMESTAMP',
                    ],
                ])
                ->add_key('notification_id', primary: TRUE)
                ->add_key('user_id', name: 'notifications_user_id_idx')
                ->add_key('report_id', name: 'notifications_report_id_idx')
                ->add_key('created_at', name: 'notifications_created_at_idx')
                ->create_table('notifications');
        }

        $columns = $this->_lava->db->raw('SHOW COLUMNS FROM `notifications`')
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $column) {
            if ($column['Field'] === 'notification_type'
                && stripos((string) $column['Type'], 'enum(') === 0) {
                $this->_lava->db->raw(
                    "ALTER TABLE `notifications` MODIFY `notification_type` VARCHAR(50) NOT NULL DEFAULT 'system'"
                );
                break;
            }
        }
    }

    public function down()
    {
        // Notifications may contain an important delivery history; do not drop them automatically.
    }
}
