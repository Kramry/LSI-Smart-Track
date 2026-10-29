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

        return [
            'eligible' => (int) ($eligible['count'] ?? 0),
            'pending' => (int) ($pending['count'] ?? 0),
            'issued' => (int) ($issued['count'] ?? 0),
            'revoked' => (int) ($revoked['count'] ?? 0),
        ];
    }

    public function getCertificates(array $filters = [])
    {
        $sql = "SELECT e.*, c.certificate_id, c.certificate_number, c.issue_date, c.revocation_reason, c.revoked_at, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, t.qr_code AS trainee_number, p.program_name, cl.class_name, cl.end_date AS completion_date, CASE WHEN c.certificate_id IS NOT NULL THEN c.status WHEN e.is_eligible = 0 THEN 'ineligible' ELSE e.approval_status END AS status FROM certificate_eligibility e JOIN trainees t ON t.trainee_id = e.trainee_id JOIN training_programs p ON p.program_id = e.program_id JOIN training_classes cl ON cl.class_id = e.class_id LEFT JOIN certificates c ON c.trainee_id = e.trainee_id AND c.class_id = e.class_id";
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
        if ($status === 'issued' || $status === 'revoked') {
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

    public function issueCertificate($traineeId, $classId, $issuedBy)
    {
        $currentEligibility = $this->eligibilityModel->calculateEligibility((int) $traineeId, (int) $classId);
        $eligibility = $this->row('SELECT * FROM certificate_eligibility WHERE trainee_id = ? AND class_id = ?', [(int) $traineeId, (int) $classId]);
        if (!$currentEligibility['eligible'] || !$eligibility || $eligibility['approval_status'] !== 'approved') {
            return false;
        }

        $class = $this->row('SELECT c.*, p.program_id FROM training_classes c JOIN training_programs p ON p.program_id = c.program_id WHERE c.class_id = ?', [(int) $classId]);
        if (!$class) {
            return false;
        }

        $certificateNumber = $this->generateCertificateNumber();
        $qrCode = bin2hex(random_bytes(32));
        $template = $this->getActiveTemplate() ?: $this->legacyTemplate();
        $templateSnapshot = json_encode($template, JSON_THROW_ON_ERROR);

        $this->execute('INSERT INTO certificates (trainee_id, class_id, program_id, certificate_number, completion_date, issue_date, status, qr_code, template_id, template_snapshot) VALUES (?, ?, ?, ?, ?, CURDATE(), "issued", ?, ?, ?)', [
            (int) $traineeId,
            (int) $classId,
            (int) $class['program_id'],
            $certificateNumber,
            $class['end_date'],
            $qrCode,
            $template['template_id'] ?: null,
            $templateSnapshot,
        ]);

        return $this->row('SELECT * FROM certificates WHERE certificate_number = ?', [$certificateNumber]);
    }

    public function revokeCertificate($certificateId, $revokedBy, $reason)
    {
        $this->execute('UPDATE certificates SET status = "revoked", revoked_at = CURDATE(), revoked_by = ?, revocation_reason = ? WHERE certificate_id = ?', [
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
