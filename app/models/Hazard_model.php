<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Hazard_model extends Model
{
    public function get_reports_by_date_range($start_date, $end_date, $filters = [])
    {
        return $this->format_reports($this->query_reports($start_date, $end_date, $filters));
    }

    public function get_barangay_report($start_date, $end_date, $barangay_id = null, $filters = [])
    {
        if ($barangay_id !== null) {
            $filters['barangay_id'] = $barangay_id;
        }

        $reports = $this->query_reports($start_date, $end_date, $filters);
        $history = $this->format_reports($reports);
        $barangays = $this->db->table('barangays')
            ->select('barangay_id, barangay_name')
            ->order_by('barangay_name', 'ASC')
            ->get_all();
        $summary = [];
        foreach ($barangays ?: [] as $barangay) {
            $summary[(int) $barangay['barangay_id']] = [
                'barangay_id' => (int) $barangay['barangay_id'],
                'barangay_name' => $barangay['barangay_name'],
                'registered_residents' => 0,
                'total_hazards' => 0,
                'pending_hazards' => 0,
                'verified_hazards' => 0,
                'assigned_hazards' => 0,
                'in_progress_hazards' => 0,
                'resolved_hazards' => 0,
                'rejected_hazards' => 0,
            ];
        }

        if (isset($filters['barangay_id']) && $filters['barangay_id'] !== '') {
            $selectedId = (int) $filters['barangay_id'];
            $summary = isset($summary[$selectedId]) ? [$selectedId => $summary[$selectedId]] : [];
        }

        $residentRows = $this->db->table('users')
            ->select('users.barangay_id, roles.role_name, users.account_status')
            ->join('roles', 'roles.role_id = users.role_id')
            ->where('users.account_status', 'active')
            ->in('roles.role_name', ['resident', 'user'])
            ->get_all();
        foreach ($residentRows ?: [] as $resident) {
            $id = (int) ($resident['barangay_id'] ?? 0);
            if (isset($summary[$id])) {
                $summary[$id]['registered_residents']++;
            }
        }

        $statusFields = [
            'pending' => 'pending_hazards',
            'verified' => 'verified_hazards',
            'assigned' => 'assigned_hazards',
            'in_progress' => 'in_progress_hazards',
            'resolved' => 'resolved_hazards',
            'rejected' => 'rejected_hazards',
        ];
        foreach ($reports as $report) {
            $id = (int) ($report['reporter_barangay_id'] ?? 0);
            if (!isset($summary[$id])) {
                continue;
            }
            $summary[$id]['total_hazards']++;
            $status = strtolower(trim((string) ($report['status'] ?? '')));
            if (isset($statusFields[$status])) {
                $summary[$id][$statusFields[$status]]++;
            }
        }

        return [
            'barangays' => array_values($summary),
            'history' => $history,
        ];
    }

    public function get_report_filter_options()
    {
        $reportColumns = get_table_column_names($this->db, 'hazard_reports');
        $severityColumn = $this->severity_column($reportColumns);
        $severityValues = [];

        if ($severityColumn !== null) {
            $severityRows = $this->db->table('hazard_reports')
                ->select($severityColumn)
                ->where_not_null($severityColumn)
                ->where($severityColumn, '!=', '')
                ->group_by($severityColumn)
                ->order_by($severityColumn, 'ASC')
                ->get_all();
            $severityValues = array_values(array_map(static function ($row) use ($severityColumn) {
                return $row[$severityColumn];
            }, $severityRows ?: []));
        }

        $statusRows = $this->db->table('hazard_reports')
            ->select('status')
            ->where_not_null('status')
            ->where('status', '!=', '')
            ->group_by('status')
            ->order_by('status', 'ASC')
            ->get_all();

        return [
            'statuses' => array_column($statusRows ?: [], 'status'),
            'severities' => $severityValues,
            'categories' => $this->db->table('hazard_categories')
                ->select('category_id, category_name')
                ->order_by('category_name', 'ASC')
                ->get_all(),
            'barangays' => $this->db->table('barangays')
                ->select('barangay_id, barangay_name')
                ->order_by('barangay_name', 'ASC')
                ->get_all(),
        ];
    }

    private function query_reports($startDate, $endDate, array $filters)
    {
        $end = $this->day_after($endDate);
        $reportColumns = get_table_column_names($this->db, 'hazard_reports');
        $userColumns = get_table_column_names($this->db, 'users');
        $columns = array_map(static function ($column) {
            return 'hazard_reports.' . $column;
        }, $reportColumns);
        $columns = array_merge($columns, [
            'hazard_categories.category_name',
            'users.full_name AS reporter_name',
            'users.barangay_id AS reporter_barangay_id',
            'users.account_status AS reporter_account_status',
            'roles.role_name AS reporter_role',
            'barangays.barangay_name',
        ]);
        foreach (['email', 'phone_number', 'mobile_number', 'contact_number', 'phone_no', 'mobile_phone', 'telephone', 'phone', 'mobile', 'contact'] as $candidate) {
            if (in_array($candidate, $userColumns, true)) {
                $columns[] = 'users.' . $candidate . ' AS user_contact_' . $candidate;
            }
        }

        $query = $this->db->table('hazard_reports')
            ->select(implode(', ', $columns))
            ->left_join('hazard_categories', 'hazard_categories.category_id = hazard_reports.category_id')
            ->left_join('users', 'users.user_id = hazard_reports.user_id')
            ->left_join('roles', 'roles.role_id = users.role_id')
            ->left_join('barangays', 'barangays.barangay_id = users.barangay_id')
            ->where('hazard_reports.submitted_at', '>=', $startDate . ' 00:00:00')
            ->where('hazard_reports.submitted_at', '<', $end . ' 00:00:00');

        $statuses = $filters['statuses'] ?? ($filters['status'] ?? []);
        $statuses = is_array($statuses) ? $statuses : [$statuses];
        $statuses = array_values(array_filter($statuses, static function ($value) {
            return $value !== '';
        }));
        if ($statuses) {
            $query->in('hazard_reports.status', $statuses);
        }
        if (isset($filters['category_id']) && $filters['category_id'] !== '') {
            $query->where('hazard_reports.category_id', $filters['category_id']);
        }
        $severities = $filters['severities'] ?? ($filters['severity'] ?? []);
        $severities = is_array($severities) ? $severities : [$severities];
        $severities = array_values(array_filter($severities, static function ($value) {
            return $value !== '';
        }));
        $severityColumn = $this->severity_column($reportColumns);
        if ($severities) {
            if ($severityColumn === null) {
                return [];
            }
            $query->in('hazard_reports.' . $severityColumn, $severities);
        }
        if (isset($filters['barangay_id']) && $filters['barangay_id'] !== '') {
            $query->where('users.barangay_id', $filters['barangay_id']);
        }

        return $query
            ->order_by('hazard_reports.submitted_at', 'DESC')
            ->order_by('hazard_reports.report_id', 'DESC')
            ->get_all() ?: [];
    }

    private function format_reports(array $reports)
    {
        if (!$reports) {
            return [];
        }
        $reportIds = array_values(array_unique(array_map(static function ($report) {
            return (int) $report['report_id'];
        }, $reports)));
        $resolutionRows = $this->latest_related_rows(
            'resolutions',
            $reportIds,
            ['resolved_at', 'resolution_at', 'resolution_date', 'confirmed_at', 'created_at', 'updated_at'],
            ['resolution_notes', 'resolution_note', 'resolution_details', 'resolution_description', 'details', 'description', 'notes', 'remarks', 'comment']
        );
        $verificationRows = $this->latest_related_rows(
            'report_verifications',
            $reportIds,
            ['verification_id'],
            ['admin_remarks', 'verification_remarks', 'remarks', 'remark', 'notes']
        );
        $reportColumns = get_table_column_names($this->db, 'hazard_reports');
        $contactColumns = array_values(array_filter(
            ['reporter_contact', 'reporter_phone', 'contact_number', 'phone_number'],
            static function ($column) use ($reportColumns) {
                return in_array($column, $reportColumns, true);
            }
        ));

        $formatted = [];
        foreach ($reports as $report) {
            $id = (int) $report['report_id'];
            $contactValues = [];
            foreach ($contactColumns as $column) {
                if (!empty($report[$column])) {
                    $contactValues[] = trim((string) $report[$column]);
                }
            }
            foreach (['email', 'phone_number', 'mobile_number', 'contact_number', 'phone_no', 'mobile_phone', 'telephone', 'phone', 'mobile', 'contact'] as $candidate) {
                $key = 'user_contact_' . $candidate;
                if (!empty($report[$key])) {
                    $contactValues[] = trim((string) $report[$key]);
                }
            }

            $resolution = $resolutionRows[$id] ?? [];
            $verification = $verificationRows[$id] ?? [];
            $resolutionNotes = null;
            foreach (['resolution_notes', 'resolution_note', 'resolution_details', 'resolution_description', 'details', 'description', 'notes', 'remarks', 'comment'] as $candidate) {
                if (!empty($report[$candidate])) {
                    $resolutionNotes = $report[$candidate];
                    break;
                }
                if (!empty($resolution[$candidate])) {
                    $resolutionNotes = $resolution[$candidate];
                    break;
                }
            }

            $coordinates = null;
            if (isset($report['latitude'], $report['longitude'])
                && $report['latitude'] !== null && $report['longitude'] !== null) {
                $coordinates = (string) $report['latitude'] . ', ' . (string) $report['longitude'];
            }

            $formatted[] = [
                'report_id' => $id,
                'hazard_type' => trim((string) ($report['category_name'] ?? ''))
                    ?: (trim((string) ($report['title'] ?? '')) ?: 'Uncategorized'),
                'location' => trim((string) ($report['address_details'] ?? '')) ?: 'Not provided',
                'severity' => trim((string) ($report['severity_level'] ?? $report['severity'] ?? '')) ?: 'Not specified',
                'reporter_name' => trim((string) ($report['reporter_name'] ?? '')) ?: 'Deleted User',
                'reporter_contact' => $contactValues ? implode(' | ', array_values(array_unique($contactValues))) : null,
                'coordinates' => $coordinates,
                'resolution_notes' => $resolutionNotes,
                'resolution_timestamp' => $report['resolved_at'] ?? ($resolution['resolved_at'] ?? $resolution['resolution_at'] ?? $resolution['resolution_date'] ?? $resolution['confirmed_at'] ?? $resolution['created_at'] ?? $resolution['updated_at'] ?? null),
                'admin_remarks' => $report['admin_remarks'] ?? ($verification['admin_remarks'] ?? $verification['verification_remarks'] ?? $verification['remarks'] ?? $verification['remark'] ?? $verification['notes'] ?? null),
                'status' => $report['status'] ?? null,
                'barangay_name' => $report['barangay_name'] ?? 'Former Resident',
                'date_reported' => $report['submitted_at'] ?? null,
            ];
        }

        return $formatted;
    }

    private function latest_related_rows($table, array $reportIds, array $orderCandidates, array $valueCandidates)
    {
        if (!database_table_exists($this->db, $table)) {
            return [];
        }
        $available = get_table_column_names($this->db, $table);
        if (!in_array('report_id', $available, true)) {
            return [];
        }
        $orderColumn = $this->first_column($available, $orderCandidates);
        $valueColumns = array_values(array_filter($valueCandidates, static function ($column) use ($available) {
            return in_array($column, $available, true);
        }));
        if ($orderColumn === null && !$valueColumns) {
            return [];
        }
        $columns = array_merge(['report_id'], $valueColumns);
        if ($orderColumn !== null) {
            $columns[] = $orderColumn;
        }
        $query = $this->db->table($table)
            ->select(implode(', ', array_unique($columns)))
            ->in('report_id', $reportIds);
        if ($orderColumn !== null) {
            $query->order_by($orderColumn, 'DESC');
        }
        $rows = $query->get_all();
        $latest = [];
        foreach ($rows ?: [] as $row) {
            $id = (int) $row['report_id'];
            if (!isset($latest[$id])) {
                $latest[$id] = $row;
            }
        }
        return $latest;
    }

    private function severity_column(array $reportColumns)
    {
        if (in_array('severity_level', $reportColumns, true)) {
            return 'severity_level';
        }
        return in_array('severity', $reportColumns, true) ? 'severity' : null;
    }

    private function first_column(array $available, array $candidates)
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
