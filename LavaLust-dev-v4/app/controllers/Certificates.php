<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'controllers/Base.php';

class Certificates extends Base
{
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

        $this->eligibilityModel->refreshEligibilityRecords();
        $stats = $this->certificateModel->certificateStats();
        $records = $this->certificateModel->getCertificates($filters);
        $programs = $this->recordModel->options('programs', $this->user);

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

        $this->eligibilityModel->refreshEligibilityRecords();
        $filters = [];
        foreach (['search', 'eligibility', 'program_id'] as $filter) {
            $value = $_GET[$filter] ?? '';
            $filters[$filter] = is_scalar($value) ? trim((string) $value) : '';
        }

        $records = $this->eligibilityModel->getEligibilityRecords($filters);
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
        $programs = $this->recordModel->options('programs', $this->user);

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

    public function reviewEligibility()
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to review certificate eligibility.');
        }

        $eligibilityId = (int) $this->input('eligibility_id');
        $decision = (string) $this->input('decision');
        $reason = trim((string) $this->input('reason'));
        if (!$eligibilityId || !in_array($decision, ['approved', 'rejected'], true) || ($decision === 'rejected' && $reason === '')) {
            return $this->actionError('Choose an approval decision and provide a reason when rejecting.', 'manage/eligibility');
        }

        if (!$this->eligibilityModel->updateEligibilityApproval($eligibilityId, $decision, (int) $this->user['user_id'], $reason ?: null)) {
            return $this->actionError('Only eligible trainees can be approved. Recalculate eligibility and review the requirements first.', 'manage/eligibility');
        }

        $this->eligibilityModel->logEligibilityAction((int) $this->user['user_id'], $eligibilityId, $decision, $reason);
        $this->recordModel->logActivity((int) $this->user['user_id'], 'Reviewed certificate eligibility', 'Eligibility #' . $eligibilityId . ' ' . $decision);
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => 'Eligibility review saved.']);
        }
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

        $result = $this->eligibilityModel->deleteEligibilityEnrollment((int) $id, (int) $this->user['user_id']);
        if (!$result['success']) {
            if ($this->apiRequest) {
                $this->jsonResponse(['message' => $result['message']], 422);
            }
            $this->notice($result['message']);
            redirect($this->url('manage/eligibility'));
            return;
        }

        $this->recordModel->logActivity((int) $this->user['user_id'], 'Removed class enrollment from certificate eligibility', 'Eligibility #' . (int) $id . ': ' . $result['message']);
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => $result['message']]);
        }
        $this->notice($result['message']);
        redirect($this->url('manage/eligibility'));
    }

    public function recalculateEligibility()
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to recalculate eligibility.');
        }

        $traineeId = (int) $this->input('trainee_id');
        $classId = (int) $this->input('class_id');

        if (!$traineeId || !$classId) {
            return $this->actionError('Invalid trainee or class.', 'manage/eligibility');
        }

        $result = $this->eligibilityModel->calculateEligibility($traineeId, $classId);
        $this->recordModel->logActivity((int) $this->user['user_id'], 'Recalculated eligibility', "Trainee ID: $traineeId, Class ID: $classId");

        $message = $result['eligible']
            ? 'Trainee is eligible for certificate.'
            : 'Trainee is not eligible: ' . implode(', ', $result['reasons']);
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => $message, 'eligibility' => $result]);
        }

        $this->notice($message);
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
            return $this->actionError('Invalid trainee or class.', 'manage/eligibility');
        }

        $certificate = $this->certificateModel->issueCertificate($traineeId, $classId, (int) $this->user['user_id']);

        if ($certificate) {
            $this->generateCertificatePDF($certificate);
            $this->recordModel->logActivity((int) $this->user['user_id'], 'Issued certificate', "Certificate: {$certificate['certificate_number']}");
            $this->certificateModel->logCertificateAction((int) $this->user['user_id'], (int) $certificate['certificate_id'], 'issue', "Certificate issued for trainee ID: $traineeId, class ID: $classId");
            if ($this->apiRequest) {
                $this->jsonResponse(['message' => 'Certificate issued successfully.', 'certificate' => $certificate]);
            }
            $this->notice('Certificate issued successfully.');
        } else {
            if ($this->apiRequest) {
                $this->jsonResponse(['message' => 'Failed to issue certificate. Trainee may not be eligible.'], 422);
            }
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

        $certificate = $this->recordModel->row('SELECT certificate_number FROM certificates WHERE certificate_id = ?', [(int) $id]);
        if (!$certificate) {
            show_404();
            return;
        }

        $this->render('module', [
            'title' => 'Certificate History',
            'section' => 'certificate_history',
            'certificate_number' => $certificate['certificate_number'],
            'records' => $this->certificateModel->certificateAuditRecords((int) $id),
            'columns' => ['action', 'actor_name', 'details', 'ip_address', 'created_at'],
        ]);
    }

    private function generateCertificatePDF($certificate)
    {
        $this->call->library('CertificateGenerator');
        $template = json_decode((string) ($certificate['template_snapshot'] ?? ''), true);
        if (!is_array($template)) {
            $template = $this->certificateModel->legacyTemplate();
        }
        $filePath = null;
        if (($template['mode'] ?? '') === 'upload') {
            $filePath = $this->privateCertificatePath($template['file_path'] ?? '');
            if (!$filePath) {
                throw new RuntimeException('The certificate background saved with this certificate is missing.');
            }
        }
        $template['settings'] = is_array($template['settings'] ?? null) ? $template['settings'] : [];
        $logoPath = $this->privateCertificatePath($template['settings']['logo_path'] ?? '');
        if (!empty($template['settings']['logo_path']) && !$logoPath) {
            throw new RuntimeException('The institution logo saved with this certificate is missing.');
        }
        $template['settings']['logo_file_path'] = $logoPath;

        $record = $this->recordModel->row('SELECT CONCAT(t.first_name, " ", t.last_name) AS trainee_name, p.program_name, c.class_name FROM certificates cert JOIN trainees t ON t.trainee_id = cert.trainee_id JOIN training_programs p ON p.program_id = cert.program_id JOIN training_classes c ON c.class_id = cert.class_id WHERE cert.certificate_id = ?', [(int) $certificate['certificate_id']]);
        if (!$record) {
            throw new RuntimeException('The trainee or program record for this certificate could not be found.');
        }
        $generator = new CertificateGenerator($template);
        $pdfData = $generator->generate([
            'trainee_name' => $record['trainee_name'],
            'program_name' => $record['program_name'],
            'class_name' => $record['class_name'],
            'completion_date' => $certificate['completion_date'],
            'issue_date' => $certificate['issue_date'],
            'certificate_number' => $certificate['certificate_number'],
            'verification_url' => $this->certificateVerificationUrl($certificate['qr_code']),
            'template_file_path' => $filePath,
        ]);

        $certificateDirectory = ROOT_DIR . 'runtime/uploads/certificates';
        if (!is_dir($certificateDirectory) && !mkdir($certificateDirectory, 0750, true) && !is_dir($certificateDirectory)) {
            throw new RuntimeException('The protected certificate output directory could not be created.');
        }
        $filename = 'certificate_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $certificate['certificate_number']) . '.pdf';
        $outputPath = $certificateDirectory . DIRECTORY_SEPARATOR . $filename;
        if (file_put_contents($outputPath, $pdfData, LOCK_EX) === false) {
            throw new RuntimeException('The certificate PDF could not be saved.');
        }
        $relativePath = 'runtime/uploads/certificates/' . $filename;
        $this->certificateModel->updateCertificateFilePath((int) $certificate['certificate_id'], $relativePath);
        return $relativePath;
    }

    public function revokeCertificate()
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to revoke certificates.');
        }

        $certificateId = (int) $this->input('certificate_id');
        $reason = trim((string) $this->input('reason'));

        if (!$certificateId || empty($reason)) {
            return $this->actionError('Certificate ID and reason are required.', 'manage/certificates');
        }

        $this->certificateModel->revokeCertificate($certificateId, (int) $this->user['user_id'], $reason);
        $this->recordModel->logActivity((int) $this->user['user_id'], 'Revoked certificate', "Certificate ID: $certificateId, Reason: $reason");
        $this->certificateModel->logCertificateAction((int) $this->user['user_id'], (int) $certificateId, 'revoke', "Reason: $reason");
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => 'Certificate revoked successfully.']);
        }
        $this->notice('Certificate revoked successfully.');
        redirect($this->url('manage/certificates'));
    }

    public function certificateConfig()
    {
        if (!$this->requireUser() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to configure certificate templates.');
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->validCsrf()) {
                return $this->deny('Your session expired. Refresh and try again.');
            }
            return $this->saveCertificateTemplate();
        }

        $template = null;
        if (array_key_exists('edit_template', $_GET)) {
            $editId = is_scalar($_GET['edit_template']) ? (string) $_GET['edit_template'] : '';
            if (!ctype_digit($editId) || !(int) $editId || !($template = $this->certificateModel->getTemplate((int) $editId))) {
                show_404();
                return;
            }
        }
        $template = $template ?: $this->certificateModel->getLatestTemplate() ?: $this->certificateModel->legacyTemplate();
        $configs = [
            'min_attendance_percentage' => $this->configModel->getConfig('min_attendance_percentage', 75),
            'require_documents' => $this->configModel->getConfig('require_documents', 1),
            'require_assessments' => $this->configModel->getConfig('require_assessments', 0),
            'required_assessment_count' => $this->configModel->getConfig('required_assessment_count', 1),
        ];

        $this->render('module', [
            'title' => 'Certificate Template',
            'section' => 'certificate_config',
            'configs' => $configs,
            'certificate_template' => $template,
            'certificate_template_versions' => $this->certificateModel->getTemplateVersions(),
            'document_requirements' => $this->documentModel->documentRequirements(),
            'certificate_classes' => $this->recordModel->rows('SELECT class_id AS id, class_name AS label FROM training_classes ORDER BY class_name'),
            'user' => $this->user,
            'app_url' => $this->url(''),
            'csrf' => $this->session->flashdata('_csrf') ?? bin2hex(random_bytes(32)),
        ]);
    }

    private function saveCertificateTemplate()
    {
        $mode = $this->formString('mode');
        $saveAction = $this->formString('save_action');
        if (!in_array($mode, ['upload', 'generated'], true) || !in_array($saveAction, ['draft', 'activate'], true)) {
            return $this->actionError('Choose a valid template mode and save action.', 'manage/certificate-config');
        }

        $minimumAttendance = filter_var($this->formString('min_attendance_percentage'), FILTER_VALIDATE_FLOAT);
        $requiredAssessmentCount = filter_var($this->formString('required_assessment_count'), FILTER_VALIDATE_INT);
        if ($minimumAttendance === false || $minimumAttendance < 0 || $minimumAttendance > 100
            || $requiredAssessmentCount === false || $requiredAssessmentCount < 1 || $requiredAssessmentCount > 100) {
            return $this->actionError('Set attendance between 0 and 100 percent and required assessments between 1 and 100.', 'manage/certificate-config');
        }

        $sourceTemplateId = $this->formString('source_template_id');
        if ($sourceTemplateId !== '') {
            if (!ctype_digit($sourceTemplateId) || !(int) $sourceTemplateId || !($previous = $this->certificateModel->getTemplate((int) $sourceTemplateId))) {
                return $this->actionError('The certificate template version you are editing could not be found.', 'manage/certificate-config', 404);
            }
        } else {
            $previous = $this->certificateModel->getLatestTemplate() ?: $this->certificateModel->legacyTemplate();
        }
        $settings = [
            'institution_name' => trim($this->formString('institution_name')),
            'title_text' => trim($this->formString('title_text')),
            'body_text' => trim($this->formString('body_text')),
            'signatory_name' => trim($this->formString('signatory_name')),
            'signatory_title' => trim($this->formString('signatory_title')),
            'border_style' => $this->formString('border_style'),
            'accent_color' => $this->formString('accent_color'),
            'font' => $this->formString('font'),
            'logo_path' => $previous['settings']['logo_path'] ?? '',
        ];
        $limits = [
            'institution_name' => 150,
            'title_text' => 150,
            'body_text' => 2000,
            'signatory_name' => 150,
            'signatory_title' => 150,
        ];
        foreach ($limits as $key => $limit) {
            if ($settings[$key] === '' || mb_strlen($settings[$key]) > $limit) {
                return $this->actionError('Complete all template text fields and keep each within its maximum length.', 'manage/certificate-config');
            }
        }
        if (!in_array($settings['border_style'], ['none', 'single', 'double'], true)
            || !in_array($settings['font'], ['helvetica', 'times', 'courier'], true)
            || !preg_match('/^#[0-9a-fA-F]{6}$/', $settings['accent_color'])) {
            return $this->actionError('Choose a valid border style, font, and six-digit accent color.', 'manage/certificate-config');
        }
        preg_match_all('/\{([^}]+)\}/', $settings['body_text'], $placeholders);
        $allowedPlaceholders = ['trainee_name', 'program_name', 'date_completed', 'cert_no', 'signatory_name', 'signatory_title', 'verification_code'];
        foreach ($placeholders[1] as $placeholder) {
            if (!in_array($placeholder, $allowedPlaceholders, true)) {
                return $this->actionError('The body contains an unsupported placeholder: {' . $placeholder . '}.', 'manage/certificate-config');
            }
        }

        $logoUpload = $this->storeCertificateUpload('logo', ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg']);
        if ($logoUpload['error']) {
            return $this->actionError($logoUpload['error'], 'manage/certificate-config');
        }
        if ($logoUpload['path']) {
            $settings['logo_path'] = $logoUpload['path'];
        }

        $layout = $previous['field_layout'] ?? [];
        $filePath = $mode === 'upload' ? ($previous['file_path'] ?? null) : null;
        if ($mode === 'upload') {
            $upload = $this->storeCertificateUpload('template_file', [
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'pdf' => 'application/pdf',
            ]);
            if ($upload['error']) {
                return $this->actionError($upload['error'], 'manage/certificate-config');
            }
            if ($upload['path']) {
                $filePath = $upload['path'];
            }
            if (!$filePath) {
                return $this->actionError('Upload a PNG, JPG, or PDF certificate background for upload mode.', 'manage/certificate-config');
            }
            $layout = $this->validatedFieldLayout($_POST['field_layout'] ?? null);
            if ($layout === false) {
                return $this->actionError('Set valid field positions (0–100%), font sizes (6–72), and alignments for all six fields.', 'manage/certificate-config');
            }
        }

        $template = [
            'mode' => $mode,
            'file_path' => $filePath,
            'field_layout' => $layout,
            'settings' => $settings,
        ];
        $saved = $this->certificateModel->saveTemplate($template, (int) $this->user['user_id'], $saveAction === 'activate');

        foreach ([
            'min_attendance_percentage' => (string) $minimumAttendance,
            'require_documents' => isset($_POST['require_documents']) ? '1' : '0',
            'require_assessments' => isset($_POST['require_assessments']) ? '1' : '0',
            'required_assessment_count' => (string) $requiredAssessmentCount,
        ] as $key => $value) {
            $this->configModel->updateConfig($key, $value);
        }
        $this->eligibilityModel->refreshEligibilityRecords();
        $state = $saveAction === 'activate' ? 'activated' : 'saved as a draft';
        $this->recordModel->logActivity((int) $this->user['user_id'], 'Updated certificate template', 'Template version ' . $saved['version'] . ' ' . $state);
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => 'Certificate template version ' . $saved['version'] . ' ' . $state . '.', 'template' => $saved]);
        }
        $this->notice('Certificate template version ' . $saved['version'] . ' ' . $state . '.');
        redirect($this->url('manage/certificate-config'));
    }

    private function formString($key)
    {
        $value = $_POST[$key] ?? '';
        return is_scalar($value) ? (string) $value : '';
    }

    private function validatedFieldLayout($input)
    {
        $fields = ['trainee_name', 'program_name', 'date_completed', 'certificate_number', 'signatory_name', 'verification_code'];
        if (!is_array($input)) {
            return false;
        }
        $layout = [];
        foreach ($fields as $field) {
            $item = $input[$field] ?? null;
            if (!is_array($item)) {
                return false;
            }
            $x = filter_var($item['x'] ?? null, FILTER_VALIDATE_FLOAT);
            $y = filter_var($item['y'] ?? null, FILTER_VALIDATE_FLOAT);
            $fontSize = filter_var($item['font_size'] ?? null, FILTER_VALIDATE_FLOAT);
            $align = $item['align'] ?? null;
            if ($x === false || $x < 0 || $x > 100 || $y === false || $y < 0 || $y > 100
                || $fontSize === false || $fontSize < 6 || $fontSize > 72
                || !in_array($align, ['left', 'center', 'right'], true)) {
                return false;
            }
            $layout[$field] = ['x' => $x, 'y' => $y, 'font_size' => $fontSize, 'align' => $align];
        }
        return $layout;
    }

    private function storeCertificateUpload($field, array $allowedMimes)
    {
        if (!isset($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['path' => null, 'error' => null];
        }
        $upload = $_FILES[$field];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['path' => null, 'error' => 'The uploaded file could not be received.'];
        }
        $actualSize = is_file($upload['tmp_name']) ? filesize($upload['tmp_name']) : false;
        if (!is_uploaded_file($upload['tmp_name']) || $actualSize === false || $actualSize < 1 || $actualSize > 5 * 1024 * 1024) {
            return ['path' => null, 'error' => 'Each certificate template or logo upload must be no larger than 5 MB.'];
        }
        $extension = strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION));
        if (!isset($allowedMimes[$extension])) {
            return ['path' => null, 'error' => 'The file extension is not allowed.'];
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        if ($mime !== $allowedMimes[$extension]) {
            return ['path' => null, 'error' => 'The uploaded file type does not match its extension.'];
        }
        if ($extension === 'pdf') {
            $handle = fopen($upload['tmp_name'], 'rb');
            $signature = $handle ? fread($handle, 5) : '';
            if ($handle) {
                fclose($handle);
            }
            if ($signature !== '%PDF-') {
                return ['path' => null, 'error' => 'The uploaded PDF file is invalid.'];
            }
            require_once ROOT_DIR . 'vendor/autoload.php';
            try {
                $validator = new \setasign\Fpdi\Tcpdf\Fpdi();
                if ($validator->setSourceFile($upload['tmp_name']) < 1) {
                    return ['path' => null, 'error' => 'The uploaded PDF contains no pages.'];
                }
            } catch (\setasign\Fpdi\FpdiException $error) {
                return ['path' => null, 'error' => 'The uploaded PDF could not be parsed.'];
            }
        } else {
            $image = getimagesize($upload['tmp_name']);
            if (!$image || ($image['mime'] ?? '') !== $allowedMimes[$extension]
                || $image[0] > 10000 || $image[1] > 10000 || $image[0] * $image[1] > 20000000) {
                return ['path' => null, 'error' => 'The uploaded image file is invalid.'];
            }
        }

        $uploadDirectory = ROOT_DIR . 'runtime/uploads/certificates/templates';
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
            return ['path' => null, 'error' => 'The protected certificate upload directory could not be created.'];
        }
        $filename = bin2hex(random_bytes(20)) . '.' . $extension;
        if (!move_uploaded_file($upload['tmp_name'], $uploadDirectory . DIRECTORY_SEPARATOR . $filename)) {
            return ['path' => null, 'error' => 'The uploaded file could not be saved.'];
        }
        return ['path' => 'runtime/uploads/certificates/templates/' . $filename, 'error' => null];
    }

    public function activateCertificateTemplate($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to activate certificate templates.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            return $this->actionError('Choose a valid certificate template version.', 'manage/certificate-config');
        }
        $template = $this->certificateModel->activateTemplate((int) $id);
        if (!$template) {
            return $this->actionError('That certificate template version could not be found.', 'manage/certificate-config', 404);
        }
        $this->recordModel->logActivity((int) $this->user['user_id'], 'Activated certificate template', 'Template version ' . $template['version']);
        $this->notice('Certificate template version ' . $template['version'] . ' activated.');
        redirect($this->url('manage/certificate-config'));
    }

    public function deleteCertificateTemplate($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to delete certificate templates.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            return $this->actionError('Choose a valid certificate template version.', 'manage/certificate-config');
        }

        $result = $this->certificateModel->deleteTemplate((int) $id);
        if (!$result['deleted']) {
            $messages = [
                'not_found' => 'That certificate template version could not be found.',
                'active' => 'The active certificate template cannot be deleted. Activate another version first.',
                'issued' => 'This template is retained because an issued certificate references it.',
            ];
            return $this->actionError($messages[$result['reason']] ?? 'The certificate template could not be deleted.', 'manage/certificate-config', $result['reason'] === 'not_found' ? 404 : 422);
        }

        $this->recordModel->logActivity((int) $this->user['user_id'], 'Deleted certificate template', 'Template version ' . $result['version']);
        $this->notice('Certificate template version ' . $result['version'] . ' deleted.');
        redirect($this->url('manage/certificate-config'));
    }

    public function certificateTemplateFile($id)
    {
        if (!$this->requireUser() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to preview certificate template files.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            show_404();
            return;
        }
        $template = $this->certificateModel->getTemplate((int) $id);
        if (!$template) {
            show_404();
            return;
        }
        $asset = ($_GET['asset'] ?? '') === 'logo' ? ($template['settings']['logo_path'] ?? '') : ($template['file_path'] ?? '');
        $filePath = $this->privateCertificatePath($asset);
        if (!$filePath) {
            show_404();
            return;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
        if (!in_array($mime, ['application/pdf', 'image/png', 'image/jpeg'], true)) {
            show_404();
            return;
        }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="certificate-template.' . ($mime === 'application/pdf' ? 'pdf' : ($mime === 'image/png' ? 'png' : 'jpg')) . '"');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Cache-Control: private, no-store');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }

    public function myCertificates()
    {
        if (!$this->requireUser() || $this->user['role'] !== 'trainee') {
            return $this->deny('Only trainees can access their certificates.');
        }

        $trainee = $this->recordModel->row('SELECT trainee_id FROM trainees WHERE user_id = ?', [(int) $this->user['user_id']]);
        if (!$trainee) {
            return $this->deny('Trainee record not found.');
        }

        $records = $this->recordModel->rows('SELECT c.*, p.program_name, cl.class_name FROM certificates c JOIN training_programs p ON p.program_id = c.program_id JOIN training_classes cl ON cl.class_id = c.class_id WHERE c.trainee_id = ? ORDER BY c.issue_date DESC', [(int) $trainee['trainee_id']]);

        $eligibility = $this->recordModel->rows('SELECT e.*, p.program_name, cl.class_name FROM certificate_eligibility e JOIN training_programs p ON p.program_id = e.program_id JOIN training_classes cl ON cl.class_id = e.class_id WHERE e.trainee_id = ? ORDER BY e.updated_at DESC', [(int) $trainee['trainee_id']]);

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

        if (!ctype_digit((string) $id) || !(int) $id) {
            show_404();
            return;
        }
        $certificate = $this->recordModel->row('SELECT c.*, t.user_id FROM certificates c JOIN trainees t ON t.trainee_id = c.trainee_id WHERE c.certificate_id = ?', [(int) $id]);
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

        $filePath = !empty($certificate['file_path']) ? $this->privateCertificatePath($certificate['file_path']) : null;
        if (!$filePath) {
            $certificate['file_path'] = $this->generateCertificatePDF($certificate);
            $filePath = $this->privateCertificatePath($certificate['file_path']);
            if (!$filePath) {
                throw new RuntimeException('The generated certificate PDF could not be found.');
            }
        }

        $this->certificateModel->logCertificateAction((int) $this->user['user_id'], (int) $certificate['certificate_id'], 'download', 'Certificate downloaded by ' . $this->user['role']);

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
            $certificate = $this->recordModel->row('SELECT c.*, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, p.program_name, cl.class_name FROM certificates c JOIN trainees t ON t.trainee_id = c.trainee_id JOIN training_programs p ON p.program_id = c.program_id JOIN training_classes cl ON cl.class_id = c.class_id WHERE c.qr_code = ?', [$qrCode]);
        } elseif ($certificateNumber) {
            $certificate = $this->recordModel->row('SELECT c.*, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, p.program_name, cl.class_name FROM certificates c JOIN trainees t ON t.trainee_id = c.trainee_id JOIN training_programs p ON p.program_id = c.program_id JOIN training_classes cl ON cl.class_id = c.class_id WHERE c.certificate_number = ?', [$certificateNumber]);
        }

        $this->render('verify_certificate', [
            'title' => 'Certificate Verification',
            'page' => 'verify_certificate',
            'certificate' => $certificate,
            'app_url' => $this->url(''),
        ]);
    }
}
