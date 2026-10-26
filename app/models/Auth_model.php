<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Auth_model extends Model {

    protected $table = 'users';
    protected $primary_key = 'user_id';

    public function get_user_by_email_or_username($login) {
        return $this->db->table($this->table)
                        ->join('roles', 'roles.role_id = users.role_id')
                        ->select('users.user_id, users.role_id, users.full_name, users.password_hash, users.account_status, roles.role_name')
                        ->where('users.email', $login)
                        ->or_where('users.username', $login)
                        ->get();
    }

    public function get_user_by_email($email) {
        return $this->db->table($this->table)
                        ->where('email', $email)
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