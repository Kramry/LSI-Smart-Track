<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';

class Certificate_model extends Base_model
{
    private $eligibilityModel;
    private $configModel;

    public function __construct($db, $eligibilityModel = null, $configModel = null)
    {
        parent::__construct($db);
        $this->eligibilityModel = $eligibilityModel;
        $this->configModel = $configModel;
    }

    public function certificateStats()
    {
        $eligible = $this->db->table('certificate_eligibility')->select_count('*', 'count')->where('is_eligible', 1)->get();
        $pending = $this->db->table('certificate_eligibility')->select_count('*', 'count')->where(['is_eligible' => 1, 'approval_status' => 'pending'])->get();
        $issued = $this->db->table('certificates')->select_count('*', 'count')->where('status', 'issued')->get();
        $revoked = $this->db->table('certificates')->select_count('*', 'count')->where('status', 'revoked')->get();
        $signing = $this->db->table('certificates')->select_count('*', 'count')->where('status', 'pending_signature')->get();

        return [
            'eligible' => (int) ($eligible['count'] ?? 0),
            'pending' => (int) ($pending['count'] ?? 0),
            'issued' => (int) ($issued['count'] ?? 0),
            'revoked' => (int) ($revoked['count'] ?? 0),
            'signing' => (int) ($signing['count'] ?? 0),
        ];
    }

    public function getCertificates(array $filters = [])
    {
        $sql = "SELECT e.*, c.certificate_id, c.certificate_number, c.issue_date, c.revocation_reason, c.revoked_at, (SELECT sig.remarks FROM certificate_signatures sig WHERE sig.certificate_id = c.certificate_id AND sig.decision = 'rejected' ORDER BY sig.signature_id DESC LIMIT 1) AS signature_rejection_remarks, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, t.qr_code AS trainee_number, p.program_name, cl.class_name, cl.end_date AS completion_date, CASE WHEN c.certificate_id IS NOT NULL THEN c.status WHEN e.is_eligible = 0 THEN 'ineligible' ELSE e.approval_status END AS status FROM certificate_eligibility e JOIN trainees t ON t.trainee_id = e.trainee_id JOIN training_programs p ON p.program_id = e.program_id JOIN training_classes cl ON cl.class_id = e.class_id LEFT JOIN certificates c ON c.trainee_id = e.trainee_id AND c.class_id = e.class_id";
        $values = [];
        $whereClauses = [];

        if (!empty($filters['search'])) {
            $whereClauses[] = "(CONCAT(t.first_name, ' ', t.last_name) LIKE ? OR t.qr_code LIKE ? OR c.certificate_number LIKE ? OR p.program_name LIKE ? OR cl.class_name LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            for ($i = 0; $i < 5; $i++) {
                $values[] = $searchTerm;
            }
        }

        $status = $filters['status'] ?? '';
        if (in_array($status, ['draft', 'pending_signature', 'signed', 'issued', 'revoked'], true)) {
            $whereClauses[] = 'c.status = ?';
            $values[] = $status;
        } elseif ($status === 'pending') {
            $whereClauses[] = "c.certificate_id IS NULL AND e.is_eligible = 1 AND e.approval_status = 'pending'";
        } elseif ($status === 'approved') {
            $whereClauses[] = "c.certificate_id IS NULL AND e.is_eligible = 1 AND e.approval_status = 'approved'";
        } elseif ($status === 'rejected') {
            $whereClauses[] = "c.certificate_id IS NULL AND e.is_eligible = 1 AND e.approval_status = 'rejected'";
        } elseif ($status === 'ineligible') {
            $whereClauses[] = 'c.certificate_id IS NULL AND e.is_eligible = 0';
        }

        if (!empty($filters['program_id'])) {
            $whereClauses[] = 'e.program_id = ?';
            $values[] = (int) $filters['program_id'];
        }

        if (!empty($whereClauses)) {
            $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
        }

        $sql .= ' ORDER BY e.updated_at DESC, c.issue_date DESC';

