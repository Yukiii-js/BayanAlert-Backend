<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'libraries' . DIRECTORY_SEPARATOR . 'BrevoMailer.php';

class Api_auth extends Controller {

    public function __construct() {
        parent::__construct();
        $this->call->model('Auth_model');
    }

    public function login() {
        $input = get_request_input();
        $username = isset($input['username']) && is_string($input['username']) ? trim($input['username']) : '';
        $password = isset($input['password']) && is_string($input['password']) ? $input['password'] : '';
        $expectedRole = 'resident';
        if (array_key_exists('expected_role', $input)) {
            $expectedRole = is_string($input['expected_role'])
                ? strtolower(trim($input['expected_role']))
                : '';
        }

        if (empty($username) || empty($password)) {
            api_error('Username/email and password are required.', 400);
            return;
        }

        if (!in_array($expectedRole, ['admin', 'resident'], true)) {
            api_error('A valid login type is required.', 400);
            return;
        }

        try {
            $user = $this->Auth_model->get_user_by_email_or_username($username);
        } catch (\PDOException $e) {
            error_log('Authentication lookup failed: ' . $e->getMessage());
            api_error('Authentication service is temporarily unavailable. Please try again.', 503);
        }

        if (!$user) {
            api_error('Invalid credentials.', 401);
            return;
        }

        $passwordValid = $this->Auth_model->verify_password(
            $password,
            $user['password_hash'] ?? null
        );

        if (!$passwordValid) {
            api_error('Invalid credentials.', 401);
            return;
        }

        $roleName = strtolower(trim((string) ($user['role_name'] ?? '')));
        $isResident = in_array($roleName, ['resident', 'user'], true);
        if (($expectedRole === 'admin' && $roleName !== 'admin')
            || ($expectedRole === 'resident' && !$isResident)) {
            api_error('Invalid credentials.', 401);
            return;
        }

        if ($isResident && empty($user['email_verified_at'])) {
            api_error('Please verify your email address before signing in. You can resend the verification email below.', 403);
            return;
        }

        if ($user['account_status'] !== 'active') {
            if ($user['account_status'] === 'pending') {
                api_error('Your account is awaiting administrator approval.', 403);
            }
            api_error('Your account is not active. Please contact an administrator.', 403);
        }

        $tokenPayload = [
            'user_id'   => $user['user_id'],
            'full_name' => $user['full_name'],
            'role_id'   => $user['role_id'],
            'role_name' => $user['role_name'],
            'sub'       => $user['user_id']
        ];

        $token = generate_jwt_token($tokenPayload, 86400 * 7); // 7 days

        api_response([
            'token' => $token,
            'user'  => [
                'user_id'   => $user['user_id'],
                'full_name' => $user['full_name'],
                'role_id'   => $user['role_id'],
                'role_name' => $user['role_name']
            ]
        ], 200, 'Login successful.');
    }

    public function me() {
        $user = require_api_auth();
        api_response([
            'user' => [
                'user_id'   => $user['user_id'],
                'full_name' => $user['full_name'],
                'role_id'   => $user['role_id'],
                'role_name' => $user['role_name']
            ]
        ]);
    }

    public function logout() {
        api_response(null, 200, 'Logged out successfully.');
    }


