<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Base extends Controller
{
    protected ?Base_model $model = null;
    protected ?array $user = null;
    protected bool $apiRequest = false;
    protected ?object $db = null;
    protected ?object $session = null;
    protected ?object $api = null;
    protected ?array $requestData = null;
    protected ?Config_model $configModel = null;
    protected ?Eligibility_model $eligibilityModel = null;
    protected ?Certificate_model $certificateModel = null;
    protected ?Payment_model $paymentModel = null;
    protected ?User_model $userModel = null;
    protected ?Record_model $recordModel = null;
    protected ?Attendance_model $attendanceModel = null;
    protected ?Document_model $documentModel = null;
    protected ?Assessment_model $assessmentModel = null;
    protected ?Dashboard_model $dashboardModel = null;
    protected ?Classes_model $classesModel = null;
    protected ?Reports_model $reportsModel = null;
    protected ?Analytics_model $analyticsModel = null;
    protected ?SmartTrackModelRegistry $modelRegistry = null;
    protected ?SmartTrackAuth $auth = null;
    protected ?SmartTrackPermissions $permissions = null;

    public function before_action()
    {
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $this->apiRequest = is_string($requestPath)
            && preg_match('#(?:^|/)api(?:/|$)#', $requestPath) === 1;

        $this->call->library([
            'SmartTrackAuth',
            'SmartTrackPermissions',
            'SmartTrackModelRegistry',
            'SmartTrackModuleCatalog',
            'SmartTrackViewContext',
            'SmartTrackFilePath',
        ]);

        $this->call->helper('url');
        $this->db = $this->call->database();
        $this->session = $this->call->library('session');

        $this->modelRegistry = $this->call->library('SmartTrackModelRegistry');
        $apiModuleMatch = [];
        $apiModuleSection = is_string($requestPath)
            && preg_match('#(?:^|/)api/modules/([A-Za-z0-9_-]+)$#', $requestPath, $apiModuleMatch) === 1
                ? $apiModuleMatch[1]
                : null;
        try {
            $models = $apiModuleSection !== null
                ? $this->modelRegistry->loadForApiModule($this->db, $apiModuleSection)
                : $this->modelRegistry->load($this->db);
        } catch (Throwable $error) {
            error_log('SmartTrack model loading failed (' . get_class($error) . '): ' . $error->getMessage());
            if ($apiModuleSection !== null) {
                $this->jsonResponse(['message' => 'The requested section is temporarily unavailable.'], 500);
            }
            throw $error;
        }
        $this->configModel = $models['configModel'];
        $this->eligibilityModel = $models['eligibilityModel'];
        $this->certificateModel = $models['certificateModel'];
        $this->paymentModel = $models['paymentModel'];
        $this->userModel = $models['userModel'];
        $this->recordModel = $models['recordModel'];
        $this->attendanceModel = $models['attendanceModel'];
        $this->documentModel = $models['documentModel'];
        $this->assessmentModel = $models['assessmentModel'];
        $this->dashboardModel = $models['dashboardModel'];
        $this->model = $models['model'];
        $this->auth = $this->call->library('SmartTrackAuth');
        $this->permissions = $this->call->library('SmartTrackPermissions');

        $this->user = $this->session->userdata('user');
        if ($this->apiRequest) {
            $this->user = $this->apiUserFromToken();
        }

        if (!$this->session->userdata('csrf')) {
            $this->session->set_userdata('csrf', bin2hex(random_bytes(32)));
        }
    }

    protected function apiLibrary()
    {
        if ($this->api === null) {
            $this->api = $this->call->library('api');
        }
        return $this->api;
    }

    protected function render($page, array $data = [])
    {
        $data = SmartTrackViewContext::prepare(
            $page,
            $data,
            $this->user,
            $this->session,
            $this->userModel->userCount(),
            $this->url(''),
            $this->permissions
        );
        $this->call->view(SmartTrackViewContext::viewName($page), $data);
    }

    protected function input($key)
    {
        $data = $this->requestData();
        return $data[$key] ?? '';
    }

    protected function queryParams()
    {
        return $this->apiRequest
            ? $this->apiLibrary()->get_query_params(false)
            : $_GET;
    }

    protected function requestData()
    {
        if ($this->requestData !== null) {
            return $this->requestData;
        }

        if ($this->apiRequest) {
            $this->requestData = $this->apiLibrary()->body(false);
            return $this->requestData;
        }

        if ($_POST) {
            $this->requestData = $_POST;
            return $this->requestData;
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $body = file_get_contents('php://input');
        if (stripos($contentType, 'application/json') !== false) {
            $decoded = json_decode($body, true);
            $this->requestData = is_array($decoded) ? $decoded : [];
            return $this->requestData;
        }

        parse_str($body, $formData);
        $this->requestData = is_array($formData) ? $formData : [];
        return $this->requestData;
    }

    protected function jsonResponse(array $data, $status = 200)
    {
        if (!array_key_exists('ok', $data)) {
            if ($status >= 400) {
                $response = [
                    'ok' => false,
                    'message' => (string) ($data['message'] ?? 'The request could not be completed.'),
                    'errors' => isset($data['errors']) && is_array($data['errors']) ? $data['errors'] : [],
                ];
                $data = $response;
            } else {
                $data = [
                    'ok' => true,
                    'data' => $data,
                    'message' => '',
                ];
            }
        } elseif ($data['ok'] === false && (!isset($data['errors']) || !is_array($data['errors']))) {
            $data['errors'] = [];
        }

        $this->apiLibrary()->respond($data, (int) $status);
        exit;
    }

    protected function actionError($message, $redirectPath, $status = 422)
    {
        if ($this->apiRequest) {
            return $this->jsonResponse(['message' => $message, 'errors' => []], $status);
        }
        $this->notice($message);
        redirect($this->url($redirectPath));
        exit;
    }

    protected function validCsrf()
    {
        $submitted = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $this->input('_csrf'));
        $stored = (string) $this->session->userdata('csrf');

        return $stored !== '' && $submitted !== '' && hash_equals($stored, $submitted);
    }

    protected function requireUser()
    {
        if ($this->apiRequest) {
            $this->user = $this->apiUserFromToken();
        }
        if ($this->user) {
            return true;
        }
        if ($this->apiRequest) {
            return $this->jsonResponse(['message' => 'Sign in to continue.', 'errors' => []], 401);
        }
        redirect($this->url(''));
        exit;
        return false;
    }

    protected function apiUserFromToken()
    {
        return $this->auth->resolveApiUser($this->apiLibrary(), $this->userModel);
    }

    protected function setRefreshTokenCookie($token)
    {
        $this->auth->setRefreshTokenCookie($token);
    }

    protected function clearRefreshTokenCookie()
    {
        $this->auth->clearRefreshTokenCookie();
    }

    protected function canAccess($section)
    {
        return $this->permissions->canAccess($this->user['role'] ?? '', $section);
    }

    protected function canManage($section)
    {
        return $this->permissions->canManage($this->user['role'] ?? '', $section);
    }

    protected function inRoleGroup($group, ?array $user = null)
    {
        return $this->permissions->inRoleGroup(($user ?? $this->user)['role'] ?? '', $group);
    }

    protected function recordIsVisible($section, array $record, array $records, $key)
    {
        if ($this->inRoleGroup('administrative_staff')) {
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
            return $this->jsonResponse(['message' => $message, 'errors' => []], 403);
        }
        $this->notice($message);
        redirect($this->url('dashboard'));
        exit;
    }

    protected function url($path)
    {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
        return ($base ? $base : '') . '/' . ltrim($path, '/');
    }

    protected function navigationForUser()
    {
        return $this->permissions->navigation($this->user['role'] ?? '', 'standalone');
    }

    protected function resourceDefinition($section)
    {
        return SmartTrackModuleCatalog::definition($section);
    }

    protected function fieldsFor($section)
    {
        return SmartTrackModuleCatalog::fields($section, $this->user['role'] ?? '');
    }

    protected function getReportsModel()
    {
        if ($this->reportsModel === null) {
            $this->reportsModel = $this->modelRegistry->loadOptional($this->db, 'Reports_model');
        }
        return $this->reportsModel;
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
        return SmartTrackFilePath::privateDocument($relativePath);
    }

    protected function privateCertificatePath($relativePath)
    {
        return SmartTrackFilePath::privateCertificate($relativePath);
    }

    protected function certificateVerificationUrl($token)
    {
        $path = $this->url('verify-certificate?qr=' . rawurlencode((string) $token));
        $configuredUrl = getenv('SMARTTRACK_PUBLIC_URL');
        $baseUrl = rtrim(
            is_string($configuredUrl) && trim($configuredUrl) !== ''
                ? trim($configuredUrl)
                : (string) config_item('base_url'),
            '/'
        );
        if ($baseUrl !== '') {
            return $baseUrl . '/' . ltrim($path, '/');
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
