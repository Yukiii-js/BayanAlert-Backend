<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Hazard_model extends Model
{
    public function get_reports_by_date_range($start_date, $end_date)
    {
        $dayAfterEnd = $this->day_after($end_date);

        $query = $this->db->raw(
            "SELECT
                reports.report_id,
                COALESCE(
                    NULLIF(categories.category_name, ''),
                    NULLIF(reports.title, ''),
                    'Uncategorized'
                ) AS hazard_type,
                COALESCE(NULLIF(reports.address_details, ''), 'Not provided') AS location,
                'Not specified' AS severity,
                reports.status,
                reports.submitted_at AS date_reported
            FROM hazard_reports AS reports
            LEFT JOIN hazard_categories AS categories
                ON categories.category_id = reports.category_id
            WHERE reports.submitted_at >= ?
                AND reports.submitted_at < ?
            ORDER BY reports.submitted_at DESC, reports.report_id DESC",
            [
                $start_date . ' 00:00:00',
                $dayAfterEnd . ' 00:00:00'
            ]
        );

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get_barangay_report($start_date, $end_date, $barangay_id = null)
    {
        $dayAfterEnd = $this->day_after($end_date);
        $barangayFilter = $barangay_id === null ? '' : ' AND barangays.barangay_id = ?';
        $parameters = [
            $start_date . ' 00:00:00',
            $dayAfterEnd . ' 00:00:00'
        ];
        if ($barangay_id !== null) {
            $parameters[] = $barangay_id;
        }

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
            WHERE barangays.is_active = 1{$barangayFilter}
            GROUP BY barangays.barangay_id, barangays.barangay_name
            ORDER BY barangays.barangay_name",
            $parameters
        )->fetchAll(PDO::FETCH_ASSOC);

        $historyParameters = [
            $start_date . ' 00:00:00',
            $dayAfterEnd . ' 00:00:00'
        ];
        $historyBarangayFilter = '';
        if ($barangay_id !== null) {
            $historyBarangayFilter = ' AND barangays.barangay_id = ?';
            $historyParameters[] = $barangay_id;
        }

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
                reports.status,
                reports.submitted_at AS date_reported
            FROM hazard_reports AS reports
            INNER JOIN users ON users.user_id = reports.user_id
            INNER JOIN barangays ON barangays.barangay_id = users.barangay_id
            LEFT JOIN hazard_categories AS categories
                ON categories.category_id = reports.category_id
            WHERE reports.submitted_at >= ?
                AND reports.submitted_at < ?
                AND barangays.is_active = 1{$historyBarangayFilter}
            ORDER BY barangays.barangay_name, reports.submitted_at DESC, reports.report_id DESC",
            $historyParameters
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'barangays' => $summary,
            'history' => $history
        ];
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
