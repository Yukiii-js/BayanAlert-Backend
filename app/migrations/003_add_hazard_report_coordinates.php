<?php

class Add_hazard_report_coordinates
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if (!$this->_lava->dbforge->table_exists('hazard_reports')) {
            throw new RuntimeException('Import the BayanAlert database schema before applying report coordinates.');
        }

        if (!$this->_lava->dbforge->column_exists('hazard_reports', 'latitude')) {
            $this->_lava->dbforge->add_column('hazard_reports', [
                'latitude' => [
                    'type' => 'DECIMAL(10,7)',
                    'null' => true,
                    'default' => null
                ]
            ]);
        }

        if (!$this->_lava->dbforge->column_exists('hazard_reports', 'longitude')) {
            $this->_lava->dbforge->add_column('hazard_reports', [
                'longitude' => [
                    'type' => 'DECIMAL(10,7)',
                    'null' => true,
                    'default' => null
                ]
            ]);
        }
    }

    public function down()
    {
        if (!$this->_lava->dbforge->table_exists('hazard_reports')) {
            return;
        }

        foreach (['longitude', 'latitude'] as $column) {
            if ($this->_lava->dbforge->column_exists('hazard_reports', $column)) {
                $this->_lava->dbforge->drop_column('hazard_reports', $column);
            }
        }
    }
}
