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
            'trainees' => 'SELECT t.trainee_id, t.first_name, t.last_name, t.sex, t.contact_number, t.course, t.status, t.qr_code, t.user_id, t.program_id, p.program_name FROM trainees t LEFT JOIN training_programs p ON p.program_id = t.program_id',
            'programs' => 'SELECT program_id, program_name, description, duration_hours, status FROM training_programs',
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
}