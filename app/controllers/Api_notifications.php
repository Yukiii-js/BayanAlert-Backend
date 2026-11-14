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
            $notifications = $this->db->raw(
                'SELECT notification_id, report_id, title, message, notification_type, is_read, created_at ' .
                'FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50',
                [$this->userId()]
            )->fetchAll(PDO::FETCH_ASSOC);

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
            $this->db->raw(
                'UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?',
                [(int) $id, $this->userId()]
            );
            $notification = $this->db->raw(
                'SELECT notification_id FROM notifications WHERE notification_id = ? AND user_id = ?',
                [(int) $id, $this->userId()]
            )->fetch(PDO::FETCH_ASSOC);

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
            $insert = $this->db->raw(
                "INSERT INTO notifications (user_id, title, message, notification_type, is_read, created_at) " .
                "SELECT u.user_id, ?, ?, 'announcement', 0, NOW() " .
                "FROM users u INNER JOIN roles r ON r.role_id = u.role_id " .
                "WHERE r.role_name IN ('resident', 'user') AND u.account_status = 'active'",
                [$title, $message]
            );
            $recipientCount = (int) $insert->rowCount();

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
