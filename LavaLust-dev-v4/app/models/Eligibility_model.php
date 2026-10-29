<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';
require_once APP_DIR . 'models/Payment_model.php';

class Eligibility_model extends Base_model
{
    private $configModel;

    public function __construct($db, $configModel = null)
    {
        parent::__construct($db);
        $this->configModel = $configModel;
    }

    public function getEligibilityRecords(array $filters = [])
    {
        $sql = 'SELECT e.*, cert.certificate_id, cert.status AS certificate_status, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, t.qr_code AS trainee_number, p.program_name, c.class_name, u.full_name AS reviewer_name, approver.full_name AS approver_name FROM certificate_eligibility e JOIN trainees t ON t.trainee_id = e.trainee_id JOIN training_classes c ON c.class_id = e.class_id JOIN training_programs p ON p.program_id = e.program_id LEFT JOIN certificates cert ON cert.trainee_id = e.trainee_id AND cert.class_id = e.class_id LEFT JOIN users u ON u.user_id = e.reviewed_by LEFT JOIN users approver ON approver.user_id = e.approved_by';
        $values = [];
        $whereClauses = [];

        if (!empty($filters['search'])) {
            $whereClauses[] = '(t.first_name LIKE ? OR t.last_name LIKE ? OR t.qr_code LIKE ? OR p.program_name LIKE ?)';
            $searchTerm = '%' . $filters['search'] . '%';
            $values[] = $searchTerm;
            $values[] = $searchTerm;
            $values[] = $searchTerm;
            $values[] = $searchTerm;
        }

        if (isset($filters['eligibility']) && $filters['eligibility'] !== '') {
            $whereClauses[] = 'e.is_eligible = ?';
            $values[] = (int) $filters['eligibility'];
        }

        if (!empty($filters['program_id'])) {
            $whereClauses[] = 'e.program_id = ?';
            $values[] = (int) $filters['program_id'];
        }

        if (!empty($whereClauses)) {
            $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
        }

        $sql .= ' ORDER BY e.updated_at DESC';

        return $this->rows($sql, $values);
    }

