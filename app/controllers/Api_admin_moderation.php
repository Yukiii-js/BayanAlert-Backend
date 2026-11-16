<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Api_admin_moderation extends Controller
{
    private $currentUser;
    private $authorized = false;

    public function __construct()
    {
        parent::__construct();
        $this->call->model('Admin_model');
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
        foreach (['resident', 'user'] as $roleName) {
            $resident = $this->db->table('users')
                ->select('users.user_id, users.full_name, users.warning_strikes, users.status, users.suspension_reason')
                ->join('roles', 'roles.role_id = users.role_id')
                ->where('users.user_id', $userId)
                ->where('LOWER(roles.role_name)', $roleName)
                ->limit(1)
                ->get();
            if ($resident) {
                return $resident;
            }
        }

        return null;
    }

    private function notifyUser($userId, $title, $message)
    {
        $this->db->table('notifications')->insert([
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'notification_type' => 'moderation',
            'target_type' => null,
            'is_read' => 0,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function issueWarningStrike($id)
    {
        if (!$this->authorized) return;
        if (!$this->validId($id)) {
            api_error('Invalid user ID.', 400);
            return;
        }

        $input = get_request_input();
        if (is_array($input) && array_key_exists('reason', $input) && $input['reason'] !== null) {
            api_validate_input($input, [
                'reason' => 'max_length[1000]',
            ]);
        }
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

            $this->db->transaction();
            try {
                $updatedCount = $this->db->table('users')
                    ->where('user_id', (int) $id)
                    ->where('warning_strikes', '<', 3)
                    ->increment('warning_strikes');
                if ($updatedCount < 1) {
                    $this->db->roll_back();
                    api_error('This resident already has three warning strikes.', 409);
                    return;
                }
                $updated = $this->resident((int) $id);
                if ($updated && (int) $updated['warning_strikes'] >= 3) {
                    $this->db->table('users')
                        ->where('user_id', (int) $id)
                        ->update([
                            'status' => 'suspended',
                            'suspension_reason' => $reason,
                        ]);
                }
                $this->db->commit();
            } catch (\Throwable $updateError) {
                $this->db->roll_back();
                throw $updateError;
            }
            if (!$updated) {
                api_error('This resident already has three warning strikes.', 409);
                return;
            }
            $updated = $this->resident((int) $id);
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
        if (is_array($input) && array_key_exists('reason', $input) && $input['reason'] !== null) {
            api_validate_input($input, [
                'reason' => 'max_length[1000]',
            ]);
        }
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
            $this->db->table('users')
                ->where('user_id', (int) $id)
                ->update([
                    'status' => $suspended ? 'suspended' : 'active',
                    'suspension_reason' => $suspended ? $reason : null,
                ]);
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

            $this->db->transaction();
            try {
                $resolvedAt = gmdate('Y-m-d H:i:s');
                $this->db->table('hazard_reports')
                    ->where('report_id', (int) $id)
                    ->update([
                        'status' => 'resolved',
                        'resolved_at' => $report['resolved_at'] ?? $resolvedAt,
                        'updated_at' => $resolvedAt,
                    ]);
                $this->Admin_model->record_hazard_status_transition(
                    (int) $id,
                    (string) $report['status'],
                    'resolved',
                    (int) ($this->currentUser['user_id'] ?? 0)
                );
                $this->db->commit();
            } catch (\Throwable $workflowError) {
                $this->db->roll_back();
                throw $workflowError;
            }

            $notificationWarning = '';
            if (!empty($report['user_id'])) {
                try {
                    $this->db->table('notifications')->insert([
                        'user_id' => (int) $report['user_id'],
                        'report_id' => (int) $id,
                        'target_type' => 'report',
                        'title' => 'Hazard report resolved',
                        'message' => 'Your report ' . ($report['reference_number'] ?: '#' . $id) . ' has been marked as resolved.',
                        'notification_type' => 'report_status',
                        'is_read' => 0,
                        'created_at' => gmdate('Y-m-d H:i:s'),
                    ]);
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
