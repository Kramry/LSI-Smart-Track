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

        if (!$this->model->updateEligibilityApproval($eligibilityId, $decision, (int) $this->user['user_id'], $reason ?: null)) {
            return $this->actionError('Only eligible trainees can be approved. Recalculate eligibility and review the requirements first.', 'manage/eligibility');
        }

        $this->model->logEligibilityAction((int) $this->user['user_id'], $eligibilityId, $decision, $reason);
        $this->model->logActivity((int) $this->user['user_id'], 'Reviewed certificate eligibility', 'Eligibility #' . $eligibilityId . ' ' . $decision);
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

        $result = $this->model->deleteEligibilityEnrollment((int) $id, (int) $this->user['user_id']);
        if (!$result['success']) {
            if ($this->apiRequest) {
                $this->jsonResponse(['message' => $result['message']], 422);
            }
            $this->notice($result['message']);
            redirect($this->url('manage/eligibility'));
            return;
        }

        $this->model->logActivity((int) $this->user['user_id'], 'Removed class enrollment from certificate eligibility', 'Eligibility #' . (int) $id . ': ' . $result['message']);
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

        $result = $this->model->calculateEligibility($traineeId, $classId);
        $this->model->logActivity((int) $this->user['user_id'], 'Recalculated eligibility', "Trainee ID: $traineeId, Class ID: $classId");

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

        $certificate = $this->model->issueCertificate($traineeId, $classId, (int) $this->user['user_id']);

        if ($certificate) {
            $this->generateCertificatePDF($certificate);
            $this->model->logActivity((int) $this->user['user_id'], 'Issued certificate', "Certificate: {$certificate['certificate_number']}");
            $this->model->logCertificateAction((int) $this->user['user_id'], (int) $certificate['certificate_id'], 'issue', "Certificate issued for trainee ID: $traineeId, class ID: $classId");
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
            return $this->actionError('Certificate ID and reason are required.', 'manage/certificates');
        }

        $this->model->revokeCertificate($certificateId, (int) $this->user['user_id'], $reason);
        $this->model->logActivity((int) $this->user['user_id'], 'Revoked certificate', "Certificate ID: $certificateId, Reason: $reason");
        $this->model->logCertificateAction((int) $this->user['user_id'], (int) $certificateId, 'revoke', "Reason: $reason");
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => 'Certificate revoked successfully.']);
        }
        $this->notice('Certificate revoked successfully.');
        redirect($this->url('manage/certificates'));
    }

    public function certificateConfig()
    {
        if (!$this->requireUser() || !in_array($this->user['role'], ['administrator'], true)) {
            return $this->deny('Only administrators can configure certificates.');
        }
        if ($this->apiRequest && !$this->validCsrf()) {
            return $this->deny('Your session expired. Refresh and try again.');
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
                if ($this->apiRequest) {
                    $this->jsonResponse(['message' => 'Set attendance between 0 and 100 percent and required assessments between 1 and 100.'], 422);
                }
                $this->notice('Set attendance between 0 and 100 percent and required assessments between 1 and 100.');
                redirect($this->url('manage/certificate-config'));
                return;
            }

            foreach ($configs as $key => $value) {
                $this->model->updateConfig($key, $value);
            }
            $this->model->refreshEligibilityRecords();

            $this->model->logActivity((int) $this->user['user_id'], 'Updated certificate template', 'Certificate configuration saved');
            if ($this->apiRequest) {
                $this->jsonResponse(['message' => 'Certificate template updated.']);
            }
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
}
