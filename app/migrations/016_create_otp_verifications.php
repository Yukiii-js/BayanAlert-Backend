<?php

class Create_otp_verifications
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
        if (!$dbforge->table_exists('otp_verifications')) {
            $dbforge
                ->add_field([
                    'otp_id' => [
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
                    'email' => [
                        'type' => 'VARCHAR',
                        'constraint' => 150,
                        'null' => TRUE,
                        'default' => NULL,
                    ],
                    'code_hash' => [
                        'type' => 'VARCHAR',
                        'constraint' => 255,
                        'null' => FALSE,
                    ],
                    'verification_type' => [
                        'type' => 'VARCHAR',
                        'constraint' => 32,
                        'null' => FALSE,
                    ],
                    'expires_at' => [
                        'type' => 'DATETIME',
                        'null' => FALSE,
                    ],
                    'used_at' => [
                        'type' => 'DATETIME',
                        'null' => TRUE,
                        'default' => NULL,
                    ],
                    'attempt_count' => [
                        'type' => 'TINYINT',
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
                ->add_key('otp_id', primary: TRUE)
                ->add_key(
                    ['email', 'verification_type', 'created_at'],
                    name: 'otp_verifications_email_type_created_idx'
                )
                ->create_table('otp_verifications');
            return;
        }

        $columns = [
            'email' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
                'null' => TRUE,
                'default' => NULL,
                'after' => 'user_id',
            ],
            'used_at' => [
                'type' => 'DATETIME',
                'null' => TRUE,
                'default' => NULL,
            ],
            'attempt_count' => [
                'type' => 'TINYINT',
                'unsigned' => TRUE,
                'null' => FALSE,
                'default' => 0,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => FALSE,
                'default' => 'CURRENT_TIMESTAMP',
            ],
        ];
        foreach ($columns as $name => $definition) {
            if (!$dbforge->column_exists('otp_verifications', $name)) {
                $dbforge->add_column('otp_verifications', [$name => $definition]);
            }
        }

        $dbforge->modify_column('otp_verifications', [
            'user_id' => [
                'type' => 'INT',
                'unsigned' => TRUE,
                'null' => TRUE,
                'default' => NULL,
            ],
            'code_hash' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => FALSE,
            ],
            'verification_type' => [
                'type' => 'VARCHAR',
                'constraint' => 32,
                'null' => FALSE,
            ],
        ]);
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('otp_verifications');
    }
}
