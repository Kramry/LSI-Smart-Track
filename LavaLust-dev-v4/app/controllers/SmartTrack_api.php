<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'controllers/Base.php';
require_once APP_DIR . 'libraries/SmartTrackPdf.php';

class SmartTrack_api extends Base
{
    private function notificationService()
    {
        require_once APP_DIR . 'libraries/NotificationService.php';
        return new NotificationService($this->db);
    }

    public function apiSession()
    {
        $this->jsonResponse([
            'ok' => true,
            'data' => [
                'authenticated' => (bool) $this->user,
                'user' => $this->user,
                'csrf' => $this->session->userdata('csrf'),
            ],
            'message' => '',
        ]);
    }

    public function apiNotificationHistory()
    {
        if (!$this->requireUser() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return;
        }
        $service = $this->notificationService();
        $this->jsonResponse([
            'history' => $service->history([
                'channel' => is_scalar($_GET['channel'] ?? null) ? trim((string) $_GET['channel']) : '',
                'status' => is_scalar($_GET['status'] ?? null) ? trim((string) $_GET['status']) : '',
            ]),
            'settings' => $service->settings(),
            'ready' => $service->isReady(),
            'missing_tables' => $service->missingTables(),
        ]);
    }

    public function apiSendAnnouncement()
    {
        if (!$this->requireUser() || $this->user['role'] !== 'administrator' || !$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'Only administrators can send notifications.'], 403);
        }
        $announcementId = (int) $this->input('announcement_id');
        $mode = trim((string) $this->input('recipient_mode'));
        $classId = (int) $this->input('class_id');
        $channels = $this->input('channels');
        $channels = is_array($channels) ? array_values(array_intersect($channels, ['email'])) : [];
        $traineeIds = $this->input('trainee_ids');
        $traineeIds = is_array($traineeIds) ? $traineeIds : [];
        if (!$announcementId || !$channels || !in_array($mode, ['all', 'batch', 'selected'], true)) {
            return $this->jsonResponse(['message' => 'Choose an announcement, recipients, and notification channel.'], 422);
        }
        $announcement = $this->recordModel->row('SELECT announcement_id, title, content, published_at, status FROM announcements WHERE announcement_id = ?', [$announcementId]);
        if (!$announcement || $announcement['status'] !== 'published') {
            return $this->jsonResponse(['message' => 'Only published announcements can be sent.'], 422);
        }
        $service = $this->notificationService();
        $recipients = $service->recipients($mode, $classId, $traineeIds);
        $queued = $service->queue($announcement, $recipients, $channels);
        $result = $service->processAnnouncement($announcementId);
        $this->recordModel->logActivity((int) $this->user['user_id'], 'Sent announcement notifications', $announcement['title']);
        $this->jsonResponse([
            'message' => 'Notification processing completed.',
            'data' => ['recipients' => count($recipients), 'queued' => $queued['queued'], 'skipped' => $queued['skipped']] + $result,
        ]);
    }

    public function apiRetryNotification($id)
    {
        if (!$this->requireUser() || $this->user['role'] !== 'administrator' || !$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'Only administrators can retry notifications.'], 403);
        }
        $notification = $this->recordModel->row('SELECT announcement_id FROM announcement_notifications WHERE id = ? AND status = "failed"', [(int) $id]);
        if (!$notification) {
            return $this->jsonResponse(['message' => 'Only failed notifications can be retried.'], 404);
        }
        $this->recordModel->execute('UPDATE announcement_notifications SET status = "pending", error_message = NULL WHERE id = ?', [(int) $id]);
        $result = $this->notificationService()->processAnnouncement((int) $notification['announcement_id']);
        $this->jsonResponse(['message' => 'Notification retry completed.', 'data' => $result]);
    }

    public function apiDeleteNotification($id)
    {
        if (!$this->requireUser() || $this->user['role'] !== 'administrator' || !$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'Only administrators can delete notification history.'], 403);
        }
        $notification = $this->recordModel->row(
            'SELECT id FROM announcement_notifications WHERE id = ?',
            [(int) $id]
        );
        if (!$notification) {
            return $this->jsonResponse(['message' => 'Notification history record not found.'], 404);
        }
        $this->recordModel->execute('DELETE FROM announcement_notifications WHERE id = ?', [(int) $id]);
        $this->jsonResponse(['message' => 'Notification history record deleted.']);
    }

    public function apiSaveNotificationSettings()
    {
        if (!$this->requireUser() || $this->user['role'] !== 'administrator' || !$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'Only administrators can change notification settings.'], 403);
        }
        $email = $this->input('email_enabled') ? 1 : 0;
        $this->recordModel->execute('INSERT INTO notification_settings (id, email_enabled) VALUES (1, ?) ON DUPLICATE KEY UPDATE email_enabled = VALUES(email_enabled)', [$email]);
        $this->jsonResponse(['message' => 'Notification settings saved.']);
    }

    public function apiTestNotification()
    {
        if (!$this->requireUser() || $this->user['role'] !== 'administrator' || !$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'Only administrators can test notifications.'], 403);
        }
        $channel = trim((string) $this->input('channel'));
        $recipient = trim((string) $this->input('recipient'));
        require_once APP_DIR . 'libraries/BrevoService.php';
        if ($channel !== 'email') {
            return $this->jsonResponse(['message' => 'Only email notifications are supported.'], 422);
        }
        $result = (new BrevoService())->send($recipient, 'SmartTrack administrator', 'LSI SmartTrack test notification', '<p>This is a test notification from LSI SmartTrack.</p>');
        if (!$result['ok']) {
            return $this->jsonResponse(['message' => $result['error'] ?? 'Provider test failed.'], 422);
        }
        $this->jsonResponse(['message' => 'Provider accepted the test notification.']);
    }

    public function apiStorageStatus()
    {
        if (!$this->requireUser()) {
            return;
        }
        $this->call->library('CloudStorage');
        $storage = new CloudStorage();
        $enabled = $storage->isEnabled();
        $this->jsonResponse([
            'enabled' => $enabled,
            'provider' => $enabled ? (getenv('CLOUD_STORAGE_PROVIDER') ?: 'cloud') : 'local',
            'fallback_available' => true,
        ]);
    }

    public function apiLogin()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }
        if (!$this->validCsrf()) {
            $this->jsonResponse(['message' => 'Your session expired. Refresh and try again.'], 403);
        }

        $username = trim((string) $this->input('username'));
        $password = (string) $this->input('password');
        $errors = [];
        if ($username === '') {
            $errors['username'] = 'Enter your username.';
        } elseif (strlen($username) > 50) {
            $errors['username'] = 'Usernames must be 50 characters or fewer.';
        }
        if ($password === '' || strlen($password) > 255) {
            $errors['password'] = 'Enter a password of 1 to 255 characters.';
        }
        if ($errors) {
            $this->jsonResponse([
                'message' => 'Check the highlighted fields.',
                'errors' => $errors,
            ], 422);
        }

        $account = $username !== '' ? $this->userModel->userByUsername($username) : null;

        if (!$account || $account['status'] !== 'active' || !password_verify($password, $account['password'])) {
            $this->jsonResponse(['message' => 'The username or password is incorrect.'], 401);
        }

        $user = [
            'user_id' => (int) $account['user_id'],
            'full_name' => $account['full_name'],
            'username' => $account['username'],
            'role' => $account['role'],
        ];

        $this->session->regenerate_on_login(true);
        $this->session->set_userdata('user', $user);
        $csrf = bin2hex(random_bytes(32));
        $this->session->set_userdata('csrf', $csrf);
        $this->user = $user;

        $this->jsonResponse([
            'ok' => true,
            'data' => [
                'authenticated' => true,
                'user' => $user,
                'csrf' => $csrf,
            ],
            'message' => '',
        ]);
    }

    public function apiRegistrationOptions()
    {
        $this->jsonResponse([
            'programs' => $this->recordModel->options('programs'),
        ]);
    }

    public function apiRegister()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }
        if (!$this->validCsrf()) {
            $this->jsonResponse(['message' => 'Your session expired. Refresh and try again.'], 403);
        }

        $name = trim((string) $this->input('full_name'));
        $firstName = trim((string) $this->input('first_name'));
        $lastName = trim((string) $this->input('last_name'));
        if ($firstName === '' || $lastName === '') {
            $parts = preg_split('/\s+/', $name, 2);
            $firstName = $firstName ?: (string) ($parts[0] ?? '');
            $lastName = $lastName ?: (string) ($parts[1] ?? '');
        }
        $username = trim((string) $this->input('username'));
        $email = strtolower(trim((string) $this->input('email')));
        $role = trim((string) $this->input('role'));
        $sex = trim((string) $this->input('sex'));
        $dateOfBirth = trim((string) $this->input('date_of_birth'));
        $address = trim((string) $this->input('address'));
        $contactNumber = trim((string) $this->input('contact_number'));
        $course = trim((string) $this->input('course'));
        $programId = trim((string) $this->input('program_id'));
        $password = (string) $this->input('password');
        $passwordConfirmation = (string) $this->input('password_confirmation');
        $errors = [];

        if ($name === '' || strlen($name) > 150) {
            $errors['full_name'] = 'Enter your full name.';
        }
        if ($firstName === '' || strlen($firstName) > 100) {
            $errors['first_name'] = 'Enter your first name.';
        }
        if ($lastName === '' || strlen($lastName) > 100) {
            $errors['last_name'] = 'Enter your last name.';
        }
        if (!in_array($role, ['trainee', 'instructor', 'staff'], true)) {
            $role = 'trainee';
        }
        if (!in_array($sex, ['Male', 'Female', 'Other'], true)) {
            $errors['sex'] = 'Select your sex.';
        }
        if ($dateOfBirth !== '') {
            $date = DateTime::createFromFormat('!Y-m-d', $dateOfBirth);
            if (!$date || $date->format('Y-m-d') !== $dateOfBirth) {
                $errors['date_of_birth'] = 'Enter a valid date of birth.';
            }
        }
        if ($contactNumber === '' || strlen($contactNumber) > 20) {
            $errors['contact_number'] = 'Enter your contact number.';
        }
        if ($course === '' || strlen($course) > 100) {
            $errors['course'] = 'Enter your course or qualification.';
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{4,50}$/', $username)) {
            $errors['username'] = 'Usernames must be 4-50 characters and use only letters, numbers, dots, underscores, or hyphens.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($programId !== '' && !ctype_digit($programId)) {
            $errors['program_id'] = 'Select a valid training program.';
        } elseif ($programId !== '' && !$this->recordModel->row("SELECT program_id FROM training_programs WHERE program_id = ? AND status = 'active'", [(int) $programId])) {
            $errors['program_id'] = 'Select a valid training program.';
        }
        if (strlen($password) < 10 || strlen($password) > 255) {
            $errors['password'] = 'Passwords must be 10-255 characters.';
        }
        if ($password !== $passwordConfirmation) {
            $errors['password_confirmation'] = 'Passwords do not match.';
        }
        if ($errors) {
            $this->jsonResponse([
                'message' => 'Check the highlighted fields.',
                'errors' => $errors,
            ], 422);
        }
        if ($this->userModel->userByUsername($username)) {
            $this->jsonResponse(['message' => 'That username is already taken.', 'errors' => ['username' => 'Choose another username.']], 422);
        }
        if ($this->userModel->userByEmail($email)) {
            $this->jsonResponse(['message' => 'That email address is already registered.', 'errors' => ['email' => 'Use another email address.']], 422);
        }

        $this->userModel->createUser($name, $username, $password, $role, $email);
        $account = $this->userModel->userByUsername($username);
        $this->userModel->createTraineeForUser((int) $account['user_id'], [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'sex' => $sex,
            'date_of_birth' => $dateOfBirth,
            'address' => $address,
            'email' => $email,
            'contact_number' => $contactNumber,
            'course' => $course,
            'program_id' => $programId,
        ]);
        $this->jsonResponse([
            'ok' => true,
            'data' => ['registered' => true],
            'message' => 'Account created successfully. Please sign in.',
        ]);
    }

    public function apiLogout()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }
        if (!$this->requireUser()) {
            return;
        }
        if (!$this->validCsrf()) {
            $this->jsonResponse(['message' => 'Your session expired. Refresh and try again.'], 403);
        }

        $this->session->sess_destroy();
        $this->jsonResponse([
            'ok' => true,
            'data' => ['authenticated' => false],
            'message' => '',
        ]);
    }

    public function apiRefresh()
    {
        $api = $this->apiLibrary();
        $api->require_method('POST');

        $body = $api->body();
        $refreshToken = $body['refresh_token'] ?? '';

        if (empty($refreshToken)) {
            $api->respond_error('Refresh token is required.', 400);
        }

        $result = $api->refresh_jwt($refreshToken);

        if (!$result) {
            $api->respond_error('Invalid or expired refresh token.', 401);
        }

        $api->respond([
            'access_token' => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
        ]);
    }

    public function apiDashboard()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }
        $user = $this->authenticatedApiUser();
        if (!$user) {
            $this->jsonResponse(['message' => 'Sign in to continue.'], 401);
        }

        $data = [
            'stats' => $this->dashboardModel->dashboardStats($user),
            'attendance' => $this->dashboardModel->recentAttendance($user),
            'announcements' => $this->dashboardModel->announcements(),
        ];

        if (in_array($user['role'], ['administrator', 'staff', 'instructor'], true)) {
            $data['charts'] = $this->dashboardModel->dashboardChartData($user);
        }

        $this->jsonResponse($data);
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
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }
        $user = $this->authenticatedApiUser();
        if (!$user) {
            $this->jsonResponse(['message' => 'Sign in to continue.'], 401);
        }

        $canAccessProfile = $section === 'profile';
        $canAccessMyCertificates = $section === 'my_certificates' && $user['role'] === 'trainee';
        if (!$this->canAccess($section) && !$canAccessProfile && !$canAccessMyCertificates) {
            $this->jsonResponse(['message' => 'You do not have access to this section.'], 403);
        }

        $definition = $section === 'certificates'
            && in_array($user['role'], ['administrator', 'staff'], true)
                ? null
                : $this->resourceDefinition($section);
        $records = [];
        $fields = [];
        $columns = [];
        $key = '';
        $canManage = false;
        $eligibilityRecords = [];

        if ($definition) {
            $columns = $definition['columns'];
            $key = $definition['key'];
            $canManage = $this->canManage($section)
                && in_array($section, ['users', 'trainees', 'programs', 'classes', 'schedules', 'enrollments', 'attendance', 'announcements', 'inquiries'], true);
            $records = $this->model->records($section, $user);
            if ($section === 'classes') {
                $requirementsByClass = [];
                foreach ($this->documentModel->documentRequirements() as $requirement) {
                    $requirementsByClass[(int) $requirement['class_id']][] = $requirement['required_document_name'];
                }
                foreach ($records as &$record) {
                    $record['requirements'] = $requirementsByClass[(int) $record['class_id']] ?? [];
                }
                unset($record);
            }
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
        } elseif ($section === 'assessments' && in_array($user['role'], ['administrator', 'staff', 'instructor'], true)) {
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
                $eligibilityRecords = $this->model->rows('SELECT e.*, p.program_name, cl.class_name FROM certificate_eligibility e JOIN training_programs p ON p.program_id = e.program_id JOIN training_classes cl ON cl.class_id = e.class_id WHERE e.trainee_id = ? ORDER BY e.updated_at DESC', [(int) $trainee['trainee_id']]);
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
            $this->jsonResponse([
                'title' => 'Certificate Settings',
                'section' => 'certificate_config',
                'configs' => $configs,
                'certificate_template' => $template,
                'certificate_template_versions' => $this->certificateModel->getTemplateVersions(),
                'document_requirements' => $this->documentModel->documentRequirements(),
                'signatories' => $this->certificateModel->signatories(),
                'signatory_accounts' => $this->certificateModel->signatoryAccounts(),
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
            $this->jsonResponse([
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
            $this->jsonResponse(['message' => 'This section is not available in the Vue interface.'], 404);
        }

        foreach ($fields as &$field) {
            if (!empty($field['options'])) {
                $field['choices'] = $this->model->options($field['options'], $user);
            }
        }
        unset($field);

        $this->jsonResponse([
            'title' => $definition['title'] ?? ucfirst(str_replace('_', ' ', $section)),
            'section' => $section,
            'records' => $records,
            'eligibility' => $eligibilityRecords,
            'columns' => $columns,
            'primary_key' => $key,
            'fields' => $fields,
            'can_manage' => $section === 'documents' ? false : $canManage,
            'can_create' => $canManage && !in_array($section, ['activity', 'documents'], true)
                && !($section === 'inquiries' && $user['role'] !== 'trainee'),
            'can_upload' => $section === 'documents' && in_array($user['role'], ['administrator', 'staff', 'instructor', 'trainee'], true),
            'can_manage_documents' => $section === 'documents' && in_array($user['role'], ['administrator', 'staff'], true),
            'can_review_documents' => $section === 'documents' && in_array($user['role'], ['administrator', 'staff', 'instructor'], true),
            'can_manage_requirements' => $section === 'documents' && in_array($user['role'], ['administrator', 'staff', 'instructor'], true),
            'can_download_documents' => $section === 'documents',
            'document_requirements' => $section === 'documents'
                ? (in_array($user['role'], ['administrator', 'staff', 'instructor'], true)
                    ? $this->documentModel->documentRequirements()
                    : ($user['role'] === 'trainee'
                        ? $this->documentModel->requirementsForTrainee((int) $user['user_id'])
                        : []))
                : [],
            'trainee_classes' => $section === 'documents' && $user['role'] === 'trainee'
                ? $this->documentModel->classesForTrainee((int) $user['user_id'])
                : [],
            'can_assess' => $section === 'assessments' && in_array($user['role'], ['administrator', 'staff', 'instructor'], true),
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

    private function authenticatedApiUser()
    {
        if ($this->user) {
            return $this->user;
        }

        $token = $this->apiLibrary()->get_jwt_data();
        $user = is_array($token) ? ($token['custom_data'] ?? []) : [];
        if (!isset($user['user_id'], $user['role'])) {
            return null;
        }
        $this->user = $user;
        return $user;
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

    public function apiAttendanceClasses()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }
        if (!$this->requireAttendanceStaff()) {
            return;
        }

        $this->jsonResponse([
            'classes' => $this->attendanceModel->attendanceClasses($this->user),
        ]);
    }

    public function apiAttendanceSchedules($classId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }
        if (!$this->requireAttendanceStaff()) {
            return;
        }

        $classId = filter_var($classId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($classId === false) {
            $this->jsonResponse(['message' => 'Choose a valid class.', 'errors' => ['class_id' => 'Choose a valid class.']], 422);
        }

        $class = $this->attendanceModel->attendanceClass((int) $classId, $this->user);
        if (!$class) {
            $this->jsonResponse(['message' => 'That class is not available to your account.'], 404);
        }

        $this->jsonResponse([
            'schedules' => $this->attendanceModel->attendanceSchedules((int) $classId),
        ]);
    }

    public function apiTodayAttendance()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }
        if (!$this->requireAttendanceStaff()) {
            return;
        }

        $this->jsonResponse([
            'records' => $this->attendanceModel->todayAttendance($this->user),
        ]);
    }

    private function requireAttendanceStaff()
    {
        if (!$this->requireUser()) {
            return false;
        }
        if (!in_array($this->user['role'] ?? '', ['administrator', 'staff', 'instructor'], true)) {
            $this->jsonResponse(['message' => 'You are not allowed to manage attendance.'], 403);
        }
        return true;
    }

    public function apiExportReport($period)
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Attendance.php';
        $attendance = new Attendance();
        $attendance->before_action();
        $attendance->exportReport($period);
    }

    public function apiReports()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }
        $user = $this->authenticatedApiUser();
        if (!$user) {
            $this->jsonResponse(['message' => 'Sign in to continue.'], 401);
        }
        if (!in_array($user['role'], ['administrator', 'staff'], true)) {
            $this->jsonResponse(['message' => 'Reports are available to administrator and staff accounts.'], 403);
        }
        require_once APP_DIR . 'models/Reports_model.php';
        $reportsModel = new Reports_model($this->db);
        $this->jsonResponse([
            'reports' => $reportsModel->availableReports(),
            'programs' => $this->recordModel->options('programs', $user),
            'classes' => $this->recordModel->options('classes', $user),
        ]);
    }

    public function apiGenerateReport()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }
        $user = $this->authenticatedApiUser();
        if (!$user) {
            $this->jsonResponse(['message' => 'Sign in to continue.'], 401);
        }
        if (!in_array($user['role'], ['administrator', 'staff'], true)) {
            $this->jsonResponse(['message' => 'Reports are available to administrator and staff accounts.'], 403);
        }
        if (!$this->validCsrf()) {
            $this->jsonResponse(['message' => 'Invalid CSRF token.'], 403);
        }
        require_once APP_DIR . 'models/Reports_model.php';
        $reportsModel = new Reports_model($this->db);
        $reportType = trim((string) $this->input('report_type'));
        $format = trim((string) $this->input('format'));
        $filters = [
            'start_date' => trim((string) $this->input('start_date')),
            'end_date' => trim((string) $this->input('end_date')),
            'program_id' => trim((string) $this->input('program_id')),
            'class_id' => trim((string) $this->input('class_id')),
            'status' => trim((string) $this->input('status')),
            'fee_kind' => trim((string) $this->input('fee_kind')),
            'method' => trim((string) $this->input('method')),
        ];
        $availableReports = $reportsModel->availableReports();
        if (!isset($availableReports[$reportType])) {
            $this->jsonResponse(['message' => 'Invalid report type.'], 400);
        }
        if (!in_array($format, $availableReports[$reportType]['formats'])) {
            $this->jsonResponse(['message' => 'Invalid format for this report type.'], 400);
        }
        $data = $reportsModel->getReportData($reportType, $filters);
        if ($format === 'pdf') {
            $this->apiGeneratePDF($reportType, $data, $filters, $availableReports);
        } else {
            $this->apiGenerateCSV($reportType, $data, $filters);
        }
    }

    private function apiGeneratePDF($reportType, $data, array $filters, array $availableReports)
    {
        $pdf = SmartTrackPdf::create('L');
        $pdf->setTitle('SmartTrack ' . $availableReports[$reportType]['name']);
        $pdf->setAuthor('LSI SmartTrack');
        $pdf->setCreator('LSI SmartTrack System');
        $pdf->addPage(['format' => 'A4', 'orientation' => 'L']);
        $html = '<h1 style="color: #173a60;">' . htmlspecialchars($availableReports[$reportType]['name'], ENT_QUOTES, 'UTF-8') . '</h1>';
        $html .= '<p style="color: #5d6f82; font-size: 12px;">';
        $html .= 'Report Period: ' . htmlspecialchars($filters['start_date'] . ' to ' . $filters['end_date'], ENT_QUOTES, 'UTF-8') . '<br>';
        $html .= 'Generated: ' . date('Y-m-d H:i:s');
        $html .= '</p>';
        if (!empty($data)) {
            $html .= '<table border="1" cellpadding="8" cellspacing="0" style="width: 100%; border-collapse: collapse; font-size: 10px;">';
            $html .= '<thead style="background-color: #173a60; color: white;"><tr>';
            $headers = array_keys($data[0]);
            foreach ($headers as $header) {
                $html .= '<th style="padding: 8px; text-align: left;">' . htmlspecialchars(str_replace('_', ' ', $header), ENT_QUOTES, 'UTF-8') . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($data as $row) {
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $html .= '<td style="padding: 6px; border-bottom: 1px solid #e1e8f1;">' . htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
            $html .= '<p style="font-size: 10px; color: #5d6f82; margin-top: 20px;">Total records: ' . count($data) . '</p>';
        } else {
            $html .= '<p style="color: #c4372c;">No data found for the selected filters.</p>';
        }
        $filename = 'smarttrack-' . $reportType . '-report-' . $filters['start_date'] . '-to-' . $filters['end_date'] . '.pdf';
        $pdf->setPDFFilename($filename);
        $pdf->addHTMLCell(html: $html, posx: 15, posy: 15, width: 267);
        $pdf->downloadPDF($pdf->getOutPDFString());
        exit;
    }

    private function apiGenerateCSV($reportType, $data, array $filters)
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="smarttrack-' . $reportType . '-report-' . $filters['start_date'] . '-to-' . $filters['end_date'] . '.csv"');
        $output = fopen('php://output', 'w');
        $safeCell = static function ($value) {
            $value = (string) $value;
            return preg_match('/^\s*[=+\-@]/u', $value) ? '"' . $value . '"' : $value;
        };
        if (!empty($data)) {
            $headers = array_keys($data[0]);
            fputcsv($output, array_map('ucwords', array_map(function($h) { return str_replace('_', ' ', $h); }, $headers)));
            foreach ($data as $row) {
                fputcsv($output, array_map($safeCell, $row));
            }
        } else {
            fputcsv($output, ['No data found for the selected filters.']);
        }
        fclose($output);
        exit;
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
        try {
            require_once APP_DIR . 'controllers/Certificates.php';
            $certificates = new Certificates();
            $certificates->before_action();
            $certificates->certificateConfig();
        } catch (Throwable $error) {
            error_log('Certificate configuration API failed: ' . $error->getMessage());
            $this->jsonResponse([
                'message' => 'The certificate settings could not be saved. Check the server error log for details.',
            ], 500);
        }
    }

    public function apiResubmitCertificateForSigning($id)
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Certificates.php';
        $certificates = new Certificates();
        $certificates->before_action();
        $certificates->resubmitCertificateForSigning($id);
    }

    public function apiActivateCertificateTemplate($id)
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Certificates.php';
        $certificates = new Certificates();
        $certificates->before_action();
        $certificates->activateCertificateTemplate($id);
    }

    public function apiDeleteCertificateTemplate($id)
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Certificates.php';
        $certificates = new Certificates();
        $certificates->before_action();
        $certificates->deleteCertificateTemplate($id);
    }

    public function apiCertificatesToSign()
    {
        if (!$this->requireUser() || !in_array($this->user['role'] ?? '', ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to sign certificates.');
        }
        return $this->jsonResponse([
            'certificates' => $this->certificateModel->signatureQueue((int) $this->user['user_id']),
        ]);
    }

    public function apiCertificateSignPreview($id)
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Certificates.php';
        $certificates = new Certificates();
        $certificates->before_action();
        $certificates->previewCertificateForSigning($id);
    }

    public function apiSignCertificate($id)
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Certificates.php';
        $certificates = new Certificates();
        $certificates->before_action();
        $certificates->signCertificate($id);
    }

    public function apiRejectCertificateSignature($id)
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Certificates.php';
        $certificates = new Certificates();
        $certificates->before_action();
        $certificates->rejectCertificateSignature($id);
    }

    public function apiSaveCertificateSignatory()
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Certificates.php';
        $certificates = new Certificates();
        $certificates->before_action();
        $certificates->saveCertificateSignatory();
    }

    public function apiDeleteCertificateSignatory($id)
    {
        $this->apiRequest = true;
        require_once APP_DIR . 'controllers/Certificates.php';
        $certificates = new Certificates();
        $certificates->before_action();
        $certificates->deleteCertificateSignatory($id);
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
