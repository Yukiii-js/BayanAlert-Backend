<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Admin_model extends Model
{
    private $tables = [
        'hazard_reports' => [
            'label' => 'Hazard reports',
            'group' => 'Operations',
            'description' => 'Review submitted incidents, locations, and report status.',
            'primary_key' => 'report_id'
        ],
        'report_verifications' => [
            'label' => 'Report verifications',
            'group' => 'Operations',
            'description' => 'Review verification decisions and remarks.',
            'primary_key' => 'verification_id'
        ],
        'report_assignments' => [
            'label' => 'Report assignments',
            'group' => 'Operations',
            'description' => 'Manage assignments and their completion status.',
            'primary_key' => 'assignment_id'
        ],
        'report_evidence' => [
            'label' => 'Report evidence',
            'group' => 'Operations',
            'description' => 'Manage evidence records attached to reports.',
            'primary_key' => 'evidence_id'
        ],
        'resolutions' => [
            'label' => 'Resolutions',
            'group' => 'Operations',
            'description' => 'Track resolution details and confirmation.',
            'primary_key' => 'resolution_id'
        ],
        'duplicate_report_checks' => [
            'label' => 'Duplicate checks',
            'group' => 'Operations',
            'description' => 'Review suspected duplicate reports.',
            'primary_key' => 'check_id'
        ],
        'users' => [
            'label' => 'Users',
            'group' => 'People & access',
            'description' => 'Manage resident, staff, and administrator accounts.',
            'primary_key' => 'user_id'
        ],
        'roles' => [
            'label' => 'Roles',
            'group' => 'People & access',
            'description' => 'Manage account roles.',
            'primary_key' => 'role_id'
        ],
        'barangays' => [
            'label' => 'Barangays',
            'group' => 'Directory',
            'description' => 'Manage barangay records and availability.',
            'primary_key' => 'barangay_id'
        ],
        'hazard_categories' => [
            'label' => 'Hazard categories',
            'group' => 'Directory',
            'description' => 'Manage categories used for hazard reports.',
            'primary_key' => 'category_id'
        ],
        'stations' => [
            'label' => 'Response stations',
            'group' => 'Directory',
            'description' => 'Manage stations that can be assigned to reports.',
            'primary_key' => 'station_id'
        ],
        'notifications' => [
            'label' => 'Notifications',
            'group' => 'System data',
            'description' => 'Manage messages sent to BayanAlert users.',
            'primary_key' => 'notification_id'
        ],
        'otp_verifications' => [
            'label' => 'OTP verifications',
            'group' => 'System data',
            'description' => 'View verification records. Verification codes remain private.',
            'primary_key' => 'otp_id',
            'read_only' => true
        ],
        'report_status_history' => [
            'label' => 'Status history',
            'group' => 'System data',
            'description' => 'View the report status audit trail.',
            'primary_key' => 'history_id',
            'read_only' => true
        ]
    ];

    private $references = [
        'users.role_id' => ['roles', 'role_name'],
        'users.barangay_id' => ['barangays', 'barangay_name'],
        'otp_verifications.user_id' => ['users', 'full_name'],
        'hazard_reports.user_id' => ['users', 'full_name'],
        'hazard_reports.category_id' => ['hazard_categories', 'category_name'],
        'hazard_reports.station_id' => ['stations', 'station_name'],
        'report_verifications.report_id' => ['hazard_reports', 'reference_number'],
        'report_verifications.verifier_id' => ['users', 'full_name'],
        'report_assignments.report_id' => ['hazard_reports', 'reference_number'],
        'report_assignments.assigned_to' => ['users', 'full_name'],
        'report_assignments.assigned_by' => ['users', 'full_name'],
        'report_evidence.report_id' => ['hazard_reports', 'reference_number'],
        'report_evidence.uploaded_by' => ['users', 'full_name'],
        'resolutions.report_id' => ['hazard_reports', 'reference_number'],
        'resolutions.confirmed_by' => ['users', 'full_name'],
        'report_status_history.report_id' => ['hazard_reports', 'reference_number'],
        'report_status_history.changed_by' => ['users', 'full_name'],
        'notifications.user_id' => ['users', 'full_name'],
        'notifications.report_id' => ['hazard_reports', 'reference_number'],
        'duplicate_report_checks.report_id' => ['hazard_reports', 'reference_number'],
        'duplicate_report_checks.possible_duplicate_id' => ['hazard_reports', 'reference_number'],
        'duplicate_report_checks.checked_by' => ['users', 'full_name']
    ];

    private $schema_cache = [];
    private $table_model_cache = [];

    private function table_model_name($table)
    {
        return implode('', array_map('ucfirst', explode('_', $table))) . '_model';
    }

    private function get_table_model($table)
    {
        if (!isset($this->table_model_cache[$table])) {
            $modelName = $this->table_model_name($table);
            $this->table_model_cache[$table] = class_exists($modelName) ? new $modelName() : null;
        }

        return $this->table_model_cache[$table];
    }

    public function get_tables()
    {
        return $this->tables;
    }

    public function get_table_info($table)
    {
        if (!isset($this->tables[$table])) {
            throw new InvalidArgumentException('Unknown admin table.');
        }

        $model = $this->get_table_model($table);
        if ($model && method_exists($model, 'info')) {
            $info = $model->info();
            if (is_array($info)) {
                return array_merge($this->tables[$table], $info);
            }
        }

        return $this->tables[$table];
    }

    public function get_schema($table)
    {
        $info = $this->get_table_info($table);
        if (isset($this->schema_cache[$table])) {
            return $this->schema_cache[$table];
        }

        $rows = get_table_column_metadata($this->db, $table);
        $columns = [];
        foreach ($rows as $row) {
            $name = $row['Field'];
            $type = strtolower($row['Type']);
            $extra = strtolower($row['Extra']);
            $isPrimary = $name === $info['primary_key'];
            $isGenerated = strpos($extra, 'auto_increment') !== false;
            $isSensitive = in_array($name, ['password_hash', 'code_hash'], true);
            $isServerTimestamp = in_array($name, [
                'created_at', 'updated_at', 'submitted_at', 'uploaded_at',
                'assigned_at', 'verified_at', 'changed_at'
            ], true);

            $field = [
                'name' => $name,
                'label' => ucwords(str_replace('_', ' ', $name)),
                'type' => $type,
                'nullable' => $row['Null'] === 'YES',
                'default' => $row['Default'],
                'primary' => $isPrimary,
                'generated' => $isGenerated,
                'sensitive' => $isSensitive,
                'password' => false,
                'enum' => [],
                'reference' => null
            ];

            if (strpos($type, 'enum(') === 0) {
                preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $row['Type'], $matches);
                $field['enum'] = $matches[1] ?? [];
            }

            if (isset($this->references[$table . '.' . $name])) {
                $field['reference'] = $this->references[$table . '.' . $name];
                $field['reference_key'] = $this->tables[$field['reference'][0]]['primary_key'];
            }

            if ($isSensitive && $name === 'password_hash' && $table === 'users') {
                $field['form_name'] = 'password';
                $field['label'] = 'Password';
                $field['password'] = true;
                $field['nullable'] = true;
            } elseif ($isSensitive && $name === 'code_hash' && $table === 'otp_verifications') {
                $field['form_name'] = 'verification_code';
                $field['label'] = 'Verification code';
                $field['password'] = true;
                $field['nullable'] = false;
            } else {
                $field['form_name'] = $name;
            }

            $field['list'] = !$isSensitive;
            $field['form'] = !$isPrimary && !$isGenerated && !$isServerTimestamp
                && (!$isSensitive || isset($field['password']));
            $field['read_only'] = !empty($info['read_only']);
            $columns[$name] = $field;
        }

        $this->schema_cache[$table] = $columns;
        return $columns;
    }

    public function get_column_names($table)
    {
        $names = [];
        foreach ($this->get_schema($table) as $column) {
            if ($column['list']) {
                $names[] = $column['name'];
            }
        }
        return $names;
    }

    public function get_table_count($table)
    {
        $this->get_table_info($table);

        if (!$this->table_exists($table)) {
            return 0;
        }

        return (int) $this->db->table($table)->count();
    }

    public function get_dashboard_data()
    {
        $counts = [];
        $total = 0;
        $availableTables = [];
        $unavailableTables = [];

        foreach ($this->tables as $table => $info) {
            $available = $this->table_exists($table);
            $availableTables[$table] = $available;
            $counts[$table] = $available
                ? (int) $this->db->table($table)->count()
                : 0;
            $total += $counts[$table];

            if (!$available) {
                $unavailableTables[] = $table;
            }
        }
        $reportCount = $counts['hazard_reports'] ?? 0;
        $pendingReports = 0;
        $reportStatuses = [];
        $recentReports = [];

        if (!empty($availableTables['hazard_reports'])) {
            $pendingReports = (int) $this->db->table('hazard_reports')
                ->where('status', 'pending')
                ->count();
            $reportStatuses = $this->db->table('hazard_reports')
                ->select('status, COUNT(*) AS total')
                ->group_by('status')
                ->get_all();
            $recentReportColumns = [
                'hazard_reports.report_id',
                'hazard_reports.reference_number',
                'hazard_reports.title',
                'hazard_reports.description',
                'hazard_reports.address_details',
                'hazard_reports.status',
                'hazard_reports.submitted_at',
                'hazard_reports.category_id',
                'hazard_reports.station_id',
            ];

            if (!empty($availableTables['hazard_categories'])) {
                $recentReportColumns[] = 'hazard_categories.category_name';
            }
            if (!empty($availableTables['stations'])) {
                $recentReportColumns[] = 'stations.station_name';
            }

            $recentReportsQuery = $this->db->table('hazard_reports')
                ->select(implode(', ', $recentReportColumns));
            if (!empty($availableTables['hazard_categories'])) {
                $recentReportsQuery->left_join(
                    'hazard_categories',
                    'hazard_categories.category_id = hazard_reports.category_id'
                );
            }
            if (!empty($availableTables['stations'])) {
                $recentReportsQuery->left_join(
                    'stations',
                    'stations.station_id = hazard_reports.station_id'
                );
            }

            $recentReports = $recentReportsQuery
                ->order_by('submitted_at', 'DESC')
                ->limit(6)
                ->get_all();

            foreach ($recentReports as &$report) {
                $report['evidence'] = [];
            }
            unset($report);

            if ($recentReports && !empty($availableTables['report_evidence'])) {
                $reportIds = array_map(
                    static function ($report) {
                        return (int) $report['report_id'];
                    },
                    $recentReports
                );
                $evidence = $this->db->table('report_evidence')
                    ->select('evidence_id, report_id, file_path, evidence_type, description, uploaded_at')
                    ->in('report_id', $reportIds)
                    ->in('evidence_type', ['photo', 'video'])
                    ->order_by('uploaded_at', 'ASC')
                    ->order_by('evidence_id', 'ASC')
                    ->get_all();
                $evidenceByReport = [];

                foreach ($evidence as $item) {
                    $evidenceByReport[(int) $item['report_id']][] = $item;
                }
                foreach ($recentReports as &$report) {
                    $report['evidence'] = $evidenceByReport[(int) $report['report_id']] ?? [];
                }
                unset($report);
            }
        }

        $userSummary = [
            'active' => 0,
            'suspended' => 0,
            'verified_email' => 0
        ];
        if (!empty($availableTables['users'])) {
            $userColumns = get_table_column_names($this->db, 'users');
            $statusColumn = in_array('status', $userColumns, true)
                ? 'status'
                : (in_array('account_status', $userColumns, true) ? 'account_status' : null);
            $userSummary = [
                'active' => $statusColumn === null
                    ? $counts['users']
                    : (int) $this->db->table('users')->where($statusColumn, 'active')->count(),
                'suspended' => $statusColumn === null
                    ? 0
                    : (int) $this->db->table('users')->where($statusColumn, 'suspended')->count(),
                'verified_email' => in_array('email_verified_at', $userColumns, true)
                    ? (int) $this->db->table('users')->where_not_null('email_verified_at')->count()
                    : 0,
            ];
        }
        $activeUsers = $userSummary['active'];

        return [
            'counts' => $counts,
            'total_records' => $total,
            'report_count' => $reportCount,
            'pending_reports' => $pendingReports,
            'active_users' => $activeUsers,
            'user_summary' => $userSummary,
            'recent_reports' => $recentReports ?: [],
            'report_statuses' => $reportStatuses ?: [],
            'table_availability' => $availableTables,
            'unavailable_tables' => $unavailableTables
        ];
    }

    public function get_barangay_incident_counts()
    {
        $rows = $this->db->table('users')
            ->select('users.barangay_id, COUNT(hazard_reports.report_id) AS incident_count')
            ->join('hazard_reports', 'hazard_reports.user_id = users.user_id')
            ->where_not_null('users.barangay_id')
            ->group_by('users.barangay_id')
            ->get_all();

        $counts = [];
        foreach ($rows ?: [] as $row) {
            $counts[(string) $row['barangay_id']] = (int) $row['incident_count'];
        }

        return $counts;
    }

    private function table_exists($table)
    {
        return database_table_exists($this->db, $table);
    }

    public function get_rows($table, $search = '', $page = 1, $pageSize = 25)
    {
        $model = $this->get_table_model($table);
        if ($table !== 'users' && $table !== 'hazard_reports'
            && $model && method_exists($model, 'get_all_records')) {
            return $model->get_all_records($search, $page, $pageSize);
        }

        $info = $this->get_table_info($table);
        $columns = $this->get_column_names($table);
        $displayColumns = $columns;
        $searchColumn = $this->get_search_column($table);
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $pageSize;

        $rowsQuery = $this->db->table($table);

        if ($table === 'users') {
            $displayColumns = array_map(function ($column) {
                if ($column === 'role_id') {
                    return 'role_name';
                }
                if ($column === 'barangay_id') {
                    return 'barangay_name';
                }
                return $column;
            }, $columns);

            $selectColumns = array_map(function ($column) {
                if ($column === 'role_id') {
                    return 'roles.role_name';
                }
                if ($column === 'barangay_id') {
                    return 'barangays.barangay_name';
                }
                return 'users.' . $column;
            }, $columns);

            $rowsQuery->left_join('roles', 'roles.role_id = users.role_id')
                ->left_join('barangays', 'barangays.barangay_id = users.barangay_id');
        } else {
            $selectColumns = $columns;
        }

        $rowsQuery->select(implode(', ', $selectColumns));
        $pattern = null;
        if ($search !== '' && $searchColumn !== null) {
            if ($table === 'users') {
                $pattern = '%' . $search . '%';
                $rowsQuery->like('users.full_name', $pattern)
                    ->or_like('users.username', $pattern)
                    ->or_like('users.email', $pattern)
                    ->or_like('barangays.barangay_name', $pattern);
            } else {
                $rowsQuery->like($searchColumn, '%' . $search . '%');
            }
        }

        $rows = $rowsQuery->order_by($table . '.' . $info['primary_key'], 'DESC')
            ->limit($pageSize, $offset)
            ->get_all();
        if ($table === 'users') {
            $displayColumns = array_values(array_unique(array_merge($displayColumns, [
                'status',
                'account_status',
                'warning_strikes',
                'suspension_reason',
            ])));
            foreach ($rows ?: [] as &$row) {
                $row = $this->with_user_moderation_defaults($row);
            }
            unset($row);
        }
        if ($table === 'hazard_reports') {
            $voteCounts = $this->get_report_vote_counts(
                array_column($rows ?: [], 'report_id')
            );
            foreach ($rows as &$row) {
                $counts = $voteCounts[(int) $row['report_id']] ?? [
                    'confirm_count' => 0,
                    'dispute_count' => 0
                ];
                $row['confirm_count'] = $counts['confirm_count'];
                $row['dispute_count'] = $counts['dispute_count'];
            }
            unset($row);
            $displayColumns[] = 'confirm_count';
            $displayColumns[] = 'dispute_count';
        }

        if ($table === 'users') {
            $countQuery = $this->db->table('users')
                ->left_join('roles', 'roles.role_id = users.role_id')
                ->left_join('barangays', 'barangays.barangay_id = users.barangay_id');
            if ($pattern !== null) {
                $countQuery->like('users.full_name', $pattern)
                    ->or_like('users.username', $pattern)
                    ->or_like('users.email', $pattern)
                    ->or_like('barangays.barangay_name', $pattern);
            }
            $total = (int) $countQuery->count();
        } else {
            $total = (int) $this->db->table($table)->count();
            if ($search !== '' && $searchColumn !== null) {
                $countQuery = $this->db->table($table)
                    ->like($searchColumn, '%' . $search . '%');
                $total = (int) $countQuery->count();
            }
        }

        return [
            'columns' => $displayColumns,
            'rows' => $rows,
            'total' => $total,
            'search_column' => $searchColumn,
            'page' => $page,
            'page_size' => $pageSize
        ];
    }

    public function get_record($table, $id)
    {
        if ($table === 'users') {
            $columns = array_map(static function ($column) {
                return 'users.' . $column;
            }, $this->get_column_names('users'));
            $columns[] = 'roles.role_name AS role_name';
            $columns[] = 'barangays.barangay_name AS barangay_name';
            $record = $this->db->table('users')
                ->select(implode(', ', $columns))
                ->left_join('roles', 'roles.role_id = users.role_id')
                ->left_join('barangays', 'barangays.barangay_id = users.barangay_id')
                ->where('users.user_id', (int) $id)
                ->limit(1)
                ->get();
            return $record ? $this->with_user_moderation_defaults($record) : null;
        }

        $model = $this->get_table_model($table);
        $record = null;
        if ($model && method_exists($model, 'find')) {
            $record = $model->find($id);
        }

        if ($record === null) {
            $info = $this->get_table_info($table);
            $record = $this->db->table($table)
                ->where($info['primary_key'], $id)
                ->get();
        }
        if ($table === 'hazard_reports' && $record) {
            $counts = $this->get_report_vote_counts([(int) $record['report_id']]);
            $record['confirm_count'] = $counts[(int) $record['report_id']]['confirm_count'] ?? 0;
            $record['dispute_count'] = $counts[(int) $record['report_id']]['dispute_count'] ?? 0;
        }

        return $record;
    }

    private function with_user_moderation_defaults(array $user)
    {
        if (!array_key_exists('status', $user) || trim((string) $user['status']) === '') {
            $user['status'] = $user['account_status'] ?? 'active';
        }
        if (!array_key_exists('account_status', $user) || trim((string) $user['account_status']) === '') {
            $user['account_status'] = $user['status'];
        }
        if (!array_key_exists('warning_strikes', $user) || $user['warning_strikes'] === null) {
            $user['warning_strikes'] = 0;
        }
        if (!array_key_exists('suspension_reason', $user)) {
            $user['suspension_reason'] = null;
        }

        return $user;
    }

    private function get_report_vote_counts(array $reportIds)
    {
        $reportIds = array_values(array_unique(array_filter(array_map('intval', $reportIds))));
        if (!$reportIds) {
            return [];
        }

        $counts = [];
        $rows = $this->db->table('report_community_votes')
            ->select('report_id, vote')
            ->in('report_id', $reportIds)
            ->get_all();
        foreach ($rows ?: [] as $row) {
            $reportId = (int) $row['report_id'];
            if (!isset($counts[$reportId])) {
                $counts[$reportId] = ['confirm_count' => 0, 'dispute_count' => 0];
            }
            if ($row['vote'] === 'confirm') {
                $counts[$reportId]['confirm_count']++;
            } elseif ($row['vote'] === 'dispute') {
                $counts[$reportId]['dispute_count']++;
            }
        }

        return $counts;
    }

    public function get_hazard_notification_data($reportId)
    {
        $reportColumns = get_table_column_names($this->db, 'hazard_reports');
        $columns = [
            'hazard_reports.report_id',
            'hazard_reports.reference_number',
            'hazard_reports.title',
            'hazard_reports.description',
            'hazard_reports.address_details',
            'hazard_reports.status',
            'hazard_reports.submitted_at',
            'hazard_categories.category_name',
            'users.full_name AS reporter_name',
        ];
        if (in_array('latitude', $reportColumns, true)) {
            $columns[] = 'hazard_reports.latitude';
        }
        if (in_array('longitude', $reportColumns, true)) {
            $columns[] = 'hazard_reports.longitude';
        }

        $severityColumn = in_array('severity_level', $reportColumns, true)
            ? 'severity_level'
            : (in_array('severity', $reportColumns, true) ? 'severity' : null);
        if ($severityColumn !== null) {
            $columns[] = 'hazard_reports.' . $severityColumn . ' AS severity_level';
        }

        $report = $this->db->table('hazard_reports')
            ->select(implode(', ', $columns))
            ->left_join('hazard_categories', 'hazard_categories.category_id = hazard_reports.category_id')
            ->left_join('users', 'users.user_id = hazard_reports.user_id')
            ->where('hazard_reports.report_id', (int) $reportId)
            ->limit(1)
            ->get();

        if (!$report) {
            return null;
        }
        if (!array_key_exists('latitude', $report)) {
            $report['latitude'] = null;
        }
        if (!array_key_exists('longitude', $report)) {
            $report['longitude'] = null;
        }
        if (!array_key_exists('severity_level', $report)) {
            $report['severity_level'] = 'Not specified';
        }
        $report['reporter_name'] = trim((string) ($report['reporter_name'] ?? '')) ?: 'Deleted User';

        $report['media'] = [];
        if ($this->table_exists('report_evidence')) {
            $report['media'] = $this->db->table('report_evidence')
                ->select('file_path, evidence_type')
                ->where('report_id', (int) $reportId)
                ->in('evidence_type', ['photo', 'video'])
                ->order_by('uploaded_at', 'ASC')
                ->order_by('evidence_id', 'ASC')
                ->get_all();
        }

        return $report;
    }

    public function get_reference_options($table, $field)
    {
        if (empty($field['reference'])) {
            return [];
        }

        list($referenceTable, $labelColumn) = $field['reference'];
        $referenceInfo = $this->get_table_info($referenceTable);
        return $this->db->table($referenceTable)
            ->select($referenceInfo['primary_key'] . ', ' . $labelColumn)
            ->order_by($labelColumn, 'ASC')
            ->limit(1000)
            ->get_all();
    }

    public function validate_form_data($table, $input, $isUpdate)
    {
        $info = $this->get_table_info($table);
        if (!empty($info['read_only'])) {
            return ['data' => [], 'error' => 'This table is read-only to preserve its audit records.'];
        }

        $schema = $this->get_schema($table);
        $data = [];
        foreach ($schema as $field) {
            if (!$field['form']) {
                continue;
            }

            $formName = $field['form_name'];
            $present = array_key_exists($formName, $input);
            $value = $present ? $input[$formName] : null;

            if ($isUpdate && !$present) {
                continue;
            }

            if (!$field['password'] && !$field['nullable'] && $field['default'] === null
                && (!$present || (is_string($value) && trim($value) === ''))) {
                return ['data' => [], 'error' => $field['label'] . ' is required.'];
            }

            if ($field['password']) {
                if (!$present || $value === '') {
                    if ($isUpdate && $table === 'users') {
                        continue;
                    }
                    return ['data' => [], 'error' => $field['label'] . ' is required.'];
                }
                if (!is_string($value) || strlen($value) < 8) {
                    return ['data' => [], 'error' => $field['label'] . ' must be at least 8 characters.'];
                }
                $data[$field['name']] = password_hash($value, PASSWORD_DEFAULT);
                continue;
            }

            if (is_array($value) || is_object($value)) {
                return ['data' => [], 'error' => 'Invalid value for ' . $field['label'] . '.'];
            }

            if ($field['type'] === 'tinyint(1)' || $field['type'] === 'boolean' || $field['type'] === 'bool') {
                $data[$field['name']] = $value === '1' || $value === 1 ? 1 : 0;
                continue;
            }

            $value = is_scalar($value) ? trim((string) $value) : '';
            if ($value === '') {
                if (!$field['nullable'] && $field['default'] === null) {
                    return ['data' => [], 'error' => $field['label'] . ' is required.'];
                }
                $data[$field['name']] = null;
                continue;
            }

            if (!empty($field['enum']) && !in_array($value, $field['enum'], true)) {
                return ['data' => [], 'error' => 'Choose a valid ' . $field['label'] . '.'];
            }

            if (preg_match('/^(tinyint|smallint|mediumint|int|bigint)/', $field['type'])) {
                if (!preg_match('/^-?\d+$/', $value)) {
                    return ['data' => [], 'error' => $field['label'] . ' must be a whole number.'];
                }
            } elseif (preg_match('/^(decimal|float|double)/', $field['type'])) {
                if (!is_numeric($value)) {
                    return ['data' => [], 'error' => $field['label'] . ' must be a number.'];
                }
            } elseif (preg_match('/^(varchar|char)\((\d+)\)/', $field['type'], $matches)) {
                if (mb_strlen($value, 'UTF-8') > (int) $matches[2]) {
                    return ['data' => [], 'error' => $field['label'] . ' is too long.'];
                }
            }

            if ($field['name'] === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return ['data' => [], 'error' => 'Enter a valid email address.'];
            }

            if ($field['reference'] !== null && !$this->reference_exists($field, $value)) {
                return ['data' => [], 'error' => 'Choose an existing record for ' . $field['label'] . '.'];
            }

            if (strpos($field['type'], 'datetime') === 0 && strpos($value, 'T') !== false) {
                $value = str_replace('T', ' ', $value);
                if (strlen($value) === 16) {
                    $value .= ':00';
                }
            }

            $data[$field['name']] = $value;
        }

        if ($table === 'users' && !$isUpdate && empty($data['password_hash'])) {
            return ['data' => [], 'error' => 'Password is required.'];
        }

        if ($isUpdate && empty($data)) {
            return ['data' => [], 'error' => 'Provide at least one field to update.'];
        }

        return ['data' => $data, 'error' => null];
    }

    private function reference_exists($field, $value)
    {
        list($referenceTable) = $field['reference'];
        $referenceInfo = $this->get_table_info($referenceTable);
        return $this->db->table($referenceTable)
            ->where($referenceInfo['primary_key'], $value)
            ->count() > 0;
    }

    private function get_search_column($table)
    {
        $preferred = [
            'hazard_reports' => 'title',
            'users' => 'full_name',
            'roles' => 'role_name',
            'barangays' => 'barangay_name',
            'hazard_categories' => 'category_name',
            'stations' => 'station_name',
            'report_evidence' => 'file_path',
            'notifications' => 'title',
            'report_status_history' => 'new_status',
            'otp_verifications' => 'verification_type'
        ];
        if (isset($preferred[$table])) {
            return $preferred[$table];
        }

        foreach ($this->get_schema($table) as $field) {
            if ($field['list'] && preg_match('/^(varchar|char|text)/', $field['type'])) {
                return $field['name'];
            }
        }

        return null;
    }

    public function insert_record($table, $data)
    {
        $this->get_table_info($table);
        return $this->db->table($table)->insert($data);
    }

    public function update_record($table, $id, $data)
    {
        $info = $this->get_table_info($table);
        return $this->db->table($table)
            ->where($info['primary_key'], $id)
            ->update($data);
    }

    public function record_hazard_status_transition($reportId, $oldStatus, $newStatus, $adminId)
    {
        $this->insert_status_history(
            (int) $reportId,
            (string) $oldStatus,
            (string) $newStatus,
            (int) $adminId
        );

        if (!in_array(strtolower((string) $newStatus), ['verified', 'approved', 'disputed', 'rejected'], true)) {
            return;
        }

        $this->insert_report_verification(
            (int) $reportId,
            (string) $newStatus,
            (int) $adminId
        );
    }

    private function insert_status_history($reportId, $oldStatus, $newStatus, $adminId)
    {
        $columns = $this->workflow_table_columns('report_status_history');
        $values = [
            'report_id' => $reportId,
            'old_status' => $oldStatus,
            'previous_status' => $oldStatus,
            'from_status' => $oldStatus,
            'new_status' => $newStatus,
            'to_status' => $newStatus,
            'status' => $newStatus,
            'changed_by' => $adminId,
            'admin_id' => $adminId,
            'changed_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'change_reason' => 'Administrator status update',
            'reason' => 'Administrator status update',
            'remarks' => 'Administrator status update',
            'notes' => 'Administrator status update',
        ];
        $this->insert_workflow_row(
            'report_status_history',
            $columns,
            $values,
            ['report_id', 'new_status', 'to_status', 'status']
        );
    }

    private function insert_report_verification($reportId, $status, $adminId)
    {
        $columns = $this->workflow_table_columns('report_verifications');
        $values = [
            'report_id' => $reportId,
            'verifier_id' => $adminId,
            'verified_by' => $adminId,
            'admin_id' => $adminId,
            'verification_status' => $status,
            'status' => $status,
            'decision' => $status,
            'result' => $status,
            'verification_result' => $status,
            'is_verified' => in_array(strtolower($status), ['verified', 'approved'], true) ? 1 : 0,
            'verified_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'admin_remarks' => 'Administrator status update',
            'verification_remarks' => 'Administrator status update',
            'remarks' => 'Administrator status update',
            'notes' => 'Administrator status update',
        ];
        $this->insert_workflow_row(
            'report_verifications',
            $columns,
            $values,
            ['report_id', 'verification_status', 'status', 'decision', 'result', 'is_verified']
        );
    }

    private function workflow_table_columns($table)
    {
        $allowedTables = ['report_status_history', 'report_verifications'];
        if (!in_array($table, $allowedTables, true)) {
            throw new InvalidArgumentException('Unsupported report workflow table.');
        }

        if (!database_table_exists($this->db, $table)) {
            throw new RuntimeException('Required audit table ' . $table . ' is not available.');
        }

        return get_table_column_names($this->db, $table);
    }

    private function insert_workflow_row($table, array $columns, array $values, array $requiredCandidates)
    {
        $insertData = [];
        foreach ($values as $column => $value) {
            if (in_array($column, $columns, true)) {
                $insertData[$column] = $value;
            }
        }

        $hasRequiredData = false;
        foreach ($requiredCandidates as $candidate) {
            if (array_key_exists($candidate, $insertData)) {
                $hasRequiredData = true;
                break;
            }
        }
        if (!$hasRequiredData) {
            throw new RuntimeException('Required workflow fields are missing from ' . $table . '.');
        }

        $this->db->table($table)->insert($insertData);
    }

    public function delete_user_safely($userId)
    {
        $userId = (int) $userId;
        $user = $this->db->table('users')
            ->select('user_id, email')
            ->where('user_id', $userId)
            ->get();
        if (!$user) {
            return 0;
        }

        $this->db->transaction();
        try {
            foreach ([
                ['notifications', 'user_id'],
                ['otp_verifications', 'user_id'],
                ['email_verification_tokens', 'user_id'],
                ['password_reset_tokens', 'user_id']
            ] as $reference) {
                $this->delete_user_reference_rows($reference[0], $reference[1], $userId);
            }

            if (!empty($user['email'])) {
                $this->delete_user_reference_rows('otp_verifications', 'email', $user['email']);
                $this->delete_user_reference_rows(
                    'registration_email_verifications',
                    'email',
                    $user['email']
                );
            }

            $historicalReferences = [
                'hazard_reports' => ['user_id'],
                'report_verifications' => ['verified_by', 'verifier_id', 'user_id'],
                'report_assignments' => ['assigned_by', 'assigned_to'],
                'resolutions' => ['resolved_by', 'confirmed_by', 'user_id'],
                'report_status_history' => ['changed_by', 'user_id'],
                'report_evidence' => ['uploaded_by'],
                'duplicate_report_checks' => ['checked_by'],
                'report_community_votes' => ['user_id']
            ];
            $references = [];

            foreach ($historicalReferences as $table => $columns) {
                foreach ($columns as $column) {
                    $metadata = $this->get_user_reference_column($table, $column);
                    if ($metadata) {
                        $references[$table . '.' . $column] = $metadata;
                    }
                }
            }

            $databaseName = database_config()['main']['database'] ?? '';
            if (!is_string($databaseName) || $databaseName === '') {
                throw new RuntimeException('The active database name is not configured.');
            }
            $foreignKeys = $this->db->table('information_schema.key_column_usage')
                ->select('table_name, column_name')
                ->where('table_schema', $databaseName)
                ->where('referenced_table_name', 'users')
                ->where('referenced_column_name', 'user_id')
                ->get_all();

            foreach ($foreignKeys as $foreignKey) {
                $table = $foreignKey['table_name'];
                $column = $foreignKey['column_name'];
                if (in_array($table, [
                    'notifications',
                    'otp_verifications',
                    'email_verification_tokens',
                    'password_reset_tokens'
                ], true)) {
                    continue;
                }
                $references[$table . '.' . $column] = [
                    'table' => $table,
                    'column' => $column,
                    'nullable' => $this->get_user_reference_column($table, $column)['nullable'] ?? false
                ];
            }

            foreach ($references as $reference) {
                if (!$reference['nullable']) {
                    throw new RuntimeException(
                        'User deletion is blocked because ' . $reference['table'] . '.' .
                        $reference['column'] . ' is not nullable. Run the user-history foreign-key migration first.'
                    );
                }

                $this->db->table($reference['table'])
                    ->where($reference['column'], $userId)
                    ->update([$reference['column'] => null]);
            }

            $deleted = $this->db->table('users')
                ->where('user_id', $userId)
                ->delete();
            $this->db->commit();

            return $deleted;
        } catch (\Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    private function delete_user_reference_rows($table, $column, $value)
    {
        $metadata = $this->get_user_reference_column($table, $column);
        if (!$metadata) {
            return;
        }

        $this->db->table($table)
            ->where($column, $value)
            ->delete();
    }

    private function get_user_reference_column($table, $column)
    {
        foreach (get_table_column_metadata($this->db, $table) as $metadata) {
            if ($metadata['Field'] === $column) {
                return [
                    'table' => $table,
                    'column' => $column,
                    'nullable' => $metadata['Null'] === 'YES'
                ];
            }
        }

        return null;
    }

    public function delete_record($table, $id)
    {
        if ($table === 'users') {
            return $this->delete_user_safely($id);
        }

        $info = $this->get_table_info($table);
        return $this->db->table($table)
            ->where($info['primary_key'], $id)
            ->delete();
    }
}
