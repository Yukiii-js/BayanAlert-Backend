
<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/EvidenceStorage.php';

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

    private function ensureNotSuspended()
    {
        try {
            $user = $this->db->table('users')
                ->select('status, suspension_reason')
                ->where('user_id', $this->userId())
                ->limit(1)
                ->get();
            if (!$user || strtolower(trim((string) ($user['status'] ?? 'active'))) !== 'suspended') {
                return true;
            }

            $reason = trim((string) ($user['suspension_reason'] ?? ''));
            api_error(
                'Your account is suspended and cannot submit or edit hazard reports.' .
                    ($reason !== '' ? ' Reason: ' . $reason : ''),
                403
            );
            return false;
        } catch (\Throwable $e) {
            error_log('Suspension check failed for user ' . $this->userId() . ': ' . $e->getMessage());
            api_error('Unable to verify your account status. Please try again later.', 503);
            return false;
        }
    }

    private function validId($id)
    {
        return ctype_digit((string) $id) && (int) $id > 0;
    }

    private function input()
    {
        $contentType = $this->request->header('Content-Type') ?? '';

        if (stripos($contentType, 'multipart/form-data') !== false) {
            return $this->request->post();
        }

        return get_request_input();
    }

    private function hasReportCoordinates()
    {
        $columns = get_table_column_names($this->db, 'hazard_reports');

        return in_array('latitude', $columns, true)
            && in_array('longitude', $columns, true);
    }

    private function hasSeverityColumn()
    {
        $columns = get_table_column_names($this->db, 'hazard_reports');
        return in_array('severity_level', $columns, true);
    }

    private function reportCoordinates($input)
    {
        $latitude = $input['latitude'] ?? null;
        $longitude = $input['longitude'] ?? null;
        $latitudeEmpty = $latitude === '' || $latitude === null;
        $longitudeEmpty = $longitude === '' || $longitude === null;

        if ($latitudeEmpty && $longitudeEmpty) {
            return ['latitude' => null, 'longitude' => null];
        }

        if ($latitudeEmpty !== $longitudeEmpty) {
            api_error('Both map coordinates are required.', 422);
            return null;
        }

        if (!is_numeric($latitude) || !is_numeric($longitude)
            || (float) $latitude < -90 || (float) $latitude > 90
            || (float) $longitude < -180 || (float) $longitude > 180) {
            api_error('Select a valid map location.', 422);
            return null;
        }

        if ((float) $latitude < 13.25 || (float) $latitude > 13.55
            || (float) $longitude < 121.05 || (float) $longitude > 121.32) {
            api_error('Select a map location within Calapan City, Oriental Mindoro.', 422);
            return null;
        }

        if (!$this->hasReportCoordinates()) {
            api_error('Map locations are not enabled until the report-coordinate migration is applied.', 503);
            return null;
        }

        return [
            'latitude' => round((float) $latitude, 7),
            'longitude' => round((float) $longitude, 7)
        ];
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
                ($this->hasReportCoordinates() ? 'hazard_reports.latitude, hazard_reports.longitude, ' : '') .
                ($this->hasSeverityColumn() ? 'hazard_reports.severity_level, ' : 'NULL AS severity_level, ') .
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

    private function uploadRequestError()
    {
        $contentType = $this->request->header('Content-Type') ?? '';
        if (stripos($contentType, 'multipart/form-data') === false) {
            return null;
        }

        $contentLength = (int) ($this->request->header('Content-Length') ?? 0);
        $postMax = trim((string) ini_get('post_max_size'));
        $unit = strtolower(substr($postMax, -1));
        $postMaxBytes = (float) $postMax;

        if ($unit === 'g') {
            $postMaxBytes *= 1024 * 1024 * 1024;
        } elseif ($unit === 'm') {
            $postMaxBytes *= 1024 * 1024;
        } elseif ($unit === 'k') {
            $postMaxBytes *= 1024;
        }

        if ($contentLength > 0 && $postMaxBytes > 0
            && $contentLength > $postMaxBytes
            && empty($this->request->post()) && empty($this->request->files())) {
            return [
                'status' => 413,
                'message' => 'The upload exceeds the server post_max_size limit. Increase PHP post_max_size and try again.'
            ];
        }

        $files = $this->request->file('evidence');
        if (!$files || !isset($files['error'])) {
            return null;
        }

        $errors = is_array($files['error']) ? $files['error'] : [$files['error']];
        foreach ($errors as $error) {
            if ($error === UPLOAD_ERR_OK || $error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                return [
                    'status' => 413,
                    'message' => 'An evidence file exceeds the PHP upload size limit. Increase PHP upload_max_filesize and post_max_size, then try again.'
                ];
            }

            if ($error === UPLOAD_ERR_PARTIAL) {
                return [
                    'status' => 400,
                    'message' => 'An evidence file was only partially uploaded. Please try again.'
                ];
            }

            return [
                'status' => 400,
                'message' => 'An evidence file could not be uploaded. Check the server upload configuration and try again.'
            ];
        }

        return null;
    }

    public function index()
    {
        try {
            $select = 'hazard_reports.report_id, hazard_reports.user_id, hazard_reports.reference_number, ' .
                'hazard_reports.category_id, hazard_reports.station_id, ' .
                'hazard_reports.title, hazard_reports.description, ' .
                'hazard_reports.address_details, hazard_reports.status, ' .
                'hazard_reports.submitted_at, hazard_reports.updated_at, ';
            if ($this->hasReportCoordinates()) {
                $select .= 'hazard_reports.latitude, hazard_reports.longitude, ';
            }
            $select .= $this->hasSeverityColumn()
                ? 'hazard_reports.severity_level, '
                : 'NULL AS severity_level, ';

            $reports = $this->db->table('hazard_reports')
            ->select(
                $select .
                'hazard_categories.category_name, stations.station_name, ' .
                "COALESCE(NULLIF(users.full_name, ''), 'Deleted User') AS reporter_name"
            )
            ->join(
                'hazard_categories',
                'hazard_categories.category_id = hazard_reports.category_id'
            )
            ->left_join(
                'stations',
                'stations.station_id = hazard_reports.station_id'
            )
            ->left_join(
                'users',
                'users.user_id = hazard_reports.user_id'
            )
            ->where('hazard_reports.user_id', $this->userId())
            ->order_by('hazard_reports.submitted_at', 'DESC')
            ->get_all();

            $this->attachAdminRemarks($reports);
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

    private function attachAdminRemarks(array &$reports)
    {
        if (!$reports) {
            return;
        }

        if (!database_table_exists($this->db, 'report_verifications')) {
            return;
        }
        $columnNames = get_table_column_names($this->db, 'report_verifications');
        $remarksColumn = null;
        foreach (['admin_remarks', 'verification_remarks', 'remarks', 'notes'] as $candidate) {
            if (in_array($candidate, $columnNames, true)) {
                $remarksColumn = $candidate;
                break;
            }
        }

        foreach ($reports as &$report) {
            $report['admin_remarks'] = null;
        }
        unset($report);

        if ($remarksColumn === null) {
            return;
        }

        $reportIds = array_values(array_filter(array_map(static function ($report) {
            return (int) ($report['report_id'] ?? 0);
        }, $reports)));
        if (!$reportIds) {
            return;
        }

        $remarks = $this->db->table('report_verifications')
            ->select('report_id, verification_id, ' . $remarksColumn . ' AS admin_remarks')
            ->in('report_id', $reportIds)
            ->order_by('verification_id', 'DESC')
            ->get_all();
        $remarksByReport = [];
        foreach ($remarks as $item) {
            $reportId = (int) $item['report_id'];
            if (!array_key_exists($reportId, $remarksByReport)) {
                $remarksByReport[$reportId] = $item['admin_remarks'];
            }
        }

        foreach ($reports as &$report) {
            $report['admin_remarks'] = $remarksByReport[(int) $report['report_id']] ?? null;
        }
        unset($report);
    }

    public function community()
    {
        try {
            $columns = 'hazard_reports.report_id, hazard_reports.user_id, hazard_reports.reference_number, ' .
                'hazard_reports.category_id, hazard_reports.title, hazard_reports.description, ' .
                'hazard_reports.status, hazard_reports.submitted_at, hazard_categories.category_name, ' .
                'barangays.barangay_name, users.full_name AS reporter_name';
            $ownedByOthers = $this->db->table('hazard_reports')
                ->select($columns)
                ->left_join('hazard_categories', 'hazard_categories.category_id = hazard_reports.category_id')
                ->left_join('users', 'users.user_id = hazard_reports.user_id')
                ->left_join('barangays', 'barangays.barangay_id = users.barangay_id')
                ->where('hazard_reports.user_id', '!=', $this->userId())
                ->order_by('hazard_reports.submitted_at', 'DESC')
                ->limit(100)
                ->get_all();
            $deletedReporterReports = $this->db->table('hazard_reports')
                ->select($columns)
                ->left_join('hazard_categories', 'hazard_categories.category_id = hazard_reports.category_id')
                ->left_join('users', 'users.user_id = hazard_reports.user_id')
                ->left_join('barangays', 'barangays.barangay_id = users.barangay_id')
                ->where_null('hazard_reports.user_id')
                ->order_by('hazard_reports.submitted_at', 'DESC')
                ->limit(100)
                ->get_all();
            $reports = array_merge($ownedByOthers ?: [], $deletedReporterReports ?: []);
            usort($reports, static function ($left, $right) {
                return strcmp((string) ($right['submitted_at'] ?? ''), (string) ($left['submitted_at'] ?? ''));
            });
            $reports = array_slice($reports, 0, 100);
            $reportIds = array_map(static function ($report) {
                return (int) $report['report_id'];
            }, $reports);
            $voteRows = $reportIds
                ? $this->db->table('report_community_votes')
                    ->select('report_id, user_id, vote')
                    ->in('report_id', $reportIds)
                    ->get_all()
                : [];
            $voteCounts = [];
            $myVotes = [];
            foreach ($voteRows ?: [] as $voteRow) {
                $reportId = (int) $voteRow['report_id'];
                if (!isset($voteCounts[$reportId])) {
                    $voteCounts[$reportId] = ['confirm' => 0, 'dispute' => 0];
                }
                if (isset($voteCounts[$reportId][$voteRow['vote']])) {
                    $voteCounts[$reportId][$voteRow['vote']]++;
                }
                if ((int) $voteRow['user_id'] === $this->userId()) {
                    $myVotes[$reportId] = $voteRow['vote'];
                }
            }
            foreach ($reports as &$report) {
                $reportId = (int) $report['report_id'];
                $report['reporter_name'] = trim((string) ($report['reporter_name'] ?? '')) ?: 'Deleted User';
                $report['confirm_count'] = $voteCounts[$reportId]['confirm'] ?? 0;
                $report['dispute_count'] = $voteCounts[$reportId]['dispute'] ?? 0;
                $report['my_vote'] = $myVotes[$reportId] ?? null;
                $report['can_vote'] = !empty($report['user_id']) && (int) $report['user_id'] !== $this->userId();
                unset($report['user_id']);
            }
            unset($report);

            $evidenceByReport = [];

            if ($reportIds) {
                $evidence = $this->db->table('report_evidence')
                    ->select('evidence_id, report_id, file_path, evidence_type, uploaded_at')
                    ->in('report_id', $reportIds)
                    ->in('evidence_type', ['photo', 'video'])
                    ->order_by('uploaded_at', 'ASC')
                    ->order_by('evidence_id', 'ASC')
                    ->get_all();

                foreach ($evidence as $item) {
                    $evidenceByReport[(int) $item['report_id']][] = $item;
                }

            }

            foreach ($reports as &$report) {
                $report['evidence'] = $evidenceByReport[(int) $report['report_id']] ?? [];
            }
            unset($report);

            $statusCounts = $this->db->table('hazard_reports')
                ->select('status, COUNT(*) AS total')
                ->group_by('status')
                ->order_by('status', 'ASC')
                ->get_all();

            $categoryCounts = $this->db->table('hazard_categories')
                ->select('hazard_categories.category_id, hazard_categories.category_name, COUNT(hazard_reports.report_id) AS total')
                ->left_join('hazard_reports', 'hazard_reports.category_id = hazard_categories.category_id')
                ->group_by(['hazard_categories.category_id', 'hazard_categories.category_name'])
                ->order_by('total', 'DESC')
                ->order_by('hazard_categories.category_name', 'ASC')
                ->get_all();

            $barangayRows = $this->db->table('barangays')
                ->select('barangays.barangay_id, barangays.barangay_name, COUNT(hazard_reports.report_id) AS total')
                ->left_join('users', 'users.barangay_id = barangays.barangay_id')
                ->left_join('hazard_reports', 'hazard_reports.user_id = users.user_id')
                ->where_not_null('hazard_reports.report_id')
                ->group_by(['barangays.barangay_id', 'barangays.barangay_name'])
                ->order_by('total', 'DESC')
                ->order_by('barangays.barangay_name', 'ASC')
                ->get_all();
            $barangayCounts = $barangayRows ?: [];

            $alerts = $this->db->table('notifications')
                ->select('notification_id, title, message, notification_type, is_read, created_at')
                ->where('user_id', $this->userId())
                ->order_by('created_at', 'DESC')
                ->limit(20)
                ->get_all();
            $publicAlerts = $this->db->table('notifications')
                ->select('notification_id, title, message, notification_type, is_read, created_at')
                ->where_null('user_id')
                ->order_by('created_at', 'DESC')
                ->limit(20)
                ->get_all();
            $alerts = array_merge($alerts ?: [], $publicAlerts ?: []);
            usort($alerts, static function ($left, $right) {
                return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
            });
            $alerts = array_slice($alerts, 0, 20);

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

    public function communityMap()
    {
        try {
            if (!$this->hasReportCoordinates()) {
                api_response(['reports' => [], 'coordinates_available' => false]);
                return;
            }

            $mapColumns = 'hazard_reports.report_id, hazard_reports.reference_number, hazard_reports.category_id, ' .
                'hazard_reports.title, hazard_reports.status, hazard_reports.submitted_at, ' .
                'hazard_reports.latitude, hazard_reports.longitude, hazard_categories.category_name';
            if ($this->hasSeverityColumn()) {
                $mapColumns .= ', hazard_reports.severity_level';
            }
            $mapColumns .= ', hazard_reports.user_id';
            $reports = $this->db->table('hazard_reports')
                ->select($mapColumns)
                ->left_join('hazard_categories', 'hazard_categories.category_id = hazard_reports.category_id')
                ->in('hazard_reports.status', ['approved', 'verified', 'assigned', 'in_progress'])
                ->where('latitude', '>=', 13.25)
                ->where('latitude', '<=', 13.55)
                ->where('longitude', '>=', 121.05)
                ->where('longitude', '<=', 121.32)
                ->order_by('submitted_at', 'DESC')
                ->limit(1000)
                ->get_all();
            $reports = array_values(array_filter($reports ?: [], function ($report) {
                return in_array(strtolower(trim((string) ($report['status'] ?? ''))), [
                    'approved', 'verified', 'assigned', 'in_progress',
                ], true) && (
                    empty($report['user_id']) || (int) $report['user_id'] !== $this->userId()
                );
            }));
            $reports = array_slice($reports, 0, 500);
            foreach ($reports as &$report) {
                if (!$this->hasSeverityColumn()) {
                    $report['severity_level'] = null;
                }
                unset($report['user_id']);
            }
            unset($report);

            api_response([
                'reports' => $reports ?: [],
                'coordinates_available' => true,
            ]);
        } catch (\Throwable $e) {
            error_log('Community hazard map error: ' . $e->getMessage());
            api_error('Unable to load verified community hazard locations.', 500);
        }
    }

    public function vote($id)
    {
        if (!$this->validId($id)) {
            api_error('Invalid report ID.', 400);
            return;
        }

        $input = $this->input();
        if (array_key_exists('vote', $input) && $input['vote'] !== null) {
            api_validate_input($input, [
                'vote' => 'required|in_list[confirm,dispute]',
            ]);
        }
        if (!array_key_exists('vote', $input)) {
            api_error('Choose confirm or dispute, or clear your existing vote.', 422);
            return;
        }

        $vote = $input['vote'] ?? null;
        if ($vote !== null && !in_array($vote, ['confirm', 'dispute'], true)) {
            api_error('Choose confirm or dispute, or clear your existing vote.', 422);
            return;
        }

        try {
            $report = $this->db->table('hazard_reports')
                ->select('user_id')
                ->where('report_id', (int) $id)
                ->limit(1)
                ->get();

            if (!$report) {
                api_error('Report not found.', 404);
                return;
            }

            if ((int) $report['user_id'] === $this->userId()) {
                api_error('You cannot vote on your own report.', 403);
                return;
            }

            if ($vote === null) {
                $this->db->table('report_community_votes')
                    ->where('report_id', (int) $id)
                    ->where('user_id', $this->userId())
                    ->delete();
            } else {
                $existingVote = $this->db->table('report_community_votes')
                    ->select('report_id')
                    ->where('report_id', (int) $id)
                    ->where('user_id', $this->userId())
                    ->limit(1)
                    ->get();
                if ($existingVote) {
                    $this->db->table('report_community_votes')
                        ->where('report_id', (int) $id)
                        ->where('user_id', $this->userId())
                        ->update([
                            'vote' => $vote,
                            'updated_at' => gmdate('Y-m-d H:i:s'),
                        ]);
                } else {
                    $this->db->table('report_community_votes')->insert([
                        'report_id' => (int) $id,
                        'user_id' => $this->userId(),
                        'vote' => $vote,
                        'created_at' => gmdate('Y-m-d H:i:s'),
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                }
            }

            $voteRows = $this->db->table('report_community_votes')
                ->select('user_id, vote')
                ->where('report_id', (int) $id)
                ->get_all();
            $confirmCount = 0;
            $disputeCount = 0;
            $myVote = null;
            foreach ($voteRows ?: [] as $voteRow) {
                if ($voteRow['vote'] === 'confirm') {
                    $confirmCount++;
                } elseif ($voteRow['vote'] === 'dispute') {
                    $disputeCount++;
                }
                if ((int) $voteRow['user_id'] === $this->userId()) {
                    $myVote = $voteRow['vote'];
                }
            }

            api_response([
                'report_id' => (int) $id,
                'confirm_count' => $confirmCount,
                'dispute_count' => $disputeCount,
                'my_vote' => $myVote,
            ], 200, 'Your community vote was saved.');
        } catch (\Throwable $e) {
            error_log('Community report vote failed for report ' . (int) $id . ': ' . $e->getMessage());
            api_error('Unable to save your vote. Please try again.', 500);
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
                ->select(
                    'station_id, department_code, station_name, address_details, contact_number, ' .
                    'contact_email, station_info, latitude, longitude, is_active'
                )
                ->get_all();

            api_response(['stations' => $stations ?: []]);
        } catch (\Throwable $e) {
            error_log('Report stations error: ' . $e->getMessage());
            api_error('Unable to load stations.', 500);
        }
    }

    public function store()
    {
        if (!$this->ensureNotSuspended()) return;

        $uploadError = $this->uploadRequestError();
        if ($uploadError !== null) {
            api_error($uploadError['message'], $uploadError['status']);
            return;
        }

        $input = $this->input();
        $validationFields = [
            'category_id' => 'required|numeric',
            'title' => 'required|max_length[200]',
            'description' => 'required|max_length[60000]',
            'address_details' => 'required|max_length[255]',
        ];
        if (is_array($input) && array_key_exists('severity_level', $input)) {
            if (is_string($input['severity_level'])) {
                $input['severity_level'] = strtolower(trim($input['severity_level']));
            }
            $validationFields['severity_level'] = 'in_list[low,moderate,high,critical]';
        }
        api_validate_input(is_array($input) ? $input : [], $validationFields);

        $categoryId = (int) ($input['category_id'] ?? 0);

        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $address = trim((string) ($input['address_details'] ?? ''));
        $severity = strtolower(trim((string) ($input['severity_level'] ?? 'moderate')));
        $coordinates = $this->reportCoordinates($input);
        if ($coordinates === null) {
            return;
        }

        if ($categoryId < 1 || $title === '' || $description === '' || $address === '') {
            api_error('Category, title, description, and address are required.', 422);
            return;
        }
        if (!in_array($severity, ['low', 'moderate', 'high', 'critical'], true)) {
            api_error('Choose a valid hazard severity level.', 422);
            return;
        }

        if (mb_strlen($title) > 200 || mb_strlen($address) > 255) {
            api_error('The title or address exceeds the allowed length.', 422);
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

            $referenceNumber = 'BA-' . date('YmdHis') . '-' . random_int(100, 999);

            $reportData = [
                'reference_number' => $referenceNumber,
                'user_id' => $this->userId(),
                'category_id' => $categoryId,
                'station_id' => null,
                'title' => $title,
                'description' => $description,
                'address_details' => $address,
                'severity_level' => $severity,
                'status' => 'pending',
                'submitted_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            if ($this->hasReportCoordinates()) {
                $reportData = array_merge($reportData, $coordinates);
            }
            if (!$this->hasSeverityColumn()) unset($reportData['severity_level']);

            $inserted = $this->db->table('hazard_reports')->insert($reportData);

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

            $notificationWarning = '';
            try {
                $admins = $this->db->table('users')
                    ->select('users.user_id')
                    ->join('roles', 'roles.role_id = users.role_id')
                    ->where('roles.role_name', 'admin')
                    ->where('users.account_status', 'active')
                    ->get_all();
                $notificationCount = 0;
                foreach ($admins ?: [] as $admin) {
                    $notificationCount += (int) $this->db->table('notifications')->insert([
                        'user_id' => (int) $admin['user_id'],
                        'report_id' => $reportId,
                        'target_type' => 'report',
                        'title' => 'New hazard report submitted',
                        'message' => 'A resident submitted report ' . $referenceNumber . '. Review it in the report register.',
                        'notification_type' => 'new_report',
                        'is_read' => 0,
                        'created_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                }
                if ($notificationCount < 1) {
                    $notificationWarning = ' No active administrators were available to receive the notification.';
                }
            } catch (\Throwable $e) {
                error_log('New report admin notification failed for report ' . $reportId . ': ' . $e->getMessage());
                $notificationWarning = ' The report was saved, but administrator notifications could not be delivered.';
            }

            api_response([
                'report_id' => $reportId,
                'reference_number' => $referenceNumber
            ], 201, 'Hazard report submitted successfully.' . $notificationWarning);
        } catch (\Throwable $e) {
            error_log('Report store error: ' . $e->getMessage());
            api_error('Unable to save the report.', 500);
        }
    }

    public function update($id)
    {
        if (!$this->ensureNotSuspended()) return;

        if (!$this->validId($id)) {
            api_error('Invalid report ID.', 400);
            return;
        }

        $uploadError = $this->uploadRequestError();
        if ($uploadError !== null) {
            api_error($uploadError['message'], $uploadError['status']);
            return;
        }

        $input = $this->input();
        $validationFields = [
            'category_id' => 'required|numeric',
            'title' => 'required|max_length[200]',
            'description' => 'required|max_length[60000]',
            'address_details' => 'required|max_length[255]',
        ];
        if (is_array($input) && array_key_exists('severity_level', $input)) {
            if (is_string($input['severity_level'])) {
                $input['severity_level'] = strtolower(trim($input['severity_level']));
            }
            $validationFields['severity_level'] = 'in_list[low,moderate,high,critical]';
        }
        api_validate_input(is_array($input) ? $input : [], $validationFields);

        $categoryId = (int) ($input['category_id'] ?? 0);

        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $address = trim((string) ($input['address_details'] ?? ''));
        $severity = strtolower(trim((string) ($input['severity_level'] ?? 'moderate')));
        $coordinates = $this->reportCoordinates($input);
        if ($coordinates === null) {
            return;
        }

        if ($categoryId < 1 || $title === '' || $description === '' || $address === '') {
            api_error('Category, title, description, and address are required.', 422);
            return;
        }
        if (!in_array($severity, ['low', 'moderate', 'high', 'critical'], true)) {
            api_error('Choose a valid hazard severity level.', 422);
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

            $reportData = [
                'category_id' => $categoryId,
                'title' => $title,
                'description' => $description,
                'address_details' => $address,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            if ($this->hasSeverityColumn()) {
                $reportData['severity_level'] = $severity;
            }
            if ($this->hasReportCoordinates()) {
                $reportData = array_merge($reportData, $coordinates);
            }

            $this->db->table('hazard_reports')
                ->where('report_id', (int) $id)
                ->where('user_id', $this->userId())
                ->update($reportData);

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
        $evidence = $this->request->file('evidence');
        if (empty($evidence)) {
            return null;
        }

        $files = $evidence;
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

        $uploadDirectory = EvidenceStorage::directory();

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
