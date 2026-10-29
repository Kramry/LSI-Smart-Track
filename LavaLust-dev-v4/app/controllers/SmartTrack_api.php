<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'controllers/Base.php';

class SmartTrack_api extends Base
{
    public function apiSession()
    {
        $this->jsonResponse([
            'authenticated' => (bool) $this->user,
            'user' => $this->user,
            'csrf' => $this->session->userdata('smarttrack_csrf'),
            'navigation' => $this->navigationForUser(),
        ]);
    }

    public function apiLogin()
    {
        $this->api->require_method('POST');

        $body = $this->api->body();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $account = $username !== '' ? $this->userModel->userByUsername($username) : null;

        if (!$account || $account['status'] !== 'active' || !password_verify($password, $account['password'])) {
            $this->api->respond_error('The username or password is incorrect.', 401);
        }

        $user = [
            'user_id' => (int) $account['user_id'],
            'full_name' => $account['full_name'],
            'username' => $account['username'],
            'role' => $account['role'],
        ];

        $token = $this->api->generate_jwt($account['user_id'], $user['role'], $user);
        $refreshToken = $this->api->generate_refresh_token($account['user_id']);

        $this->api->respond([
            'authenticated' => true,
            'user' => $user,
            'access_token' => $token,
            'refresh_token' => $refreshToken,
            'navigation' => $this->navigationForUser(),
        ]);
    }

    public function apiLogout()
    {
        $this->api->require_method('POST');
        $this->api->require_jwt();

        $tokenData = $this->api->get_jwt_data();
        $this->api->revoke_refresh_token($tokenData['sub']);

        $this->api->respond(['authenticated' => false]);
    }

    public function apiRefresh()
    {
        $this->api->require_method('POST');

        $body = $this->api->body();
        $refreshToken = $body['refresh_token'] ?? '';

        if (empty($refreshToken)) {
            $this->api->respond_error('Refresh token is required.', 400);
        }

        $result = $this->api->refresh_jwt($refreshToken);

        if (!$result) {
            $this->api->respond_error('Invalid or expired refresh token.', 401);
        }

        $this->api->respond([
            'access_token' => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
        ]);
    }

    public function apiDashboard()
    {
        $this->api->require_method('GET');
        $this->api->require_jwt();

        $tokenData = $this->api->get_jwt_data();
        $user = $tokenData['custom_data'] ?? [];

        $data = [
            'stats' => $this->model->dashboardStats($user),
            'attendance' => $this->model->recentAttendance($user),
            'announcements' => $this->model->announcements(),
        ];

        if (in_array($user['role'], ['administrator', 'staff', 'instructor'], true)) {
            $data['charts'] = $this->model->dashboardChartData($user);
        }

        $this->api->respond($data);
    }

    public function getTrainees()
    {
        if (!$this->user) {
            $this->jsonResponse(['message' => 'Sign in to view trainees.'], 401);
        }
        if (!$this->canAccess('trainees')) {
            $this->jsonResponse(['message' => 'You do not have access to trainees.'], 403);
        }

        $this->jsonResponse(['trainees' => $this->model->records('trainees', $this->user)]);
    }

