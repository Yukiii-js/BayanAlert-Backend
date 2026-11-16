<?php

class Create_email_verification_tokens
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('email_verification_tokens')) {
            return;
        }

        $this->_lava->dbforge
            ->add_field([
                'verification_id' => [
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
            ->add_key('verification_id', primary: TRUE)
            ->add_key('user_id', unique: TRUE, name: 'email_verification_user_unique')
            ->add_key('token_hash', unique: TRUE, name: 'email_verification_token_unique')
            ->add_foreign_key('user_id', 'users', 'user_id', 'CASCADE')
            ->create_table('email_verification_tokens');

        $this->_lava->db->raw(
            "UPDATE users SET email_verified_at = NOW() " .
            "WHERE account_status = 'active' AND email_verified_at IS NULL " .
            "AND role_id IN (SELECT role_id FROM roles WHERE role_name IN ('resident', 'user'))"
        );
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('email_verification_tokens');
    }
}