    public function register() {
        $input = get_request_input();

        $full_name = isset($input['full_name']) && is_string($input['full_name'])
            ? trim($input['full_name']) : '';

        $email = isset($input['email']) && is_string($input['email'])
            ? strtolower(trim($input['email'])) : '';

        $username = isset($input['username']) && is_string($input['username'])
            ? trim($input['username']) : '';

        $password = isset($input['password']) && is_string($input['password'])
            ? $input['password'] : '';

        $confirm_password = isset($input['confirm_password']) && is_string($input['confirm_password'])
            ? $input['confirm_password'] : '';

        $address_details = isset($input['address_details']) && is_string($input['address_details'])
            ? trim($input['address_details']) : '';

        $barangay_id = isset($input['barangay_id']) && is_numeric($input['barangay_id'])
            ? (int) $input['barangay_id'] : 0;

        if ($full_name === '' || $email === '' || $username === '' ||
            $password === '' || $confirm_password === '' || $barangay_id <= 0) {
            api_error('Please complete all required fields.', 400);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            api_error('Please provide a valid email address.', 400);
            return;
        }

        if (strlen($username) < 3 || strlen($username) > 100) {
            api_error('Username must be between 3 and 100 characters.', 400);
            return;
        }

        if (strlen($password) < 8) {
            api_error('Password must contain at least 8 characters.', 400);
            return;
        }

        if ($password !== $confirm_password) {
            api_error('Passwords do not match.', 400);
            return;
        }

        try {
            if ($this->Auth_model->get_user_by_email($email)) {
                api_error('This email address is already registered.', 409);
                return;
            }

            if ($this->Auth_model->get_user_by_username($username)) {
                api_error('This username is already taken.', 409);
                return;
            }

            // Verify that the selected barangay exists and is active.
            $barangay = $this->Auth_model->get_active_barangay($barangay_id);

            if (!$barangay) {
                api_error('Please select a valid active barangay.', 400);
                return;
            }

            $residentRole = $this->Auth_model->get_resident_role();
            if (!$residentRole) {
                api_error('Resident registration is unavailable because the resident role is not configured.', 500);
                return;
            }

            $preRegistrationVerification = null;
            try {
                $preRegistrationVerification = $this->db->raw(
                    'SELECT verification_id, verified_at FROM registration_email_verifications ' .
                    'WHERE email = ? AND expires_at > NOW() LIMIT 1',
                    [$email]
                )->fetch(\PDO::FETCH_ASSOC);
            } catch (\Throwable $verificationStoreError) {
                error_log('Pre-registration email verification lookup unavailable: ' . $verificationStoreError->getMessage());
            }
            $verifiedEmail = $preRegistrationVerification && !empty($preRegistrationVerification['verified_at']);

            $data = [
                'role_id'         => $residentRole['role_id'],
                'barangay_id'     => $barangay_id,
                'full_name'       => $full_name,
                'email'           => $email,
                'username'        => $username,
                'password_hash'   => password_hash($password, PASSWORD_DEFAULT),
                'address_details' => $address_details !== '' ? $address_details : null,
                'account_status'  => 'pending',
                'email_verified_at' => $verifiedEmail ? date('Y-m-d H:i:s') : null
            ];

            $created = $this->Auth_model->create_resident($data);

            if (!$created) {
                api_error('Unable to create your account. Please try again.', 500);
                return;
            }

            $verificationEmailSent = (bool) ($preRegistrationVerification && !$verifiedEmail);
            if (!$verifiedEmail && !$verificationEmailSent) {
                try {
                    $resident = $this->Auth_model->get_user_by_email($email);
                    $verificationResult = $this->issueVerificationEmail(
                        (int) $resident['user_id'],
                        $email,
                        $full_name
                    );
                    $verificationEmailSent = $verificationResult['success'];
                    if (!$verificationResult['success']) {
                        error_log('Verification email was not queued for newly registered user ID ' . (int) $resident['user_id'] . '.');
                    }
                } catch (\Throwable $emailError) {
                    error_log('Registration succeeded but verification email failed: ' . $emailError->getMessage());
                }
            }

            api_response([
                'account_status' => 'pending',
                'email_verified' => (bool) $verifiedEmail
            ], 201, $verifiedEmail
                ? 'Registration successful. Your email is verified; administrator approval is still required before signing in.'
                : ($verificationEmailSent
                    ? 'Account created successfully. Verify your email before signing in using the link in your inbox.'
                    : 'Account created successfully, but a verification email could not be sent. Use the Verify button or request another email before signing in.'));

        } catch (\Exception $e) {
            error_log('Resident registration failed: ' . $e->getMessage());
            api_error('Registration failed due to a server or database error.', 500);
        }
    }

    public function verifyEmail()
    {
        $input = get_request_input();
        $token = isset($input['token']) && is_string($input['token']) ? trim($input['token']) : '';
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            api_error('This email verification link is invalid or expired.', 400);
            return;
        }

