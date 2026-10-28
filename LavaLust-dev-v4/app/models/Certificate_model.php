<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';

class Certificate_model extends Base_model
{
    private $eligibilityModel;

    private const DEFAULT_LAYOUT = [
        'trainee_name' => ['x' => 50, 'y' => 52, 'font_size' => 28, 'align' => 'C'],
        'program_name' => ['x' => 50, 'y' => 65, 'font_size' => 20, 'align' => 'C'],
        'completion_date' => ['x' => 50, 'y' => 76, 'font_size' => 12, 'align' => 'C'],
        'certificate_number' => ['x' => 50, 'y' => 91, 'font_size' => 10, 'align' => 'C'],
        'signatory_name' => ['x' => 24, 'y' => 86, 'font_size' => 12, 'align' => 'C'],
        'signatory_designation' => ['x' => 24, 'y' => 90, 'font_size' => 10, 'align' => 'C'],
        'qr_code' => ['x' => 86, 'y' => 80, 'font_size' => 10, 'align' => 'C'],
    ];

    public function __construct($db, $eligibilityModel = null)
    {
        parent::__construct($db);
        $this->eligibilityModel = $eligibilityModel;
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
        $sql = "SELECT e.*, c.certificate_id, c.certificate_number, c.issue_date, c.revocation_reason, c.revoked_at, (SELECT cs.remarks FROM certificate_signatures cs WHERE cs.certificate_id = c.certificate_id AND cs.status = 'rejected' ORDER BY cs.signature_id DESC LIMIT 1) AS signing_remarks, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, t.qr_code AS trainee_number, p.program_name, cl.class_name, cl.end_date AS completion_date, CASE WHEN c.certificate_id IS NOT NULL THEN c.status WHEN e.is_eligible = 0 THEN 'ineligible' ELSE e.approval_status END AS status FROM certificate_eligibility e JOIN trainees t ON t.trainee_id = e.trainee_id JOIN training_programs p ON p.program_id = e.program_id JOIN training_classes cl ON cl.class_id = e.class_id LEFT JOIN certificates c ON c.trainee_id = e.trainee_id AND c.class_id = e.class_id";
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
        if (in_array($status, ['issued', 'revoked', 'draft', 'pending_signature', 'signed'], true)) {
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
        $random = strtoupper(bin2hex(random_bytes(4)));
        return sprintf('%s-%s-%s', $prefix, $year, $random);
    }

    public function defaultCertificateTemplate(array $legacyConfig = [])
    {
        return [
            'template_id' => null,
            'version' => 0,
            'mode' => 'generate',
            'file_path' => null,
            'file_mime' => null,
            'field_layout' => self::DEFAULT_LAYOUT,
            'settings' => [
                'institution_name' => $legacyConfig['institution_name'] ?? 'LSI Training Center',
                'logo_path' => $legacyConfig['logo_path'] ?? '',
                'title_text' => $legacyConfig['certificate_title'] ?? 'Certificate of Completion',
                'subtitle_text' => $legacyConfig['certificate_subtitle'] ?? 'OF COMPLETION',
                'body_text' => 'This is to certify that {trainee_name} has successfully completed {program_name} on {date_completed}.',
                'signatory_name' => $legacyConfig['signatory_name'] ?? 'Training Director',
                'signatory_designation' => $legacyConfig['signatory_designation'] ?? 'Director',
                'border_style' => 'double',
                'accent_color' => $legacyConfig['template_color'] ?? '#dd4814',
                'font_family' => 'helvetica',
            ],
            'is_active' => 0,
        ];
    }

    public function activeCertificateTemplate()
    {
        $template = $this->row('SELECT * FROM certificate_templates WHERE is_active = 1 ORDER BY version DESC LIMIT 1');
        return $template ? $this->decodeTemplate($template) : null;
    }

    public function latestCertificateTemplate()
    {
        $template = $this->row('SELECT * FROM certificate_templates ORDER BY version DESC LIMIT 1');
        return $template ? $this->decodeTemplate($template) : null;
    }

    public function certificateTemplate($templateId)
    {
        $template = $this->row('SELECT * FROM certificate_templates WHERE template_id = ?', [(int) $templateId]);
        return $template ? $this->decodeTemplate($template) : null;
    }

    public function certificateTemplateHistory()
    {
        $templates = $this->rows('SELECT template_id, version, mode, file_path, is_active, created_by, created_at FROM certificate_templates ORDER BY version DESC');
        foreach ($templates as &$template) {
            $template['is_active'] = (int) $template['is_active'];
        }
        unset($template);
        return $templates;
    }

    public function saveCertificateTemplate(array $template, $createdBy, $activate = false)
    {
        $this->db->transaction();
        try {
            $versionRow = $this->row('SELECT COALESCE(MAX(version), 0) AS max_version FROM certificate_templates');
            $version = (int) ($versionRow['max_version'] ?? 0) + 1;
            if ($activate) {
                $this->execute('UPDATE certificate_templates SET is_active = 0 WHERE is_active = 1');
            }

            $this->execute('INSERT INTO certificate_templates (version, mode, file_path, file_mime, field_layout, settings, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
                $version,
                $template['mode'],
                $template['file_path'] ?: null,
                $template['file_mime'] ?: null,
                json_encode($template['field_layout'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                json_encode($template['settings'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $activate ? 1 : 0,
                (int) $createdBy,
            ]);
            $saved = $this->row('SELECT * FROM certificate_templates WHERE version = ?', [$version]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        return $saved ? $this->decodeTemplate($saved) : null;
    }

    public function activateCertificateTemplate($templateId)
    {
        $template = $this->row('SELECT template_id FROM certificate_templates WHERE template_id = ?', [(int) $templateId]);
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
        return true;
    }

    private function decodeTemplate(array $template)
    {
        $template['field_layout'] = json_decode($template['field_layout'] ?? '{}', true) ?: self::DEFAULT_LAYOUT;
        $template['settings'] = json_decode($template['settings'] ?? '{}', true) ?: [];
        $template['is_active'] = (int) $template['is_active'];
        return $template;
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
        $activeTemplate = $this->activeCertificateTemplate();
        $signatories = $this->requiredActiveCertificateSignatories();
        if (!$signatories) {
            return false;
        }

        $this->db->transaction();
        try {
            $this->execute('INSERT INTO certificates (trainee_id, class_id, program_id, certificate_number, completion_date, issue_date, status, qr_code, template_id) VALUES (?, ?, ?, ?, CURDATE(), CURDATE(), "pending_signature", ?, ?)', [
                (int) $traineeId,
                (int) $classId,
                (int) $class['program_id'],
                $certificateNumber,
                $qrCode,
                $activeTemplate ? (int) $activeTemplate['template_id'] : null,
            ]);

            $certificate = $this->row('SELECT * FROM certificates WHERE certificate_number = ?', [$certificateNumber]);
            foreach ($signatories as $signatory) {
                $this->execute('INSERT INTO certificate_signatures (certificate_id, signatory_id, user_id, status, signature_path, signatory_name, position, field_x, field_y, font_size, alignment, is_required) VALUES (?, ?, ?, "pending", ?, ?, ?, ?, ?, ?, ?, ?)', [
                    (int) $certificate['certificate_id'],
                    (int) $signatory['signatory_id'],
                    (int) $signatory['user_id'],
                    $signatory['signature_path'],
                    $signatory['name'],
                    $signatory['position'],
                    (float) $signatory['field_x'],
                    (float) $signatory['field_y'],
                    (int) $signatory['font_size'],
                    $signatory['alignment'],
                    (int) $signatory['is_required'],
                ]);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        return $this->row('SELECT * FROM certificates WHERE certificate_number = ?', [$certificateNumber]);
    }

    public function certificateSignatories()
    {
        return $this->rows('SELECT s.*, u.full_name AS account_name, u.username FROM certificate_signatories s JOIN users u ON u.user_id = s.user_id ORDER BY s.is_active DESC, s.name');
    }

    public function requiredActiveCertificateSignatories()
    {
        return $this->rows('SELECT s.* FROM certificate_signatories s JOIN users u ON u.user_id = s.user_id WHERE s.is_active = 1 AND s.is_required = 1 AND u.status = "active" AND u.role IN ("administrator", "staff") ORDER BY s.signatory_id');
    }

    public function signatoryUsers()
    {
        return $this->rows('SELECT u.user_id, u.full_name, u.username, s.signatory_id FROM users u LEFT JOIN certificate_signatories s ON s.user_id = u.user_id WHERE u.status = "active" AND u.role IN ("administrator", "staff") ORDER BY u.full_name');
    }

    public function saveCertificateSignatory(array $signatory, $actorId)
    {
        if (!empty($signatory['signatory_id'])) {
            $this->execute('UPDATE certificate_signatories SET user_id = ?, name = ?, position = ?, signature_path = ?, field_x = ?, field_y = ?, font_size = ?, alignment = ?, is_required = ?, is_active = 1, updated_at = NOW() WHERE signatory_id = ?', [
                (int) $signatory['user_id'],
                $signatory['name'],
                $signatory['position'],
                $signatory['signature_path'],
                (float) $signatory['field_x'],
                (float) $signatory['field_y'],
                (int) $signatory['font_size'],
                $signatory['alignment'],
                (int) $signatory['is_required'],
                (int) $signatory['signatory_id'],
            ]);
            return $this->row('SELECT * FROM certificate_signatories WHERE signatory_id = ?', [(int) $signatory['signatory_id']]);
        }

        $this->execute('INSERT INTO certificate_signatories (user_id, name, position, signature_path, field_x, field_y, font_size, alignment, is_required, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)', [
            (int) $signatory['user_id'],
            $signatory['name'],
            $signatory['position'],
            $signatory['signature_path'],
            (float) $signatory['field_x'],
            (float) $signatory['field_y'],
            (int) $signatory['font_size'],
            $signatory['alignment'],
            (int) $signatory['is_required'],
            (int) $actorId,
        ]);
        return $this->row('SELECT * FROM certificate_signatories WHERE signatory_id = LAST_INSERT_ID()');
    }

    public function deactivateCertificateSignatory($signatoryId)
    {
        return $this->execute('UPDATE certificate_signatories SET is_active = 0, updated_at = NOW() WHERE signatory_id = ?', [(int) $signatoryId]) > 0;
    }

    public function certificateSigningQueue($userId)
    {
        return $this->rows('SELECT cs.signature_id, cs.certificate_id, cs.signatory_name, cs.position, cs.is_required, c.certificate_number, c.status AS certificate_status, c.completion_date, c.issue_date, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, p.program_name, cl.class_name FROM certificate_signatures cs JOIN certificates c ON c.certificate_id = cs.certificate_id JOIN trainees t ON t.trainee_id = c.trainee_id JOIN training_programs p ON p.program_id = c.program_id JOIN training_classes cl ON cl.class_id = c.class_id WHERE cs.user_id = ? AND cs.status = "pending" AND c.status = "pending_signature" ORDER BY c.created_at ASC, cs.signature_id ASC', [(int) $userId]);
    }

    public function certificateForSigning($certificateId, $userId)
    {
        return $this->row('SELECT c.*, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, p.program_name, cl.class_name, cs.signature_id, cs.status AS signature_status FROM certificates c JOIN trainees t ON t.trainee_id = c.trainee_id JOIN training_programs p ON p.program_id = c.program_id JOIN training_classes cl ON cl.class_id = c.class_id JOIN certificate_signatures cs ON cs.certificate_id = c.certificate_id WHERE c.certificate_id = ? AND cs.user_id = ?', [(int) $certificateId, (int) $userId]);
    }

    private function certificateDataHash($certificateId)
    {
        $data = $this->row('SELECT c.trainee_id, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, c.program_id, p.program_name, c.certificate_number, c.completion_date, c.issue_date FROM certificates c JOIN trainees t ON t.trainee_id = c.trainee_id JOIN training_programs p ON p.program_id = c.program_id WHERE c.certificate_id = ?', [(int) $certificateId]);
        if (!$data) {
            return null;
        }
        return hash('sha256', json_encode([
            'trainee_id' => (int) $data['trainee_id'],
            'trainee_name' => $data['trainee_name'],
            'program_id' => (int) $data['program_id'],
            'program_name' => $data['program_name'],
            'certificate_number' => $data['certificate_number'],
            'completion_date' => $data['completion_date'],
            'issue_date' => $data['issue_date'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function signCertificate($certificateId, $userId, $ipAddress)
    {
        $this->db->transaction();
        try {
            $slot = $this->row('SELECT cs.signature_id, cs.status, cs.is_required, c.status AS certificate_status FROM certificate_signatures cs JOIN certificates c ON c.certificate_id = cs.certificate_id WHERE cs.certificate_id = ? AND cs.user_id = ? FOR UPDATE', [
                (int) $certificateId,
                (int) $userId,
            ]);
            if (!$slot || $slot['status'] !== 'pending' || $slot['certificate_status'] !== 'pending_signature') {
                $this->db->roll_back();
                return ['success' => false, 'message' => 'This certificate is not assigned to you for signing.'];
            }

            $hash = $this->certificateDataHash((int) $certificateId);
            $mismatched = $this->row('SELECT signature_id FROM certificate_signatures WHERE certificate_id = ? AND status = "signed" AND (certificate_hash IS NULL OR certificate_hash <> ?) LIMIT 1', [(int) $certificateId, $hash]);
            if ($mismatched) {
                $this->db->roll_back();
                return ['success' => false, 'message' => 'Certificate data changed after another signatory signed it. Return it to staff for review.'];
            }

            $this->execute('UPDATE certificate_signatures SET status = "signed", signed_at = NOW(), ip_address = ?, certificate_hash = ? WHERE signature_id = ? AND status = "pending"', [
                substr((string) $ipAddress, 0, 45),
                $hash,
                (int) $slot['signature_id'],
            ]);
            $pending = $this->row('SELECT COUNT(*) AS total FROM certificate_signatures WHERE certificate_id = ? AND is_required = 1 AND status <> "signed"', [(int) $certificateId]);
            $signed = $this->row('SELECT COUNT(*) AS total FROM certificate_signatures WHERE certificate_id = ? AND status = "signed"', [(int) $certificateId]);
            $complete = (int) ($pending['total'] ?? 0) === 0 && (int) ($signed['total'] ?? 0) > 0;
            if ($complete) {
                $this->execute('UPDATE certificates SET status = "signed" WHERE certificate_id = ? AND status = "pending_signature"', [(int) $certificateId]);
            }
            $this->db->commit();
            return ['success' => true, 'complete' => $complete, 'hash' => $hash];
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function rejectCertificateSignature($certificateId, $userId, $remarks)
    {
        $this->db->transaction();
        try {
            $slot = $this->row('SELECT cs.signature_id FROM certificate_signatures cs JOIN certificates c ON c.certificate_id = cs.certificate_id WHERE cs.certificate_id = ? AND cs.user_id = ? AND cs.status = "pending" AND c.status = "pending_signature" FOR UPDATE', [
                (int) $certificateId,
                (int) $userId,
            ]);
            if (!$slot) {
                $this->db->roll_back();
                return false;
            }
            $this->execute('UPDATE certificate_signatures SET status = "rejected", remarks = ? WHERE signature_id = ?', [
                $remarks,
                (int) $slot['signature_id'],
            ]);
            $this->execute('UPDATE certificates SET status = "draft" WHERE certificate_id = ? AND status = "pending_signature"', [(int) $certificateId]);
            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function resubmitCertificateForSignature($certificateId)
    {
        $this->db->transaction();
        try {
            $certificate = $this->row('SELECT certificate_id FROM certificates WHERE certificate_id = ? AND status = "draft"', [(int) $certificateId]);
            if (!$certificate) {
                $this->db->roll_back();
                return false;
            }
            $signatories = $this->rows('SELECT * FROM certificate_signatories WHERE is_active = 1 AND is_required = 1 ORDER BY signatory_id');
            if (!$signatories) {
                $this->db->roll_back();
                return false;
            }
            $activeIds = array_map(static function ($signatory) {
                return (int) $signatory['signatory_id'];
            }, $signatories);
            $placeholders = implode(',', array_fill(0, count($activeIds), '?'));
            $this->execute('DELETE FROM certificate_signatures WHERE certificate_id = ? AND signatory_id NOT IN (' . $placeholders . ')', array_merge([(int) $certificateId], $activeIds));
            foreach ($signatories as $signatory) {
                $existing = $this->row('SELECT signature_id FROM certificate_signatures WHERE certificate_id = ? AND signatory_id = ?', [(int) $certificateId, (int) $signatory['signatory_id']]);
                if ($existing) {
                    $this->execute('UPDATE certificate_signatures SET user_id = ?, status = "pending", signature_path = ?, signatory_name = ?, position = ?, field_x = ?, field_y = ?, font_size = ?, alignment = ?, is_required = 1, signed_at = NULL, ip_address = NULL, certificate_hash = NULL, remarks = NULL WHERE signature_id = ?', [
                        (int) $signatory['user_id'],
                        $signatory['signature_path'],
                        $signatory['name'],
                        $signatory['position'],
                        (float) $signatory['field_x'],
                        (float) $signatory['field_y'],
                        (int) $signatory['font_size'],
                        $signatory['alignment'],
                        (int) $existing['signature_id'],
                    ]);
                } else {
                    $this->execute('INSERT INTO certificate_signatures (certificate_id, signatory_id, user_id, status, signature_path, signatory_name, position, field_x, field_y, font_size, alignment, is_required) VALUES (?, ?, ?, "pending", ?, ?, ?, ?, ?, ?, ?, 1)', [
                        (int) $certificateId,
                        (int) $signatory['signatory_id'],
                        (int) $signatory['user_id'],
                        $signatory['signature_path'],
                        $signatory['name'],
                        $signatory['position'],
                        (float) $signatory['field_x'],
                        (float) $signatory['field_y'],
                        (int) $signatory['font_size'],
                        $signatory['alignment'],
                    ]);
                }
            }
            $this->execute('UPDATE certificates SET status = "pending_signature" WHERE certificate_id = ?', [(int) $certificateId]);
            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function signedCertificateSignatures($certificateId)
    {
        return $this->rows('SELECT signature_id, signatory_id, user_id, status, signature_path, signatory_name, position, field_x, field_y, font_size, alignment, signed_at, ip_address, certificate_hash FROM certificate_signatures WHERE certificate_id = ? AND status = "signed" ORDER BY signature_id', [(int) $certificateId]);
    }

    public function certificateSignatureVerification($certificateId)
    {
        $signatures = $this->rows('SELECT signatory_name, position, signed_at, certificate_hash FROM certificate_signatures WHERE certificate_id = ? AND status = "signed" ORDER BY signature_id', [(int) $certificateId]);
        if (!$signatures) {
            return ['signatures' => [], 'hash_valid' => null];
        }
        $currentHash = $this->certificateDataHash((int) $certificateId);
        $valid = true;
        foreach ($signatures as $signature) {
            if (!hash_equals((string) $signature['certificate_hash'], (string) $currentHash)) {
                $valid = false;
            }
        }
        return ['signatures' => $signatures, 'hash_valid' => $valid];
    }

    public function issueSignedCertificate($certificateId, $filePath)
    {
        $this->db->transaction();
        try {
            $certificate = $this->row('SELECT c.certificate_id, t.user_id, c.certificate_number FROM certificates c JOIN trainees t ON t.trainee_id = c.trainee_id WHERE c.certificate_id = ? AND c.status = "signed" FOR UPDATE', [(int) $certificateId]);
            if (!$certificate) {
                $this->db->roll_back();
                return null;
            }
            $pending = $this->row('SELECT COUNT(*) AS total FROM certificate_signatures WHERE certificate_id = ? AND is_required = 1 AND status <> "signed"', [(int) $certificateId]);
            if ((int) ($pending['total'] ?? 0) !== 0) {
                $this->db->roll_back();
                return null;
            }
            $this->execute('UPDATE certificates SET status = "issued", file_path = ? WHERE certificate_id = ? AND status = "signed"', [$filePath, (int) $certificateId]);
            if (!empty($certificate['user_id'])) {
                $this->execute('INSERT INTO certificate_notifications (user_id, certificate_id, message) VALUES (?, ?, ?)', [
                    (int) $certificate['user_id'],
                    (int) $certificateId,
                    'Your certificate ' . $certificate['certificate_number'] . ' has been issued and is ready to download.',
                ]);
            }
            $this->db->commit();
            return $certificate;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function traineeCertificateNotifications($userId)
    {
        return $this->rows('SELECT n.notification_id, n.message, n.created_at, c.certificate_id, c.certificate_number FROM certificate_notifications n JOIN certificates c ON c.certificate_id = n.certificate_id WHERE n.user_id = ? ORDER BY n.created_at DESC LIMIT 10', [(int) $userId]);
    }

    public function revokeCertificate($certificateId, $revokedBy, $reason)
    {
        $this->execute('UPDATE certificates SET status = "revoked", revoked_at = CURDATE(), revoked_by = ?, revocation_reason = ? WHERE certificate_id = ?', [
            (int) $revokedBy,
            $reason,
            (int) $certificateId
        ]);
    }

    public function discardUnrenderedCertificate($certificateId)
    {
        return $this->execute('DELETE FROM certificates WHERE certificate_id = ? AND status = "issued" AND file_path IS NULL', [
            (int) $certificateId,
        ]) > 0;
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
}
