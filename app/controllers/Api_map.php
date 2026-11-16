<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Api_map extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    public function reports()
    {
        try {
            $columns = get_table_column_names($this->db, 'hazard_reports');
            $coordinatesAvailable = in_array('latitude', $columns, true)
                && in_array('longitude', $columns, true);

            $categories = $this->db->table('hazard_categories')
                ->select('category_id, category_name')
                ->where('is_active', 1)
                ->get_all();

            $reports = [];
            if ($coordinatesAvailable) {
                $select = [
                    'r.report_id',
                    'r.reference_number',
                    'r.category_id',
                    'r.status',
                    'r.latitude',
                    'r.longitude',
                    'c.category_name',
                ];
                if (in_array('severity_level', $columns, true)) {
                    $select[] = 'r.severity_level';
                }
                if (in_array('title', $columns, true)) {
                    $select[] = 'r.title';
                }
                if (in_array('description', $columns, true)) {
                    $select[] = 'LEFT(r.description, 240) AS description';
                }
                if (in_array('submitted_at', $columns, true)) {
                    $select[] = 'r.submitted_at';
                }
                $sortColumn = in_array('submitted_at', $columns, true)
                    ? 'r.submitted_at'
                    : 'r.report_id';

                $reports = $this->db->table('hazard_reports AS r')
                    ->select(implode(', ', $select))
                    ->left_join('hazard_categories AS c', 'c.category_id = r.category_id')
                    ->where('r.latitude', '>=', 13.25)
                    ->where('r.latitude', '<=', 13.55)
                    ->where('r.longitude', '>=', 121.05)
                    ->where('r.longitude', '<=', 121.32)
                    ->order_by($sortColumn, 'DESC')
                    ->limit(1000)
                    ->get_all();

                $voteCounts = [];
                if (database_table_exists($this->db, 'report_community_votes')) {
                    $counts = $this->db->table('report_community_votes')
                        ->select(
                            "report_id, SUM(CASE WHEN vote = 'confirm' THEN 1 ELSE 0 END) AS confirm_count, " .
                            "SUM(CASE WHEN vote = 'dispute' THEN 1 ELSE 0 END) AS dispute_count"
                        )
                        ->group_by('report_id')
                        ->get_all();
                    foreach ($counts as $count) {
                        $voteCounts[(int) $count['report_id']] = [
                            'confirm_count' => (int) $count['confirm_count'],
                            'dispute_count' => (int) $count['dispute_count'],
                        ];
                    }
                }

                $reports = array_values(array_filter($reports, static function ($report) {
                    return strtolower((string) ($report['status'] ?? '')) !== 'resolved';
                }));
                $reports = array_slice($reports, 0, 500);
                foreach ($reports as &$report) {
                    $report['severity_level'] = $report['severity_level'] ?? null;
                    $report['title'] = $report['title'] ?? '';
                    $report['description'] = $report['description'] ?? '';
                    $report['submitted_at'] = $report['submitted_at'] ?? null;
                    $report += $voteCounts[(int) $report['report_id']] ?? [
                        'confirm_count' => 0,
                        'dispute_count' => 0,
                    ];
                }
                unset($report);
            }

            api_response([
                'reports' => $reports ?: [],
                'categories' => $categories ?: [],
                'coordinates_available' => $coordinatesAvailable
            ]);
        } catch (\Throwable $e) {
            error_log('Hazard map data error: ' . $e->getMessage());
            api_error('Unable to load hazard map data.', 500);
        }
    }
}
