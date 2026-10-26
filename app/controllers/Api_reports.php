
<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Api_reports extends Controller
{
    private $currentUser;

    public function __construct()
    {
        parent::__construct();

        $this->currentUser = require_api_auth();

        $roleName = strtolower(trim((string) ($this->currentUser['role_name'] ?? '')));

        if ($roleName !== 'user' && $roleName !== 'resident') {
            api_error('Resident access required.', 403);
            return;
        }
    }

    private function userId()
    {
        return (int) ($this->currentUser['user_id'] ?? $this->currentUser['sub'] ?? 0);
    }

    private function validId($id)
    {
        return ctype_digit((string) $id) && (int) $id > 0;
    }

    private function input()
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (stripos($contentType, 'multipart/form-data') !== false) {
            return $_POST;
        }

        return get_request_input();
    }

    private function findReport($id)
    {
        return $this->db->table('hazard_reports')
            ->where('report_id', (int) $id)
            ->where('user_id', $this->userId())
            ->get();
    }

    private function reportDetails($id)
    {
        $report = $this->db->table('hazard_reports')
            ->select(
                'hazard_reports.report_id, hazard_reports.reference_number, hazard_reports.user_id, ' .
                'hazard_reports.category_id, hazard_reports.station_id, hazard_reports.title, ' .
                'hazard_reports.description, hazard_reports.address_details, hazard_reports.status, ' .
                'hazard_reports.submitted_at, hazard_reports.updated_at, ' .
                'hazard_categories.category_name, stations.station_name'
            )
            ->join('hazard_categories', 'hazard_categories.category_id = hazard_reports.category_id')
            ->left_join('stations', 'stations.station_id = hazard_reports.station_id')
            ->where('hazard_reports.report_id', (int) $id)
            ->where('hazard_reports.user_id', $this->userId())
            ->get();

        if (!$report) {
            return null;
        }

        $evidence = $this->db->table('report_evidence')
            ->select('evidence_id, report_id, uploaded_by, file_path, evidence_type, description, uploaded_at')
            ->where('report_id', (int) $id)
            ->get_all();

        $report['evidence'] = $evidence ?: [];

        return $report;
    }

    public function index()
    {
        try {
            $reports = $this->db->table('hazard_reports')
            ->select(
                'hazard_reports.report_id, hazard_reports.reference_number, ' .
                'hazard_reports.category_id, hazard_reports.station_id, ' .
                'hazard_reports.title, hazard_reports.description, ' .
                'hazard_reports.address_details, hazard_reports.status, ' .
                'hazard_reports.submitted_at, hazard_reports.updated_at, ' .
                'hazard_categories.category_name, stations.station_name'
            )
            ->join(
                'hazard_categories',
                'hazard_categories.category_id = hazard_reports.category_id'
            )
            ->left_join(
                'stations',
                'stations.station_id = hazard_reports.station_id'
            )
            ->where('hazard_reports.user_id', $this->userId())
            ->order_by('hazard_reports.submitted_at', 'DESC')
            ->get_all();

            foreach ($reports as &$report) {
                $report['evidence'] = $this->db->table('report_evidence')
                    ->select('evidence_id, report_id, file_path, evidence_type, description, uploaded_at')
                    ->where('report_id', (int) $report['report_id'])
                    ->get_all() ?: [];
            }
            unset($report);

            api_response(['reports' => $reports ?: []]);
        } catch (\Throwable $e) {
            error_log('Reports index error: ' . $e->getMessage());
            api_error('Unable to load reports.', 500);
        }
    }

    public function community()
    {
        try {
            $reports = $this->db->raw(
                'SELECT r.report_id, r.reference_number, r.category_id, r.title, r.description, r.status, r.submitted_at, ' .
                'c.category_name, b.barangay_name ' .
                'FROM hazard_reports r ' .
                'LEFT JOIN hazard_categories c ON c.category_id = r.category_id ' .
                'LEFT JOIN users u ON u.user_id = r.user_id ' .
                'LEFT JOIN barangays b ON b.barangay_id = u.barangay_id ' .
                'ORDER BY r.submitted_at DESC LIMIT 100'
            )->fetchAll(PDO::FETCH_ASSOC);

            $statusCounts = $this->db->raw(
                'SELECT status, COUNT(*) AS total FROM hazard_reports GROUP BY status ORDER BY status'
            )->fetchAll(PDO::FETCH_ASSOC);

            $categoryCounts = $this->db->raw(
                'SELECT c.category_id, c.category_name, COUNT(r.report_id) AS total ' .
                'FROM hazard_categories c ' .
                'LEFT JOIN hazard_reports r ON r.category_id = c.category_id ' .
                'GROUP BY c.category_id, c.category_name ' .
                'ORDER BY total DESC, c.category_name'
            )->fetchAll(PDO::FETCH_ASSOC);

            $barangayCounts = $this->db->raw(
                'SELECT b.barangay_id, b.barangay_name, COUNT(r.report_id) AS total ' .
                'FROM barangays b ' .
                'LEFT JOIN users u ON u.barangay_id = b.barangay_id ' .
                'LEFT JOIN hazard_reports r ON r.user_id = u.user_id ' .
                'GROUP BY b.barangay_id, b.barangay_name ' .
                'HAVING COUNT(r.report_id) > 0 ' .
                'ORDER BY total DESC, b.barangay_name'
            )->fetchAll(PDO::FETCH_ASSOC);

            $alerts = $this->db->raw(
                'SELECT notification_id, title, message, notification_type, is_read, created_at ' .
                'FROM notifications WHERE user_id = ? OR user_id IS NULL ' .
                'ORDER BY created_at DESC LIMIT 20',
                [$this->userId()]
            )->fetchAll(PDO::FETCH_ASSOC);

            api_response([
                'reports' => $reports,
                'status_counts' => $statusCounts,
                'category_counts' => $categoryCounts,
                'barangay_counts' => $barangayCounts,
                'alerts' => $alerts
            ]);
        } catch (\Throwable $e) {
            error_log('Community dashboard error: ' . $e->getMessage());
            api_error('Unable to load current community data.', 500);
        }
    }

    public function show($id)
    {
        if (!$this->validId($id)) {
            api_error('Invalid report ID.', 400);
            return;
        }

        try {
            $report = $this->reportDetails($id);

            if (!$report) {
                api_error('Report not found.', 404);
                return;
            }

            api_response(['report' => $report]);
        } catch (\Throwable $e) {
            error_log('Report show error: ' . $e->getMessage());
            api_error('Unable to load this report.', 500);
        }
    }

    public function categories()
    {
        try {
            $categories = $this->db->table('hazard_categories')
                ->select('category_id, category_name')
                ->where('is_active', 1)
                ->get_all();

            api_response(['categories' => $categories ?: []]);
        } catch (\Throwable $e) {
            error_log('Report categories error: ' . $e->getMessage());
            api_error('Unable to load hazard categories.', 500);
        }
    }

    public function stations()
    {
        try {
            $stations = $this->db->table('stations')
                ->select('station_id, station_name')
                ->get_all();

            api_response(['stations' => $stations ?: []]);
        } catch (\Throwable $e) {
            error_log('Report stations error: ' . $e->getMessage());
            api_error('Unable to load stations.', 500);
        }
    }

    public function store()
    {
        $input = $this->input();

        $categoryId = (int) ($input['category_id'] ?? 0);
        $stationValue = $input['station_id'] ?? '';
        $stationId = ($stationValue === '' || $stationValue === null)
            ? null
            : (int) $stationValue;

        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $address = trim((string) ($input['address_details'] ?? ''));

        if ($categoryId < 1 || $title === '' || $description === '' || $address === '') {
            api_error('Category, title, description, and address are required.', 422);
            return;
        }

        if (mb_strlen($title) > 200 || mb_strlen($address) > 255) {
            api_error('The title or address exceeds the allowed length.', 422);
            return;
        }

        if ($stationId !== null && $stationId < 1) {
            api_error('Invalid station.', 422);
            return;
        }

        try {
            $category = $this->db->table('hazard_categories')
                ->where('category_id', $categoryId)
                ->where('is_active', 1)
                ->get();

            if (!$category) {
                api_error('Select a valid active hazard category.', 422);
                return;
            }

            if ($stationId !== null) {
                $station = $this->db->table('stations')
                    ->where('station_id', $stationId)
                    ->get();

                if (!$station) {
                    api_error('Select a valid station.', 422);
                    return;
                }
            }

            $referenceNumber = 'BA-' . date('YmdHis') . '-' . random_int(100, 999);

            $inserted = $this->db->table('hazard_reports')->insert([
                'reference_number' => $referenceNumber,
                'user_id' => $this->userId(),
                'category_id' => $categoryId,
                'station_id' => $stationId,
                'title' => $title,
                'description' => $description,
                'address_details' => $address,
                'status' => 'pending',
                'submitted_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            if (!$inserted) {
                api_error('Could not save the report.', 500);
                return;
            }

            // Retrieve the newly created report using its unique reference.
            $savedReport = $this->db->table('hazard_reports')
                ->select('report_id, reference_number')
                ->where('reference_number', $referenceNumber)
                ->where('user_id', $this->userId())
                ->get();

            if (!$savedReport) {
                api_error('Report was submitted, but its saved record could not be retrieved.', 500);
                return;
            }

            $reportId = (int) $savedReport['report_id'];

            $uploadError = $this->saveEvidence($reportId);

            if ($uploadError !== null) {
                api_error(
                    'The report was saved, but evidence upload failed: ' . $uploadError,
                    400
                );
                return;
            }

            api_response([
                'report_id' => $reportId,
                'reference_number' => $referenceNumber
            ], 201, 'Hazard report submitted successfully.');
        } catch (\Throwable $e) {
            error_log('Report store error: ' . $e->getMessage());
            api_error('Unable to save the report.', 500);
        }
    }

    public function update($id)
    {
        if (!$this->validId($id)) {
            api_error('Invalid report ID.', 400);
            return;
        }

        $input = $this->input();

        $categoryId = (int) ($input['category_id'] ?? 0);
        $stationValue = $input['station_id'] ?? '';
        $stationId = ($stationValue === '' || $stationValue === null)
            ? null
            : (int) $stationValue;

        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $address = trim((string) ($input['address_details'] ?? ''));

        if ($categoryId < 1 || $title === '' || $description === '' || $address === '') {
            api_error('Category, title, description, and address are required.', 422);
            return;
        }

        if (mb_strlen($title) > 200 || mb_strlen($address) > 255) {
            api_error('The title or address exceeds the allowed length.', 422);
            return;
        }

        try {
            $report = $this->findReport($id);

            if (!$report) {
                api_error('Report not found.', 404);
                return;
            }

            if (strtolower((string) $report['status']) !== 'pending') {
                api_error('Only pending reports can be edited.', 403);
                return;
            }

            $category = $this->db->table('hazard_categories')
                ->where('category_id', $categoryId)
                ->where('is_active', 1)
                ->get();

            if (!$category) {
                api_error('Select a valid active hazard category.', 422);
                return;
            }

            if ($stationId !== null) {
                $station = $this->db->table('stations')
                    ->where('station_id', $stationId)
                    ->get();

                if (!$station) {
                    api_error('Select a valid station.', 422);
                    return;
                }
            }

            $this->db->table('hazard_reports')
                ->where('report_id', (int) $id)
                ->where('user_id', $this->userId())
                ->update([
                    'category_id' => $categoryId,
                    'station_id' => $stationId,
                    'title' => $title,
                    'description' => $description,
                    'address_details' => $address,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

            $uploadError = $this->saveEvidence((int) $id);

            if ($uploadError !== null) {
                api_error(
                    'Report details were updated, but evidence upload failed: ' . $uploadError,
                    400
                );
                return;
            }

            api_response(null, 200, 'Report updated successfully.');
        } catch (\Throwable $e) {
            error_log('Report update error: ' . $e->getMessage());
            api_error('Unable to update the report.', 500);
        }
    }

    private function saveEvidence($reportId)
    {
        if (empty($_FILES['evidence'])) {
            return null;
        }

        $files = $_FILES['evidence'];
        $names = $files['name'] ?? [];

        if (!is_array($names)) {
            $files = [
                'name' => [$files['name'] ?? ''],
                'type' => [$files['type'] ?? ''],
                'tmp_name' => [$files['tmp_name'] ?? ''],
                'error' => [$files['error'] ?? UPLOAD_ERR_NO_FILE],
                'size' => [$files['size'] ?? 0]
            ];
            $names = $files['name'];
        }

        $uploadDirectory = FCPATH . 'uploads/evidence/';

        if (!is_dir($uploadDirectory) &&
            !mkdir($uploadDirectory, 0755, true) &&
            !is_dir($uploadDirectory)) {
            return 'Unable to create the evidence upload directory.';
        }

        $allowed = [
            'image/jpeg' => ['photo', 'jpg'],
            'image/png' => ['photo', 'png'],
            'image/webp' => ['photo', 'webp'],
            'video/mp4' => ['video', 'mp4'],
            'application/pdf' => ['document', 'pdf']
        ];

        $savedPaths = [];

        foreach ($names as $i => $originalName) {
            $error = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($error !== UPLOAD_ERR_OK) {
                return 'A file did not upload correctly.';
            }

            $tmpName = $files['tmp_name'][$i] ?? '';
            $size = (int) ($files['size'][$i] ?? 0);

            if ($size < 1 || $size > 10 * 1024 * 1024) {
                return 'Evidence files must be smaller than or equal to 10 MB.';
            }

            if (!is_uploaded_file($tmpName)) {
                return 'Invalid uploaded file.';
            }

            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);

            if (!isset($allowed[$mime])) {
                return 'Only JPG, PNG, WEBP, MP4, and PDF files are allowed.';
            }

            [$type, $extension] = $allowed[$mime];

            $fileName = bin2hex(random_bytes(16)) . '.' . $extension;
            $destination = $uploadDirectory . $fileName;
            $relativePath = 'uploads/evidence/' . $fileName;

            if (!move_uploaded_file($tmpName, $destination)) {
                return 'Unable to store an uploaded file.';
            }

            try {
                $this->db->table('report_evidence')->insert([
                    'report_id' => $reportId,
                    'uploaded_by' => $this->userId(),
                    'file_path' => $relativePath,
                    'evidence_type' => $type,
                    'description' => null,
                    'uploaded_at' => date('Y-m-d H:i:s')
                ]);

                $savedPaths[] = $destination;
            } catch (\Throwable $e) {
                error_log('Evidence insert error: ' . $e->getMessage());

                foreach ($savedPaths as $savedPath) {
                    if (is_file($savedPath)) {
                        unlink($savedPath);
                    }
                }

                if (is_file($destination)) {
                    unlink($destination);
                }

                return 'Unable to save evidence information to the database.';
            }
        }

        return null;
    }
}