    public function apiModule($section)
    {
        $this->api->require_method('GET');
        $this->api->require_jwt();

        $tokenData = $this->api->get_jwt_data();
        $user = $tokenData['custom_data'] ?? [];

        $canAccessProfile = $section === 'profile';
        $canAccessMyCertificates = $section === 'my_certificates' && $user['role'] === 'trainee';
        if (!$this->canAccess($section) && !$canAccessProfile && !$canAccessMyCertificates) {
            $this->api->respond_error('You do not have access to this section.', 403);
        }

        $definition = $this->resourceDefinition($section);
        $records = [];
        $fields = [];
        $columns = [];
        $key = '';
        $canManage = false;

        if ($definition) {
            $columns = $definition['columns'];
            $key = $definition['key'];
            $canManage = $this->canManage($section)
                && in_array($section, ['users', 'trainees', 'programs', 'classes', 'schedules', 'enrollments', 'attendance', 'announcements', 'inquiries'], true);
            $records = $this->model->records($section, $user);
            $fields = $canManage ? $this->fieldsFor($section) : [];
        } elseif ($section === 'certificates' && in_array($user['role'], ['administrator', 'staff'], true)) {
            $this->eligibilityModel->refreshEligibilityRecords();
            $records = $this->certificateModel->getCertificates([]);
            $columns = ['trainee_name', 'program_name', 'class_name', 'certificate_number', 'issue_date', 'status'];
            $key = 'certificate_id';
        } elseif ($section === 'eligibility' && in_array($user['role'], ['administrator', 'staff'], true)) {
            $records = $this->eligibilityModel->getEligibilityRecords([]);
            $columns = ['trainee_name', 'trainee_number', 'program_name', 'class_name', 'attendance_percentage', 'documents_approved', 'assessments_completed', 'is_eligible', 'approval_status', 'ineligibility_reasons'];
            $key = 'eligibility_id';
        } elseif ($section === 'assessments' && in_array($user['role'], ['administrator', 'staff'], true)) {
            $records = $this->assessmentModel->assessmentRecords();
            $columns = ['trainee_name', 'trainee_number', 'program_name', 'class_name', 'assessment_name', 'outcome', 'assessment_date', 'assessor_name', 'notes'];
            $key = 'assessment_id';
            $fields = [
                ['name' => 'trainee_id', 'label' => 'Trainee', 'type' => 'options', 'options' => 'trainees', 'required' => true],
                ['name' => 'class_id', 'label' => 'Class', 'type' => 'options', 'options' => 'classes', 'required' => true],
                ['name' => 'assessment_name', 'label' => 'Assessment name', 'required' => true],
                ['name' => 'outcome', 'label' => 'Outcome', 'type' => 'select', 'values' => ['pass', 'fail'], 'required' => true],
                ['name' => 'assessment_date', 'label' => 'Assessment date', 'type' => 'date', 'required' => true],
                ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ];
        } elseif ($section === 'my_certificates' && $user['role'] === 'trainee') {
            $trainee = $this->model->row('SELECT trainee_id FROM trainees WHERE user_id = ?', [(int) $user['user_id']]);
            if ($trainee) {
                $records = $this->model->rows('SELECT c.*, p.program_name, cl.class_name FROM certificates c JOIN training_programs p ON p.program_id = c.program_id JOIN training_classes cl ON cl.class_id = c.class_id WHERE c.trainee_id = ? ORDER BY c.issue_date DESC', [(int) $trainee['trainee_id']]);
            }
            $columns = ['certificate_number', 'program_name', 'class_name', 'issue_date', 'status'];
            $key = 'certificate_id';
        } elseif ($section === 'certificate_config' && in_array($user['role'], ['administrator', 'staff'], true)) {
            $configs = [];
            foreach ([
                'min_attendance_percentage', 'require_documents', 'require_assessments',
                'required_assessment_count',
            ] as $configKey) {
                $configs[$configKey] = $this->configModel->getConfig($configKey, '');
            }
            $template = $this->certificateModel->getLatestTemplate() ?: $this->certificateModel->legacyTemplate();
            $this->api->respond([
                'title' => 'Certificate Settings',
                'section' => 'certificate_config',
                'configs' => $configs,
                'certificate_template' => $template,
                'certificate_template_versions' => $this->certificateModel->getTemplateVersions(),
                'document_requirements' => $this->documentModel->documentRequirements(),
                'certificate_classes' => $this->recordModel->rows('SELECT class_id AS id, class_name AS label FROM training_classes ORDER BY class_name'),
                'can_configure_certificates' => true,
                'can_manage' => false,
                'records' => [],
                'fields' => [],
                'columns' => [],
                'primary_key' => '',
            ]);
        } elseif ($section === 'profile') {
            $trainee = $user['role'] === 'trainee'
                ? $this->userModel->traineeForUser((int) $user['user_id'])
                : null;
            $this->api->respond([
                'title' => 'My Profile',
                'section' => 'profile',
                'user' => $user,
                'trainee' => $trainee,
                'can_manage' => false,
                'records' => [],
                'fields' => [],
                'columns' => [],
                'primary_key' => '',
            ]);
        } elseif ($section === 'documents') {
            $records = $this->model->records('documents', $user);
            $columns = ['trainee_name', 'document_name', 'upload_date', 'status', 'access_level'];
            $key = 'document_id';
        } else {
            $this->api->respond_error('This section is not available in the Vue interface.', 404);
        }

        foreach ($fields as &$field) {
            if (!empty($field['options'])) {
                $field['choices'] = $this->model->options($field['options'], $user);
            }
        }
        unset($field);

        $this->api->respond([
            'title' => $definition['title'] ?? ucfirst(str_replace('_', ' ', $section)),
            'section' => $section,
            'records' => $records,
            'columns' => $columns,
            'primary_key' => $key,
            'fields' => $fields,
            'can_manage' => $section === 'documents' ? false : $canManage,
            'can_create' => $canManage && !in_array($section, ['activity', 'documents'], true)
                && !($section === 'inquiries' && $user['role'] !== 'trainee'),
            'can_upload' => $section === 'documents' && in_array($user['role'], ['administrator', 'staff', 'instructor', 'trainee'], true),
            'can_manage_documents' => $section === 'documents' && in_array($user['role'], ['administrator', 'staff'], true),
            'can_download_documents' => $section === 'documents',
            'can_assess' => $section === 'assessments' && in_array($user['role'], ['administrator', 'staff'], true),
            'can_review_certificates' => in_array($section, ['certificates', 'eligibility'], true)
                && in_array($user['role'], ['administrator', 'staff'], true),
            'can_configure_certificates' => $section === 'certificate_config' && $user['role'] === 'administrator',
            'can_scan_attendance' => $section === 'attendance' && in_array($user['role'], ['administrator', 'staff', 'instructor'], true),
            'attendance_options' => $section === 'attendance' ? [
                'classes' => $this->model->options('classes', $user),
                'schedules' => $user['role'] === 'instructor'
                    ? $this->model->rows('SELECT s.schedule_id AS id, s.class_id, CONCAT(s.schedule_date, " - ", s.start_time) AS label FROM training_schedules s JOIN training_classes c ON c.class_id = s.class_id WHERE s.schedule_date = CURDATE() AND c.instructor_id = ? ORDER BY s.start_time', [(int) $user['user_id']])
                    : $this->model->rows('SELECT schedule_id AS id, class_id, CONCAT(schedule_date, " - ", start_time) AS label FROM training_schedules WHERE schedule_date = CURDATE() ORDER BY start_time'),
            ] : [],
            'attendance_followups' => $section === 'attendance' && in_array($user['role'], ['administrator', 'staff', 'instructor'], true)
                ? $this->model->absenceFollowUps($user)
                : [],
            'document_options' => $section === 'documents' ? [
                'trainees' => $this->model->options('trainees', $user),
                'classes' => $this->model->options('classes', $user),
            ] : [],
            'certificate_stats' => $section === 'certificates' ? $this->certificateModel->certificateStats() : null,
            'assessment_options' => $section === 'assessments' ? [
                'trainees' => $this->model->options('trainees', $user),
                'classes' => $this->model->rows('SELECT class_id AS id, class_name AS label FROM training_classes ORDER BY class_name'),
            ] : [],
        ]);
    }

