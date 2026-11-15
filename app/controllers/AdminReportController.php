<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminReportController extends Controller
{
    private $hazardColumns = [
        'hazard_type' => 'Category',
        'location' => 'Location',
        'severity' => 'Severity',
        'status' => 'Status',
        'date_reported' => 'Date reported',
        'reporter_name' => 'Reporter name',
        'reporter_contact' => 'Reporter contact',
        'coordinates' => 'Exact coordinates',
        'resolution_notes' => 'Resolution notes',
        'resolution_timestamp' => 'Resolution timestamp',
        'admin_remarks' => 'Admin remarks',
    ];

    public function __construct()
    {
        parent::__construct();
        $currentUser = require_api_auth();

        if (strtolower(trim((string) ($currentUser['role_name'] ?? ''))) !== 'admin') {
            api_error('Forbidden: Administrator access required.', 403);
        }

        $this->call->model('Hazard_model');
    }

    public function hazard_pdf()
    {
        $this->build_report('hazards');
    }

    public function report_options()
    {
        try {
            $options = $this->Hazard_model->get_report_filter_options();
            api_response($options);
        } catch (Throwable $exception) {
            error_log('PDF report filter options failed: ' . $exception->getMessage());
            api_error('Could not load PDF report filters.', 500);
        }
    }

    public function report_pdf()
    {
        $reportType = $this->request->get('type', 'hazards');
        if (!is_string($reportType) || !in_array($reportType, ['hazards', 'barangays'], true)) {
            api_error('Choose a supported report type.', 400);
        }

        $this->build_report($reportType);
    }

    private function build_report($reportType)
    {
        $today = date('Y-m-d');
        $startDate = $this->request->get('start_date', date('Y-m-01'));
        $endDate = $this->request->get('end_date', $today);
        $barangayInput = $this->request->get('barangay_id', '');
        $categoryInput = $this->request->get('category_id', '');
        $statusInput = $this->request->get('statuses', $this->request->get('status', ''));
        $severityInput = $this->request->get('severities', $this->request->get('severity', ''));

        if (!is_string($startDate) || !is_string($endDate)
            || !$this->isValidDate($startDate) || !$this->isValidDate($endDate)
            || $startDate > $endDate) {
            api_error('Invalid date range. Use start_date and end_date in YYYY-MM-DD format.', 400);
        }

        $reportSections = $reportType === 'barangays'
            ? ['overview', 'history']
            : ['summary', 'records'];
        $allowedColumns = array_keys($this->hazardColumns);
        $defaultColumns = array_slice($allowedColumns, 0, 5);
        if ($reportType === 'barangays') {
            array_unshift($allowedColumns, 'barangay_name');
            array_unshift($defaultColumns, 'barangay_name');
        }
        $sections = $this->parseSelection($this->request->get('sections', null), $reportSections);
        $columns = $this->parseSelection($this->request->get('columns', null), $defaultColumns);
        if (empty($sections) || array_diff($sections, $reportSections)) {
            api_error('Select at least one supported PDF section.', 400);
        }
        if (array_diff($columns, $allowedColumns)
            || (in_array($reportType === 'barangays' ? 'history' : 'records', $sections, true) && empty($columns))) {
            api_error('Select at least one supported table column.', 400);
        }

        $filters = [];
        $statuses = $this->parseFilterList($statusInput);
        foreach ($statuses as $status) {
            if (!preg_match('/^[a-z][a-z0-9_ -]{0,31}$/i', $status)) {
                api_error('Choose a valid report status.', 400);
            }
        }
        if (!empty($statuses)) {
            $filters['statuses'] = $statuses;
        }
        if ($categoryInput !== '') {
            if (!is_string($categoryInput) || !ctype_digit($categoryInput) || (int) $categoryInput < 1) {
                api_error('Choose a valid hazard category.', 400);
            }
            $filters['category_id'] = (int) $categoryInput;
        }
        if ($barangayInput !== '') {
            if (!is_string($barangayInput) || !ctype_digit($barangayInput) || (int) $barangayInput < 1) {
                api_error('Choose a valid barangay.', 400);
            }
            $filters['barangay_id'] = (int) $barangayInput;
        }
        $severities = $this->parseFilterList($severityInput);
        foreach ($severities as $severity) {
            if (strlen($severity) > 64 || trim($severity) === '') {
                api_error('Choose a valid report severity.', 400);
            }
        }
        if (!empty($severities)) {
            $filters['severities'] = array_map('trim', $severities);
        }

        if (!is_file(APP_DIR . 'libraries' . DIRECTORY_SEPARATOR . 'Pdf.php')) {
            error_log('PDF report could not be generated: app/libraries/Pdf.php is missing.');
            api_error('PDF report generation is unavailable because the PDF library is not installed.', 503);
        }

        try {
            $this->call->library('pdf');
        } catch (Throwable $exception) {
            error_log('PDF library failed to load: ' . $exception->getMessage());
            api_error('PDF report generation is unavailable. Check the PDF wrapper and TCPDF installation.', 503);
        }

        try {
            if ($reportType === 'hazards') {
                $reports = $this->Hazard_model->get_reports_by_date_range($startDate, $endDate, $filters);
            } else {
                $reports = $this->Hazard_model->get_barangay_report(
                    $startDate,
                    $endDate,
                    null,
                    $filters
                );
            }
        } catch (PDOException $exception) {
            error_log('PDF report query failed: ' . $exception->getMessage());
            api_error('Could not load report data. Check the database connection and report schema.', 500);
        }

        if ($reportType === 'hazards' && !is_array($reports)) {
            error_log('PDF hazard report query returned an invalid result.');
            api_error('Could not load hazard reports because the report data was invalid.', 500);
        }
        if ($reportType === 'barangays'
            && (!is_array($reports) || !is_array($reports['barangays'] ?? null) || !is_array($reports['history'] ?? null))) {
            error_log('PDF barangay report query returned an invalid result.');
            api_error('Could not load barangay data because the report result was invalid.', 500);
        }

        $this->pdf->SetCreator('BayanAlert');
        $title = $reportType === 'hazards'
            ? 'Community Hazard Verification Report'
            : 'Barangay Activity and Hazard History Report';
        $this->pdf->SetTitle('BayanAlert: ' . $title);
        $this->pdf->SetMargins(12, 33, 12);
        $this->pdf->SetHeaderMargin(7);
        $this->pdf->SetFooterMargin(17);
        $this->pdf->SetAutoPageBreak(true, 23);
        $orientation = $reportType === 'barangays'
            || (in_array('records', $sections, true) && count($columns) > 5)
            ? 'L'
            : 'P';
        $this->pdf->AddPage($orientation);

        $escape = static function ($value) {
            return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $filterSummary = $this->describeFilters($filters, $startDate, $endDate, $escape);
        $html = '
            <h1 style="font-size:16pt; color:#173742;">' . $escape($title) . '</h1>
            <p style="font-size:10pt; color:#52666e;">
                Calapan City, Oriental Mindoro<br>
                <strong>Date generated:</strong> ' . $escape(date('F j, Y g:i A')) . '<br>
                <strong>Report period:</strong> ' . $escape($startDate) . ' to ' . $escape($endDate) . '<br>
                <strong>Filters:</strong> ' . $filterSummary . '
            </p>';

        if ($reportType === 'hazards') {
            $statusCounts = [];
            foreach ($reports as $report) {
                $status = (string) ($report['status'] ?? 'unknown');
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            }

            if (in_array('summary', $sections, true)) {
                $html .= '
                    <h2 style="font-size:12pt; color:#173742;">Summary</h2>
                    <p style="font-size:10pt; color:#52666e;"><strong>Total matching reports:</strong> ' .
                    count($reports) . '</p>';
                if (!empty($statusCounts)) {
                    $html .= '<table border="1" cellpadding="4" width="100%">
                        <thead><tr style="background-color:#e8f1f3; color:#173742; font-weight:bold;">
                            <th>Status</th><th>Reports</th>
                        </tr></thead><tbody>';
                    ksort($statusCounts);
                    foreach ($statusCounts as $status => $count) {
                        $html .= '<tr><td>' . $escape(ucwords(str_replace('_', ' ', $status))) .
                            '</td><td>' . $count . '</td></tr>';
                    }
                    $html .= '</tbody></table>';
                }
            }

            if (in_array('records', $sections, true)) {
                $html .= '<h2 style="font-size:12pt; color:#173742;">Hazard Reports</h2>';
                $html .= $this->renderReportTable($reports, $columns, $this->hazardColumns, $escape, 'No hazard reports found for the selected filters.');
            }
            $filename = 'BayanAlert_Hazard_Report.pdf';
        } else {
            if (in_array('overview', $sections, true)) {
                $html .= '
                    <h2 style="font-size:12pt; color:#173742;">Barangay Overview</h2>
                    <p style="font-size:9pt; color:#52666e;">
                        Resident counts include active resident accounts. Hazard counts and history cover the selected report period and filters.
                    </p>
                    <table border="1" cellpadding="4" width="100%">
                        <thead>
                            <tr style="background-color:#e8f1f3; color:#173742; font-weight:bold;">
                                <th width="20%">Barangay</th>
                                <th width="11%">Residents</th>
                                <th width="10%">Hazards</th>
                                <th width="10%">Pending</th>
                                <th width="10%">Verified</th>
                                <th width="10%">Assigned</th>
                                <th width="12%">In Progress</th>
                                <th width="9%">Resolved</th>
                                <th width="8%">Rejected</th>
                            </tr>
                        </thead>
                        <tbody>';
                foreach ($reports['barangays'] as $barangay) {
                    $html .= '<tr>
                        <td width="20%">' . $escape($barangay['barangay_name'] ?? '') . '</td>
                        <td width="11%">' . $escape($barangay['registered_residents'] ?? 0) . '</td>
                        <td width="10%">' . $escape($barangay['total_hazards'] ?? 0) . '</td>
                        <td width="10%">' . $escape($barangay['pending_hazards'] ?? 0) . '</td>
                        <td width="10%">' . $escape($barangay['verified_hazards'] ?? 0) . '</td>
                        <td width="10%">' . $escape($barangay['assigned_hazards'] ?? 0) . '</td>
                        <td width="12%">' . $escape($barangay['in_progress_hazards'] ?? 0) . '</td>
                        <td width="9%">' . $escape($barangay['resolved_hazards'] ?? 0) . '</td>
                        <td width="8%">' . $escape($barangay['rejected_hazards'] ?? 0) . '</td>
                    </tr>';
                }
                if (empty($reports['barangays'])) {
                    $html .= '<tr><td colspan="9" style="text-align:center;">No barangays were found.</td></tr>';
                }
                $html .= '</tbody></table>';
            }

            if (in_array('history', $sections, true)) {
                $html .= '<h2 style="font-size:12pt; color:#173742;">Hazard Report History</h2>';
                $historyColumns = ['barangay_name' => 'Barangay'] + $this->hazardColumns;
                $html .= $this->renderReportTable($reports['history'], $columns, $historyColumns, $escape, 'No hazard history was found for the selected filters.');
            }
            $filename = 'BayanAlert_Barangay_Activity_Report.pdf';
        }

        $this->pdf->writeHTML($html, true, false, true, false, '');
        $this->pdf->Output($filename, 'I');
    }

    private function parseSelection($value, $defaults)
    {
        if ($value === null) {
            return $defaults;
        }
        if (!is_string($value)) {
            api_error('Invalid PDF section or column selection.', 400);
        }

        $items = array_filter(array_map('trim', explode(',', $value)), static function ($item) {
            return $item !== '';
        });
        return array_values(array_unique($items));
    }

    private function parseFilterList($value)
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_string($value)) {
            api_error('Invalid PDF report filter.', 400);
        }

        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $value)),
            static function ($item) {
                return $item !== '';
            }
        )));
    }

    private function renderReportTable($rows, $columns, $labels, $escape, $emptyMessage)
    {
        $columnCount = count($columns);
        $width = $columnCount > 0 ? round(100 / $columnCount, 2) : 100;
        $html = '<table border="1" cellpadding="4" width="100%"><thead><tr style="background-color:#e8f1f3; color:#173742; font-weight:bold;">';
        foreach ($columns as $column) {
            $html .= '<th width="' . $width . '%">' . $escape($labels[$column]) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        if (empty($rows)) {
            return $html . '<tr><td colspan="' . max(1, $columnCount) . '" style="text-align:center;">' .
                $escape($emptyMessage) . '</td></tr></tbody></table>';
        }

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($columns as $column) {
                $value = $row[$column] ?? '';
                if ($column === 'severity' && $value === '') {
                    $value = 'Not specified';
                }
                $html .= '<td width="' . $width . '%">' . $escape($value) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    private function describeFilters($filters, $startDate, $endDate, $escape)
    {
        $parts = [$startDate . ' to ' . $endDate];
        foreach ([
            'statuses' => 'Status',
            'category_id' => 'Category ID',
            'severities' => 'Severity',
            'barangay_id' => 'Barangay ID',
        ] as $key => $label) {
            if (isset($filters[$key])) {
                $value = is_array($filters[$key])
                    ? implode(', ', $filters[$key])
                    : (string) $filters[$key];
                $parts[] = $label . ': ' . str_replace('_', ' ', $value);
            }
        }
        return implode(' | ', array_map($escape, $parts));
    }

    private function isValidDate($date)
    {
        $parsed = DateTime::createFromFormat('!Y-m-d', $date);
        $errors = DateTime::getLastErrors();

        return $parsed !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $parsed->format('Y-m-d') === $date;
    }
}
