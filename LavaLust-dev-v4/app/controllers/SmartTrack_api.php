<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'controllers/Base.php';
require_once APP_DIR . 'controllers/ReportsQueries.php';
require_once APP_DIR . 'libraries/SmartTrackPdf.php';

class SmartTrack_api extends Base
{
    use ReportsQueries;

    private function notificationService()
    {
        require_once APP_DIR . 'libraries/NotificationService.php';
        return new NotificationService($this->db);
    }

    public function apiSession()
    {
        $this->requireRequestMethod('GET');
        return $this->jsonResponse([
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
        $this->requireRequestMethod('GET');
        if (!$this->requireUser() || !$this->inRoleGroup('administrative_staff')) {
            return;
        }
        $service = $this->notificationService();
        return $this->jsonResponse([
            'history' => $service->history([
                'channel' => is_scalar($this->queryParams()['channel'] ?? null) ? trim((string) $this->queryParams()['channel']) : '',
                'status' => is_scalar($this->queryParams()['status'] ?? null) ? trim((string) $this->queryParams()['status']) : '',
            ]),
            'settings' => $service->settings(),
            'ready' => $service->isReady(),
            'missing_tables' => $service->missingTables(),
        ]);
    }

    public function apiSendAnnouncement()
    {
        $this->requireRequestMethod('POST');
        if (!$this->requireUser() || $this->user['role'] !== 'administrator' || !$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'Only administrators can send notifications.'], 403);
        }
        $announcementId = (int) $this->input('announcement_id');
        $mode = trim((string) $this->input('recipient_mode'));
        $classId = (int) $this->input('class_id');
        $channels = $this->input('channels');
        $channels = is_array($channels) ? array_values(array_intersect($channels, ['email'])) : [];
        $traineeIds = $this->input('trainee_ids');
        $traineeIds = is_array($traineeIds)
            ? array_values(array_filter($traineeIds, 'is_scalar'))
            : [];
        if (!$announcementId || !$channels || !in_array($mode, ['all', 'batch', 'selected'], true)) {
            return $this->jsonResponse(['message' => 'Choose an announcement, recipients, and notification channel.'], 422);
        }
        $announcement = $this->recordModel->findRecordScoped('announcements', 'announcement_id', $announcementId, $this->user);
        if (!$announcement || $announcement['status'] !== 'published') {
            return $this->jsonResponse(['message' => 'Only published announcements can be sent.'], 422);
        }
        if ($mode === 'batch' && !$this->recordModel->findRecordScoped('training_classes', 'class_id', $classId, $this->user)) {
            return $this->jsonResponse(['message' => 'Choose a class available to your account.', 'errors' => ['class_id' => 'Choose a valid class.']], 422);
        }
        if ($mode === 'selected') {
            $traineeIds = array_values(array_unique(array_filter(array_map('intval', $traineeIds), static function ($id) {
                return $id > 0;
            })));
            if (!$traineeIds) {
                return $this->jsonResponse(['message' => 'Choose at least one recipient.', 'errors' => ['trainee_ids' => 'Choose at least one recipient.']], 422);
            }
            foreach ($traineeIds as $traineeId) {
                if (!$this->recordModel->findRecordScoped('trainees', 'trainee_id', $traineeId, $this->user)) {
                    return $this->jsonResponse(['message' => 'One or more recipients are not available to your account.'], 403);
                }
            }
        }
        $service = $this->notificationService();
        $recipients = $service->recipients($mode, $classId, $traineeIds);
        $queued = $service->queue($announcement, $recipients, $channels);
        $result = $service->processAnnouncement($announcementId);
        $this->recordModel->logActivity((int) $this->user['user_id'], 'Sent announcement notifications', $announcement['title']);
        return $this->jsonResponse([
            'message' => 'Notification processing completed.',
            'data' => ['recipients' => count($recipients), 'queued' => $queued['queued'], 'skipped' => $queued['skipped']] + $result,
        ]);
    }

