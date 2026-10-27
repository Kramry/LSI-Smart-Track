<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'controllers/Base.php';

class Profile extends Base
{
    public function profile()
    {
        if (!$this->requireUser()) {
            return;
        }
        $trainee = $this->user['role'] === 'trainee' ? $this->userModel->traineeForUser($this->user['user_id']) : null;
        $this->render('profile', ['trainee' => $trainee]);
    }

    public function saveProfile()
    {
        if (!$this->requireUser() || !$this->validCsrf()) {
            return $this->deny('Your session expired. Sign in and try again.');
        }
        $trainee = $this->user['role'] === 'trainee' ? $this->userModel->traineeForUser($this->user['user_id']) : null;
        $firstName = trim((string) $this->input('first_name'));
        $lastName = trim((string) $this->input('last_name'));
        $name = $trainee ? trim($firstName . ' ' . $lastName) : trim((string) $this->input('full_name'));
        if ($name === '' || strlen($name) > 150) {
            if ($this->apiRequest) {
                $this->jsonResponse(['message' => 'Enter a valid name.'], 422);
            }
            return $this->render('profile', ['trainee' => $trainee, 'error' => 'Enter a valid name.']);
        }
        $contact = '';
        if ($trainee) {
            $contact = trim((string) $this->input('contact_number'));
            if ($firstName === '' || $lastName === '' || strlen($contact) > 20) {
                if ($this->apiRequest) {
                    $this->jsonResponse(['message' => 'Enter a first name, last name, and valid contact number.'], 422);
                }
                return $this->render('profile', ['trainee' => $trainee, 'error' => 'Enter a first name, last name, and valid contact number.']);
            }
        }
        $this->model->execute('UPDATE users SET full_name = ? WHERE user_id = ?', [$name, (int) $this->user['user_id']]);
        if ($trainee) {
            $this->model->execute('UPDATE trainees SET first_name = ?, last_name = ?, contact_number = ? WHERE trainee_id = ?', [$firstName, $lastName, $contact, (int) $trainee['trainee_id']]);
        }
        $this->user['full_name'] = $name;
        $this->session->set_userdata('smarttrack_user', $this->user);
        $this->model->logActivity((int) $this->user['user_id'], 'Updated profile', 'Name changed');
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => 'Profile updated.', 'user' => $this->user]);
        }
        $this->notice('Profile updated.');
        redirect($this->url('profile'));
    }

    public function savePassword()
    {
        if (!$this->requireUser() || !$this->validCsrf()) {
            return $this->deny('Your session expired. Sign in and try again.');
        }
        $account = $this->model->row('SELECT password FROM users WHERE user_id = ?', [(int) $this->user['user_id']]);
        $current = (string) $this->input('current_password');
        $newPassword = (string) $this->input('new_password');
        if (!$account || !password_verify($current, $account['password']) || strlen($newPassword) < 10) {
            if ($this->apiRequest) {
                $this->jsonResponse(['message' => 'Current password is incorrect or the new password is shorter than 10 characters.'], 422);
            }
            return $this->render('profile', ['error' => 'Current password is incorrect or the new password is shorter than 10 characters.']);
        }
        $this->model->execute('UPDATE users SET password = ? WHERE user_id = ?', [password_hash($newPassword, PASSWORD_DEFAULT), (int) $this->user['user_id']]);
        $this->model->logActivity((int) $this->user['user_id'], 'Changed password', 'Password updated');
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => 'Password updated.']);
        }
        $this->notice('Password updated.');
        redirect($this->url('profile'));
    }
}
