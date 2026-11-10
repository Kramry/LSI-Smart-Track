<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'controllers/Base.php';

class Attendance extends Base
{
    public function scanAttendance()
    {
        if (!$this->requireUser()) {
            return;
        }
        if (!$this->validCsrf()) {
            $this->jsonResponse(['message' => 'Your session expired. Refresh and try again.'], 403);
        }
        if (!in_array($this->user['role'], ['administrator', 'staff', 'instructor'], true)) {
            return $this->deny('You are not allowed to record attendance.');
        }
        $qrCode = trim((string) $this->input('qr_code'));
        $action = (string) $this->input('scan_action');
        $classIdInput = trim((string) $this->input('class_id'));
        $scheduleIdInput = trim((string) $this->input('schedule_id'));
        $classId = filter_var($classIdInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $scheduleId = $scheduleIdInput === ''
            ? 0
            : filter_var($scheduleIdInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $errors = [];
        if ($qrCode === '' || strlen($qrCode) > 100) {
            $errors['qr_code'] = 'Enter a QR code of 1 to 100 characters.';
        }
        if (!in_array($action, ['in', 'out'], true)) {
            $errors['scan_action'] = 'Choose time-in or time-out.';
        }
        if ($classId === false) {
            $errors['class_id'] = 'Choose a valid class.';
        }
        if ($scheduleId === false) {
            $errors['schedule_id'] = 'Choose a valid session.';
        }
        if ($errors) {
            if ($this->apiRequest) {
                $this->jsonResponse([
                    'message' => 'Check the scan details and try again.',
                    'errors' => $errors,
                ], 422);
            }
            return $this->actionError('Enter a QR ID and choose time-in or time-out.', 'manage/attendance');
        }
        $class = $this->attendanceModel->attendanceClass((int) $classId, $this->user);
        if (!$class) {
            if (!$this->apiRequest) {
                return $this->actionError('That class is not available to your account.', 'manage/attendance', 404);
            }
            $this->jsonResponse(['message' => 'That class is not available to your account.'], 404);
        }
        $qrTrainee = $this->attendanceModel->activeTraineeByQrCode($qrCode);
        $enrolled = $qrTrainee
            ? $this->attendanceModel->enrollmentForClass((int) $classId, (int) $qrTrainee['trainee_id'])
            : null;
        if (!$enrolled) {
            if ($this->apiRequest) {
                $this->jsonResponse([
                    'message' => 'That trainee is not enrolled in the selected class.',
                    'errors' => ['qr_code' => 'No active enrolled trainee matches this code.'],
                ], 422);
            }
            return $this->actionError('That trainee is not enrolled in the selected class.', 'manage/attendance');
        }
        if ($scheduleId && !$this->attendanceModel->scheduleForToday((int) $scheduleId, (int) $classId)) {
            if ($this->apiRequest) {
                $this->jsonResponse([
                    'message' => 'Choose a session scheduled for this class today.',
                    'errors' => ['schedule_id' => 'This session is not scheduled for the selected class today.'],
                ], 422);
            }
            return $this->actionError('Choose a session scheduled for this class today.', 'manage/attendance');
        }
        $result = $this->attendanceModel->scanAttendance($qrCode, $action, (int) $classId, (int) $scheduleId, (int) $this->user['user_id']);
        if (isset($result['success'])) {
            $this->recalculateEligibilityPair($qrTrainee['trainee_id'], $classId);
            $this->model->logActivity((int) $this->user['user_id'], 'Recorded attendance', $result['success']);
        }
        if ($this->apiRequest) {
            if (isset($result['error'])) {
                $this->jsonResponse([
                    'message' => $result['error'],
                    'errors' => ['scan_action' => $result['error']],
                ], 422);
            }
            $this->jsonResponse([
                'ok' => true,
                'data' => [
                    'trainee' => $result['trainee'],
                    'status' => $result['status'],
                    'scan_action' => $result['action'],
                ],
                'message' => $result['success'],
            ]);
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
