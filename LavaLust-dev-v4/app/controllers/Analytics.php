<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'controllers/Base.php';

class AnalyticsServiceUnavailableException extends RuntimeException
{
}

class Analytics extends Base
{
    public function __construct()
    {
        parent::__construct();
        $this->analyticsModel = $this->modelRegistry->loadOptional($this->db, 'Analytics_model');
        if (!$this->analyticsModel instanceof Analytics_model) {
            throw new RuntimeException('Analytics model initialization failed.');
        }
    }

    public function index()
    {
        if (!$this->requireUser() || !$this->canViewAnalytics($this->user)) {
            return $this->deny('Analytics is available to administrator, staff, and instructor accounts.');
        }
        $filters = $this->validatedFilters($this->queryParams());
        if (!$filters['success']) {
            return $this->actionError($filters['message'], 'manage/analytics');
        }
        if (!empty($filters['filters']['class_id'])
            && !$this->classIsVisible((int) $filters['filters']['class_id'], $this->user)) {
            return $this->actionError('The selected class is not available to your account.', 'manage/analytics');
        }
        $data = [
            'title' => 'Analytics',
            'user' => $this->user,
            'navigation' => $this->navigationForUser(),
            'app_url' => $this->url(''),
            'csrf' => $this->session->userdata('csrf'),
            'filters' => $filters['filters'],
            'classes' => $this->classOptions($this->user),
        ];
        $this->call->view('analytics', $data);
    }

    public function data()
    {
        $this->requireRequestMethod('GET');
        if (!$this->requireAnalyticsApiViewer()) {
            return;
        }
        $user = $this->user;
        $filters = $this->analyticsFilters();
        if ($filters === null) {
            return;
        }
        try {
            return $this->jsonResponse($this->analyticsModel->analytics($user, $filters));
        } catch (AnalyticsAuthorizationException $error) {
            return $this->jsonResponse(['message' => 'The selected class is not available to your account.'], 403);
        }
    }

