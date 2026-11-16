<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ActiveAccountMiddleware
{
    public function handle(Closure $next)
    {
        $identity = require_api_auth();
        $userId = (int) ($identity['user_id'] ?? $identity['sub'] ?? 0);
        $lava = lava_instance();
        $user = $lava->db->table('users')
            ->select('account_status, status, suspension_reason')
            ->where('user_id', $userId)
            ->limit(1)
            ->get();

        if (!$user) {
            api_error('Authentication account was not found.', 401);
        }
        if (($user['account_status'] ?? null) !== 'active'
            || strtolower(trim((string) ($user['status'] ?? 'active'))) === 'suspended') {
            api_error('This account is not permitted to access protected services.', 403);
        }

        return $next();
    }
}
