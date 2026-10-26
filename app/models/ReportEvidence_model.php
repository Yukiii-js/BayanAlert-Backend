<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ReportEvidence_model extends Model
{
    protected $table = 'report_evidence';
    protected $primary_key = 'evidence_id';
    protected $fillable = [];
    protected $guarded = [];
    protected $timestamps = false;

    public function info()
    {
        return [
        'label' => 'Report evidence',
        'group' => 'Operations',
        'description' => 'Manage evidence records attached to reports.',
        'primary_key' => 'evidence_id',
        ];
    }

    public function references()
    {
        return [
            'report_id' => ['hazard_reports', 'reference_number'],
            'uploaded_by' => ['users', 'full_name']
        ];
    }

    public function get_all_records($search = '', $page = 1, $pageSize = 25)
    {
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $pageSize;
        $query = $this->db->table($this->table);
        $countQuery = $this->db->table($this->table);

        if ($search !== '') {
            $column = $this->primary_key;
            foreach ($this->get_columns() as $candidate) {
                if (strpos($candidate, 'name') !== false || strpos($candidate, 'title') !== false || strpos($candidate, 'email') !== false || strpos($candidate, 'code') !== false) {
                    $column = $candidate;
                    break;
                }
            }
            $query->like($column, '%' . $search . '%');
            $countQuery->like($column, '%' . $search . '%');
        }

        return [
            'columns' => $this->get_columns(),
            'rows' => $query->order_by($this->primary_key, 'DESC')->limit($pageSize, $offset)->get_all(),
            'total' => (int) $countQuery->count(),
            'page' => $page,
            'page_size' => $pageSize
        ];
    }

    public function get_columns()
    {
        $columns = $this->db->raw('SHOW COLUMNS FROM `' . $this->table . '`')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function ($column) {
            return $column['Field'];
        }, $columns);
    }
}
