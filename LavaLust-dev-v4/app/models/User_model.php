<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'models/Base_model.php';

class User_model extends Base_model
{
    public function userByUsername($username)
    {
        return $this->row(
            'SELECT user_id, full_name, username, password, role, status FROM users WHERE username = ? LIMIT 1',
            [$username]
        );
    }

    public function userCount()
    {
        $record = $this->row('SELECT COUNT(*) AS total FROM users');
        return (int) ($record['total'] ?? 0);
    }

    public function createInitialAdmin($name, $username, $password)
    {
        $this->execute(
            'INSERT INTO users (full_name, username, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$name, $username, password_hash($password, PASSWORD_DEFAULT), 'administrator', 'active']
        );
    }

    public function createUser($name, $username, $password, $role)
    {
        $this->execute(
            'INSERT INTO users (full_name, username, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$name, $username, password_hash($password, PASSWORD_DEFAULT), $role, 'active']
        );
    }

    public function traineeForUser($userId)
    {
        return $this->row('SELECT * FROM trainees WHERE user_id = ? LIMIT 1', [(int) $userId]);
    }
}
