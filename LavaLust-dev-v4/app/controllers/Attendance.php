<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'controllers/Base.php';

class Attendance extends Base
{
    public function scanAttendance()
    {
        $this->requireRequestMethod('POST');
        if (!$this->requireUser()) {
            return;
        }
        if (!$this->inRoleGroup('teaching_staff')) {
            return $this->deny('You are not allowed to record attendance.');
        }
        if (!$this->validCsrf()) {
            if ($this->apiRequest) {
                return $this->jsonResponse([
                    'message' => 'Your session expired. Refresh and try again.',
                    'errors' => [],
                ], 403);
            }
            return $this->actionError('Your session expired. Refresh and try again.', 'manage/attendance', 403);
        }

        $qrCode = $this->input('qr_code');
        $action = $this->input('scan_action');
        $classIdInput = $this->input('class_id');
        $scheduleIdInput = $this->input('schedule_id');
        $errors = $this->validateScanInput($qrCode, $action, $classIdInput, $scheduleIdInput, $classId, $scheduleId);
        try {
            $limit = getenv('ATTENDANCE_SCAN_LIMIT');
            $windowSeconds = getenv('ATTENDANCE_SCAN_WINDOW_SECONDS');
            $rateLimit = $this->attendanceModel->consumeScanRateLimit(
                $this->user,
                (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                $limit === false ? 12 : (int) $limit,
                $windowSeconds === false ? 60 : (int) $windowSeconds
            );
        } catch (Throwable $error) {
            error_log('Attendance scan rate limiter failed (' . get_class($error) . '): ' . $error->getMessage());
            return $this->attendanceError('Attendance scanning is temporarily unavailable.', 500);
        }
        if (!$rateLimit['allowed']) {
            header('Retry-After: ' . (int) $rateLimit['retry_after']);
            return $this->jsonResponse([
                'message' => 'Too many attendance scans. Please wait before trying again.',
                'errors' => [],
            ], 429);
        }

        if ($errors) {
            if ($this->apiRequest) {
                return $this->jsonResponse([
                    'message' => 'Check the scan details and try again.',
                    'errors' => $errors,
                ], 422);
            }
            return $this->actionError('Enter a QR ID and choose time-in or time-out.', 'manage/attendance', 422);
        }

        $recalculate = function ($traineeId, $selectedClassId) {
            try {
                $this->recalculateEligibilityPair($traineeId, $selectedClassId);
                return true;
            } catch (Throwable $error) {
                $this->logAttendanceFailure('Attendance eligibility recalculation failed (' . get_class($error) . '): ' . $error->getMessage());
                return false;
            }
        };

        try {
            $result = $this->attendanceModel->scanAttendance(
                $qrCode,
                $action,
                $classId,
                $scheduleId,
                $this->user,
                $recalculate
            );
        } catch (Throwable $error) {
            $this->logAttendanceFailure('Attendance scan failed (' . get_class($error) . '): ' . $error->getMessage());
            return $this->attendanceError('The attendance scan could not be completed.', 500);
        }

        if (!$result['ok']) {
            if ($this->apiRequest) {
                return $this->jsonResponse([
                    'message' => $result['message'],
                    'errors' => $result['errors'],
                ], (int) $result['status_code']);
            }
            return $this->actionError($result['message'], 'manage/attendance', (int) $result['status_code']);
        }

        try {
            $this->recordModel->logActivity(
                (int) $this->user['user_id'],
                'Recorded attendance',
                $result['message']
            );
        } catch (Throwable $error) {
            $this->logAttendanceFailure('Attendance activity logging failed (' . get_class($error) . '): ' . $error->getMessage());
        }

        if ($this->apiRequest) {
            return $this->jsonResponse([
                'ok' => true,
                'data' => [
                    'trainee' => $result['data']['trainee'],
                    'status' => $result['data']['status'],
                    'scan_action' => $result['data']['action'],
                ],
                'message' => $result['message'],
            ]);
        }

        $this->notice($result['message']);
        redirect($this->url('manage/attendance'));
        exit;
    }

    public function exportReport($period)
    {
        $this->requireRequestMethod('GET');
        if (!$this->requireUser()) {
            return;
        }
        if (!$this->inRoleGroup('teaching_staff')) {
            return $this->deny('You are not allowed to export attendance reports.');
        }
        if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) {
            return $this->actionError('That report is not available.', 'manage/attendance', 404);
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

        try {
            $records = $this->attendanceModel->attendanceReport(
                $start->format('Y-m-d'),
                $end->format('Y-m-d'),
                $this->user,
                10000
            );
        } catch (Throwable $error) {
            error_log('Attendance CSV export query failed (' . get_class($error) . '): ' . $error->getMessage());
            return $this->attendanceError('The attendance report could not be generated.', 500);
        }
        while (ob_get_level() > 0) {
            if (!ob_end_clean()) {
                if (!ob_clean()) {
                    error_log('Attendance CSV export could not clear the output buffer.');
                    http_response_code(500);
                    exit;
                }
                break;
            }
        }
        if (headers_sent($file, $line)) {
            error_log('Attendance CSV export aborted because output was sent before headers at ' . $file . ':' . $line . '.');
            http_response_code(500);
            exit;
        }

        $output = fopen('php://output', 'w');
        if ($output === false) {
            error_log('Attendance CSV export could not open the output stream.');
            http_response_code(500);
            exit;
        }
        $filename = 'smarttrack-' . $period . '-report-' . $today->format('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        fputcsv($output, ['Date', 'Trainee', 'Program', 'Class', 'Time in', 'Time out', 'Status', 'Remarks'], ',', '"', '');
        foreach ($records as $record) {
            fputcsv($output, [
                $this->safeCsvText($record['attendance_date'] ?? ''),
                $this->safeCsvText(trim((string) ($record['first_name'] ?? '') . ' ' . (string) ($record['last_name'] ?? ''))),
                $this->safeCsvText($record['program_name'] ?? ''),
                $this->safeCsvText($record['class_name'] ?? ''),
                $this->safeCsvText($record['time_in'] ?? ''),
                $this->safeCsvText($record['time_out'] ?? ''),
                $this->safeCsvText($record['status'] ?? ''),
                $this->safeCsvText($record['remarks'] ?? ''),
            ], ',', '"', '');
        }
        fclose($output);
        exit;
    }

    private function validateScanInput($qrCodeInput, $actionInput, $classIdInput, $scheduleIdInput, &$classId, &$scheduleId)
    {
        $errors = [];
        $qrCode = is_scalar($qrCodeInput) ? trim((string) $qrCodeInput) : '';
        $action = is_scalar($actionInput) ? (string) $actionInput : '';
        $classValue = is_scalar($classIdInput) ? trim((string) $classIdInput) : '';
        $scheduleValue = is_scalar($scheduleIdInput) ? trim((string) $scheduleIdInput) : '';
        $classId = filter_var($classValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $scheduleId = $scheduleValue === ''
            ? 0
            : filter_var($scheduleValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

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
        if (!is_scalar($qrCodeInput) || !is_scalar($actionInput)
            || !is_scalar($classIdInput) || !is_scalar($scheduleIdInput)) {
            $errors['scan'] = 'Scan details must be scalar values.';
        }

        $classId = $classId === false ? 0 : (int) $classId;
        $scheduleId = $scheduleId === false ? 0 : (int) $scheduleId;
        return $errors;
    }

    private function attendanceError($message, $status)
    {
        if ($this->apiRequest) {
            return $this->jsonResponse(['message' => $message, 'errors' => []], (int) $status);
        }
        return $this->actionError($message, 'manage/attendance', (int) $status);
    }

    private function safeCsvText($value): string
    {
        $text = (string) $value;
        if (preg_match('/^[\s\x00-\x20]*[=+\-@]/u', $text) === 1) {
            return "'" . $text;
        }
        return $text;
    }

    private function logAttendanceFailure($message)
    {
        try {
            error_log($message);
        } catch (Throwable $loggingError) {
            return false;
        }
        return true;
    }
}


