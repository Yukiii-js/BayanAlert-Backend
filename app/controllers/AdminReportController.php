<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminReportController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $currentUser = require_api_auth();

        if (strtolower(trim((string) ($currentUser['role_name'] ?? ''))) !== 'admin'
            && (string) ($currentUser['role_id'] ?? '') !== '1') {
            api_error('Forbidden: Administrator access required.', 403);
        }

        $this->call->model('Hazard_model');
    }

    public function hazard_pdf()
    {
        $this->build_report('hazards');
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
        $barangayId = null;

        if (!is_string($startDate) || !is_string($endDate)
            || !$this->isValidDate($startDate) || !$this->isValidDate($endDate)
            || $startDate > $endDate) {
            api_error('Invalid date range. Use start_date and end_date in YYYY-MM-DD format.', 400);
        }

        if ($reportType === 'barangays' && $barangayInput !== '') {
            if (!is_string($barangayInput) || !ctype_digit($barangayInput) || (int) $barangayInput < 1) {
                api_error('Choose a valid barangay.', 400);
            }
            $barangayId = (int) $barangayInput;
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
                $reports = $this->Hazard_model->get_reports_by_date_range($startDate, $endDate);
            } else {
                $reports = $this->Hazard_model->get_barangay_report($startDate, $endDate, $barangayId);
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
        $this->pdf->AddPage($reportType === 'barangays' ? 'L' : 'P');

        $escape = static function ($value) {
            return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $html = '
            <h1 style="font-size:16pt; color:#173742;">' . $escape($title) . '</h1>
            <p style="font-size:10pt; color:#52666e;">
                Calapan City, Oriental Mindoro<br>
                <strong>Date generated:</strong> ' . $escape(date('F j, Y g:i A')) . '<br>
                <strong>Report period:</strong> ' . $escape($startDate) . ' to ' . $escape($endDate) . '
            </p>';

        if ($reportType === 'hazards') {
            $html .= '
                <table border="1" cellpadding="4" width="100%">
                    <thead>
                        <tr style="background-color:#e8f1f3; color:#173742; font-weight:bold;">
                            <th width="6%">#</th>
                            <th width="21%">Hazard Type</th>
                            <th width="29%">Location</th>
                            <th width="14%">Severity</th>
                            <th width="14%">Status</th>
                            <th width="16%">Date</th>
                        </tr>
                    </thead>
                    <tbody>';

            foreach ($reports as $index => $report) {
                $html .= '
                    <tr>
                        <td width="6%">' . ($index + 1) . '</td>
                        <td width="21%">' . $escape($report['hazard_type'] ?? '') . '</td>
                        <td width="29%">' . $escape($report['location'] ?? '') . '</td>
                        <td width="14%">' . $escape($report['severity'] ?? '') . '</td>
                        <td width="14%">' . $escape($report['status'] ?? '') . '</td>
                        <td width="16%">' . $escape($report['date_reported'] ?? '') . '</td>
                    </tr>';
            }

            if (empty($reports)) {
                $html .= '<tr><td colspan="6" style="text-align:center;">No hazard reports found for this date range.</td></tr>';
            }

            $html .= '</tbody></table>';
            $filename = 'BayanAlert_Hazard_Report.pdf';
        } else {
            $html .= '
                <h2 style="font-size:12pt; color:#173742;">Barangay Overview</h2>
                <p style="font-size:9pt; color:#52666e;">
                    Resident counts include active resident accounts. Hazard counts and history cover the selected report period and are grouped by the submitting resident&rsquo;s registered barangay.
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
                $html .= '
                    <tr>
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
                $html .= '<tr><td colspan="9" style="text-align:center;">No active barangays were found.</td></tr>';
            }

            $html .= '
                    </tbody>
                </table>
                <h2 style="font-size:12pt; color:#173742;">Hazard Report History</h2>
                <table border="1" cellpadding="4" width="100%">
                    <thead>
                        <tr style="background-color:#e8f1f3; color:#173742; font-weight:bold;">
                            <th width="6%">#</th>
                            <th width="18%">Date</th>
                            <th width="20%">Barangay</th>
                            <th width="20%">Hazard Type</th>
                            <th width="22%">Location</th>
                            <th width="14%">Status</th>
                        </tr>
                    </thead>
                    <tbody>';

            foreach ($reports['history'] as $index => $history) {
                $html .= '
                    <tr>
                        <td width="6%">' . ($index + 1) . '</td>
                        <td width="18%">' . $escape($history['date_reported'] ?? '') . '</td>
                        <td width="20%">' . $escape($history['barangay_name'] ?? '') . '</td>
                        <td width="20%">' . $escape($history['hazard_type'] ?? '') . '</td>
                        <td width="22%">' . $escape($history['location'] ?? '') . '</td>
                        <td width="14%">' . $escape($history['status'] ?? '') . '</td>
                    </tr>';
            }

            if (empty($reports['history'])) {
                $html .= '<tr><td colspan="6" style="text-align:center;">No hazard history was found for this date range.</td></tr>';
            }

            $html .= '</tbody></table>';
            $filename = 'BayanAlert_Barangay_Activity_Report.pdf';
        }

        $this->pdf->writeHTML($html, true, false, true, false, '');
        $this->pdf->Output($filename, 'I');
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
