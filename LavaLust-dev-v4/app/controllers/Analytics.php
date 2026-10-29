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
        $this->api->require_method('GET');
        $user = $this->user;
        if (!$user) {
            $token = $this->api->require_jwt();
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
