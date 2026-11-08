<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';

class Analytics_model extends Base_model
{
    public function classOptions(array $user)
    {
        $sql = "SELECT class_id, class_name FROM training_classes WHERE 1 = 1";
        $values = [];
        if (($user['role'] ?? '') === 'instructor') {
            $sql .= ' AND instructor_id = ?';
            $values[] = (int) $user['user_id'];
        }
        return $this->rows($sql . ' ORDER BY class_name', $values);
    }

    public function classIsVisible($classId, array $user)
    {
        $sql = 'SELECT class_id FROM training_classes WHERE class_id = ?';
        $values = [(int) $classId];
        if (($user['role'] ?? '') === 'instructor') {
            $sql .= ' AND instructor_id = ?';
            $values[] = (int) $user['user_id'];
        }
        return (bool) $this->row($sql . ' LIMIT 1', $values);
    }

    public function analytics(array $user, array $filters)
    {
        $classWhere = '';
        $classValues = [];
        if (!empty($filters['class_id'])) {
            $classWhere .= ' AND c.class_id = ?';
            $classValues[] = (int) $filters['class_id'];
        }
        if (($user['role'] ?? '') === 'instructor') {
            $classWhere .= ' AND c.instructor_id = ?';
            $classValues[] = (int) $user['user_id'];
        }

        $attendanceWhere = 'a.attendance_date BETWEEN ? AND ?';
        $attendanceValues = [$filters['start_date'], $filters['end_date']];
        if (!empty($filters['class_id'])) {
            $attendanceWhere .= ' AND a.class_id = ?';
            $attendanceValues[] = (int) $filters['class_id'];
        }
        if (($user['role'] ?? '') === 'instructor') {
            $attendanceWhere .= ' AND a.class_id IN (SELECT class_id FROM training_classes WHERE instructor_id = ?)';
            $attendanceValues[] = (int) $user['user_id'];
        }

        $summary = $this->row(
            "SELECT
                (SELECT COUNT(DISTINCT e.trainee_id)
                 FROM class_enrollments e
                 JOIN trainees t ON t.trainee_id = e.trainee_id AND t.status = 'active'
                 JOIN training_classes c ON c.class_id = e.class_id
                 WHERE 1 = 1 {$classWhere}) AS active_trainees,
                (SELECT COUNT(DISTINCT c.class_id)
                 FROM training_classes c
                 WHERE c.status = 'active' {$classWhere}) AS active_classes,
                COUNT(a.attendance_id) AS attendance_records,
                COALESCE(SUM(CASE WHEN a.status IN ('Present', 'Late') THEN 1 ELSE 0 END), 0) AS attended_records,
                COALESCE(SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END), 0) AS absent_records
             FROM attendance_records a
             JOIN training_classes c ON c.class_id = a.class_id
             WHERE {$attendanceWhere}",
            array_merge($classValues, $classValues, $attendanceValues)
        ) ?: [];
        $totalRecords = (int) ($summary['attendance_records'] ?? 0);
        $summary = [
            'active_trainees' => (int) ($summary['active_trainees'] ?? 0),
            'active_classes' => (int) ($summary['active_classes'] ?? 0),
            'attendance_records' => $totalRecords,
            'absent_records' => (int) ($summary['absent_records'] ?? 0),
            'attendance_rate' => $totalRecords > 0
                ? round(((int) $summary['attended_records'] / $totalRecords) * 100, 1)
                : 0,
        ];

        $trendRows = $this->rows(
            "SELECT a.attendance_date,
                    COUNT(*) AS total,
                    SUM(a.status = 'Present') AS present,
                    SUM(a.status = 'Late') AS late,
                    SUM(a.status = 'Absent') AS absent
             FROM attendance_records a
             JOIN training_classes c ON c.class_id = a.class_id
             WHERE {$attendanceWhere}
             GROUP BY a.attendance_date
             ORDER BY a.attendance_date",
            $attendanceValues
        );
        $trendByDate = [];
        foreach ($trendRows as $row) {
            $trendByDate[$row['attendance_date']] = [
                'present' => (int) $row['present'],
                'late' => (int) $row['late'],
                'absent' => (int) $row['absent'],
                'total' => (int) $row['total'],
            ];
        }
        $trend = [];
        $period = new DatePeriod(
            new DateTimeImmutable($filters['start_date']),
            new DateInterval('P1D'),
            (new DateTimeImmutable($filters['end_date']))->modify('+1 day')
        );
        foreach ($period as $date) {
            $key = $date->format('Y-m-d');
            $trend[] = [
                'date' => $key,
                'label' => $date->format('M j'),
                'present' => $trendByDate[$key]['present'] ?? 0,
                'late' => $trendByDate[$key]['late'] ?? 0,
                'absent' => $trendByDate[$key]['absent'] ?? 0,
            ];
        }

