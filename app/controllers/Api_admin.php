<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Api_admin extends Controller
{
    protected $currentUser;

    public function __construct()
    {
        parent::__construct();
        $this->call->model('Admin_model');
        $this->currentUser = require_api_auth();

        if (strtolower((string) ($this->currentUser['role_name'] ?? '')) !== 'admin') {
            api_error('Forbidden: Administrator access required.', 403);
        }
    }

    public function dashboard()
    {
        try {
            $data = $this->Admin_model->get_dashboard_data();
            $data['tables'] = $this->Admin_model->get_tables();
            api_response($data);
        } catch (\Throwable $e) {
            error_log('Admin dashboard failed: ' . $e->getMessage());
            api_error('Could not load dashboard data. Check the application and database logs.', 500);
        }
    }

    public function tables()
    {
        $tables = $this->Admin_model->get_tables();
        $groups = [];
        foreach ($tables as $name => $info) {
            $group = $info['group'] ?? 'General';
            $info['table_name'] = $name;
            $groups[$group][$name] = $info;
        }

        api_response([
            'tables' => $tables,
            'groups' => $groups
        ]);
    }

    public function table($table)
    {
        $info = $this->get_table_info($table);
        $searchInput = $this->request->get('q', '');
        $pageInput = $this->request->get('page', '1');
        $search = is_string($searchInput) ? trim($searchInput) : '';
        $page = is_numeric($pageInput) && (int) $pageInput > 0 ? (int) $pageInput : 1;

        try {
            $result = $this->Admin_model->get_rows($table, $search, $page);
            $schema = $this->Admin_model->get_schema($table);
            
            api_response([
                'table'         => $table,
                'table_info'    => $info,
                'columns'       => $result['columns'],
                'schema'        => $schema,
                'rows'          => $result['rows'],
                'total'         => $result['total'],
                'search'        => $search,
                'search_column' => $result['search_column'],
                'page'          => $result['page'],
                'page_size'     => $result['page_size']
            ]);
        } catch (\Exception $e) {
            api_error('Could not fetch table records: ' . $e->getMessage(), 500);
        }
    }

    public function options($table)
    {
        $info = $this->get_table_info($table);
        $schema = $this->Admin_model->get_schema($table);
        $fields = [];

        foreach ($schema as $field) {
            if (!$field['form']) {
                continue;
            }
            $field['options'] = $this->Admin_model->get_reference_options($table, $field);
            $fields[] = $field;
        }

        api_response([
            'table'      => $table,
            'table_info' => $info,
            'fields'     => $fields
        ]);
    }

    public function show($table, $id)
    {
        $info = $this->get_table_info($table);
        if (!ctype_digit((string) $id) || (int) $id < 1) {
            api_error('Invalid record ID.', 400);
        }

        $record = $this->Admin_model->get_record($table, $id);
        if (!$record) {
            api_error('Record not found.', 404);
        }

        $schema = $this->Admin_model->get_schema($table);
        $fields = [];
        foreach ($schema as $field) {
            if (!$field['form']) {
                continue;
            }
            $field['options'] = $this->Admin_model->get_reference_options($table, $field);
            $field['value'] = $record[$field['name']] ?? '';
            $fields[] = $field;
        }

        api_response([
            'table'      => $table,
            'table_info' => $info,
            'record'     => $record,
            'fields'     => $fields
        ]);
    }

    public function store($table)
    {
        $info = $this->get_table_info($table);
        if (!empty($info['read_only'])) {
            api_error('This table is read-only to preserve audit records.', 403);
        }

        $input = get_request_input();
        $validation = $this->Admin_model->validate_form_data($table, $input, false);
        if ($validation['error'] !== null) {
            api_error($validation['error'], 422);
        }

        try {
            $insertedId = $this->Admin_model->insert_record($table, $validation['data']);
            api_response([
                'id' => $insertedId
            ], 201, $info['label'] . ' record created successfully.');
        } catch (\PDOException $e) {
            error_log('Admin insert failed for ' . $table . ': ' . $e->getMessage());
            api_error('Could not save record. Check for duplicate keys or invalid references.', 400);
        }
    }

    public function update($table, $id)
    {
        $info = $this->get_table_info($table);
        if (!empty($info['read_only'])) {
            api_error('This table is read-only to preserve audit records.', 403);
        }

        if (!ctype_digit((string) $id) || (int) $id < 1) {
            api_error('Invalid record ID.', 400);
        }

        if ($table === 'users' && (string) $id === (string) $this->currentUser['user_id']) {
            api_error('You cannot modify your own administrator account from this screen.', 403);
        }

        $record = $this->Admin_model->get_record($table, $id);
        if (!$record) {
            api_error('Record not found.', 404);
        }

        $input = get_request_input();
        $validation = $this->Admin_model->validate_form_data($table, $input, true);
        if ($validation['error'] !== null) {
            api_error($validation['error'], 422);
        }

        try {
            $this->Admin_model->update_record($table, $id, $validation['data']);
            api_response(null, 200, $info['label'] . ' record updated successfully.');
        } catch (\PDOException $e) {
            error_log('Admin update failed for ' . $table . ': ' . $e->getMessage());
            api_error('Could not update record. Check for duplicate values or reference conflicts.', 400);
        }
    }

    public function delete($table, $id)
    {
        $info = $this->get_table_info($table);
        if (!empty($info['read_only'])) {
            api_error('This table is read-only to preserve audit records.', 403);
        }

        if (!ctype_digit((string) $id) || (int) $id < 1) {
            api_error('Invalid record ID.', 400);
        }

        if ($table === 'users' && (string) $id === (string) $this->currentUser['user_id']) {
            api_error('You cannot delete your own administrator account.', 403);
        }

        try {
            $deleted = $this->Admin_model->delete_record($table, $id);
            if ($deleted) {
                api_response(null, 200, $info['label'] . ' record deleted successfully.');
            } else {
                api_error('No record was deleted.', 404);
            }
        } catch (\PDOException $e) {
            error_log('Admin delete failed for ' . $table . ': ' . $e->getMessage());
            api_error('Could not delete record. It may be referenced by other records.', 400);
        }
    }

    private function get_table_info($table)
    {
        try {
            return $this->Admin_model->get_table_info($table);
        } catch (\InvalidArgumentException $e) {
            api_error('Requested data register does not exist.', 404);
        }
    }
}
