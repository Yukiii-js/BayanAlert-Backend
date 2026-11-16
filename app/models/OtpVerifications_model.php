<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class OtpVerifications_model extends Model
{
    protected $table = 'otp_verifications';
    protected $primary_key = 'otp_id';
    protected $fillable = [];
    protected $guarded = [];
    protected $timestamps = false;

    public function registrationCooldownSeconds($email)
    {
        $latest = $this->db->table($this->table)
            ->where('email', $email)
            ->where('verification_type', 'registration')
            ->order_by('created_at', 'DESC')
            ->limit(1)
            ->get();
        if (!$latest || empty($latest['created_at'])) {
            return 0;
        }

        $createdAt = strtotime((string) $latest['created_at'] . ' UTC');
        return $createdAt === false
            ? 0
            : max(0, 60 - (time() - $createdAt));
    }

    public function createRegistrationCode($email, $codeHash, $expiresAt)
    {
        $this->db->table($this->table)
            ->where('email', $email)
            ->where('verification_type', 'registration')
            ->update(['used_at' => gmdate('Y-m-d H:i:s')]);

        $table = $this->db->table($this->table);
        return $table->insert([
            'user_id' => null,
            'email' => $email,
            'code_hash' => $codeHash,
            'verification_type' => 'registration',
            'expires_at' => $expiresAt,
            'used_at' => null,
            'attempt_count' => 0,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function getActiveRegistrationCode($email)
    {
        $record = $this->db->table($this->table)
            ->where('email', $email)
            ->where('verification_type', 'registration')
            ->order_by('created_at', 'DESC')
            ->limit(1)
            ->get();

        if (!$record || !empty($record['used_at']) || (int) ($record['attempt_count'] ?? 0) >= 5
            || empty($record['expires_at'])
            || strtotime((string) $record['expires_at'] . ' UTC') <= time()) {
            return null;
        }

        return $record;
    }

    public function recordRegistrationCodeFailure($otpId)
    {
        return $this->db->raw(
            'UPDATE `otp_verifications` SET `used_at` = IF(`attempt_count` >= 4, UTC_TIMESTAMP(), `used_at`), ' .
            '`attempt_count` = `attempt_count` + 1 ' .
            'WHERE `otp_id` = ? AND `verification_type` = ? AND `used_at` IS NULL',
            [(int) $otpId, 'registration']
        );
    }

    public function consumeRegistrationCode($otpId)
    {
        $result = $this->db->raw(
            'UPDATE `otp_verifications` SET `used_at` = UTC_TIMESTAMP() ' .
            'WHERE `otp_id` = ? AND `verification_type` = ? ' .
            'AND `used_at` IS NULL AND `expires_at` > UTC_TIMESTAMP()',
            [(int) $otpId, 'registration']
        );
        return $result->rowCount() === 1;
    }

    public function deleteRegistrationCode($otpId)
    {
        return $this->db->table($this->table)
            ->where('otp_id', $otpId)
            ->where('verification_type', 'registration')
            ->delete();
    }

    public function info()
    {
        return [
        'label' => 'OTP verifications',
        'group' => 'System data',
        'description' => 'View verification records. Verification codes remain private.',
        'primary_key' => 'otp_id',
        'read_only' => true,
        ];
    }

    public function references()
    {
        return [
            'user_id' => ['users', 'full_name']
        ];
    }

    public function get_all_records($search = '', $page = 1, $pageSize = 25)
    {
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $pageSize;
        $query = $this->db->table($this->table);
        $countQuery = $this->db->table($this->table);

        if ($search !== '') {
            $column = $this->primary_key;
            foreach ($this->get_columns() as $candidate) {
                if (strpos($candidate, 'name') !== false || strpos($candidate, 'title') !== false || strpos($candidate, 'email') !== false || strpos($candidate, 'code') !== false) {
                    $column = $candidate;
                    break;
                }
            }
            $query->like($column, '%' . $search . '%');
            $countQuery->like($column, '%' . $search . '%');
        }

        return [
            'columns' => $this->get_columns(),
            'rows' => $query->order_by($this->primary_key, 'DESC')->limit($pageSize, $offset)->get_all(),
            'total' => (int) $countQuery->count(),
            'page' => $page,
            'page_size' => $pageSize
        ];
    }

    public function get_columns()
    {
        $columns = $this->db->raw('SHOW COLUMNS FROM `' . $this->table . '`')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function ($column) {
            return $column['Field'];
        }, $columns);
    }
}
