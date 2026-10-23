<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SmartTrack_model
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function rows($sql, array $values = [])
    {
        return $this->db->raw($sql, $values)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function row($sql, array $values = [])
    {
        $record = $this->db->raw($sql, $values)->fetch(PDO::FETCH_ASSOC);
        return $record ?: null;
    }

    public function execute($sql, array $values = [])
    {
        return $this->db->raw($sql, $values)->rowCount();
    }

    public function userByUsername($username)
    {
        return $this->row(
            'SELECT user_id, full_name, username, password, role, status FROM users WHERE username = ? LIMIT 1',
            [$username]
        );
    }

    public function userCount()
    {
        $record = $this->row('SELECT COUNT(*) AS total FROM users');
        return (int) ($record['total'] ?? 0);
    }

    public function createInitialAdmin($name, $username, $password)
    {
        $this->execute(
            'INSERT INTO users (full_name, username, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$name, $username, password_hash($password, PASSWORD_DEFAULT), 'administrator', 'active']
        );
    }

    public function createUser($name, $username, $password, $role)
    {
        $this->execute(
            'INSERT INTO users (full_name, username, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$name, $username, password_hash($password, PASSWORD_DEFAULT), $role, 'active']
        );
    }

    public function dashboardStats(array $user)
    {
        if ($user['role'] === 'trainee') {
            $trainee = $this->traineeForUser($user['user_id']);
            if (!$trainee) {
                return ['trainees' => 0, 'programs' => 0, 'present_today' => 0, 'follow_up' => 0];
            }
            return [
                'trainees' => 1,
                'programs' => (int) ($this->row('SELECT COUNT(DISTINCT program_id) AS total FROM trainees WHERE user_id = ?', [(int) $user['user_id']])['total'] ?? 0),
                'present_today' => (int) ($this->row("SELECT COUNT(*) AS total FROM attendance_records WHERE trainee_id = ? AND attendance_date = CURDATE() AND status IN ('Present', 'Late')", [(int) $trainee['trainee_id']])['total'] ?? 0),
                'follow_up' => (int) ($this->row("SELECT COUNT(*) AS total FROM attendance_records WHERE trainee_id = ? AND status = 'Absent' AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)", [(int) $trainee['trainee_id']])['total'] ?? 0),
            ];
        }
        if ($user['role'] === 'instructor') {
            $id = (int) $user['user_id'];
            return [
                'trainees' => (int) ($this->row("SELECT COUNT(DISTINCT t.trainee_id) AS total FROM trainees t JOIN class_enrollments e ON e.trainee_id = t.trainee_id JOIN training_classes c ON c.class_id = e.class_id WHERE c.instructor_id = ? AND t.status = 'active'", [$id])['total'] ?? 0),
                'programs' => (int) ($this->row("SELECT COUNT(DISTINCT program_id) AS total FROM training_classes WHERE instructor_id = ? AND status = 'active'", [$id])['total'] ?? 0),
                'present_today' => (int) ($this->row("SELECT COUNT(*) AS total FROM attendance_records a JOIN training_classes c ON c.class_id = a.class_id WHERE c.instructor_id = ? AND a.attendance_date = CURDATE() AND a.status IN ('Present', 'Late')", [$id])['total'] ?? 0),
                'follow_up' => (int) ($this->row("SELECT COUNT(*) AS total FROM attendance_records a JOIN training_classes c ON c.class_id = a.class_id WHERE c.instructor_id = ? AND a.status = 'Absent' AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)", [$id])['total'] ?? 0),
            ];
        }
        return [
            'trainees' => (int) ($this->row("SELECT COUNT(*) AS total FROM trainees WHERE status = 'active'")['total'] ?? 0),
            'programs' => (int) ($this->row("SELECT COUNT(*) AS total FROM training_programs WHERE status = 'active'")['total'] ?? 0),
            'present_today' => (int) ($this->row("SELECT COUNT(*) AS total FROM attendance_records WHERE attendance_date = CURDATE() AND status IN ('Present', 'Late')")['total'] ?? 0),
            'follow_up' => (int) ($this->row("SELECT COUNT(*) AS total FROM attendance_records WHERE status = 'Absent' AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")['total'] ?? 0),
        ];
    }

    public function recentAttendance(array $user)
    {
        $sql = 'SELECT a.attendance_date, a.time_in, a.time_out, a.status, t.first_name, t.last_name, p.program_name '
            . 'FROM attendance_records a JOIN trainees t ON t.trainee_id = a.trainee_id '
            . 'LEFT JOIN training_programs p ON p.program_id = t.program_id';
        $values = [];
        if ($user['role'] === 'trainee') {
            $sql .= ' WHERE t.user_id = ?';
            $values[] = (int) $user['user_id'];
        } elseif ($user['role'] === 'instructor') {
            $sql .= ' JOIN training_classes c ON c.class_id = a.class_id WHERE c.instructor_id = ?';
            $values[] = (int) $user['user_id'];
        }
        return $this->rows($sql . ' ORDER BY a.attendance_date DESC, a.time_in DESC LIMIT 8', $values);
    }

    public function announcements()
    {
        return $this->rows(
            "SELECT announcement_id, title, content, created_at FROM announcements WHERE status = 'published' ORDER BY created_at DESC LIMIT 5"
        );
    }

    public function records($section, array $user)
    {
        $userId = (int) $user['user_id'];
        $role = $user['role'];
        $queries = [
            'users' => 'SELECT user_id, full_name, username, role, status, created_at FROM users',
            'trainees' => 'SELECT t.trainee_id, t.first_name, t.last_name, t.sex, t.date_of_birth, t.address, t.email, t.contact_number, t.course, t.status, t.qr_code, t.user_id, t.program_id, p.program_name FROM trainees t LEFT JOIN training_programs p ON p.program_id = t.program_id',
            'programs' => 'SELECT program_id, program_code, program_name, description, duration_hours, status FROM training_programs',
            'classes' => 'SELECT c.class_id, c.class_name, c.program_id, p.program_name, c.instructor_id, u.full_name AS instructor_name, c.start_date, c.end_date, c.status FROM training_classes c JOIN training_programs p ON p.program_id = c.program_id LEFT JOIN users u ON u.user_id = c.instructor_id',
            'schedules' => 'SELECT s.schedule_id, s.class_id, c.class_name, s.schedule_date, s.start_time, s.end_time FROM training_schedules s LEFT JOIN training_classes c ON c.class_id = s.class_id',
            'enrollments' => 'SELECT e.enrollment_id, e.class_id, c.class_name, e.trainee_id, CONCAT(t.first_name, \' \', t.last_name) AS trainee_name, e.enrolled_at FROM class_enrollments e JOIN training_classes c ON c.class_id = e.class_id JOIN trainees t ON t.trainee_id = e.trainee_id',
            'attendance' => 'SELECT a.attendance_id, a.trainee_id, CONCAT(t.first_name, \' \', t.last_name) AS trainee_name, a.class_id, c.class_name, a.schedule_id, a.attendance_date, a.time_in, a.time_out, a.status, a.remarks FROM attendance_records a JOIN trainees t ON t.trainee_id = a.trainee_id LEFT JOIN training_classes c ON c.class_id = a.class_id',
            'documents' => 'SELECT d.document_id, d.trainee_id, d.class_id, COALESCE(CONCAT(t.first_name, \' \', t.last_name), \'Institution\') AS trainee_name, d.document_name, d.upload_date, d.status, d.access_level FROM trainee_documents d LEFT JOIN trainees t ON t.trainee_id = d.trainee_id',
            'announcements' => 'SELECT a.announcement_id, a.title, a.content, a.status, a.created_at, u.full_name AS author FROM announcements a LEFT JOIN users u ON u.user_id = a.user_id',
            'inquiries' => 'SELECT i.inquiry_id, i.trainee_id, CONCAT(t.first_name, \' \', t.last_name) AS trainee_name, i.subject, i.message, i.reply, i.status, i.created_at FROM inquiries i JOIN trainees t ON t.trainee_id = i.trainee_id',
            'activity' => 'SELECT l.log_id, l.user_id, COALESCE(u.full_name, \'System\') AS user_name, l.action, l.details, l.created_at FROM activity_logs l LEFT JOIN users u ON u.user_id = l.user_id LIMIT 200',
        ];

        if (!isset($queries[$section])) {
            return [];
        }

        $sql = $queries[$section];
        if ($section === 'trainees' && $role === 'trainee') {
            return $this->rows($sql . ' WHERE t.user_id = ? LIMIT 1', [$userId]);
        }
        if ($section === 'trainees' && $role === 'instructor') {
            return $this->rows(
                'SELECT DISTINCT t.trainee_id, t.first_name, t.last_name, t.sex, t.contact_number, t.course, t.status, t.qr_code, t.user_id, t.program_id, p.program_name FROM trainees t JOIN class_enrollments e ON e.trainee_id = t.trainee_id JOIN training_classes c ON c.class_id = e.class_id LEFT JOIN training_programs p ON p.program_id = t.program_id WHERE c.instructor_id = ? ORDER BY t.last_name, t.first_name',
                [$userId]
            );
        }
        if ($section === 'classes' && $role === 'instructor') {
            return $this->rows($sql . ' WHERE c.instructor_id = ?', [$userId]);
        }
        if ($section === 'classes' && $role === 'trainee') {
            return $this->rows($sql . ' JOIN class_enrollments e ON e.class_id = c.class_id WHERE e.trainee_id = (SELECT trainee_id FROM trainees WHERE user_id = ? LIMIT 1)', [$userId]);
        }
        if ($section === 'attendance' && $role === 'trainee') {
            return $this->rows($sql . ' WHERE t.user_id = ?', [$userId]);
        }
        if ($section === 'attendance' && $role === 'instructor') {
            return $this->rows($sql . ' JOIN training_classes owned_class ON owned_class.class_id = a.class_id WHERE owned_class.instructor_id = ?', [$userId]);
        }
        if ($section === 'documents' && $role === 'trainee') {
            return $this->rows($sql . " WHERE d.access_level <> 'staff' AND (t.user_id = ? OR (d.access_level = 'class' AND d.class_id IN (SELECT e.class_id FROM class_enrollments e JOIN trainees mine ON mine.trainee_id = e.trainee_id WHERE mine.user_id = ?)))", [$userId, $userId]);
        }
        if ($section === 'documents' && $role === 'instructor') {
            return $this->rows($sql . " JOIN training_classes c ON c.class_id = d.class_id WHERE d.access_level = 'class' AND c.instructor_id = ?", [$userId]);
        }
        if ($section === 'announcements' && $role !== 'administrator' && $role !== 'staff') {
            return $this->rows("SELECT announcement_id, title, content, status, created_at, NULL AS author FROM announcements WHERE status = 'published' ORDER BY created_at DESC");
        }
        if ($section === 'inquiries' && $role === 'trainee') {
            return $this->rows($sql . ' WHERE t.user_id = ?', [$userId]);
        }
        if ($section === 'schedules' && $role === 'instructor') {
            return $this->rows($sql . ' JOIN training_classes owned_class ON owned_class.class_id = s.class_id WHERE owned_class.instructor_id = ?', [$userId]);
        }
        if ($section === 'enrollments' && $role === 'instructor') {
            return $this->rows($sql . ' WHERE c.instructor_id = ?', [$userId]);
        }
        return $this->rows($sql);
    }

    public function options($section, $user = null)
    {
        $queries = [
            'programs' => 'SELECT program_id AS id, program_name AS label FROM training_programs WHERE status = \'active\' ORDER BY program_name',
            'trainees' => "SELECT trainee_id AS id, CONCAT(first_name, ' ', last_name) AS label FROM trainees WHERE status = 'active' ORDER BY last_name, first_name",
            'classes' => 'SELECT class_id AS id, class_name AS label FROM training_classes WHERE status = \'active\' ORDER BY class_name',
            'instructors' => "SELECT user_id AS id, full_name AS label FROM users WHERE role = 'instructor' AND status = 'active' ORDER BY full_name",
            'schedules' => "SELECT schedule_id AS id, CONCAT(schedule_date, ' - ', start_time) AS label FROM training_schedules ORDER BY schedule_date DESC",
            'user_accounts' => "SELECT user_id AS id, CONCAT(full_name, ' (', username, ')') AS label FROM users WHERE role = 'trainee' AND status = 'active' ORDER BY full_name",
        ];
        if (!isset($queries[$section])) {
            return [];
        }
        if ($user && $user['role'] === 'instructor' && $section === 'classes') {
            return $this->rows('SELECT class_id AS id, class_name AS label FROM training_classes WHERE status = \'active\' AND instructor_id = ? ORDER BY class_name', [(int) $user['user_id']]);
        }
        if ($user && $user['role'] === 'instructor' && $section === 'trainees') {
            return $this->rows("SELECT DISTINCT t.trainee_id AS id, CONCAT(t.first_name, ' ', t.last_name) AS label FROM trainees t JOIN class_enrollments e ON e.trainee_id = t.trainee_id JOIN training_classes c ON c.class_id = e.class_id WHERE t.status = 'active' AND c.instructor_id = ? ORDER BY label", [(int) $user['user_id']]);
        }
        if ($user && $user['role'] === 'instructor' && $section === 'schedules') {
            return $this->rows("SELECT s.schedule_id AS id, CONCAT(s.schedule_date, ' - ', s.start_time) AS label FROM training_schedules s JOIN training_classes c ON c.class_id = s.class_id WHERE c.instructor_id = ? ORDER BY s.schedule_date DESC", [(int) $user['user_id']]);
        }
        if ($user && $user['role'] === 'trainee' && $section === 'trainees') {
            return $this->rows("SELECT trainee_id AS id, CONCAT(first_name, ' ', last_name) AS label FROM trainees WHERE status = 'active' AND user_id = ?", [(int) $user['user_id']]);
        }
        if ($user && $user['role'] === 'trainee' && $section === 'classes') {
            return $this->rows('SELECT c.class_id AS id, c.class_name AS label FROM training_classes c JOIN class_enrollments e ON e.class_id = c.class_id JOIN trainees t ON t.trainee_id = e.trainee_id WHERE t.user_id = ?', [(int) $user['user_id']]);
        }
        if ($user && $user['role'] === 'trainee' && $section === 'schedules') {
            return $this->rows("SELECT s.schedule_id AS id, CONCAT(s.schedule_date, ' - ', s.start_time) AS label FROM training_schedules s JOIN class_enrollments e ON e.class_id = s.class_id JOIN trainees t ON t.trainee_id = e.trainee_id WHERE t.user_id = ? ORDER BY s.schedule_date DESC", [(int) $user['user_id']]);
        }
        return $this->rows($queries[$section]);
    }

    public function saveRecord($table, $primaryKey, array $data, $id = null)
    {
        if (isset($data['password']) && $data['password'] !== '') {
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        } elseif (isset($data['password'])) {
            unset($data['password']);
        }

        if (!$data) {
            return $id;
        }
        $columns = array_keys($data);
        if ($id) {
            $sets = implode(', ', array_map(static function ($column) {
                return '`' . $column . '` = ?';
            }, $columns));
            $this->execute("UPDATE `{$table}` SET {$sets} WHERE `{$primaryKey}` = ?", array_merge(array_values($data), [(int) $id]));
            return (int) $id;
        }

        $quoted = implode(', ', array_map(static function ($column) {
            return '`' . $column . '`';
        }, $columns));
        $marks = implode(', ', array_fill(0, count($columns), '?'));
        $this->execute("INSERT INTO `{$table}` ({$quoted}) VALUES ({$marks})", array_values($data));
        return (int) $this->row('SELECT LAST_INSERT_ID() AS id')['id'];
    }

    public function findRecord($table, $primaryKey, $id)
    {
        return $this->row("SELECT * FROM `{$table}` WHERE `{$primaryKey}` = ? LIMIT 1", [(int) $id]);
    }

    public function deleteRecord($table, $primaryKey, $id, $deactivate = false)
    {
        if ($deactivate) {
            return $this->execute("UPDATE `{$table}` SET status = 'inactive' WHERE `{$primaryKey}` = ?", [(int) $id]);
        }
        return $this->execute("DELETE FROM `{$table}` WHERE `{$primaryKey}` = ?", [(int) $id]);
    }

    public function logActivity($userId, $action, $details)
    {
        $this->execute('INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)', [$userId, $action, $details]);
    }

    public function attendanceSummary($traineeId)
    {
        return $this->row("SELECT SUM(status = 'Present') AS present, SUM(status = 'Late') AS late, SUM(status = 'Absent') AS absent FROM attendance_records WHERE trainee_id = ?", [(int) $traineeId]) ?: [];
    }

    public function absenceFollowUps(array $user)
    {
        $sql = "SELECT a.attendance_date, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, c.class_name, a.remarks FROM attendance_records a JOIN trainees t ON t.trainee_id = a.trainee_id LEFT JOIN training_classes c ON c.class_id = a.class_id WHERE a.status = 'Absent' AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $values = [];
        if ($user['role'] === 'instructor') {
            $sql .= ' AND c.instructor_id = ?';
            $values[] = (int) $user['user_id'];
        } elseif ($user['role'] === 'trainee') {
            $sql .= ' AND t.user_id = ?';
            $values[] = (int) $user['user_id'];
        }
        return $this->rows($sql . ' ORDER BY a.attendance_date DESC, t.last_name, t.first_name', $values);
    }

    public function traineeForUser($userId)
    {
        return $this->row('SELECT * FROM trainees WHERE user_id = ? LIMIT 1', [(int) $userId]);
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

    public function dashboardChartData(array $user)
    {
        $isAdminOrStaff = in_array($user['role'], ['administrator', 'staff'], true);
        $isInstructor = $user['role'] === 'instructor';

        // Weekly attendance trend (last 7 days)
        $weeklyData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $dateLabel = date('M j', strtotime("-$i days"));

            $sql = "SELECT COUNT(*) AS total FROM attendance_records WHERE attendance_date = ?";
            $values = [$date];

            if ($isInstructor) {
                $sql .= " AND class_id IN (SELECT class_id FROM training_classes WHERE instructor_id = ?)";
                $values[] = (int) $user['user_id'];
            }

            $total = (int) ($this->row($sql, $values)['total'] ?? 0);

            $weeklyData[] = [
                'date' => $dateLabel,
                'total' => $total
            ];
        }

        // Attendance by status (last 30 days)
        $statusSql = "SELECT status, COUNT(*) AS total FROM attendance_records WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $statusValues = [];

        if ($isInstructor) {
            $statusSql .= " AND class_id IN (SELECT class_id FROM training_classes WHERE instructor_id = ?)";
            $statusValues[] = (int) $user['user_id'];
        }

        $statusSql .= " GROUP BY status";
        $statusRows = $this->rows($statusSql, $statusValues);
        $statusData = [
            'Present' => 0,
            'Late' => 0,
            'Absent' => 0
        ];

        foreach ($statusRows as $row) {
            if (isset($statusData[$row['status']])) {
                $statusData[$row['status']] = (int) $row['total'];
            }
        }

        // Program-level statistics (admin/staff only)
        $programData = [];
        if ($isAdminOrStaff) {
            $programRows = $this->rows(
                "SELECT p.program_id, p.program_name, p.program_code,
                        COUNT(DISTINCT t.trainee_id) AS trainee_count,
                        COUNT(DISTINCT a.attendance_id) AS attendance_count
                 FROM training_programs p
                 LEFT JOIN trainees t ON t.program_id = p.program_id AND t.status = 'active'
                 LEFT JOIN attendance_records a ON t.trainee_id = a.trainee_id
                 WHERE p.status = 'active'
                 GROUP BY p.program_id, p.program_name, p.program_code
                 ORDER BY attendance_count DESC"
            );

            foreach ($programRows as $row) {
                $programData[] = [
                    'name' => $row['program_name'],
                    'code' => $row['program_code'],
                    'trainees' => (int) $row['trainee_count'],
                    'attendance' => (int) $row['attendance_count']
                ];
            }
        }

        // Late trainees this week
        $lateSql = "SELECT COUNT(*) AS total FROM attendance_records WHERE status = 'Late' AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $lateValues = [];

        if ($isInstructor) {
            $lateSql .= " AND class_id IN (SELECT class_id FROM training_classes WHERE instructor_id = ?)";
            $lateValues[] = (int) $user['user_id'];
        }

        $lateCount = (int) ($this->row($lateSql, $lateValues)['total'] ?? 0);

        // Absences this week
        $absentSql = "SELECT COUNT(*) AS total FROM attendance_records WHERE status = 'Absent' AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $absentValues = [];

        if ($isInstructor) {
            $absentSql .= " AND class_id IN (SELECT class_id FROM training_classes WHERE instructor_id = ?)";
            $absentValues[] = (int) $user['user_id'];
        }

        $absentCount = (int) ($this->row($absentSql, $absentValues)['total'] ?? 0);

        return [
            'weekly' => $weeklyData,
            'status' => $statusData,
            'programs' => $programData,
            'late_this_week' => $lateCount,
            'absent_this_week' => $absentCount
        ];
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

    public function documentById($id)
    {
        return $this->row('SELECT d.*, t.user_id AS trainee_user_id FROM trainee_documents d LEFT JOIN trainees t ON t.trainee_id = d.trainee_id WHERE d.document_id = ? LIMIT 1', [(int) $id]);
    }

    public function documentStats(array $user)
    {
        $isAdminOrStaff = in_array($user['role'], ['administrator', 'staff'], true);

        if ($isAdminOrStaff) {
            return [
                'total' => (int) ($this->row("SELECT COUNT(*) AS total FROM trainee_documents")['total'] ?? 0),
                'active' => (int) ($this->row("SELECT COUNT(*) AS total FROM trainee_documents WHERE status = 'active'")['total'] ?? 0),
                'archived' => (int) ($this->row("SELECT COUNT(*) AS total FROM trainee_documents WHERE status = 'archived'")['total'] ?? 0),
                'private' => (int) ($this->row("SELECT COUNT(*) AS total FROM trainee_documents WHERE access_level = 'private'")['total'] ?? 0),
                'class' => (int) ($this->row("SELECT COUNT(*) AS total FROM trainee_documents WHERE access_level = 'class'")['total'] ?? 0),
                'staff' => (int) ($this->row("SELECT COUNT(*) AS total FROM trainee_documents WHERE access_level = 'staff'")['total'] ?? 0),
            ];
        }

        if ($user['role'] === 'instructor') {
            return [
                'total' => (int) ($this->row("SELECT COUNT(*) AS total FROM trainee_documents d JOIN training_classes c ON c.class_id = d.class_id WHERE c.instructor_id = ?", [(int) $user['user_id']])['total'] ?? 0),
                'active' => (int) ($this->row("SELECT COUNT(*) AS total FROM trainee_documents d JOIN training_classes c ON c.class_id = d.class_id WHERE c.instructor_id = ? AND d.status = 'active'", [(int) $user['user_id']])['total'] ?? 0),
            ];
        }

        return ['total' => 0, 'active' => 0];
    }

    public function recentDocuments(array $user, $limit = 10)
    {
        $sql = 'SELECT d.*, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, c.class_name, u.full_name AS uploader_name FROM trainee_documents d LEFT JOIN trainees t ON t.trainee_id = d.trainee_id LEFT JOIN training_classes c ON c.class_id = d.class_id LEFT JOIN users u ON u.user_id = d.uploaded_by';
        $values = [];

        if ($user['role'] === 'trainee') {
            $sql .= ' WHERE t.user_id = ?';
            $values[] = (int) $user['user_id'];
        } elseif ($user['role'] === 'instructor') {
            $sql .= ' WHERE d.access_level = "class" AND c.instructor_id = ?';
            $values[] = (int) $user['user_id'];
        }

        return $this->rows($sql . ' ORDER BY d.upload_date DESC LIMIT ' . (int) $limit, $values);
    }

    public function filterDocuments(array $user, array $filters = [])
    {
        $sql = 'SELECT d.*, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, c.class_name, u.full_name AS uploader_name FROM trainee_documents d LEFT JOIN trainees t ON t.trainee_id = d.trainee_id LEFT JOIN training_classes c ON c.class_id = d.class_id LEFT JOIN users u ON u.user_id = d.uploaded_by';
        $values = [];
        $whereClauses = [];

        // Role-based filtering
        if ($user['role'] === 'trainee') {
            $whereClauses[] = "d.access_level <> 'staff' AND (t.user_id = ? OR (d.access_level = 'class' AND d.class_id IN (SELECT e.class_id FROM class_enrollments e JOIN trainees mine ON mine.trainee_id = e.trainee_id WHERE mine.user_id = ?)))";
            $values[] = (int) $user['user_id'];
            $values[] = (int) $user['user_id'];
        } elseif ($user['role'] === 'instructor') {
            $whereClauses[] = 'd.access_level = "class"';
            $whereClauses[] = 'c.instructor_id = ?';
            $values[] = (int) $user['user_id'];
        }

        // Search by document name or trainee name
        if (!empty($filters['search'])) {
            $whereClauses[] = '(d.document_name LIKE ? OR CONCAT(t.first_name, " ", t.last_name) LIKE ?)';
            $searchTerm = '%' . $filters['search'] . '%';
            $values[] = $searchTerm;
            $values[] = $searchTerm;
        }

        // Filter by document type (file extension)
        if (!empty($filters['document_type'])) {
            $whereClauses[] = 'd.file_path LIKE ?';
            $values[] = '%' . $filters['document_type'];
        }

        // Filter by class
        if (!empty($filters['class_id'])) {
            $whereClauses[] = 'd.class_id = ?';
            $values[] = (int) $filters['class_id'];
        }

        // Filter by access level
        if (!empty($filters['access_level'])) {
            $whereClauses[] = 'd.access_level = ?';
            $values[] = $filters['access_level'];
        }

        // Filter by status
        if (!empty($filters['status'])) {
            $whereClauses[] = 'd.status = ?';
            $values[] = $filters['status'];
        }

        // Filter by upload date range
        if (!empty($filters['date_from'])) {
            $whereClauses[] = 'd.upload_date >= ?';
            $values[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $whereClauses[] = 'd.upload_date <= ?';
            $values[] = $filters['date_to'];
        }

        // Add WHERE clauses
        if (!empty($whereClauses)) {
            $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
        }

        // Sorting
        $sort = $filters['sort'] ?? 'newest';
        $sql .= ' ORDER BY d.upload_date ' . ($sort === 'oldest' ? 'ASC' : 'DESC');

        return $this->rows($sql, $values);
    }

    public function certificateStats()
    {
        $eligible = $this->row('SELECT COUNT(*) as count FROM certificate_eligibility WHERE is_eligible = 1');
        $pending = $this->row("SELECT COUNT(*) AS count FROM certificate_eligibility WHERE is_eligible = 1 AND approval_status = 'pending'");
        $issued = $this->row('SELECT COUNT(*) as count FROM certificates WHERE status = "issued"');
        $revoked = $this->row('SELECT COUNT(*) as count FROM certificates WHERE status = "revoked"');

        return [
            'eligible' => (int) ($eligible['count'] ?? 0),
            'pending' => (int) ($pending['count'] ?? 0),
            'issued' => (int) ($issued['count'] ?? 0),
            'revoked' => (int) ($revoked['count'] ?? 0),
        ];
    }

    public function getEligibilityRecords(array $filters = [])
    {
        $sql = 'SELECT e.*, cert.certificate_id, cert.status AS certificate_status, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, t.qr_code AS trainee_number, p.program_name, c.class_name, u.full_name AS reviewer_name, approver.full_name AS approver_name FROM certificate_eligibility e JOIN trainees t ON t.trainee_id = e.trainee_id JOIN training_classes c ON c.class_id = e.class_id JOIN training_programs p ON p.program_id = e.program_id LEFT JOIN certificates cert ON cert.trainee_id = e.trainee_id AND cert.class_id = e.class_id LEFT JOIN users u ON u.user_id = e.reviewed_by LEFT JOIN users approver ON approver.user_id = e.approved_by';
        $values = [];
        $whereClauses = [];

        if (!empty($filters['search'])) {
            $whereClauses[] = '(t.first_name LIKE ? OR t.last_name LIKE ? OR t.qr_code LIKE ? OR p.program_name LIKE ?)';
            $searchTerm = '%' . $filters['search'] . '%';
            $values[] = $searchTerm;
            $values[] = $searchTerm;
            $values[] = $searchTerm;
            $values[] = $searchTerm;
        }

        if (isset($filters['eligibility']) && $filters['eligibility'] !== '') {
            $whereClauses[] = 'e.is_eligible = ?';
            $values[] = (int) $filters['eligibility'];
        }

        if (!empty($filters['program_id'])) {
            $whereClauses[] = 'e.program_id = ?';
            $values[] = (int) $filters['program_id'];
        }

        if (!empty($whereClauses)) {
            $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
        }

        $sql .= ' ORDER BY e.updated_at DESC';

        return $this->rows($sql, $values);
    }

    public function deleteEligibilityEnrollment($eligibilityId, $actorId)
    {
        $eligibility = $this->row('SELECT trainee_id, class_id FROM certificate_eligibility WHERE eligibility_id = ?', [(int) $eligibilityId]);
        if (!$eligibility) {
            return ['success' => false, 'message' => 'Eligibility record not found.'];
        }
        if ($this->row('SELECT certificate_id FROM certificates WHERE trainee_id = ? AND class_id = ?', [
            (int) $eligibility['trainee_id'],
            (int) $eligibility['class_id'],
        ])) {
            return ['success' => false, 'message' => 'Enrollment cannot be removed because a certificate has already been issued or revoked.'];
        }

        $enrollment = $this->row('SELECT enrollment_id FROM class_enrollments WHERE trainee_id = ? AND class_id = ?', [
            (int) $eligibility['trainee_id'],
            (int) $eligibility['class_id'],
        ]);
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
                $this->execute('DELETE FROM class_enrollments WHERE enrollment_id = ?', [(int) $enrollment['enrollment_id']]);
            }
            $this->execute('DELETE FROM certificate_eligibility WHERE eligibility_id = ?', [(int) $eligibilityId]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        return ['success' => true, 'message' => $message];
    }

    public function calculateEligibility($traineeId, $classId)
    {
        $minAttendance = (float) $this->getConfig('min_attendance_percentage', 75);
        $requireDocuments = (bool) $this->getConfig('require_documents', 1);
        $requireAssessments = (bool) $this->getConfig('require_assessments', 0);
        $requiredAssessments = max(1, (int) $this->getConfig('required_assessment_count', 1));

        $class = $this->row('SELECT c.*, p.program_id FROM training_classes c JOIN training_programs p ON p.program_id = c.program_id WHERE c.class_id = ?', [(int) $classId]);
        if (!$class) {
            return ['eligible' => false, 'reasons' => ['Class not found']];
        }

        $reasons = [];
        if (empty($class['end_date']) || $class['end_date'] > date('Y-m-d')) {
            $reasons[] = 'The class has not reached its completion date';
        }

        $enrollment = $this->row('SELECT * FROM class_enrollments WHERE trainee_id = ? AND class_id = ?', [(int) $traineeId, (int) $classId]);
        if (!$enrollment) {
            $reasons[] = 'Not enrolled in this class';
        }

        $attendance = $this->row("SELECT COUNT(*) AS total, SUM(CASE WHEN status IN ('Present', 'Late') THEN 1 ELSE 0 END) AS attended FROM attendance_records WHERE trainee_id = ? AND class_id = ?", [(int) $traineeId, (int) $classId]);
        $attendancePercentage = $attendance && (int) $attendance['total'] > 0
            ? ((int) $attendance['attended'] / (int) $attendance['total']) * 100
            : 0;
        if ($attendancePercentage < $minAttendance) {
            $reasons[] = sprintf('Attendance (%.1f%%) below minimum (%.0f%%)', $attendancePercentage, $minAttendance);
        }

        $requirements = $this->rows('SELECT required_document_name FROM certificate_document_requirements WHERE class_id = ? ORDER BY required_document_name', [(int) $classId]);
        $submittedDocuments = $this->rows('SELECT document_name FROM trainee_documents WHERE trainee_id = ? AND class_id = ? AND status = "active"', [(int) $traineeId, (int) $classId]);
        $submittedNames = [];
        foreach ($submittedDocuments as $document) {
            $submittedNames[mb_strtolower(trim($document['document_name']))] = true;
        }
        $approvedDocuments = 0;
        $missingDocuments = [];
        foreach ($requirements as $requirement) {
            if (isset($submittedNames[mb_strtolower(trim($requirement['required_document_name']))])) {
                $approvedDocuments++;
            } else {
                $missingDocuments[] = $requirement['required_document_name'];
            }
        }

        if ($requireDocuments) {
            if (!$requirements) {
                $reasons[] = 'No required-document checklist is configured for this class';
            } elseif ($missingDocuments) {
                $reasons[] = 'Missing approved documents: ' . implode(', ', $missingDocuments);
            }
        }

        $assessmentCounts = $this->row("SELECT COUNT(*) AS total, SUM(CASE WHEN outcome = 'pass' THEN 1 ELSE 0 END) AS passed FROM training_assessments WHERE trainee_id = ? AND class_id = ?", [(int) $traineeId, (int) $classId]);
        $assessmentsPassed = (int) ($assessmentCounts['passed'] ?? 0);
        if ($requireAssessments) {
            if ($assessmentsPassed < $requiredAssessments) {
                $reasons[] = sprintf('Passed assessments (%d/%d) do not meet the requirement', $assessmentsPassed, $requiredAssessments);
            }
        }

        $existing = $this->row('SELECT certificate_id FROM certificates WHERE trainee_id = ? AND class_id = ?', [(int) $traineeId, (int) $classId]);
        if ($existing) {
            $reasons[] = 'A certificate record already exists for this class';
        }

        $isEligible = empty($reasons);
        $reasonText = implode('; ', $reasons);
        $check = $this->row('SELECT eligibility_id, program_id, attendance_percentage, documents_submitted, documents_required, documents_approved, assessments_completed, assessments_required, is_eligible, ineligibility_reasons FROM certificate_eligibility WHERE trainee_id = ? AND class_id = ?', [(int) $traineeId, (int) $classId]);
        $documentsSubmitted = count($submittedDocuments);
        $documentsRequired = count($requirements);
        $eligibilityChanged = !$check
            || (int) $check['program_id'] !== (int) $class['program_id']
            || (float) $check['attendance_percentage'] !== round($attendancePercentage, 2)
            || (int) $check['documents_submitted'] !== $documentsSubmitted
            || (int) $check['documents_required'] !== $documentsRequired
            || (int) $check['documents_approved'] !== $approvedDocuments
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
                $approvedDocuments,
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
            $this->execute('INSERT INTO certificate_eligibility (trainee_id, class_id, program_id, attendance_percentage, documents_submitted, documents_required, documents_approved, assessments_completed, assessments_required, is_eligible, ineligibility_reasons) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                (int) $traineeId,
                (int) $classId,
                (int) $class['program_id'],
                round($attendancePercentage, 2),
                $documentsSubmitted,
                $documentsRequired,
                $approvedDocuments,
                $assessmentsPassed,
                $requireAssessments ? $requiredAssessments : 0,
                $isEligible ? 1 : 0,
                $reasonText
            ]);
        }

        return [
            'eligible' => $isEligible,
            'reasons' => $reasons,
            'attendance_percentage' => $attendancePercentage,
            'documents_submitted' => $documentsSubmitted,
            'documents_required' => $documentsRequired,
            'documents_approved' => $approvedDocuments,
            'assessments_passed' => $assessmentsPassed,
            'assessments_required' => $requireAssessments ? $requiredAssessments : 0,
        ];
    }

    public function refreshEligibilityRecords()
    {
        $enrollments = $this->rows('SELECT trainee_id, class_id FROM class_enrollments');
        foreach ($enrollments as $enrollment) {
            $this->calculateEligibility((int) $enrollment['trainee_id'], (int) $enrollment['class_id']);
        }
    }

    public function recalculateClassEligibility($classId)
    {
        $enrollments = $this->rows('SELECT trainee_id FROM class_enrollments WHERE class_id = ?', [(int) $classId]);
        foreach ($enrollments as $enrollment) {
            $this->calculateEligibility((int) $enrollment['trainee_id'], (int) $classId);
        }
    }

    public function assessmentRecords()
    {
        return $this->rows('SELECT a.*, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, t.qr_code AS trainee_number, c.class_name, p.program_name, u.full_name AS assessor_name FROM training_assessments a JOIN trainees t ON t.trainee_id = a.trainee_id JOIN training_classes c ON c.class_id = a.class_id JOIN training_programs p ON p.program_id = c.program_id JOIN users u ON u.user_id = a.assessed_by ORDER BY a.assessment_date DESC, a.assessment_id DESC');
    }

    public function assessmentById($assessmentId)
    {
        return $this->row('SELECT * FROM training_assessments WHERE assessment_id = ?', [(int) $assessmentId]);
    }

    public function saveAssessment(array $assessment)
    {
        if (!empty($assessment['assessment_id'])) {
            $this->execute('UPDATE training_assessments SET trainee_id = ?, class_id = ?, assessment_name = ?, outcome = ?, assessed_by = ?, assessment_date = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE assessment_id = ?', [
                (int) $assessment['trainee_id'],
                (int) $assessment['class_id'],
                $assessment['assessment_name'],
                $assessment['outcome'],
                (int) $assessment['assessed_by'],
                $assessment['assessment_date'],
                $assessment['notes'],
                (int) $assessment['assessment_id'],
            ]);
            return (int) $assessment['assessment_id'];
        }

        $existing = $this->row('SELECT assessment_id FROM training_assessments WHERE trainee_id = ? AND class_id = ? AND assessment_name = ?', [
            (int) $assessment['trainee_id'],
            (int) $assessment['class_id'],
            $assessment['assessment_name'],
        ]);

        if ($existing) {
            $this->execute('UPDATE training_assessments SET outcome = ?, assessed_by = ?, assessment_date = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE assessment_id = ?', [
                $assessment['outcome'],
                (int) $assessment['assessed_by'],
                $assessment['assessment_date'],
                $assessment['notes'],
                (int) $existing['assessment_id'],
            ]);
            return (int) $existing['assessment_id'];
        }

        $this->execute('INSERT INTO training_assessments (trainee_id, class_id, assessment_name, outcome, assessed_by, assessment_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            (int) $assessment['trainee_id'],
            (int) $assessment['class_id'],
            $assessment['assessment_name'],
            $assessment['outcome'],
            (int) $assessment['assessed_by'],
            $assessment['assessment_date'],
            $assessment['notes'],
        ]);
        return (int) $this->row('SELECT assessment_id FROM training_assessments WHERE trainee_id = ? AND class_id = ? AND assessment_name = ?', [
            (int) $assessment['trainee_id'],
            (int) $assessment['class_id'],
            $assessment['assessment_name'],
        ])['assessment_id'];
    }

    public function deleteAssessment($assessmentId)
    {
        return $this->execute('DELETE FROM training_assessments WHERE assessment_id = ?', [(int) $assessmentId]) > 0;
    }

    public function documentRequirements()
    {
        return $this->rows('SELECT r.*, c.class_name FROM certificate_document_requirements r JOIN training_classes c ON c.class_id = r.class_id ORDER BY c.class_name, r.required_document_name');
    }

    public function addDocumentRequirement($classId, $documentName, $createdBy)
    {
        $this->execute('INSERT INTO certificate_document_requirements (class_id, required_document_name, created_by) VALUES (?, ?, ?)', [
            (int) $classId,
            $documentName,
            (int) $createdBy,
        ]);
        $requirement = $this->row('SELECT requirement_id FROM certificate_document_requirements WHERE class_id = ? AND required_document_name = ?', [(int) $classId, $documentName]);
        return (int) ($requirement['requirement_id'] ?? 0);
    }

    public function deleteDocumentRequirement($requirementId)
    {
        return $this->execute('DELETE FROM certificate_document_requirements WHERE requirement_id = ?', [(int) $requirementId]);
    }

    public function updateEligibilityApproval($eligibilityId, $decision, $actorId, $reason = null)
    {
        $eligibility = $this->row('SELECT is_eligible FROM certificate_eligibility WHERE eligibility_id = ?', [(int) $eligibilityId]);
        if (!$eligibility || ($decision === 'approved' && !(int) $eligibility['is_eligible'])) {
            return false;
        }
        return $this->execute('UPDATE certificate_eligibility SET approval_status = ?, approval_reason = ?, approved_by = ?, approved_at = CURRENT_TIMESTAMP, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE eligibility_id = ?', [
            $decision,
            $reason,
            (int) $actorId,
            (int) $actorId,
            (int) $eligibilityId,
        ]) > 0;
    }

    public function getConfig($key, $default = null)
    {
        $config = $this->row('SELECT config_value FROM certificate_config WHERE config_key = ?', [$key]);
        return $config ? $config['config_value'] : $default;
    }

    public function updateConfig($key, $value, $description = null)
    {
        $check = $this->row('SELECT config_id FROM certificate_config WHERE config_key = ?', [$key]);
        if ($check) {
            $this->execute('UPDATE certificate_config SET config_value = ?, description = ?, updated_at = CURRENT_TIMESTAMP WHERE config_key = ?', [$value, $description, $key]);
        } else {
            $this->execute('INSERT INTO certificate_config (config_key, config_value, description) VALUES (?, ?, ?)', [$key, $value, $description]);
        }
    }

    public function getCertificates(array $filters = [])
    {
        $sql = "SELECT e.*, c.certificate_id, c.certificate_number, c.issue_date, c.revocation_reason, c.revoked_at, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, t.qr_code AS trainee_number, p.program_name, cl.class_name, cl.end_date AS completion_date, CASE WHEN c.certificate_id IS NOT NULL THEN c.status WHEN e.is_eligible = 0 THEN 'ineligible' ELSE e.approval_status END AS status FROM certificate_eligibility e JOIN trainees t ON t.trainee_id = e.trainee_id JOIN training_programs p ON p.program_id = e.program_id JOIN training_classes cl ON cl.class_id = e.class_id LEFT JOIN certificates c ON c.trainee_id = e.trainee_id AND c.class_id = e.class_id";
        $values = [];
        $whereClauses = [];

        if (!empty($filters['search'])) {
            $whereClauses[] = "(CONCAT(t.first_name, ' ', t.last_name) LIKE ? OR t.qr_code LIKE ? OR c.certificate_number LIKE ? OR p.program_name LIKE ? OR cl.class_name LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            for ($i = 0; $i < 5; $i++) {
                $values[] = $searchTerm;
            }
        }

        $status = $filters['status'] ?? '';
        if ($status === 'issued' || $status === 'revoked') {
            $whereClauses[] = 'c.status = ?';
            $values[] = $status;
        } elseif ($status === 'pending') {
            $whereClauses[] = "c.certificate_id IS NULL AND e.is_eligible = 1 AND e.approval_status = 'pending'";
        } elseif ($status === 'approved') {
            $whereClauses[] = "c.certificate_id IS NULL AND e.is_eligible = 1 AND e.approval_status = 'approved'";
        } elseif ($status === 'rejected') {
            $whereClauses[] = "c.certificate_id IS NULL AND e.is_eligible = 1 AND e.approval_status = 'rejected'";
        } elseif ($status === 'ineligible') {
            $whereClauses[] = 'c.certificate_id IS NULL AND e.is_eligible = 0';
        }

        if (!empty($filters['program_id'])) {
            $whereClauses[] = 'e.program_id = ?';
            $values[] = (int) $filters['program_id'];
        }

        if (!empty($whereClauses)) {
            $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
        }

        $sql .= ' ORDER BY e.updated_at DESC, c.issue_date DESC';

        $records = $this->rows($sql, $values);
        foreach ($records as &$record) {
            $record['completion_status'] = !empty($record['completion_date']) && $record['completion_date'] <= date('Y-m-d')
                ? 'Completed'
                : 'In progress';
        }
        unset($record);
        return $records;
    }

    public function generateCertificateNumber()
    {
        $prefix = 'CERT';
        $year = date('Y');
        $random = strtoupper(bin2hex(random_bytes(4)));
        return sprintf('%s-%s-%s', $prefix, $year, $random);
    }

    public function issueCertificate($traineeId, $classId, $issuedBy)
    {
        $currentEligibility = $this->calculateEligibility((int) $traineeId, (int) $classId);
        $eligibility = $this->row('SELECT * FROM certificate_eligibility WHERE trainee_id = ? AND class_id = ?', [(int) $traineeId, (int) $classId]);
        if (!$currentEligibility['eligible'] || !$eligibility || $eligibility['approval_status'] !== 'approved') {
            return false;
        }

        $class = $this->row('SELECT c.*, p.program_id FROM training_classes c JOIN training_programs p ON p.program_id = c.program_id WHERE c.class_id = ?', [(int) $classId]);
        if (!$class) {
            return false;
        }

        $certificateNumber = $this->generateCertificateNumber();
        $qrCode = bin2hex(random_bytes(32));

        $this->execute('INSERT INTO certificates (trainee_id, class_id, program_id, certificate_number, completion_date, issue_date, status, qr_code) VALUES (?, ?, ?, ?, CURDATE(), CURDATE(), "issued", ?)', [
            (int) $traineeId,
            (int) $classId,
            (int) $class['program_id'],
            $certificateNumber,
            $qrCode
        ]);

        return $this->row('SELECT * FROM certificates WHERE certificate_number = ?', [$certificateNumber]);
    }

    public function revokeCertificate($certificateId, $revokedBy, $reason)
    {
        $this->execute('UPDATE certificates SET status = "revoked", revoked_at = CURDATE(), revoked_by = ?, revocation_reason = ? WHERE certificate_id = ?', [
            (int) $revokedBy,
            $reason,
            (int) $certificateId
        ]);
    }

    public function logCertificateAction($actorId, $certificateId, $action, $details = null)
    {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $this->execute('INSERT INTO certificate_audit_logs (actor_id, certificate_id, action, details, ip_address) VALUES (?, ?, ?, ?, ?)', [
            (int) $actorId,
            (int) $certificateId,
            $action,
            $details,
            $ipAddress
        ]);
    }

    public function logEligibilityAction($actorId, $eligibilityId, $action, $details = null)
    {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $this->execute('INSERT INTO certificate_audit_logs (actor_id, eligibility_id, action, details, ip_address) VALUES (?, ?, ?, ?, ?)', [
            (int) $actorId,
            (int) $eligibilityId,
            $action,
            $details,
            $ipAddress,
        ]);
    }

    public function certificateAuditRecords($certificateId)
    {
        return $this->rows('SELECT a.*, u.full_name AS actor_name FROM certificate_audit_logs a LEFT JOIN users u ON u.user_id = a.actor_id WHERE a.certificate_id = ? ORDER BY a.created_at DESC, a.audit_id DESC', [(int) $certificateId]);
    }
}