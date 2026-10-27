<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';

class Attendance_model extends Base_model
{
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
        $trainee = $this->row("SELECT trainee_id, first_name, last_name FROM trainees WHERE qr_code = ? AND status = 'active' LIMIT 1", [$qrCode]);
        if (!$trainee) {
            return ['error' => 'No active trainee matches that QR code.'];
        }

        $today = date('Y-m-d');
        $existing = $this->row('SELECT attendance_id, time_in, time_out FROM attendance_records WHERE trainee_id = ? AND attendance_date = ? AND class_id <=> ? LIMIT 1', [(int) $trainee['trainee_id'], $today, $classId ?: null]);
        if ($action === 'in') {
            if ($existing && $existing['time_in']) {
                return ['error' => 'This trainee already has a time-in for today.'];
            }
            $status = 'Present';
            if ($scheduleId) {
                $schedule = $this->row('SELECT start_time FROM training_schedules WHERE schedule_id = ?', [(int) $scheduleId]);
                if ($schedule && date('H:i:s') > $schedule['start_time']) {
                    $status = 'Late';
                }
            }
            if ($existing) {
                $this->execute('UPDATE attendance_records SET time_in = NOW(), status = ?, schedule_id = ?, recorded_by = ? WHERE attendance_id = ?', [$status, $scheduleId ?: null, $userId, (int) $existing['attendance_id']]);
            } else {
                $this->execute('INSERT INTO attendance_records (trainee_id, class_id, schedule_id, attendance_date, time_in, time_out, status, remarks, recorded_by) VALUES (?, ?, ?, ?, NOW(), NULL, ?, ?, ?)', [(int) $trainee['trainee_id'], $classId ?: null, $scheduleId ?: null, $today, $status, 'QR/manual scan', $userId]);
            }
            return ['success' => $trainee['first_name'] . ' ' . $trainee['last_name'] . ' checked in.'];
        }

        if (!$existing || !$existing['time_in']) {
            return ['error' => 'Time-in must be recorded before time-out.'];
        }
        if ($existing['time_out']) {
            return ['error' => 'This trainee already has a time-out for today.'];
        }
        $this->execute('UPDATE attendance_records SET time_out = NOW(), recorded_by = ? WHERE attendance_id = ?', [$userId, (int) $existing['attendance_id']]);
        return ['success' => $trainee['first_name'] . ' ' . $trainee['last_name'] . ' checked out.'];
    }
}
