<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';

class Analytics_model extends Base_model
{
    public function classOptions(array $user)
    {
        $query = $this->db->table('training_classes')
            ->select('class_id, class_name');
        if (($user['role'] ?? '') === 'instructor') {
            $query->where('instructor_id', (int) $user['user_id']);
        }
        return $query->order_by('class_name')->result_array();
    }

    public function classIsVisible($classId, array $user)
    {
        $query = $this->db->table('training_classes')
            ->select('class_id')
            ->where('class_id', (int) $classId);
        if (($user['role'] ?? '') === 'instructor') {
            $query->where('instructor_id', (int) $user['user_id']);
        }
        return (bool) $query->limit(1)->row_array();
    }

    public function analytics(array $user, array $filters)
    {
        $classFilters = [];
        if (!empty($filters['class_id'])) {
            $classFilters['class_id'] = (int) $filters['class_id'];
        }
        if (($user['role'] ?? '') === 'instructor') {
            $classFilters['instructor_id'] = (int) $user['user_id'];
        }

        $applyClassFilters = static function ($query) use ($classFilters) {
            foreach ($classFilters as $column => $value) {
                $query->where('c.' . $column, $value);
            }
            return $query;
        };

        $activeTraineeQuery = $this->db->table('class_enrollments e')
            ->select('COUNT(DISTINCT e.trainee_id) AS active_trainees')
            ->join('trainees t', "t.trainee_id = e.trainee_id AND t.status = 'active'")
            ->join('training_classes c', 'c.class_id = e.class_id');
        $applyClassFilters($activeTraineeQuery);
        $activeTrainees = (int) ($activeTraineeQuery->row_array()['active_trainees'] ?? 0);

        $activeClassQuery = $this->db->table('training_classes c')
            ->select('COUNT(DISTINCT c.class_id) AS active_classes')
            ->where('c.status', 'active');
        $applyClassFilters($activeClassQuery);
        $activeClasses = (int) ($activeClassQuery->row_array()['active_classes'] ?? 0);

        $attendanceQuery = $this->db->table('attendance_records a')
            ->select("COUNT(a.attendance_id) AS attendance_records, SUM(CASE WHEN a.status = 'Present' OR a.status = 'Late' THEN 1 ELSE 0 END) AS attended_records, SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) AS absent_records")
            ->join('training_classes c', 'c.class_id = a.class_id')
            ->between('a.attendance_date', $filters['start_date'], $filters['end_date']);
        if (!empty($filters['class_id'])) {
            $attendanceQuery->where('a.class_id', (int) $filters['class_id']);
        }
        if (($user['role'] ?? '') === 'instructor') {
            $attendanceQuery->where('c.instructor_id', (int) $user['user_id']);
        }
        $attendanceSummary = $attendanceQuery->row_array() ?: [];
        $totalRecords = (int) ($attendanceSummary['attendance_records'] ?? 0);
        $summary = [
            'active_trainees' => $activeTrainees,
            'active_classes' => $activeClasses,
            'attendance_records' => $totalRecords,
            'absent_records' => (int) ($attendanceSummary['absent_records'] ?? 0),
            'attendance_rate' => $totalRecords > 0
                ? round(((int) ($attendanceSummary['attended_records'] ?? 0) / $totalRecords) * 100, 1)
                : 0,
        ];

        $trendQuery = $this->db->table('attendance_records a')
            ->select("a.attendance_date, COUNT(*) AS total, SUM(a.status = 'Present') AS present, SUM(a.status = 'Late') AS late, SUM(a.status = 'Absent') AS absent")
            ->join('training_classes c', 'c.class_id = a.class_id')
            ->between('a.attendance_date', $filters['start_date'], $filters['end_date']);
        if (!empty($filters['class_id'])) {
            $trendQuery->where('a.class_id', (int) $filters['class_id']);
        }
        if (($user['role'] ?? '') === 'instructor') {
            $trendQuery->where('c.instructor_id', (int) $user['user_id']);
        }
        $trendRows = $trendQuery->group_by('a.attendance_date')
            ->order_by('a.attendance_date')
            ->result_array();
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

        $statusQuery = $this->db->table('attendance_records a')
            ->select('a.status, COUNT(*) AS total')
            ->join('training_classes c', 'c.class_id = a.class_id')
            ->between('a.attendance_date', $filters['start_date'], $filters['end_date']);
        if (!empty($filters['class_id'])) {
            $statusQuery->where('a.class_id', (int) $filters['class_id']);
        }
        if (($user['role'] ?? '') === 'instructor') {
            $statusQuery->where('c.instructor_id', (int) $user['user_id']);
        }
        $statusRows = $statusQuery->group_by('a.status')
            ->order_by('a.status')
            ->result_array();
        $status = ['Present' => 0, 'Late' => 0, 'Absent' => 0];
        foreach ($statusRows as $row) {
            $status[$row['status']] = (int) $row['total'];
        }

        $classQuery = $this->db->table('training_classes c')
            ->select('c.class_id, c.class_name, p.program_name')
            ->join('training_programs p', 'p.program_id = c.program_id');
        if (!empty($filters['class_id'])) {
            $classQuery->where('c.class_id', (int) $filters['class_id']);
        }
        if (($user['role'] ?? '') === 'instructor') {
            $classQuery->where('c.instructor_id', (int) $user['user_id']);
        }
        $classes = $classQuery->order_by('c.class_name')->result_array();

        $enrollmentStats = $this->db->table('class_enrollments e')
            ->select('e.class_id, COUNT(DISTINCT t.trainee_id) AS active_trainees')
            ->join('trainees t', "t.trainee_id = e.trainee_id AND t.status = 'active'")
            ->group_by('e.class_id')
            ->result_array();
        $enrollmentByClass = [];
        foreach ($enrollmentStats as $stats) {
            $enrollmentByClass[(int) $stats['class_id']] = (int) $stats['active_trainees'];
        }

        $attendanceStats = $this->db->table('attendance_records a')
            ->select("a.class_id, COUNT(*) AS attendance_records, SUM(a.status = 'Present' OR a.status = 'Late') AS attended_records, SUM(a.status = 'Absent') AS absent_records")
            ->between('a.attendance_date', $filters['start_date'], $filters['end_date'])
            ->group_by('a.class_id')
            ->result_array();
        $attendanceByClass = [];
        foreach ($attendanceStats as $stats) {
            $attendanceByClass[(int) $stats['class_id']] = $stats;
        }
        foreach ($classes as $index => &$class) {
            $classId = (int) $class['class_id'];
            $enrollmentCount = $enrollmentByClass[$classId] ?? 0;
            $attendance = $attendanceByClass[$classId] ?? [];
            $classTotal = (int) ($attendance['attendance_records'] ?? 0);
            $attended = (int) ($attendance['attended_records'] ?? 0);
            $class['class_id'] = $classId;
            $class['active_trainees'] = $enrollmentCount;
            $class['attendance_records'] = $classTotal;
            $class['absent_records'] = (int) ($attendance['absent_records'] ?? 0);
            $class['attendance_rate'] = $classTotal > 0
                ? round(($attended / $classTotal) * 100, 1)
                : 0;
            $class['_sort_order'] = $index;
        }
        unset($class);
        usort($classes, static function ($left, $right) {
            $byAbsent = $right['absent_records'] <=> $left['absent_records'];
            return $byAbsent !== 0 ? $byAbsent : $left['_sort_order'] <=> $right['_sort_order'];
        });
        foreach ($classes as &$class) {
            unset($class['_sort_order']);
        }
        unset($class);

        $traineeQuery = $this->db->table('attendance_records a')
            ->select("t.trainee_id, t.first_name, t.last_name, COUNT(a.attendance_id) AS attendance_records, SUM(a.status = 'Present') AS present_records, SUM(a.status = 'Late') AS late_records, SUM(a.status = 'Absent') AS absent_records, MAX(a.attendance_date) AS last_attendance")
            ->join('trainees t', 't.trainee_id = a.trainee_id')
            ->join('training_classes c', 'c.class_id = a.class_id')
            ->between('a.attendance_date', $filters['start_date'], $filters['end_date']);
        if (!empty($filters['class_id'])) {
            $traineeQuery->where('a.class_id', (int) $filters['class_id']);
        }
        if (($user['role'] ?? '') === 'instructor') {
            $traineeQuery->where('c.instructor_id', (int) $user['user_id']);
        }
        $trainees = $traineeQuery
            ->group_by(['t.trainee_id', 't.first_name', 't.last_name'])
            ->result_array();

        $classNameWhere = 'a.attendance_date BETWEEN ? AND ?';
        $classNameValues = [$filters['start_date'], $filters['end_date']];
        if (!empty($filters['class_id'])) {
            $classNameWhere .= ' AND a.class_id = ?';
            $classNameValues[] = (int) $filters['class_id'];
        }
        if (($user['role'] ?? '') === 'instructor') {
            $classNameWhere .= ' AND a.class_id IN (SELECT class_id FROM training_classes WHERE instructor_id = ?)';
            $classNameValues[] = (int) $user['user_id'];
        }
        $classNames = $this->rows(
            "SELECT t.trainee_id,
                    GROUP_CONCAT(DISTINCT c.class_name ORDER BY c.class_name SEPARATOR ', ') AS classes
             FROM attendance_records a
             JOIN trainees t ON t.trainee_id = a.trainee_id
             JOIN training_classes c ON c.class_id = a.class_id
             WHERE {$classNameWhere}
             GROUP BY t.trainee_id",
            $classNameValues
        );
        $classesByTrainee = [];
        foreach ($classNames as $row) {
            $classesByTrainee[(int) $row['trainee_id']] = $row['classes'];
        }
        foreach ($trainees as &$trainee) {
            $count = (int) $trainee['attendance_records'];
            $trainee['trainee_id'] = (int) $trainee['trainee_id'];
            $trainee['trainee_name'] = $trainee['first_name'] === null || $trainee['last_name'] === null
                ? null
                : $trainee['first_name'] . ' ' . $trainee['last_name'];
            $trainee['classes'] = $classesByTrainee[$trainee['trainee_id']] ?? null;
            $trainee['attendance_records'] = $count;
            $trainee['present_records'] = (int) $trainee['present_records'];
            $trainee['late_records'] = (int) $trainee['late_records'];
            $trainee['absent_records'] = (int) $trainee['absent_records'];
            $trainee['attendance_rate'] = $count > 0
                ? round((($trainee['present_records'] + $trainee['late_records']) / $count) * 100, 1)
                : 0;
        }
        unset($trainee);
        $traineeSortRanks = [
            'last_name' => $this->columnOrderRanks('trainees', 'last_name'),
            'first_name' => $this->columnOrderRanks('trainees', 'first_name'),
        ];
        foreach ($trainees as $index => &$trainee) {
            $trainee['_sort_order'] = $index;
        }
        unset($trainee);
        usort($trainees, static function ($left, $right) use ($traineeSortRanks) {
            foreach (['absent_records', 'late_records'] as $field) {
                if ($left[$field] !== $right[$field]) {
                    return $right[$field] <=> $left[$field];
                }
            }
            foreach (['last_name', 'first_name'] as $field) {
                $leftRank = $traineeSortRanks[$field][serialize($left[$field])] ?? PHP_INT_MAX;
                $rightRank = $traineeSortRanks[$field][serialize($right[$field])] ?? PHP_INT_MAX;
                if ($leftRank !== $rightRank) {
                    return $leftRank <=> $rightRank;
                }
            }
            return $left['_sort_order'] <=> $right['_sort_order'];
        });
        $trainees = array_slice($trainees, 0, 100);
        foreach ($trainees as &$trainee) {
            $trainee = [
                'trainee_id' => $trainee['trainee_id'],
                'trainee_name' => $trainee['trainee_name'],
                'classes' => $trainee['classes'],
                'attendance_records' => $trainee['attendance_records'],
                'present_records' => $trainee['present_records'],
                'late_records' => $trainee['late_records'],
                'absent_records' => $trainee['absent_records'],
                'last_attendance' => $trainee['last_attendance'],
                'attendance_rate' => $trainee['attendance_rate'],
            ];
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
        $summaryQuery = $this->db->table('training_assessments a')
            ->select('a.outcome, COUNT(*) AS total')
            ->join('training_classes c', 'c.class_id = a.class_id')
            ->between('a.assessment_date', $filters['start_date'], $filters['end_date']);
        if (!empty($filters['class_id'])) {
            $summaryQuery->where('a.class_id', (int) $filters['class_id']);
        }

        $summaryRows = $summaryQuery->group_by('a.outcome')->result_array();
        $summary = ['total' => 0, 'passed' => 0, 'failed' => 0];
        foreach ($summaryRows as $row) {
            $outcome = strtolower((string) ($row['outcome'] ?? ''));
            $count = (int) $row['total'];
            $summary['total'] += $count;
            if ($outcome === 'pass') {
                $summary['passed'] += $count;
            } elseif ($outcome === 'fail') {
                $summary['failed'] += $count;
            }
        }

        $programQuery = $this->db->table('training_assessments a')
            ->select('p.program_id, p.program_name, a.outcome, COUNT(*) AS total')
            ->join('training_classes c', 'c.class_id = a.class_id')
            ->join('training_programs p', 'p.program_id = c.program_id')
            ->between('a.assessment_date', $filters['start_date'], $filters['end_date']);
        if (!empty($filters['class_id'])) {
            $programQuery->where('a.class_id', (int) $filters['class_id']);
        }
        $groupedRows = $programQuery->group_by(['p.program_id', 'p.program_name', 'a.outcome'])
            ->order_by('p.program_name')
            ->result_array();
        $programs = [];
        foreach ($groupedRows as $row) {
            $outcome = strtolower((string) ($row['outcome'] ?? ''));
            $count = (int) $row['total'];

            $programId = (int) $row['program_id'];
            if (!isset($programs[$programId])) {
                $programs[$programId] = [
                    'program_id' => $programId,
                    'program_name' => $row['program_name'],
                    'total' => 0,
                    'passed' => 0,
                    'failed' => 0,
                ];
            }
            $programs[$programId]['total'] += $count;
            if ($outcome === 'pass') {
                $programs[$programId]['passed'] += $count;
            } elseif ($outcome === 'fail') {
                $programs[$programId]['failed'] += $count;
            }
        }
        $byProgram = array_values($programs);
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
        $applyFeeClass = static function ($query, $alias = 'f') use ($filters) {
            if (!empty($filters['class_id'])) {
                $query->where($alias . '.class_id', (int) $filters['class_id']);
            }
            return $query;
        };
        $buildPaymentQuery = function ($select) use ($filters, $applyFeeClass) {
            $query = $this->db->table('payments p')
                ->select($select)
                ->join('trainee_fees f', 'f.trainee_fee_id = p.trainee_fee_id')
                ->between('p.payment_date', $filters['start_date'], $filters['end_date']);
            return $applyFeeClass($query);
        };

        $paymentTotals = ['net_collected' => 0, 'gross_collected' => 0, 'reversed_amount' => 0];
        $paymentGroups = $buildPaymentQuery('p.entry_type, SUM(p.amount) AS amount')
            ->group_by('p.entry_type')
            ->result_array();
        foreach ($paymentGroups as $group) {
            $amount = (float) $group['amount'];
            if ($group['entry_type'] === 'payment') {
                $paymentTotals['net_collected'] += $amount;
                $paymentTotals['gross_collected'] += $amount;
            } elseif ($group['entry_type'] === 'reversal') {
                $paymentTotals['net_collected'] -= $amount;
                $paymentTotals['reversed_amount'] += $amount;
            }
        }

        $trendRows = $buildPaymentQuery('p.payment_date, p.entry_type, SUM(p.amount) AS amount')
            ->group_by(['p.payment_date', 'p.entry_type'])
            ->order_by('p.payment_date')
            ->result_array();
        $collectionByDate = [];
        foreach ($trendRows as $row) {
            $amount = (float) $row['amount'];
            if ($row['entry_type'] === 'payment') {
                $collectionByDate[$row['payment_date']] = ($collectionByDate[$row['payment_date']] ?? 0) + $amount;
            } elseif ($row['entry_type'] === 'reversal') {
                $collectionByDate[$row['payment_date']] = ($collectionByDate[$row['payment_date']] ?? 0) - $amount;
            }
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

        $feeGroups = $buildPaymentQuery('f.fee_kind, p.entry_type, SUM(p.amount) AS amount')
            ->group_by(['f.fee_kind', 'p.entry_type'])
            ->order_by('f.fee_kind')
            ->result_array();
        $byFeeKind = [];
        foreach ($feeGroups as $group) {
            $key = serialize($group['fee_kind']);
            if (!isset($byFeeKind[$key])) {
                $byFeeKind[$key] = ['fee_kind' => $group['fee_kind'], 'net_collected' => 0.0];
            }
            if ($group['entry_type'] === 'payment') {
                $byFeeKind[$key]['net_collected'] += (float) $group['amount'];
            } elseif ($group['entry_type'] === 'reversal') {
                $byFeeKind[$key]['net_collected'] -= (float) $group['amount'];
            }
        }
        $byFeeKind = array_values($byFeeKind);
        foreach ($byFeeKind as &$row) {
            $row['net_collected'] = round((float) $row['net_collected'], 2);
        }
        unset($row);

        $methodGroups = $buildPaymentQuery('p.method, p.entry_type, SUM(p.amount) AS amount, COUNT(*) AS entry_count')
            ->group_by(['p.method', 'p.entry_type'])
            ->result_array();
        $byMethod = [];
        foreach ($methodGroups as $group) {
            $key = serialize($group['method']);
            if (!isset($byMethod[$key])) {
                $byMethod[$key] = [
                    'method' => $group['method'] ?? 'unspecified',
                    'net_collected_cents' => 0,
                    'payment_count' => 0,
                ];
            }
            if ($group['entry_type'] === 'payment') {
                $byMethod[$key]['net_collected_cents'] += (int) round((float) $group['amount'] * 100);
                $byMethod[$key]['payment_count'] += (int) $group['entry_count'];
            } elseif ($group['entry_type'] === 'reversal') {
                $byMethod[$key]['net_collected_cents'] -= (int) round((float) $group['amount'] * 100);
            }
        }
        $byMethod = array_values($byMethod);
        usort($byMethod, static function ($left, $right) {
            return $right['net_collected_cents'] <=> $left['net_collected_cents'];
        });
        foreach ($byMethod as &$row) {
            $row = [
                'method' => $row['method'],
                'net_collected' => $row['net_collected_cents'] / 100,
                'payment_count' => (int) $row['payment_count'],
            ];
        }
        unset($row);

        $outstandingQuery = $this->db->table('trainee_fees f')
            ->select("f.trainee_fee_id, f.amount_due, SUM(CASE WHEN p.entry_type = 'payment' THEN p.amount WHEN p.entry_type = 'reversal' THEN -p.amount ELSE 0 END) AS total_paid")
            ->left_join('payments p', 'p.trainee_fee_id = f.trainee_fee_id');
        $applyFeeClass($outstandingQuery);
        $outstandingTotal = 0.0;
        foreach ($outstandingQuery->group_by(['f.trainee_fee_id', 'f.amount_due'])->result_array() as $fee) {
            $outstandingTotal += max((float) $fee['amount_due'] - (float) ($fee['total_paid'] ?? 0), 0);
        }
        $outstanding = ['total' => $outstandingTotal];

        $proofQuery = $this->db->table('payment_proofs proof')
            ->select('COUNT(*) AS proof_count, SUM(proof.amount) AS proof_amount')
            ->join('trainee_fees f', 'f.trainee_fee_id = proof.trainee_fee_id')
            ->where('proof.status', 'pending_verification');
        $applyFeeClass($proofQuery);
        $pendingProofs = $proofQuery->row_array() ?: [];

        $bondQuery = $this->db->table('bond_transactions b')
            ->select('b.transaction_type, SUM(b.amount) AS amount')
            ->join('trainee_fees f', 'f.trainee_fee_id = b.trainee_fee_id')
            ->where('f.fee_kind', 'bond');
        $applyFeeClass($bondQuery);
        $bondHeldTotal = 0.0;
        foreach ($bondQuery->group_by('b.transaction_type')->result_array() as $row) {
            if ($row['transaction_type'] === 'deposit') {
                $bondHeldTotal += (float) $row['amount'];
            } elseif (in_array($row['transaction_type'], ['refund', 'forfeit', 'reversal'], true)) {
                $bondHeldTotal -= (float) $row['amount'];
            }
        }
        $bondHeld = ['total' => $bondHeldTotal];

        $bondActionsQuery = $this->db->table('bond_transactions b')
            ->select('b.transaction_type, SUM(b.amount) AS amount')
            ->join('trainee_fees f', 'f.trainee_fee_id = b.trainee_fee_id')
            ->between('b.transaction_date', $filters['start_date'], $filters['end_date'])
            ->in('b.transaction_type', ['refund', 'forfeit']);
        $applyFeeClass($bondActionsQuery);
        $bondActions = ['refunded' => 0.0, 'forfeited' => 0.0];
        foreach ($bondActionsQuery->group_by('b.transaction_type')->result_array() as $row) {
            if ($row['transaction_type'] === 'refund') {
                $bondActions['refunded'] = (float) $row['amount'];
            } else {
                $bondActions['forfeited'] = (float) $row['amount'];
            }
        }

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
        $enrollmentQuery = $this->db->table('class_enrollments e')
            ->select("COUNT(DISTINCT e.trainee_id) AS total_enrolled, COUNT(DISTINCT CASE WHEN t.status = 'active' THEN e.trainee_id END) AS active_trainees, COUNT(DISTINCT CASE WHEN t.status = 'inactive' THEN e.trainee_id END) AS inactive_trainees")
            ->join('trainees t', 't.trainee_id = e.trainee_id')
            ->join('training_classes c', 'c.class_id = e.class_id');
        if (!empty($filters['class_id'])) {
            $enrollmentQuery->where('c.class_id', (int) $filters['class_id']);
        }
        $enrollmentStats = $enrollmentQuery->row_array() ?: [];

        $completionQuery = $this->db->table('class_enrollments e')
            ->select('COUNT(DISTINCT cert.trainee_id) AS completed_trainings, COUNT(DISTINCT e.trainee_id) AS total_enrolled')
            ->join('training_classes c', 'c.class_id = e.class_id')
            ->left_join('certificates cert', "cert.trainee_id = e.trainee_id AND cert.class_id = e.class_id AND cert.status = 'issued'");
        if (!empty($filters['class_id'])) {
            $completionQuery->where('c.class_id', (int) $filters['class_id']);
        }
        $completionStats = $completionQuery->row_array() ?: [];
        $completionTotal = (int) ($completionStats['total_enrolled'] ?? 0);
        $completionRate = $completionTotal > 0
            ? ((int) ($completionStats['completed_trainings'] ?? 0) / $completionTotal) * 100
            : 0;

        $batchQuery = $this->db->table('training_classes c')
            ->select('c.class_id, c.class_name, p.program_name, COUNT(DISTINCT e.trainee_id) AS trainee_count, c.start_date, c.end_date')
            ->join('training_programs p', 'p.program_id = c.program_id')
            ->left_join('class_enrollments e', 'e.class_id = c.class_id');
        if (!empty($filters['class_id'])) {
            $batchQuery->where('c.class_id', (int) $filters['class_id']);
        }
        $traineesPerBatch = $batchQuery
            ->group_by(['c.class_id', 'c.class_name', 'p.program_name', 'c.start_date', 'c.end_date'])
            ->order_by('c.start_date', 'DESC')
            ->result_array();

        // Monthly registration trends
        $monthlyQuery = $this->db->table('class_enrollments e')
            ->select('YEAR(e.enrolled_at) AS year, MONTH(e.enrolled_at) AS month_number, COUNT(DISTINCT e.trainee_id) AS new_registrations')
            ->join('training_classes c', 'c.class_id = e.class_id')
            ->between('e.enrolled_at', $filters['start_date'], $filters['end_date']);
        if (!empty($filters['class_id'])) {
            $monthlyQuery->where('c.class_id', (int) $filters['class_id']);
        }
        $monthlyTrends = $monthlyQuery
            ->group_by(['YEAR(e.enrolled_at)', 'MONTH(e.enrolled_at)'])
            ->result_array();
        foreach ($monthlyTrends as &$trend) {
            $trend = [
                'month' => sprintf('%04d-%02d', (int) $trend['year'], (int) $trend['month_number']),
                'new_registrations' => $trend['new_registrations'],
            ];
        }
        unset($trend);
        usort($monthlyTrends, static function ($left, $right) {
            return $left['month'] <=> $right['month'];
        });

        // Training program popularity
        $popularityQuery = $this->db->table('training_programs p')
            ->select('p.program_id, p.program_name, p.program_code, COUNT(DISTINCT e.trainee_id) AS trainee_count, COUNT(DISTINCT c.class_id) AS class_count')
            ->left_join('training_classes c', 'c.program_id = p.program_id')
            ->left_join('class_enrollments e', 'e.class_id = c.class_id');
        if (!empty($filters['class_id'])) {
            $popularityQuery->where('c.class_id', (int) $filters['class_id']);
        }
        $programPopularity = $popularityQuery
            ->group_by(['p.program_id', 'p.program_name', 'p.program_code'])
            ->order_by('trainee_count', 'DESC')
            ->result_array();

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
                'completion_rate' => round($completionRate, 1),
            ],
            'trainees_per_batch' => $traineesPerBatch,
            'monthly_trends' => $monthlyTrends,
            'program_popularity' => $programPopularity,
        ];
    }