    public function deleteEligibilityEnrollment($eligibilityId, $actorId)
    {
        $eligibility = $this->row('SELECT trainee_id, class_id FROM certificate_eligibility WHERE eligibility_id = ?', [(int) $eligibilityId]);
        if (!$eligibility) {
            return ['success' => false, 'message' => 'Eligibility record not found.'];
        }
        if ($this->row('SELECT certificate_id FROM certificates WHERE trainee_id = ? AND class_id = ?', [
            (int) $eligibility['trainee_id'],
            (int) $eligibility['class_id'],
        ])) {
            return ['success' => false, 'message' => 'Enrollment cannot be removed because a certificate has already been issued or revoked.'];
        }

        $enrollment = $this->row('SELECT enrollment_id FROM class_enrollments WHERE trainee_id = ? AND class_id = ?', [
            (int) $eligibility['trainee_id'],
            (int) $eligibility['class_id'],
        ]);
        $message = $enrollment
            ? 'Enrollment removed. Attendance, documents, and assessment history were retained.'
            : 'Stale eligibility record removed; no enrollment was found.';
        $this->db->transaction();
        try {
            $this->logEligibilityAction(
                (int) $actorId,
                (int) $eligibilityId,
                $enrollment ? 'delete_enrollment' : 'delete_stale_eligibility',
                $message
            );
            if ($enrollment) {
                $this->execute('DELETE FROM class_enrollments WHERE enrollment_id = ?', [(int) $enrollment['enrollment_id']]);
            }
            $this->execute('DELETE FROM certificate_eligibility WHERE eligibility_id = ?', [(int) $eligibilityId]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        return ['success' => true, 'message' => $message];
    }

    public function calculateEligibility($traineeId, $classId)
    {
        $minAttendance = (float) $this->configModel->getConfig('min_attendance_percentage', 75);
        $requireDocuments = (bool) $this->configModel->getConfig('require_documents', 1);
        $requireAssessments = (bool) $this->configModel->getConfig('require_assessments', 0);
        $requiredAssessments = max(1, (int) $this->configModel->getConfig('required_assessment_count', 1));

        $class = $this->row('SELECT c.*, p.program_id FROM training_classes c JOIN training_programs p ON p.program_id = c.program_id WHERE c.class_id = ?', [(int) $classId]);
        if (!$class) {
            return ['eligible' => false, 'reasons' => ['Class not found']];
        }
        $paymentModel = new Payment_model($this->db);

        $reasons = [];
        if (empty($class['end_date']) || $class['end_date'] > date('Y-m-d')) {
            $reasons[] = 'The class has not reached its completion date';
        }

        $enrollment = $this->row('SELECT * FROM class_enrollments WHERE trainee_id = ? AND class_id = ?', [(int) $traineeId, (int) $classId]);
        if (!$enrollment) {
            $reasons[] = 'Not enrolled in this class';
        } else {
            $paymentModel->syncEnrollmentFees((int) $traineeId, (int) $classId);
            $requiredFees = $this->rows("SELECT ft.fee_type_id, ft.fee_name, ft.class_id FROM fee_types ft WHERE ft.is_active = 1 AND ft.required_for_certificate = 1 AND (ft.class_id = ? OR (ft.class_id IS NULL AND ft.program_id = ? AND NOT EXISTS (SELECT 1 FROM fee_types class_override WHERE class_override.class_id = ? AND class_override.is_active = 1 AND class_override.fee_kind = ft.fee_kind)))", [
                (int) $classId, (int) $class['program_id'], (int) $classId,
            ]);
            $unpaidFeeNames = [];
            foreach ($requiredFees as $requiredFee) {
                $feeBalance = $this->row("SELECT tf.trainee_fee_id, GREATEST(tf.amount_due - COALESCE(SUM(CASE WHEN pay.entry_type = 'payment' THEN pay.amount WHEN pay.entry_type = 'reversal' THEN -pay.amount ELSE 0 END), 0), 0) AS balance FROM trainee_fees tf JOIN training_classes fee_class ON fee_class.class_id = tf.class_id LEFT JOIN payments pay ON pay.trainee_fee_id = tf.trainee_fee_id WHERE tf.fee_type_id = ? AND tf.trainee_id = ? AND (? IS NULL OR tf.class_id = ?) AND fee_class.program_id = ? GROUP BY tf.trainee_fee_id", [
                    (int) $requiredFee['fee_type_id'], (int) $traineeId, $requiredFee['class_id'], (int) $requiredFee['class_id'], (int) $class['program_id'],
                ]);
                if (!$feeBalance || (float) $feeBalance['balance'] > 0.00001) {
                    $unpaidFeeNames[] = $requiredFee['fee_name'];
                }
            }
            if ($unpaidFeeNames) {
                $reasons[] = 'Required fees unpaid: ' . implode(', ', $unpaidFeeNames);
            }
        }

        $attendance = $this->row("SELECT COUNT(*) AS total, SUM(CASE WHEN status IN ('Present', 'Late') THEN 1 ELSE 0 END) AS attended FROM attendance_records WHERE trainee_id = ? AND class_id = ?", [(int) $traineeId, (int) $classId]);
        $attendancePercentage = $attendance && (int) $attendance['total'] > 0
            ? ((int) $attendance['attended'] / (int) $attendance['total']) * 100
            : 0;
        if ($attendancePercentage < $minAttendance) {
            $reasons[] = sprintf('Attendance (%.1f%%) below minimum (%.0f%%)', $attendancePercentage, $minAttendance);
        }

        $requirements = $this->rows('SELECT required_document_name FROM certificate_document_requirements WHERE class_id = ? ORDER BY required_document_name', [(int) $classId]);
        $submittedDocuments = $this->rows('SELECT document_name FROM trainee_documents WHERE trainee_id = ? AND class_id = ? AND status = "active" AND review_status = "approved"', [(int) $traineeId, (int) $classId]);
        $submittedNames = [];
        foreach ($submittedDocuments as $document) {
            $submittedNames[mb_strtolower(trim($document['document_name']))] = true;
        }
        $matchedRequiredDocuments = 0;
        $missingDocuments = [];
        foreach ($requirements as $requirement) {
            if (isset($submittedNames[mb_strtolower(trim($requirement['required_document_name']))])) {
                $matchedRequiredDocuments++;
            } else {
                $missingDocuments[] = $requirement['required_document_name'];
            }
        }

        if ($requireDocuments) {
            if (!$requirements) {
                $reasons[] = 'No required-document checklist is configured for this class';
            } elseif ($missingDocuments) {
                $reasons[] = 'Missing required documents: ' . implode(', ', $missingDocuments);
            }
        }

        $assessmentCounts = $this->row("SELECT COUNT(*) AS total, SUM(CASE WHEN outcome = 'pass' THEN 1 ELSE 0 END) AS passed FROM training_assessments WHERE trainee_id = ? AND class_id = ?", [(int) $traineeId, (int) $classId]);
        $assessmentsPassed = (int) ($assessmentCounts['passed'] ?? 0);
        if ($requireAssessments) {
            if ($assessmentsPassed < $requiredAssessments) {
                $reasons[] = sprintf('Passed assessments (%d/%d) do not meet the requirement', $assessmentsPassed, $requiredAssessments);
            }
        }

        $existing = $this->row('SELECT certificate_id FROM certificates WHERE trainee_id = ? AND class_id = ?', [(int) $traineeId, (int) $classId]);
        if ($existing) {
            $reasons[] = 'A certificate record already exists for this class';
        }

        $isEligible = empty($reasons);
        $reasonText = implode('; ', $reasons);
        $check = $this->row('SELECT eligibility_id, program_id, attendance_percentage, documents_submitted, documents_required, documents_approved, assessments_completed, assessments_required, is_eligible, ineligibility_reasons FROM certificate_eligibility WHERE trainee_id = ? AND class_id = ?', [(int) $traineeId, (int) $classId]);
        $documentsSubmitted = count($submittedDocuments);
        $documentsRequired = count($requirements);
        $eligibilityChanged = !$check
            || (int) $check['program_id'] !== (int) $class['program_id']
            || (float) $check['attendance_percentage'] !== round($attendancePercentage, 2)
            || (int) $check['documents_submitted'] !== $documentsSubmitted
            || (int) $check['documents_required'] !== $documentsRequired
            || (int) $check['documents_approved'] !== $matchedRequiredDocuments
            || (int) $check['assessments_completed'] !== $assessmentsPassed
            || (int) $check['assessments_required'] !== ($requireAssessments ? $requiredAssessments : 0)
            || (int) $check['is_eligible'] !== (int) $isEligible
            || (string) ($check['ineligibility_reasons'] ?? '') !== $reasonText;
        $resetApproval = $eligibilityChanged && !$existing;
        if ($check) {
            $this->execute("UPDATE certificate_eligibility SET program_id = ?, attendance_percentage = ?, documents_submitted = ?, documents_required = ?, documents_approved = ?, assessments_completed = ?, assessments_required = ?, is_eligible = ?, ineligibility_reasons = ?, approval_status = CASE WHEN ? = 1 THEN 'pending' ELSE approval_status END, approval_reason = CASE WHEN ? = 1 THEN NULL ELSE approval_reason END, approved_by = CASE WHEN ? = 1 THEN NULL ELSE approved_by END, approved_at = CASE WHEN ? = 1 THEN NULL ELSE approved_at END, updated_at = CURRENT_TIMESTAMP WHERE eligibility_id = ?", [
                (int) $class['program_id'],
                round($attendancePercentage, 2),
                $documentsSubmitted,
                $documentsRequired,
                $matchedRequiredDocuments,
                $assessmentsPassed,
                $requireAssessments ? $requiredAssessments : 0,
                $isEligible ? 1 : 0,
                $reasonText,
                $resetApproval ? 1 : 0,
                $resetApproval ? 1 : 0,
                $resetApproval ? 1 : 0,
                $resetApproval ? 1 : 0,
                (int) $check['eligibility_id'],
            ]);
        } else {
            $this->execute('INSERT INTO certificate_eligibility (trainee_id, class_id, program_id, attendance_percentage, documents_submitted, documents_required, documents_approved, assessments_completed, assessments_required, is_eligible, ineligibility_reasons) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                (int) $traineeId,
                (int) $classId,
                (int) $class['program_id'],
                round($attendancePercentage, 2),
                $documentsSubmitted,
                $documentsRequired,
                $matchedRequiredDocuments,
                $assessmentsPassed,
                $requireAssessments ? $requiredAssessments : 0,
                $isEligible ? 1 : 0,
                $reasonText
            ]);
        }

        $paymentModel->evaluateBondClearanceForEnrollment((int) $traineeId, (int) $classId);
        return [
            'eligible' => $isEligible,
            'reasons' => $reasons,
            'attendance_percentage' => $attendancePercentage,
            'documents_submitted' => $documentsSubmitted,
            'documents_required' => $documentsRequired,
            'documents_approved' => $matchedRequiredDocuments,
            'assessments_passed' => $assessmentsPassed,
            'assessments_required' => $requireAssessments ? $requiredAssessments : 0,
        ];
    }

    public function refreshEligibilityRecords()
    {
        $enrollments = $this->rows('SELECT trainee_id, class_id FROM class_enrollments');
        foreach ($enrollments as $enrollment) {
            $this->calculateEligibility((int) $enrollment['trainee_id'], (int) $enrollment['class_id']);
        }
    }

    public function recalculateClassEligibility($classId)
    {
        $enrollments = $this->rows('SELECT trainee_id FROM class_enrollments WHERE class_id = ?', [(int) $classId]);
        foreach ($enrollments as $enrollment) {
            $this->calculateEligibility((int) $enrollment['trainee_id'], (int) $classId);
        }
    }

    public function updateEligibilityApproval($eligibilityId, $decision, $actorId, $reason = null)
    {
        $eligibility = $this->row('SELECT is_eligible FROM certificate_eligibility WHERE eligibility_id = ?', [(int) $eligibilityId]);
        if (!$eligibility || ($decision === 'approved' && !(int) $eligibility['is_eligible'])) {
            return false;
        }
        $this->execute('UPDATE certificate_eligibility SET approval_status = ?, approval_reason = ?, approved_by = ?, approved_at = CURRENT_TIMESTAMP, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE eligibility_id = ?', [
            $decision,
            $reason,
            (int) $actorId,
            (int) $actorId,
            (int) $eligibilityId,
        ]);
        return true;
    }

    public function logEligibilityAction($actorId, $eligibilityId, $action, $details = null)
    {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $this->execute('INSERT INTO certificate_audit_logs (actor_id, eligibility_id, action, details, ip_address) VALUES (?, ?, ?, ?, ?)', [
            (int) $actorId,
            (int) $eligibilityId,
            $action,
            $details,
            $ipAddress,
        ]);
    }
}