    public function apiRetryNotification($id)
    {
        $this->requireRequestMethod('POST');
        if (!$this->requireUser() || $this->user['role'] !== 'administrator' || !$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'Only administrators can retry notifications.'], 403);
        }
        $notificationId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $notification = $notificationId === false ? null : $this->recordModel->failedNotification($notificationId);
        if (!$notification || !$this->recordModel->findRecordScoped('announcements', 'announcement_id', (int) $notification['announcement_id'], $this->user)) {
            return $this->jsonResponse(['message' => 'Only failed notifications can be retried.'], 404);
        }
        $announcement = $this->recordModel->announcementForNotifications((int) $notification['announcement_id']);
        if (!$announcement || $announcement['status'] !== 'published') {
            return $this->jsonResponse(['message' => 'Only notifications for published announcements can be retried.'], 422);
        }
        $this->recordModel->retryNotification((int) $id);
        $result = $this->notificationService()->processAnnouncement((int) $notification['announcement_id']);
        return $this->jsonResponse(['message' => 'Notification retry completed.', 'data' => $result]);
    }

    public function apiDeleteNotification($id)
    {
        $this->requireRequestMethod('DELETE');
        if (!$this->requireUser() || $this->user['role'] !== 'administrator' || !$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'Only administrators can delete notification history.'], 403);
        }
        $notification = $this->recordModel->notificationExists((int) $id);
        if (!$notification) {
            return $this->jsonResponse(['message' => 'Notification history record not found.'], 404);
        }
        $this->recordModel->deleteNotification((int) $id);
        return $this->jsonResponse(['message' => 'Notification history record deleted.']);
    }

    public function apiSaveNotificationSettings()
    {
        $this->requireRequestMethod('POST');
        if (!$this->requireUser() || $this->user['role'] !== 'administrator' || !$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'Only administrators can change notification settings.'], 403);
        }
        $email = $this->input('email_enabled') ? 1 : 0;
        $this->recordModel->saveNotificationSettings($email);
        return $this->jsonResponse(['message' => 'Notification settings saved.']);
    }

    public function apiTestNotification()
    {
        $this->requireRequestMethod('POST');
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
        return $this->jsonResponse(['message' => 'Provider accepted the test notification.']);
    }

    public function apiStorageStatus()
    {
        $this->requireRequestMethod('GET');
        if (!$this->requireUser()) {
            return;
        }
        $this->call->library('CloudStorage');
        $storage = new CloudStorage();
        $enabled = $storage->isEnabled();
        return $this->jsonResponse([
            'enabled' => $enabled,
            'provider' => $enabled ? (getenv('CLOUD_STORAGE_PROVIDER') ?: 'cloud') : 'local',
            'fallback_available' => true,
        ]);
    }

    public function apiLogin()
    {
        $this->requireRequestMethod('POST');
        if (!$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'Your session expired. Refresh and try again.'], 403);
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
            return $this->jsonResponse([
                'message' => 'Check the highlighted fields.',
                'errors' => $errors,
            ], 422);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $this->apiLibrary()->rate_limit('login-ip:' . hash('sha256', $ip), 30, 300);
        $account = $username !== '' ? $this->userModel->userByUsername($username) : null;

        if (!$account || $account['status'] !== 'active' || !password_verify($password, $account['password'])) {
            return $this->jsonResponse(['message' => 'The username or password is incorrect.'], 401);
        }

        $user = [
            'user_id' => (int) $account['user_id'],
            'full_name' => $account['full_name'],
            'username' => $account['username'],
            'role' => $account['role'],
        ];

        $this->session->regenerate_on_login(true);
        $csrf = bin2hex(random_bytes(32));
        $this->session->set_userdata('csrf', $csrf);
        $tokens = $this->apiLibrary()->issue_tokens([
            'id' => $user['user_id'],
            'role' => $user['role'],
        ]);
        $this->setRefreshTokenCookie($tokens['refresh_token']);

        return $this->jsonResponse([
            'ok' => true,
            'data' => [
                'authenticated' => true,
                'user' => $user,
                'csrf' => $csrf,
                'access_token' => $tokens['access_token'],
                'expires_in' => $tokens['expires_in'],
                'token_type' => $tokens['token_type'],
            ],
            'message' => '',
        ]);
    }

    public function apiRegistrationOptions()
    {
        $this->requireRequestMethod('GET');
        return $this->jsonResponse([
            'programs' => $this->recordModel->options('programs'),
        ]);
    }

    public function apiRegister()
    {
        $this->requireRequestMethod('POST');
        if (!$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'Your session expired. Refresh and try again.'], 403);
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
        $requestedRole = trim((string) $this->input('role'));
        $sex = trim((string) $this->input('sex'));
        $dateOfBirth = trim((string) $this->input('date_of_birth'));
        $address = trim((string) $this->input('address'));
        $contactNumber = trim((string) $this->input('contact_number'));
        $course = trim((string) $this->input('course'));
        $programId = trim((string) $this->input('program_id'));
        $password = (string) $this->input('password');
        $passwordConfirmation = (string) $this->input('password_confirmation');
        $errors = [];

        if ($name === '' || mb_strlen($name) > 150) {
            $errors['full_name'] = 'Enter your full name.';
        }
        if ($firstName === '' || mb_strlen($firstName) > 100) {
            $errors['first_name'] = 'Enter your first name.';
        }
        if ($lastName === '' || mb_strlen($lastName) > 100) {
            $errors['last_name'] = 'Enter your last name.';
        }
        if ($requestedRole !== '' && $requestedRole !== 'trainee') {
            $errors['role'] = 'Public registration is limited to trainee accounts.';
        }
        if (!in_array($sex, ['Male', 'Female', 'Other'], true)) {
            $errors['sex'] = 'Select your sex.';
        }
        if ($dateOfBirth !== '') {
            if (!$this->isValidApiDate($dateOfBirth)) {
                $errors['date_of_birth'] = 'Enter a valid date of birth.';
            }
        }
        if (!$this->isValidContactNumber($contactNumber)) {
            $errors['contact_number'] = 'Enter your contact number.';
        }
        if ($course === '' || mb_strlen($course) > 100) {
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
        } elseif ($programId !== '' && !$this->recordModel->isActiveProgram((int) $programId)) {
            $errors['program_id'] = 'Select a valid training program.';
        }
        if (mb_strlen($password) < 10 || mb_strlen($password) > 255) {
            $errors['password'] = 'Passwords must be 10-255 characters.';
        }
        if ($password !== $passwordConfirmation) {
            $errors['password_confirmation'] = 'Passwords do not match.';
        }
        if ($errors) {
            return $this->jsonResponse([
                'message' => 'Check the highlighted fields.',
                'errors' => $errors,
            ], 422);
        }
        if ($this->userModel->userByUsername($username)) {
            return $this->jsonResponse(['message' => 'That username is already taken.', 'errors' => ['username' => 'Choose another username.']], 422);
        }
        if ($this->userModel->userByEmail($email)) {
            return $this->jsonResponse(['message' => 'That email address is already registered.', 'errors' => ['email' => 'Use another email address.']], 422);
        }

        $this->userModel->createUser($name, $username, $password, 'trainee', $email);
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
        return $this->jsonResponse([
            'ok' => true,
            'data' => ['registered' => true],
            'message' => 'Account created successfully. Please sign in.',
        ]);
    }

    public function apiLogout()
    {
        $this->requireRequestMethod('POST');
        if (!$this->requireUser()) {
            return;
        }
        if (!$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'Your session expired. Refresh and try again.'], 403);
        }

        $refreshToken = (string) ($_COOKIE['smarttrack_refresh'] ?? '');
        if ($refreshToken !== '') {
            $this->apiLibrary()->revoke_refresh_token($refreshToken);
        }
        $this->clearRefreshTokenCookie();
        $this->session->sess_destroy();
        return $this->jsonResponse([
            'ok' => true,
            'data' => ['authenticated' => false],
            'message' => '',
        ]);
    }

    public function apiRefresh()
    {
        $api = $this->apiLibrary();
        $this->requireRequestMethod('POST');

        if (!$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'Your session expired. Refresh and try again.'], 403);
        }
        $body = $api->body();
        $refreshToken = (string) ($_COOKIE['smarttrack_refresh'] ?? ($body['refresh_token'] ?? ''));

        if (empty($refreshToken)) {
            return $this->jsonResponse(['message' => 'Refresh token is required.'], 401);
        }

        $segments = explode('.', $refreshToken);
        $refreshPayload = count($segments) === 3
            ? json_decode(base64_decode(strtr($segments[1], '-_', '+/')), true)
            : null;
        $subject = is_array($refreshPayload) ? ($refreshPayload['sub'] ?? null) : null;
        $account = is_scalar($subject) && ctype_digit((string) $subject)
            ? $this->userModel->apiUserById((int) $subject)
            : null;
        if (!$account || $account['status'] !== 'active') {
            $this->clearRefreshTokenCookie();
            return $this->jsonResponse(['message' => 'Invalid or expired refresh token.'], 401);
        }

        $result = $api->refresh_jwt($refreshToken);

        if (!$result) {
            $this->clearRefreshTokenCookie();
            return $this->jsonResponse(['message' => 'Invalid or expired refresh token.'], 401);
        }

        $this->setRefreshTokenCookie($result['refresh_token']);
        return $this->jsonResponse([
            'access_token' => $result['access_token'],
            'expires_in' => $result['expires_in'],
            'token_type' => $result['token_type'],
        ]);
    }

    public function apiDashboard()
    {
        $this->requireRequestMethod('GET');
        $user = $this->authenticatedApiUser();
        if (!$user) {
            return $this->jsonResponse(['message' => 'Sign in to continue.'], 401);
        }

        $data = [
            'stats' => $this->dashboardModel->dashboardStats($user),
            'attendance' => $this->dashboardModel->recentAttendance($user),
            'announcements' => $this->dashboardModel->announcements(),
        ];

        if ($this->inRoleGroup('teaching_staff', $user)) {
            $data['charts'] = $this->dashboardModel->dashboardChartData($user);
        }

        return $this->jsonResponse($data);
    }

    public function getTrainees()
    {
        $this->requireRequestMethod('GET');
        if (!$this->user) {
            return $this->jsonResponse(['message' => 'Sign in to view trainees.'], 401);
        }
        if (!$this->canAccess('trainees')) {
            return $this->jsonResponse(['message' => 'You do not have access to trainees.'], 403);
        }

        return $this->jsonResponse(['trainees' => $this->model->records('trainees', $this->user)]);
    }

    public function apiModule($section)
    {
        $this->requireRequestMethod('GET');
        $user = $this->authenticatedApiUser();
        if (!$user) {
            return $this->jsonResponse(['message' => 'Sign in to continue.'], 401);
        }

        $canAccessProfile = $section === 'profile';
        $canAccessMyCertificates = $section === 'my_certificates' && $user['role'] === 'trainee';
        if (!$this->canAccess($section) && !$canAccessProfile && !$canAccessMyCertificates) {
            return $this->jsonResponse(['message' => 'You do not have access to this section.'], 403);
        }

        try {
            return $this->buildApiModuleResponse($section, $user);
        } catch (Throwable $error) {
            error_log('API module load failed for section ' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $section) . ' (' . get_class($error) . '): ' . $error->getMessage());
            return $this->jsonResponse(['message' => 'The requested section could not be loaded.'], 500);
        }
    }

    private function buildApiModuleResponse($section, array $user)
    {
        $definition = $section === 'certificates'
            && $this->inRoleGroup('administrative_staff', $user)
                ? null
                : $this->resourceDefinition($section);
        $sectionData = $definition ? null : $this->apiModuleSectionData($section, $user);
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
        } elseif ($sectionData !== null) {
            $records = $sectionData['records'];
            $columns = $sectionData['columns'];
            $key = $sectionData['key'];
            $fields = $sectionData['fields'];
            $eligibilityRecords = $sectionData['eligibility'];
        } elseif ($section === 'certificate_config' && $this->inRoleGroup('administrative_staff', $user)) {
            return $this->certificateConfigModuleResponse();
        } elseif ($section === 'profile') {
            return $this->profileModuleResponse($user);
        } else {
            return $this->jsonResponse(['message' => 'This section is not available in the Vue interface.'], 404);
        }

        foreach ($fields as &$field) {
            if (!empty($field['options'])) {
                $field['choices'] = $this->model->options($field['options'], $user);
            }
        }
        unset($field);

        return $this->jsonResponse([
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
            'can_upload' => $section === 'documents' && $this->inRoleGroup('all_accounts', $user),
            'can_manage_documents' => $section === 'documents' && $this->inRoleGroup('administrative_staff', $user),
            'can_review_documents' => $section === 'documents' && $this->inRoleGroup('teaching_staff', $user),
            'can_manage_requirements' => $section === 'documents' && $this->inRoleGroup('teaching_staff', $user),
            'can_download_documents' => $section === 'documents',
            'document_requirements' => $section === 'documents'
                ? ($this->inRoleGroup('teaching_staff', $user)
                    ? $this->documentModel->documentRequirements()
                    : ($user['role'] === 'trainee'
                        ? $this->documentModel->requirementsForTrainee((int) $user['user_id'])
                        : []))
                : [],
            'trainee_classes' => $section === 'documents' && $user['role'] === 'trainee'
                ? $this->documentModel->classesForTrainee((int) $user['user_id'])
                : [],
            'can_assess' => $section === 'assessments' && $this->inRoleGroup('teaching_staff', $user),
            'can_review_certificates' => in_array($section, ['certificates', 'eligibility'], true)
                && $this->inRoleGroup('administrative_staff', $user),
            'can_configure_certificates' => $section === 'certificate_config' && $user['role'] === 'administrator',
            'can_scan_attendance' => $section === 'attendance' && $this->inRoleGroup('teaching_staff', $user),
            'attendance_options' => $section === 'attendance' ? [
                'classes' => $this->model->options('classes', $user),
                'schedules' => $this->attendanceModel->scheduleOptionsForToday($user),
            ] : [],
            'attendance_followups' => $section === 'attendance' && $this->inRoleGroup('teaching_staff', $user)
                ? $this->dashboardModel->absenceFollowUps($user)
                : [],
            'document_options' => $section === 'documents' ? [
                'trainees' => $this->model->options('trainees', $user),
                'classes' => $this->model->options('classes', $user),
            ] : [],
            'certificate_stats' => $section === 'certificates' ? $this->certificateModel->certificateStats() : null,
            'assessment_options' => $section === 'assessments' ? [
                'trainees' => $this->model->options('trainees', $user),
                'classes' => $this->assessmentModel->classOptions($user),
            ] : [],
        ]);
    }

    private function apiModuleSectionData(string $section, array $user): ?array
    {
        if ($section === 'certificates' && $this->inRoleGroup('administrative_staff', $user)) {
            $this->eligibilityModel->refreshEligibilityRecords();
            return [
                'records' => $this->certificateModel->getCertificates([]),
                'columns' => ['trainee_name', 'program_name', 'class_name', 'certificate_number', 'issue_date', 'status'],
                'key' => 'certificate_id',
                'fields' => [],
                'eligibility' => [],
            ];
        }
        if ($section === 'eligibility' && $this->inRoleGroup('administrative_staff', $user)) {
            return [
                'records' => $this->eligibilityModel->getEligibilityRecords([]),
                'columns' => ['trainee_name', 'trainee_number', 'program_name', 'class_name', 'attendance_percentage', 'documents_approved', 'assessments_completed', 'is_eligible', 'approval_status', 'ineligibility_reasons'],
                'key' => 'eligibility_id',
                'fields' => [],
                'eligibility' => [],
            ];
        }
        if ($section === 'assessments' && $this->inRoleGroup('teaching_staff', $user)) {
            return [
                'records' => $this->assessmentModel->assessmentRecords($user),
                'columns' => ['trainee_name', 'trainee_number', 'program_name', 'class_name', 'assessment_name', 'outcome', 'assessment_date', 'assessor_name', 'notes'],
                'key' => 'assessment_id',
                'fields' => [
                    ['name' => 'trainee_id', 'label' => 'Trainee', 'type' => 'options', 'options' => 'trainees', 'required' => true],
                    ['name' => 'class_id', 'label' => 'Class', 'type' => 'options', 'options' => 'classes', 'required' => true],
                    ['name' => 'assessment_name', 'label' => 'Assessment name', 'required' => true],
                    ['name' => 'outcome', 'label' => 'Outcome', 'type' => 'select', 'values' => ['pass', 'fail'], 'required' => true],
                    ['name' => 'assessment_date', 'label' => 'Assessment date', 'type' => 'date', 'required' => true],
                    ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
                ],
                'eligibility' => [],
            ];
        }
        if ($section === 'my_certificates' && $user['role'] === 'trainee') {
            $trainee = $this->certificateModel->traineeForUser((int) $user['user_id']);
            return [
                'records' => $trainee ? $this->certificateModel->traineeCertificates((int) $trainee['trainee_id']) : [],
                'columns' => ['certificate_number', 'program_name', 'class_name', 'issue_date', 'status'],
                'key' => 'certificate_id',
                'fields' => [],
                'eligibility' => $trainee ? $this->certificateModel->traineeEligibilityRecords((int) $trainee['trainee_id']) : [],
            ];
        }
        if ($section === 'documents') {
            return [
                'records' => $this->model->records('documents', $user),
                'columns' => ['trainee_name', 'document_name', 'upload_date', 'status', 'access_level'],
                'key' => 'document_id',
                'fields' => [],
                'eligibility' => [],
            ];
        }
        return null;
    }

    private function certificateConfigModuleResponse()
    {
        $configs = [];
        foreach ([
            'min_attendance_percentage',
            'require_documents',
            'require_assessments',
            'required_assessment_count',
        ] as $configKey) {
            $configs[$configKey] = $this->configModel->getConfig($configKey, '');
        }
        $template = $this->certificateModel->getLatestTemplate() ?: $this->certificateModel->legacyTemplate();

        return $this->jsonResponse([
            'title' => 'Certificate Settings',
            'section' => 'certificate_config',
            'configs' => $configs,
            'certificate_template' => $template,
            'certificate_template_versions' => $this->certificateModel->getTemplateVersions(),
            'document_requirements' => $this->documentModel->documentRequirements(),
            'signatories' => $this->certificateModel->signatories(),
            'signatory_accounts' => $this->certificateModel->signatoryAccounts(),
            'certificate_classes' => $this->recordModel->certificateClassOptions(),
            'can_configure_certificates' => true,
            'can_manage' => false,
            'records' => [],
            'fields' => [],
            'columns' => [],
            'primary_key' => '',
        ]);
    }

    private function profileModuleResponse(array $user)
    {
        $trainee = $user['role'] === 'trainee'
            ? $this->userModel->traineeForUser((int) $user['user_id'])
            : null;

        return $this->jsonResponse([
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
    }

    private function authenticatedApiUser()
    {
        return $this->apiUserFromToken();
    }

    private function isValidApiDate(string $value): bool
    {
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        $errors = DateTime::getLastErrors();
        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }

    private function isValidContactNumber(string $value): bool
    {
        return preg_match('/^[0-9\s\-\+\(\)]{7,20}$/', $value) === 1;
    }

    public function apiAttendanceClasses()
    {
        $this->requireRequestMethod('GET');
        if (!$this->requireAttendanceStaff()) {
            return;
        }

        return $this->jsonResponse([
            'classes' => $this->attendanceModel->attendanceClasses($this->user),
        ]);
    }

    public function apiAttendanceSchedules($classId)
    {
        $this->requireRequestMethod('GET');
        if (!$this->requireAttendanceStaff()) {
            return;
        }

        $classId = filter_var($classId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($classId === false) {
            return $this->jsonResponse(['message' => 'Choose a valid class.', 'errors' => ['class_id' => 'Choose a valid class.']], 422);
        }

        $class = $this->attendanceModel->attendanceClass((int) $classId, $this->user);
        if (!$class) {
            return $this->jsonResponse(['message' => 'That class is not available to your account.'], 404);
        }

        return $this->jsonResponse([
            'schedules' => $this->attendanceModel->attendanceSchedules((int) $classId),
        ]);
    }

    public function apiTodayAttendance()
    {
        $this->requireRequestMethod('GET');
        if (!$this->requireAttendanceStaff()) {
            return;
        }

        return $this->jsonResponse([
            'records' => $this->attendanceModel->todayAttendance($this->user),
        ]);
    }

    private function requireAttendanceStaff()
    {
        if (!$this->requireUser()) {
            return false;
        }
        if (!$this->inRoleGroup('teaching_staff')) {
            return $this->jsonResponse(['message' => 'You are not allowed to manage attendance.'], 403);
        }
        return true;
    }

    public function apiReports()
    {
        $this->requireRequestMethod('GET');
        $user = $this->authenticatedApiUser();
        if (!$user) {
            return $this->jsonResponse(['message' => 'Sign in to continue.'], 401);
        }
        if (!$this->inRoleGroup('administrative_staff', $user)) {
            return $this->jsonResponse(['message' => 'Reports are available to administrator and staff accounts.'], 403);
        }
        return $this->jsonResponse([
            'reports' => $this->availableReports(),
            'programs' => $this->recordModel->options('programs', $user),
            'classes' => $this->recordModel->options('classes', $user),
        ]);
    }

    public function apiGenerateReport()
    {
        $this->requireRequestMethod('POST');
        $user = $this->authenticatedApiUser();
        if (!$user) {
            return $this->jsonResponse(['message' => 'Sign in to continue.'], 401);
        }
        if (!$this->inRoleGroup('administrative_staff', $user)) {
            return $this->jsonResponse(['message' => 'Reports are available to administrator and staff accounts.'], 403);
        }
        if (!$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'Invalid CSRF token.'], 403);
        }
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
        foreach (['start_date', 'end_date'] as $dateField) {
            if ($filters[$dateField] !== '' && !$this->isValidApiDate($filters[$dateField])) {
                return $this->jsonResponse([
                    'message' => 'Enter valid report dates.',
                    'errors' => [$dateField => 'Use the YYYY-MM-DD format.'],
                ], 422);
            }
        }
        $filters['start_date'] = $filters['start_date'] ?: date('Y-m-01');
        $filters['end_date'] = $filters['end_date'] ?: date('Y-m-d');
        if ($filters['start_date'] > $filters['end_date']) {
            return $this->jsonResponse([
                'message' => 'The report start date must be before its end date.',
                'errors' => ['start_date' => 'Must not be later than the end date.'],
            ], 422);
        }
        $availableReports = $this->availableReports();
        if (!isset($availableReports[$reportType])) {
            return $this->jsonResponse(['message' => 'Invalid report type.'], 400);
        }
        if (!in_array($format, $availableReports[$reportType]['formats'])) {
            return $this->jsonResponse(['message' => 'Invalid format for this report type.'], 400);
        }
        $data = $this->getReportData($reportType, $filters);
        if ($format === 'pdf') {
            $this->apiGeneratePDF($reportType, $data, $filters, $availableReports);
        } else {
            $this->apiGenerateCSV($reportType, $data, $filters);
        }
    }

    private function apiGeneratePDF($reportType, $data, array $filters, array $availableReports)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $pdf = SmartTrackPdf::create('L');
        $pdf->setTitle('SmartTrack ' . $availableReports[$reportType]['name']);
        $pdf->setAuthor('LSI SmartTrack');
        $pdf->setCreator('LSI SmartTrack System');
        $html = '<h1 style="color: #173a60;">' . htmlspecialchars($availableReports[$reportType]['name'], ENT_QUOTES, 'UTF-8') . '</h1>';
        $html .= '<p style="color: #5d6f82; font-size: 12px;">';
        $html .= 'Report Period: ' . htmlspecialchars($filters['start_date'] . ' to ' . $filters['end_date'], ENT_QUOTES, 'UTF-8') . '<br>';
        $html .= 'Generated: ' . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
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
        $filename = 'smarttrack-' . $reportType . '-report-' . bin2hex(random_bytes(8)) . '.pdf';
        $pdf->setPDFFilename($filename);
        $pdf->addHTMLCell(html: $html, posx: 15, posy: 15);
        $pdf->downloadPDF($pdf->getOutPDFString());
        exit;
    }

    private function apiGenerateCSV($reportType, $data, array $filters)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

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

    public function apiCertificatesToSign()
    {
        $this->requireRequestMethod('GET');
        if (!$this->requireUser() || !$this->inRoleGroup('administrative_staff')) {
            return $this->deny('You are not allowed to sign certificates.');
        }
        return $this->jsonResponse([
            'certificates' => $this->certificateModel->signatureQueue((int) $this->user['user_id']),
        ]);
    }

}


