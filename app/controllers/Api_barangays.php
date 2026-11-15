
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
        $query = isset($_GET['q']) && is_string($_GET['q'])
            ? trim($_GET['q'])
            : '';
        if (mb_strlen($query) < 2) {
            api_response(['suggestions' => []]);
            return;
        }
        $query = mb_substr($query, 0, 80);

        try {
            $term = '%' . $query . '%';
            $suggestions = [];
            $barangays = $this->db->raw(
                'SELECT barangay_name, latitude, longitude FROM barangays ' .
                'WHERE is_active = 1 AND barangay_name LIKE ? ORDER BY barangay_name LIMIT 8',
                [$term]
            )->fetchAll(PDO::FETCH_ASSOC);

            foreach ($barangays as $barangay) {
                $suggestions[] = [
                    'label' => 'Brgy. ' . $barangay['barangay_name'] . ', Calapan City',
                    'latitude' => $barangay['latitude'],
                    'longitude' => $barangay['longitude'],
                    'source' => 'Barangay'
                ];
            }

            $columns = $this->db->raw('SHOW COLUMNS FROM `hazard_reports`')
                ->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('address_details', $columns, true)) {
                $coordinates = in_array('latitude', $columns, true)
                    && in_array('longitude', $columns, true);
                $locationColumns = $coordinates
                    ? 'latitude, longitude, '
                    : 'NULL AS latitude, NULL AS longitude, ';
                $locationGroup = $coordinates ? ', latitude, longitude' : '';
                $addresses = $this->db->raw(
                    'SELECT address_details, ' . $locationColumns .
                    'MAX(submitted_at) AS last_reported FROM hazard_reports ' .
                    'WHERE address_details LIKE ? AND address_details <> \'\' ' .
                    'GROUP BY address_details' . $locationGroup . ' ' .
                    'ORDER BY last_reported DESC LIMIT 8',
                    [$term]
                )->fetchAll(PDO::FETCH_ASSOC);

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