    public function aiInsights()
    {
        $this->requireRequestMethod('GET');
        if (!$this->requireAnalyticsApiViewer()) {
            return;
        }
        $user = $this->user;
        $filters = $this->analyticsFilters();
        if ($filters === null) {
            return;
        }
        try {
            $analytics = $this->analyticsModel->analytics($user, $filters);
        } catch (AnalyticsAuthorizationException $error) {
            return $this->jsonResponse(['message' => 'The selected class is not available to your account.'], 403);
        }

        try {
            $service = $this->geminiService();
            $insights = $service->cachedInsight($analytics);
            if ($insights === null) {
                $service->assertConfigured();
            }
            return $this->jsonResponse(['insights' => $insights]);
        } catch (InvalidArgumentException $error) {
            return $this->jsonResponse(['message' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Gemini analytics cache read failed: ' . get_class($error) . ': ' . $error->getMessage());
            return $this->geminiErrorResponse($error);
        }
    }

    public function generateAiInsights()
    {
        $this->requireRequestMethod('POST');
        if (!$this->requireAnalyticsApiViewer(true)) {
            return;
        }
        $user = $this->user;
        $filters = $this->analyticsFilters();
        if ($filters === null) {
            return;
        }

        try {
            $analytics = $this->analyticsModel->analytics($user, $filters);
        } catch (AnalyticsAuthorizationException $error) {
            return $this->jsonResponse(['message' => 'The selected class is not available to your account.'], 403);
        }

        try {
            $service = $this->geminiService();
            $service->assertConfigured();
        } catch (Throwable $error) {
            error_log('Gemini analytics configuration failed: ' . get_class($error) . ': ' . $error->getMessage());
            return $this->geminiErrorResponse($error);
        }

        if (!$this->analyticsModel->hasGeminiGenerationLimitStorage()) {
            error_log('Gemini analytics generation limit storage is not installed.');
            return $this->jsonResponse(['message' => 'AI insights are temporarily unavailable.'], 503);
        }
        $rateLimit = $this->analyticsModel->consumeGeminiGenerationSlot((int) $user['user_id']);
        if (!$rateLimit['allowed']) {
            header('Retry-After: ' . $rateLimit['retry_after']);
            return $this->jsonResponse([
                'message' => 'Gemini insight generation limit reached. Try again shortly.',
            ], 429);
        }

        try {
            $insights = $service->generate($analytics);
            return $this->jsonResponse(['insights' => $insights]);
        } catch (InvalidArgumentException $error) {
            return $this->jsonResponse(['message' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Gemini analytics request failed: ' . get_class($error) . ': ' . $error->getMessage());
            return $this->geminiErrorResponse($error);
        }
    }

    private function geminiErrorResponse(Throwable $error)
    {
        $configurationError = class_exists('GeminiAnalyticsConfigurationException', false)
            && $error instanceof GeminiAnalyticsConfigurationException;
        if ($configurationError) {
            return $this->jsonResponse(['message' => 'AI insights are not configured on this server.'], 503);
        }
        if ($error instanceof AnalyticsServiceUnavailableException) {
            return $this->jsonResponse(['message' => 'AI insights are temporarily unavailable.'], 503);
        }
        return $this->jsonResponse(['message' => 'Gemini insights could not be generated. Please try again.'], 502);
    }

    private function geminiService()
    {
        $serviceFile = APP_DIR . 'libraries/GeminiAnalyticsService.php';
        if (!is_file($serviceFile) || !is_readable($serviceFile)) {
            throw new AnalyticsServiceUnavailableException('Gemini analytics service file is unavailable.');
        }
        require_once $serviceFile;
        if (!class_exists('GeminiAnalyticsService', false)) {
            throw new AnalyticsServiceUnavailableException('Gemini analytics service class is unavailable.');
        }
        return new GeminiAnalyticsService();
    }

    private function requireAnalyticsApiViewer(bool $requireCsrf = false)
    {
        $bearerToken = $this->apiLibrary()->get_bearer_token();
        if ($bearerToken === null) {
            $sessionUser = $this->session->userdata('user');
            $userId = is_array($sessionUser) ? (int) ($sessionUser['user_id'] ?? 0) : 0;
            $account = $userId > 0 ? $this->db->select('user_id, full_name, username, role, status')
                ->from('users')->where('user_id', $userId)->limit(1)->get() : null;
            $this->user = $account && ($account['status'] ?? '') === 'active'
                ? [
                    'user_id' => (int) $account['user_id'],
                    'full_name' => $account['full_name'],
                    'username' => $account['username'],
                    'role' => $account['role'],
                ]
                : null;
        }

        if (!$this->user) {
            $this->jsonResponse(['message' => 'Sign in to continue.'], 401);
            return false;
        }
        if (!$this->canViewAnalytics($this->user)) {
            $this->jsonResponse([
                'message' => 'Analytics is available to administrator, staff, and instructor accounts.',
            ], 403);
            return false;
        }
        if ($requireCsrf && $bearerToken === null && !$this->validCsrf()) {
            $this->jsonResponse(['message' => 'Your session expired. Refresh and try again.'], 403);
            return false;
        }
        return true;
    }

    private function analyticsFilters()
    {
        $result = $this->validatedFilters($this->queryParams());
        if (!$result['success']) {
            $this->jsonResponse(['message' => $result['message']], 422);
            return null;
        }
        if (!empty($result['filters']['class_id'])
            && !$this->classIsVisible((int) $result['filters']['class_id'], $this->user)) {
            $this->jsonResponse(['message' => 'The selected class is not available to your account.'], 403);
            return null;
        }
        return $result['filters'];
    }

    private function canViewAnalytics(array $user)
    {
        return $this->inRoleGroup('teaching_staff', $user);
    }

    private function classOptions(array $user): array
    {
        $query = $this->db->select('class_id, class_name')->from('training_classes');
        if (($user['role'] ?? '') === 'instructor') {
            $query->where('instructor_id', (int) $user['user_id']);
        }
        return $query->order_by('class_name')->get_all();
    }

    private function classIsVisible($classId, array $user): bool
    {
        if (!$this->canViewAnalytics($user)
            || (!is_int($classId) && (!is_string($classId) || !ctype_digit($classId)))
            || (int) $classId < 1) {
            return false;
        }
        $query = $this->db->select('class_id')->from('training_classes')->where('class_id', (int) $classId);
        if (($user['role'] ?? '') === 'instructor') {
            $query->where('instructor_id', (int) $user['user_id']);
        }
        return (bool) $query->limit(1)->get();
    }


    private function validatedFilters(array $input)
    {
        $today = new DateTimeImmutable('today');
        $defaultStart = $today->modify('-29 days')->format('Y-m-d');
        $startDate = $this->dateFilter($input['start_date'] ?? $defaultStart);
        $endDate = $this->dateFilter($input['end_date'] ?? $today->format('Y-m-d'));
        if (!$startDate || !$endDate || $startDate > $endDate) {
            return ['success' => false, 'message' => 'Choose valid start and end dates, with the start date no later than the end date.'];
        }
        $start = new DateTimeImmutable($startDate);
        $end = new DateTimeImmutable($endDate);
        if ((int) $start->diff($end)->format('%a') > 365) {
            return ['success' => false, 'message' => 'The selected date range cannot exceed 366 days.'];
        }
        $rawClassId = $input['class_id'] ?? '';
        if (!is_scalar($rawClassId)) {
            return ['success' => false, 'message' => 'Choose a valid class.'];
        }
        $rawClassId = trim((string) $rawClassId);
        if ($rawClassId !== '' && (!ctype_digit($rawClassId) || (int) $rawClassId < 1)) {
            return ['success' => false, 'message' => 'Choose a valid class.'];
        }
        return [
            'success' => true,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'class_id' => $rawClassId === '' ? null : (int) $rawClassId,
            ],
        ];
    }

    private function dateFilter($value)
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = (string) $value;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))
            || $date->format('Y-m-d') !== $value) {
            return null;
        }
        return $value;
    }
}