        $statusRows = $this->rows(
            "SELECT a.status, COUNT(*) AS total
             FROM attendance_records a
             JOIN training_classes c ON c.class_id = a.class_id
             WHERE {$attendanceWhere}
             GROUP BY a.status
             ORDER BY a.status",
            $attendanceValues
        );
        $status = ['Present' => 0, 'Late' => 0, 'Absent' => 0];
        foreach ($statusRows as $row) {
            $status[$row['status']] = (int) $row['total'];
        }

        $classes = $this->rows(
            "SELECT c.class_id, c.class_name, p.program_name,
                    COALESCE(enrollment_stats.active_trainees, 0) AS active_trainees,
                    COALESCE(attendance_stats.attendance_records, 0) AS attendance_records,
                    COALESCE(attendance_stats.attended_records, 0) AS attended_records,
                    COALESCE(attendance_stats.absent_records, 0) AS absent_records
             FROM training_classes c
             JOIN training_programs p ON p.program_id = c.program_id
             LEFT JOIN (
                    SELECT e.class_id, COUNT(DISTINCT t.trainee_id) AS active_trainees
                    FROM class_enrollments e
                    JOIN trainees t ON t.trainee_id = e.trainee_id AND t.status = 'active'
                    GROUP BY e.class_id
             ) enrollment_stats ON enrollment_stats.class_id = c.class_id
             LEFT JOIN (
                    SELECT a.class_id, COUNT(*) AS attendance_records,
                           SUM(a.status IN ('Present', 'Late')) AS attended_records,
                           SUM(a.status = 'Absent') AS absent_records
                    FROM attendance_records a
                    WHERE a.attendance_date BETWEEN ? AND ?
                    GROUP BY a.class_id
             ) attendance_stats ON attendance_stats.class_id = c.class_id
             WHERE 1 = 1 {$classWhere}
             ORDER BY absent_records DESC, c.class_name",
            array_merge([$filters['start_date'], $filters['end_date']], $classValues)
        );
        foreach ($classes as &$class) {
            $classTotal = (int) $class['attendance_records'];
            $class['class_id'] = (int) $class['class_id'];
            $class['active_trainees'] = (int) $class['active_trainees'];
            $class['attendance_records'] = $classTotal;
            $class['absent_records'] = (int) $class['absent_records'];
            $class['attendance_rate'] = $classTotal > 0
                ? round(((int) $class['attended_records'] / $classTotal) * 100, 1)
                : 0;
            unset($class['attended_records']);
        }
        unset($class);

        $trainees = $this->rows(
            "SELECT t.trainee_id, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name,
                    GROUP_CONCAT(DISTINCT c.class_name ORDER BY c.class_name SEPARATOR ', ') AS classes,
                    COUNT(a.attendance_id) AS attendance_records,
                    COALESCE(SUM(a.status = 'Present'), 0) AS present_records,
                    COALESCE(SUM(a.status = 'Late'), 0) AS late_records,
                    COALESCE(SUM(a.status = 'Absent'), 0) AS absent_records,
                    MAX(a.attendance_date) AS last_attendance
             FROM attendance_records a
             JOIN trainees t ON t.trainee_id = a.trainee_id
             JOIN training_classes c ON c.class_id = a.class_id
             WHERE {$attendanceWhere}
             GROUP BY t.trainee_id, t.first_name, t.last_name
             ORDER BY absent_records DESC, late_records DESC, t.last_name, t.first_name
             LIMIT 100",
            $attendanceValues
        );
        foreach ($trainees as &$trainee) {
            $count = (int) $trainee['attendance_records'];
            $trainee['trainee_id'] = (int) $trainee['trainee_id'];
            $trainee['attendance_records'] = $count;
            $trainee['present_records'] = (int) $trainee['present_records'];
            $trainee['late_records'] = (int) $trainee['late_records'];
            $trainee['absent_records'] = (int) $trainee['absent_records'];
            $trainee['attendance_rate'] = $count > 0
                ? round((($trainee['present_records'] + $trainee['late_records']) / $count) * 100, 1)
                : 0;
        }
        unset($trainee);

