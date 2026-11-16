
<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Api_barangays extends Controller {

    public function __construct() {
        parent::__construct();
        $this->call->model('Auth_model');
    }

    public function index() {
        try {
            $barangays = $this->Auth_model->get_active_barangays();

            api_response([
                'barangays' => $barangays
            ], 200, 'Barangays retrieved successfully.');

        } catch (\Exception $e) {
            api_error('Unable to retrieve barangays.', 500);
        }
    }

    public function suggestions() {
        $search = $this->request->get('q');
        $query = is_string($search)
            ? trim($search)
            : '';
        if (mb_strlen($query) < 2) {
            api_response(['suggestions' => []]);
            return;
        }
        $query = mb_substr($query, 0, 80);

        try {
            $term = '%' . $query . '%';
            $suggestions = [];
            $barangays = $this->db->table('barangays')
                ->select('barangay_name, latitude, longitude')
                ->where('is_active', 1)
                ->like('barangay_name', $term)
                ->order_by('barangay_name', 'ASC')
                ->limit(8)
                ->get_all();

            foreach ($barangays as $barangay) {
                $suggestions[] = [
                    'label' => 'Brgy. ' . $barangay['barangay_name'] . ', Calapan City',
                    'latitude' => $barangay['latitude'],
                    'longitude' => $barangay['longitude'],
                    'source' => 'Barangay'
                ];
            }

            $columns = get_table_column_names($this->db, 'hazard_reports');
            if (in_array('address_details', $columns, true)) {
                $coordinates = in_array('latitude', $columns, true)
                    && in_array('longitude', $columns, true);
                $select = $coordinates
                    ? 'address_details, latitude, longitude, MAX(submitted_at) AS last_reported'
                    : 'address_details, NULL AS latitude, NULL AS longitude, MAX(submitted_at) AS last_reported';
                $groupBy = $coordinates
                    ? ['address_details', 'latitude', 'longitude']
                    : ['address_details'];
                $addresses = $this->db->table('hazard_reports')
                    ->select($select)
                    ->like('address_details', $term)
                    ->where('address_details', '!=', '')
                    ->group_by($groupBy)
                    ->order_by('last_reported', 'DESC')
                    ->limit(8)
                    ->get_all();

                foreach ($addresses as $address) {
                    $label = trim((string) $address['address_details']);
                    $alreadyIncluded = false;
                    foreach ($suggestions as $suggestion) {
                        if (mb_strtolower($suggestion['label']) === mb_strtolower($label)) {
                            $alreadyIncluded = true;
                            break;
                        }
                    }
                    if (!$alreadyIncluded) {
                        $suggestions[] = [
                            'label' => $label,
                            'latitude' => $address['latitude'],
                            'longitude' => $address['longitude'],
                            'source' => 'Previously reported'
                        ];
                    }
                }
            }

            api_response(['suggestions' => array_slice($suggestions, 0, 12)]);
        } catch (\Throwable $e) {
            error_log('Barangay address suggestions failed: ' . $e->getMessage());
            api_error('Unable to load address suggestions.', 500);
        }
    }
}
