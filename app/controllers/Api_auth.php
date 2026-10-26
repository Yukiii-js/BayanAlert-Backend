<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Api_auth extends Controller {

    public function __construct() {
        parent::__construct();
        $this->call->model('Auth_model');
    }

    public function login() {
        $input = get_request_input();
        $username = isset($input['username']) && is_string($input['username']) ? trim($input['username']) : '';
        $password = isset($input['password']) && is_string($input['password']) ? $input['password'] : '';

        if (empty($username) || empty($password)) {
            api_error('Username/email and password are required.', 400);
        }

        try {
            $user = $this->Auth_model->get_user_by_email_or_username($username);
        } catch (\Exception $e) {
            api_error('Database error: ' . $e->getMessage(), 500);
        }

        if (!$user) {
            api_error('Invalid credentials.', 401);
        }

        if ($user['account_status'] !== 'active') {
            if ($user['account_status'] === 'pending') {
                api_error('Your account is awaiting administrator approval.', 403);
            }
            api_error('Your account is not active. Please contact an administrator.', 403);
        }

        if (!password_verify($password, $user['password_hash'])) {
            api_error('Invalid credentials.', 401);
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

            $data = [
                'role_id'         => $residentRole['role_id'],
                'barangay_id'     => $barangay_id,
                'full_name'       => $full_name,
                'email'           => $email,
                'username'        => $username,
                'password_hash'   => password_hash($password, PASSWORD_DEFAULT),
                'address_details' => $address_details !== '' ? $address_details : null,
                'account_status'  => 'pending',
                'email_verified_at' => null
            ];

            $created = $this->Auth_model->create_resident($data);

            if (!$created) {
                api_error('Unable to create your account. Please try again.', 500);
                return;
            }

            api_response([
                'account_status' => 'pending'
            ], 201, 'Registration successful. Your account is awaiting approval.');

        } catch (\Exception $e) {
            api_error('Registration failed due to a server or database error.', 500);
        }
    }
}
