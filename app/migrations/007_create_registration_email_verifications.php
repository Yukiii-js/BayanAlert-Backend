<?php

class Create_registration_email_verifications
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('registration_email_verifications')) {
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
                'email' => [
                    'type' => 'VARCHAR',
                    'constraint' => 150,
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
                'verified_at' => [
                    'type' => 'DATETIME',
                    'null' => TRUE,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => FALSE,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
            ])
            ->add_key('verification_id', primary: TRUE)
            ->add_key('email', unique: TRUE, name: 'registration_email_verification_email_unique')
            ->add_key('token_hash', unique: TRUE, name: 'registration_email_verification_token_unique')
            ->add_key('expires_at', name: 'registration_email_verification_expiry_idx')
            ->create_table('registration_email_verifications');
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('registration_email_verifications');
    }
}
