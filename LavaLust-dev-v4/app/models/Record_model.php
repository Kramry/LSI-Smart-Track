<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';

class Record_model extends Base_model
{
    public function records($section, array $user)
    {
        $userId = (int) $user['user_id'];
        $role = $user['role'];
        $queries = [
            'users' => 'SELECT user_id, full_name, username, role, status, created_at FROM users',
            'trainees' => 'SELECT t.trainee_id, t.first_name, t.last_name, t.sex, t.date_of_birth, t.address, t.email, t.contact_number, t.course, t.status, t.qr_code, t.user_id, t.program_id, p.program_name FROM trainees t LEFT JOIN training_programs p ON p.program_id = t.program_id',
            'programs' => 'SELECT program_id, program_code, program_name, description, status FROM training_programs',
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
}
