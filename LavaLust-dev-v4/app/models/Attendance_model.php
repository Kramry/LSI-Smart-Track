<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';

class Attendance_model extends Base_model
{
    private static int $scanLimitCleanupCounter = 0;
    private static bool $redisFallbackLogged = false;
    private static bool $scanLimitTableEnsured = false;

    public function activeTraineeByQrCode($qrCode)
    {
        return $this->db->table('trainees')
            ->select('trainee_id')
            ->where(['qr_code' => $qrCode, 'status' => 'active'])
            ->get() ?: null;
    }

    public function enrollmentForClass($classId, $traineeId, array $user)
    {
        if (!$this->canManageAttendance($user) || !$this->classIsInScope($classId, $user)) {
            return null;
        }

        return $this->db->table('class_enrollments')
            ->select('enrollment_id')
            ->where(['class_id' => (int) $classId, 'trainee_id' => (int) $traineeId])
            ->get() ?: null;
    }

    public function scheduleForToday($scheduleId, $classId, array $user)
    {
        if (!$this->canManageAttendance($user) || !$this->attendanceClass($classId, $user)) {
            return null;
        }

        return $this->row(
            'SELECT s.schedule_id FROM training_schedules s
            JOIN training_classes c ON c.class_id = s.class_id
            WHERE s.schedule_id = ? AND s.class_id = ? AND s.schedule_date = CURDATE()
                AND c.status = \'active\'
                AND (? <> \'instructor\' OR c.instructor_id = ?)',
            [(int) $scheduleId, (int) $classId, (string) $user['role'], (int) $user['user_id']]
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
        if (!$this->canManageAttendance($user)) {
            return [];
        }
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
        if (!$this->canManageAttendance($user)
            || filter_var($classId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return null;
        }
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

    public function attendanceReport($startDate, $endDate, array $user, $limit = 10000)
    {
        $limit = max(1, min(10000, (int) $limit));
        $sql = 'SELECT a.attendance_date, t.first_name, t.last_name, p.program_name, c.class_name, a.time_in, a.time_out, a.status, a.remarks FROM attendance_records a JOIN trainees t ON t.trainee_id = a.trainee_id LEFT JOIN training_programs p ON p.program_id = t.program_id LEFT JOIN training_classes c ON c.class_id = a.class_id WHERE a.attendance_date BETWEEN ? AND ?';
        $values = [$startDate, $endDate];
        if ($user['role'] === 'instructor') {
            $sql .= ' AND c.instructor_id = ?';
            $values[] = (int) $user['user_id'];
        } elseif ($user['role'] === 'trainee') {
            $sql .= ' AND t.user_id = ?';
            $values[] = (int) $user['user_id'];
        }
        return $this->rows($sql . ' ORDER BY a.attendance_date DESC, t.last_name, t.first_name LIMIT ' . $limit, $values);
    }

    private function ensureScanRateLimitTable()
    {
        if (self::$scanLimitTableEnsured) {
            return;
        }

        $this->execute(
            'CREATE TABLE IF NOT EXISTS smarttrack_attendance_scan_limits (
                rate_key CHAR(64) NOT NULL,
                window_started_at DATETIME NOT NULL,
                request_count INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (rate_key),
                KEY idx_smarttrack_attendance_scan_window (window_started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$scanLimitTableEnsured = true;
    }
    public function consumeScanRateLimit(array $user, $ipAddress, $limit = 12, $windowSeconds = 60)
    {
        if (!$this->canManageAttendance($user)) {
            throw new InvalidArgumentException('A valid teaching-staff account is required for attendance rate limiting.');
        }

        $limit = max(1, min(1000, (int) $limit));
        $windowSeconds = max(1, min(3600, (int) $windowSeconds));
        $keys = [
            'user:' . (int) $user['user_id'],
            'ip:' . hash('sha256', (string) $ipAddress),
        ];
        $redis = $this->connectScanRateLimitRedis();
        if ($redis) {
            try {
                $counters = [];
                foreach ($keys as $key) {
                    $redisKey = 'smarttrack:attendance-scan:' . hash('sha256', $key);
                    $count = (int) $redis->incr($redisKey);
                    if ($count === 1) {
                        $redis->expire($redisKey, $windowSeconds);
                    }
                    $ttl = (int) $redis->ttl($redisKey);
                    if ($ttl < 1) {
                        $redis->expire($redisKey, $windowSeconds);
                        $ttl = $windowSeconds;
                    }
                    $counters[] = ['count' => $count, 'retry_after' => max(1, $ttl)];
                }
                $redis->close();
                return $this->rateLimitResult($counters, $limit);
            } catch (Throwable $error) {
                try {
                    $redis->close();
                } catch (Throwable $closeError) {
                    $this->logAttendanceFailure('Attendance Redis rate-limit connection cleanup failed.');
                }
                $this->logAttendanceFailure('Attendance Redis rate limiter unavailable; using the MySQL counter store (' . get_class($error) . ').');
            }
        }

        $this->ensureScanRateLimitTable();
        $counters = [];
        foreach ($keys as $key) {
            $storedKey = hash('sha256', 'attendance-scan:' . $key);
            $this->execute(
                'INSERT INTO smarttrack_attendance_scan_limits (rate_key, window_started_at, request_count)
                VALUES (?, UTC_TIMESTAMP(), 1)
                ON DUPLICATE KEY UPDATE
                    request_count = IF(window_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $windowSeconds . ' SECOND), 1, request_count + 1),
                    window_started_at = IF(window_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $windowSeconds . ' SECOND), UTC_TIMESTAMP(), window_started_at)',
                [$storedKey]
            );
            $counter = $this->row(
                'SELECT request_count,
                    GREATEST(1, ' . $windowSeconds . ' - TIMESTAMPDIFF(SECOND, window_started_at, UTC_TIMESTAMP())) AS retry_after
                FROM smarttrack_attendance_scan_limits
                WHERE rate_key = ?',
                [$storedKey]
            );
            if (!$counter) {
                throw new RuntimeException('The attendance scan rate-limit counter could not be read.');
            }
            $counters[] = [
                'count' => (int) $counter['request_count'],
                'retry_after' => (int) $counter['retry_after'],
            ];
        }
        if ((++self::$scanLimitCleanupCounter % 100) === 0) {
            $this->execute('DELETE FROM smarttrack_attendance_scan_limits WHERE window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)');
        }

        return $this->rateLimitResult($counters, $limit);
    }

    public function scanAttendance($qrCode, $action, $classId, $scheduleId, array $user, callable $recalculateEligibility)
    {
        if (!$this->canManageAttendance($user)
            || !is_string($qrCode)
            || trim($qrCode) === ''
            || strlen(trim($qrCode)) > 100
            || filter_var($classId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || !in_array($action, ['in', 'out'], true)
            || (!is_int($scheduleId) && !is_string($scheduleId))
            || !preg_match('/^(0|[1-9]\d*)$/D', (string) $scheduleId)) {
            return $this->scanFailure(422, 'Invalid attendance scan details.', ['scan' => 'Check the scan fields and try again.']);
        }

        $qrCode = trim($qrCode);
        $classId = (int) $classId;
        $scheduleId = (int) $scheduleId;
        if (!$this->attendanceClass($classId, $user)) {
            return $this->scanFailure(404, 'That class is not available to your account.', ['class_id' => 'That class is not available to your account.']);
        }
        if ($scheduleId && !$this->scheduleForToday($scheduleId, $classId, $user)) {
            return $this->scanFailure(422, 'Choose a session scheduled for this class today.', ['schedule_id' => 'This session is not scheduled for the selected class today.']);
        }

        $trainee = $this->db->table('trainees')
            ->select('trainee_id, first_name, last_name')
            ->where(['qr_code' => (string) $qrCode, 'status' => 'active'])
            ->limit(1)
            ->get();
        if (!$trainee) {
            return $this->scanFailure(422, 'No active trainee matches that QR code.', ['qr_code' => 'No active trainee matches this code.']);
        }
        if (!$this->enrollmentForClass($classId, (int) $trainee['trainee_id'], $user)) {
            return $this->scanFailure(422, 'That trainee is not enrolled in the selected class.', ['qr_code' => 'No active enrolled trainee matches this code.']);
        }

        if (!$this->db->transaction()) {
            throw new RuntimeException('Unable to start the attendance transaction.');
        }

        try {
            if (!$this->attendanceClass($classId, $user)) {
                $this->db->roll_back();
                return $this->scanFailure(404, 'That class is not available to your account.', ['class_id' => 'That class is not available to your account.']);
            }

            if (!$this->enrollmentForClass($classId, (int) $trainee['trainee_id'], $user)) {
                $this->db->roll_back();
                return $this->scanFailure(422, 'That trainee is not enrolled in the selected class.', ['qr_code' => 'No active enrolled trainee matches this code.']);
            }

            if ($scheduleId && !$this->scheduleForToday($scheduleId, $classId, $user)) {
                $this->db->roll_back();
                return $this->scanFailure(422, 'Choose a session scheduled for this class today.', ['schedule_id' => 'This session is not scheduled for the selected class today.']);
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
                    $this->db->roll_back();
                    return $this->scanFailure(422, 'This trainee already has a time-in for today.', ['scan_action' => 'This trainee already has a time-in for today.']);
                }
                $status = 'Present';
                if ($scheduleId) {
                    $schedule = $this->db->table('training_schedules')
                        ->select('start_time')
                        ->where('schedule_id', $scheduleId)
                        ->get();
                    if ($schedule && date('H:i:s') > $schedule['start_time']) {
                        $status = 'Late';
                    }
                }
                if ($existing) {
                    $affected = $this->execute(
                        'UPDATE attendance_records SET time_in = NOW(), status = ?, schedule_id = ?, recorded_by = ? WHERE attendance_id = ? AND time_in IS NULL',
                        [$status, $scheduleId ?: null, (int) $user['user_id'], (int) $existing['attendance_id']]
                    );
                    if ($affected < 1) {
                        $this->db->roll_back();
                        return $this->scanFailure(422, 'This trainee already has a time-in for today.', ['scan_action' => 'This trainee already has a time-in for today.']);
                    }
                } else {
                    $affected = $this->execute(
                        'INSERT INTO attendance_records (trainee_id, class_id, schedule_id, attendance_date, time_in, time_out, status, remarks, recorded_by) VALUES (?, ?, ?, ?, NOW(), NULL, ?, ?, ?)',
                        [(int) $trainee['trainee_id'], $classId ?: null, $scheduleId ?: null, $today, $status, 'QR/manual scan', (int) $user['user_id']]
                    );
                    if ($affected < 1) {
                        throw new RuntimeException('The attendance time-in was not recorded.');
                    }
                }
            } else {
                if (!$existing || !$existing['time_in']) {
                    $this->db->roll_back();
                    return $this->scanFailure(422, 'Time-in must be recorded before time-out.', ['scan_action' => 'Time-in must be recorded before time-out.']);
                }
                if ($existing['time_out']) {
                    $this->db->roll_back();
                    return $this->scanFailure(422, 'This trainee already has a time-out for today.', ['scan_action' => 'This trainee already has a time-out for today.']);
                }
                if ($this->execute(
                    'UPDATE attendance_records SET time_out = NOW(), recorded_by = ? WHERE attendance_id = ? AND time_out IS NULL',
                    [(int) $user['user_id'], (int) $existing['attendance_id']]
                ) < 1) {
                    $this->db->roll_back();
                    return $this->scanFailure(422, 'This trainee already has a time-out for today.', ['scan_action' => 'This trainee already has a time-out for today.']);
                }
                $status = $existing['status'];
            }

            $eligibilityRecalculated = false;
            if ($this->db->transaction()) {
                try {
                    $eligibilityRecalculated = $recalculateEligibility((int) $trainee['trainee_id'], $classId) === true;
                    if ($eligibilityRecalculated) {
                        $this->db->commit();
                    } else {
                        $this->db->roll_back();
                    }
                } catch (Throwable $error) {
                    $this->db->roll_back();
                    $this->logAttendanceFailure('Attendance eligibility recalculation failed (' . get_class($error) . ').');
                }
            } else {
                $this->logAttendanceFailure('Attendance eligibility recalculation savepoint could not be started.');
            }

            if (!$this->db->commit()) {
                throw new RuntimeException('Unable to commit the attendance transaction.');
            }

            $traineeName = trim((string) $trainee['first_name'] . ' ' . (string) $trainee['last_name']);
            return [
                'ok' => true,
                'status_code' => 200,
                'message' => $traineeName . ($action === 'in' ? ' checked in.' : ' checked out.'),
                'errors' => [],
                'data' => [
                    'trainee' => ['trainee_id' => (int) $trainee['trainee_id'], 'name' => $traineeName],
                    'status' => $status,
                    'action' => $action,
                    'eligibility_recalculated' => $eligibilityRecalculated,
                ],
            ];
        } catch (Throwable $error) {
            try {
                $this->db->roll_back();
            } catch (Throwable $rollbackError) {
                $this->logAttendanceFailure('Attendance transaction rollback failed (' . get_class($rollbackError) . ').');
            }
            $errorMessage = strtolower($error->getMessage());
            if ($action === 'in'
                && (strpos($errorMessage, 'uq_smarttrack_attendance_class_day') !== false
                    || (strpos($errorMessage, 'duplicate entry') !== false && strpos($errorMessage, 'attendance_records') !== false))) {
                return $this->scanFailure(422, 'This trainee already has a time-in for today.', ['scan_action' => 'This trainee already has a time-in for today.']);
            }
            throw $error;
        }
    }

    private function canManageAttendance(array $user)
    {
        return in_array($user['role'] ?? '', ['administrator', 'staff', 'instructor'], true)
            && filter_var($user['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }

    private function classIsInScope($classId, array $user)
    {
        if (filter_var($classId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return false;
        }
        $query = $this->db->table('training_classes')
            ->select('class_id')
            ->where('class_id', (int) $classId);
        if ($user['role'] === 'instructor') {
            $query->where('instructor_id', (int) $user['user_id']);
        }
        return (bool) $query->get();
    }

    private function scanFailure($status, $message, array $errors)
    {
        return [
            'ok' => false,
            'status_code' => (int) $status,
            'message' => $message,
            'errors' => $errors,
            'data' => [],
        ];
    }

    private function rateLimitResult(array $counters, $limit)
    {
        $retryAfter = 0;
        foreach ($counters as $counter) {
            if ($counter['count'] > $limit) {
                $retryAfter = max($retryAfter, (int) $counter['retry_after']);
            }
        }
        return [
            'allowed' => $retryAfter === 0,
            'retry_after' => $retryAfter,
        ];
    }

    private function connectScanRateLimitRedis()
    {
        $redisUrl = trim((string) (getenv('REDIS_URL') ?: ''));
        if ($redisUrl === '') {
            return null;
        }
        if (!class_exists('Redis')) {
            $this->logRedisFallback('The Redis extension is not installed.');
            return null;
        }

        try {
            $parts = parse_url($redisUrl);
            if (!is_array($parts) || empty($parts['host'])) {
                throw new InvalidArgumentException('REDIS_URL is not a valid Redis URL.');
            }
            $scheme = strtolower((string) ($parts['scheme'] ?? 'redis'));
            if (!in_array($scheme, ['redis', 'rediss'], true)) {
                throw new InvalidArgumentException('REDIS_URL must use redis or rediss.');
            }
            $host = ($scheme === 'rediss' ? 'tls://' : '') . $parts['host'];
            $redis = new Redis();
            if (!$redis->connect($host, (int) ($parts['port'] ?? 6379), 1.0)) {
                throw new RuntimeException('Redis connection failed.');
            }
            if (isset($parts['pass'])) {
                $password = rawurldecode($parts['pass']);
                $username = rawurldecode((string) ($parts['user'] ?? ''));
                $authenticated = $username !== ''
                    ? $redis->auth([$username, $password])
                    : $redis->auth($password);
                if (!$authenticated) {
                    throw new RuntimeException('Redis authentication failed.');
                }
            }
            if (isset($parts['path']) && trim($parts['path'], '/') !== '') {
                if (!$redis->select((int) trim($parts['path'], '/'))) {
                    throw new RuntimeException('Redis database selection failed.');
                }
            }
            return $redis;
        } catch (Throwable $error) {
            $this->logRedisFallback('Redis could not be initialized (' . get_class($error) . ').');
            return null;
        }
    }

    private function logRedisFallback($reason)
    {
        if (!self::$redisFallbackLogged) {
            self::$redisFallbackLogged = true;
            $this->logAttendanceFailure('Attendance Redis rate limiter unavailable; using the MySQL counter store. ' . $reason);
        }
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
