<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Auth extends Controller {

    public function __construct() {
        parent::__construct();
        $this->call->model('Auth_model');
        $this->call->library('session');
    }

    public function login() {
        if ($this->session->userdata('logged_in')
            && strtolower((string) $this->session->userdata('role_name')) === 'admin') {
            redirect('admin');
        }
        $this->call->view('auth/login', [
            'error' => $this->session->flashdata('error')
        ]);
    }

    public function authenticate() {
        $post = $this->request->post();
        $username = isset($post['username']) && is_string($post['username']) ? trim($post['username']) : '';
        $password = isset($post['password']) && is_string($post['password']) ? $post['password'] : '';

        try {
            $user = $this->Auth_model->get_user_by_email_or_username($username);
        } catch (\PDOException $e) {
            error_log('Admin authentication lookup failed: ' . $e->getMessage());
            $this->session->set_flashdata('error', 'Sign-in is temporarily unavailable. Please try again.');
            redirect('auth/login');
            return;
        }

        if ($user
            && strtolower((string) $user['role_name']) === 'admin'
            && $user['account_status'] === 'active'
            && $this->Auth_model->verify_password($password, $user['password_hash'] ?? null)) {
            $this->session->regenerate_on_login(true);
            $session_data = [
                'user_id'   => $user['user_id'],
                'full_name' => $user['full_name'],
                'role_id'   => $user['role_id'],
                'role_name' => $user['role_name'],
                'logged_in' => TRUE
            ];
            $this->session->set_userdata($session_data);
            redirect('admin');
        }

        $this->session->set_flashdata('error', 'Invalid admin credentials or inactive account.');
        redirect('auth/login');
    }

    public function logout() {
        $this->session->sess_destroy();
        redirect('auth/login');
    }
}