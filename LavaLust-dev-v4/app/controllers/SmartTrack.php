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

        $data = [
            'stats' => $this->model->dashboardStats($this->user),
            'attendance' => $this->model->recentAttendance($this->user),
            'announcements' => $this->model->announcements(),
        ];

        // Add chart data for admin/staff/instructor
        if (in_array($this->user['role'], ['administrator', 'staff', 'instructor'], true)) {
            $data['charts'] = $this->model->dashboardChartData($this->user);
        }

        $this->render('dashboard', $data);
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

        // Handle document filtering
        if ($section === 'documents') {
            $filters = [];
            foreach (['search', 'document_type', 'class_id', 'access_level', 'status', 'date_from', 'date_to', 'sort'] as $filter) {
                $value = $_GET[$filter] ?? '';
                $filters[$filter] = is_scalar($value) ? trim((string) $value) : '';
            }
            $filters['sort'] = $filters['sort'] ?: 'newest';
            $records = $this->model->filterDocuments($this->user, $filters);
        } else {
            $records = $this->model->records($section, $this->user);
        }

        $editId = (int) ($_GET['edit'] ?? $this->session->flashdata('smarttrack_edit') ?? 0);
        $editing = $editId ? $this->model->findRecord($definition['table'], $definition['key'], $editId) : null;
        if ($editing && !$this->recordIsVisible($section, $editing, $records, $definition['key'])) {
            $editing = null;
        }

        $data = [
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
        ];

        // Add document-specific data for admin/staff
        if ($section === 'documents' && in_array($this->user['role'], ['administrator', 'staff'], true)) {
            $data['doc_stats'] = $this->model->documentStats($this->user);
            $data['recent_docs'] = $this->model->recentDocuments($this->user, 5);
            error_log('Recent docs count: ' . count($data['recent_docs']));
        }

        $this->render('module', $data);
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

        $existing = null;
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
            if ($section === 'classes') {
                $this->model->recalculateClassEligibility((int) $savedId);
            } elseif (in_array($section, ['enrollments', 'attendance'], true)) {
                if ($existing) {
                    $this->recalculateEligibilityPair($existing['trainee_id'] ?? 0, $existing['class_id'] ?? 0);
                }
                $savedRecord = $this->model->findRecord($definition['table'], $definition['key'], (int) $savedId);
                if ($savedRecord) {
                    $this->recalculateEligibilityPair($savedRecord['trainee_id'] ?? 0, $savedRecord['class_id'] ?? 0);
                }
            }
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
        if (in_array($section, ['enrollments', 'attendance', 'documents'], true)) {
            $this->recalculateEligibilityPair($existing['trainee_id'] ?? 0, $existing['class_id'] ?? 0);
        }
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
            $this->recalculateEligibilityPair($qrTrainee['trainee_id'], $classId);
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

        // Cloud Storage or Local Storage
        $this->call->library('CloudStorage');
        $cloudStorage = new CloudStorage();
        $useCloud = $cloudStorage->isEnabled();
        $cloudRequested = getenv('CLOUD_STORAGE_ENABLED') === 'true';
        $cloudWarning = $cloudRequested && !$useCloud
            ? 'Cloud storage is enabled but its provider, AWS settings, or SDK are incomplete.'
            : null;

        $filename = bin2hex(random_bytes(20)) . '.' . $allowed[$mime];
        $remotePath = 'documents/' . date('Y/m') . '/' . $filename;
        $dbPath = $remotePath;

        if ($useCloud) {
            // Upload to cloud storage
            $cloudUploaded = $cloudStorage->upload($file['tmp_name'], $remotePath, $mime);
            if (!$cloudUploaded) {
                $cloudWarning = 'Cloud upload failed. The document was stored locally instead.';
                $useCloud = false;
            } else {
                $dbPath = 'cloud://' . $remotePath;
            }
        }

        if (!$useCloud) {
            // Fallback to local storage
            $directory = ROOT_DIR . 'runtime/uploads/private';
            if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                return $this->deny('The private upload directory is unavailable.');
            }
            $localFilename = bin2hex(random_bytes(20)) . '.' . $allowed[$mime];
            $destination = $directory . DIRECTORY_SEPARATOR . $localFilename;
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                $this->notice('The document could not be stored.');
                redirect($this->url('manage/documents'));
                return;
            }
            $dbPath = 'runtime/uploads/private/' . $localFilename;
        }

        $this->model->execute('INSERT INTO trainee_documents (trainee_id, class_id, uploaded_by, document_name, file_path, upload_date, status, access_level) VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?)', [$traineeId ?: null, $classId, (int) $this->user['user_id'], substr($label, 0, 150), $dbPath, 'active', $access]);
        $this->recalculateEligibilityPair($traineeId, $classId);
        $this->model->logActivity((int) $this->user['user_id'], 'Uploaded document', substr($label, 0, 150) . ($useCloud ? ' (Cloud)' : ' (Local)'));
        $this->notice($useCloud
            ? 'Document uploaded to cloud storage.'
            : 'Document uploaded locally.' . ($cloudWarning ? ' ' . $cloudWarning : ''));
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
        if (!$document || (!$isAdmin && !$isOwner && !$isClassDocument)) {
            http_response_code(404);
            exit('Document not found.');
        }

        $filePath = $document['file_path'];
        $isCloud = strpos($filePath, 'cloud://') === 0;

        if ($isCloud) {
            // Cloud storage - generate temporary URL
            $this->call->library('CloudStorage');
            $cloudStorage = new CloudStorage();
            $remotePath = substr($filePath, 8); // Remove 'cloud://' prefix
            $tempUrl = $cloudStorage->getTemporaryUrl($remotePath, 3600);

            if ($tempUrl) {
                header('Location: ' . $tempUrl);
                exit;
            } else {
                http_response_code(500);
                exit('Could not generate download URL.');
            }
        }

        // Local storage
        $path = $this->privateDocumentPath($filePath);
        if (!$path) {
            http_response_code(404);
            exit('Document not found.');
        }
        header('Content-Type: ' . (mime_content_type($path) ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $document['document_name']) . '"');
        readfile($path);
        exit;
    }

    public function deleteDocument($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to delete documents.');
        }

        $document = $this->model->documentById((int) $id);
        if (!$document) {
            $this->notice('Document not found.');
            redirect($this->url('manage/documents'));
            return;
        }

        $filePath = $document['file_path'];
        $isCloud = strpos($filePath, 'cloud://') === 0;

        if ($isCloud) {
            // Delete from cloud storage
            $this->call->library('CloudStorage');
            $cloudStorage = new CloudStorage();
            $remotePath = substr($filePath, 8); // Remove 'cloud://' prefix
            if (!$cloudStorage->delete($remotePath)) {
                $this->notice('The cloud document could not be deleted. No database records were removed.');
                redirect($this->url('manage/documents'));
                return;
            }
        } else {
            // Delete from local storage
            $path = $this->privateDocumentPath($filePath);
            if ($path && file_exists($path)) {
                unlink($path);
            }
        }

        $this->model->execute('DELETE FROM trainee_documents WHERE document_id = ?', [(int) $id]);
        $this->recalculateEligibilityPair($document['trainee_id'] ?? 0, $document['class_id'] ?? 0);
        $this->model->logActivity((int) $this->user['user_id'], 'Deleted document', $document['document_name'] . ($isCloud ? ' (Cloud)' : ' (Local)'));
        $this->notice('Document deleted.');
        redirect($this->url('manage/documents'));
    }

    public function updateDocumentStatus($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to update document status.');
        }

        $status = $this->input('status');
        if (!in_array($status, ['active', 'inactive', 'archived'], true)) {
            $this->notice('Invalid status.');
            redirect($this->url('manage/documents'));
            return;
        }

        $document = $this->model->documentById((int) $id);
        if (!$document) {
            $this->notice('Document not found.');
            redirect($this->url('manage/documents'));
            return;
        }

        $this->model->execute('UPDATE trainee_documents SET status = ? WHERE document_id = ?', [$status, (int) $id]);
        $this->recalculateEligibilityPair($document['trainee_id'] ?? 0, $document['class_id'] ?? 0);
        $this->model->logActivity((int) $this->user['user_id'], 'Updated document status', $document['document_name'] . ' -> ' . $status);
        $this->notice('Document status updated.');
        redirect($this->url('manage/documents'));
    }

    public function certificates()
    {
        if (!$this->requireUser() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to manage certificates.');
        }

        $filters = [];
        foreach (['search', 'status', 'program_id'] as $filter) {
            $value = $_GET[$filter] ?? '';
            $filters[$filter] = is_scalar($value) ? trim((string) $value) : '';
        }

        $this->model->refreshEligibilityRecords();
        $stats = $this->model->certificateStats();
        $records = $this->model->getCertificates($filters);
        $programs = $this->model->options('programs', $this->user);

        $this->render('module', [
            'title' => 'Certificates',
            'section' => 'certificates',
            'records' => $records,
            'certificate_stats' => $stats,
            'options' => ['programs' => $programs],
            'columns' => ['trainee_name', 'trainee_number', 'program_name', 'class_name', 'completion_status', 'attendance_percentage', 'documents_approved', 'documents_required', 'assessments_completed', 'assessments_required', 'is_eligible', 'ineligibility_reasons', 'approval_status', 'certificate_number', 'issue_date', 'status'],
            'user' => $this->user,
            'app_url' => $this->url(''),
            'csrf' => $this->session->flashdata('_csrf') ?? bin2hex(random_bytes(32)),
        ]);
    }

    public function eligibility()
    {
        if (!$this->requireUser() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to manage certificate eligibility.');
        }

        $this->model->refreshEligibilityRecords();
        $filters = [];
        foreach (['search', 'eligibility', 'program_id'] as $filter) {
            $value = $_GET[$filter] ?? '';
            $filters[$filter] = is_scalar($value) ? trim((string) $value) : '';
        }

        $records = $this->model->getEligibilityRecords($filters);
        $editingEligibility = null;
        if (array_key_exists('edit', $_GET)) {
            $editId = is_scalar($_GET['edit']) ? (string) $_GET['edit'] : '';
            if (!ctype_digit($editId) || !(int) $editId) {
                show_404();
                return;
            }
            foreach ($records as $record) {
                if ((int) $record['eligibility_id'] === (int) $editId) {
                    $editingEligibility = $record;
                    break;
                }
            }
            if (!$editingEligibility) {
                show_404();
                return;
            }
        }
        $programs = $this->model->options('programs', $this->user);

        $this->render('module', [
            'title' => 'Certificate Eligibility',
            'section' => 'eligibility',
            'records' => $records,
            'editing_eligibility' => $editingEligibility,
            'options' => ['programs' => $programs],
            'columns' => ['trainee_name', 'trainee_number', 'program_name', 'class_name', 'attendance_percentage', 'documents_approved', 'assessments_completed', 'is_eligible', 'approval_status', 'ineligibility_reasons'],
            'user' => $this->user,
            'app_url' => $this->url(''),
            'csrf' => $this->session->flashdata('_csrf') ?? bin2hex(random_bytes(32)),
        ]);
    }

    public function assessments()
    {
        if (!$this->requireUser() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to manage assessments.');
        }

        $editing = null;
        if (array_key_exists('edit', $_GET)) {
            $editId = is_scalar($_GET['edit']) ? (string) $_GET['edit'] : '';
            if (!ctype_digit($editId) || !(int) $editId || !($editing = $this->model->assessmentById((int) $editId))) {
                show_404();
                return;
            }
        }
        $this->render('module', [
            'title' => 'Training Assessments',
            'section' => 'assessments',
            'records' => $this->model->assessmentRecords(),
            'editing' => $editing,
            'primary_key' => 'assessment_id',
            'options' => [
                'trainees' => $this->model->options('trainees', $this->user),
                'classes' => $this->model->rows('SELECT class_id AS id, class_name AS label FROM training_classes ORDER BY class_name'),
            ],
            'columns' => ['trainee_name', 'trainee_number', 'program_name', 'class_name', 'assessment_name', 'outcome', 'assessment_date', 'assessor_name', 'notes'],
        ]);
    }

    public function saveAssessment()
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to record assessment results.');
        }

        $traineeId = (int) $this->input('trainee_id');
        $classId = (int) $this->input('class_id');
        $name = trim((string) $this->input('assessment_name'));
        $outcome = strtolower(trim((string) $this->input('outcome')));
        $assessmentDate = trim((string) $this->input('assessment_date'));
        $notes = trim((string) $this->input('notes'));
        $assessmentId = trim((string) $this->input('assessment_id'));
        $editing = null;
        if ($assessmentId !== '') {
            if (!ctype_digit($assessmentId) || !(int) $assessmentId || !($editing = $this->model->assessmentById((int) $assessmentId))) {
                $this->notice('The assessment record could not be found.');
                redirect($this->url('manage/assessments'));
                return;
            }
        }
        $enrollment = $this->model->row('SELECT enrollment_id FROM class_enrollments WHERE trainee_id = ? AND class_id = ?', [$traineeId, $classId]);
        $date = DateTime::createFromFormat('!Y-m-d', $assessmentDate);

        if (!$traineeId || !$classId || !$enrollment || $name === '' || strlen($name) > 150
            || !in_array($outcome, ['pass', 'fail'], true)
            || !$date || $date->format('Y-m-d') !== $assessmentDate
            || strlen($notes) > 2000) {
            $this->notice('Select an enrolled trainee and class, enter an assessment name and date, and choose Pass or Fail.');
            redirect($this->url('manage/assessments'));
            return;
        }

        $duplicate = $editing
            ? $this->model->row('SELECT assessment_id FROM training_assessments WHERE trainee_id = ? AND class_id = ? AND assessment_name = ? AND assessment_id <> ?', [
                $traineeId,
                $classId,
                $name,
                (int) $editing['assessment_id'],
            ])
            : null;
        if ($duplicate) {
            $this->notice('That trainee already has an assessment with this name in the selected class.');
            redirect($this->url('manage/assessments' . ($editing ? '?edit=' . (int) $editing['assessment_id'] : '')));
            return;
        }

        $savedAssessmentId = $this->model->saveAssessment([
            'assessment_id' => $editing ? (int) $editing['assessment_id'] : null,
            'trainee_id' => $traineeId,
            'class_id' => $classId,
            'assessment_name' => $name,
            'outcome' => $outcome,
            'assessed_by' => (int) $this->user['user_id'],
            'assessment_date' => $assessmentDate,
            'notes' => $notes,
        ]);
        if ($editing && ((int) $editing['trainee_id'] !== $traineeId || (int) $editing['class_id'] !== $classId)) {
            $this->recalculateEligibilityPair($editing['trainee_id'], $editing['class_id']);
        }
        $this->model->calculateEligibility($traineeId, $classId);
        $this->model->logActivity((int) $this->user['user_id'], $editing ? 'Updated training assessment' : 'Recorded training assessment', $name . ' (' . strtoupper($outcome) . '), record #' . $savedAssessmentId);
        $this->notice($editing ? 'Assessment result updated.' : 'Assessment result saved.');
        redirect($this->url('manage/assessments'));
    }

    public function deleteAssessment($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to delete assessment results.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            show_404();
            return;
        }

        $assessment = $this->model->assessmentById((int) $id);
        if (!$assessment || !$this->model->deleteAssessment((int) $id)) {
            $this->notice('Assessment record not found or could not be deleted.');
            redirect($this->url('manage/assessments'));
            return;
        }

        $this->recalculateEligibilityPair($assessment['trainee_id'], $assessment['class_id']);
        $this->model->logActivity((int) $this->user['user_id'], 'Deleted training assessment', $assessment['assessment_name'] . ', record #' . (int) $id);
        $this->notice('Assessment record deleted.');
        redirect($this->url('manage/assessments'));
    }

    public function reviewEligibility()
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to review certificate eligibility.');
        }

        $eligibilityId = (int) $this->input('eligibility_id');
        $decision = (string) $this->input('decision');
        $reason = trim((string) $this->input('reason'));
        if (!$eligibilityId || !in_array($decision, ['approved', 'rejected'], true) || ($decision === 'rejected' && $reason === '')) {
            $this->notice('Choose an approval decision and provide a reason when rejecting.');
            redirect($this->url('manage/eligibility'));
            return;
        }

        if (!$this->model->updateEligibilityApproval($eligibilityId, $decision, (int) $this->user['user_id'], $reason ?: null)) {
            $this->notice('Only eligible trainees can be approved. Recalculate eligibility and review the requirements first.');
            redirect($this->url('manage/eligibility?edit=' . $eligibilityId));
            return;
        }

        $this->model->logEligibilityAction((int) $this->user['user_id'], $eligibilityId, $decision, $reason);
        $this->model->logActivity((int) $this->user['user_id'], 'Reviewed certificate eligibility', 'Eligibility #' . $eligibilityId . ' ' . $decision);
        $this->notice('Eligibility review saved.');
        redirect($this->url('manage/eligibility'));
    }

    public function deleteEligibility($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to remove class enrollments.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            show_404();
            return;
        }

        $result = $this->model->deleteEligibilityEnrollment((int) $id, (int) $this->user['user_id']);
        if (!$result['success']) {
            $this->notice($result['message']);
            redirect($this->url('manage/eligibility'));
            return;
        }

        $this->model->logActivity((int) $this->user['user_id'], 'Removed class enrollment from certificate eligibility', 'Eligibility #' . (int) $id . ': ' . $result['message']);
        $this->notice($result['message']);
        redirect($this->url('manage/eligibility'));
    }

    public function saveDocumentRequirement()
    {
        if (!$this->requireUser() || !$this->validCsrf() || $this->user['role'] !== 'administrator') {
            return $this->deny('Only administrators can configure required documents.');
        }

        $classId = (int) $this->input('class_id');
        $documentName = trim((string) $this->input('required_document_name'));
        $class = $this->model->row('SELECT class_id FROM training_classes WHERE class_id = ?', [$classId]);
        if (!$class || $documentName === '' || strlen($documentName) > 150) {
            $this->notice('Choose a class and enter a document name up to 150 characters.');
            redirect($this->url('manage/certificate-config'));
            return;
        }

        try {
            $this->model->addDocumentRequirement($classId, $documentName, (int) $this->user['user_id']);
        } catch (Throwable $error) {
            $this->notice('That required document is already listed for this class.');
            redirect($this->url('manage/certificate-config'));
            return;
        }
        $this->model->recalculateClassEligibility($classId);
        $this->model->logActivity((int) $this->user['user_id'], 'Added certificate document requirement', $documentName);
        $this->notice('Required document added.');
        redirect($this->url('manage/certificate-config'));
    }

    public function deleteDocumentRequirement($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || $this->user['role'] !== 'administrator') {
            return $this->deny('Only administrators can configure required documents.');
        }
        $requirement = ctype_digit((string) $id)
            ? $this->model->row('SELECT class_id FROM certificate_document_requirements WHERE requirement_id = ?', [(int) $id])
            : null;
        if (!$requirement || !$this->model->deleteDocumentRequirement((int) $id)) {
            $this->notice('Required document not found.');
        } else {
            $this->model->recalculateClassEligibility((int) $requirement['class_id']);
            $this->model->logActivity((int) $this->user['user_id'], 'Removed certificate document requirement', 'Requirement #' . (int) $id);
            $this->notice('Required document removed.');
        }
        redirect($this->url('manage/certificate-config'));
    }

    public function recalculateEligibility()
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to recalculate eligibility.');
        }

        $traineeId = (int) $this->input('trainee_id');
        $classId = (int) $this->input('class_id');

        if (!$traineeId || !$classId) {
            $this->notice('Invalid trainee or class.');
            redirect($this->url('manage/eligibility'));
            return;
        }

        $result = $this->model->calculateEligibility($traineeId, $classId);
        $this->model->logActivity((int) $this->user['user_id'], 'Recalculated eligibility', "Trainee ID: $traineeId, Class ID: $classId");

        if ($result['eligible']) {
            $this->notice('Trainee is eligible for certificate.');
        } else {
            $this->notice('Trainee is not eligible: ' . implode(', ', $result['reasons']));
        }

        redirect($this->url('manage/eligibility'));
    }

    public function issueCertificate()
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to issue certificates.');
        }

        $traineeId = (int) $this->input('trainee_id');
        $classId = (int) $this->input('class_id');

        if (!$traineeId || !$classId) {
            $this->notice('Invalid trainee or class.');
            redirect($this->url('manage/eligibility'));
            return;
        }

        $certificate = $this->model->issueCertificate($traineeId, $classId, (int) $this->user['user_id']);

        if ($certificate) {
            $this->generateCertificatePDF($certificate);
            $this->model->logActivity((int) $this->user['user_id'], 'Issued certificate', "Certificate: {$certificate['certificate_number']}");
            $this->model->logCertificateAction((int) $this->user['user_id'], (int) $certificate['certificate_id'], 'issue', "Certificate issued for trainee ID: $traineeId, class ID: $classId");
            $this->notice('Certificate issued successfully.');
        } else {
            $this->notice('Failed to issue certificate. Trainee may not be eligible.');
        }

        redirect($this->url('manage/certificates'));
    }

    public function certificateHistory($id)
    {
        if (!$this->requireUser() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to view certificate history.');
        }
        if (!ctype_digit((string) $id)) {
            show_404();
            return;
        }

        $certificate = $this->model->row('SELECT certificate_number FROM certificates WHERE certificate_id = ?', [(int) $id]);
        if (!$certificate) {
            show_404();
            return;
        }

        $this->render('module', [
            'title' => 'Certificate History',
            'section' => 'certificate_history',
            'certificate_number' => $certificate['certificate_number'],
            'records' => $this->model->certificateAuditRecords((int) $id),
            'columns' => ['action', 'actor_name', 'details', 'ip_address', 'created_at'],
        ]);
    }

    private function generateCertificatePDF($certificate)
    {
        $this->call->library('CertificateGenerator');
        $generator = new CertificateGenerator([
            'institution_name' => $this->model->getConfig('institution_name', 'LSI Training Center'),
            'logo_path' => $this->model->getConfig('logo_path', ''),
            'signatory_name' => $this->model->getConfig('signatory_name', 'Training Director'),
            'signatory_designation' => $this->model->getConfig('signatory_designation', 'Director'),
            'certificate_title' => $this->model->getConfig('certificate_title', 'Certificate of Completion'),
            'certificate_subtitle' => $this->model->getConfig('certificate_subtitle', 'OF COMPLETION'),
            'template_color' => $this->model->getConfig('template_color', '#dd4814'),
        ]);

        $trainee = $this->model->row('SELECT CONCAT(t.first_name, " ", t.last_name) AS trainee_name FROM trainees t WHERE t.trainee_id = ?', [(int) $certificate['trainee_id']]);
        $program = $this->model->row('SELECT p.program_name, c.class_name FROM training_programs p JOIN training_classes c ON c.program_id = p.program_id WHERE c.class_id = ?', [(int) $certificate['class_id']]);

        $pdfData = $generator->generate([
            'trainee_name' => $trainee['trainee_name'] ?? 'Unknown',
            'program_name' => $program['program_name'] ?? 'Unknown Program',
            'class_name' => $program['class_name'] ?? '',
            'completion_date' => $certificate['completion_date'],
            'issue_date' => $certificate['issue_date'],
            'certificate_number' => $certificate['certificate_number'],
            'qr_code' => $this->certificateVerificationUrl($certificate['qr_code']),
        ]);

        $filename = 'certificate_' . $certificate['certificate_number'] . '.pdf';
        $filePath = ROOT_DIR . 'runtime/uploads/certificates/' . $filename;

        if (!is_dir(ROOT_DIR . 'runtime/uploads/certificates')) {
            mkdir(ROOT_DIR . 'runtime/uploads/certificates', 0755, true);
        }

        file_put_contents($filePath, $pdfData);

        $relativePath = 'runtime/uploads/certificates/' . $filename;
        $this->model->execute('UPDATE certificates SET file_path = ? WHERE certificate_id = ?', [$relativePath, (int) $certificate['certificate_id']]);
    }

    public function revokeCertificate()
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to revoke certificates.');
        }

        $certificateId = (int) $this->input('certificate_id');
        $reason = trim((string) $this->input('reason'));

        if (!$certificateId || empty($reason)) {
            $this->notice('Certificate ID and reason are required.');
            redirect($this->url('manage/certificates'));
            return;
        }

        $this->model->revokeCertificate($certificateId, (int) $this->user['user_id'], $reason);
        $this->model->logActivity((int) $this->user['user_id'], 'Revoked certificate', "Certificate ID: $certificateId, Reason: $reason");
        $this->model->logCertificateAction((int) $this->user['user_id'], (int) $certificateId, 'revoke', "Reason: $reason");
        $this->notice('Certificate revoked successfully.');
        redirect($this->url('manage/certificates'));
    }

    public function certificateConfig()
    {
        if (!$this->requireUser() || !in_array($this->user['role'], ['administrator'], true)) {
            return $this->deny('Only administrators can configure certificates.');
        }

        if ($this->validCsrf()) {
            // Handle logo upload
            $logoPath = $this->input('current_logo_path');
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = ROOT_DIR . 'runtime/uploads/certificates/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                $filename = 'logo_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $filename)) {
                    $logoPath = 'runtime/uploads/certificates/' . $filename;
                }
            }

            $configs = [
                'institution_name' => $this->input('institution_name'),
                'logo_path' => $logoPath,
                'signatory_name' => $this->input('signatory_name'),
                'signatory_designation' => $this->input('signatory_designation'),
                'min_attendance_percentage' => $this->input('min_attendance_percentage'),
                'require_documents' => $this->input('require_documents') ? '1' : '0',
                'require_assessments' => $this->input('require_assessments') ? '1' : '0',
                'required_assessment_count' => $this->input('required_assessment_count'),
                'certificate_title' => $this->input('certificate_title'),
                'certificate_subtitle' => $this->input('certificate_subtitle'),
                'template_color' => $this->input('template_color'),
            ];
            $minimumAttendance = filter_var($configs['min_attendance_percentage'], FILTER_VALIDATE_FLOAT);
            $requiredAssessmentCount = filter_var($configs['required_assessment_count'], FILTER_VALIDATE_INT);
            if ($minimumAttendance === false || $minimumAttendance < 0 || $minimumAttendance > 100
                || $requiredAssessmentCount === false || $requiredAssessmentCount < 1 || $requiredAssessmentCount > 100) {
                $this->notice('Set attendance between 0 and 100 percent and required assessments between 1 and 100.');
                redirect($this->url('manage/certificate-config'));
                return;
            }

            foreach ($configs as $key => $value) {
                $this->model->updateConfig($key, $value);
            }
            $this->model->refreshEligibilityRecords();

            $this->model->logActivity((int) $this->user['user_id'], 'Updated certificate template');
            $this->notice('Certificate template updated.');
            redirect($this->url('manage/certificate-config'));
        }

        $configs = [
            'institution_name' => $this->model->getConfig('institution_name', 'LSI Training Center'),
            'logo_path' => $this->model->getConfig('logo_path', ''),
            'signatory_name' => $this->model->getConfig('signatory_name', 'Training Director'),
            'signatory_designation' => $this->model->getConfig('signatory_designation', 'Director'),
            'min_attendance_percentage' => $this->model->getConfig('min_attendance_percentage', 75),
            'require_documents' => $this->model->getConfig('require_documents', 1),
            'require_assessments' => $this->model->getConfig('require_assessments', 0),
            'required_assessment_count' => $this->model->getConfig('required_assessment_count', 1),
            'certificate_title' => $this->model->getConfig('certificate_title', 'Certificate of Completion'),
            'certificate_subtitle' => $this->model->getConfig('certificate_subtitle', 'OF COMPLETION'),
            'template_color' => $this->model->getConfig('template_color', '#dd4814'),
        ];

        $this->render('module', [
            'title' => 'Certificate Template',
            'section' => 'certificate_config',
            'configs' => $configs,
            'document_requirements' => $this->model->documentRequirements(),
            'certificate_classes' => $this->model->rows('SELECT class_id AS id, class_name AS label FROM training_classes ORDER BY class_name'),
            'user' => $this->user,
            'app_url' => $this->url(''),
            'csrf' => $this->session->flashdata('_csrf') ?? bin2hex(random_bytes(32)),
        ]);
    }

    public function myCertificates()
    {
        if (!$this->requireUser() || $this->user['role'] !== 'trainee') {
            return $this->deny('Only trainees can access their certificates.');
        }

        $trainee = $this->model->row('SELECT trainee_id FROM trainees WHERE user_id = ?', [(int) $this->user['user_id']]);
        if (!$trainee) {
            return $this->deny('Trainee record not found.');
        }

        $records = $this->model->rows('SELECT c.*, p.program_name, cl.class_name FROM certificates c JOIN training_programs p ON p.program_id = c.program_id JOIN training_classes cl ON cl.class_id = c.class_id WHERE c.trainee_id = ? ORDER BY c.issue_date DESC', [(int) $trainee['trainee_id']]);

        $eligibility = $this->model->rows('SELECT e.*, p.program_name, cl.class_name FROM certificate_eligibility e JOIN training_programs p ON p.program_id = e.program_id JOIN training_classes cl ON cl.class_id = e.class_id WHERE e.trainee_id = ? ORDER BY e.updated_at DESC', [(int) $trainee['trainee_id']]);

        $this->render('module', [
            'title' => 'My Certificates',
            'section' => 'my_certificates',
            'records' => $records,
            'eligibility' => $eligibility,
            'user' => $this->user,
            'app_url' => $this->url(''),
            'csrf' => $this->session->flashdata('_csrf') ?? bin2hex(random_bytes(32)),
        ]);
    }

    public function downloadCertificate($id)
    {
        if (!$this->requireUser()) {
            return;
        }

        $certificate = $this->model->row('SELECT c.*, t.user_id FROM certificates c JOIN trainees t ON t.trainee_id = c.trainee_id WHERE c.certificate_id = ?', [(int) $id]);
        if (!$certificate) {
            show_404();
            return;
        }

        // Security check: trainees can only download their own certificates
        if ($this->user['role'] === 'trainee' && (int) $certificate['user_id'] !== (int) $this->user['user_id']) {
            return $this->deny('You are not allowed to download this certificate.');
        }

        // Staff/admin can download any certificate
        if (!in_array($this->user['role'], ['administrator', 'staff', 'trainee'], true)) {
            return $this->deny('You are not allowed to download certificates.');
        }

        if ($certificate['status'] !== 'issued') {
            $this->notice('This certificate has been revoked.');
            redirect($this->url('dashboard'));
            return;
        }

        if (empty($certificate['file_path'])) {
            $this->notice('Certificate file not available.');
            redirect($this->url('dashboard'));
            return;
        }

        $filePath = $this->privateCertificatePath($certificate['file_path']);
        if (!$filePath) {
            $this->notice('Certificate file not found.');
            redirect($this->url('dashboard'));
            return;
        }

        $this->model->logCertificateAction((int) $this->user['user_id'], (int) $certificate['certificate_id'], 'download', 'Certificate downloaded by ' . $this->user['role']);

        header('Content-Type: application/pdf');
        $disposition = isset($_GET['preview']) && $_GET['preview'] === '1' ? 'inline' : 'attachment';
        header('Content-Disposition: ' . $disposition . '; filename="certificate_' . $certificate['certificate_number'] . '.pdf"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }

    public function verifyCertificate()
    {
        $qrCode = isset($_GET['qr']) && is_scalar($_GET['qr']) ? trim((string) $_GET['qr']) : '';
        $certificateNumber = isset($_GET['certificate_number']) && is_scalar($_GET['certificate_number'])
            ? trim((string) $_GET['certificate_number'])
            : '';

        $certificate = null;
        if ($qrCode) {
            $certificate = $this->model->row('SELECT c.*, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, p.program_name, cl.class_name FROM certificates c JOIN trainees t ON t.trainee_id = c.trainee_id JOIN training_programs p ON p.program_id = c.program_id JOIN training_classes cl ON cl.class_id = c.class_id WHERE c.qr_code = ?', [$qrCode]);
        } elseif ($certificateNumber) {
            $certificate = $this->model->row('SELECT c.*, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, p.program_name, cl.class_name FROM certificates c JOIN trainees t ON t.trainee_id = c.trainee_id JOIN training_programs p ON p.program_id = c.program_id JOIN training_classes cl ON cl.class_id = c.class_id WHERE c.certificate_number = ?', [$certificateNumber]);
        }

        $this->render('verify_certificate', [
            'title' => 'Certificate Verification',
            'page' => 'verify_certificate',
            'certificate' => $certificate,
            'app_url' => $this->url(''),
        ]);
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

    private function privateCertificatePath($relativePath)
    {
        $certificateRoot = realpath(ROOT_DIR . 'runtime/uploads/certificates');
        $path = realpath(ROOT_DIR . ltrim((string) $relativePath, '/\\'));
        if (!$certificateRoot || !$path || strpos($path, $certificateRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
            return null;
        }
        return $path;
    }

    private function certificateVerificationUrl($token)
    {
        $path = $this->url('verify-certificate?qr=' . rawurlencode((string) $token));
        $baseUrl = rtrim((string) config_item('base_url'), '/');
        if ($baseUrl !== '') {
            return $baseUrl . '/' . ltrim($path, '/');
        }

        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host !== '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            return $scheme . '://' . $host . $path;
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
            'documents' => ['title' => 'Documents', 'table' => 'trainee_documents', 'key' => 'document_id', 'columns' => ['trainee_name', 'document_name', 'upload_date', 'status', 'access_level']],
            'certificates' => ['title' => 'Certificates', 'table' => 'certificates', 'key' => 'certificate_id', 'columns' => ['trainee_name', 'program_name', 'class_name', 'certificate_number', 'issue_date', 'status']],
            'eligibility' => ['title' => 'Certificate Eligibility', 'table' => 'certificate_eligibility', 'key' => 'eligibility_id', 'columns' => ['trainee_name', 'program_name', 'class_name', 'attendance_percentage', 'is_eligible', 'ineligibility_reasons']],
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
                ['name' => 'date_of_birth', 'label' => 'Date of birth', 'type' => 'date'],
                ['name' => 'address', 'label' => 'Address', 'type' => 'textarea'],
                ['name' => 'email', 'label' => 'Email address', 'type' => 'email'],
                ['name' => 'contact_number', 'label' => 'Contact number', 'required' => true],
                ['name' => 'course', 'label' => 'Course / qualification', 'required' => true],
                ['name' => 'program_id', 'label' => 'Training program', 'type' => 'options', 'options' => 'programs'],
                ['name' => 'user_id', 'label' => 'Trainee login account', 'type' => 'options', 'options' => 'user_accounts'],
                ['name' => 'status', 'label' => 'Trainee status', 'type' => 'select', 'required' => true, 'values' => ['active', 'inactive']],
            ],
            'programs' => [
                ['name' => 'program_code', 'label' => 'LSI program code', 'required' => true],
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
            'administrator' => ['users', 'trainees', 'programs', 'classes', 'schedules', 'enrollments', 'attendance', 'documents', 'certificates', 'eligibility', 'assessments', 'certificate_config', 'announcements', 'inquiries', 'activity'],
            'staff' => ['users', 'trainees', 'programs', 'classes', 'schedules', 'enrollments', 'attendance', 'documents', 'certificates', 'eligibility', 'assessments', 'announcements', 'inquiries', 'activity'],
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
        if ($page === 'module') {
            $section = $data['section'] ?? '';
            $definition = $this->resourceDefinition($section);
            $data['can_manage'] = $data['can_manage'] ?? $this->canManage($section);
            $data['can_create'] = $data['can_create'] ?? false;
            $data['primary_key'] = $data['primary_key'] ?? ($definition['key'] ?? '');
            $data['fields'] = $data['fields'] ?? [];
            $data['editing'] = $data['editing'] ?? null;
            $data['records'] = $data['records'] ?? [];
            $data['columns'] = $data['columns'] ?? [];
            $data['options'] = $data['options'] ?? [];
        }
        $data['page'] = $page;
        $data['user'] = $this->user;
        $data['csrf'] = $this->session->userdata('smarttrack_csrf');
        $data['setup_required'] = $this->model->userCount() === 0;
        $data['app_url'] = $this->url('');
        $data['notice'] = $this->session->flashdata('smarttrack_notice');
        $data['flash_error'] = $this->session->flashdata('smarttrack_error');

        $navigation = [
            'administrator' => ['dashboard', 'users', 'trainees', 'programs', 'classes', 'schedules', 'enrollments', 'attendance', 'documents', 'certificates', 'eligibility', 'assessments', 'certificate_config', 'announcements', 'inquiries', 'activity'],
            'staff' => ['dashboard', 'users', 'trainees', 'programs', 'classes', 'schedules', 'enrollments', 'attendance', 'documents', 'certificates', 'eligibility', 'assessments', 'announcements', 'inquiries', 'activity'],
            'instructor' => ['dashboard', 'classes', 'trainees', 'schedules', 'enrollments', 'attendance', 'documents', 'announcements', 'inquiries'],
            'trainee' => ['dashboard', 'attendance', 'documents', 'my_certificates', 'announcements', 'inquiries'],
        ];
        $data['navigation'] = $this->user ? ($navigation[$this->user['role']] ?? []) : [];

        $this->call->view('smarttrack', $data);
    }

    private function input($key)
    {
        return $_POST[$key] ?? '';
    }

    private function recalculateEligibilityPair($traineeId, $classId)
    {
        $traineeId = (int) $traineeId;
        $classId = (int) $classId;
        if ($traineeId > 0 && $classId > 0) {
            $this->model->calculateEligibility($traineeId, $classId);
        }
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