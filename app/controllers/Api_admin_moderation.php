<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Api_admin_moderation extends Controller
{
    private $currentUser;
    private $authorized = false;

    public function __construct()
    {
        parent::__construct();
        $this->currentUser = require_api_auth();
        $this->authorized = strtolower(trim((string) ($this->currentUser['role_name'] ?? ''))) === 'admin';
        if (!$this->authorized) {
            api_error('Administrator access required.', 403);
        }
    }

    private function validId($id)
    {
        return ctype_digit((string) $id) && (int) $id > 0;
    }

    private function resident($userId)
    {
        return $this->db->raw(
            "SELECT u.user_id, u.full_name, u.warning_strikes, u.status, u.suspension_reason " .
            "FROM users u INNER JOIN roles r ON r.role_id = u.role_id " .
            "WHERE u.user_id = ? AND LOWER(r.role_name) IN ('resident', 'user') LIMIT 1",
            [$userId]
        )->fetch(PDO::FETCH_ASSOC);
    }

    private function notifyUser($userId, $title, $message)
    {
        $this->db->raw(
            'INSERT INTO notifications ' .
            '(user_id, title, message, notification_type, target_type, is_read, created_at) ' .
            "VALUES (?, ?, ?, 'moderation', NULL, 0, NOW())",
            [$userId, $title, $message]
        );
    }

    public function issueWarningStrike($id)
    {
        if (!$this->authorized) return;
        if (!$this->validId($id)) {
            api_error('Invalid user ID.', 400);
            return;
        }

        $input = get_request_input();
        $reason = isset($input['reason']) && is_string($input['reason'])
            ? trim($input['reason'])
            : '';
        if (mb_strlen($reason) > 1000) {
            api_error('The warning reason must be 1,000 characters or fewer.', 422);
            return;
        }
        if ($reason === '') $reason = 'False or unverified hazard report.';

        try {
            $user = $this->resident((int) $id);
            if (!$user) {
                api_error('Resident account not found.', 404);
                return;
            }
            if ((int) $user['warning_strikes'] >= 3) {
                api_error('This resident already has three warning strikes.', 409);
                return;
            }

            $updatedStatement = $this->db->raw(
                "UPDATE users SET " .
                "status = IF(warning_strikes + 1 >= 3, 'suspended', status), " .
                "suspension_reason = IF(warning_strikes + 1 >= 3, ?, suspension_reason), " .
                'warning_strikes = LEAST(warning_strikes + 1, 3) ' .
                'WHERE user_id = ? AND warning_strikes < 3',
                [$reason, (int) $id]
            );
            if ($updatedStatement->rowCount() < 1) {
                api_error('This resident already has three warning strikes.', 409);
                return;
            }
            $updated = $this->resident((int) $id);
            if (!$updated) {
                api_error('Resident account not found.', 404);
                return;
            }
            $strikeCount = (int) $updated['warning_strikes'];
            $suspended = $updated['status'] === 'suspended';
            $message = 'You received warning strike ' . $strikeCount . ' of 3. Reason: ' . $reason;
            if ($suspended) {
                $message .= ' Your account has been suspended after reaching three strikes.';
            }
            $notificationWarning = '';
            try {
                $this->notifyUser((int) $id, 'Account warning issued', $message);
            } catch (\Throwable $notificationError) {
                error_log('Warning notification failed for user ' . (int) $id . ': ' . $notificationError->getMessage());
                $notificationWarning = ' The strike was saved, but its in-app notification could not be delivered.';
            }

            api_response([
                'user_id' => (int) $id,
                'warning_strikes' => $strikeCount,
                'status' => $updated['status'],
                'suspension_reason' => $updated['suspension_reason']
            ], 200, $suspended
                ? 'Third warning issued; the account is now suspended.' . $notificationWarning
                : 'Warning strike issued.' . $notificationWarning);
        } catch (\Throwable $e) {
            error_log('Warning strike update failed for user ' . (int) $id . ': ' . $e->getMessage());
            api_error('Unable to issue this warning strike.', 500);
        }
    }

    public function setSuspension($id)
    {
        if (!$this->authorized) return;
        if (!$this->validId($id)) {
            api_error('Invalid user ID.', 400);
            return;
        }

        $input = get_request_input();
        $suspended = $input['suspended'] ?? null;
        if (!in_array($suspended, [true, false, 1, 0, '1', '0'], true)) {
            api_error('Choose whether to suspend or reactivate this account.', 422);
            return;
        }
        $suspended = in_array($suspended, [true, 1, '1'], true);
        $reason = isset($input['reason']) && is_string($input['reason'])
            ? trim($input['reason'])
            : '';
        if ($suspended && $reason === '') $reason = 'Suspended directly by an administrator.';
        if (mb_strlen($reason) > 1000) {
            api_error('The suspension reason must be 1,000 characters or fewer.', 422);
            return;
        }

        try {
            $user = $this->resident((int) $id);
            if (!$user) {
                api_error('Resident account not found.', 404);
                return;
            }
            $this->db->raw(
                'UPDATE users SET status = ?, suspension_reason = ? WHERE user_id = ?',
                [$suspended ? 'suspended' : 'active', $suspended ? $reason : null, (int) $id]
            );
            $notificationWarning = '';
            try {
                $this->notifyUser(
                    (int) $id,
                    $suspended ? 'Account suspended' : 'Account reactivated',
                    $suspended
                        ? 'Your account has been suspended. Reason: ' . $reason
                        : 'Your account has been reactivated by an administrator.'
                );
            } catch (\Throwable $notificationError) {
                error_log('Account moderation notification failed for user ' . (int) $id . ': ' . $notificationError->getMessage());
                $notificationWarning = ' The account status changed, but its in-app notification could not be delivered.';
            }

            api_response([
                'user_id' => (int) $id,
                'status' => $suspended ? 'suspended' : 'active',
                'suspension_reason' => $suspended ? $reason : null
            ], 200, ($suspended ? 'Account suspended.' : 'Account reactivated.') . $notificationWarning);
        } catch (\Throwable $e) {
            error_log('Account moderation failed for user ' . (int) $id . ': ' . $e->getMessage());
            api_error('Unable to update this account.', 500);
        }
    }

    public function resolveReport($id)
    {
        if (!$this->authorized) return;
        if (!$this->validId($id)) {
            api_error('Invalid report ID.', 400);
            return;
        }

        try {
            $report = $this->db->table('hazard_reports')
                ->select('report_id, user_id, reference_number, status')
                ->where('report_id', (int) $id)
                ->get();
            if (!$report) {
                api_error('Hazard report not found.', 404);
                return;
            }
            if (strtolower((string) $report['status']) === 'resolved') {
                api_response(['report_id' => (int) $id], 200, 'This report is already resolved.');
                return;
            }
            if (!in_array(strtolower((string) $report['status']), ['approved', 'verified', 'assigned', 'in_progress'], true)) {
                api_error('Only active or verified hazard reports can be marked as resolved.', 409);
                return;
            }

            $this->db->raw(
                "UPDATE hazard_reports SET status = 'resolved', resolved_at = COALESCE(resolved_at, NOW()), updated_at = NOW() " .
                'WHERE report_id = ?',
                [(int) $id]
            );

            $notificationWarning = '';
            if (!empty($report['user_id'])) {
                try {
                    $this->db->raw(
                        'INSERT INTO notifications ' .
                        '(user_id, report_id, target_type, title, message, notification_type, is_read, created_at) ' .
                        "VALUES (?, ?, 'report', 'Hazard report resolved', ?, 'report_status', 0, NOW())",
                        [
                            (int) $report['user_id'],
                            (int) $id,
                            'Your report ' . ($report['reference_number'] ?: '#' . $id) . ' has been marked as resolved.'
                        ]
                    );
                } catch (\Throwable $notificationError) {
                    error_log('Resolution notification failed for report ' . (int) $id . ': ' . $notificationError->getMessage());
                    $notificationWarning = ' The report was resolved, but its notification could not be delivered.';
                }
            }

            api_response(
                ['report_id' => (int) $id],
                200,
                'Hazard report marked as resolved.' . $notificationWarning
            );
        } catch (\Throwable $e) {
            error_log('Hazard resolution failed for report ' . (int) $id . ': ' . $e->getMessage());
            api_error('Unable to mark this hazard report as resolved.', 500);
        }
    }
}
