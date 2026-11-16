<?php

class Expand_external_hazard_type
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('external_hazard_events')) {
            $this->_lava->db->raw(
                'ALTER TABLE external_hazard_events MODIFY hazard_type VARCHAR(24) NOT NULL'
            );
        }
    }

    public function down()
    {
        if ($this->_lava->dbforge->table_exists('external_hazard_events')) {
            $this->_lava->db->raw(
                "UPDATE external_hazard_events SET hazard_type = CASE LOWER(hazard_type) " .
                "WHEN 'earthquake' THEN 'EQ' WHEN 'typhoon' THEN 'TC' " .
                "WHEN 'rainfall' THEN 'RAIN' WHEN 'severe-weather' THEN 'WEATHER' " .
                "ELSE LEFT(hazard_type, 8) END"
            );
            $this->_lava->db->raw(
                'ALTER TABLE external_hazard_events MODIFY hazard_type VARCHAR(8) NOT NULL'
            );
        }
    }
}
