<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Auth_model extends Model {

    protected $table = 'users';
    protected $primary_key = 'user_id';

    
    public function get_user_by_email_or_username($login)
    {
        $result = $this->db->raw(
            'SELECT users.user_id, users.role_id, users.full_name, users.password_hash, ' .
            'users.account_status, users.email_verified_at, roles.role_name ' .
            'FROM users INNER JOIN roles ON roles.role_id = users.role_id ' .
            'WHERE LOWER(users.email) = LOWER(?) OR LOWER(users.username) = LOWER(?) LIMIT 1',
            [$login, $login]
        )->fetch(\PDO::FETCH_ASSOC);

        return is_array($result) && !empty($result)
            ? $result
            : null;
    }


    public function verify_password($password, $storedHash) {
        if (!is_string($password) || $password === ''
            || !is_string($storedHash) || $storedHash === '') {
            return false;
        }

        return password_verify($password, $storedHash);
    }

    public function get_user_by_email($email) {
        return $this->db->table($this->table)
                        ->join('roles', 'roles.role_id = users.role_id')
                        ->select('users.*, roles.role_name')
                        ->where('users.email', $email)
                        ->get();
    }

    public function get_user_by_username($username) {
        return $this->db->table($this->table)
                        ->where('username', $username)
                        ->get();
    }

    public function get_resident_role() {
        $role = $this->db->table('roles')
                         ->where('role_name', 'resident')
                         ->get();

        if (!$role) {
            $role = $this->db->table('roles')
                             ->where('role_name', 'user')
                             ->get();
        }

        return $role;
    }

    public function create_resident($data) {
        return $this->db->table($this->table)->insert($data);
    }

    
    public function get_active_barangay($barangay_id) {
        return $this->db->table('barangays')
                        ->where('barangay_id', $barangay_id)
                        ->where('is_active', 1)
                        ->get();
    }

    public function get_active_barangays() {
        return $this->db->table('barangays')
                        ->select('barangay_id, barangay_name')
                        ->where('is_active', 1)
                        ->get_all();
    }

}