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
            $this->_lava->dbforge->modify_column('external_hazard_events', [
                'hazard_type' => [
                    'type' => 'VARCHAR',
                    'constraint' => 24,
                    'null' => FALSE,
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->_lava->dbforge->table_exists('external_hazard_events')) {
            $events = $this->_lava->db->table('external_hazard_events')
                ->select('id, hazard_type')
                ->get_all();
            $legacyTypes = [
                'earthquake' => 'EQ',
                'typhoon' => 'TC',
                'rainfall' => 'RAIN',
                'severe-weather' => 'WEATHER',
            ];
            foreach ($events as $event) {
                $type = strtolower((string) $event['hazard_type']);
                $legacyType = $legacyTypes[$type] ?? substr((string) $event['hazard_type'], 0, 8);
                $this->_lava->db->table('external_hazard_events')
                    ->where('id', (int) $event['id'])
                    ->update(['hazard_type' => $legacyType]);
            }
            $this->_lava->dbforge->modify_column('external_hazard_events', [
                'hazard_type' => [
                    'type' => 'VARCHAR',
                    'constraint' => 8,
                    'null' => FALSE,
                ],
            ]);
        }
    }
}
