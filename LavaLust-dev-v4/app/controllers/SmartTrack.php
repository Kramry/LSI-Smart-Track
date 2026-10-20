<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SmartTrack extends Controller
{
    private $model;
    private $user;

    public function before_action()
    {
        require_once APP_DIR . 'models/SmartTrack_model.php';
        $this->call->helper('url');
        $this->db = $this->call->database();
        $this->session = $this->call->library('session');
        $this->model = new SmartTrack_model($this->db);
        $this->user = $this->session->userdata('smarttrack_user');

        if (!$this->session->userdata('smarttrack_csrf')) {
            $this->session->set_userdata('smarttrack_csrf', bin2hex(random_bytes(32)));
        }
    }

    public function index()
    {
        if (!$this->user) {
            $this->render('login');
            return;
        }

        $this->dashboard();
    }

    public function login()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$this->validCsrf()) {
            $this->render('login', ['error' => 'Your session expired. Please try again.']);
            return;
        }

        $username = trim((string) $this->input('username'));
        $password = (string) $this->input('password');
        $account = $username !== '' ? $this->model->userByUsername($username) : null;

        if (!$account || $account['status'] !== 'active' || !password_verify($password, $account['password'])) {
            $this->render('login', ['error' => 'The username or password is incorrect.']);
            return;
        }

        session_regenerate_id(true);
        $this->session->set_userdata('smarttrack_user', [
            'user_id' => (int) $account['user_id'],
            'full_name' => $account['full_name'],
            'username' => $account['username'],
            'role' => $account['role'],
        ]);
        $this->session->set_userdata('smarttrack_csrf', bin2hex(random_bytes(32)));
        redirect($this->url('dashboard'));
    }

    public function setup()
    {
        if ($this->model->userCount() > 0) {
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

        $this->model->createInitialAdmin($name, $username, $password);
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
        $password = (string) $this->input('password');
        $role = trim((string) $this->input('role'));

        if ($name === '' || !preg_match('/^[A-Za-z0-9_.-]{4,50}$/', $username) || strlen($password) < 10) {
            $this->render('register', ['error' => 'Enter a name, a username of 4-50 valid characters, and a password with at least 10 characters.']);
            return;
        }

        if (!in_array($role, ['trainee', 'instructor', 'staff'], true)) {
            $this->render('register', ['error' => 'Select a valid role.']);
            return;
        }

        $existing = $this->model->userByUsername($username);
        if ($existing) {
            $this->render('register', ['error' => 'That username is already taken.']);
            return;
        }

        $this->model->createUser($name, $username, $password, $role);
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

    public function dashboard()
    {
        if (!$this->requireUser()) {
            return;
        }

        $this->render('dashboard', [
            'stats' => $this->model->dashboardStats($this->user),
            'attendance' => $this->model->recentAttendance($this->user),
            'announcements' => $this->model->announcements(),
        ]);
    }

    public function module($section)
    {
        if (!$this->requireUser()) {
            return;
        }
        if (!$this->canAccess($section)) {
            return $this->deny('You do not have access to that section.');
        }

        $definition = $this->resourceDefinition($section);
        if (!$definition) {
            show_404();
            return;
        }

        $fields = $this->fieldsFor($section);
        foreach ($fields as &$field) {
            if (!empty($field['options'])) {
                $field['choices'] = $this->model->options($field['options'], $this->user);
            }
        }
        unset($field);

        $records = $this->model->records($section, $this->user);
        $editId = (int) ($_GET['edit'] ?? $this->session->flashdata('smarttrack_edit') ?? 0);
        $editing = $editId ? $this->model->findRecord($definition['table'], $definition['key'], $editId) : null;
        if ($editing && !$this->recordIsVisible($section, $editing, $records, $definition['key'])) {
            $editing = null;
        }

        $this->render('module', [
            'section' => $section,
            'title' => $definition['title'],
            'primary_key' => $definition['key'],
            'can_manage' => $this->canManage($section),
            'can_create' => $this->canManage($section) && !in_array($section, ['activity', 'documents'], true) && !($section === 'inquiries' && $this->user['role'] !== 'trainee'),
            'fields' => $fields,
            'records' => $records,
            'editing' => $editing,
            'columns' => $definition['columns'],
            'options' => [
                'trainees' => $this->model->options('trainees', $this->user),
                'classes' => $this->model->options('classes', $this->user),
                'schedules' => $this->model->options('schedules', $this->user),
            ],
            'summary' => $section === 'attendance' && $this->user['role'] === 'trainee'
                ? $this->model->attendanceSummary((int) ($this->model->traineeForUser($this->user['user_id'])['trainee_id'] ?? 0))
                : [],
            'followups' => $section === 'attendance' && $this->user['role'] !== 'trainee'
                ? $this->model->absenceFollowUps($this->user)
                : [],
        ]);
    }

    public function save($section)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !$this->canAccess($section) || !$this->canManage($section)) {
            return $this->deny('You are not allowed to change this section.');
        }
        $definition = $this->resourceDefinition($section);
        if (!$definition) {
            show_404();
            return;
        }

        $recordId = (int) $this->input($definition['key']);
        if ($recordId) {
            if ($section === 'users' && $recordId === (int) $this->user['user_id']) {
                return $this->deny('Use My Profile to change your own account details.');
            }
            $existing = $this->model->findRecord($definition['table'], $definition['key'], $recordId);
            if (!$existing || !$this->recordIsVisible($section, $existing, $this->model->records($section, $this->user), $definition['key'])) {
                return $this->deny('That record is not available to your account.');
            }
            if ($section === 'users' && $this->user['role'] !== 'administrator' && $existing['role'] === 'administrator') {
                return $this->deny('Only an administrator can change another administrator account.');
            }
        }
        $fields = $this->fieldsFor($section);
        $data = [];
        $errors = [];
        foreach ($fields as $field) {
            $name = $field['name'];
            $value = trim((string) $this->input($name));
            if (!empty($field['required']) && $value === '' && !($name === 'password' && $recordId > 0)) {
                $errors[] = $field['label'] . ' is required.';
                continue;
            }
            if ($value !== '' && ($field['type'] ?? '') === 'select' && !in_array($value, $field['values'], true)) {
                $errors[] = 'Choose a valid ' . strtolower($field['label']) . '.';
                continue;
            }
            if ($value !== '' && ($field['type'] ?? '') === 'options') {
                $validIds = array_map(static function ($option) { return (string) $option['id']; }, $this->model->options($field['options'], $this->user));
                if (!in_array($value, $validIds, true)) {
                    $errors[] = 'Choose a valid ' . strtolower($field['label']) . '.';
                    continue;
                }
            }
            if ($value !== '' && ($field['type'] ?? '') === 'date') {
                $date = DateTime::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) {
                    $errors[] = 'Enter a valid ' . strtolower($field['label']) . '.';
                    continue;
                }
            }
            if ($value !== '' && ($field['type'] ?? '') === 'time') {
                if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/', $value)) {
                    $errors[] = 'Enter a valid ' . strtolower($field['label']) . '.';
                    continue;
                }
                if (strlen($value) === 5) {
                    $value .= ':00';
                }
            }
            $maxLengths = ['full_name' => 150, 'username' => 50, 'first_name' => 100, 'last_name' => 100, 'sex' => 20, 'contact_number' => 20, 'course' => 100, 'program_code' => 30, 'program_name' => 150, 'class_name' => 150, 'title' => 150, 'subject' => 150, 'document_name' => 150];
            if (isset($maxLengths[$name]) && strlen($value) > $maxLengths[$name]) {
                $errors[] = $field['label'] . ' is too long.';
                continue;
            }
            if ($value !== '' || in_array($name, ['time_in', 'time_out', 'class_id', 'schedule_id', 'program_id', 'instructor_id', 'user_id', 'reply'], true)) {
                $data[$name] = $value === '' ? null : $value;
            }
        }

        if ($section === 'users' && isset($data['password']) && strlen((string) $data['password']) < 10) {
            $errors[] = 'Passwords must contain at least 10 characters.';
        }
        if ($section === 'users' && isset($data['username']) && !preg_match('/^[A-Za-z0-9_.-]{4,50}$/', (string) $data['username'])) {
            $errors[] = 'Usernames must be 4-50 letters, numbers, dots, underscores, or hyphens.';
        }
        if ($section === 'users' && !$recordId && empty($data['password'])) {
            $errors[] = 'A temporary password is required for a new account.';
        }
        if ($section === 'attendance' && !empty($data['class_id']) && !empty($data['trainee_id'])) {
            $enrollment = $this->model->row('SELECT enrollment_id FROM class_enrollments WHERE class_id = ? AND trainee_id = ?', [(int) $data['class_id'], (int) $data['trainee_id']]);
            if (!$enrollment) {
                $errors[] = 'The trainee must be enrolled in the selected class.';
            }
            if (!empty($data['schedule_id'])) {
                $schedule = $this->model->row('SELECT schedule_id FROM training_schedules WHERE schedule_id = ? AND class_id = ? AND schedule_date = ?', [(int) $data['schedule_id'], (int) $data['class_id'], $data['attendance_date']]);
                if (!$schedule) {
                    $errors[] = 'The session must belong to the selected class and attendance date.';
                }
            }
        }
        if ($section === 'attendance') {
            if (in_array($data['status'] ?? '', ['Present', 'Late'], true) && empty($data['time_in'])) {
                $errors[] = 'Present and late records require a time-in.';
            }
            if (!empty($data['time_out']) && empty($data['time_in'])) {
                $errors[] = 'A time-out requires a time-in.';
            }
            if (!empty($data['time_in']) && !empty($data['time_out']) && $data['time_out'] < $data['time_in']) {
                $errors[] = 'Time-out cannot be earlier than time-in.';
            }
        }
        if ($section === 'classes' && !empty($data['start_date']) && !empty($data['end_date']) && $data['end_date'] < $data['start_date']) {
            $errors[] = 'Class end date cannot be earlier than its start date.';
        }
        if ($section === 'schedules' && !empty($data['start_time']) && !empty($data['end_time']) && $data['end_time'] <= $data['start_time']) {
            $errors[] = 'Session end time must be later than its start time.';
        }
        if ($section === 'trainees' && !$recordId) {
            $data['qr_code'] = 'LSI-' . strtoupper(bin2hex(random_bytes(6)));
        }
        if ($section === 'users' && !$recordId) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        if ($section === 'attendance') {
            $data['recorded_by'] = (int) $this->user['user_id'];
            if (empty($data['remarks'])) {
                $data['remarks'] = 'Manual entry';
            }
        }
        if ($section === 'announcements') {
            $data['user_id'] = (int) $this->user['user_id'];
        }
        if ($section === 'inquiries') {
            if ($this->user['role'] === 'trainee') {
                $trainee = $this->model->traineeForUser($this->user['user_id']);
                if (!$trainee) {
                    $errors[] = 'Your trainee profile is not linked to this account.';
                } else {
                    $data['trainee_id'] = (int) $trainee['trainee_id'];
                    unset($data['reply'], $data['status']);
                    if (!$recordId) {
                        $data['status'] = 'open';
                    }
                }
            } elseif ($recordId) {
                $data = array_intersect_key($data, ['reply' => true, 'status' => true]);
            }
        }

        if ($errors) {
            return $this->moduleWithError($section, implode(' ', $errors), $recordId);
        }

        try {
            $savedId = $this->model->saveRecord($definition['table'], $definition['key'], $data, $recordId ?: null);
            $this->model->logActivity((int) $this->user['user_id'], ($recordId ? 'Updated ' : 'Created ') . $section, 'Record #' . $savedId);
            $this->notice(ucfirst(rtrim($definition['title'], 's')) . ' saved.');
            redirect($this->url('manage/' . $section));
        } catch (Throwable $error) {
            return $this->moduleWithError($section, 'The record could not be saved. Check for duplicate values and valid relationships.', $recordId);
        }
    }

    public function delete($section, $id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !$this->canAccess($section) || !$this->canManage($section)) {
            return $this->deny('You are not allowed to delete records in this section.');
        }
        $definition = $this->resourceDefinition($section);
        if (!$definition || !ctype_digit((string) $id)) {
            show_404();
            return;
        }
        $existing = $this->model->findRecord($definition['table'], $definition['key'], (int) $id);
        if (!$existing || !$this->recordIsVisible($section, $existing, $this->model->records($section, $this->user), $definition['key'])) {
            return $this->deny('That record is not available to your account.');
        }
        $deactivate = in_array($section, ['users', 'trainees'], true);
        if ($section === 'users' && (int) $id === (int) $this->user['user_id']) {
            return $this->deny('You cannot deactivate your own account.');
        }
        if ($section === 'users' && $this->user['role'] !== 'administrator' && $existing['role'] === 'administrator') {
            return $this->deny('Only an administrator can deactivate another administrator account.');
        }
        if ($section === 'documents') {
            $document = $this->model->documentById((int) $id);
            if ($document) {
                $path = $this->privateDocumentPath($document['file_path']);
                if ($path) {
                    unlink($path);
                }
            }
        }
        $this->model->deleteRecord($definition['table'], $definition['key'], (int) $id, $deactivate);
        $this->model->logActivity((int) $this->user['user_id'], 'Deleted ' . $section, 'Record #' . (int) $id . ($deactivate ? ' deactivated' : ' removed'));
        $this->notice($deactivate ? 'Record deactivated.' : 'Record deleted.');
        redirect($this->url('manage/' . $section));
    }

    public function profile()
    {
        if (!$this->requireUser()) {
            return;
        }
        $trainee = $this->user['role'] === 'trainee' ? $this->model->traineeForUser($this->user['user_id']) : null;
        $this->render('profile', ['trainee' => $trainee]);
    }

    public function saveProfile()
    {
        if (!$this->requireUser() || !$this->validCsrf()) {
            return $this->deny('Your session expired. Sign in and try again.');
        }
        $trainee = $this->user['role'] === 'trainee' ? $this->model->traineeForUser($this->user['user_id']) : null;
        $firstName = trim((string) $this->input('first_name'));
        $lastName = trim((string) $this->input('last_name'));
        $name = $trainee ? trim($firstName . ' ' . $lastName) : trim((string) $this->input('full_name'));
        if ($name === '' || strlen($name) > 150) {
            return $this->render('profile', ['trainee' => $trainee, 'error' => 'Enter a valid name.']);
        }
        $contact = '';
        if ($trainee) {
            $contact = trim((string) $this->input('contact_number'));
            if ($firstName === '' || $lastName === '' || strlen($contact) > 20) {
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
            return $this->render('profile', ['error' => 'Current password is incorrect or the new password is shorter than 10 characters.']);
        }
        $this->model->execute('UPDATE users SET password = ? WHERE user_id = ?', [password_hash($newPassword, PASSWORD_DEFAULT), (int) $this->user['user_id']]);
        $this->model->logActivity((int) $this->user['user_id'], 'Changed password', 'Password updated');
        $this->notice('Password updated.');
        redirect($this->url('profile'));
    }

    public function scanAttendance()
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff', 'instructor'], true)) {
            return $this->deny('You are not allowed to record attendance.');
        }
        $qrCode = trim((string) $this->input('qr_code'));
        $action = (string) $this->input('scan_action');
        $classId = (int) $this->input('class_id');
        $scheduleId = (int) $this->input('schedule_id');
        if ($qrCode === '' || !in_array($action, ['in', 'out'], true)) {
            $this->notice('Enter a QR ID and choose time-in or time-out.');
            redirect($this->url('manage/attendance'));
            return;
        }
        if (!$classId) {
            $this->notice('Choose a class before scanning attendance.');
            redirect($this->url('manage/attendance'));
            return;
        }
        $qrTrainee = $this->model->row('SELECT trainee_id FROM trainees WHERE qr_code = ? AND status = \'active\'', [$qrCode]);
        $enrolled = $qrTrainee ? $this->model->row('SELECT enrollment_id FROM class_enrollments WHERE class_id = ? AND trainee_id = ?', [$classId, (int) $qrTrainee['trainee_id']]) : null;
        if (!$enrolled) {
            $this->notice('That trainee is not enrolled in the selected class.');
            redirect($this->url('manage/attendance'));
            return;
        }
        if ($scheduleId && !$this->model->row('SELECT schedule_id FROM training_schedules WHERE schedule_id = ? AND class_id = ? AND schedule_date = CURDATE()', [$scheduleId, $classId])) {
            $this->notice('Choose a session scheduled for this class today.');
            redirect($this->url('manage/attendance'));
            return;
        }
        if ($this->user['role'] === 'instructor' && $classId) {
            $assigned = $this->model->row('SELECT class_id FROM training_classes WHERE class_id = ? AND instructor_id = ?', [$classId, (int) $this->user['user_id']]);
            if (!$assigned) {
                return $this->deny('That class is not assigned to your account.');
            }
        }
        $result = $this->model->scanAttendance($qrCode, $action, $classId, $scheduleId, (int) $this->user['user_id']);
        if (isset($result['success'])) {
            $this->model->logActivity((int) $this->user['user_id'], 'Recorded attendance', $result['success']);
        }
        $this->notice($result['success'] ?? $result['error']);
        redirect($this->url('manage/attendance'));
    }

    public function exportReport($period)
    {
        if (!$this->requireUser() || !in_array($period, ['daily', 'weekly', 'monthly'], true)) {
            return $this->deny('That report is not available.');
        }
        $today = new DateTimeImmutable('today');
        if ($period === 'weekly') {
            $start = $today->modify('monday this week');
            $end = $start->modify('+6 days');
        } elseif ($period === 'monthly') {
            $start = $today->modify('first day of this month');
            $end = $today->modify('last day of this month');
        } else {
            $start = $today;
            $end = $today;
        }
        $records = $this->model->attendanceReport($start->format('Y-m-d'), $end->format('Y-m-d'), $this->user);
        $filename = 'smarttrack-' . $period . '-report-' . $today->format('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Date', 'Trainee', 'Program', 'Class', 'Time in', 'Time out', 'Status', 'Remarks']);
        foreach ($records as $record) {
            fputcsv($output, [$record['attendance_date'], $record['first_name'] . ' ' . $record['last_name'], $record['program_name'], $record['class_name'], $record['time_in'], $record['time_out'], $record['status'], $record['remarks']]);
        }
        fclose($output);
        exit;
    }

    public function uploadDocument()
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff', 'instructor', 'trainee'], true)) {
            return $this->deny('You are not allowed to upload documents.');
        }
        $traineeId = (int) $this->input('trainee_id');
        $scope = $this->user['role'] === 'trainee' ? 'trainee' : $this->input('document_scope');
        if ($this->user['role'] === 'trainee') {
            $trainee = $this->model->traineeForUser($this->user['user_id']);
            $traineeId = (int) ($trainee['trainee_id'] ?? 0);
        }
        if ($scope === 'institution') {
            $traineeId = 0;
        }
        $trainee = $traineeId ? $this->model->row('SELECT trainee_id FROM trainees WHERE trainee_id = ?', [$traineeId]) : null;
        $file = $_FILES['document'] ?? null;
        if (($scope !== 'institution' && !$trainee) || !in_array($scope, ['trainee', 'institution'], true) || !$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 10 * 1024 * 1024) {
            $this->notice('Choose a valid document under 10 MB and a trainee record.');
            redirect($this->url('manage/documents'));
            return;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'];
        if (!isset($allowed[$mime])) {
            $this->notice('Only PDF, JPG, PNG, and DOCX files are accepted.');
            redirect($this->url('manage/documents'));
            return;
        }

        $directory = ROOT_DIR . 'runtime/uploads/private';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            return $this->deny('The private upload directory is unavailable.');
        }
        $filename = bin2hex(random_bytes(20)) . '.' . $allowed[$mime];
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            $this->notice('The document could not be stored.');
            redirect($this->url('manage/documents'));
            return;
        }
        $label = trim((string) $this->input('document_name')) ?: basename($file['name']);
        $access = $this->user['role'] === 'instructor' ? 'class' : (in_array($this->input('access_level'), ['private', 'class', 'staff'], true) ? $this->input('access_level') : 'private');
        $classId = (int) $this->input('class_id');
        if ($access === 'class') {
            $classSql = 'SELECT class_id FROM training_classes WHERE class_id = ?';
            $classValues = [$classId];
            if ($this->user['role'] === 'instructor') {
                $classSql .= ' AND instructor_id = ?';
                $classValues[] = (int) $this->user['user_id'];
            }
            if (!$classId || !$this->model->row($classSql, $classValues)) {
                unlink($destination);
                $this->notice('Select a class you are permitted to share this document with.');
                redirect($this->url('manage/documents'));
                return;
            }
        } else {
            $classId = null;
        }
        if ($this->user['role'] === 'trainee') {
            $access = 'private';
        }
        $this->model->execute('INSERT INTO trainee_documents (trainee_id, class_id, uploaded_by, document_name, file_path, upload_date, status, access_level) VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?)', [$traineeId ?: null, $classId, (int) $this->user['user_id'], substr($label, 0, 150), 'runtime/uploads/private/' . $filename, 'active', $access]);
        $this->model->logActivity((int) $this->user['user_id'], 'Uploaded document', substr($label, 0, 150));
        $this->notice('Document uploaded.');
        redirect($this->url('manage/documents'));
    }

    public function downloadDocument($id)
    {
        if (!$this->requireUser() || !ctype_digit((string) $id)) {
            return $this->deny('The document could not be found.');
        }
        $document = $this->model->documentById((int) $id);
        $isAdmin = in_array($this->user['role'], ['administrator', 'staff'], true);
        $isOwner = $document && $this->user['role'] === 'trainee' && $document['access_level'] !== 'staff' && (int) $document['trainee_user_id'] === (int) $this->user['user_id'];
        $isClassDocument = false;
        if ($document && in_array($this->user['role'], ['instructor', 'trainee'], true) && $document['access_level'] === 'class' && !empty($document['class_id'])) {
            if ($this->user['role'] === 'instructor') {
                $isClassDocument = (bool) $this->model->row('SELECT class_id FROM training_classes WHERE class_id = ? AND instructor_id = ?', [(int) $document['class_id'], (int) $this->user['user_id']]);
            } else {
                $isClassDocument = (bool) $this->model->row('SELECT e.enrollment_id FROM class_enrollments e JOIN trainees t ON t.trainee_id = e.trainee_id WHERE e.class_id = ? AND t.user_id = ?', [(int) $document['class_id'], (int) $this->user['user_id']]);
            }
        }
        $path = $document ? $this->privateDocumentPath($document['file_path']) : null;
        if (!$document || (!$isAdmin && !$isOwner && !$isClassDocument) || !$path) {
            http_response_code(404);
            exit('Document not found.');
        }
        header('Content-Type: ' . (mime_content_type($path) ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $document['document_name']) . '"');
        readfile($path);
        exit;
    }

    private function privateDocumentPath($relativePath)
    {
        $privateRoot = realpath(ROOT_DIR . 'runtime/uploads/private');
        $path = realpath(ROOT_DIR . ltrim((string) $relativePath, '/\\'));
        if (!$privateRoot || !$path || strpos($path, $privateRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
            return null;
        }
        return $path;
    }

    private function moduleWithError($section, $error, $recordId = 0)
    {
        $this->session->set_flashdata('smarttrack_error', $error);
        if ($recordId) {
            $this->session->set_flashdata('smarttrack_edit', $recordId);
        }
        redirect($this->url('manage/' . $section));
    }

    private function resourceDefinition($section)
    {
        $definitions = [
            'users' => ['title' => 'User accounts', 'table' => 'users', 'key' => 'user_id', 'columns' => ['full_name', 'username', 'role', 'status']],
            'trainees' => ['title' => 'Trainees', 'table' => 'trainees', 'key' => 'trainee_id', 'columns' => ['first_name', 'last_name', 'course', 'program_name', 'status', 'qr_code']],
            'programs' => ['title' => 'Training programs', 'table' => 'training_programs', 'key' => 'program_id', 'columns' => ['program_code', 'program_name', 'duration_hours', 'status']],
            'classes' => ['title' => 'Classes', 'table' => 'training_classes', 'key' => 'class_id', 'columns' => ['class_name', 'program_name', 'instructor_name', 'start_date', 'end_date', 'status']],
            'schedules' => ['title' => 'Schedules', 'table' => 'training_schedules', 'key' => 'schedule_id', 'columns' => ['class_name', 'schedule_date', 'start_time', 'end_time']],
            'enrollments' => ['title' => 'Class enrollments', 'table' => 'class_enrollments', 'key' => 'enrollment_id', 'columns' => ['class_name', 'trainee_name', 'enrolled_at']],
            'attendance' => ['title' => 'Attendance', 'table' => 'attendance_records', 'key' => 'attendance_id', 'columns' => ['trainee_name', 'class_name', 'attendance_date', 'time_in', 'time_out', 'status', 'remarks']],
            'announcements' => ['title' => 'Announcements', 'table' => 'announcements', 'key' => 'announcement_id', 'columns' => ['title', 'status', 'author', 'created_at']],
            'inquiries' => ['title' => 'Inquiries', 'table' => 'inquiries', 'key' => 'inquiry_id', 'columns' => ['trainee_name', 'subject', 'message', 'reply', 'status', 'created_at']],
            'activity' => ['title' => 'Activity log', 'table' => 'activity_logs', 'key' => 'log_id', 'columns' => ['user_name', 'action', 'details', 'created_at']],
        ];
        return $definitions[$section] ?? null;
    }

    private function fieldsFor($section)
    {
        $fields = [
            'users' => [
                ['name' => 'full_name', 'label' => 'Full name', 'required' => true],
                ['name' => 'username', 'label' => 'Username', 'required' => true],
                ['name' => 'role', 'label' => 'Role', 'type' => 'select', 'required' => true, 'values' => $this->user['role'] === 'administrator' ? ['trainee', 'instructor', 'staff', 'administrator'] : ['trainee', 'instructor', 'staff']],
                ['name' => 'status', 'label' => 'Account status', 'type' => 'select', 'required' => true, 'values' => ['active', 'inactive']],
                ['name' => 'password', 'label' => 'Temporary password (10+ characters)', 'type' => 'password', 'required' => false],
            ],
            'trainees' => [
                ['name' => 'first_name', 'label' => 'First name', 'required' => true],
                ['name' => 'last_name', 'label' => 'Last name', 'required' => true],
                ['name' => 'sex', 'label' => 'Sex', 'type' => 'select', 'required' => true, 'values' => ['Female', 'Male', 'Other']],
                ['name' => 'contact_number', 'label' => 'Contact number', 'required' => true],
                ['name' => 'course', 'label' => 'Course / qualification', 'required' => true],
                ['name' => 'program_id', 'label' => 'Training program', 'type' => 'options', 'options' => 'programs'],
                ['name' => 'user_id', 'label' => 'Trainee login account', 'type' => 'options', 'options' => 'user_accounts'],
                ['name' => 'status', 'label' => 'Trainee status', 'type' => 'select', 'required' => true, 'values' => ['active', 'inactive']],
            ],
            'programs' => [
                ['name' => 'program_code', 'label' => 'TESDA program code', 'required' => true],
                ['name' => 'program_name', 'label' => 'Program name', 'required' => true],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                ['name' => 'duration_hours', 'label' => 'Duration in hours', 'type' => 'number'],
                ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'values' => ['active', 'inactive']],
            ],
            'classes' => [
                ['name' => 'class_name', 'label' => 'Class name', 'required' => true],
                ['name' => 'program_id', 'label' => 'Program', 'type' => 'options', 'options' => 'programs', 'required' => true],
                ['name' => 'instructor_id', 'label' => 'Instructor', 'type' => 'options', 'options' => 'instructors'],
                ['name' => 'start_date', 'label' => 'Start date', 'type' => 'date'],
                ['name' => 'end_date', 'label' => 'End date', 'type' => 'date'],
                ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'values' => ['active', 'inactive']],
            ],
            'schedules' => [
                ['name' => 'class_id', 'label' => 'Class', 'type' => 'options', 'options' => 'classes', 'required' => true],
                ['name' => 'program_id', 'label' => 'Program (legacy link)', 'type' => 'options', 'options' => 'programs'],
                ['name' => 'schedule_date', 'label' => 'Date', 'type' => 'date', 'required' => true],
                ['name' => 'start_time', 'label' => 'Start time', 'type' => 'time', 'required' => true],
                ['name' => 'end_time', 'label' => 'End time', 'type' => 'time', 'required' => true],
            ],
            'enrollments' => [
                ['name' => 'class_id', 'label' => 'Class', 'type' => 'options', 'options' => 'classes', 'required' => true],
                ['name' => 'trainee_id', 'label' => 'Trainee', 'type' => 'options', 'options' => 'trainees', 'required' => true],
            ],
            'attendance' => [
                ['name' => 'trainee_id', 'label' => 'Trainee', 'type' => 'options', 'options' => 'trainees', 'required' => true],
                ['name' => 'class_id', 'label' => 'Class', 'type' => 'options', 'options' => 'classes'],
                ['name' => 'schedule_id', 'label' => 'Schedule', 'type' => 'options', 'options' => 'schedules'],
                ['name' => 'attendance_date', 'label' => 'Attendance date', 'type' => 'date', 'required' => true],
                ['name' => 'time_in', 'label' => 'Time in', 'type' => 'time'],
                ['name' => 'time_out', 'label' => 'Time out', 'type' => 'time'],
                ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'values' => ['Present', 'Late', 'Absent']],
                ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea'],
            ],
            'announcements' => [
                ['name' => 'title', 'label' => 'Title', 'required' => true],
                ['name' => 'content', 'label' => 'Announcement', 'type' => 'textarea', 'required' => true],
                ['name' => 'status', 'label' => 'Visibility', 'type' => 'select', 'required' => true, 'values' => ['published', 'draft']],
            ],
            'inquiries' => [
                ['name' => 'subject', 'label' => 'Subject', 'required' => true],
                ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true],
                ['name' => 'reply', 'label' => 'Reply', 'type' => 'textarea'],
                ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'values' => ['open', 'replied', 'closed']],
            ],
        ];
        if ($section === 'inquiries' && $this->user['role'] === 'trainee') {
            return array_slice($fields[$section], 0, 2);
        }
        if ($section === 'inquiries' && $this->user['role'] !== 'trainee') {
            return array_slice($fields[$section], 2);
        }
        return $fields[$section] ?? [];
    }

    private function canAccess($section)
    {
        $roles = [
            'administrator' => ['users', 'trainees', 'programs', 'classes', 'schedules', 'enrollments', 'attendance', 'documents', 'announcements', 'inquiries', 'activity'],
            'staff' => ['users', 'trainees', 'programs', 'classes', 'schedules', 'enrollments', 'attendance', 'documents', 'announcements', 'inquiries', 'activity'],
            'instructor' => ['trainees', 'classes', 'schedules', 'enrollments', 'attendance', 'documents', 'announcements', 'inquiries'],
            'trainee' => ['attendance', 'documents', 'announcements', 'inquiries'],
        ];
        return in_array($section, $roles[$this->user['role']] ?? [], true);
    }

    private function canManage($section)
    {
        if (in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return true;
        }
        if ($this->user['role'] === 'instructor') {
            return in_array($section, ['attendance', 'announcements', 'inquiries', 'documents'], true);
        }
        return $this->user['role'] === 'trainee' && $section === 'inquiries';
    }

    private function recordIsVisible($section, array $record, array $records, $key)
    {
        if (in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return true;
        }
        foreach ($records as $visible) {
            if ((int) ($visible[$key] ?? 0) === (int) ($record[$key] ?? -1)) {
                return true;
            }
        }
        return false;
    }

    private function notice($message)
    {
        $this->session->set_flashdata('smarttrack_notice', $message);
    }

    private function deny($message)
    {
        $this->notice($message);
        redirect($this->url('dashboard'));
    }

    private function render($page, array $data = [])
    {
        $data['page'] = $page;
        $data['user'] = $this->user;
        $data['csrf'] = $this->session->userdata('smarttrack_csrf');
        $data['setup_required'] = $this->model->userCount() === 0;
        $data['app_url'] = $this->url('');
        $data['notice'] = $this->session->flashdata('smarttrack_notice');
        $data['flash_error'] = $this->session->flashdata('smarttrack_error');
        $navigation = [
            'administrator' => ['dashboard', 'users', 'trainees', 'programs', 'classes', 'schedules', 'enrollments', 'attendance', 'documents', 'announcements', 'inquiries', 'activity'],
            'staff' => ['dashboard', 'users', 'trainees', 'programs', 'classes', 'schedules', 'enrollments', 'attendance', 'documents', 'announcements', 'inquiries', 'activity'],
            'instructor' => ['dashboard', 'classes', 'trainees', 'schedules', 'enrollments', 'attendance', 'documents', 'announcements', 'inquiries'],
            'trainee' => ['dashboard', 'attendance', 'documents', 'announcements', 'inquiries'],
        ];
        $data['navigation'] = $this->user ? ($navigation[$this->user['role']] ?? []) : [];
        $this->call->view('smarttrack', $data);
    }

    private function input($key)
    {
        return $_POST[$key] ?? '';
    }

    private function validCsrf()
    {
        $submitted = (string) $this->input('_csrf');
        $stored = (string) $this->session->userdata('smarttrack_csrf');

        // Auto-regenerate CSRF token if session expired
        if ($stored === '') {
            $this->session->set_userdata('smarttrack_csrf', bin2hex(random_bytes(32)));
            return true;
        }

        return $submitted !== '' && hash_equals($stored, $submitted);
    }

    private function requireUser()
    {
        if ($this->user) {
            return true;
        }
        redirect($this->url(''));
        return false;
    }

    private function url($path)
    {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
        return ($base ? $base : '') . '/' . ltrim($path, '/');
    }
}