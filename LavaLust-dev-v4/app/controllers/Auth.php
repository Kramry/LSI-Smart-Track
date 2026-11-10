<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'controllers/Base.php';

class Auth extends Base
{
    public function index()
    {
        if (!$this->user) {
            $this->render('login');
            return;
        }

        redirect($this->url('dashboard'));
    }

    public function showLogin()
    {
        if ($this->user) {
            redirect($this->url('dashboard'));
            return;
        }

        $this->render('login');
    }

    public function login()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$this->validCsrf()) {
            $this->render('login', ['error' => 'Your session expired. Please try again.']);
            return;
        }

        $username = trim((string) $this->input('username'));
        $password = (string) $this->input('password');
        $account = $username !== '' ? $this->userModel->userByUsername($username) : null;

        if (!$account || $account['status'] !== 'active' || !password_verify($password, $account['password'])) {
            $this->render('login', ['error' => 'The username or password is incorrect.']);
            return;
        }

        session_regenerate_id(true);
        $this->session->set_userdata('user', [
            'user_id' => (int) $account['user_id'],
            'full_name' => $account['full_name'],
            'username' => $account['username'],
            'role' => $account['role'],
        ]);
        $this->session->set_userdata('csrf', bin2hex(random_bytes(32)));
        redirect($this->url('dashboard'));
    }

    public function setup()
    {
        if ($this->userModel->userCount() > 0) {
            $this->render('login', ['error' => 'Initial setup is already complete. Sign in to continue.']);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->render('setup');
            return;
        }
        if (!$this->validCsrf()) {
            $this->render('setup', ['error' => 'Your session expired. Please try again.']);
            return;
        }

        $name = trim((string) $this->input('full_name'));
        $username = trim((string) $this->input('username'));
        $password = (string) $this->input('password');
        if ($name === '' || !preg_match('/^[A-Za-z0-9_.-]{4,50}$/', $username) || strlen($password) < 10) {
            $this->render('setup', ['error' => 'Enter a name, a username of 4-50 valid characters, and a password with at least 10 characters.']);
            return;
        }

        $this->userModel->createInitialAdmin($name, $username, $password);
        redirect($this->url(''));
    }

    public function register()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->render('register');
            return;
        }
        if (!$this->validCsrf()) {
            $this->render('register', ['error' => 'Your session expired. Please try again.']);
            return;
        }

        $name = trim((string) $this->input('full_name'));
        $username = trim((string) $this->input('username'));
        $email = strtolower(trim((string) $this->input('email')));
        $sex = trim((string) $this->input('sex'));
        $dateOfBirth = trim((string) $this->input('date_of_birth'));
        $address = trim((string) $this->input('address'));
        $contactNumber = trim((string) $this->input('contact_number'));
        $course = trim((string) $this->input('course'));
        $programId = trim((string) $this->input('program_id'));
        $password = (string) $this->input('password');
        $passwordConfirmation = (string) $this->input('password_confirmation');

        if ($name === '' || !preg_match('/^[A-Za-z0-9_.-]{4,50}$/', $username) || strlen($password) < 10) {
            $this->render('register', ['error' => 'Enter a name, a username of 4-50 valid characters, and a password with at least 10 characters.']);
            return;
        }

        if ($password !== $passwordConfirmation) {
            $this->render('register', ['error' => 'Passwords do not match.']);
            return;
        }

        if ($this->userModel->userByUsername($username)) {
            $this->render('register', ['error' => 'That username is already taken.']);
            return;
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->render('register', ['error' => 'Enter a valid email address.']);
            return;
        }

        $this->userModel->createUser($name, $username, $password, 'trainee', $email !== '' ? $email : null);
        $account = $this->userModel->userByUsername($username);

        $this->userModel->createTraineeForUser((int) $account['user_id'], [
            'first_name' => $name,
            'last_name' => '',
            'sex' => $sex,
            'date_of_birth' => $dateOfBirth !== '' ? $dateOfBirth : null,
            'address' => $address !== '' ? $address : null,
            'email' => $email !== '' ? $email : null,
            'contact_number' => $contactNumber !== '' ? $contactNumber : null,
            'course' => $course !== '' ? $course : null,
            'program_id' => $programId !== '' ? (int) $programId : null,
        ]);

        $this->notice('Account created successfully. Please sign in.');
        redirect($this->url(''));
    }

    public function logout()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $this->validCsrf()) {
            $this->session->unset_userdata(['smarttrack_user', 'smarttrack_csrf']);
            $this->session->sess_destroy();
        }
        redirect($this->url(''));
    }
}
