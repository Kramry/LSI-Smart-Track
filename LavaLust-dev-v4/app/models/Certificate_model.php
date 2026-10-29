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
        $eligible = $this->row('SELECT COUNT(*) as count FROM certificate_eligibility WHERE is_eligible = 1');
        $pending = $this->row("SELECT COUNT(*) AS count FROM certificate_eligibility WHERE is_eligible = 1 AND approval_status = 'pending'");
        $issued = $this->row('SELECT COUNT(*) as count FROM certificates WHERE status = "issued"');
        $revoked = $this->row('SELECT COUNT(*) as count FROM certificates WHERE status = "revoked"');
        $signing = $this->row('SELECT COUNT(*) AS count FROM certificates WHERE status = "pending_signature"');

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
            if (!$this->row('SELECT certificate_id FROM certificates WHERE certificate_number = ?', [$certificateNumber])) {
                return $certificateNumber;
            }
        }
        throw new RuntimeException('A unique certificate number could not be generated.');
    }

    public function getActiveTemplate()
    {
        $template = $this->row('SELECT * FROM certificate_templates WHERE is_active = 1 ORDER BY version DESC LIMIT 1');
        return $this->decodeTemplate($template);
    }

    public function getLatestTemplate()
    {
        $template = $this->row('SELECT * FROM certificate_templates ORDER BY version DESC LIMIT 1');
        return $this->decodeTemplate($template);
    }

    public function getTemplate($templateId)
    {
        $template = $this->row('SELECT * FROM certificate_templates WHERE template_id = ?', [(int) $templateId]);
        return $this->decodeTemplate($template);
    }

    public function getTemplateVersions()
    {
        $templates = $this->rows('SELECT template_id, mode, file_path, settings, is_active, version, created_by, created_at, EXISTS (SELECT 1 FROM certificates WHERE certificates.template_id = certificate_templates.template_id) AS has_issued_certificates FROM certificate_templates ORDER BY version DESC');
        foreach ($templates as &$template) {
            $template['settings'] = json_decode($template['settings'], true) ?: [];
        }
        unset($template);
        return $templates;
    }

    public function saveTemplate(array $template, $createdBy, $activate = false)
    {
        $this->db->transaction();
        try {
            $latest = $this->row('SELECT version FROM certificate_templates ORDER BY version DESC LIMIT 1 FOR UPDATE');
            $version = (int) ($latest['version'] ?? 0) + 1;
            if ($activate) {
                $this->execute('UPDATE certificate_templates SET is_active = 0 WHERE is_active = 1');
            }
            $this->execute('INSERT INTO certificate_templates (mode, file_path, field_layout, settings, is_active, version, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                $template['mode'],
                $template['file_path'] ?: null,
                json_encode($template['field_layout'], JSON_THROW_ON_ERROR),
                json_encode($template['settings'], JSON_THROW_ON_ERROR),
                $activate ? 1 : 0,
                $version,
                (int) $createdBy,
            ]);
            $saved = $this->row('SELECT * FROM certificate_templates WHERE version = ?', [$version]);
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
            $this->execute('UPDATE certificate_templates SET is_active = 0 WHERE is_active = 1');
            $this->execute('UPDATE certificate_templates SET is_active = 1 WHERE template_id = ?', [(int) $templateId]);
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
            $template = $this->row('SELECT template_id, version, is_active FROM certificate_templates WHERE template_id = ? FOR UPDATE', [(int) $templateId]);
            if (!$template) {
                $this->db->commit();
                return ['deleted' => false, 'reason' => 'not_found'];
            }
            if ((int) $template['is_active'] === 1) {
                $this->db->commit();
                return ['deleted' => false, 'reason' => 'active'];
            }
            $issued = $this->row('SELECT certificate_id FROM certificates WHERE template_id = ? LIMIT 1', [(int) $templateId]);
            if ($issued) {
                $this->db->commit();
                return ['deleted' => false, 'reason' => 'issued'];
            }

            $this->execute('DELETE FROM certificate_templates WHERE template_id = ?', [(int) $templateId]);
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
        return (int) ($this->row("SELECT COUNT(*) AS total FROM certificate_signatories s JOIN users u ON u.user_id = s.user_id WHERE s.is_active = 1 AND u.status = 'active' AND u.role IN ('administrator', 'staff')")['total'] ?? 0) > 0;
    }

    public function signatoryAccounts()
    {
        return $this->rows("SELECT user_id AS id, full_name AS label FROM users WHERE status = 'active' AND role IN ('administrator', 'staff') ORDER BY full_name");
    }

    public function signatories()
    {
        return $this->rows('SELECT s.*, u.full_name AS account_name, u.username FROM certificate_signatories s JOIN users u ON u.user_id = s.user_id ORDER BY s.name');
    }

    public function saveSignatory(array $signatory, $createdBy, $signatoryId = null)
    {
        if ($signatoryId) {
            $this->execute('UPDATE certificate_signatories SET user_id = ?, name = ?, position = ?, signature_path = ?, x_position = ?, y_position = ?, is_active = 1 WHERE signatory_id = ?', [
                (int) $signatory['user_id'],
                $signatory['name'],
                $signatory['position'],
                $signatory['signature_path'],
                $signatory['x_position'],
                $signatory['y_position'],
                (int) $signatoryId,
            ]);
            return (int) $signatoryId;
        }
        $this->execute('INSERT INTO certificate_signatories (user_id, name, position, signature_path, x_position, y_position, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            (int) $signatory['user_id'],
            $signatory['name'],
            $signatory['position'],
            $signatory['signature_path'],
            $signatory['x_position'],
            $signatory['y_position'],
            (int) $createdBy,
        ]);
        return (int) $this->row('SELECT LAST_INSERT_ID() AS id')['id'];
    }

    public function deleteSignatory($signatoryId)
    {
        return $this->execute('UPDATE certificate_signatories SET is_active = 0 WHERE signatory_id = ?', [(int) $signatoryId]);
    }

    public function createPendingCertificate($traineeId, $classId, $createdBy)
    {
        $currentEligibility = $this->eligibilityModel->calculateEligibility((int) $traineeId, (int) $classId);
        $eligibility = $this->row('SELECT * FROM certificate_eligibility WHERE trainee_id = ? AND class_id = ?', [(int) $traineeId, (int) $classId]);
        if (!$currentEligibility['eligible'] || !$eligibility || $eligibility['approval_status'] !== 'approved' || !$this->hasActiveSignatories()) {
            return false;
        }

        if ($this->row('SELECT certificate_id FROM certificates WHERE trainee_id = ? AND class_id = ?', [(int) $traineeId, (int) $classId])) {
            return false;
        }
        $class = $this->row('SELECT c.*, p.program_id FROM training_classes c JOIN training_programs p ON p.program_id = c.program_id WHERE c.class_id = ?', [(int) $classId]);
        if (!$class || empty($class['end_date'])) {
            return false;
        }

        $certificateNumber = $this->generateCertificateNumber();
        $qrCode = bin2hex(random_bytes(32));
        $template = $this->getActiveTemplate() ?: $this->legacyTemplate();
        $templateSnapshot = json_encode($template, JSON_THROW_ON_ERROR);
        $signatories = $this->rows("SELECT s.* FROM certificate_signatories s JOIN users u ON u.user_id = s.user_id WHERE s.is_active = 1 AND u.status = 'active' AND u.role IN ('administrator', 'staff') ORDER BY s.signatory_id");
        if (!$signatories) {
            return false;
        }

        $this->db->transaction();
        try {
            $this->execute('INSERT INTO certificates (trainee_id, class_id, program_id, certificate_number, completion_date, issue_date, status, qr_code, template_id, template_snapshot) VALUES (?, ?, ?, ?, ?, CURDATE(), "draft", ?, ?, ?)', [
                (int) $traineeId,
                (int) $classId,
                (int) $class['program_id'],
                $certificateNumber,
                $class['end_date'],
                $qrCode,
                $template['template_id'] ?: null,
                $templateSnapshot,
            ]);
            $certificateId = (int) $this->row('SELECT LAST_INSERT_ID() AS id')['id'];
            foreach ($signatories as $signatory) {
                $this->execute('INSERT INTO certificate_signatures (certificate_id, signatory_id, signatory_user_id, signatory_name, signatory_position, signature_path, x_position, y_position, decision) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending")', [
                    $certificateId,
                    (int) $signatory['signatory_id'],
                    (int) $signatory['user_id'],
                    $signatory['name'],
                    $signatory['position'],
                    $signatory['signature_path'],
                    $signatory['x_position'],
                    $signatory['y_position'],
                ]);
                $this->execute('INSERT INTO notifications (user_id, message, notification_type) VALUES (?, ?, ?)', [
                    (int) $signatory['user_id'],
                    'Certificate ' . $certificateNumber . ' is ready for your signature.',
                    'certificate_signature',
                ]);
            }
            $this->execute('UPDATE certificates SET status = "pending_signature" WHERE certificate_id = ?', [$certificateId]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        return $this->row('SELECT * FROM certificates WHERE certificate_id = ?', [$certificateId]);
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
            $certificate = $this->row('SELECT c.*, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, p.program_name FROM certificates c JOIN trainees t ON t.trainee_id = c.trainee_id JOIN training_programs p ON p.program_id = c.program_id WHERE c.certificate_id = ? FOR UPDATE', [(int) $certificateId]);
            $slot = $this->row("SELECT s.* FROM certificate_signatures s WHERE s.certificate_id = ? AND s.signatory_user_id = ? AND s.decision = 'pending' AND NOT EXISTS (SELECT 1 FROM certificate_signatures newer WHERE newer.certificate_id = s.certificate_id AND newer.signatory_id = s.signatory_id AND newer.signature_id > s.signature_id) ORDER BY s.signature_id DESC LIMIT 1 FOR UPDATE", [(int) $certificateId, (int) $userId]);
            if (!$certificate || $certificate['status'] !== 'pending_signature' || !$slot) {
                $this->db->roll_back();
                return ['success' => false, 'message' => 'This certificate is not assigned to you for signing.'];
            }
            $dataHash = $this->certificateDataHash($certificate);
            $this->execute('UPDATE certificate_signatures SET decision = "signed", signed_at = CURRENT_TIMESTAMP, ip_address = ?, certificate_data_hash = ? WHERE signature_id = ?', [
                substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                $dataHash,
                (int) $slot['signature_id'],
            ]);
            $remaining = (int) ($this->row("SELECT COUNT(*) AS total FROM certificate_signatures s WHERE s.certificate_id = ? AND s.decision <> 'signed' AND NOT EXISTS (SELECT 1 FROM certificate_signatures newer WHERE newer.certificate_id = s.certificate_id AND newer.signatory_id = s.signatory_id AND newer.signature_id > s.signature_id)", [(int) $certificateId])['total'] ?? 0);
            $issued = $remaining === 0;
            if ($issued) {
                $this->execute('UPDATE certificates SET status = "signed" WHERE certificate_id = ?', [(int) $certificateId]);
                $this->execute('UPDATE certificates SET status = "issued", issue_date = CURDATE() WHERE certificate_id = ?', [(int) $certificateId]);
                $trainee = $this->row('SELECT t.user_id FROM trainees t JOIN certificates c ON c.trainee_id = t.trainee_id WHERE c.certificate_id = ?', [(int) $certificateId]);
                if (!empty($trainee['user_id'])) {
                    $this->execute('INSERT INTO notifications (user_id, message, notification_type) VALUES (?, ?, ?)', [
                        (int) $trainee['user_id'],
                        'Your certificate ' . $certificate['certificate_number'] . ' has been signed and issued.',
                        'certificate_issued',
                    ]);
                }
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        return ['success' => true, 'issued' => $issued, 'certificate' => $this->row('SELECT * FROM certificates WHERE certificate_id = ?', [(int) $certificateId])];
    }

    public function rejectCertificateSignature($certificateId, $userId, $remarks)
    {
        $this->db->transaction();
        try {
            $certificate = $this->row('SELECT certificate_id, status FROM certificates WHERE certificate_id = ? FOR UPDATE', [(int) $certificateId]);
            $slot = $this->row("SELECT s.signature_id FROM certificate_signatures s WHERE s.certificate_id = ? AND s.signatory_user_id = ? AND s.decision = 'pending' AND NOT EXISTS (SELECT 1 FROM certificate_signatures newer WHERE newer.certificate_id = s.certificate_id AND newer.signatory_id = s.signatory_id AND newer.signature_id > s.signature_id) ORDER BY s.signature_id DESC LIMIT 1 FOR UPDATE", [(int) $certificateId, (int) $userId]);
            if (!$certificate || $certificate['status'] !== 'pending_signature' || !$slot) {
                $this->db->roll_back();
                return false;
            }
            $this->execute('UPDATE certificate_signatures SET decision = "rejected", remarks = ?, ip_address = ? WHERE signature_id = ?', [
                $remarks,
                substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                (int) $slot['signature_id'],
            ]);
            $this->execute('UPDATE certificates SET status = "draft" WHERE certificate_id = ?', [(int) $certificateId]);
            $staff = $this->rows("SELECT user_id FROM users WHERE status = 'active' AND role IN ('administrator', 'staff')");
            foreach ($staff as $account) {
                $this->execute('INSERT INTO notifications (user_id, message, notification_type) VALUES (?, ?, ?)', [
                    (int) $account['user_id'],
                    'A signatory returned certificate #' . (int) $certificateId . ' for staff review.',
                    'certificate_signature_rejected',
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
            $certificate = $this->row('SELECT certificate_id, certificate_number, status FROM certificates WHERE certificate_id = ? FOR UPDATE', [(int) $certificateId]);
            if (!$certificate || $certificate['status'] !== 'draft') {
                $this->db->roll_back();
                return false;
            }
            $signatories = $this->rows("SELECT s.* FROM certificate_signatories s JOIN users u ON u.user_id = s.user_id WHERE s.is_active = 1 AND u.status = 'active' AND u.role IN ('administrator', 'staff') ORDER BY s.signatory_id");
            if (!$signatories) {
                $this->db->roll_back();
                return false;
            }
            foreach ($signatories as $signatory) {
                $this->execute('INSERT INTO certificate_signatures (certificate_id, signatory_id, signatory_user_id, signatory_name, signatory_position, signature_path, x_position, y_position, decision) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending")', [
                    (int) $certificateId,
                    (int) $signatory['signatory_id'],
                    (int) $signatory['user_id'],
                    $signatory['name'],
                    $signatory['position'],
                    $signatory['signature_path'],
                    $signatory['x_position'],
                    $signatory['y_position'],
                ]);
                $this->execute('INSERT INTO notifications (user_id, message, notification_type) VALUES (?, ?, ?)', [
                    (int) $signatory['user_id'],
                    'Certificate ' . $certificate['certificate_number'] . ' was resubmitted for signature.',
                    'certificate_signature',
                ]);
            }
            $this->execute('UPDATE certificates SET status = "pending_signature" WHERE certificate_id = ?', [(int) $certificateId]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        return true;
    }

    public function certificateSignatures($certificate)
    {
        $records = $this->rows('SELECT s.* FROM certificate_signatures s WHERE s.certificate_id = ? AND s.decision = "signed" AND NOT EXISTS (SELECT 1 FROM certificate_signatures newer WHERE newer.certificate_id = s.certificate_id AND newer.signatory_id = s.signatory_id AND newer.signature_id > s.signature_id) ORDER BY s.signature_id', [(int) $certificate['certificate_id']]);
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
        return $this->execute('UPDATE certificates SET status = "revoked", revoked_at = CURDATE(), revoked_by = ?, revocation_reason = ? WHERE certificate_id = ? AND status = "issued"', [
            (int) $revokedBy,
            $reason,
            (int) $certificateId
        ]);
    }

    public function logCertificateAction($actorId, $certificateId, $action, $details = null)
    {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $this->execute('INSERT INTO certificate_audit_logs (actor_id, certificate_id, action, details, ip_address) VALUES (?, ?, ?, ?, ?)', [
            (int) $actorId,
            (int) $certificateId,
            $action,
            $details,
            $ipAddress
        ]);
    }

    public function certificateAuditRecords($certificateId)
    {
        return $this->rows('SELECT a.*, u.full_name AS actor_name FROM certificate_audit_logs a LEFT JOIN users u ON u.user_id = a.actor_id WHERE a.certificate_id = ? ORDER BY a.created_at DESC, a.audit_id DESC', [(int) $certificateId]);
    }

    public function updateCertificateFilePath($certificateId, $relativePath)
    {
        $this->execute('UPDATE certificates SET file_path = ? WHERE certificate_id = ?', [$relativePath, (int) $certificateId]);
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