        $result = [
            'filters' => $filters,
            'class_options' => $this->classOptions($user),
            'summary' => $summary,
            'trend' => $trend,
            'status' => $status,
            'classes' => $classes,
            'trainees' => $trainees,
        ];
        if (in_array($user['role'] ?? '', ['administrator', 'staff'], true)) {
            $result['payments'] = $this->paymentAnalytics($filters);
            $result['training'] = $this->trainingAnalytics($filters);
            $result['assessments'] = $this->assessmentAnalytics($filters);
            $result['documents'] = $this->documentAnalytics($filters);
            $result['certificates'] = $this->certificateAnalytics($filters);
            $result['announcements'] = $this->announcementAnalytics($filters);
        }
        return $result;
    }

    private function assessmentAnalytics(array $filters)
    {
        $classScope = '';
        $values = [$filters['start_date'], $filters['end_date']];
        if (!empty($filters['class_id'])) {
            $classScope = ' AND a.class_id = ?';
            $values[] = (int) $filters['class_id'];
        }

        $summary = $this->row(
            "SELECT COUNT(*) AS total,
                    SUM(LOWER(a.outcome) = 'pass') AS passed,
                    SUM(LOWER(a.outcome) = 'fail') AS failed
             FROM training_assessments a
             JOIN training_classes c ON c.class_id = a.class_id
             WHERE a.assessment_date BETWEEN ? AND ? {$classScope}",
            $values
        ) ?: [];

        $byProgram = $this->rows(
            "SELECT p.program_id, p.program_name,
                    COUNT(*) AS total,
                    SUM(LOWER(a.outcome) = 'pass') AS passed,
                    SUM(LOWER(a.outcome) = 'fail') AS failed
             FROM training_assessments a
             JOIN training_classes c ON c.class_id = a.class_id
             JOIN training_programs p ON p.program_id = c.program_id
             WHERE a.assessment_date BETWEEN ? AND ? {$classScope}
             GROUP BY p.program_id, p.program_name
             ORDER BY p.program_name",
            $values
        );
        foreach ($byProgram as &$program) {
            $program['program_id'] = (int) $program['program_id'];
            $program['total'] = (int) $program['total'];
            $program['passed'] = (int) ($program['passed'] ?? 0);
            $program['failed'] = (int) ($program['failed'] ?? 0);
            $program['pass_rate'] = $program['total'] > 0 ? round(($program['passed'] / $program['total']) * 100, 1) : 0;
        }
        unset($program);

        $total = (int) ($summary['total'] ?? 0);
        $passed = (int) ($summary['passed'] ?? 0);
        $failed = (int) ($summary['failed'] ?? 0);
        return [
            'summary' => [
                'total_assessments' => $total,
                'passed' => $passed,
                'failed' => $failed,
                'other_outcomes' => max(0, $total - $passed - $failed),
                'pass_rate' => $total > 0 ? round(($passed / $total) * 100, 1) : 0,
            ],
            'by_program' => $byProgram,
        ];
    }

    private function paymentAnalytics(array $filters)
    {
        $feeScope = '';
        $feeValues = [];
        if (!empty($filters['class_id'])) {
            $feeScope = ' AND f.class_id = ?';
            $feeValues[] = (int) $filters['class_id'];
        }

        $paymentScope = '';
        $paymentValues = [$filters['start_date'], $filters['end_date']];
        if (!empty($filters['class_id'])) {
            $paymentScope = ' AND f.class_id = ?';
            $paymentValues[] = (int) $filters['class_id'];
        }
        $paymentTotals = $this->row(
            "SELECT
                COALESCE(SUM(CASE WHEN p.entry_type = 'payment' THEN p.amount WHEN p.entry_type = 'reversal' THEN -p.amount ELSE 0 END), 0) AS net_collected,
                COALESCE(SUM(CASE WHEN p.entry_type = 'payment' THEN p.amount ELSE 0 END), 0) AS gross_collected,
                COALESCE(SUM(CASE WHEN p.entry_type = 'reversal' THEN p.amount ELSE 0 END), 0) AS reversed_amount
             FROM payments p
             JOIN trainee_fees f ON f.trainee_fee_id = p.trainee_fee_id
             WHERE p.payment_date BETWEEN ? AND ? {$paymentScope}",
            $paymentValues
        ) ?: [];

        $trendRows = $this->rows(
            "SELECT p.payment_date,
                    COALESCE(SUM(CASE WHEN p.entry_type = 'payment' THEN p.amount WHEN p.entry_type = 'reversal' THEN -p.amount ELSE 0 END), 0) AS net_collected
             FROM payments p
             JOIN trainee_fees f ON f.trainee_fee_id = p.trainee_fee_id
             WHERE p.payment_date BETWEEN ? AND ? {$paymentScope}
             GROUP BY p.payment_date
             ORDER BY p.payment_date",
            $paymentValues
        );
        $collectionByDate = [];
        foreach ($trendRows as $row) {
            $collectionByDate[$row['payment_date']] = (float) $row['net_collected'];
        }
        $collections = [];
        $period = new DatePeriod(
            new DateTimeImmutable($filters['start_date']),
            new DateInterval('P1D'),
            (new DateTimeImmutable($filters['end_date']))->modify('+1 day')
        );
        foreach ($period as $date) {
            $key = $date->format('Y-m-d');
            $collections[] = [
                'date' => $key,
                'label' => $date->format('M j'),
                'amount' => round($collectionByDate[$key] ?? 0, 2),
            ];
        }

        $byFeeKind = $this->rows(
            "SELECT f.fee_kind,
                    COALESCE(SUM(CASE WHEN p.entry_type = 'payment' THEN p.amount WHEN p.entry_type = 'reversal' THEN -p.amount ELSE 0 END), 0) AS net_collected
             FROM payments p
             JOIN trainee_fees f ON f.trainee_fee_id = p.trainee_fee_id
             WHERE p.payment_date BETWEEN ? AND ? {$paymentScope}
             GROUP BY f.fee_kind
             ORDER BY f.fee_kind",
            $paymentValues
        );
        foreach ($byFeeKind as &$row) {
            $row['net_collected'] = round((float) $row['net_collected'], 2);
        }
        unset($row);

        $byMethod = $this->rows(
            "SELECT COALESCE(p.method, 'unspecified') AS method,
                    COALESCE(SUM(CASE WHEN p.entry_type = 'payment' THEN p.amount WHEN p.entry_type = 'reversal' THEN -p.amount ELSE 0 END), 0) AS net_collected,
                    SUM(p.entry_type = 'payment') AS payment_count
             FROM payments p
             JOIN trainee_fees f ON f.trainee_fee_id = p.trainee_fee_id
             WHERE p.payment_date BETWEEN ? AND ? {$paymentScope}
             GROUP BY p.method
             ORDER BY net_collected DESC",
            $paymentValues
        );
        foreach ($byMethod as &$row) {
            $row['net_collected'] = round((float) $row['net_collected'], 2);
            $row['payment_count'] = (int) $row['payment_count'];
        }
        unset($row);

        $outstanding = $this->row(
            "SELECT COALESCE(SUM(GREATEST(f.amount_due - COALESCE(paid.total_paid, 0), 0)), 0) AS total
             FROM trainee_fees f
             LEFT JOIN (
                    SELECT trainee_fee_id,
                           SUM(CASE WHEN entry_type = 'payment' THEN amount WHEN entry_type = 'reversal' THEN -amount ELSE 0 END) AS total_paid
                    FROM payments
                    GROUP BY trainee_fee_id
             ) paid ON paid.trainee_fee_id = f.trainee_fee_id
             WHERE 1 = 1 {$feeScope}",
            $feeValues
        );

        $pendingProofs = $this->row(
            "SELECT COUNT(*) AS proof_count, COALESCE(SUM(proof.amount), 0) AS proof_amount
             FROM payment_proofs proof
             JOIN trainee_fees f ON f.trainee_fee_id = proof.trainee_fee_id
             WHERE proof.status = 'pending_verification' {$feeScope}",
            $feeValues
        ) ?: [];

        $bondHeld = $this->row(
            "SELECT COALESCE(SUM(CASE WHEN b.transaction_type = 'deposit' THEN b.amount WHEN b.transaction_type IN ('refund', 'forfeit', 'reversal') THEN -b.amount ELSE 0 END), 0) AS total
             FROM bond_transactions b
             JOIN trainee_fees f ON f.trainee_fee_id = b.trainee_fee_id
             WHERE f.fee_kind = 'bond' {$feeScope}",
            $feeValues
        );

        $bondActions = $this->row(
            "SELECT
                COALESCE(SUM(CASE WHEN b.transaction_type = 'refund' THEN b.amount ELSE 0 END), 0) AS refunded,
                COALESCE(SUM(CASE WHEN b.transaction_type = 'forfeit' THEN b.amount ELSE 0 END), 0) AS forfeited
             FROM bond_transactions b
             JOIN trainee_fees f ON f.trainee_fee_id = b.trainee_fee_id
             WHERE b.transaction_date BETWEEN ? AND ?
               AND b.transaction_type IN ('refund', 'forfeit') {$feeScope}",
            array_merge([$filters['start_date'], $filters['end_date']], $feeValues)
        ) ?: [];

        return [
            'summary' => [
                'net_collected' => round((float) ($paymentTotals['net_collected'] ?? 0), 2),
                'gross_collected' => round((float) ($paymentTotals['gross_collected'] ?? 0), 2),
                'reversed_amount' => round((float) ($paymentTotals['reversed_amount'] ?? 0), 2),
                'outstanding_balance' => round((float) ($outstanding['total'] ?? 0), 2),
                'pending_proof_count' => (int) ($pendingProofs['proof_count'] ?? 0),
                'pending_proof_amount' => round((float) ($pendingProofs['proof_amount'] ?? 0), 2),
                'bond_held' => round((float) ($bondHeld['total'] ?? 0), 2),
                'refunded' => round((float) ($bondActions['refunded'] ?? 0), 2),
                'forfeited' => round((float) ($bondActions['forfeited'] ?? 0), 2),
            ],
            'collections' => $collections,
            'by_fee_kind' => $byFeeKind,
            'by_method' => $byMethod,
        ];
    }

    private function trainingAnalytics(array $filters)
    {
        $classWhere = '';
        $classValues = [];
        if (!empty($filters['class_id'])) {
            $classWhere .= ' AND c.class_id = ?';
            $classValues[] = (int) $filters['class_id'];
        }

        // Training enrollment statistics
        $enrollmentStats = $this->row(
            "SELECT
                COUNT(DISTINCT e.trainee_id) AS total_enrolled,
                COUNT(DISTINCT CASE WHEN t.status = 'active' THEN e.trainee_id END) AS active_trainees,
                COUNT(DISTINCT CASE WHEN t.status = 'inactive' THEN e.trainee_id END) AS inactive_trainees
             FROM class_enrollments e
             JOIN trainees t ON t.trainee_id = e.trainee_id
             JOIN training_classes c ON c.class_id = e.class_id
             WHERE 1 = 1 {$classWhere}",
            $classValues
        ) ?: [];

        // Training completion rates
        $completionStats = $this->row(
            "SELECT
                COUNT(DISTINCT cert.trainee_id) AS completed_trainings,
                COUNT(DISTINCT e.trainee_id) AS total_enrolled,
                COALESCE(COUNT(DISTINCT cert.trainee_id) / NULLIF(COUNT(DISTINCT e.trainee_id), 0) * 100, 0) AS completion_rate
             FROM class_enrollments e
             JOIN training_classes c ON c.class_id = e.class_id
             LEFT JOIN certificates cert ON cert.trainee_id = e.trainee_id AND cert.class_id = e.class_id AND cert.status = 'issued'
             WHERE 1 = 1 {$classWhere}",
            $classValues
        ) ?: [];

        // Trainees per training batch
        $traineesPerBatch = $this->rows(
            "SELECT c.class_id, c.class_name, p.program_name,
                    COUNT(DISTINCT e.trainee_id) AS trainee_count,
                    c.start_date, c.end_date
             FROM training_classes c
             JOIN training_programs p ON p.program_id = c.program_id
             LEFT JOIN class_enrollments e ON e.class_id = c.class_id
             WHERE 1 = 1 {$classWhere}
             GROUP BY c.class_id, c.class_name, p.program_name, c.start_date, c.end_date
             ORDER BY c.start_date DESC",
            $classValues
        );

        // Monthly registration trends
        $monthlyTrends = $this->rows(
            "SELECT DATE_FORMAT(e.enrolled_at, '%Y-%m') AS month,
                    COUNT(DISTINCT e.trainee_id) AS new_registrations
             FROM class_enrollments e
             JOIN training_classes c ON c.class_id = e.class_id
             WHERE e.enrolled_at BETWEEN ? AND ? {$classWhere}
             GROUP BY DATE_FORMAT(e.enrolled_at, '%Y-%m')
             ORDER BY month",
            array_merge([$filters['start_date'], $filters['end_date']], $classValues)
        );

        // Training program popularity
        $programPopularity = $this->rows(
            "SELECT p.program_id, p.program_name, p.program_code,
                    COUNT(DISTINCT e.trainee_id) AS trainee_count,
                    COUNT(DISTINCT c.class_id) AS class_count
             FROM training_programs p
             LEFT JOIN training_classes c ON c.program_id = p.program_id
             LEFT JOIN class_enrollments e ON e.class_id = c.class_id
             WHERE 1 = 1 {$classWhere}
             GROUP BY p.program_id, p.program_name, p.program_code
             ORDER BY trainee_count DESC",
            $classValues
        );

        foreach ($traineesPerBatch as &$batch) {
            $batch['class_id'] = (int) $batch['class_id'];
            $batch['trainee_count'] = (int) $batch['trainee_count'];
        }
        unset($batch);

        foreach ($monthlyTrends as &$trend) {
            $trend['new_registrations'] = (int) $trend['new_registrations'];
        }
        unset($trend);

        foreach ($programPopularity as &$program) {
            $program['program_id'] = (int) $program['program_id'];
            $program['trainee_count'] = (int) $program['trainee_count'];
            $program['class_count'] = (int) $program['class_count'];
        }
        unset($program);

        return [
            'summary' => [
                'total_enrolled' => (int) ($enrollmentStats['total_enrolled'] ?? 0),
                'active_trainees' => (int) ($enrollmentStats['active_trainees'] ?? 0),
                'inactive_trainees' => (int) ($enrollmentStats['inactive_trainees'] ?? 0),
                'completed_trainings' => (int) ($completionStats['completed_trainings'] ?? 0),
                'completion_rate' => round((float) ($completionStats['completion_rate'] ?? 0), 1),
            ],
            'trainees_per_batch' => $traineesPerBatch,
            'monthly_trends' => $monthlyTrends,
            'program_popularity' => $programPopularity,
        ];
    }

    private function documentAnalytics(array $filters)
    {
        $classWhere = '';
        $classValues = [];
        if (!empty($filters['class_id'])) {
            $classWhere .= ' AND d.class_id = ?';
            $classValues[] = (int) $filters['class_id'];
        }

        $dateWhere = ' AND d.upload_date BETWEEN ? AND ?';
        $dateValues = [$filters['start_date'], $filters['end_date']];

        // Document statistics
        $docStats = $this->row(
            "SELECT
                COUNT(*) AS total_submitted,
                SUM(CASE WHEN d.review_status = 'approved' THEN 1 ELSE 0 END) AS verified,
                SUM(CASE WHEN d.review_status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                SUM(CASE WHEN d.review_status = 'pending' THEN 1 ELSE 0 END) AS pending
             FROM trainee_documents d
             WHERE 1 = 1 {$classWhere} {$dateWhere}",
            array_merge($classValues, $dateValues)
        ) ?: [];

        // Missing documents based on requirements
        $missingDocs = $this->row(
            "SELECT COUNT(*) AS missing_count
             FROM certificate_document_requirements cdr
             LEFT JOIN trainee_documents d ON d.class_id = cdr.class_id
                 AND d.document_name = cdr.required_document_name
                 AND d.review_status = 'approved'
             WHERE 1 = 1 {$classWhere}
             AND d.document_id IS NULL",
            $classValues
        ) ?: [];

        // Document completion rates per class
        $completionByClass = $this->rows(
            "SELECT c.class_id, c.class_name,
                    COUNT(DISTINCT cdr.requirement_id) AS required_count,
                    COUNT(DISTINCT d.document_id) AS submitted_count,
                    COALESCE(COUNT(DISTINCT d.document_id) / NULLIF(COUNT(DISTINCT cdr.requirement_id), 0) * 100, 0) AS completion_rate
             FROM training_classes c
             LEFT JOIN certificate_document_requirements cdr ON cdr.class_id = c.class_id
             LEFT JOIN trainee_documents d ON d.class_id = c.class_id
                 AND d.document_name = cdr.required_document_name
                 AND d.review_status = 'approved'
             WHERE 1 = 1 {$classWhere}
             GROUP BY c.class_id, c.class_name
             ORDER BY completion_rate DESC",
            $classValues
        );

        // Document types distribution
        $docTypes = $this->rows(
            "SELECT d.document_name,
                    COUNT(*) AS count,
                    SUM(CASE WHEN d.review_status = 'approved' THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN d.review_status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                    SUM(CASE WHEN d.review_status = 'pending' THEN 1 ELSE 0 END) AS pending
             FROM trainee_documents d
             WHERE 1 = 1 {$classWhere} {$dateWhere}
             GROUP BY d.document_name
             ORDER BY count DESC",
            array_merge($classValues, $dateValues)
        );

        foreach ($completionByClass as &$completion) {
            $completion['class_id'] = (int) $completion['class_id'];
            $completion['required_count'] = (int) $completion['required_count'];
            $completion['submitted_count'] = (int) $completion['submitted_count'];
            $completion['completion_rate'] = round((float) $completion['completion_rate'], 1);
        }
        unset($completion);

        foreach ($docTypes as &$type) {
            $type['count'] = (int) $type['count'];
            $type['approved'] = (int) $type['approved'];
            $type['rejected'] = (int) $type['rejected'];
            $type['pending'] = (int) $type['pending'];
        }
        unset($type);

        return [
            'summary' => [
                'total_submitted' => (int) ($docStats['total_submitted'] ?? 0),
                'verified' => (int) ($docStats['verified'] ?? 0),
                'rejected' => (int) ($docStats['rejected'] ?? 0),
                'pending' => (int) ($docStats['pending'] ?? 0),
                'missing' => (int) ($missingDocs['missing_count'] ?? 0),
                'completion_rate' => (int) ($docStats['total_submitted'] ?? 0) > 0
                    ? round(((int) ($docStats['verified'] ?? 0) / (int) ($docStats['total_submitted'] ?? 1)) * 100, 1)
                    : 0,
            ],
            'completion_by_class' => $completionByClass,
            'document_types' => $docTypes,
        ];
    }

    private function certificateAnalytics(array $filters)
    {
        $classWhere = '';
        $classValues = [];
        if (!empty($filters['class_id'])) {
            $classWhere .= ' AND cert.class_id = ?';
            $classValues[] = (int) $filters['class_id'];
        }

        $dateWhere = ' AND cert.issue_date BETWEEN ? AND ?';
        $dateValues = [$filters['start_date'], $filters['end_date']];

        // Certificate statistics
        $certStats = $this->row(
            "SELECT
                COUNT(*) AS total_issued,
                SUM(CASE WHEN cert.status = 'issued' THEN 1 ELSE 0 END) AS issued,
                SUM(CASE WHEN cert.status = 'draft' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN cert.status = 'revoked' THEN 1 ELSE 0 END) AS revoked
             FROM certificates cert
             WHERE 1 = 1 {$classWhere} {$dateWhere}",
            array_merge($classValues, $dateValues)
        ) ?: [];

        // Certificates per training batch
        $certsPerBatch = $this->rows(
            "SELECT c.class_id, c.class_name, p.program_name,
                    COUNT(DISTINCT cert.certificate_id) AS certificate_count,
                    COUNT(DISTINCT cert.trainee_id) AS trainee_count
             FROM training_classes c
             JOIN training_programs p ON p.program_id = c.program_id
             LEFT JOIN certificates cert ON cert.class_id = c.class_id {$dateWhere}
             WHERE 1 = 1 {$classWhere}
             GROUP BY c.class_id, c.class_name, p.program_name
             ORDER BY certificate_count DESC",
            array_merge($classValues, $dateValues)
        );

        // Monthly certificate issuance
        $monthlyIssuance = $this->rows(
            "SELECT DATE_FORMAT(cert.issue_date, '%Y-%m') AS month,
                    COUNT(*) AS certificates_issued
             FROM certificates cert
             WHERE cert.issue_date BETWEEN ? AND ? {$classWhere}
             GROUP BY DATE_FORMAT(cert.issue_date, '%Y-%m')
             ORDER BY month",
            array_merge([$filters['start_date'], $filters['end_date']], $classValues)
        );

        // Certificate eligibility statistics
        $eligibilityStats = $this->row(
            "SELECT
                COUNT(*) AS total_eligible,
                SUM(CASE WHEN e.is_eligible = 1 THEN 1 ELSE 0 END) AS eligible,
                SUM(CASE WHEN e.is_eligible = 0 THEN 1 ELSE 0 END) AS not_eligible,
                AVG(e.attendance_percentage) AS avg_attendance
             FROM certificate_eligibility e
             JOIN training_classes c ON c.class_id = e.class_id
             WHERE 1 = 1 {$classWhere}",
            $classValues
        ) ?: [];

        foreach ($certsPerBatch as &$batch) {
            $batch['class_id'] = (int) $batch['class_id'];
            $batch['certificate_count'] = (int) $batch['certificate_count'];
            $batch['trainee_count'] = (int) $batch['trainee_count'];
        }
        unset($batch);

        foreach ($monthlyIssuance as &$monthly) {
            $monthly['certificates_issued'] = (int) $monthly['certificates_issued'];
        }
        unset($monthly);

        return [
            'summary' => [
                'total_issued' => (int) ($certStats['total_issued'] ?? 0),
                'issued' => (int) ($certStats['issued'] ?? 0),
                'pending' => (int) ($certStats['pending'] ?? 0),
                'revoked' => (int) ($certStats['revoked'] ?? 0),
            ],
            'certificates_per_batch' => $certsPerBatch,
            'monthly_issuance' => $monthlyIssuance,
            'eligibility' => [
                'total_eligible' => (int) ($eligibilityStats['total_eligible'] ?? 0),
                'eligible' => (int) ($eligibilityStats['eligible'] ?? 0),
                'not_eligible' => (int) ($eligibilityStats['not_eligible'] ?? 0),
                'avg_attendance' => round((float) ($eligibilityStats['avg_attendance'] ?? 0), 1),
            ],
        ];
    }

    private function announcementAnalytics(array $filters)
    {
        $dateWhere = ' AND created_at BETWEEN ? AND ?';
        $dateValues = [$filters['start_date'], $filters['end_date']];

        // Announcement statistics
        $announcementStats = $this->row(
            "SELECT
                COUNT(*) AS total_published,
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft
             FROM announcements
             WHERE 1 = 1 {$dateWhere}",
            $dateValues
        ) ?: [];

        // Notification statistics (from notifications table)
        $notificationStats = $this->row(
            "SELECT
                COUNT(*) AS total_notifications,
                SUM(CASE WHEN notification_type = 'email' THEN 1 ELSE 0 END) AS email_notifications,
                SUM(CASE WHEN notification_type = 'sms' THEN 1 ELSE 0 END) AS sms_notifications,
                SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) AS read_notifications,
                SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_notifications
             FROM notifications
             WHERE created_at BETWEEN ? AND ?",
            $dateValues
        ) ?: [];

        // Failed notifications (simulated - add actual tracking if needed)
        $failedNotifications = $this->row(
            "SELECT COUNT(*) AS failed_count
             FROM notifications
             WHERE created_at BETWEEN ? AND ?
             AND (notification_type = 'email' OR notification_type = 'sms')
             AND is_read = 0
             AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            $dateValues
        ) ?: [];

        // Announcement read statistics (if tracking exists)
        $readStats = $this->rows(
            "SELECT a.announcement_id, a.title, a.created_at,
                    COUNT(DISTINCT n.user_id) AS unique_readers
             FROM announcements a
             LEFT JOIN notifications n ON n.notification_type = 'announcement'
                 AND n.message LIKE CONCAT('%', a.title, '%')
                 AND n.is_read = 1
             WHERE a.created_at BETWEEN ? AND ?
             GROUP BY a.announcement_id, a.title, a.created_at
             ORDER BY a.created_at DESC",
            $dateValues
        );

        foreach ($readStats as &$stat) {
            $stat['announcement_id'] = (int) $stat['announcement_id'];
            $stat['unique_readers'] = (int) $stat['unique_readers'];
        }
        unset($stat);

        return [
            'summary' => [
                'total_published' => (int) ($announcementStats['total_published'] ?? 0),
                'published' => (int) ($announcementStats['published'] ?? 0),
                'draft' => (int) ($announcementStats['draft'] ?? 0),
                'email_notifications' => (int) ($notificationStats['email_notifications'] ?? 0),
                'sms_notifications' => (int) ($notificationStats['sms_notifications'] ?? 0),
                'read_notifications' => (int) ($notificationStats['read_notifications'] ?? 0),
                'unread_notifications' => (int) ($notificationStats['unread_notifications'] ?? 0),
                'failed_notifications' => (int) ($failedNotifications['failed_count'] ?? 0),
            ],
            'read_statistics' => $readStats,
        ];
    }
}