        $records = $this->rows($sql, $values);
        foreach ($records as &$record) {
            $record['completion_status'] = !empty($record['completion_date']) && $record['completion_date'] <= date('Y-m-d')
                ? 'Completed'
                : 'In progress';
        }
        unset($record);
        return $records;
    }

    public function generateCertificateNumber()
    {
        $prefix = 'CERT';
        $year = date('Y');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $random = strtoupper(bin2hex(random_bytes(8)));
            $certificateNumber = sprintf('%s-%s-%s', $prefix, $year, $random);
            if (!$this->db->table('certificates')->select('certificate_id')->where('certificate_number', $certificateNumber)->get()) {
                return $certificateNumber;
            }
        }
        throw new RuntimeException('A unique certificate number could not be generated.');
    }

    public function getActiveTemplate()
    {
        $template = $this->db->table('certificate_templates')->where('is_active', 1)->order_by('version', 'DESC')->limit(1)->get();
        return $this->decodeTemplate($template);
    }

    public function getLatestTemplate()
    {
        $template = $this->db->table('certificate_templates')->order_by('version', 'DESC')->limit(1)->get();
        return $this->decodeTemplate($template);
    }

    public function getTemplate($templateId)
    {
        $template = $this->db->table('certificate_templates')->where('template_id', (int) $templateId)->get();
        return $this->decodeTemplate($template);
    }

    public function getTemplateVersions()
    {
        $templates = $this->db->table('certificate_templates')
            ->select('template_id, mode, file_path, settings, is_active, version, created_by, created_at')
            ->order_by('version DESC')
            ->get_all();
        $issuedTemplateIds = $this->db->table('certificates')
            ->select('template_id')
            ->where_not_null('template_id')
            ->get_all();
        $issuedTemplates = [];
        foreach ($issuedTemplateIds as $issuedTemplate) {
            $issuedTemplates[(int) $issuedTemplate['template_id']] = true;
        }
        foreach ($templates as &$template) {
            $template['has_issued_certificates'] = isset($issuedTemplates[(int) $template['template_id']]) ? 1 : 0;
            $template['settings'] = json_decode($template['settings'], true) ?: [];
        }
        unset($template);
        return $templates;
    }

    public function saveTemplate(array $template, $createdBy, $activate = false)
    {
        $this->db->transaction();
        try {
            // Serialize version allocation; the builder cannot express SELECT FOR UPDATE.
            $latest = $this->row('SELECT version FROM certificate_templates ORDER BY version DESC LIMIT 1 FOR UPDATE');
            $version = (int) ($latest['version'] ?? 0) + 1;
            if ($activate) {
                $this->db->table('certificate_templates')->where('is_active', 1)->update(['is_active' => 0]);
            }
            $this->db->table('certificate_templates')->insert([
                'mode' => $template['mode'],
                'file_path' => $template['file_path'] ?: null,
                'field_layout' => json_encode($template['field_layout'], JSON_THROW_ON_ERROR),
                'settings' => json_encode($template['settings'], JSON_THROW_ON_ERROR),
                'is_active' => $activate ? 1 : 0,
                'version' => $version,
                'created_by' => (int) $createdBy,
            ]);
            $saved = $this->db->table('certificate_templates')->where('version', $version)->get();
            if (!$saved) {
                throw new RuntimeException('The saved certificate template version could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        return $this->decodeTemplate($saved);
    }

    public function activateTemplate($templateId)
    {
        $template = $this->getTemplate($templateId);
        if (!$template) {
            return false;
        }

        $this->db->transaction();
        try {
            $this->db->table('certificate_templates')->where('is_active', 1)->update(['is_active' => 0]);
            $this->db->table('certificate_templates')->where('template_id', (int) $templateId)->update(['is_active' => 1]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        return $template;
    }

    public function deleteTemplate($templateId)
    {
        $this->db->transaction();
        try {
            // Keep the template locked while checking its state and deleting it.
            $template = $this->row('SELECT template_id, version, is_active FROM certificate_templates WHERE template_id = ? FOR UPDATE', [(int) $templateId]);
            if (!$template) {
                $this->db->commit();
                return ['deleted' => false, 'reason' => 'not_found'];
            }
            if ((int) $template['is_active'] === 1) {
                $this->db->commit();
                return ['deleted' => false, 'reason' => 'active'];
            }
            $issued = $this->db->table('certificates')->select('certificate_id')->where('template_id', (int) $templateId)->limit(1)->get();
            if ($issued) {
                $this->db->commit();
                return ['deleted' => false, 'reason' => 'issued'];
            }

            $this->db->table('certificate_templates')->where('template_id', (int) $templateId)->delete();
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        return ['deleted' => true, 'version' => (int) $template['version']];
    }

    public function legacyTemplate()
    {
        $settings = [
            'institution_name' => $this->configModel->getConfig('institution_name', 'LSI Training Center'),
            'logo_path' => $this->configModel->getConfig('logo_path', ''),
            'title_text' => $this->configModel->getConfig('certificate_title', 'Certificate of Completion'),
            'body_text' => 'This certifies that {trainee_name} has successfully completed {program_name} on {date_completed}. Certificate No. {cert_no}.',
            'signatory_name' => $this->configModel->getConfig('signatory_name', 'Training Director'),
            'signatory_title' => $this->configModel->getConfig('signatory_designation', 'Director'),
            'border_style' => 'double',
            'accent_color' => $this->configModel->getConfig('template_color', '#dd4814'),
            'font' => 'helvetica',
        ];
        return [
            'template_id' => null,
            'mode' => 'generated',
            'file_path' => null,
            'field_layout' => [],
            'settings' => $settings,
            'is_active' => 1,
            'version' => 0,
        ];
    }

    public function hasActiveSignatories()
    {
        $total = $this->db->table('certificate_signatories s')
            ->select_count('*', 'total')
            ->join('users u', 'u.user_id = s.user_id')
            ->where(['s.is_active' => 1, 'u.status' => 'active'])
            ->in('u.role', ['administrator', 'staff'])
            ->get();
        return (int) ($total['total'] ?? 0) > 0;
    }

    public function signatoryAccounts()
    {
        return $this->db->table('users')
            ->select('user_id AS id, full_name AS label')
            ->where('status', 'active')
            ->in('role', ['administrator', 'staff'])
            ->order_by('full_name')
            ->get_all();
    }

    public function signatories()
    {
        return $this->db->table('certificate_signatories s')
            ->select('s.signatory_id, s.user_id, s.name, s.position, s.signature_path, s.field_x, s.field_y, s.font_size, s.alignment, s.is_required, s.is_active, s.created_by, s.created_at, s.updated_at, s.x_position, s.y_position, u.full_name AS account_name, u.username')
            ->join('users u', 'u.user_id = s.user_id')
            ->order_by('s.name')
            ->get_all();
    }

    public function saveSignatory(array $signatory, $createdBy, $signatoryId = null)
    {
        if ($signatoryId) {
            $this->db->table('certificate_signatories')
                ->where('signatory_id', (int) $signatoryId)
                ->update([
                    'user_id' => (int) $signatory['user_id'],
                    'name' => $signatory['name'],
                    'position' => $signatory['position'],
                    'signature_path' => $signatory['signature_path'],
                    'x_position' => $signatory['x_position'],
                    'y_position' => $signatory['y_position'],
                    'is_active' => 1,
                ]);
            return (int) $signatoryId;
        }
        return (int) $this->db->table('certificate_signatories')->insert([
            'user_id' => (int) $signatory['user_id'],
            'name' => $signatory['name'],
            'position' => $signatory['position'],
            'signature_path' => $signatory['signature_path'],
            'x_position' => $signatory['x_position'],
            'y_position' => $signatory['y_position'],
            'created_by' => (int) $createdBy,
        ]);
    }

    public function deleteSignatory($signatoryId)
    {
        return $this->db->table('certificate_signatories')->where('signatory_id', (int) $signatoryId)->update(['is_active' => 0]);
    }

    public function eligibilityById($eligibilityId)
    {
        return $this->db->table('certificate_eligibility')
            ->select('trainee_id, class_id')
            ->where('eligibility_id', (int) $eligibilityId)
            ->get() ?: null;
    }

    public function certificateStatusForTraineeClass($traineeId, $classId)
    {
        return $this->db->table('certificates')
            ->select('status')
            ->where(['trainee_id' => (int) $traineeId, 'class_id' => (int) $classId])
            ->get() ?: null;
    }

    public function signingAccount($userId)
    {
        return $this->db->table('users')
            ->select('password, status')
            ->where('user_id', (int) $userId)
            ->get() ?: null;
    }

    public function certificateNumber($certificateId)
    {
        return $this->db->table('certificates')
            ->select('certificate_number')
            ->where('certificate_id', (int) $certificateId)
            ->get() ?: null;
    }

    public function certificateClasses()
    {
        return $this->db->table('training_classes')
            ->select('class_id AS id, class_name AS label')
            ->order_by('class_name')
            ->get_all();
    }

    public function activeSignatoryAccount($userId)
    {
        return $this->db->table('users')
            ->select('user_id')
            ->where(['user_id' => (int) $userId, 'status' => 'active'])
            ->in('role', ['administrator', 'staff'])
            ->get() ?: null;
    }

    public function signatoryById($signatoryId)
    {
        return $this->db->table('certificate_signatories')
            ->where('signatory_id', (int) $signatoryId)
            ->get() ?: null;
    }

    public function signatoryForUserExcept($userId, $signatoryId)
    {
        return $this->db->table('certificate_signatories')
            ->select('signatory_id')
            ->where('user_id', (int) $userId)
            ->where('signatory_id', '<>', (int) $signatoryId)
            ->get() ?: null;
    }

    public function signatoryNameAndPosition($signatoryId)
    {
        return $this->db->table('certificate_signatories')
            ->select('name, position')
            ->where('signatory_id', (int) $signatoryId)
            ->get() ?: null;
    }

    public function traineeForUser($userId)
    {
        return $this->db->table('trainees')
            ->select('trainee_id')
            ->where('user_id', (int) $userId)
            ->get() ?: null;
    }

    public function traineeCertificates($traineeId)
    {
        return $this->db->table('certificates c')
            ->select('c.certificate_id, c.trainee_id, c.class_id, c.program_id, c.certificate_number, c.completion_date, c.issue_date, c.status, c.file_path, c.qr_code, c.template_id, c.revoked_at, c.revoked_by, c.revocation_reason, c.created_at, c.template_snapshot, p.program_name, cl.class_name')
            ->join('training_programs p', 'p.program_id = c.program_id')
            ->join('training_classes cl', 'cl.class_id = c.class_id')
            ->where('c.trainee_id', (int) $traineeId)
            ->order_by('c.issue_date DESC')
            ->get_all();
    }

    public function traineeEligibilityRecords($traineeId)
    {
        return $this->db->table('certificate_eligibility e')
            ->select('e.eligibility_id, e.trainee_id, e.class_id, e.program_id, e.attendance_percentage, e.documents_submitted, e.documents_required, e.documents_approved, e.assessments_completed, e.assessments_required, e.is_eligible, e.ineligibility_reasons, e.approval_status, e.approval_reason, e.approved_by, e.approved_at, e.reviewed_by, e.reviewed_at, e.created_at, e.updated_at, p.program_name, cl.class_name')
            ->join('training_programs p', 'p.program_id = e.program_id')
            ->join('training_classes cl', 'cl.class_id = e.class_id')
            ->where('e.trainee_id', (int) $traineeId)
            ->order_by('e.updated_at DESC')
            ->get_all();
    }

    public function downloadableCertificate($certificateId)
    {
        return $this->db->table('certificates c')
            ->select('c.certificate_id, c.trainee_id, c.class_id, c.program_id, c.certificate_number, c.completion_date, c.issue_date, c.status, c.file_path, c.qr_code, c.template_id, c.revoked_at, c.revoked_by, c.revocation_reason, c.created_at, c.template_snapshot, t.user_id')
            ->join('trainees t', 't.trainee_id = c.trainee_id')
            ->where('c.certificate_id', (int) $certificateId)
            ->get() ?: null;
    }

    public function certificateForVerificationByQrCode($qrCode)
    {
        return $this->certificateWithTraineeName('c.qr_code', $qrCode);
    }

    public function certificateForVerificationByNumber($certificateNumber)
    {
        return $this->certificateWithTraineeName('c.certificate_number', $certificateNumber);
    }

    public function certificatePrintDetails($certificateId)
    {
        $record = $this->db->table('certificates cert')
            ->select('t.first_name, t.last_name, p.program_name, c.class_name')
            ->join('trainees t', 't.trainee_id = cert.trainee_id')
            ->join('training_programs p', 'p.program_id = cert.program_id')
            ->join('training_classes c', 'c.class_id = cert.class_id')
            ->where('cert.certificate_id', (int) $certificateId)
            ->get();
        if (!$record) {
            return null;
        }
        $record['trainee_name'] = trim($record['first_name'] . ' ' . $record['last_name']);
        unset($record['first_name'], $record['last_name']);
        return $record;
    }

    private function certificateWithTraineeName($lookupColumn, $lookupValue)
    {
        if (!in_array($lookupColumn, ['c.qr_code', 'c.certificate_number'], true)) {
            throw new InvalidArgumentException('Unsupported certificate lookup.');
        }
        $certificate = $this->db->table('certificates c')
            ->select('c.certificate_id, c.trainee_id, c.class_id, c.program_id, c.certificate_number, c.completion_date, c.issue_date, c.status, c.file_path, c.qr_code, c.template_id, c.revoked_at, c.revoked_by, c.revocation_reason, c.created_at, c.template_snapshot, t.first_name, t.last_name, p.program_name, cl.class_name')
            ->join('trainees t', 't.trainee_id = c.trainee_id')
            ->join('training_programs p', 'p.program_id = c.program_id')
            ->join('training_classes cl', 'cl.class_id = c.class_id')
            ->where($lookupColumn, $lookupValue)
            ->get();
        if (!$certificate) {
            return null;
        }
        $certificate['trainee_name'] = trim($certificate['first_name'] . ' ' . $certificate['last_name']);
        unset($certificate['first_name'], $certificate['last_name']);
        return $certificate;
    }

    public function createPendingCertificate($traineeId, $classId, $createdBy)
    {
        $currentEligibility = $this->eligibilityModel->calculateEligibility((int) $traineeId, (int) $classId);
        $eligibility = $this->db->table('certificate_eligibility')
            ->where(['trainee_id' => (int) $traineeId, 'class_id' => (int) $classId])
            ->get();
        if (!$currentEligibility['eligible'] || !$eligibility || $eligibility['approval_status'] !== 'approved' || !$this->hasActiveSignatories()) {
            return false;
        }

        if ($this->db->table('certificates')->select('certificate_id')
            ->where(['trainee_id' => (int) $traineeId, 'class_id' => (int) $classId])
            ->get()) {
            return false;
        }
        $class = $this->db->table('training_classes')
            ->where('class_id', (int) $classId)
            ->get();
        if (!$class || empty($class['end_date'])) {
            return false;
        }

        $certificateNumber = $this->generateCertificateNumber();
        $qrCode = bin2hex(random_bytes(32));
        $template = $this->getActiveTemplate() ?: $this->legacyTemplate();
        $templateSnapshot = json_encode($template, JSON_THROW_ON_ERROR);
        $signatories = $this->db->table('certificate_signatories s')
            ->select('s.signatory_id, s.user_id, s.name, s.position, s.signature_path, s.x_position, s.y_position')
            ->join('users u', 'u.user_id = s.user_id')
            ->where(['s.is_active' => 1, 'u.status' => 'active'])
            ->in('u.role', ['administrator', 'staff'])
            ->order_by('s.signatory_id')
            ->get_all();
        if (!$signatories) {
            return false;
        }

        $this->db->transaction();
        try {
            $this->execute("INSERT INTO certificates (trainee_id, class_id, program_id, certificate_number, completion_date, issue_date, status, qr_code, template_id, template_snapshot) VALUES (?, ?, ?, ?, ?, CURDATE(), 'draft', ?, ?, ?)", [
                (int) $traineeId,
                (int) $classId,
                (int) $class['program_id'],
                $certificateNumber,
                $class['end_date'],
                $qrCode,
                $template['template_id'] ?: null,
                $templateSnapshot,
            ]);
            $certificateId = (int) $this->db->last_id();
            foreach ($signatories as $signatory) {
                $this->db->table('certificate_signatures')->insert([
                    'certificate_id' => $certificateId,
                    'signatory_id' => (int) $signatory['signatory_id'],
                    'signatory_user_id' => (int) $signatory['user_id'],
                    'signatory_name' => $signatory['name'],
                    'signatory_position' => $signatory['position'],
                    'signature_path' => $signatory['signature_path'],
                    'x_position' => $signatory['x_position'],
                    'y_position' => $signatory['y_position'],
                    'decision' => 'pending',
                ]);
                $this->db->table('notifications')->insert([
                    'user_id' => (int) $signatory['user_id'],
                    'message' => 'Certificate ' . $certificateNumber . ' is ready for your signature.',
                    'notification_type' => 'certificate_signature',
                ]);
            }
            $this->db->table('certificates')->where('certificate_id', $certificateId)->update(['status' => 'pending_signature']);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        return $this->db->table('certificates')->where('certificate_id', $certificateId)->get();
    }

    public function signatureQueue($userId)
    {
        return $this->rows("SELECT c.certificate_id, c.certificate_number, c.completion_date, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, p.program_name, cl.class_name, s.signature_id FROM certificate_signatures s JOIN certificates c ON c.certificate_id = s.certificate_id JOIN trainees t ON t.trainee_id = c.trainee_id JOIN training_programs p ON p.program_id = c.program_id JOIN training_classes cl ON cl.class_id = c.class_id WHERE s.signatory_user_id = ? AND s.decision = 'pending' AND c.status = 'pending_signature' AND NOT EXISTS (SELECT 1 FROM certificate_signatures newer WHERE newer.certificate_id = s.certificate_id AND newer.signatory_id = s.signatory_id AND newer.signature_id > s.signature_id) ORDER BY c.created_at ASC", [(int) $userId]);
    }

    public function certificateForSigning($certificateId, $userId)
    {
        return $this->row("SELECT c.*, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, p.program_name, cl.class_name, s.signature_id, s.signatory_name, s.signatory_position, s.signature_path, s.x_position, s.y_position FROM certificate_signatures s JOIN certificates c ON c.certificate_id = s.certificate_id JOIN trainees t ON t.trainee_id = c.trainee_id JOIN training_programs p ON p.program_id = c.program_id JOIN training_classes cl ON cl.class_id = c.class_id WHERE s.certificate_id = ? AND s.signatory_user_id = ? AND s.decision = 'pending' AND c.status = 'pending_signature' AND NOT EXISTS (SELECT 1 FROM certificate_signatures newer WHERE newer.certificate_id = s.certificate_id AND newer.signatory_id = s.signatory_id AND newer.signature_id > s.signature_id) LIMIT 1", [(int) $certificateId, (int) $userId]);
    }

    public function signCertificate($certificateId, $userId)
    {
        $this->db->transaction();
        try {
            // Lock certificate and active signature slot to prevent duplicate or conflicting decisions.
            $certificate = $this->row("SELECT c.*, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, p.program_name FROM certificates c JOIN trainees t ON t.trainee_id = c.trainee_id JOIN training_programs p ON p.program_id = c.program_id WHERE c.certificate_id = ? FOR UPDATE", [(int) $certificateId]);
            $slot = $this->row("SELECT s.* FROM certificate_signatures s WHERE s.certificate_id = ? AND s.signatory_user_id = ? AND s.decision = 'pending' AND NOT EXISTS (SELECT 1 FROM certificate_signatures newer WHERE newer.certificate_id = s.certificate_id AND newer.signatory_id = s.signatory_id AND newer.signature_id > s.signature_id) ORDER BY s.signature_id DESC LIMIT 1 FOR UPDATE", [(int) $certificateId, (int) $userId]);
            if (!$certificate || $certificate['status'] !== 'pending_signature' || !$slot) {
                $this->db->roll_back();
                return ['success' => false, 'message' => 'This certificate is not assigned to you for signing.'];
            }
            $dataHash = $this->certificateDataHash($certificate);
            $this->execute("UPDATE certificate_signatures SET decision = 'signed', signed_at = CURRENT_TIMESTAMP, ip_address = ?, certificate_data_hash = ? WHERE signature_id = ?", [
                substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                $dataHash,
                (int) $slot['signature_id'],
            ]);
            $remaining = (int) ($this->row("SELECT COUNT(*) AS total FROM certificate_signatures s WHERE s.certificate_id = ? AND s.decision <> 'signed' AND NOT EXISTS (SELECT 1 FROM certificate_signatures newer WHERE newer.certificate_id = s.certificate_id AND newer.signatory_id = s.signatory_id AND newer.signature_id > s.signature_id)", [(int) $certificateId])['total'] ?? 0);
            $issued = $remaining === 0;
            if ($issued) {
                $this->db->table('certificates')->where('certificate_id', (int) $certificateId)->update(['status' => 'signed']);
                $this->execute("UPDATE certificates SET status = 'issued', issue_date = CURDATE() WHERE certificate_id = ?", [(int) $certificateId]);
                $trainee = $this->db->table('trainees t')
                    ->select('t.user_id')
                    ->join('certificates c', 'c.trainee_id = t.trainee_id')
                    ->where('c.certificate_id', (int) $certificateId)
                    ->get();
                if (!empty($trainee['user_id'])) {
                    $this->db->table('notifications')->insert([
                        'user_id' => (int) $trainee['user_id'],
                        'message' => 'Your certificate ' . $certificate['certificate_number'] . ' has been signed and issued.',
                        'notification_type' => 'certificate_issued',
                    ]);
                }
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        return ['success' => true, 'issued' => $issued, 'certificate' => $this->db->table('certificates')->where('certificate_id', (int) $certificateId)->get()];
    }

    public function rejectCertificateSignature($certificateId, $userId, $remarks)
    {
        $this->db->transaction();
        try {
            // Keep the rejection and slot-state check atomic against concurrent signing.
            $certificate = $this->row('SELECT certificate_id, status FROM certificates WHERE certificate_id = ? FOR UPDATE', [(int) $certificateId]);
            $slot = $this->row("SELECT s.signature_id FROM certificate_signatures s WHERE s.certificate_id = ? AND s.signatory_user_id = ? AND s.decision = 'pending' AND NOT EXISTS (SELECT 1 FROM certificate_signatures newer WHERE newer.certificate_id = s.certificate_id AND newer.signatory_id = s.signatory_id AND newer.signature_id > s.signature_id) ORDER BY s.signature_id DESC LIMIT 1 FOR UPDATE", [(int) $certificateId, (int) $userId]);
            if (!$certificate || $certificate['status'] !== 'pending_signature' || !$slot) {
                $this->db->roll_back();
                return false;
            }
            $this->execute("UPDATE certificate_signatures SET decision = 'rejected', remarks = ?, ip_address = ? WHERE signature_id = ?", [
                $remarks,
                substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                (int) $slot['signature_id'],
            ]);
            $this->db->table('certificates')->where('certificate_id', (int) $certificateId)->update(['status' => 'draft']);
            $staff = $this->db->table('users')
                ->select('user_id')
                ->where('status', 'active')
                ->in('role', ['administrator', 'staff'])
                ->get_all();
            foreach ($staff as $account) {
                $this->db->table('notifications')->insert([
                    'user_id' => (int) $account['user_id'],
                    'message' => 'A signatory returned certificate #' . (int) $certificateId . ' for staff review.',
                    'notification_type' => 'certificate_signature_rejected',
                ]);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        return true;
    }

    public function resubmitCertificateForSigning($certificateId, $actorId)
    {
        $this->db->transaction();
        try {
            // Lock the draft while creating its next signature round.
            $certificate = $this->row('SELECT certificate_id, certificate_number, status FROM certificates WHERE certificate_id = ? FOR UPDATE', [(int) $certificateId]);
            if (!$certificate || $certificate['status'] !== 'draft') {
                $this->db->roll_back();
                return false;
            }
            $signatories = $this->db->table('certificate_signatories s')
                ->select('s.signatory_id, s.user_id, s.name, s.position, s.signature_path, s.x_position, s.y_position')
                ->join('users u', 'u.user_id = s.user_id')
                ->where(['s.is_active' => 1, 'u.status' => 'active'])
                ->in('u.role', ['administrator', 'staff'])
                ->order_by('s.signatory_id')
                ->get_all();
            if (!$signatories) {
                $this->db->roll_back();
                return false;
            }
            foreach ($signatories as $signatory) {
                $this->db->table('certificate_signatures')->insert([
                    'certificate_id' => (int) $certificateId,
                    'signatory_id' => (int) $signatory['signatory_id'],
                    'signatory_user_id' => (int) $signatory['user_id'],
                    'signatory_name' => $signatory['name'],
                    'signatory_position' => $signatory['position'],
                    'signature_path' => $signatory['signature_path'],
                    'x_position' => $signatory['x_position'],
                    'y_position' => $signatory['y_position'],
                    'decision' => 'pending',
                ]);
                $this->db->table('notifications')->insert([
                    'user_id' => (int) $signatory['user_id'],
                    'message' => 'Certificate ' . $certificate['certificate_number'] . ' was resubmitted for signature.',
                    'notification_type' => 'certificate_signature',
                ]);
            }
            $this->db->table('certificates')->where('certificate_id', (int) $certificateId)->update(['status' => 'pending_signature']);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        return true;
    }

    public function certificateSignatures($certificate)
    {
        $records = $this->rows("SELECT s.* FROM certificate_signatures s WHERE s.certificate_id = ? AND s.decision = 'signed' AND NOT EXISTS (SELECT 1 FROM certificate_signatures newer WHERE newer.certificate_id = s.certificate_id AND newer.signatory_id = s.signatory_id AND newer.signature_id > s.signature_id) ORDER BY s.signature_id", [(int) $certificate['certificate_id']]);
        $expectedHash = $this->certificateDataHash($certificate);
        foreach ($records as &$record) {
            $record['hash_valid'] = hash_equals($expectedHash, (string) $record['certificate_data_hash']);
        }
        unset($record);
        return $records;
    }

    public function certificateDataHash(array $certificate)
    {
        $canonical = [
            'trainee' => (string) ($certificate['trainee_name'] ?? ''),
            'program' => (string) ($certificate['program_name'] ?? ''),
            'certificate_number' => (string) ($certificate['certificate_number'] ?? ''),
            'completion_date' => (string) ($certificate['completion_date'] ?? ''),
        ];
        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function revokeCertificate($certificateId, $revokedBy, $reason)
    {
        return $this->execute("UPDATE certificates SET status = 'revoked', revoked_at = CURDATE(), revoked_by = ?, revocation_reason = ? WHERE certificate_id = ? AND status = 'issued'", [
            (int) $revokedBy,
            $reason,
            (int) $certificateId
        ]);
    }

    public function logCertificateAction($actorId, $certificateId, $action, $details = null)
    {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $this->db->table('certificate_audit_logs')->insert([
            'actor_id' => (int) $actorId,
            'certificate_id' => (int) $certificateId,
            'action' => $action,
            'details' => $details,
            'ip_address' => $ipAddress,
        ]);
    }

    public function certificateAuditRecords($certificateId)
    {
        $records = $this->db->table('certificate_audit_logs a')
            ->select('a.audit_id, a.actor_id, a.certificate_id, a.eligibility_id, a.action, a.details, a.ip_address, a.created_at, u.full_name AS actor_name')
            ->left_join('users u', 'u.user_id = a.actor_id')
            ->where('a.certificate_id', (int) $certificateId)
            ->order_by('a.created_at', 'DESC')
            ->get_all();
        usort($records, static function ($left, $right) {
            $dateOrder = strcmp((string) $right['created_at'], (string) $left['created_at']);
            return $dateOrder ?: ((int) $right['audit_id'] <=> (int) $left['audit_id']);
        });
        return $records;
    }

    public function updateCertificateFilePath($certificateId, $relativePath)
    {
        $this->db->table('certificates')->where('certificate_id', (int) $certificateId)->update(['file_path' => $relativePath]);
    }

    private function decodeTemplate($template)
    {
        if (!$template) {
            return null;
        }
        $template['field_layout'] = json_decode($template['field_layout'], true) ?: [];
        $template['settings'] = json_decode($template['settings'], true) ?: [];
        return $template;
    }
}
