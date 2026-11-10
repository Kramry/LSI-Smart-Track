<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';

class Attendance_model extends Base_model
{
    public function activeTraineeByQrCode($qrCode)
    {
        return $this->db->table('trainees')
            ->select('trainee_id')
            ->where(['qr_code' => $qrCode, 'status' => 'active'])
            ->get() ?: null;
    }

    public function enrollmentForClass($classId, $traineeId)
    {
        return $this->db->table('class_enrollments')
            ->select('enrollment_id')
            ->where(['class_id' => (int) $classId, 'trainee_id' => (int) $traineeId])
            ->get() ?: null;
    }

    public function scheduleForToday($scheduleId, $classId)
    {
        return $this->row(
            'SELECT schedule_id FROM training_schedules WHERE schedule_id = ? AND class_id = ? AND schedule_date = CURDATE()',
            [(int) $scheduleId, (int) $classId]
        );
    }

    public function scheduleForClassOnDate($scheduleId, $classId, $scheduleDate)
    {
        return $this->db->table('training_schedules')
            ->select('schedule_id')
            ->where([
                'schedule_id' => (int) $scheduleId,
                'class_id' => (int) $classId,
                'schedule_date' => $scheduleDate,
            ])
            ->get() ?: null;
    }

    public function scheduleOptionsForToday(array $user)
    {
        if (($user['role'] ?? '') === 'instructor') {
            return $this->rows(
                'SELECT s.schedule_id AS id, s.class_id, CONCAT(s.schedule_date, " - ", s.start_time) AS label FROM training_schedules s JOIN training_classes c ON c.class_id = s.class_id WHERE s.schedule_date = CURDATE() AND c.instructor_id = ? ORDER BY s.start_time',
                [(int) $user['user_id']]
            );
        }

        return $this->rows(
            'SELECT schedule_id AS id, class_id, CONCAT(schedule_date, " - ", start_time) AS label FROM training_schedules WHERE schedule_date = CURDATE() ORDER BY start_time'
        );
    }

    public function attendanceClasses(array $user)
    {
        $query = $this->db->table('training_classes')
            ->select('class_id, class_name')
            ->where('status', 'active');
        if ($user['role'] === 'instructor') {
            $query->where('instructor_id', (int) $user['user_id']);
        }
        return $query->order_by('class_name')->get_all();
    }

    public function attendanceClass($classId, array $user)
    {
        $query = $this->db->table('training_classes')
            ->select('class_id, class_name')
            ->where('class_id', (int) $classId)
            ->where('status', 'active');
        if ($user['role'] === 'instructor') {
            $query->where('instructor_id', (int) $user['user_id']);
        }
        return $query->get() ?: null;
    }

    public function attendanceSchedules($classId)
    {
        return $this->rows(
            'SELECT schedule_id, schedule_date, start_time, end_time FROM training_schedules WHERE class_id = ? AND schedule_date = CURDATE() ORDER BY start_time',
            [(int) $classId]
        );
    }

    public function todayAttendance(array $user)
    {
        $sql = 'SELECT a.attendance_id, a.trainee_id, CONCAT(t.first_name, \' \', t.last_name) AS trainee_name, a.class_id, c.class_name, a.attendance_date, a.time_in, a.time_out, a.status FROM attendance_records a JOIN trainees t ON t.trainee_id = a.trainee_id LEFT JOIN training_classes c ON c.class_id = a.class_id WHERE a.attendance_date = CURDATE()';
        $values = [];
        if ($user['role'] === 'instructor') {
            $sql .= ' AND c.instructor_id = ?';
            $values[] = (int) $user['user_id'];
        }
        return $this->rows($sql . ' ORDER BY a.time_in DESC, t.last_name, t.first_name', $values);
    }

    public function attendanceSummary($traineeId)
    {
        return $this->row("SELECT SUM(status = 'Present') AS present, SUM(status = 'Late') AS late, SUM(status = 'Absent') AS absent FROM attendance_records WHERE trainee_id = ?", [(int) $traineeId]) ?: [];
    }

