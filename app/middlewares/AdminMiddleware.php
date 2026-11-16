<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminMiddleware
{
    public function handle(Closure $next)
    {
        $identity = require_api_auth();
        $userId = (int) ($identity['user_id'] ?? $identity['sub'] ?? 0);
        $lava = lava_instance();
        $user = $lava->db->table('users')
            ->select('roles.role_name, users.account_status')
            ->join('roles', 'roles.role_id = users.role_id')
            ->where('users.user_id', $userId)
            ->limit(1)
            ->get();

        if (!$user || strtolower(trim((string) ($user['role_name'] ?? ''))) !== 'admin') {
            api_error('Administrator access required.', 403);
        }
        if (($user['account_status'] ?? null) !== 'active') {
            api_error('This administrator account is not active.', 403);
        }

        return $next();
    }
}
