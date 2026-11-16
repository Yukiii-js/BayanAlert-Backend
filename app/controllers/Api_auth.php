<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'libraries' . DIRECTORY_SEPARATOR . 'BrevoMailer.php';

class Api_auth extends Controller {

    public function __construct() {
        parent::__construct();
        $this->call->model('Auth_model');
        $this->call->model('OtpVerifications_model');
    }

    public function login() {
        $input = get_request_input();
        api_validate_input(is_array($input) ? $input : [], [
            'username' => 'required|max_length[150]',
            'password' => 'required|max_length[255]',
        ]);
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

        if (strtolower(trim((string) ($user['status'] ?? 'active'))) === 'suspended') {
            $reason = trim((string) ($user['suspension_reason'] ?? ''));
            api_error(
                'Your account is suspended.' . ($reason !== '' ? ' Reason: ' . $reason : ' Contact an administrator for assistance.'),
                403
            );
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
            api_error('Please verify your email address before signing in. Use the registration page to request a new verification link.', 403);
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

        try {
            $token = generate_jwt_token($tokenPayload, 86400 * 7);
        } catch (RuntimeException $e) {
            error_log('JWT token issuance is unavailable: ' . $e->getMessage());
            api_error('Authentication service is unavailable.', 503);
        }

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
        api_validate_input(is_array($input) ? $input : [], [
            'full_name' => 'required|max_length[160]',
            'email' => 'required|valid_email|max_length[254]',
            'username' => 'required|min_length[3]|max_length[100]',
            'password' => 'required|min_length[8]|max_length[255]',
            'confirm_password' => 'required|matches[password]',
            'barangay_id' => 'required|numeric',
        ]);

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
                $preRegistrationVerification = $this->db->table('registration_email_verifications')
                    ->select('verification_id, verified_at, expires_at')
                    ->where('email', $email)
                    ->limit(1)
                    ->get();
                if (!$this->isFutureTimestamp($preRegistrationVerification['expires_at'] ?? null)) {
                    $preRegistrationVerification = null;
                }
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
        api_validate_input(is_array($input) ? $input : [], [
            'token' => 'required|exact_length[64]',
        ]);
        $token = isset($input['token']) && is_string($input['token']) ? trim($input['token']) : '';
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            api_error('This email verification link is invalid or expired.', 400);
            return;
        }

        try {
            $verification = null;
            if ($this->tableExists('email_verification_tokens')) {
                $verification = $this->db->table('email_verification_tokens')
                    ->select('user_id, expires_at')
                    ->where('token_hash', hash('sha256', $token))
                    ->limit(1)
                    ->get();
                if (!$this->isFutureTimestamp($verification['expires_at'] ?? null)) {
                    $verification = null;
                }
            }

            if ($verification) {
                $userId = (int) $verification['user_id'];
                $userRecord = $this->db->table('users')
                    ->select('email_verified_at')
                    ->where('user_id', $userId)
                    ->limit(1)
                    ->get();
                if ($userRecord && empty($userRecord['email_verified_at'])) {
                    $this->db->table('users')
                        ->where('user_id', $userId)
                        ->update(['email_verified_at' => gmdate('Y-m-d H:i:s')]);
                }
                $this->db->table('email_verification_tokens')->where('user_id', $userId)->delete();

                $user = $this->db->table('users')
                    ->select('account_status, email')
                    ->where('user_id', $userId)
                    ->limit(1)
                    ->get();
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

            $registrationVerification = null;
            if ($this->tableExists('registration_email_verifications')) {
                $registrationVerification = $this->db->table('registration_email_verifications')
                    ->select('email, expires_at')
                    ->where('token_hash', hash('sha256', $token))
                    ->limit(1)
                    ->get();
                if (!$this->isFutureTimestamp($registrationVerification['expires_at'] ?? null)) {
                    $registrationVerification = null;
                }
            }
            if (!$registrationVerification) {
                api_error('This email verification link is invalid or expired.', 400);
                return;
            }

            $this->db->table('registration_email_verifications')
                ->where('email', $registrationVerification['email'])
                ->where_null('verified_at')
                ->update(['verified_at' => gmdate('Y-m-d H:i:s')]);
            $this->db->table('users')
                ->where('email', $registrationVerification['email'])
                ->where_null('email_verified_at')
                ->update(['email_verified_at' => gmdate('Y-m-d H:i:s')]);
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
        api_validate_input(is_array($input) ? $input : [], [
            'email' => 'required|valid_email|max_length[254]',
        ]);
        $email = isset($input['email']) && is_string($input['email'])
            ? strtolower(trim($input['email']))
            : '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            api_error('Please enter a valid email address first.', 400);
            return;
        }

        $stage = 'email account lookup';
        try {
            $user = $this->Auth_model->get_user_by_email($email);
            if ($user) {
                api_error('Email already registered. Sign in or use account recovery instead.', 409);
                return;
            }

            $stage = 'verification table check';
            $this->ensureOtpVerificationTable();
            $this->ensureRegistrationEmailVerificationTable();
            $stage = 'existing verification lookup';
            $alreadyVerified = $this->db->table('registration_email_verifications')
                ->select('verification_id, expires_at')
                ->where('email', $email)
                ->where_not_null('verified_at')
                ->limit(1)
                ->get();
            if ($alreadyVerified && $this->isFutureTimestamp($alreadyVerified['expires_at'] ?? null)) {
                api_response([
                    'email_sent' => false,
                    'email_verified' => true,
                ], 200, 'This email address is already verified. You can continue creating your account.');
                return;
            }

            $stage = 'OTP resend limit check';
            $retryAfter = $this->OtpVerifications_model->registrationCooldownSeconds($email);
            if ($retryAfter > 0) {
                api_response([
                    'email_sent' => false,
                    'retry_after_seconds' => $retryAfter,
                ], 200, 'A verification code was sent recently. You can request another code in ' . $retryAfter . ' seconds.');
                return;
            }

            $stage = 'OTP storage and email delivery';
            $code = (string) random_int(100000, 999999);
            $expiresAt = gmdate('Y-m-d H:i:s', time() + 10 * 60);
            $otpId = $this->OtpVerifications_model->createRegistrationCode(
                $email,
                password_hash($code, PASSWORD_DEFAULT),
                $expiresAt
            );
            if (!$otpId) {
                throw new \RuntimeException('The registration verification code could not be stored in otp_verifications.');
            }
            $result = (new BrevoMailer())->sendRegistrationOtpEmail($email, $code);
            if (!$result['success']) {
                $this->OtpVerifications_model->deleteRegistrationCode((int) $otpId);
                api_error(
                    $result['error'] ?? 'Verification email could not be sent. Please try again later.',
                    503
                );
                return;
            }

            api_response([
                'email_sent' => true,
                'expires_in_seconds' => 600,
                'retry_after_seconds' => 60,
            ], 200, 'Verification code sent. Enter the 6-digit code from your inbox.');
        } catch (\Throwable $e) {
            $errorInfo = $e instanceof \PDOException && is_array($e->errorInfo ?? null)
                ? $e->errorInfo
                : [];
            $sqlState = (string) ($errorInfo[0] ?? $e->getCode());
            $driverCode = (int) ($errorInfo[1] ?? 0);
            error_log(
                'Registration verification code request failed during ' . $stage .
                ' (SQLSTATE ' . $sqlState . ', driver ' . $driverCode . '): ' . $e->getMessage()
            );
            $message = stripos($e->getMessage(), 'otp_verifications') !== false
                || stripos($e->getMessage(), 'registration_email_verifications') !== false
                ? 'Email verification tables are not ready. Apply manual_migration_013_otp_verifications.sql to the Aiven database.'
                : $this->registrationDatabaseErrorMessage($stage, $sqlState, $driverCode);
            api_error($message, 503);
        }
    }

    public function verifyRegistrationOtp()
    {
        $input = get_request_input();
        api_validate_input(is_array($input) ? $input : [], [
            'email' => 'required|valid_email|max_length[254]',
            'code' => 'required|exact_length[6]|numeric',
        ]);
        $email = isset($input['email']) && is_string($input['email'])
            ? strtolower(trim($input['email']))
            : '';
        $code = isset($input['code']) && is_string($input['code'])
            ? trim($input['code'])
            : '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            api_error('Please enter a valid email address.', 400);
            return;
        }
        if (!preg_match('/^[0-9]{6}$/', $code)) {
            api_error('Enter the 6-digit verification code.', 400);
            return;
        }

        $stage = 'email account lookup';
        try {
            if ($this->Auth_model->get_user_by_email($email)) {
                api_error('Email already registered. Sign in or use account recovery instead.', 409);
                return;
            }

            $stage = 'verification table check';
            $this->ensureOtpVerificationTable();
            $this->ensureRegistrationEmailVerificationTable();
            $stage = 'OTP lookup and validation';
            $verification = $this->OtpVerifications_model->getActiveRegistrationCode($email);
            if (!$verification) {
                api_error('The verification code is invalid or expired. Request a new code and try again.', 400);
                return;
            }
            if (!password_verify($code, (string) $verification['code_hash'])) {
                $this->OtpVerifications_model->recordRegistrationCodeFailure((int) $verification['otp_id']);
                api_error('The verification code is invalid or expired. Request a new code and try again.', 400);
                return;
            }

            $stage = 'verification state update';
            $this->db->transaction();
            try {
                if (!$this->OtpVerifications_model->consumeRegistrationCode((int) $verification['otp_id'])) {
                    $this->db->roll_back();
                    api_error('The verification code is invalid or expired. Request a new code and try again.', 400);
                    return;
                }
                $this->saveRegistrationEmailVerification(
                    $email,
                    hash('sha256', random_bytes(32)),
                    gmdate('Y-m-d H:i:s', time() + 86400),
                    gmdate('Y-m-d H:i:s')
                );
                $this->db->commit();
            } catch (\Throwable $workflowError) {
                $this->db->roll_back();
                throw $workflowError;
            }

            api_response([
                'email_verified' => true,
                'email' => $email,
            ], 200, 'Email verified successfully. You can continue creating your account.');
        } catch (\Throwable $e) {
            $errorInfo = $e instanceof \PDOException && is_array($e->errorInfo ?? null)
                ? $e->errorInfo
                : [];
            $sqlState = (string) ($errorInfo[0] ?? $e->getCode());
            $driverCode = (int) ($errorInfo[1] ?? 0);
            error_log(
                'Registration OTP verification failed during ' . $stage .
                ' (SQLSTATE ' . $sqlState . ', driver ' . $driverCode . '): ' . $e->getMessage()
            );
            $message = stripos($e->getMessage(), 'otp_verifications') !== false
                || stripos($e->getMessage(), 'registration_email_verifications') !== false
                ? 'Email verification tables are not ready. Apply manual_migration_013_otp_verifications.sql to the Aiven database.'
                : $this->registrationDatabaseErrorMessage($stage, $sqlState, $driverCode);
            api_error($message, 503);
        }
    }