    // API wrappers that call the actual controller methods
    public function apiSaveModule($section)
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Dashboard.php';
        $dashboard = new Dashboard();
        $dashboard->before_action();
        $dashboard->save($section);
    }

    public function apiDeleteModule($section, $id)
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Dashboard.php';
        $dashboard = new Dashboard();
        $dashboard->before_action();
        $dashboard->delete($section, $id);
    }

    public function apiSaveProfile()
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Profile.php';
        $profile = new Profile();
        $profile->before_action();
        $profile->saveProfile();
    }

    public function apiSavePassword()
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Profile.php';
        $profile = new Profile();
        $profile->before_action();
        $profile->savePassword();
    }

    public function apiUploadDocument()
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Documents.php';
        $documents = new Documents();
        $documents->before_action();
        $documents->uploadDocument();
    }

    public function apiDownloadDocument($id)
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Documents.php';
        $documents = new Documents();
        $documents->before_action();
        $documents->downloadDocument($id);
    }

    public function apiUpdateDocumentStatus($id)
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Documents.php';
        $documents = new Documents();
        $documents->before_action();
        $documents->updateDocumentStatus($id);
    }

    public function apiReviewDocument($id)
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Documents.php';
        $documents = new Documents();
        $documents->before_action();
        $documents->reviewDocument($id);
    }

    public function apiDeleteDocument($id)
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Documents.php';
        $documents = new Documents();
        $documents->before_action();
        $documents->deleteDocument($id);
    }

    public function apiSaveAssessment()
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Assessments.php';
        $assessments = new Assessments();
        $assessments->before_action();
        $assessments->saveAssessment();
    }

    public function apiDeleteAssessment($id)
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Assessments.php';
        $assessments = new Assessments();
        $assessments->before_action();
        $assessments->deleteAssessment($id);
    }

    public function apiScanAttendance()
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Attendance.php';
        $attendance = new Attendance();
        $attendance->before_action();
        $attendance->scanAttendance();
    }

    public function apiExportReport($period)
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Attendance.php';
        $attendance = new Attendance();
        $attendance->before_action();
        $attendance->exportReport($period);
    }

    public function apiReviewEligibility()
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Certificates.php';
        $certificates = new Certificates();
        $certificates->before_action();
        $certificates->reviewEligibility();
    }

    public function apiRecalculateEligibility()
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Certificates.php';
        $certificates = new Certificates();
        $certificates->before_action();
        $certificates->recalculateEligibility();
    }

    public function apiDeleteEligibility($id)
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Certificates.php';
        $certificates = new Certificates();
        $certificates->before_action();
        $certificates->deleteEligibility($id);
    }

    public function apiIssueCertificate()
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Certificates.php';
        $certificates = new Certificates();
        $certificates->before_action();
        $certificates->issueCertificate();
    }

    public function apiRevokeCertificate()
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Certificates.php';
        $certificates = new Certificates();
        $certificates->before_action();
        $certificates->revokeCertificate();
    }

    public function apiSaveCertificateConfig()
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Certificates.php';
        $certificates = new Certificates();
        $certificates->before_action();
        $certificates->certificateConfig();
    }

    public function apiSaveDocumentRequirement()
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Documents.php';
        $documents = new Documents();
        $documents->before_action();
        $documents->saveDocumentRequirement();
    }

    public function apiDeleteDocumentRequirement($id)
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Documents.php';
        $documents = new Documents();
        $documents->before_action();
        $documents->deleteDocumentRequirement($id);
    }
}
