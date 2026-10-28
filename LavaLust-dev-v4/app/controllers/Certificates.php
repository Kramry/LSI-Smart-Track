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

        $certificate = null;
        if ($decision === 'approved') {
            $eligibleRecord = $this->eligibilityModel->row('SELECT trainee_id, class_id FROM certificate_eligibility WHERE eligibility_id = ?', [$eligibilityId]);
            $requiredSignatories = $this->certificateModel->requiredActiveCertificateSignatories();
            if (!$requiredSignatories) {
                return $this->actionError('Configure at least one active required signatory before approving certificates.', 'manage/certificate-config');
            }
            if (!$eligibleRecord) {
                return $this->actionError('The eligibility record was not found.', 'manage/eligibility', 404);
            }
        }

        if (!$this->eligibilityModel->updateEligibilityApproval($eligibilityId, $decision, (int) $this->user['user_id'], $reason ?: null)) {
            return $this->actionError('Only eligible trainees can be approved. Recalculate eligibility and review the requirements first.', 'manage/eligibility');
        }
        if ($decision === 'approved') {
            $certificate = $this->certificateModel->issueCertificate(
                (int) $eligibleRecord['trainee_id'],
                (int) $eligibleRecord['class_id'],
                (int) $this->user['user_id']
            );
            if (!$certificate) {
                return $this->actionError('Eligibility was approved, but a pending-signature certificate could not be created. Review eligibility and certificate records.', 'manage/eligibility', 409);
            }
            $this->recordModel->logActivity((int) $this->user['user_id'], 'Submitted approved certificate for signature', 'Certificate ' . $certificate['certificate_number'] . ' is pending signatory approval.');
            $this->certificateModel->logCertificateAction((int) $this->user['user_id'], (int) $certificate['certificate_id'], 'pending_signature', 'Eligibility approved; certificate assigned to required signatories.');
        }

        $this->eligibilityModel->logEligibilityAction((int) $this->user['user_id'], $eligibilityId, $decision, $reason);
        $this->recordModel->logActivity((int) $this->user['user_id'], 'Reviewed certificate eligibility', 'Eligibility #' . $eligibilityId . ' ' . $decision);
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => $decision === 'approved' ? 'Eligibility approved and submitted for signatures.' : 'Eligibility review saved.', 'certificate' => $certificate]);
        }
        $this->notice($decision === 'approved' ? 'Eligibility approved and submitted for signatures.' : 'Eligibility review saved.');
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

        $requiredSignatories = $this->certificateModel->requiredActiveCertificateSignatories();
        if (!$requiredSignatories) {
            return $this->actionError('Configure at least one active signatory before submitting certificates for signature.', 'manage/certificate-config');
        }
        $certificate = $this->certificateModel->issueCertificate($traineeId, $classId, (int) $this->user['user_id']);

        if ($certificate) {
            $this->recordModel->logActivity((int) $this->user['user_id'], 'Submitted certificate for signature', "Certificate: {$certificate['certificate_number']}");
            $this->certificateModel->logCertificateAction((int) $this->user['user_id'], (int) $certificate['certificate_id'], 'pending_signature', "Certificate submitted for trainee ID: $traineeId, class ID: $classId");
            if ($this->apiRequest) {
                $this->jsonResponse(['message' => 'Certificate submitted for signatory review.', 'certificate' => $certificate]);
            }
            $this->notice('Certificate submitted for signatory review.');
        } else {
            if ($this->apiRequest) {
                $this->jsonResponse(['message' => 'Failed to issue certificate. Trainee may not be eligible.'], 422);
            }
            $this->notice('Failed to issue certificate. Trainee may not be eligible.');
        }

        redirect($this->url('manage/certificates'));
    }

    public function certificatesToSign()
    {
        if (!$this->requireUser() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to view certificates to sign.');
        }

        $this->render('module', [
            'title' => 'Certificates to sign',
            'section' => 'certificates_to_sign',
            'records' => $this->certificateModel->certificateSigningQueue((int) $this->user['user_id']),
            'columns' => [],
            'user' => $this->user,
            'app_url' => $this->url(''),
            'csrf' => $this->session->flashdata('_csrf') ?? bin2hex(random_bytes(32)),
        ]);
    }

    public function previewCertificateForSigning($id)
    {
        if (!$this->requireUser() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to preview this certificate.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            show_404();
            return;
        }

        $certificate = $this->certificateModel->certificateForSigning((int) $id, (int) $this->user['user_id']);
        if (!$certificate || $certificate['status'] !== 'pending_signature' || $certificate['signature_status'] !== 'pending') {
            return $this->deny('This certificate is not assigned to you for signing.');
        }

        try {
            $pdf = $this->generateCertificatePDF($certificate, false);
        } catch (RuntimeException $error) {
            $this->recordModel->logActivity((int) $this->user['user_id'], 'Failed to preview certificate for signing', 'Certificate #' . (int) $id . ': ' . $error->getMessage());
            return $this->actionError('The certificate preview could not be generated. Check the template and signature files.', 'manage/certificates-to-sign', 500);
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="certificate-signing-preview.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    public function signCertificate($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to sign this certificate.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            show_404();
            return;
        }

        $certificate = $this->certificateModel->certificateForSigning((int) $id, (int) $this->user['user_id']);
        $passwordInput = $this->input('password');
        $password = is_string($passwordInput) ? $passwordInput : '';
        $account = $this->certificateModel->row('SELECT password FROM users WHERE user_id = ? AND role IN ("administrator", "staff") AND status = "active"', [(int) $this->user['user_id']]);
        if (!$certificate || $certificate['status'] !== 'pending_signature' || $certificate['signature_status'] !== 'pending') {
            return $this->deny('This certificate is not assigned to you for signing.');
        }
        if (!$account || !password_verify($password, $account['password'])) {
            return $this->actionError('Your password is incorrect. The certificate was not signed.', 'manage/certificates-to-sign');
        }

        $result = $this->certificateModel->signCertificate((int) $id, (int) $this->user['user_id'], $_SERVER['REMOTE_ADDR'] ?? '');
        if (!$result['success']) {
            return $this->actionError($result['message'], 'manage/certificates-to-sign');
        }

        $this->recordModel->logActivity((int) $this->user['user_id'], 'Signed certificate', 'Certificate ' . $certificate['certificate_number'] . '; SHA-256 ' . $result['hash']);
        $this->certificateModel->logCertificateAction((int) $this->user['user_id'], (int) $id, 'sign', 'Certificate signed; SHA-256 ' . $result['hash']);
        if ($result['complete']) {
            try {
                $filePath = $this->generateCertificatePDF($certificate);
                $issued = $this->certificateModel->issueSignedCertificate((int) $id, $filePath);
                if (!$issued) {
                    return $this->actionError('All required signatories signed, but the certificate could not be finalized. Contact an administrator.', 'manage/certificates-to-sign', 500);
                }
            } catch (RuntimeException $error) {
                $this->recordModel->logActivity((int) $this->user['user_id'], 'Failed to finalize signed certificate', 'Certificate #' . (int) $id . ': ' . $error->getMessage());
                return $this->actionError('All required signatories signed, but the final PDF could not be generated. Contact an administrator.', 'manage/certificates-to-sign', 500);
            }
            $this->recordModel->logActivity((int) $this->user['user_id'], 'Issued signed certificate', 'Certificate ' . $certificate['certificate_number'] . ' is ready for the trainee.');
            $this->certificateModel->logCertificateAction((int) $this->user['user_id'], (int) $id, 'issue', 'All required signatures collected; certificate issued.');
            $this->notice('Your signature was saved. The certificate is now fully signed and issued.');
        } else {
            $this->notice('Your signature was saved. The certificate is waiting for the remaining signatories.');
        }
        redirect($this->url('manage/certificates-to-sign'));
    }

    public function rejectCertificateSignature($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to reject this certificate.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            show_404();
            return;
        }

        $remarksInput = $this->input('remarks');
        if (!is_scalar($remarksInput)) {
            return $this->actionError('Enter valid rejection remarks.', 'manage/certificates-to-sign');
        }
        $remarks = trim((string) $remarksInput);
        if ($remarks === '' || strlen($remarks) > 2000) {
            return $this->actionError('Provide rejection remarks of no more than 2000 characters.', 'manage/certificates-to-sign');
        }
        $certificate = $this->certificateModel->certificateForSigning((int) $id, (int) $this->user['user_id']);
        if (!$certificate || !$this->certificateModel->rejectCertificateSignature((int) $id, (int) $this->user['user_id'], $remarks)) {
            return $this->deny('This certificate is not assigned to you for rejection.');
        }

        $this->recordModel->logActivity((int) $this->user['user_id'], 'Rejected certificate for correction', 'Certificate ' . $certificate['certificate_number'] . ': ' . $remarks);
        $this->certificateModel->logCertificateAction((int) $this->user['user_id'], (int) $id, 'reject_signature', $remarks);
        $this->notice('Certificate returned to staff with your remarks.');
        redirect($this->url('manage/certificates-to-sign'));
    }

    public function resubmitCertificateForSignature($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to resubmit certificates.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            show_404();
            return;
        }
        if (!$this->certificateModel->resubmitCertificateForSignature((int) $id)) {
            return $this->actionError('Only returned certificates can be resubmitted.', 'manage/certificates');
        }
        $this->recordModel->logActivity((int) $this->user['user_id'], 'Resubmitted certificate for signature', 'Certificate ID: ' . (int) $id);
        $this->certificateModel->logCertificateAction((int) $this->user['user_id'], (int) $id, 'resubmit_signature', 'Certificate resubmitted to signatories.');
        $this->notice('Certificate resubmitted for signature.');
        redirect($this->url('manage/certificates'));
    }

    public function finalizeSignedCertificate($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to finalize certificates.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            show_404();
            return;
        }
        $certificate = $this->certificateModel->row('SELECT c.*, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, p.program_name, cl.class_name FROM certificates c JOIN trainees t ON t.trainee_id = c.trainee_id JOIN training_programs p ON p.program_id = c.program_id JOIN training_classes cl ON cl.class_id = c.class_id WHERE c.certificate_id = ? AND c.status = "signed"', [(int) $id]);
        if (!$certificate) {
            return $this->actionError('Only fully signed certificates can be finalized.', 'manage/certificates');
        }
        $integrity = $this->certificateModel->certificateSignatureVerification((int) $id);
        if ($integrity['hash_valid'] !== true) {
            return $this->actionError('The certificate data hash does not match the signatures. Do not issue it; return it for review.', 'manage/certificates', 409);
        }
        try {
            $filePath = $this->generateCertificatePDF($certificate);
            $issued = $this->certificateModel->issueSignedCertificate((int) $id, $filePath);
            if (!$issued) {
                return $this->actionError('The certificate could not be finalized. Check the signature status and try again.', 'manage/certificates', 409);
            }
        } catch (RuntimeException $error) {
            $this->recordModel->logActivity((int) $this->user['user_id'], 'Failed to finalize signed certificate', 'Certificate #' . (int) $id . ': ' . $error->getMessage());
            return $this->actionError('The final certificate PDF could not be generated. Check template and signature storage.', 'manage/certificates', 500);
        }
        $this->recordModel->logActivity((int) $this->user['user_id'], 'Issued signed certificate', 'Certificate ' . $certificate['certificate_number'] . ' is ready for the trainee.');
        $this->certificateModel->logCertificateAction((int) $this->user['user_id'], (int) $id, 'issue', 'All required signatures collected; certificate issued.');
        $this->notice('Signed certificate finalized and issued.');
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

        $certificate = $this->certificateModel->row('SELECT certificate_number FROM certificates WHERE certificate_id = ?', [(int) $id]);
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

    private function generateCertificatePDF($certificate, $persist = true)
    {
        $this->call->library('CertificateGenerator');
        $template = !empty($certificate['template_id'])
            ? $this->certificateModel->certificateTemplate((int) $certificate['template_id'])
            : null;
        if (!$template) {
            $template = $this->certificateModel->defaultCertificateTemplate([
                'institution_name' => $this->configModel->getConfig('institution_name', 'LSI Training Center'),
                'logo_path' => $this->configModel->getConfig('logo_path', ''),
                'signatory_name' => $this->configModel->getConfig('signatory_name', 'Training Director'),
                'signatory_designation' => $this->configModel->getConfig('signatory_designation', 'Director'),
                'certificate_title' => $this->configModel->getConfig('certificate_title', 'Certificate of Completion'),
                'certificate_subtitle' => $this->configModel->getConfig('certificate_subtitle', 'OF COMPLETION'),
                'template_color' => $this->configModel->getConfig('template_color', '#dd4814'),
            ]);
        }
        $template['file_path'] = $this->resolveTemplateAssetPath($template['file_path'] ?? '');
        $template['settings']['logo_path'] = $this->resolveTemplateAssetPath($template['settings']['logo_path'] ?? '');
        $generator = new CertificateGenerator($template);

        $trainee = $this->certificateModel->row('SELECT CONCAT(t.first_name, " ", t.last_name) AS trainee_name FROM trainees t WHERE t.trainee_id = ?', [(int) $certificate['trainee_id']]);
        $program = $this->certificateModel->row('SELECT p.program_name, c.class_name FROM training_programs p JOIN training_classes c ON c.program_id = p.program_id WHERE c.class_id = ?', [(int) $certificate['class_id']]);
        $signatures = [];
        foreach ($this->certificateModel->signedCertificateSignatures((int) $certificate['certificate_id']) as $signature) {
            $signature['file_path'] = $this->resolveSignatureAssetPath($signature['signature_path']);
            if ($signature['file_path'] === '') {
                throw new RuntimeException('A signed certificate signature image is missing from protected storage.');
            }
            $signatures[] = $signature;
        }

        $pdfData = $generator->generate([
            'trainee_name' => $trainee['trainee_name'] ?? 'Unknown',
            'program_name' => $program['program_name'] ?? 'Unknown Program',
            'class_name' => $program['class_name'] ?? '',
            'completion_date' => $certificate['completion_date'],
            'issue_date' => $certificate['issue_date'],
            'certificate_number' => $certificate['certificate_number'],
            'qr_code' => $this->certificateVerificationUrl($certificate['qr_code']),
            'signatures' => $signatures,
        ]);

        if ($persist === false) {
            return $pdfData;
        }

        $filename = 'certificate_' . $certificate['certificate_number'] . '.pdf';
        $filePath = ROOT_DIR . 'runtime/uploads/certificates/' . $filename;

        if (!is_dir(ROOT_DIR . 'runtime/uploads/certificates')) {
            if (!mkdir(ROOT_DIR . 'runtime/uploads/certificates', 0750, true) && !is_dir(ROOT_DIR . 'runtime/uploads/certificates')) {
                throw new RuntimeException('Unable to create the certificate output directory.');
            }
        }

        if (file_put_contents($filePath, $pdfData, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the certificate PDF.');
        }

        $relativePath = 'runtime/uploads/certificates/' . $filename;
        $this->certificateModel->execute('UPDATE certificates SET file_path = ? WHERE certificate_id = ?', [$relativePath, (int) $certificate['certificate_id']]);
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
            return $this->deny('You are not allowed to configure certificates.');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->validCsrf()) {
                return $this->deny('Your session expired. Refresh and try again.');
            }
            $saveAction = $this->input('save_action');
            if ($saveAction === '' && $this->apiRequest) {
                $saveAction = 'activate';
            }
            if (!in_array($saveAction, ['draft', 'activate'], true)) {
                return $this->actionError('Choose whether to save this template as a draft or activate it.', 'manage/certificate-config');
            }
            $result = $this->certificateTemplateFromRequest(true);
            if ($result['error'] !== null) {
                return $this->actionError($result['error'], 'manage/certificate-config');
            }

            $saved = $this->certificateModel->saveCertificateTemplate(
                $result['template'],
                (int) $this->user['user_id'],
                $saveAction === 'activate'
            );
            foreach ($result['eligibility_config'] as $key => $value) {
                $this->configModel->updateConfig($key, $value);
            }
            $this->configModel->updateConfig('institution_name', $result['template']['settings']['institution_name']);
            $this->configModel->updateConfig('logo_path', $result['template']['settings']['logo_path']);
            $this->configModel->updateConfig('signatory_name', $result['template']['settings']['signatory_name']);
            $this->configModel->updateConfig('signatory_designation', $result['template']['settings']['signatory_designation']);
            $this->configModel->updateConfig('certificate_title', $result['template']['settings']['title_text']);
            $this->configModel->updateConfig('certificate_subtitle', $result['template']['settings']['subtitle_text']);
            $this->configModel->updateConfig('template_color', $result['template']['settings']['accent_color']);
            $this->eligibilityModel->refreshEligibilityRecords();

            $this->recordModel->logActivity(
                (int) $this->user['user_id'],
                $saveAction === 'activate' ? 'Activated certificate template' : 'Saved certificate template draft',
                'Template version ' . (int) ($saved['version'] ?? 0) . ' (' . $result['template']['mode'] . ')'
            );
            if ($this->apiRequest) {
                $this->jsonResponse(['message' => 'Certificate template saved.', 'template' => $saved]);
            }
            $this->notice($saveAction === 'activate' ? 'Certificate template activated.' : 'Certificate template saved as a draft.');
            redirect($this->url('manage/certificate-config'));
            return;
        }

        $legacyConfig = [
            'institution_name' => $this->configModel->getConfig('institution_name', 'LSI Training Center'),
            'logo_path' => $this->configModel->getConfig('logo_path', ''),
            'signatory_name' => $this->configModel->getConfig('signatory_name', 'Training Director'),
            'signatory_designation' => $this->configModel->getConfig('signatory_designation', 'Director'),
            'certificate_title' => $this->configModel->getConfig('certificate_title', 'Certificate of Completion'),
            'certificate_subtitle' => $this->configModel->getConfig('certificate_subtitle', 'OF COMPLETION'),
            'template_color' => $this->configModel->getConfig('template_color', '#dd4814'),
        ];
        $currentTemplate = $this->certificateModel->latestCertificateTemplate()
            ?: $this->certificateModel->defaultCertificateTemplate($legacyConfig);
        $eligibilityConfig = [
            'min_attendance_percentage' => $this->configModel->getConfig('min_attendance_percentage', 75),
            'require_documents' => $this->configModel->getConfig('require_documents', 1),
            'require_assessments' => $this->configModel->getConfig('require_assessments', 0),
            'required_assessment_count' => $this->configModel->getConfig('required_assessment_count', 1),
        ];

        $this->render('module', [
            'title' => 'Certificate Templates',
            'section' => 'certificate_config',
            'current_template' => $currentTemplate,
            'template_history' => $this->certificateModel->certificateTemplateHistory(),
            'certificate_signatories' => $this->certificateModel->certificateSignatories(),
            'signatory_users' => $this->certificateModel->signatoryUsers(),
            'eligibility_config' => $eligibilityConfig,
            'document_requirements' => $this->documentModel->documentRequirements(),
            'certificate_classes' => $this->certificateModel->rows('SELECT class_id AS id, class_name AS label FROM training_classes ORDER BY class_name'),
            'user' => $this->user,
            'app_url' => $this->url(''),
            'csrf' => $this->session->flashdata('_csrf') ?? bin2hex(random_bytes(32)),
        ]);
    }

    public function saveCertificateSignatory()
    {
        if (!$this->requireUser() || !$this->validCsrf() || $this->user['role'] !== 'administrator') {
            return $this->deny('Only administrators can configure certificate signatories.');
        }

        $signatoryIdInput = $_POST['signatory_id'] ?? '';
        $signatoryId = $signatoryIdInput === '' ? 0 : (
            is_scalar($signatoryIdInput) && ctype_digit((string) $signatoryIdInput)
                ? (int) $signatoryIdInput
                : -1
        );
        $userIdInput = $_POST['user_id'] ?? '';
        $userId = is_scalar($userIdInput) && ctype_digit((string) $userIdInput) ? (int) $userIdInput : 0;
        $nameInput = $this->input('name');
        $positionInput = $this->input('position');
        $xInput = $this->input('field_x');
        $yInput = $this->input('field_y');
        $fontSizeInput = $this->input('font_size');
        $alignmentInput = $this->input('alignment');
        if (!is_scalar($nameInput) || !is_scalar($positionInput) || !is_scalar($xInput)
            || !is_scalar($yInput) || !is_scalar($fontSizeInput) || !is_scalar($alignmentInput)) {
            return $this->actionError('Enter valid scalar signatory details and field placement values.', 'manage/certificate-config');
        }
        $name = trim((string) $nameInput);
        $position = trim((string) $positionInput);
        $x = filter_var($xInput, FILTER_VALIDATE_FLOAT);
        $y = filter_var($yInput, FILTER_VALIDATE_FLOAT);
        $fontSize = filter_var($fontSizeInput, FILTER_VALIDATE_INT);
        $alignment = (string) $alignmentInput;
        $isRequired = $this->input('is_required') === '1' ? 1 : 0;
        if ($signatoryId < 0 || !$userId || $name === '' || strlen($name) > 150
            || $position === '' || strlen($position) > 150
            || $x === false || $x < 0 || $x > 100 || $y === false || $y < 0 || $y > 100
            || $fontSize === false || $fontSize < 6 || $fontSize > 60
            || !in_array($alignment, ['L', 'C', 'R'], true)) {
            return $this->actionError('Enter a valid account, signatory name, position, and field placement.', 'manage/certificate-config');
        }

        $account = $this->certificateModel->row('SELECT user_id FROM users WHERE user_id = ? AND role IN ("administrator", "staff") AND status = "active"', [$userId]);
        if (!$account) {
            return $this->actionError('Choose an active administrator or staff account as the signatory.', 'manage/certificate-config');
        }
        $existing = $signatoryId ? $this->certificateModel->row('SELECT * FROM certificate_signatories WHERE signatory_id = ?', [$signatoryId]) : null;
        if ($signatoryId && !$existing) {
            return $this->actionError('The selected signatory was not found.', 'manage/certificate-config');
        }
        $assigned = $this->certificateModel->row('SELECT signatory_id FROM certificate_signatories WHERE user_id = ? AND signatory_id <> ? LIMIT 1', [$userId, $signatoryId]);
        if ($assigned) {
            return $this->actionError('That account is already assigned as a certificate signatory.', 'manage/certificate-config');
        }

        $upload = $this->inspectCertificateUpload('signature_image', ['png' => 'image/png']);
        if ($upload['error'] !== null) {
            return $this->actionError($upload['error'], 'manage/certificate-config');
        }
        if ($upload['path'] !== null && filesize($upload['path']) > 1024 * 1024) {
            return $this->actionError('Signature PNG files must be no larger than 1 MB.', 'manage/certificate-config');
        }
        if ($upload['path'] !== null) {
            $signatureInfo = getimagesize($upload['path']);
            if (!$signatureInfo || ($signatureInfo['mime'] ?? '') !== 'image/png' || ($signatureInfo['channels'] ?? 0) !== 4) {
                return $this->actionError('Upload a PNG signature with an alpha channel so its background remains transparent.', 'manage/certificate-config');
            }
        }

        $signaturePath = $existing['signature_path'] ?? '';
        if ($upload['path'] !== null) {
            $signaturePath = $this->storeSignatureUpload($upload['path']);
            if ($signaturePath === null) {
                return $this->actionError('Unable to store the signature in protected storage. Check folder permissions.', 'manage/certificate-config', 500);
            }
        } else {
            $canvasInput = $this->input('signature_data');
            if (!is_string($canvasInput)) {
                return $this->actionError('Invalid signature pad data.', 'manage/certificate-config');
            }
            $canvasData = $canvasInput;
            if ($canvasData !== '') {
                $signaturePath = $this->storeCanvasSignature($canvasData);
                if ($signaturePath === null) {
                    return $this->actionError('The drawn signature must be a valid transparent PNG no larger than 1 MB.', 'manage/certificate-config');
                }
            }
        }
        if ($signaturePath === '' || $signaturePath === null) {
            return $this->actionError('Upload a PNG signature or draw one on the signature pad.', 'manage/certificate-config');
        }

        $saved = $this->certificateModel->saveCertificateSignatory([
            'signatory_id' => $signatoryId,
            'user_id' => $userId,
            'name' => $name,
            'position' => $position,
            'signature_path' => $signaturePath,
            'field_x' => $x,
            'field_y' => $y,
            'font_size' => $fontSize,
            'alignment' => $alignment,
            'is_required' => $isRequired,
        ], (int) $this->user['user_id']);
        $this->recordModel->logActivity((int) $this->user['user_id'], 'Saved certificate signatory', 'Signatory ' . $name . ' (user #' . $userId . ')');
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => 'Certificate signatory saved.', 'signatory_id' => (int) ($saved['signatory_id'] ?? 0)]);
        }
        $this->notice('Certificate signatory saved.');
        redirect($this->url('manage/certificate-config'));
    }

    public function deactivateCertificateSignatory($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || $this->user['role'] !== 'administrator') {
            return $this->deny('Only administrators can deactivate certificate signatories.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            show_404();
            return;
        }
        if (!$this->certificateModel->deactivateCertificateSignatory((int) $id)) {
            return $this->actionError('The signatory could not be deactivated.', 'manage/certificate-config');
        }
        $this->recordModel->logActivity((int) $this->user['user_id'], 'Deactivated certificate signatory', 'Signatory #' . (int) $id);
        $this->notice('Certificate signatory deactivated.');
        redirect($this->url('manage/certificate-config'));
    }

    public function activateCertificateTemplate($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to activate certificate templates.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            return $this->actionError('Invalid certificate template version.', 'manage/certificate-config');
        }
        if (!$this->certificateModel->activateCertificateTemplate((int) $id)) {
            return $this->actionError('The certificate template version was not found.', 'manage/certificate-config', 404);
        }

        $template = $this->certificateModel->certificateTemplate((int) $id);
        $this->recordModel->logActivity((int) $this->user['user_id'], 'Activated certificate template', 'Template version ' . (int) $template['version']);
        $this->notice('Certificate template activated.');
        redirect($this->url('manage/certificate-config'));
    }

    public function certificateTemplatePreview()
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to preview certificate templates.');
        }
        $result = $this->certificateTemplateFromRequest(false);
        if ($result['error'] !== null) {
            return $this->actionError($result['error'], 'manage/certificate-config');
        }
        $template = $result['template'];
        $template['file_path'] = $this->resolveTemplateAssetPath($template['file_path'] ?? '');
        $template['settings']['logo_path'] = $this->resolveTemplateAssetPath($template['settings']['logo_path'] ?? '');
        $this->call->library('CertificateGenerator');
        try {
            $generator = new CertificateGenerator($template);
            $pdf = $generator->generate([
                'trainee_name' => 'Jordan Sample',
                'program_name' => 'Sample Training Program',
                'class_name' => 'Sample Class',
                'completion_date' => date('Y-m-d'),
                'issue_date' => date('Y-m-d'),
                'certificate_number' => 'CERT-PREVIEW-0001',
                'qr_code' => $this->certificateVerificationUrl('PREVIEW-CODE'),
            ]);
        } catch (RuntimeException $error) {
            return $this->actionError('The certificate preview could not be generated. Check the selected background and PDF dependencies.', 'manage/certificate-config');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="certificate-template-preview.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    public function previewCertificateTemplate($id)
    {
        if (!$this->requireUser() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to preview certificate templates.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            show_404();
            return;
        }
        $template = $this->certificateModel->certificateTemplate((int) $id);
        $path = $template ? $this->resolveTemplateAssetPath($template['file_path'] ?? '') : '';
        if (!$template || !$path || !is_file($path)) {
            show_404();
            return;
        }

        $mime = $template['file_mime'] ?? 'application/octet-stream';
        if (!in_array($mime, ['application/pdf', 'image/png', 'image/jpeg'], true)) {
            show_404();
            return;
        }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="certificate-template-v' . (int) $template['version'] . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    public function previewCertificateTemplateLogo($id)
    {
        if (!$this->requireUser() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to preview certificate templates.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            show_404();
            return;
        }
        $template = $this->certificateModel->certificateTemplate((int) $id);
        $path = $template ? $this->resolveTemplateAssetPath($template['settings']['logo_path'] ?? '') : '';
        if (!$template || !$path || !is_file($path)) {
            show_404();
            return;
        }
        $image = getimagesize($path);
        if (!$image || !in_array($image['mime'], ['image/png', 'image/jpeg'], true)) {
            show_404();
            return;
        }
        header('Content-Type: ' . $image['mime']);
        header('Content-Disposition: inline; filename="certificate-logo-v' . (int) $template['version'] . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    private function certificateTemplateFromRequest($persistUploads)
    {
        $sourceId = $_POST['source_template_id'] ?? '';
        $source = null;
        if ($sourceId !== '') {
            if (!is_scalar($sourceId) || !ctype_digit((string) $sourceId) || !(int) $sourceId) {
                return ['error' => 'Invalid source template version.', 'template' => null];
            }
            $source = $this->certificateModel->certificateTemplate((int) $sourceId);
            if (!$source) {
                return ['error' => 'The selected source template version was not found.', 'template' => null];
            }
        }

        $legacy = [
            'institution_name' => $this->configModel->getConfig('institution_name', 'LSI Training Center'),
            'logo_path' => $this->configModel->getConfig('logo_path', ''),
            'signatory_name' => $this->configModel->getConfig('signatory_name', 'Training Director'),
            'signatory_designation' => $this->configModel->getConfig('signatory_designation', 'Director'),
            'certificate_title' => $this->configModel->getConfig('certificate_title', 'Certificate of Completion'),
            'template_color' => $this->configModel->getConfig('template_color', '#dd4814'),
        ];
        $defaults = $this->certificateModel->defaultCertificateTemplate($legacy);
        $source = $source ?: $this->certificateModel->latestCertificateTemplate() ?: $defaults;
        $settingsInput = $_POST['settings'] ?? [];
        if (!is_array($settingsInput)) {
            return ['error' => 'Invalid certificate settings.', 'template' => null];
        }

        $mode = $this->input('mode');
        if ($mode === '' && $this->apiRequest) {
            $mode = 'generate';
            $settingsInput = array_merge($settingsInput, [
                'institution_name' => $_POST['institution_name'] ?? '',
                'title_text' => $_POST['certificate_title'] ?? '',
                'subtitle_text' => $_POST['certificate_subtitle'] ?? '',
                'body_text' => $_POST['certificate_body'] ?? 'This is to certify that {trainee_name} has successfully completed {program_name} on {date_completed}.',
                'signatory_name' => $_POST['signatory_name'] ?? '',
                'signatory_designation' => $_POST['signatory_designation'] ?? '',
                'accent_color' => $_POST['template_color'] ?? '#dd4814',
            ]);
        }
        if (!is_string($mode) || !in_array($mode, ['upload', 'generate'], true)) {
            return ['error' => 'Choose an upload or generated certificate template.', 'template' => null];
        }
        $sourceSettings = array_merge($defaults['settings'], $source['settings'] ?? []);
        $settings = [];
        foreach ([
            'institution_name' => 150,
            'title_text' => 150,
            'subtitle_text' => 150,
            'body_text' => 2000,
            'signatory_name' => 150,
            'signatory_designation' => 150,
        ] as $key => $maximum) {
            $value = $settingsInput[$key] ?? $sourceSettings[$key] ?? '';
            if (!is_scalar($value)) {
                return ['error' => 'Invalid value for ' . str_replace('_', ' ', $key) . '.', 'template' => null];
            }
            $value = trim((string) $value);
            if ($value === '' && $this->apiRequest) {
                $value = (string) ($sourceSettings[$key] ?? '');
            }
            if ($value === '' || strlen($value) > $maximum) {
                return ['error' => 'Enter ' . str_replace('_', ' ', $key) . ' using no more than ' . $maximum . ' characters.', 'template' => null];
            }
            $settings[$key] = $value;
        }

        preg_match_all('/\{([a-z_]+)\}/', $settings['body_text'], $placeholderMatches);
        $allowedPlaceholders = ['trainee_name', 'program_name', 'date_completed', 'cert_no', 'certificate_number', 'class_name', 'issue_date', 'signatory_name', 'signatory_title'];
        foreach ($placeholderMatches[1] as $placeholder) {
            if (!in_array($placeholder, $allowedPlaceholders, true)) {
                return ['error' => 'Unsupported certificate text placeholder: {' . $placeholder . '}.', 'template' => null];
            }
        }

        $settings['border_style'] = $settingsInput['border_style'] ?? $sourceSettings['border_style'] ?? 'double';
        if (!is_string($settings['border_style']) || !in_array($settings['border_style'], ['none', 'single', 'double', 'ornate'], true)) {
            return ['error' => 'Choose a valid certificate border style.', 'template' => null];
        }
        $settings['font_family'] = $settingsInput['font_family'] ?? $sourceSettings['font_family'] ?? 'helvetica';
        if (!is_string($settings['font_family']) || !in_array($settings['font_family'], ['helvetica', 'times', 'courier', 'dejavusans'], true)) {
            return ['error' => 'Choose a supported certificate font.', 'template' => null];
        }
        $settings['accent_color'] = $settingsInput['accent_color'] ?? $sourceSettings['accent_color'] ?? '#dd4814';
        if (!is_string($settings['accent_color']) || !preg_match('/^#[0-9a-fA-F]{6}$/', $settings['accent_color'])) {
            return ['error' => 'Enter a valid six-digit accent color.', 'template' => null];
        }

        $layoutInput = $_POST['field_layout'] ?? [];
        if (!is_array($layoutInput)) {
            return ['error' => 'Invalid field placement data.', 'template' => null];
        }
        $layout = array_merge($defaults['field_layout'], $source['field_layout'] ?? []);
        foreach (array_keys($defaults['field_layout']) as $fieldName) {
            $fieldInput = $layoutInput[$fieldName] ?? [];
            if (!is_array($fieldInput)) {
                return ['error' => 'Invalid position for ' . str_replace('_', ' ', $fieldName) . '.', 'template' => null];
            }
            foreach (['x', 'y'] as $coordinate) {
                $value = $fieldInput[$coordinate] ?? $layout[$fieldName][$coordinate];
                if (!is_scalar($value) || !is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
                    return ['error' => 'Field positions must be percentages between 0 and 100.', 'template' => null];
                }
                $layout[$fieldName][$coordinate] = round((float) $value, 2);
            }
            $fontSize = $fieldInput['font_size'] ?? $layout[$fieldName]['font_size'];
            if (!is_scalar($fontSize) || filter_var($fontSize, FILTER_VALIDATE_INT) === false || (int) $fontSize < 6 || (int) $fontSize > 60) {
                return ['error' => 'Field font sizes must be whole numbers from 6 to 60.', 'template' => null];
            }
            $layout[$fieldName]['font_size'] = (int) $fontSize;
            $align = $fieldInput['align'] ?? $layout[$fieldName]['align'];
            if (!is_string($align) || !in_array($align, ['L', 'C', 'R'], true)) {
                return ['error' => 'Choose left, center, or right alignment for each field.', 'template' => null];
            }
            $layout[$fieldName]['align'] = $align;
        }

        $minimumAttendanceValue = $_POST['min_attendance_percentage'] ?? $this->configModel->getConfig('min_attendance_percentage', 75);
        $requiredAssessmentsValue = $_POST['required_assessment_count'] ?? $this->configModel->getConfig('required_assessment_count', 1);
        $minimumAttendance = filter_var($minimumAttendanceValue, FILTER_VALIDATE_FLOAT);
        $requiredAssessments = filter_var($requiredAssessmentsValue, FILTER_VALIDATE_INT);
        if ($minimumAttendance === false || $minimumAttendance < 0 || $minimumAttendance > 100
            || $requiredAssessments === false || $requiredAssessments < 1 || $requiredAssessments > 100) {
            return ['error' => 'Set attendance between 0 and 100 percent and required assessments between 1 and 100.', 'template' => null];
        }

        $backgroundUpload = $this->inspectCertificateUpload('template_file', [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'pdf' => 'application/pdf',
        ]);
        if ($backgroundUpload['error'] !== null) {
            return ['error' => $backgroundUpload['error'], 'template' => null];
        }
        $logoUpload = $this->inspectCertificateUpload('logo', [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
        ]);
        if ($logoUpload['error'] !== null) {
            return ['error' => $logoUpload['error'], 'template' => null];
        }

        $filePath = null;
        $fileMime = null;
        if ($mode === 'upload') {
            if ($backgroundUpload['path'] !== null) {
                $fileMime = $backgroundUpload['mime'];
                $filePath = $persistUploads
                    ? $this->storeCertificateUpload($backgroundUpload)
                    : $backgroundUpload['path'];
                if (!$filePath) {
                    return ['error' => 'The certificate background could not be saved.', 'template' => null];
                }
            } elseif (($source['mode'] ?? '') === 'upload' && !empty($source['file_path'])) {
                if (!in_array($source['file_mime'] ?? '', ['application/pdf', 'image/png', 'image/jpeg'], true)
                    || !$this->resolveTemplateAssetPath($source['file_path'])) {
                    return ['error' => 'The saved certificate background is no longer available. Upload it again.', 'template' => null];
                }
                $filePath = $source['file_path'];
                $fileMime = $source['file_mime'];
            } else {
                return ['error' => 'Upload a PNG, JPG, or PDF background for upload mode.', 'template' => null];
            }
        }

        $logoPath = $sourceSettings['logo_path'] ?? '';
        if ($logoUpload['path'] !== null) {
            $logoPath = $persistUploads
                ? $this->storeCertificateUpload($logoUpload)
                : $logoUpload['path'];
            if (!$logoPath) {
                return ['error' => 'The institution logo could not be saved.', 'template' => null];
            }
        }
        $settings['logo_path'] = $logoPath;

        foreach (['require_documents', 'require_assessments'] as $checkbox) {
            if (array_key_exists($checkbox, $_POST)
                && (!is_scalar($_POST[$checkbox]) || !in_array((string) $_POST[$checkbox], ['0', '1'], true))) {
                return ['error' => 'Invalid eligibility requirement setting.', 'template' => null];
            }
        }
        $requireDocuments = array_key_exists('require_documents', $_POST)
            ? (bool) $this->input('require_documents')
            : ($this->apiRequest ? (bool) $this->configModel->getConfig('require_documents', 1) : false);
        $requireAssessments = array_key_exists('require_assessments', $_POST)
            ? (bool) $this->input('require_assessments')
            : ($this->apiRequest ? (bool) $this->configModel->getConfig('require_assessments', 0) : false);
        return [
            'error' => null,
            'template' => [
                'mode' => $mode,
                'file_path' => $filePath,
                'file_mime' => $fileMime,
                'field_layout' => $layout,
                'settings' => $settings,
            ],
            'eligibility_config' => [
                'min_attendance_percentage' => (string) $minimumAttendance,
                'require_documents' => $requireDocuments ? '1' : '0',
                'require_assessments' => $requireAssessments ? '1' : '0',
                'required_assessment_count' => (string) $requiredAssessments,
            ],
        ];
    }

    private function inspectCertificateUpload($field, array $allowedTypes)
    {
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
            return ['path' => null, 'mime' => null, 'extension' => null, 'error' => null];
        }
        $upload = $_FILES[$field];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['path' => null, 'mime' => null, 'extension' => null, 'error' => null];
        }
        if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
            return ['path' => null, 'mime' => null, 'extension' => null, 'error' => 'The uploaded file could not be read.'];
        }

        $extension = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
        $size = filesize($upload['tmp_name']);
        if (!isset($allowedTypes[$extension]) || $size === false || $size < 1 || $size > 5 * 1024 * 1024) {
            return ['path' => null, 'mime' => null, 'extension' => null, 'error' => 'Upload a supported file no larger than 5 MB.'];
        }
        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $fileInfo->file($upload['tmp_name']);
        if ($mime !== $allowedTypes[$extension]) {
            return ['path' => null, 'mime' => null, 'extension' => null, 'error' => 'The file extension does not match its actual file type.'];
        }
        if ($mime !== 'application/pdf' && getimagesize($upload['tmp_name']) === false) {
            return ['path' => null, 'mime' => null, 'extension' => null, 'error' => 'The uploaded image file is invalid.'];
        }
        if ($mime === 'application/pdf') {
            $handle = fopen($upload['tmp_name'], 'rb');
            $signature = $handle ? fread($handle, 5) : false;
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($signature !== '%PDF-') {
                return ['path' => null, 'mime' => null, 'extension' => null, 'error' => 'The uploaded PDF file is invalid.'];
            }
        }

        return ['path' => $upload['tmp_name'], 'mime' => $mime, 'extension' => $extension, 'error' => null];
    }

    private function storeCertificateUpload(array $upload)
    {
        $directory = ROOT_DIR . 'runtime/uploads/private/certificate_templates/';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            return null;
        }
        if (!is_writable($directory)) {
            return null;
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $upload['extension'];
        if (!move_uploaded_file($upload['path'], $directory . $filename)) {
            return null;
        }
        return 'runtime/uploads/private/certificate_templates/' . $filename;
    }

    private function resolveTemplateAssetPath($relativePath)
    {
        $relativePath = (string) $relativePath;
        if ($relativePath === '') {
            return '';
        }
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $relativePath)) {
            return is_file($relativePath) ? realpath($relativePath) : '';
        }

        $path = realpath(ROOT_DIR . ltrim($relativePath, '/\\'));
        if (!$path || !is_file($path)) {
            return '';
        }
        $privateRoot = realpath(ROOT_DIR . 'runtime/uploads/private/certificate_templates');
        $legacyRoot = realpath(ROOT_DIR . 'runtime/uploads/certificates');
        foreach ([$privateRoot, $legacyRoot] as $allowedRoot) {
            if ($allowedRoot && strpos($path, $allowedRoot . DIRECTORY_SEPARATOR) === 0) {
                return $path;
            }
        }
        return '';
    }

    public function myCertificates()
    {
        if (!$this->requireUser() || $this->user['role'] !== 'trainee') {
            return $this->deny('Only trainees can access their certificates.');
        }

        $trainee = $this->certificateModel->row('SELECT trainee_id FROM trainees WHERE user_id = ?', [(int) $this->user['user_id']]);
        if (!$trainee) {
            return $this->deny('Trainee record not found.');
        }

        $records = $this->certificateModel->rows('SELECT c.*, p.program_name, cl.class_name FROM certificates c JOIN training_programs p ON p.program_id = c.program_id JOIN training_classes cl ON cl.class_id = c.class_id WHERE c.trainee_id = ? ORDER BY c.issue_date DESC', [(int) $trainee['trainee_id']]);

        $eligibility = $this->eligibilityModel->rows('SELECT e.*, p.program_name, cl.class_name FROM certificate_eligibility e JOIN training_programs p ON p.program_id = e.program_id JOIN training_classes cl ON cl.class_id = e.class_id WHERE e.trainee_id = ? ORDER BY e.updated_at DESC', [(int) $trainee['trainee_id']]);

        $this->render('module', [
            'title' => 'My Certificates',
            'section' => 'my_certificates',
            'records' => $records,
            'eligibility' => $eligibility,
            'certificate_notifications' => $this->certificateModel->traineeCertificateNotifications((int) $this->user['user_id']),
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

        $certificate = $this->certificateModel->row('SELECT c.*, t.user_id FROM certificates c JOIN trainees t ON t.trainee_id = c.trainee_id WHERE c.certificate_id = ?', [(int) $id]);
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
            $message = $certificate['status'] === 'revoked'
                ? 'This certificate has been revoked.'
                : 'This certificate is not available until all required signatories have approved it.';
            $this->notice($message);
            redirect($this->url($this->user['role'] === 'trainee' ? 'my-certificates' : 'manage/certificates'));
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
            $certificate = $this->certificateModel->row('SELECT c.*, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, p.program_name, cl.class_name FROM certificates c JOIN trainees t ON t.trainee_id = c.trainee_id JOIN training_programs p ON p.program_id = c.program_id JOIN training_classes cl ON cl.class_id = c.class_id WHERE c.qr_code = ?', [$qrCode]);
        } elseif ($certificateNumber) {
            $certificate = $this->certificateModel->row('SELECT c.*, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, p.program_name, cl.class_name FROM certificates c JOIN trainees t ON t.trainee_id = c.trainee_id JOIN training_programs p ON p.program_id = c.program_id JOIN training_classes cl ON cl.class_id = c.class_id WHERE c.certificate_number = ?', [$certificateNumber]);
        }

        $this->render('verify_certificate', [
            'title' => 'Certificate Verification',
            'page' => 'verify_certificate',
            'certificate' => $certificate,
            'signature_verification' => $certificate
                ? $this->certificateModel->certificateSignatureVerification((int) $certificate['certificate_id'])
                : ['signatures' => [], 'hash_valid' => null],
            'app_url' => $this->url(''),
        ]);
    }

    private function storeSignatureUpload($temporaryPath)
    {
        $directory = ROOT_DIR . 'runtime/uploads/private/certificate_signatures/';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            return null;
        }
        if (!is_writable($directory)) {
            return null;
        }
        $filename = bin2hex(random_bytes(24)) . '.png';
        $destination = $directory . $filename;
        if (!move_uploaded_file($temporaryPath, $destination)) {
            return null;
        }
        chmod($destination, 0640);
        return 'runtime/uploads/private/certificate_signatures/' . $filename;
    }

    private function storeCanvasSignature($dataUri)
    {
        if (strlen($dataUri) > 1400000
            || !preg_match('~^data:image/png;base64,([A-Za-z0-9+/]+={0,2})$~', $dataUri, $matches)) {
            return null;
        }
        $contents = base64_decode($matches[1], true);
        if ($contents === false || strlen($contents) < 33 || strlen($contents) > 1024 * 1024
            || substr($contents, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return null;
        }
        $image = getimagesizefromstring($contents);
        if (!$image || ($image['mime'] ?? '') !== 'image/png' || ($image['channels'] ?? 0) !== 4) {
            return null;
        }

        $directory = ROOT_DIR . 'runtime/uploads/private/certificate_signatures/';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            return null;
        }
        if (!is_writable($directory)) {
            return null;
        }
        $filename = bin2hex(random_bytes(24)) . '.png';
        $destination = $directory . $filename;
        if (file_put_contents($destination, $contents, LOCK_EX) !== strlen($contents)) {
            return null;
        }
        chmod($destination, 0640);
        return 'runtime/uploads/private/certificate_signatures/' . $filename;
    }

    private function resolveSignatureAssetPath($relativePath)
    {
        $relativePath = (string) $relativePath;
        if ($relativePath === '' || preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $relativePath)) {
            return '';
        }
        $path = realpath(ROOT_DIR . ltrim($relativePath, '/\\'));
        $privateRoot = realpath(ROOT_DIR . 'runtime/uploads/private/certificate_signatures');
        if (!$path || !$privateRoot || !is_file($path)
            || strpos($path, $privateRoot . DIRECTORY_SEPARATOR) !== 0) {
            return '';
        }
        return $path;
    }
}
