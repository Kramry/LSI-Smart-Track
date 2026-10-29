<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Base extends Controller
{
    protected $model;
    protected $user;
    protected $apiRequest = false;
    protected $db;
    protected $session;
    protected $api;

    public function before_action()
    {
        require_once APP_DIR . 'models/Base_model.php';
        require_once APP_DIR . 'models/User_model.php';
        require_once APP_DIR . 'models/Record_model.php';
        require_once APP_DIR . 'models/Attendance_model.php';
        require_once APP_DIR . 'models/Document_model.php';
        require_once APP_DIR . 'models/Assessment_model.php';
        require_once APP_DIR . 'models/Config_model.php';
        require_once APP_DIR . 'models/Eligibility_model.php';
        require_once APP_DIR . 'models/Certificate_model.php';
        require_once APP_DIR . 'models/Dashboard_model.php';

        $this->call->helper('url');
        $this->db = $this->call->database();
        $this->session = $this->call->library('session');
        $this->api = $this->call->library('api');

        // Instantiate models with dependencies
        $this->configModel = new Config_model($this->db);
        $this->eligibilityModel = new Eligibility_model($this->db, $this->configModel);
        $this->certificateModel = new Certificate_model($this->db, $this->eligibilityModel, $this->configModel);
        $this->userModel = new User_model($this->db);
        $this->recordModel = new Record_model($this->db);
        $this->attendanceModel = new Attendance_model($this->db);
        $this->documentModel = new Document_model($this->db);
        $this->assessmentModel = new Assessment_model($this->db);
        $this->dashboardModel = new Dashboard_model($this->db);

        // Keep $this->model for backward compatibility with controllers that haven't been updated yet
        $this->model = $this->recordModel;

        $this->user = $this->session->userdata('user');

        if (!$this->session->userdata('csrf')) {
            $this->session->set_userdata('csrf', bin2hex(random_bytes(32)));
        }
    }

    protected function render($page, array $data = [])
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
        $data['csrf'] = $this->session->userdata('csrf');
        $data['setup_required'] = $this->userModel->userCount() === 0;
        $data['app_url'] = $this->url('');
        $data['notice'] = $this->session->flashdata('notice');
        $data['flash_error'] = $this->session->flashdata('error');

        $navigation = [
            'administrator' => ['dashboard', 'users', 'trainees', 'programs', 'classes', 'schedules', 'enrollments', 'attendance', 'documents', 'certificates', 'eligibility', 'assessments', 'certificate_config', 'announcements', 'inquiries', 'activity'],
            'staff' => ['dashboard', 'users', 'trainees', 'programs', 'classes', 'schedules', 'enrollments', 'attendance', 'documents', 'certificates', 'eligibility', 'assessments', 'certificate_config', 'announcements', 'inquiries', 'activity'],
            'instructor' => ['dashboard', 'classes', 'trainees', 'schedules', 'enrollments', 'attendance', 'documents', 'announcements', 'inquiries'],
            'trainee' => ['dashboard', 'attendance', 'documents', 'my_certificates', 'announcements', 'inquiries'],
        ];
        $data['navigation'] = $this->user ? ($navigation[$this->user['role']] ?? []) : [];

        $this->call->view('smarttrack', $data);
    }

    protected function input($key)
    {
        return $_POST[$key] ?? '';
    }

    protected function jsonResponse(array $data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    protected function actionError($message, $redirectPath, $status = 422)
    {
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => $message], $status);
        }
        $this->notice($message);
        redirect($this->url($redirectPath));
    }

    protected function validCsrf()
    {
        $submitted = (string) $this->input('_csrf');
        $stored = (string) $this->session->userdata('csrf');

        // Auto-regenerate CSRF token if session expired
        if ($stored === '') {
            $this->session->set_userdata('csrf', bin2hex(random_bytes(32)));
            return true;
        }

        return $submitted !== '' && hash_equals($stored, $submitted);
    }

    protected function requireUser()
    {
        if ($this->user) {
            return true;
        }
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => 'Sign in to continue.'], 401);
        }
        redirect($this->url(''));
        return false;
    }

    protected function canAccess($section)
    {
        $roles = [
            'administrator' => ['users', 'trainees', 'programs', 'classes', 'schedules', 'enrollments', 'attendance', 'documents', 'certificates', 'eligibility', 'assessments', 'certificate_config', 'announcements', 'inquiries', 'activity'],
            'staff' => ['users', 'trainees', 'programs', 'classes', 'schedules', 'enrollments', 'attendance', 'documents', 'certificates', 'eligibility', 'assessments', 'certificate_config', 'announcements', 'inquiries', 'activity'],
            'instructor' => ['trainees', 'classes', 'schedules', 'enrollments', 'attendance', 'documents', 'announcements', 'inquiries'],
            'trainee' => ['attendance', 'documents', 'announcements', 'inquiries'],
        ];
        return in_array($section, $roles[$this->user['role']] ?? [], true);
    }

    protected function canManage($section)
    {
        if (in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return true;
        }
        if ($this->user['role'] === 'instructor') {
            return in_array($section, ['attendance', 'announcements', 'inquiries', 'documents'], true);
        }
        return $this->user['role'] === 'trainee' && $section === 'inquiries';
    }

    protected function recordIsVisible($section, array $record, array $records, $key)
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

    protected function notice($message)
    {
        $this->session->set_flashdata('notice', $message);
    }

    protected function deny($message)
    {
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => $message], 403);
        }
        $this->notice($message);
        redirect($this->url('dashboard'));
    }

    protected function url($path)
    {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
        return ($base ? $base : '') . '/' . ltrim($path, '/');
    }

    protected function navigationForUser()
    {
        $navigation = [
            'administrator' => ['dashboard', 'users', 'trainees', 'programs', 'classes', 'schedules', 'enrollments', 'attendance', 'documents', 'certificates', 'eligibility', 'assessments', 'certificate_config', 'announcements', 'inquiries', 'activity', 'profile'],
            'staff' => ['dashboard', 'users', 'trainees', 'programs', 'classes', 'schedules', 'enrollments', 'attendance', 'documents', 'certificates', 'eligibility', 'assessments', 'certificate_config', 'announcements', 'inquiries', 'activity', 'profile'],
            'instructor' => ['dashboard', 'classes', 'trainees', 'schedules', 'enrollments', 'attendance', 'documents', 'announcements', 'inquiries', 'profile'],
            'trainee' => ['dashboard', 'attendance', 'documents', 'my_certificates', 'announcements', 'inquiries', 'profile'],
        ];
        return $this->user ? ($navigation[$this->user['role']] ?? []) : [];
    }

    protected function resourceDefinition($section)
    {
        $definitions = [
            'users' => ['title' => 'User accounts', 'table' => 'users', 'key' => 'user_id', 'columns' => ['full_name', 'username', 'role', 'status']],
            'trainees' => ['title' => 'Trainees', 'table' => 'trainees', 'key' => 'trainee_id', 'columns' => ['first_name', 'last_name', 'course', 'program_name', 'status', 'qr_code']],
            'programs' => ['title' => 'Training programs', 'table' => 'training_programs', 'key' => 'program_id', 'columns' => ['program_code', 'program_name', 'status']],
            'classes' => ['title' => 'Classes', 'table' => 'training_classes', 'key' => 'class_id', 'columns' => ['class_name', 'program_name', 'instructor_name', 'start_date', 'end_date', 'status']],
            'schedules' => ['title' => 'Schedules', 'table' => 'training_schedules', 'key' => 'schedule_id', 'columns' => ['class_name', 'schedule_date', 'start_time', 'end_time']],
            'enrollments' => ['title' => 'Class enrollments', 'table' => 'class_enrollments', 'key' => 'enrollment_id', 'columns' => ['class_name', 'trainee_name', 'enrolled_at']],
            'attendance' => ['title' => 'Attendance', 'table' => 'attendance_records', 'key' => 'attendance_id', 'columns' => ['trainee_name', 'class_name', 'attendance_date', 'time_in', 'time_out', 'status', 'remarks']],
            'documents' => ['title' => 'Documents', 'table' => 'trainee_documents', 'key' => 'document_id', 'columns' => ['trainee_name', 'document_name', 'upload_date', 'review_status', 'status', 'access_level']],
            'certificates' => ['title' => 'Certificates', 'table' => 'certificates', 'key' => 'certificate_id', 'columns' => ['trainee_name', 'program_name', 'class_name', 'certificate_number', 'issue_date', 'status']],
            'eligibility' => ['title' => 'Certificate Eligibility', 'table' => 'certificate_eligibility', 'key' => 'eligibility_id', 'columns' => ['trainee_name', 'program_name', 'class_name', 'attendance_percentage', 'is_eligible', 'ineligibility_reasons']],
            'announcements' => ['title' => 'Announcements', 'table' => 'announcements', 'key' => 'announcement_id', 'columns' => ['title', 'status', 'author', 'created_at']],
            'inquiries' => ['title' => 'Inquiries', 'table' => 'inquiries', 'key' => 'inquiry_id', 'columns' => ['trainee_name', 'subject', 'message', 'reply', 'status', 'created_at']],
            'activity' => ['title' => 'Activity log', 'table' => 'activity_logs', 'key' => 'log_id', 'columns' => ['user_name', 'action', 'details', 'created_at']],
        ];
        return $definitions[$section] ?? null;
    }

    protected function fieldsFor($section)
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

    protected function moduleWithError($section, $error, $recordId = 0)
    {
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => $error], 422);
        }
        $this->session->set_flashdata('smarttrack_error', $error);
        if ($recordId) {
            $this->session->set_flashdata('smarttrack_edit', $recordId);
        }
        redirect($this->url('manage/' . $section));
    }

    protected function privateDocumentPath($relativePath)
    {
        $privateRoot = realpath(ROOT_DIR . 'runtime/uploads/private');
        $path = realpath(ROOT_DIR . ltrim((string) $relativePath, '/\\'));
        if (!$privateRoot || !$path || strpos($path, $privateRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
            return null;
        }
        return $path;
    }

    protected function privateCertificatePath($relativePath)
    {
        $certificateRoot = realpath(ROOT_DIR . 'runtime/uploads/certificates');
        $path = realpath(ROOT_DIR . ltrim((string) $relativePath, '/\\'));
        if (!$certificateRoot || !$path || strpos($path, $certificateRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
            return null;
        }
        return $path;
    }

    protected function certificateVerificationUrl($token)
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

    protected function recalculateEligibilityPair($traineeId, $classId)
    {
        $traineeId = (int) $traineeId;
        $classId = (int) $classId;
        if ($traineeId > 0 && $classId > 0) {
            $this->eligibilityModel->calculateEligibility($traineeId, $classId);
        }
    }
}
