<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'models/Base_model.php';

class User_model extends Base_model
{
    public function userByUsername($username)
    {
        return $this->db->table('users')
            ->select('user_id, full_name, username, email, password, role, status')
            ->where('username', $username)
            ->limit(1)
            ->get() ?: null;
    }

    public function userByEmail($email)
    {
        return $this->db->table('users')
            ->select('user_id')
            ->where('email', $email)
            ->limit(1)
            ->get() ?: null;
    }

    public function apiUserById($userId)
    {
        return $this->db->table('users')
            ->select('user_id, full_name, username, role, status')
            ->where('user_id', (int) $userId)
            ->limit(1)
            ->get() ?: null;
    }

    public function userCount()
    {
        return (int) $this->db->table('users')->count();
    }

    public function createInitialAdmin($name, $username, $password)
    {
        return $this->execute(
            'INSERT INTO users (full_name, username, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$name, $username, password_hash($password, PASSWORD_DEFAULT), 'administrator', 'active', date('Y-m-d H:i:s')]
        );
    }

    public function createUser($name, $username, $password, $role, $email = null)
    {
        $this->execute(
            'INSERT INTO users (full_name, username, email, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$name, $username, $email, password_hash($password, PASSWORD_DEFAULT), $role, 'active', date('Y-m-d H:i:s')]
        );
        return (int) $this->db->last_id();
    }

    public function registerTrainee(array $user, array $trainee)
    {
        if (!$this->db->transaction()) {
            throw new RuntimeException('Unable to start the account registration transaction.');
        }

        try {
            $userId = $this->createUser(
                $user['full_name'],
                $user['username'],
                $user['password'],
                $user['role'],
                $user['email'] ?? null
            );
            if ($userId < 1) {
                throw new RuntimeException('Account registration did not return a valid user ID.');
            }

            $this->createTraineeForUser($userId, $trainee);
            if (!$this->db->commit()) {
                throw new RuntimeException('Unable to complete the account registration transaction.');
            }
            return $userId;
        } catch (Throwable $error) {
            try {
                $this->db->roll_back();
            } catch (Throwable $rollbackError) {
                error_log('Account registration rollback failed (' . get_class($rollbackError) . ').');
            }
            throw $error;
        }
    }

    public function createTraineeForUser($userId, array $data)
    {
        $this->db->table('trainees')->insert([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'sex' => $data['sex'],
            'date_of_birth' => $data['date_of_birth'] ?: null,
            'address' => $data['address'] ?: null,
            'email' => $data['email'] ?: null,
            'contact_number' => $data['contact_number'],
            'course' => $data['course'],
            'program_id' => $data['program_id'] ?: null,
            'status' => 'active',
            'user_id' => (int) $userId,
            'qr_code' => 'LSI-' . strtoupper(bin2hex(random_bytes(6))),
        ]);
    }

    public function traineeForUser($userId)
    {
        return $this->db->table('trainees')
            ->where('user_id', (int) $userId)
            ->limit(1)
            ->get() ?: null;
    }

    public function updateFullName($userId, $name)
    {
        return $this->db->table('users')
            ->where('user_id', (int) $userId)
            ->update(['full_name' => $name]);
    }

    public function updateTraineeProfile($traineeId, $firstName, $lastName, $contactNumber)
    {
        return $this->db->table('trainees')
            ->where('trainee_id', (int) $traineeId)
            ->update([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'contact_number' => $contactNumber,
            ]);
    }

    public function passwordForUser($userId)
    {
        return $this->db->table('users')
            ->select('password')
            ->where('user_id', (int) $userId)
            ->get() ?: null;
    }

    public function updatePassword($userId, $passwordHash)
    {
        return $this->db->table('users')
            ->where('user_id', (int) $userId)
            ->update(['password' => $passwordHash]);
    }
}
