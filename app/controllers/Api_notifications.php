<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Api_notifications extends Controller
{
    private $currentUser;

    public function __construct()
    {
        parent::__construct();
        $this->currentUser = require_api_auth();

        $roleName = strtolower(trim((string) ($this->currentUser['role_name'] ?? '')));
        if (!in_array($roleName, ['admin', 'resident', 'user'], true)) {
            api_error('This account cannot access notifications.', 403);
        }
    }

    private function userId()
    {
        return (int) ($this->currentUser['user_id'] ?? $this->currentUser['sub'] ?? 0);
    }

    private function requireAdmin()
    {
        if (strtolower(trim((string) ($this->currentUser['role_name'] ?? ''))) !== 'admin') {
            api_error('Administrator access required.', 403);
        }
    }

    public function index()
    {
        try {
            $notifications = $this->db->table('notifications')
                ->select('notification_id, report_id, advisory_id, target_type, title, message, notification_type, is_read, created_at')
                ->where('user_id', $this->userId())
                ->order_by('created_at', 'DESC')
                ->limit(50)
                ->get_all();

            foreach ($notifications as &$notification) {
                if (!in_array($notification['target_type'], ['report', 'advisory'], true)) {
                    $notification['target_type'] = !empty($notification['report_id'])
                        ? 'report'
                        : ($notification['notification_type'] === 'external_hazard' ? 'advisory' : null);
                }
            }
            unset($notification);

            api_response(['notifications' => $notifications ?: []]);
        } catch (\Throwable $e) {
            error_log('Notification list failed: ' . $e->getMessage());
            api_error('Unable to load notifications.', 500);
        }
    }

    public function markRead($id)
    {
        if (!ctype_digit((string) $id) || (int) $id < 1) {
            api_error('Invalid notification ID.', 400);
        }

        try {
            $this->db->table('notifications')
                ->where('notification_id', (int) $id)
                ->where('user_id', $this->userId())
                ->update(['is_read' => 1]);
            $notification = $this->db->table('notifications')
                ->select('notification_id')
                ->where('notification_id', (int) $id)
                ->where('user_id', $this->userId())
                ->get();

            if (!$notification) {
                api_error('Notification not found.', 404);
            }

            api_response(['notification_id' => (int) $id], 200, 'Notification marked as read.');
        } catch (\Throwable $e) {
            error_log('Mark notification read failed: ' . $e->getMessage());
            api_error('Unable to update this notification.', 500);
        }
    }

    public function announce()
    {
        $this->requireAdmin();
        $input = get_request_input();
        api_validate_input(is_array($input) ? $input : [], [
            'title' => 'required|max_length[200]',
            'message' => 'required|max_length[5000]',
        ]);
        $title = isset($input['title']) && is_string($input['title'])
            ? trim($input['title'])
            : '';
        $message = isset($input['message']) && is_string($input['message'])
            ? trim($input['message'])
            : '';

        if ($title === '' || $message === '') {
            api_error('An announcement title and message are required.', 422);
        }
        if (mb_strlen($title) > 200 || mb_strlen($message) > 5000) {
            api_error('The title must be 200 characters or fewer and the message 5,000 characters or fewer.', 422);
        }

        try {
            $recipients = [];
            foreach (['resident', 'user'] as $roleName) {
                $roleRecipients = $this->db->table('users')
                    ->select('users.user_id')
                    ->join('roles', 'roles.role_id = users.role_id')
                    ->where('LOWER(roles.role_name)', $roleName)
                    ->where('users.account_status', 'active')
                    ->get_all();
                foreach ($roleRecipients as $recipient) {
                    $recipients[(int) $recipient['user_id']] = true;
                }
            }

            $createdAt = gmdate('Y-m-d H:i:s');
            foreach (array_keys($recipients) as $userId) {
                $this->db->table('notifications')->insert([
                    'user_id' => $userId,
                    'title' => $title,
                    'message' => $message,
                    'notification_type' => 'announcement',
                    'target_type' => null,
                    'is_read' => 0,
                    'created_at' => $createdAt,
                ]);
            }
            $recipientCount = count($recipients);

            if ($recipientCount < 1) {
                api_error('There are no active resident accounts to receive this announcement.', 409);
            }

            api_response(
                ['recipient_count' => $recipientCount],
                201,
                'Announcement sent to ' . $recipientCount . ' active resident(s).'
            );
        } catch (\Throwable $e) {
            error_log('Announcement delivery failed: ' . $e->getMessage());
            api_error('Unable to send the announcement.', 500);
        }
    }
}
