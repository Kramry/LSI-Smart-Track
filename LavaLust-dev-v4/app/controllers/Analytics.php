<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'controllers/Base.php';
require_once APP_DIR . 'models/Analytics_model.php';

class Analytics extends Base
{
    private $analyticsModel;

    public function before_action()
    {
        parent::before_action();
        $this->analyticsModel = new Analytics_model($this->db);
    }

    public function index()
    {
        if (!$this->requireUser() || !$this->canViewAnalytics($this->user)) {
            return $this->deny('Analytics is available to administrator, staff, and instructor accounts.');
        }
        $filters = $this->validatedFilters($_GET);
        if (!$filters['success']) {
            return $this->actionError($filters['message'], 'manage/analytics');
        }
        if (!empty($filters['filters']['class_id'])
            && !$this->analyticsModel->classIsVisible((int) $filters['filters']['class_id'], $this->user)) {
            return $this->actionError('The selected class is not available to your account.', 'manage/analytics');
        }
        $data = [
            'title' => 'Analytics',
            'user' => $this->user,
            'navigation' => $this->navigationForUser(),
            'app_url' => $this->url(''),
            'csrf' => $this->session->userdata('csrf'),
            'filters' => $filters['filters'],
            'classes' => $this->analyticsModel->classOptions($this->user),
        ];
        $this->call->view('analytics', $data);
    }

    public function data()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }
        $user = $this->user;
        if (!$user) {
            $token = $this->apiLibrary()->require_jwt();
            $user = $token['custom_data'] ?? [];
        }
        if (!$this->canViewAnalytics($user)) {
            return $this->jsonResponse(['message' => 'Analytics is available to administrator, staff, and instructor accounts.'], 403);
        }

        $result = $this->validatedFilters($_GET);
        if (!$result['success']) {
            return $this->jsonResponse(['message' => $result['message']], 422);
        }
        if (!empty($result['filters']['class_id'])
            && !$this->analyticsModel->classIsVisible((int) $result['filters']['class_id'], $user)) {
            return $this->jsonResponse(['message' => 'The selected class is not available to your account.'], 422);
        }
        return $this->jsonResponse($this->analyticsModel->analytics($user, $result['filters']));
    }

    public function aiInsights()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }
        $user = $this->user;
        if (!$user) {
            $token = $this->apiLibrary()->require_jwt();
            $user = $token['custom_data'] ?? [];
        }
        if (!$this->canViewAnalytics($user)) {
            return $this->jsonResponse(['message' => 'Analytics is available to administrator, staff, and instructor accounts.'], 403);
        }

        $result = $this->validatedFilters($_GET);
        if (!$result['success']) {
            return $this->jsonResponse(['message' => $result['message']], 422);
        }
        try {
            require_once APP_DIR . 'libraries/GeminiAnalyticsService.php';
            $analytics = $this->analyticsModel->analytics($user, $result['filters']);
            $service = new GeminiAnalyticsService();
            $insights = $service->cachedInsight($analytics);
            return $this->jsonResponse(['insights' => $insights]);
        } catch (InvalidArgumentException $error) {
            return $this->jsonResponse(['message' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Gemini analytics cache read failed: ' . $error->getMessage());
            if ($error instanceof GeminiAnalyticsConfigurationException && trim((string) getenv('GEMINI_API_KEY')) === '') {
                return $this->jsonResponse(['insights' => null]);
            }
            return $this->geminiErrorResponse($error);
        }
    }

    public function generateAiInsights()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }
        $user = $this->user;
        if (!$user) {
            $token = $this->apiLibrary()->require_jwt();
            $user = $token['custom_data'] ?? [];
        }
        if (!$this->canViewAnalytics($user)) {
            return $this->jsonResponse(['message' => 'Analytics is available to administrator, staff, and instructor accounts.'], 403);
        }

        $result = $this->validatedFilters($_GET);
        if (!$result['success']) {
            return $this->jsonResponse(['message' => $result['message']], 422);
        }

        require_once APP_DIR . 'libraries/GeminiAnalyticsService.php';
        try {
            $key = 'gemini-generation:' . (int) $user['user_id'] . ':' . date('YmdHi');
            $count = (int) (@file_get_contents($this->geminiRateLimitFile($key)) ?: 0);
            if ($count >= 5) {
                return $this->jsonResponse(['message' => 'Gemini insight generation limit reached. Try again in a minute.'], 429);
            }
            @file_put_contents($this->geminiRateLimitFile($key), (string) ($count + 1));

            $analytics = $this->analyticsModel->analytics($user, $result['filters']);
            $insights = (new GeminiAnalyticsService())->generate($analytics);
            return $this->jsonResponse(['insights' => $insights]);
        } catch (InvalidArgumentException $error) {
            return $this->jsonResponse(['message' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Gemini analytics request failed: ' . $error->getMessage());
            return $this->geminiErrorResponse($error);
        }
    }

    private function geminiErrorResponse(Throwable $error)
    {
        $status = $error instanceof GeminiAnalyticsConfigurationException ? 503 : 502;
        if ($error instanceof GeminiAnalyticsConfigurationException && getenv('GEMINI_API_KEY') === false) {
            return $this->jsonResponse(['message' => 'AI insights are not configured on this server.'], $status);
        }
        return $this->jsonResponse(['message' => 'Gemini insights could not be generated. Please try again.'], $status);
    }

    private function geminiRateLimitFile($key)
    {
        $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'gemini-rate-limit';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        return $directory . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.txt';
    }

    private function canViewAnalytics(array $user)
    {
        return in_array($user['role'] ?? '', ['administrator', 'staff', 'instructor'], true);
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
