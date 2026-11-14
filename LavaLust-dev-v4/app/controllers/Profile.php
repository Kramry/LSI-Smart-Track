<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'controllers/Base.php';

class Profile extends Base
{
    public function profile()
    {
        $this->requireRequestMethod('GET');
        if (!$this->requireUser()) {
            return;
        }
        $trainee = $this->user['role'] === 'trainee'
            ? $this->userModel->traineeForUser((int) $this->user['user_id'])
            : null;
        $this->render('profile', ['trainee' => $trainee]);
    }

    public function saveProfile()
    {
        $this->requireRequestMethod('POST');
        if (!$this->requireUser() || !$this->validCsrf()) {
            return $this->deny('Your session expired. Sign in and try again.');
        }
        $trainee = $this->user['role'] === 'trainee'
            ? $this->userModel->traineeForUser((int) $this->user['user_id'])
            : null;
        $firstName = trim((string) $this->input('first_name'));
        $lastName = trim((string) $this->input('last_name'));
        $name = $trainee ? trim($firstName . ' ' . $lastName) : trim((string) $this->input('full_name'));
        if ($name === '' || mb_strlen($name) > 150) {
            if ($this->apiRequest) {
                return $this->jsonResponse(['message' => 'Enter a valid name.'], 422);
            }
            return $this->render('profile', ['trainee' => $trainee, 'error' => 'Enter a valid name.']);
        }
        $contact = '';
        if ($trainee) {
            $contact = trim((string) $this->input('contact_number'));
            if ($firstName === '' || $lastName === '' || mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
                if ($this->apiRequest) {
                    return $this->jsonResponse(['message' => 'Enter valid first and last names, each up to 100 characters.'], 422);
                }
                return $this->render('profile', ['trainee' => $trainee, 'error' => 'Enter valid first and last names, each up to 100 characters.']);
            }
            if (!$this->isValidContactNumber($contact)) {
                if ($this->apiRequest) {
                    return $this->jsonResponse(['message' => 'Enter a valid contact number.'], 422);
                }
                return $this->render('profile', ['trainee' => $trainee, 'error' => 'Enter a valid contact number.']);
            }
        }
        $this->userModel->updateFullName((int) $this->user['user_id'], $name);
        if ($trainee) {
            $this->userModel->updateTraineeProfile((int) $trainee['trainee_id'], $firstName, $lastName, $contact);
        }
        $this->user['full_name'] = $name;
        $this->session->set_userdata('user', $this->user);
        $this->model->logActivity((int) $this->user['user_id'], 'Updated profile', 'Name changed');
        if ($this->apiRequest) {
            return $this->jsonResponse(['message' => 'Profile updated.', 'user' => $this->user]);
        }
        $this->notice('Profile updated.');
        redirect($this->url('profile'));
    }

    public function savePassword()
    {
        $this->requireRequestMethod('POST');
        if (!$this->requireUser() || !$this->validCsrf()) {
            return $this->deny('Your session expired. Sign in and try again.');
        }
        $account = $this->userModel->passwordForUser((int) $this->user['user_id']);
        $current = (string) $this->input('current_password');
        $newPassword = (string) $this->input('new_password');
        if (!$account || !password_verify($current, $account['password']) || mb_strlen($newPassword) < 10) {
            if ($this->apiRequest) {
                return $this->jsonResponse(['message' => 'Current password is incorrect or the new password is shorter than 10 characters.'], 422);
            }
            return $this->render('profile', ['error' => 'Current password is incorrect or the new password is shorter than 10 characters.']);
        }
        if ($newPassword === $current) {
            if ($this->apiRequest) {
                return $this->jsonResponse(['message' => 'The new password must be different from your current password.'], 422);
            }
            return $this->render('profile', ['error' => 'The new password must be different from your current password.']);
        }
        $this->userModel->updatePassword(
            (int) $this->user['user_id'],
            password_hash($newPassword, PASSWORD_DEFAULT)
        );
        $this->model->logActivity((int) $this->user['user_id'], 'Changed password', 'Password updated');
        if ($this->apiRequest) {
            return $this->jsonResponse(['message' => 'Password updated.']);
        }
        $this->notice('Password updated.');
        redirect($this->url('profile'));
    }

    private function isValidContactNumber(string $contact): bool
    {
        return preg_match('/^[0-9\s\-\+\(\)]{7,20}$/', $contact) === 1;
    }
}

