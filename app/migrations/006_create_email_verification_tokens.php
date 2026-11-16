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

        $residentRoles = $this->_lava->db->table('roles')
            ->select('role_id')
            ->where('role_name', 'resident')
            ->or_where('role_name', 'user')
            ->get_all();
        $verifiedAt = gmdate('Y-m-d H:i:s');
        foreach ($residentRoles as $role) {
            $this->_lava->db->table('users')
                ->where('role_id', (int) $role['role_id'])
                ->where('account_status', 'active')
                ->where_null('email_verified_at')
                ->update(['email_verified_at' => $verifiedAt]);
        }
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('email_verification_tokens');
    }
}