        try {
            $this->ensureEmailVerificationTables();
            $verification = null;
            $verification = $this->db->raw(
                'SELECT user_id FROM email_verification_tokens WHERE token_hash = ? AND expires_at > NOW() LIMIT 1',
                [hash('sha256', $token)]
            )->fetch(\PDO::FETCH_ASSOC);

            if ($verification) {
                $userId = (int) $verification['user_id'];
                $this->db->raw(
                    'UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE user_id = ?',
                    [$userId]
                );
                $this->db->raw('DELETE FROM email_verification_tokens WHERE user_id = ?', [$userId]);

                $user = $this->db->raw(
                    'SELECT account_status, email FROM users WHERE user_id = ? LIMIT 1',
                    [$userId]
                )->fetch(\PDO::FETCH_ASSOC);
                $accountStatus = $user['account_status'] ?? 'pending';
                api_response([
                    'email_verified' => true,
                    'account_status' => $accountStatus,
                    'email' => $user['email'] ?? ''
                ], 200, $accountStatus === 'active'
                    ? 'Email verified. You can now sign in.'
                    : 'Email verified. Administrator approval is still required before signing in.');
                return;
            }

            $registrationVerification = $this->db->raw(
                'SELECT email FROM registration_email_verifications ' .
                'WHERE token_hash = ? AND expires_at > NOW() LIMIT 1',
                [hash('sha256', $token)]
            )->fetch(\PDO::FETCH_ASSOC);
            if (!$registrationVerification) {
                api_error('This email verification link is invalid or expired.', 400);
                return;
            }

            $this->db->raw(
                'UPDATE registration_email_verifications SET verified_at = COALESCE(verified_at, NOW()) ' .
                'WHERE email = ?',
                [$registrationVerification['email']]
            );
            $this->db->raw(
                'UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE email = ?',
                [$registrationVerification['email']]
            );
            $user = $this->Auth_model->get_user_by_email($registrationVerification['email']);
            $accountStatus = $user['account_status'] ?? 'not_registered';
            api_response([
                'email_verified' => true,
                'account_status' => $accountStatus,
                'email' => $registrationVerification['email']
            ], 200, $accountStatus === 'not_registered'
                ? 'Email verified. You can now finish creating your account.'
                : ($accountStatus === 'active'
                    ? 'Email verified. You can now sign in.'
                    : 'Email verified. Administrator approval is still required before signing in.'));
        } catch (\Throwable $e) {
            error_log('Email verification failed: ' . $e->getMessage());
            api_error('Email verification is temporarily unavailable. Please try again.', 503);
        }
    }

    public function sendRegistrationVerification()
    {
        $input = get_request_input();
        $email = isset($input['email']) && is_string($input['email'])
            ? strtolower(trim($input['email']))
            : '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            api_error('Please enter a valid email address first.', 400);
            return;
        }

        try {
            $this->ensureEmailVerificationTables();
            $user = $this->Auth_model->get_user_by_email($email);
            if ($user && !empty($user['email_verified_at'])) {
                api_response(['email_sent' => false], 200, 'This email address is already verified.');
                return;
            }

            if ($user) {
                $recentToken = $this->db->raw(
                    'SELECT verification_id FROM email_verification_tokens ' .
                    'WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) LIMIT 1',
                    [(int) $user['user_id']]
                )->fetch(\PDO::FETCH_ASSOC);
                if ($recentToken) {
                    api_response(['email_sent' => true], 200, 'A verification email was sent recently. Check your inbox.');
                    return;
                }

                $result = $this->issueVerificationEmail(
                    (int) $user['user_id'],
                    $email,
                    (string) $user['full_name']
                );
            } else {
                $recentRequest = $this->db->raw(
                    'SELECT verification_id FROM registration_email_verifications ' .
                    'WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) LIMIT 1',
                    [$email]
                )->fetch(\PDO::FETCH_ASSOC);
                if ($recentRequest) {
                    api_response(['email_sent' => true], 200, 'A verification email was sent recently. Check your inbox.');
                    return;
                }

                $token = bin2hex(random_bytes(32));
                $this->db->raw(
                    'INSERT INTO registration_email_verifications (email, token_hash, expires_at, verified_at, created_at) ' .
                    'VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY), NULL, NOW()) ' .
                    'ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), expires_at = VALUES(expires_at), ' .
                    'verified_at = NULL, created_at = NOW()',
                    [$email, hash('sha256', $token)]
                );
                $result = (new BrevoMailer())->sendVerificationEmail($email, '', $token);
                if (!$result['success']) {
                    $this->db->raw(
                        'DELETE FROM registration_email_verifications WHERE email = ? AND token_hash = ?',
                        [$email, hash('sha256', $token)]
                    );
                }
            }

            if (!$result['success']) {
                api_error($result['error'] ?? 'Verification email could not be sent. Please try again later.', 503);
                return;
            }

            api_response(['email_sent' => true], 200, 'Verification link sent. Open it from your inbox before signing in.');
        } catch (\Throwable $e) {
            error_log('Registration verification email failed: ' . $e->getMessage());
            api_error('Verification email service is temporarily unavailable. Please try again.', 503);
        }
    }

    public function resendVerificationEmail()
    {
        $input = get_request_input();
        $email = isset($input['email']) && is_string($input['email'])
            ? strtolower(trim($input['email']))
            : '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            api_error('Please provide a valid email address.', 400);
            return;
        }

        try {
            $this->ensureEmailVerificationTables();
            $user = $this->Auth_model->get_user_by_email($email);
            if ($user
                && in_array(strtolower(trim((string) ($user['role_name'] ?? ''))), ['resident', 'user'], true)
                && empty($user['email_verified_at'])) {
                $recentToken = $this->db->raw(
                    'SELECT verification_id FROM email_verification_tokens ' .
                    'WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) LIMIT 1',
                    [(int) $user['user_id']]
                )->fetch(\PDO::FETCH_ASSOC);

                if (!$recentToken) {
                    $result = $this->issueVerificationEmail(
                        (int) $user['user_id'],
                        $email,
                        (string) $user['full_name']
                    );
                    if (!$result['success']) {
                        error_log('Verification email could not be resent for user ID ' . (int) $user['user_id'] . '.');
                        api_error($result['error'] ?? 'Verification email could not be sent. Please try again later.', 503);
                        return;
                    }
                }
            } elseif (!$user) {
                $registrationVerification = $this->db->raw(
                    'SELECT verified_at, ' .
                    '(created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)) AS is_recent ' .
                    'FROM registration_email_verifications WHERE email = ? LIMIT 1',
                    [$email]
                )->fetch(\PDO::FETCH_ASSOC);

                if ($registrationVerification && !empty($registrationVerification['verified_at'])) {
                    api_response(['email_sent' => false], 200, 'This email address is already verified. You can continue creating your account.');
                    return;
                }

                if ($registrationVerification && !empty($registrationVerification['is_recent'])) {
                    api_response(['email_sent' => true], 200, 'A verification email was sent recently. Check your inbox.');
                    return;
                }

                $token = bin2hex(random_bytes(32));
                $this->db->raw(
                    'INSERT INTO registration_email_verifications (email, token_hash, expires_at, verified_at, created_at) ' .
                    'VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY), NULL, NOW()) ' .
                    'ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), expires_at = VALUES(expires_at), ' .
                    'verified_at = NULL, created_at = NOW()',
                    [$email, hash('sha256', $token)]
                );
                $result = (new BrevoMailer())->sendVerificationEmail($email, '', $token);
                if (!$result['success']) {
                    $this->db->raw(
                        'DELETE FROM registration_email_verifications WHERE email = ? AND token_hash = ?',
                        [$email, hash('sha256', $token)]
                    );
                    api_error($result['error'] ?? 'Verification email could not be sent. Please try again later.', 503);
                    return;
                }

                api_response(['email_sent' => true], 200, 'Verification link sent. Open it from your inbox before registering.');
                return;
            }

            api_response(null, 200, 'If the account is eligible, a verification email has been sent.');
        } catch (\Throwable $e) {
            error_log('Verification email resend failed: ' . $e->getMessage());
            api_error('Verification email service is temporarily unavailable. Please try again.', 503);
        }
    }

    private function issueVerificationEmail($userId, $email, $fullName)
    {
        $this->ensureEmailVerificationTables();
        $token = bin2hex(random_bytes(32));
        $this->db->raw('DELETE FROM email_verification_tokens WHERE user_id = ?', [$userId]);
        $this->db->raw(
            'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))',
            [$userId, hash('sha256', $token)]
        );

        $result = (new BrevoMailer())->sendVerificationEmail($email, $fullName, $token);
        if (!$result['success']) {
            $this->db->raw(
                'DELETE FROM email_verification_tokens WHERE user_id = ? AND token_hash = ?',
                [$userId, hash('sha256', $token)]
            );
        }

        return $result;
    }

    private function ensureEmailVerificationTables()
    {
        $this->db->raw(
            'CREATE TABLE IF NOT EXISTS email_verification_tokens (' .
            'verification_id INT UNSIGNED NOT NULL AUTO_INCREMENT,' .
            'user_id INT UNSIGNED NOT NULL,' .
            'token_hash CHAR(64) NOT NULL,' .
            'expires_at DATETIME NOT NULL,' .
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
            'PRIMARY KEY (verification_id),' .
            'UNIQUE KEY email_verification_user_unique (user_id),' .
            'UNIQUE KEY email_verification_token_unique (token_hash),' .
            'CONSTRAINT email_verification_user_fk FOREIGN KEY (user_id) ' .
            'REFERENCES users (user_id) ON DELETE CASCADE' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $this->db->raw(
            'CREATE TABLE IF NOT EXISTS registration_email_verifications (' .
            'verification_id INT UNSIGNED NOT NULL AUTO_INCREMENT,' .
            'email VARCHAR(150) NOT NULL,' .
            'token_hash CHAR(64) NOT NULL,' .
            'expires_at DATETIME NOT NULL,' .
            'verified_at DATETIME NULL,' .
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
            'PRIMARY KEY (verification_id),' .
            'UNIQUE KEY registration_email_verification_email_unique (email),' .
            'UNIQUE KEY registration_email_verification_token_unique (token_hash),' .
            'KEY registration_email_verification_expiry_idx (expires_at)' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}
