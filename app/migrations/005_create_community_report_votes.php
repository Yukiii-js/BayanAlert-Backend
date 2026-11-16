<?php

class Create_community_report_votes
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('report_community_votes')) {
            return;
        }

        $this->_lava->dbforge
            ->add_field([
                'vote_id' => [
                    'type' => 'INT',
                    'unsigned' => TRUE,
                    'auto_increment' => TRUE,
                    'null' => FALSE,
                ],
                'report_id' => [
                    'type' => 'INT',
                    'unsigned' => TRUE,
                    'null' => FALSE,
                ],
                'user_id' => [
                    'type' => 'INT',
                    'unsigned' => TRUE,
                    'null' => FALSE,
                ],
                'vote' => [
                    'type' => 'VARCHAR',
                    'constraint' => 10,
                    'null' => FALSE,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => FALSE,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => FALSE,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
            ])
            ->add_key('vote_id', primary: TRUE)
            ->add_key(['report_id', 'user_id'], unique: TRUE, name: 'report_community_vote_unique')
            ->add_key('user_id', name: 'report_community_votes_user_id_idx')
            ->add_foreign_key('report_id', 'hazard_reports', 'report_id', 'CASCADE')
            ->add_foreign_key('user_id', 'users', 'user_id', 'CASCADE')
            ->create_table('report_community_votes');
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('report_community_votes');
    }
}
