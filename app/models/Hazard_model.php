<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Hazard_model extends Model
{
    public function get_reports_by_date_range($start_date, $end_date, $filters = [])
    {
        $dayAfterEnd = $this->day_after($end_date);
        $filter = $this->report_filter_sql($filters);
        $detail = $this->report_detail_fields();

        $query = $this->db->raw(
            "SELECT
                reports.report_id,
                COALESCE(
                    NULLIF(categories.category_name, ''),
                    NULLIF(reports.title, ''),
                    'Uncategorized'
                ) AS hazard_type,
                COALESCE(NULLIF(reports.address_details, ''), 'Not provided') AS location,
                {$filter['severity_select']},
                {$detail['select']},
                reports.status,
                COALESCE(barangays.barangay_name, 'Former Resident') AS barangay_name,
                reports.submitted_at AS date_reported
            FROM hazard_reports AS reports
            LEFT JOIN hazard_categories AS categories
                ON categories.category_id = reports.category_id
            LEFT JOIN users ON users.user_id = reports.user_id
            LEFT JOIN barangays ON barangays.barangay_id = users.barangay_id
            {$detail['joins']}
            WHERE reports.submitted_at >= ?
                AND reports.submitted_at < ?
                {$filter['where']}
            ORDER BY reports.submitted_at DESC, reports.report_id DESC",
            array_merge([
                $start_date . ' 00:00:00',
                $dayAfterEnd . ' 00:00:00'
            ], $filter['parameters'])
        );

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get_barangay_report($start_date, $end_date, $barangay_id = null, $filters = [])
    {
        $dayAfterEnd = $this->day_after($end_date);
        if ($barangay_id !== null) {
            $filters['barangay_id'] = $barangay_id;
        }
        $filter = $this->report_filter_sql($filters);
        $parameters = [
            $start_date . ' 00:00:00',
            $dayAfterEnd . ' 00:00:00',
        ];

        $summary = $this->db->raw(
            "SELECT
                barangays.barangay_id,
                barangays.barangay_name,
                COUNT(DISTINCT CASE
                    WHEN roles.role_name IN ('resident', 'user')
                        AND users.account_status = 'active'
                    THEN users.user_id
                END) AS registered_residents,
                COUNT(DISTINCT reports.report_id) AS total_hazards,
                COUNT(DISTINCT CASE WHEN reports.status = 'pending' THEN reports.report_id END) AS pending_hazards,
                COUNT(DISTINCT CASE WHEN reports.status = 'verified' THEN reports.report_id END) AS verified_hazards,
                COUNT(DISTINCT CASE WHEN reports.status = 'assigned' THEN reports.report_id END) AS assigned_hazards,
                COUNT(DISTINCT CASE WHEN reports.status = 'in_progress' THEN reports.report_id END) AS in_progress_hazards,
                COUNT(DISTINCT CASE WHEN reports.status = 'resolved' THEN reports.report_id END) AS resolved_hazards,
                COUNT(DISTINCT CASE WHEN reports.status = 'rejected' THEN reports.report_id END) AS rejected_hazards
            FROM barangays
            LEFT JOIN users ON users.barangay_id = barangays.barangay_id
            LEFT JOIN roles ON roles.role_id = users.role_id
            LEFT JOIN hazard_reports AS reports
                ON reports.user_id = users.user_id
                AND reports.submitted_at >= ?
                AND reports.submitted_at < ?
                {$filter['join_where']}
            WHERE 1 = 1{$filter['summary_where']}
            GROUP BY barangays.barangay_id, barangays.barangay_name
            ORDER BY barangays.barangay_name",
            array_merge($parameters, $filter['join_parameters'], $filter['summary_parameters'])
        )->fetchAll(PDO::FETCH_ASSOC);

        $historyParameters = [
            $start_date . ' 00:00:00',
            $dayAfterEnd . ' 00:00:00'
        ];
        $detail = $this->report_detail_fields();

        $history = $this->db->raw(
            "SELECT
                barangays.barangay_name,
                reports.report_id,
                COALESCE(
                    NULLIF(categories.category_name, ''),
                    NULLIF(reports.title, ''),
                    'Uncategorized'
                ) AS hazard_type,
                COALESCE(NULLIF(reports.address_details, ''), 'Not provided') AS location,
                {$filter['severity_select']},
                {$detail['select']},
                reports.status,
                reports.submitted_at AS date_reported
            FROM hazard_reports AS reports
            LEFT JOIN users ON users.user_id = reports.user_id
            LEFT JOIN barangays ON barangays.barangay_id = users.barangay_id
            LEFT JOIN hazard_categories AS categories
                ON categories.category_id = reports.category_id
            {$detail['joins']}
            WHERE reports.submitted_at >= ?
                AND reports.submitted_at < ?
                {$filter['where']}
            ORDER BY barangays.barangay_name, reports.submitted_at DESC, reports.report_id DESC",
            array_merge($historyParameters, $filter['parameters'])
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'barangays' => $summary,
            'history' => $history
        ];
    }

    public function get_report_filter_options()
    {
        $reportColumns = array_column(
            $this->db->raw('SHOW COLUMNS FROM `hazard_reports`')->fetchAll(PDO::FETCH_ASSOC),
            'Field'
        );
        $severityColumn = in_array('severity_level', $reportColumns, true)
            ? 'severity_level'
            : (in_array('severity', $reportColumns, true) ? 'severity' : null);
        $severityValues = [];

        if ($severityColumn !== null) {
            $severityValues = $this->db->raw(
                'SELECT DISTINCT `' . $severityColumn . '` AS severity FROM hazard_reports ' .
                'WHERE `' . $severityColumn . '` IS NOT NULL AND `' . $severityColumn . '` <> \'\' ' .
                'ORDER BY severity'
            )->fetchAll(PDO::FETCH_COLUMN);
        }

        return [
            'statuses' => $this->db->raw(
                'SELECT DISTINCT status FROM hazard_reports WHERE status IS NOT NULL AND status <> \'\' ORDER BY status'
            )->fetchAll(PDO::FETCH_COLUMN),
            'severities' => $severityValues,
            'categories' => $this->db->raw(
                'SELECT category_id, category_name FROM hazard_categories ORDER BY category_name'
            )->fetchAll(PDO::FETCH_ASSOC),
            'barangays' => $this->db->raw(
                'SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name'
            )->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    private function report_filter_sql($filters)
    {
        $reportColumns = array_column(
            $this->db->raw('SHOW COLUMNS FROM `hazard_reports`')->fetchAll(PDO::FETCH_ASSOC),
            'Field'
        );
        $severityColumn = in_array('severity_level', $reportColumns, true)
            ? 'severity_level'
            : (in_array('severity', $reportColumns, true) ? 'severity' : null);
        $severitySelect = $severityColumn === null
            ? "'Not specified' AS severity"
            : 'reports.`' . $severityColumn . '` AS severity';
        $conditionDetails = [];
        $parameters = [];

        $statuses = $filters['statuses'] ?? ($filters['status'] ?? []);
        $statuses = is_array($statuses) ? $statuses : [$statuses];
        $statuses = array_values(array_filter($statuses, static function ($value) {
            return $value !== '';
        }));
        if (!empty($statuses)) {
            $conditionDetails[] = [
                'sql' => 'reports.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')',
                'parameters' => $statuses,
            ];
        }

        if (isset($filters['category_id']) && $filters['category_id'] !== '') {
            $conditionDetails[] = [
                'sql' => 'reports.category_id = ?',
                'parameters' => [$filters['category_id']],
            ];
        }

        $severities = $filters['severities'] ?? ($filters['severity'] ?? []);
        $severities = is_array($severities) ? $severities : [$severities];
        $severities = array_values(array_filter($severities, static function ($value) {
            return $value !== '';
        }));
        if (!empty($severities) && $severityColumn !== null) {
            $conditionDetails[] = [
                'sql' => 'reports.`' . $severityColumn . '` IN (' . implode(',', array_fill(0, count($severities), '?')) . ')',
                'parameters' => $severities,
            ];
        } elseif (!empty($severities)) {
            $conditionDetails[] = [
                'sql' => 'reports.report_id = ?',
                'parameters' => [-1],
            ];
        }

        if (isset($filters['barangay_id']) && $filters['barangay_id'] !== '') {
            $conditionDetails[] = [
                'sql' => 'users.barangay_id = ?',
                'parameters' => [$filters['barangay_id']],
            ];
        }

        $conditions = [];
        $joinConditions = [];
        $joinParameters = [];
        foreach ($conditionDetails as $detail) {
            $conditions[] = $detail['sql'];
            $parameters = array_merge($parameters, $detail['parameters']);
            if (strpos($detail['sql'], 'reports.') !== false) {
                $joinConditions[] = $detail['sql'];
                $joinParameters = array_merge($joinParameters, $detail['parameters']);
            }
        }

        $where = empty($conditions) ? '' : ' AND ' . implode(' AND ', $conditions);
        $joinWhere = empty($joinConditions) ? '' : ' AND ' . implode(' AND ', $joinConditions);
        $summaryWhere = isset($filters['barangay_id']) && $filters['barangay_id'] !== ''
            ? ' AND barangays.barangay_id = ?'
            : '';

        return [
            'where' => $where,
            'join_where' => $joinWhere,
            'parameters' => $parameters,
            'join_parameters' => $joinParameters,
            'summary_where' => $summaryWhere,
            'summary_parameters' => $summaryWhere === '' ? [] : [$filters['barangay_id']],
            'severity_select' => $severitySelect,
        ];
    }

    private function report_detail_fields()
    {
        $userColumns = $this->table_columns('users');
        $reportColumns = $this->table_columns('hazard_reports');
        $resolutionColumns = $this->table_columns('resolutions');
        $verificationColumns = $this->table_columns('report_verifications');
        $select = [
            in_array('full_name', $userColumns, true)
            ? "COALESCE(NULLIF(users.full_name, ''), 'Deleted User') AS reporter_name"
            : "'Deleted User' AS reporter_name",
        ];

        $contactParts = [];
        foreach (['email', 'phone_number', 'mobile_number', 'contact_number', 'phone_no', 'mobile_phone', 'telephone', 'phone', 'mobile', 'contact'] as $candidate) {
            if (in_array($candidate, $userColumns, true)) {
                $contactParts[] = 'NULLIF(users.`' . $candidate . '`, \'\')';
            }
        }
        foreach (['reporter_contact', 'reporter_phone', 'contact_number', 'phone_number'] as $candidate) {
            if (in_array($candidate, $reportColumns, true)) {
                $contactParts[] = 'NULLIF(reports.`' . $candidate . '`, \'\')';
            }
        }
        $select[] = empty($contactParts)
            ? 'NULL AS reporter_contact'
            : 'NULLIF(CONCAT_WS(\' | \', ' . implode(', ', $contactParts) . '), \'\') AS reporter_contact';

        $coordinatesAvailable = in_array('latitude', $reportColumns, true)
            && in_array('longitude', $reportColumns, true);
        $select[] = $coordinatesAvailable
            ? 'CONCAT(CAST(reports.latitude AS CHAR), \', \', CAST(reports.longitude AS CHAR)) AS coordinates'
            : 'NULL AS coordinates';

        $joins = [];
        $resolutionJoin = '';
        $resolutionTimeColumn = $this->first_column(
            $resolutionColumns,
            ['resolved_at', 'resolution_at', 'resolution_date', 'confirmed_at', 'created_at', 'updated_at']
        );
        if (in_array('report_id', $resolutionColumns, true)) {
            if (in_array('resolution_id', $resolutionColumns, true)) {
                $resolutionJoin = 'LEFT JOIN resolutions AS resolution_info ' .
                    'ON resolution_info.report_id = reports.report_id ' .
                    'AND resolution_info.resolution_id = (' .
                    'SELECT MAX(latest_resolution.resolution_id) FROM resolutions AS latest_resolution ' .
                    'WHERE latest_resolution.report_id = reports.report_id)';
            } elseif ($resolutionTimeColumn !== null) {
                $resolutionJoin = 'LEFT JOIN resolutions AS resolution_info ' .
                    'ON resolution_info.report_id = reports.report_id ' .
                    'AND resolution_info.`' . $resolutionTimeColumn . '` = (' .
                    'SELECT MAX(latest_resolution.`' . $resolutionTimeColumn . '`) FROM resolutions AS latest_resolution ' .
                    'WHERE latest_resolution.report_id = reports.report_id)';
            }
        }
        if ($resolutionJoin !== '') {
            $joins[] = $resolutionJoin;
        }

        $resolutionNoteColumn = $this->first_column(
            $reportColumns,
            ['resolution_notes', 'resolution_note', 'resolution_details', 'resolution_description', 'resolution']
        );
        if ($resolutionNoteColumn !== null) {
            $select[] = 'reports.`' . $resolutionNoteColumn . '` AS resolution_notes';
        } else {
            $resolutionNoteColumn = $this->first_column(
                $resolutionColumns,
                ['resolution_notes', 'resolution_note', 'resolution_details', 'resolution_description', 'details', 'description', 'notes', 'remarks', 'comment']
            );
            $select[] = $resolutionNoteColumn !== null && $resolutionJoin !== ''
                ? 'resolution_info.`' . $resolutionNoteColumn . '` AS resolution_notes'
                : 'NULL AS resolution_notes';
        }

        $timestampExpressions = [];
        if (in_array('resolved_at', $reportColumns, true)) {
            $timestampExpressions[] = 'reports.resolved_at';
        }
        if ($resolutionTimeColumn !== null && $resolutionJoin !== '') {
            $timestampExpressions[] = 'resolution_info.`' . $resolutionTimeColumn . '`';
        }
        $select[] = empty($timestampExpressions)
            ? 'NULL AS resolution_timestamp'
            : 'COALESCE(' . implode(', ', $timestampExpressions) . ') AS resolution_timestamp';

        $adminRemarksColumn = $this->first_column(
            $verificationColumns,
            ['admin_remarks', 'verification_remarks', 'remarks', 'remark', 'notes']
        );
        if ($adminRemarksColumn !== null && in_array('report_id', $verificationColumns, true)
            && in_array('verification_id', $verificationColumns, true)) {
            $joins[] = 'LEFT JOIN report_verifications AS verification_info ' .
                'ON verification_info.report_id = reports.report_id ' .
                'AND verification_info.verification_id = (' .
                'SELECT MAX(latest_verification.verification_id) FROM report_verifications AS latest_verification ' .
                'WHERE latest_verification.report_id = reports.report_id)';
            $select[] = 'verification_info.`' . $adminRemarksColumn . '` AS admin_remarks';
        } else {
            $adminRemarksColumn = $this->first_column(
                $reportColumns,
                ['admin_remarks', 'verification_remarks', 'remarks', 'remark']
            );
            $select[] = $adminRemarksColumn !== null
                ? 'reports.`' . $adminRemarksColumn . '` AS admin_remarks'
                : 'NULL AS admin_remarks';
        }

        return [
            'select' => implode(",\n                ", $select),
            'joins' => implode("\n            ", $joins),
        ];
    }

    private function table_columns($table)
    {
        if (!in_array($table, ['users', 'hazard_reports', 'resolutions', 'report_verifications'], true)) {
            return [];
        }
        $tableExists = $this->db->raw("SHOW TABLES LIKE '" . $table . "'")->fetch(PDO::FETCH_NUM);
        if (!$tableExists) {
            return [];
        }

        return array_column(
            $this->db->raw('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC),
            'Field'
        );
    }

    private function first_column($available, $candidates)
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $available, true)) {
                return $candidate;
            }
        }
        return null;
    }

    private function day_after($date)
    {
        $dayAfterEnd = DateTime::createFromFormat('!Y-m-d', $date);
        if ($dayAfterEnd === false) {
            throw new InvalidArgumentException('The report end date is invalid.');
        }

        return $dayAfterEnd->modify('+1 day')->format('Y-m-d');
    }
}
