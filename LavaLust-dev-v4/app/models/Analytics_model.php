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
        }
        return $result;
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
}