    public function attendanceReport($startDate, $endDate, $user)
    {
        $sql = 'SELECT a.attendance_date, t.first_name, t.last_name, p.program_name, c.class_name, a.time_in, a.time_out, a.status, a.remarks FROM attendance_records a JOIN trainees t ON t.trainee_id = a.trainee_id LEFT JOIN training_programs p ON p.program_id = t.program_id LEFT JOIN training_classes c ON c.class_id = a.class_id WHERE a.attendance_date BETWEEN ? AND ?';
        $values = [$startDate, $endDate];
        if ($user['role'] === 'instructor') {
            $sql .= ' AND c.instructor_id = ?';
            $values[] = (int) $user['user_id'];
        } elseif ($user['role'] === 'trainee') {
            $sql .= ' AND t.user_id = ?';
            $values[] = (int) $user['user_id'];
        }
        return $this->rows($sql . ' ORDER BY a.attendance_date DESC, t.last_name, t.first_name', $values);
    }

    public function scanAttendance($qrCode, $action, $classId, $scheduleId, $userId)
    {
        $trainee = $this->db->table('trainees')
            ->select('trainee_id, first_name, last_name')
            ->where('qr_code', $qrCode)
            ->where('status', 'active')
            ->limit(1)
            ->get();
        if (!$trainee) {
            return ['error' => 'No active trainee matches that QR code.'];
        }

        $today = date('Y-m-d');
        $existingQuery = $this->db->table('attendance_records')
            ->select('attendance_id, time_in, time_out, status')
            ->where('trainee_id', (int) $trainee['trainee_id'])
            ->where('attendance_date', $today);
        if ($classId) {
            $existingQuery->where('class_id', $classId);
        } else {
            $existingQuery->where_null('class_id');
        }
        $existing = $existingQuery->limit(1)->get();
        if ($action === 'in') {
            if ($existing && $existing['time_in']) {
                return ['error' => 'This trainee already has a time-in for today.'];
            }
            $status = 'Present';
            if ($scheduleId) {
                $schedule = $this->db->table('training_schedules')
                    ->select('start_time')
                    ->where('schedule_id', (int) $scheduleId)
                    ->get();
                if ($schedule && date('H:i:s') > $schedule['start_time']) {
                    $status = 'Late';
                }
            }
            if ($existing) {
                $this->execute('UPDATE attendance_records SET time_in = NOW(), status = ?, schedule_id = ?, recorded_by = ? WHERE attendance_id = ?', [$status, $scheduleId ?: null, $userId, (int) $existing['attendance_id']]);
            } else {
                $this->execute('INSERT INTO attendance_records (trainee_id, class_id, schedule_id, attendance_date, time_in, time_out, status, remarks, recorded_by) VALUES (?, ?, ?, ?, NOW(), NULL, ?, ?, ?)', [(int) $trainee['trainee_id'], $classId ?: null, $scheduleId ?: null, $today, $status, 'QR/manual scan', $userId]);
            }
            return [
                'success' => $trainee['first_name'] . ' ' . $trainee['last_name'] . ' checked in.',
                'trainee' => ['trainee_id' => (int) $trainee['trainee_id'], 'name' => $trainee['first_name'] . ' ' . $trainee['last_name']],
                'status' => $status,
                'action' => $action,
            ];
        }

        if (!$existing || !$existing['time_in']) {
            return ['error' => 'Time-in must be recorded before time-out.'];
        }
        if ($existing['time_out']) {
            return ['error' => 'This trainee already has a time-out for today.'];
        }
        $this->execute('UPDATE attendance_records SET time_out = NOW(), recorded_by = ? WHERE attendance_id = ?', [$userId, (int) $existing['attendance_id']]);
        return [
            'success' => $trainee['first_name'] . ' ' . $trainee['last_name'] . ' checked out.',
            'trainee' => ['trainee_id' => (int) $trainee['trainee_id'], 'name' => $trainee['first_name'] . ' ' . $trainee['last_name']],
            'status' => $existing['status'],
            'action' => $action,
        ];
    }
}
