<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'models/Base_model.php';

class User_model extends Base_model
{
    public function userByUsername($username)
    {
        return $this->row(
            'SELECT user_id, full_name, username, email, password, role, status FROM users WHERE username = ? LIMIT 1',
            [$username]
        );
    }

    public function userByEmail($email)
    {
        return $this->row(
            'SELECT user_id FROM users WHERE email = ? LIMIT 1',
            [$email]
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

    public function createUser($name, $username, $password, $role, $email = null)
    {
        $this->execute(
            'INSERT INTO users (full_name, username, email, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$name, $username, $email, password_hash($password, PASSWORD_DEFAULT), $role, 'active']
        );
    }

    public function createTraineeForUser($userId, array $data)
    {
        $this->execute(
            'INSERT INTO trainees (first_name, last_name, sex, date_of_birth, address, email, contact_number, course, program_id, status, user_id, qr_code)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['first_name'],
                $data['last_name'],
                $data['sex'],
                $data['date_of_birth'] ?: null,
                $data['address'] ?: null,
                $data['email'] ?: null,
                $data['contact_number'],
                $data['course'],
                $data['program_id'] ?: null,
                'active',
                (int) $userId,
                'LSI-' . strtoupper(bin2hex(random_bytes(6))),
            ]
        );
    }

    public function traineeForUser($userId)
    {
        return $this->row('SELECT * FROM trainees WHERE user_id = ? LIMIT 1', [(int) $userId]);
    }
}
