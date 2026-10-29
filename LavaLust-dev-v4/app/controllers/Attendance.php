<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'controllers/Base.php';

class Attendance extends Base
{
    public function scanAttendance()
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff', 'instructor'], true)) {
            return $this->deny('You are not allowed to record attendance.');
        }
        $qrCode = trim((string) $this->input('qr_code'));
        $action = (string) $this->input('scan_action');
        $classId = (int) $this->input('class_id');
        $scheduleId = (int) $this->input('schedule_id');
        if ($qrCode === '' || !in_array($action, ['in', 'out'], true)) {
            return $this->actionError('Enter a QR ID and choose time-in or time-out.', 'manage/attendance');
        }
        if (!$classId) {
            return $this->actionError('Choose a class before scanning attendance.', 'manage/attendance');
        }
        $qrTrainee = $this->model->row('SELECT trainee_id FROM trainees WHERE qr_code = ? AND status = \'active\'', [$qrCode]);
        $enrolled = $qrTrainee ? $this->model->row('SELECT enrollment_id FROM class_enrollments WHERE class_id = ? AND trainee_id = ?', [$classId, (int) $qrTrainee['trainee_id']]) : null;
        if (!$enrolled) {
            return $this->actionError('That trainee is not enrolled in the selected class.', 'manage/attendance');
        }
        if ($scheduleId && !$this->model->row('SELECT schedule_id FROM training_schedules WHERE schedule_id = ? AND class_id = ? AND schedule_date = CURDATE()', [$scheduleId, $classId])) {
            return $this->actionError('Choose a session scheduled for this class today.', 'manage/attendance');
        }
        if ($this->user['role'] === 'instructor' && $classId) {
            $assigned = $this->model->row('SELECT class_id FROM training_classes WHERE class_id = ? AND instructor_id = ?', [$classId, (int) $this->user['user_id']]);
            if (!$assigned) {
                return $this->deny('That class is not assigned to your account.');
            }
        }
        $result = $this->attendanceModel->scanAttendance($qrCode, $action, $classId, $scheduleId, (int) $this->user['user_id']);
        if (isset($result['success'])) {
            $this->recalculateEligibilityPair($qrTrainee['trainee_id'], $classId);
            $this->model->logActivity((int) $this->user['user_id'], 'Recorded attendance', $result['success']);
        }
        if ($this->apiRequest) {
            if (isset($result['error'])) {
                $this->jsonResponse(['message' => $result['error']], 422);
            }
            $this->jsonResponse(['message' => $result['success']]);
        }
        $this->notice($result['success'] ?? $result['error']);
        redirect($this->url('manage/attendance'));
    }

    public function exportReport($period)
    {
        if (!$this->requireUser() || !in_array($period, ['daily', 'weekly', 'monthly'], true)) {
            return $this->deny('That report is not available.');
        }
        $today = new DateTimeImmutable('today');
        if ($period === 'weekly') {
            $start = $today->modify('monday this week');
            $end = $start->modify('+6 days');
        } elseif ($period === 'monthly') {
            $start = $today->modify('first day of this month');
            $end = $today->modify('last day of this month');
        } else {
            $start = $today;
            $end = $today;
        }
        $records = $this->model->attendanceReport($start->format('Y-m-d'), $end->format('Y-m-d'), $this->user);
        $filename = 'smarttrack-' . $period . '-report-' . $today->format('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Date', 'Trainee', 'Program', 'Class', 'Time in', 'Time out', 'Status', 'Remarks']);
        foreach ($records as $record) {
            fputcsv($output, [$record['attendance_date'], $record['first_name'] . ' ' . $record['last_name'], $record['program_name'], $record['class_name'], $record['time_in'], $record['time_out'], $record['status'], $record['remarks']]);
        }
        fclose($output);
        exit;
    }
}