    private function documentAnalytics(array $filters)
    {
        $docStatsQuery = $this->db->table('trainee_documents d')
            ->select("COUNT(*) AS total_submitted, SUM(CASE WHEN d.review_status = 'approved' THEN 1 ELSE 0 END) AS verified, SUM(CASE WHEN d.review_status = 'rejected' THEN 1 ELSE 0 END) AS rejected, SUM(CASE WHEN d.review_status = 'pending' THEN 1 ELSE 0 END) AS pending")
            ->between('d.upload_date', $filters['start_date'], $filters['end_date']);
        if (!empty($filters['class_id'])) {
            $docStatsQuery->where('d.class_id', (int) $filters['class_id']);
        }
        $docStats = $docStatsQuery->row_array() ?: [];

        $missingQuery = $this->db->table('certificate_document_requirements cdr')
            ->select('COUNT(*) AS missing_count')
            ->left_join('trainee_documents d', "d.class_id = cdr.class_id AND d.document_name = cdr.required_document_name AND d.review_status = 'approved'")
            ->where_null('d.document_id');
        if (!empty($filters['class_id'])) {
            $missingQuery->where('d.class_id', (int) $filters['class_id']);
        }
        $missingDocs = $missingQuery->row_array() ?: [];

        $completionQuery = $this->db->table('training_classes c')
            ->select('c.class_id, c.class_name, COUNT(DISTINCT cdr.requirement_id) AS required_count, COUNT(DISTINCT d.document_id) AS submitted_count')
            ->left_join('certificate_document_requirements cdr', 'cdr.class_id = c.class_id')
            ->left_join('trainee_documents d', "d.class_id = c.class_id AND d.document_name = cdr.required_document_name AND d.review_status = 'approved'");
        if (!empty($filters['class_id'])) {
            $completionQuery->where('d.class_id', (int) $filters['class_id']);
        }
        $completionByClass = $completionQuery
            ->group_by(['c.class_id', 'c.class_name'])
            ->result_array();
        foreach ($completionByClass as &$completion) {
            $required = (int) $completion['required_count'];
            $completion['completion_rate'] = $required > 0
                ? ((int) $completion['submitted_count'] / $required) * 100
                : 0;
        }
        unset($completion);
        usort($completionByClass, static function ($left, $right) {
            return $right['completion_rate'] <=> $left['completion_rate'];
        });

        $docTypesQuery = $this->db->table('trainee_documents d')
            ->select("d.document_name, COUNT(*) AS count, SUM(CASE WHEN d.review_status = 'approved' THEN 1 ELSE 0 END) AS approved, SUM(CASE WHEN d.review_status = 'rejected' THEN 1 ELSE 0 END) AS rejected, SUM(CASE WHEN d.review_status = 'pending' THEN 1 ELSE 0 END) AS pending")
            ->between('d.upload_date', $filters['start_date'], $filters['end_date']);
        if (!empty($filters['class_id'])) {
            $docTypesQuery->where('d.class_id', (int) $filters['class_id']);
        }
        $docTypes = $docTypesQuery
            ->group_by('d.document_name')
            ->order_by('count', 'DESC')
            ->result_array();

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
        $certStatsQuery = $this->db->table('certificates cert')
            ->select("COUNT(*) AS total_issued, SUM(CASE WHEN cert.status = 'issued' THEN 1 ELSE 0 END) AS issued, SUM(CASE WHEN cert.status = 'draft' THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN cert.status = 'revoked' THEN 1 ELSE 0 END) AS revoked")
            ->between('cert.issue_date', $filters['start_date'], $filters['end_date']);
        if (!empty($filters['class_id'])) {
            $certStatsQuery->where('cert.class_id', (int) $filters['class_id']);
        }
        $certStats = $certStatsQuery->row_array() ?: [];

        $certsPerBatchQuery = $this->db->table('training_classes c')
            ->select('c.class_id, c.class_name, p.program_name')
            ->join('training_programs p', 'p.program_id = c.program_id');
        if (!empty($filters['class_id'])) {
            $certsPerBatchQuery->where('c.class_id', (int) $filters['class_id']);
        }
        $certsPerBatch = $certsPerBatchQuery->result_array();
        $certificateCountsQuery = $this->db->table('certificates')
            ->select('class_id, COUNT(DISTINCT certificate_id) AS certificate_count, COUNT(DISTINCT trainee_id) AS trainee_count')
            ->between('issue_date', $filters['start_date'], $filters['end_date']);
        if (!empty($filters['class_id'])) {
            $certificateCountsQuery->where('class_id', (int) $filters['class_id']);
        }
        $certificateCounts = $certificateCountsQuery->group_by('class_id')->result_array();
        $certificateCountsByClass = [];
        foreach ($certificateCounts as $counts) {
            $certificateCountsByClass[(int) $counts['class_id']] = $counts;
        }
        foreach ($certsPerBatch as &$batch) {
            $counts = $certificateCountsByClass[(int) $batch['class_id']] ?? [];
            $batch['certificate_count'] = (int) ($counts['certificate_count'] ?? 0);
            $batch['trainee_count'] = (int) ($counts['trainee_count'] ?? 0);
        }
        unset($batch);
        usort($certsPerBatch, static function ($left, $right) {
            return $right['certificate_count'] <=> $left['certificate_count'];
        });

        // Monthly certificate issuance
        $monthlyQuery = $this->db->table('certificates cert')
            ->select('YEAR(cert.issue_date) AS year, MONTH(cert.issue_date) AS month_number, COUNT(*) AS certificates_issued')
            ->between('cert.issue_date', $filters['start_date'], $filters['end_date']);
        if (!empty($filters['class_id'])) {
            $monthlyQuery->where('cert.class_id', (int) $filters['class_id']);
        }
        $monthlyIssuance = $monthlyQuery
            ->group_by(['YEAR(cert.issue_date)', 'MONTH(cert.issue_date)'])
            ->result_array();
        foreach ($monthlyIssuance as &$monthly) {
            $monthly = [
                'month' => sprintf('%04d-%02d', (int) $monthly['year'], (int) $monthly['month_number']),
                'certificates_issued' => $monthly['certificates_issued'],
            ];
        }
        unset($monthly);
        usort($monthlyIssuance, static function ($left, $right) {
            return $left['month'] <=> $right['month'];
        });

        $eligibilityQuery = $this->db->table('certificate_eligibility e')
            ->select('COUNT(*) AS total_eligible, SUM(CASE WHEN e.is_eligible = 1 THEN 1 ELSE 0 END) AS eligible, SUM(CASE WHEN e.is_eligible = 0 THEN 1 ELSE 0 END) AS not_eligible, AVG(e.attendance_percentage) AS avg_attendance')
            ->join('training_classes c', 'c.class_id = e.class_id');
        if (!empty($filters['class_id'])) {
            $eligibilityQuery->where('c.class_id', (int) $filters['class_id']);
        }
        $eligibilityStats = $eligibilityQuery->row_array() ?: [];

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
        $announcementStats = $this->db->table('announcements')
            ->select("COUNT(*) AS total_published, SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published, SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft")
            ->between('created_at', $filters['start_date'], $filters['end_date'])
            ->row_array() ?: [];

        $notificationStats = $this->db->table('notifications')
            ->select("COUNT(*) AS total_notifications, SUM(CASE WHEN notification_type = 'email' THEN 1 ELSE 0 END) AS email_notifications, SUM(CASE WHEN notification_type = 'sms' THEN 1 ELSE 0 END) AS sms_notifications, SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) AS read_notifications, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_notifications")
            ->between('created_at', $filters['start_date'], $filters['end_date'])
            ->row_array() ?: [];

        // Failed notifications (simulated - add actual tracking if needed)
        $failedNotifications = $this->row(
            "SELECT COUNT(*) AS failed_count
             FROM notifications
             WHERE created_at BETWEEN ? AND ?
             AND (notification_type = 'email' OR notification_type = 'sms')
             AND is_read = 0
             AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            [$filters['start_date'], $filters['end_date']]
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
            [$filters['start_date'], $filters['end_date']]
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

    private function columnOrderRanks($table, $column, $direction = 'ASC')
    {
        $values = $this->db->table($table)
            ->select($column)
            ->order_by($column, $direction)
            ->result_array();
        $ranks = [];
        foreach ($values as $index => $value) {
            $key = serialize($value[$column]);
            if (!array_key_exists($key, $ranks)) {
                $ranks[$key] = $index;
            }
        }
        return $ranks;
    }
}
