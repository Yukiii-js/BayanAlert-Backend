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

        $rows = $this->db->raw('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
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
            $recentReports = $this->db->table('hazard_reports')
                ->select('report_id, reference_number, title, status, submitted_at')
                ->order_by('submitted_at', 'DESC')
                ->limit(6)
                ->get_all();
        }

        $activeUsers = !empty($availableTables['users'])
            ? (int) $this->db->table('users')->where('account_status', 'active')->count()
            : 0;

        return [
            'counts' => $counts,
            'total_records' => $total,
            'report_count' => $reportCount,
            'pending_reports' => $pendingReports,
            'active_users' => $activeUsers,
            'recent_reports' => $recentReports ?: [],
            'report_statuses' => $reportStatuses ?: [],
            'table_availability' => $availableTables,
            'unavailable_tables' => $unavailableTables
        ];
    }

    private function table_exists($table)
    {
        $result = $this->db->raw(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );

        return (int) $result->fetchColumn() > 0;
    }

    public function get_rows($table, $search = '', $page = 1, $pageSize = 25)
    {
        $model = $this->get_table_model($table);
        if ($model && method_exists($model, 'get_all_records')) {
            return $model->get_all_records($search, $page, $pageSize);
        }

        $info = $this->get_table_info($table);
        $columns = $this->get_column_names($table);
        $displayColumns = $columns;
        $searchColumn = $this->get_search_column($table);
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $pageSize;

        $countQuery = $this->db->table($table);
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
                    return 'roles.role_name AS role_name';
                }
                if ($column === 'barangay_id') {
                    return 'barangays.barangay_name AS barangay_name';
                }
                return 'users.' . $column;
            }, $columns);

            $rowsQuery->left_join('roles', 'roles.role_id = users.role_id')
                ->left_join('barangays', 'barangays.barangay_id = users.barangay_id');
        } else {
            $selectColumns = $columns;
        }

        $rowsQuery->select(implode(', ', $selectColumns));
        if ($search !== '' && $searchColumn !== null) {
            $countQuery->like($searchColumn, '%' . $search . '%');
            $rowsQuery->like($searchColumn, '%' . $search . '%');
        }

        return [
            'columns' => $displayColumns,
            'rows' => $rowsQuery->order_by($table . '.' . $info['primary_key'], 'DESC')
                ->limit($pageSize, $offset)
                ->get_all(),
            'total' => (int) $countQuery->count(),
            'search_column' => $searchColumn,
            'page' => $page,
            'page_size' => $pageSize
        ];
    }

    public function get_record($table, $id)
    {
        $model = $this->get_table_model($table);
        if ($model && method_exists($model, 'find')) {
            $record = $model->find($id);
            if ($record !== null) {
                return $record;
            }
        }

        $info = $this->get_table_info($table);
        return $this->db->table($table)
            ->where($info['primary_key'], $id)
            ->get();
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

            $value = is_string($value) ? trim($value) : '';
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

    public function delete_record($table, $id)
    {
        $info = $this->get_table_info($table);
        return $this->db->table($table)
            ->where($info['primary_key'], $id)
            ->delete();
    }
}
