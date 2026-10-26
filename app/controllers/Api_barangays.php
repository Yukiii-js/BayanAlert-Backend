
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
}
