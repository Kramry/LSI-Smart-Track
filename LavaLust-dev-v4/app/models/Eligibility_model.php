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
        $query = $this->db->table('certificate_eligibility e')
            ->select('e.eligibility_id, e.trainee_id, e.class_id, e.program_id, e.attendance_percentage, e.documents_submitted, e.documents_required, e.documents_approved, e.assessments_completed, e.assessments_required, e.is_eligible, e.ineligibility_reasons, e.approval_status, e.approval_reason, e.approved_by, e.approved_at, e.reviewed_by, e.reviewed_at, e.created_at, e.updated_at, cert.certificate_id, cert.status AS certificate_status, t.first_name, t.last_name, t.qr_code AS trainee_number, p.program_name, c.class_name, u.full_name AS reviewer_name, approver.full_name AS approver_name')
            ->join('trainees t', 't.trainee_id = e.trainee_id')
            ->join('training_classes c', 'c.class_id = e.class_id')
            ->join('training_programs p', 'p.program_id = e.program_id')
            ->left_join('certificates cert', 'cert.trainee_id = e.trainee_id AND cert.class_id = e.class_id')
            ->left_join('users u', 'u.user_id = e.reviewed_by')
            ->left_join('users approver', 'approver.user_id = e.approved_by');

        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $query->grouped(function ($db) use ($searchTerm) {
                $db->like('t.first_name', $searchTerm)
                    ->or_like('t.last_name', $searchTerm)
                    ->or_like('t.qr_code', $searchTerm)
                    ->or_like('p.program_name', $searchTerm);
            });
        }

        if (isset($filters['eligibility']) && $filters['eligibility'] !== '') {
            $query->where('e.is_eligible', (int) $filters['eligibility']);
        }

        if (!empty($filters['program_id'])) {
            $query->where('e.program_id', (int) $filters['program_id']);
        }

        $records = $query->order_by('e.updated_at', 'DESC')->result_array();
        foreach ($records as &$record) {
            $traineeName = $record['first_name'] === null || $record['last_name'] === null
                ? null
                : $record['first_name'] . ' ' . $record['last_name'];
            $record = [
                'eligibility_id' => $record['eligibility_id'],
                'trainee_id' => $record['trainee_id'],
                'class_id' => $record['class_id'],
                'program_id' => $record['program_id'],
                'attendance_percentage' => $record['attendance_percentage'],
                'documents_submitted' => $record['documents_submitted'],
                'documents_required' => $record['documents_required'],
                'documents_approved' => $record['documents_approved'],
                'assessments_completed' => $record['assessments_completed'],
                'assessments_required' => $record['assessments_required'],
                'is_eligible' => $record['is_eligible'],
                'ineligibility_reasons' => $record['ineligibility_reasons'],
                'approval_status' => $record['approval_status'],
                'approval_reason' => $record['approval_reason'],
                'approved_by' => $record['approved_by'],
                'approved_at' => $record['approved_at'],
                'reviewed_by' => $record['reviewed_by'],
                'reviewed_at' => $record['reviewed_at'],
                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
                'certificate_id' => $record['certificate_id'],
                'certificate_status' => $record['certificate_status'],
                'trainee_name' => $traineeName,
                'trainee_number' => $record['trainee_number'],
                'program_name' => $record['program_name'],
                'class_name' => $record['class_name'],
                'reviewer_name' => $record['reviewer_name'],
                'approver_name' => $record['approver_name'],
            ];
        }
        unset($record);
        return $records;
    }

    public function deleteEligibilityEnrollment($eligibilityId, $actorId)
    {
        $eligibility = $this->db->table('certificate_eligibility')
            ->select('trainee_id, class_id')
            ->where('eligibility_id', (int) $eligibilityId)
            ->row_array();
        if (!$eligibility) {
            return ['success' => false, 'message' => 'Eligibility record not found.'];
        }
        if ($this->db->table('certificates')
            ->select('certificate_id')
            ->where('trainee_id', (int) $eligibility['trainee_id'])
            ->where('class_id', (int) $eligibility['class_id'])
            ->row_array()) {
            return ['success' => false, 'message' => 'Enrollment cannot be removed because a certificate has already been issued or revoked.'];
        }

        $enrollment = $this->db->table('class_enrollments')
            ->select('enrollment_id')
            ->where('trainee_id', (int) $eligibility['trainee_id'])
            ->where('class_id', (int) $eligibility['class_id'])
            ->row_array();
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
                $this->db->table('class_enrollments')
                    ->where('enrollment_id', (int) $enrollment['enrollment_id'])
                    ->delete();
            }
            $this->db->table('certificate_eligibility')
                ->where('eligibility_id', (int) $eligibilityId)
                ->delete();
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

        $class = $this->db->table('training_classes c')
            ->select('c.class_id, c.program_id, c.instructor_id, c.class_name, c.room, c.start_date, c.end_date, c.status, c.created_at')
            ->join('training_programs p', 'p.program_id = c.program_id')
            ->where('c.class_id', (int) $classId)
            ->row_array();
        if (!$class) {
            return ['eligible' => false, 'reasons' => ['Class not found']];
        }
        $paymentModel = new Payment_model($this->db);

        $reasons = [];
        if (empty($class['end_date']) || $class['end_date'] > date('Y-m-d')) {
            $reasons[] = 'The class has not reached its completion date';
        }

        $enrollment = $this->db->table('class_enrollments')
            ->where('trainee_id', (int) $traineeId)
            ->where('class_id', (int) $classId)
            ->row_array();
        if (!$enrollment) {
            $reasons[] = 'Not enrolled in this class';
        } else {
            $paymentModel->syncEnrollmentFees((int) $traineeId, (int) $classId);
            $requiredFees = $this->rows("SELECT ft.fee_type_id, ft.fee_name, ft.class_id FROM fee_types ft WHERE ft.is_active = 1 AND ft.required_for_certificate = 1 AND (ft.class_id = ? OR (ft.class_id IS NULL AND ft.program_id = ? AND NOT EXISTS (SELECT 1 FROM fee_types class_override WHERE class_override.class_id = ? AND class_override.is_active = 1 AND class_override.fee_kind = ft.fee_kind)))", [
                (int) $classId, (int) $class['program_id'], (int) $classId,
            ]);
            $unpaidFeeNames = [];
            foreach ($requiredFees as $requiredFee) {
                $feeBalance = $this->db->table('trainee_fees tf')
                    ->select('tf.trainee_fee_id, tf.amount_due, SUM(CASE WHEN pay.entry_type = \'payment\' THEN pay.amount WHEN pay.entry_type = \'reversal\' THEN -pay.amount ELSE 0 END) AS net_paid')
                    ->join('training_classes fee_class', 'fee_class.class_id = tf.class_id')
                    ->left_join('payments pay', 'pay.trainee_fee_id = tf.trainee_fee_id')
                    ->where('tf.fee_type_id', (int) $requiredFee['fee_type_id'])
                    ->where('tf.trainee_id', (int) $traineeId)
                    ->where('fee_class.program_id', (int) $class['program_id'])
                    ->group_by('tf.trainee_fee_id');
                if ($requiredFee['class_id'] !== null) {
                    $feeBalance->where('tf.class_id', (int) $requiredFee['class_id']);
                }
                $feeBalance = $feeBalance->row_array();
                if ($feeBalance) {
                    $feeBalance['balance'] = max(
                        (float) $feeBalance['amount_due'] - (float) ($feeBalance['net_paid'] ?? 0),
                        0
                    );
                }
                if (!$feeBalance || (float) $feeBalance['balance'] > 0.00001) {
                    $unpaidFeeNames[] = $requiredFee['fee_name'];
                }
            }
            if ($unpaidFeeNames) {
                $reasons[] = 'Required fees unpaid: ' . implode(', ', $unpaidFeeNames);
            }
        }

        $attendance = $this->db->table('attendance_records')
            ->select("COUNT(*) AS total, SUM(CASE WHEN status = 'Present' OR status = 'Late' THEN 1 ELSE 0 END) AS attended")
            ->where('trainee_id', (int) $traineeId)
            ->where('class_id', (int) $classId)
            ->row_array();
        $attendancePercentage = $attendance && (int) $attendance['total'] > 0
            ? ((int) $attendance['attended'] / (int) $attendance['total']) * 100
            : 0;
        if ($attendancePercentage < $minAttendance) {
            $reasons[] = sprintf('Attendance (%.1f%%) below minimum (%.0f%%)', $attendancePercentage, $minAttendance);
        }

        $requirements = $this->db->table('certificate_document_requirements')
            ->select('required_document_name')
            ->where('class_id', (int) $classId)
            ->order_by('required_document_name')
            ->result_array();
        $submittedDocuments = $this->db->table('trainee_documents')
            ->select('document_name')
            ->where('trainee_id', (int) $traineeId)
            ->where('class_id', (int) $classId)
            ->where('status', 'active')
            ->where('review_status', 'approved')
            ->result_array();
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

        $assessmentCounts = $this->db->table('training_assessments')
            ->select('COUNT(*) AS total, SUM(CASE WHEN outcome = \'pass\' THEN 1 ELSE 0 END) AS passed')
            ->where('trainee_id', (int) $traineeId)
            ->where('class_id', (int) $classId)
            ->row_array();
        $assessmentsPassed = (int) ($assessmentCounts['passed'] ?? 0);
        if ($requireAssessments) {
            if ($assessmentsPassed < $requiredAssessments) {
                $reasons[] = sprintf('Passed assessments (%d/%d) do not meet the requirement', $assessmentsPassed, $requiredAssessments);
            }
        }

        $existing = $this->db->table('certificates')
            ->select('certificate_id')
            ->where('trainee_id', (int) $traineeId)
            ->where('class_id', (int) $classId)
            ->row_array();
        if ($existing) {
            $reasons[] = 'A certificate record already exists for this class';
        }

        $isEligible = empty($reasons);
        $reasonText = implode('; ', $reasons);
        $check = $this->db->table('certificate_eligibility')
            ->select('eligibility_id, program_id, attendance_percentage, documents_submitted, documents_required, documents_approved, assessments_completed, assessments_required, is_eligible, ineligibility_reasons')
            ->where('trainee_id', (int) $traineeId)
            ->where('class_id', (int) $classId)
            ->row_array();
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
            $this->db->table('certificate_eligibility')->insert([
                'trainee_id' => (int) $traineeId,
                'class_id' => (int) $classId,
                'program_id' => (int) $class['program_id'],
                'attendance_percentage' => round($attendancePercentage, 2),
                'documents_submitted' => $documentsSubmitted,
                'documents_required' => $documentsRequired,
                'documents_approved' => $matchedRequiredDocuments,
                'assessments_completed' => $assessmentsPassed,
                'assessments_required' => $requireAssessments ? $requiredAssessments : 0,
                'is_eligible' => $isEligible ? 1 : 0,
                'ineligibility_reasons' => $reasonText,
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
        $enrollments = $this->db->table('class_enrollments')
            ->select('trainee_id, class_id')
            ->result_array();
        foreach ($enrollments as $enrollment) {
            $this->calculateEligibility((int) $enrollment['trainee_id'], (int) $enrollment['class_id']);
        }
    }

    public function recalculateClassEligibility($classId)
    {
        $enrollments = $this->db->table('class_enrollments')
            ->select('trainee_id')
            ->where('class_id', (int) $classId)
            ->result_array();
        foreach ($enrollments as $enrollment) {
            $this->calculateEligibility((int) $enrollment['trainee_id'], (int) $classId);
        }
    }

    public function updateEligibilityApproval($eligibilityId, $decision, $actorId, $reason = null)
    {
        $eligibility = $this->db->table('certificate_eligibility')
            ->select('is_eligible')
            ->where('eligibility_id', (int) $eligibilityId)
            ->row_array();
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
        $this->db->table('certificate_audit_logs')->insert([
            'actor_id' => (int) $actorId,
            'eligibility_id' => (int) $eligibilityId,
            'action' => $action,
            'details' => $details,
            'ip_address' => $ipAddress,
        ]);
    }
}