    public function resendVerificationEmail()
    {
        $input = get_request_input();
        api_validate_input(is_array($input) ? $input : [], [
            'email' => 'required|valid_email|max_length[254]',
        ]);
        $email = isset($input['email']) && is_string($input['email'])
            ? strtolower(trim($input['email']))
            : '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            api_error('Please provide a valid email address.', 400);
            return;
        }

        try {
            $user = $this->Auth_model->get_user_by_email($email);
            if ($user
                && in_array(strtolower(trim((string) ($user['role_name'] ?? ''))), ['resident', 'user'], true)
                && empty($user['email_verified_at'])) {
                $this->ensureUserEmailVerificationTable();
                $recentToken = $this->db->table('email_verification_tokens')
                    ->select('verification_id, created_at')
                    ->where('user_id', (int) $user['user_id'])
                    ->order_by('created_at', 'DESC')
                    ->limit(1)
                    ->get();

                if (!$recentToken || !$this->isRecentTimestamp($recentToken['created_at'] ?? null, 60)) {
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
                $this->ensureRegistrationEmailVerificationTable();
                $registrationVerification = $this->db->table('registration_email_verifications')
                    ->select('verified_at, created_at')
                    ->where('email', $email)
                    ->limit(1)
                    ->get();

                if ($registrationVerification && !empty($registrationVerification['verified_at'])) {
                    api_response(['email_sent' => false], 200, 'This email address is already verified. You can continue creating your account.');
                    return;
                }

                if ($registrationVerification
                    && $this->isRecentTimestamp($registrationVerification['created_at'] ?? null, 60)) {
                    api_response(['email_sent' => true], 200, 'A verification email was sent recently. Check your inbox.');
                    return;
                }

                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $this->saveRegistrationEmailVerification(
                    $email,
                    $tokenHash,
                    gmdate('Y-m-d H:i:s', time() + 86400),
                    null
                );
                $result = (new BrevoMailer())->sendVerificationEmail($email, '', $token);
                if (!$result['success']) {
                    $this->db->table('registration_email_verifications')
                        ->where('email', $email)
                        ->where('token_hash', $tokenHash)
                        ->delete();
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

    public function requestPasswordReset()
    {
        $input = get_request_input();
        api_validate_input(is_array($input) ? $input : [], [
            'email' => 'required|valid_email|max_length[254]',
        ]);
        $email = isset($input['email']) && is_string($input['email'])
            ? strtolower(trim($input['email']))
            : '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            api_error('Please provide a valid email address.', 400);
            return;
        }

        $genericMessage = 'If the verified account is eligible, a password reset link will be sent shortly.';

        try {
            $user = $this->Auth_model->get_user_by_email($email);
            $isResident = $user
                && in_array(strtolower(trim((string) ($user['role_name'] ?? ''))), ['resident', 'user'], true);
            if (!$isResident || empty($user['email_verified_at'])) {
                api_response(['email_sent' => false], 200, $genericMessage);
                return;
            }

            $this->ensurePasswordResetTable();
            $userId = (int) $user['user_id'];
            $recentRequest = $this->db->table('password_reset_tokens')
                ->select('reset_id, created_at')
                ->where('user_id', $userId)
                ->order_by('created_at', 'DESC')
                ->limit(1)
                ->get();
            if ($recentRequest && $this->isRecentTimestamp($recentRequest['created_at'] ?? null, 60)) {
                api_response(['email_sent' => true], 200, $genericMessage);
                return;
            }

            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $this->db->table('password_reset_tokens')->where('user_id', $userId)->delete();
            $this->db->table('password_reset_tokens')->insert([
                'user_id' => $userId,
                'token_hash' => $tokenHash,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);

            $result = (new BrevoMailer())->sendPasswordResetEmail(
                $email,
                (string) ($user['full_name'] ?? ''),
                $token
            );
            if (!$result['success']) {
                $this->db->table('password_reset_tokens')
                    ->where('user_id', $userId)
                    ->where('token_hash', $tokenHash)
                    ->delete();
                error_log('Password reset email could not be sent for user ID ' . $userId . '.');
                api_error($result['error'] ?? 'Password reset email could not be sent. Please try again later.', 503);
                return;
            }

            api_response(['email_sent' => true], 200, $genericMessage);
        } catch (\Throwable $e) {
            error_log('Password reset request failed: ' . $e->getMessage());
            api_error('Password reset service is temporarily unavailable. Please try again.', 503);
        }
    }

    public function resetPassword()
    {
        $input = get_request_input();
        api_validate_input(is_array($input) ? $input : [], [
            'token' => 'required|exact_length[64]',
            'password' => 'required|min_length[8]|max_length[255]',
            'confirm_password' => 'required|matches[password]',
        ]);
        $token = isset($input['token']) && is_string($input['token']) ? trim($input['token']) : '';
        $password = isset($input['password']) && is_string($input['password']) ? $input['password'] : '';
        $confirmPassword = isset($input['confirm_password']) && is_string($input['confirm_password'])
            ? $input['confirm_password']
            : '';

        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            api_error('This password reset link is invalid or expired.', 400);
            return;
        }
        if (strlen($password) < 8) {
            api_error('Password must contain at least 8 characters.', 400);
            return;
        }
        if ($password !== $confirmPassword) {
            api_error('Passwords do not match.', 400);
            return;
        }

        try {
            $this->ensurePasswordResetTable();
            $tokenHash = hash('sha256', $token);
            $reset = $this->db->table('password_reset_tokens')
                ->select('password_reset_tokens.user_id, password_reset_tokens.expires_at, users.email_verified_at, roles.role_name')
                ->join('users', 'users.user_id = password_reset_tokens.user_id')
                ->join('roles', 'roles.role_id = users.role_id')
                ->where('password_reset_tokens.token_hash', $tokenHash)
                ->limit(1)
                ->get();
            if (!$reset
                || !$this->isFutureTimestamp($reset['expires_at'] ?? null)
                || empty($reset['email_verified_at'])
                || !in_array(strtolower((string) ($reset['role_name'] ?? '')), ['resident', 'user'], true)) {
                api_error('This password reset link is invalid or expired.', 400);
                return;
            }

            $userId = (int) $reset['user_id'];
            $this->db->transaction();
            try {
                $claimedToken = $this->db->table('password_reset_tokens')
                    ->where('token_hash', $tokenHash)
                    ->where('expires_at', '>', gmdate('Y-m-d H:i:s'))
                    ->delete();
                if ($claimedToken !== 1) {
                    $this->db->roll_back();
                    api_error('This password reset link is invalid or expired.', 400);
                    return;
                }

                $this->db->table('users')
                    ->where('user_id', $userId)
                    ->update(['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
                $this->db->table('password_reset_tokens')->where('user_id', $userId)->delete();
                $this->db->commit();
            } catch (\Throwable $resetError) {
                $this->db->roll_back();
                throw $resetError;
            }

            api_response(['password_reset' => true], 200, 'Your password has been reset. You can now sign in.');
        } catch (\Throwable $e) {
            error_log('Password reset failed: ' . $e->getMessage());
            api_error('Password reset service is temporarily unavailable. Please try again.', 503);
        }
    }

    private function issueVerificationEmail($userId, $email, $fullName)
    {
        $this->ensureUserEmailVerificationTable();
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $this->db->table('email_verification_tokens')->where('user_id', $userId)->delete();
        $this->db->table('email_verification_tokens')->insert([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $result = (new BrevoMailer())->sendVerificationEmail($email, $fullName, $token);
        if (!$result['success']) {
            $this->db->table('email_verification_tokens')
                ->where('user_id', $userId)
                ->where('token_hash', $tokenHash)
                ->delete();
        }

        return $result;
    }

    private function ensureUserEmailVerificationTable()
    {
        if (!$this->tableExists('email_verification_tokens')) {
            throw new \RuntimeException(
                'The email_verification_tokens table is missing. Apply migration 006 before enabling verification.'
            );
        }
    }

    private function saveRegistrationEmailVerification($email, $tokenHash, $expiresAt, $verifiedAt)
    {
        $existing = $this->db->table('registration_email_verifications')
            ->select('verification_id')
            ->where('email', $email)
            ->limit(1)
            ->get();
        $data = [
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'verified_at' => $verifiedAt,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];

        if ($existing) {
            return $this->db->table('registration_email_verifications')
                ->where('email', $email)
                ->update($data);
        }

        return $this->db->table('registration_email_verifications')->insert(array_merge(
            ['email' => $email],
            $data
        ));
    }

    private function isFutureTimestamp($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return false;
        }
        $timestamp = strtotime($value . ' UTC');
        return $timestamp !== false && $timestamp > time();
    }

    private function isRecentTimestamp($value, $seconds)
    {
        if (!is_string($value) || trim($value) === '') {
            return false;
        }
        $timestamp = strtotime($value . ' UTC');
        if ($timestamp === false) {
            return false;
        }
        $age = time() - $timestamp;
        return $age >= 0 && $age < (int) $seconds;
    }

    private function registrationDatabaseErrorMessage($stage, $sqlState, $driverCode)
    {
        if (in_array($driverCode, [2002, 2003, 2006, 2013], true)) {
            return 'Cannot connect to Aiven during ' . $stage . '. Check Render DB_HOST, DB_PORT, Aiven network access, and DB_SSL_CA settings.';
        }
        if ($driverCode === 1045 || $sqlState === '28000') {
            return 'Aiven rejected the database login during ' . $stage . '. Check Render DB_USER and DB_PASSWORD.';
        }
        if ($sqlState === '42S02' || $sqlState === '42S22') {
            return 'The Aiven database schema is incomplete during ' . $stage . '. Check the users/roles tables and apply manual_migration_013_otp_verifications.sql.';
        }

        $diagnostic = $sqlState !== ''
            ? ' (SQLSTATE ' . $sqlState . ($driverCode ? ', MySQL ' . $driverCode : '') . ')'
            : '';
        return 'Email verification failed during ' . $stage . $diagnostic . '. Check the Aiven database and backend logs.';
    }

    private function ensureOtpVerificationTable()
    {
        if (!$this->tableExists('otp_verifications')) {
            throw new \RuntimeException('The otp_verifications table is missing.');
        }

        $columns = get_table_column_names($this->db, 'otp_verifications');
        $required = ['otp_id', 'user_id', 'email', 'code_hash', 'verification_type', 'expires_at', 'used_at', 'attempt_count', 'created_at'];
        $missing = array_values(array_diff($required, $columns));
        if ($missing) {
            throw new \RuntimeException(
                'otp_verifications is missing required columns: ' . implode(', ', $missing)
            );
        }
    }

    private function ensureRegistrationEmailVerificationTable()
    {
        if ($this->tableExists('registration_email_verifications')) {
            return;
        }

        throw new RuntimeException(
            'The registration_email_verifications table is missing. Apply migration 007 before enabling verification.'
        );
    }

    private function ensurePasswordResetTable()
    {
        if ($this->tableExists('password_reset_tokens')) {
            return;
        }

        throw new RuntimeException(
            'The password_reset_tokens table is missing. Apply the password-reset migration before enabling password reset.'
        );
    }

    private function tableExists(string $tableName): bool
    {
        return database_table_exists($this->db, $tableName);
    }
}
