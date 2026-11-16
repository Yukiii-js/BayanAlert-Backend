<?php

class Create_password_reset_tokens
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('password_reset_tokens')) {
            return;
        }

        $this->_lava->dbforge
            ->add_field([
                'reset_id' => [
                    'type' => 'INT',
                    'unsigned' => TRUE,
                    'auto_increment' => TRUE,
                    'null' => FALSE,
                ],
                'user_id' => [
                    'type' => 'INT',
                    'unsigned' => TRUE,
                    'null' => FALSE,
                ],
                'token_hash' => [
                    'type' => 'CHAR',
                    'constraint' => 64,
                    'null' => FALSE,
                ],
                'expires_at' => [
                    'type' => 'DATETIME',
                    'null' => FALSE,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => FALSE,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
            ])
            ->add_key('reset_id', primary: TRUE)
            ->add_key('user_id', unique: TRUE, name: 'password_reset_user_unique')
            ->add_key('token_hash', unique: TRUE, name: 'password_reset_token_unique')
            ->add_key('expires_at', name: 'password_reset_expiry_idx')
            ->add_foreign_key('user_id', 'users', 'user_id', 'CASCADE')
            ->create_table('password_reset_tokens');
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('password_reset_tokens');
    }
}
