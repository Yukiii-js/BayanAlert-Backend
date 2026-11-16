<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'libraries' . DIRECTORY_SEPARATOR . 'BrevoMailer.php';

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

    public function barangay_incident_counts()
    {
        try {
            api_response(['counts' => $this->Admin_model->get_barangay_incident_counts()]);
        } catch (\Throwable $e) {
            error_log('Admin barangay incident counts failed: ' . $e->getMessage());
            api_error('Could not load barangay incident counts.', 500);
        }
    }

    public function table($table)
    {
        try {
            $info = $this->get_table_info($table);
            $searchInput = $this->request->get('q', '');
            $pageInput = $this->request->get('page', '1');
            $search = is_string($searchInput) ? trim($searchInput) : '';
            $page = is_numeric($pageInput) && (int) $pageInput > 0 ? (int) $pageInput : 1;
            $result = $this->Admin_model->get_rows($table, $search, $page);
            $schema = $this->Admin_model->get_schema($table);
            if ($table === 'hazard_reports') {
                $schema['confirm_count'] = ['label' => 'Resident confirms'];
                $schema['dispute_count'] = ['label' => 'Resident disputes'];
            }
            
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
        } catch (\Throwable $e) {
            $errorInfo = $e instanceof \PDOException ? ($e->errorInfo ?? []) : [];
            $sqlState = (string) ($errorInfo[0] ?? $e->getCode());
            $driverCode = (int) ($errorInfo[1] ?? 0);
            if ($driverCode === 0 && is_numeric($e->getCode())) {
                $driverCode = (int) $e->getCode();
            }
            if ($sqlState === '' && preg_match('/SQLSTATE\[([A-Z0-9]+)\]/i', $e->getMessage(), $matches)) {
                $sqlState = strtoupper($matches[1]);
            }
            if ($driverCode === 0
                && preg_match('/SQLSTATE\[[^\]]+\].*?:\s*(\d{4})\b/i', $e->getMessage(), $matches)) {
                $driverCode = (int) $matches[1];
            }

            error_log(sprintf(
                'Admin table fetch failed (%s): %s | SQLSTATE=%s | MySQL=%s | at=%s:%s',
                $table,
                $e->getMessage(),
                $sqlState !== '' ? $sqlState : 'unknown',
                $driverCode > 0 ? $driverCode : 'unknown',
                $e->getFile(),
                $e->getLine()
            ));

            $connectionFailure = in_array($driverCode, [1045, 2002, 2003, 2005, 2006, 2013], true);
            if ($connectionFailure) {
                api_error(
                    'Database connection failed (MySQL ' . $driverCode . '). Check the Render and Aiven connection settings.',
                    503
                );
            }

            $detail = trim(preg_split('/\bQuery:\s*/i', $e->getMessage(), 2)[0]);
            $diagnostic = 'SQLSTATE ' . ($sqlState !== '' ? $sqlState : 'unknown')
                . ($driverCode > 0 ? ', MySQL ' . $driverCode : '');
            api_error('Could not fetch table records (' . $diagnostic . '): ' . $detail, 500);
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
            'fields'     => $fields,
            'notification' => $table === 'hazard_reports'
                ? $this->getHazardNotificationPreview((int) $id)
                : null
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
        if ($table === 'hazard_reports'
            && strtolower(trim((string) ($validation['data']['status'] ?? ''))) === 'resolved'
            && strtolower(trim((string) ($record['status'] ?? ''))) !== 'resolved') {
            api_error('Use the Mark as Resolved action so the resolution timestamp is recorded.', 422);
            return;
        }

        try {
            $oldStatus = strtolower(trim((string) ($record['status'] ?? '')));
            $newStatus = strtolower(trim((string) ($validation['data']['status'] ?? '')));
            $hasStatusTransition = $table === 'hazard_reports'
                && array_key_exists('status', $validation['data'])
                && $oldStatus !== $newStatus;
            $isNewlyApproved = $table === 'hazard_reports'
                && in_array($newStatus, ['approved', 'verified'], true)
                && !in_array($oldStatus, ['approved', 'verified'], true);
            $sendDepartmentEmail = false;
            if (array_key_exists('send_department_email', $input)) {
                $emailOption = filter_var(
                    $input['send_department_email'],
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                );
                if ($emailOption === null) {
                    api_error('Department email delivery must be enabled or disabled explicitly.', 422);
                    return;
                }
                $sendDepartmentEmail = $emailOption;
            }
            $notificationEmail = isset($input['department_email']) && is_string($input['department_email'])
                ? trim($input['department_email'])
                : '';
            $hazardReport = null;

            if ($isNewlyApproved && $sendDepartmentEmail) {
                $hazardReport = $this->Admin_model->get_hazard_notification_data((int) $id);
                $defaultDepartment = $hazardReport
                    ? (new BrevoMailer())->getDepartmentDetailsForCategory(
                        (string) ($hazardReport['category_name'] ?? '')
                    )
                    : null;
                if ($notificationEmail === '' && $defaultDepartment) {
                    $notificationEmail = $defaultDepartment['email'];
                }
                if (!filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) {
                    api_error('A valid recipient email is required when department email delivery is enabled.', 422);
                    return;
                }
            }

            $message = $info['label'] . ' record updated successfully.';
            if ($hasStatusTransition) {
                $this->db->transaction();
                try {
                    $this->Admin_model->update_record($table, $id, $validation['data']);
                    $this->Admin_model->record_hazard_status_transition(
                        (int) $id,
                        $oldStatus,
                        $newStatus,
                        (int) ($this->currentUser['user_id'] ?? 0)
                    );
                    $this->db->commit();
                } catch (\Throwable $workflowError) {
                    $this->db->roll_back();
                    error_log('Hazard report workflow update failed for report ' . (int) $id . ': ' . $workflowError->getMessage());
                    api_error('Could not update the report and its workflow audit records.', 500);
                    return;
                }
            } else {
                $this->Admin_model->update_record($table, $id, $validation['data']);
            }

            if ($table === 'hazard_reports'
                && isset($validation['data']['status'])
                && (string) $record['status'] !== (string) $validation['data']['status']
                && !empty($record['user_id'])) {
                try {
                    $this->db->raw(
                        'INSERT INTO notifications (user_id, report_id, target_type, title, message, notification_type, is_read, created_at) ' .
                        'VALUES (?, ?, ?, ?, ?, ?, 0, NOW())',
                        [
                            (int) $record['user_id'],
                            (int) $record['report_id'],
                            'report',
                            'Report status updated',
                            'Your report ' . ($record['reference_number'] ?: '#' . $record['report_id']) .
                                ' is now ' . str_replace('_', ' ', strtolower((string) $validation['data']['status'])) . '.',
                            'report_status'
                        ]
                    );
                } catch (\Throwable $notificationError) {
                    error_log('Report status notification failed for report ' . $record['report_id'] . ': ' . $notificationError->getMessage());
                    $message .= ' Resident notification delivery failed.';
                }
            }

            if ($isNewlyApproved && $sendDepartmentEmail) {
                try {
                    if ($hazardReport) {
                        $hazardReport['notification_email'] = $notificationEmail;
                    }
                    $emailResult = $hazardReport
                        ? (new BrevoMailer())->sendHazardNotificationToDepartment($hazardReport)
                        : ['success' => false, 'error' => 'Approved report details could not be loaded.'];

                    if ($emailResult['success']) {
                        $message .= ' Department notification email queued.';
                    } else {
                        error_log(
                            'Department email was not queued for report ' . (int) $id . ': ' .
                            ($emailResult['error'] ?? 'Unknown email error.')
                        );
                        $message .= ' Report approved, but the department notification email could not be queued.';
                    }
                } catch (\Throwable $emailError) {
                    error_log('Department email failed for report ' . (int) $id . ': ' . $emailError->getMessage());
                    $message .= ' Report approved, but the department notification email could not be queued.';
                }
            }

            api_response(null, 200, $message);
        } catch (\PDOException $e) {
            error_log('Admin update failed for ' . $table . ': ' . $e->getMessage());
            api_error('Could not update record. Check for duplicate values or reference conflicts.', 400);
        }
    }

    private function getHazardNotificationPreview($reportId)
    {
        $report = $this->Admin_model->get_hazard_notification_data($reportId);
        if (!$report) {
            return null;
        }

        $department = (new BrevoMailer())->getDepartmentDetailsForCategory(
            (string) ($report['category_name'] ?? '')
        );

        return [
            'report' => $report,
            'department' => $department,
            'default_email' => $department['email'] ?? ''
        ];
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
