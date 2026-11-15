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
            $columns = $this->db->raw('SHOW COLUMNS FROM `hazard_reports`')
                ->fetchAll(PDO::FETCH_COLUMN);
            $coordinatesAvailable = in_array('latitude', $columns, true)
                && in_array('longitude', $columns, true);
            $severityColumn = in_array('severity_level', $columns, true)
                ? 'r.severity_level'
                : 'NULL AS severity_level';
            $titleColumn = in_array('title', $columns, true)
                ? 'r.title'
                : "'' AS title";
            $descriptionColumn = in_array('description', $columns, true)
                ? 'LEFT(r.description, 240) AS description'
                : "'' AS description";
            $submittedAtColumn = in_array('submitted_at', $columns, true)
                ? 'r.submitted_at'
                : 'NULL AS submitted_at';

            $categories = $this->db->table('hazard_categories')
                ->select('category_id, category_name')
                ->where('is_active', 1)
                ->get_all();

            $reports = [];
            if ($coordinatesAvailable) {
                $reports = $this->db->raw(
                    'SELECT r.report_id, r.reference_number, r.category_id, r.status, ' . $severityColumn . ', ' .
                    $titleColumn . ', ' . $descriptionColumn . ', ' . $submittedAtColumn . ', ' .
                    'r.latitude, r.longitude, c.category_name ' .
                    'FROM hazard_reports r ' .
                    'LEFT JOIN hazard_categories c ON c.category_id = r.category_id ' .
                    "WHERE LOWER(COALESCE(r.status, '')) <> 'resolved' " .
                    'AND r.latitude BETWEEN 13.25 AND 13.55 ' .
                    'AND r.longitude BETWEEN 121.05 AND 121.32 ' .
                    'ORDER BY r.submitted_at DESC LIMIT 500'
                )->fetchAll(PDO::FETCH_ASSOC);
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
