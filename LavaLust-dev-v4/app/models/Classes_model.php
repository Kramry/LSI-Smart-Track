<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';

class Classes_model extends Base_model
{
    public function classes(array $user, array $filters = [])
    {
        $roomSelect = $this->columnExists('training_classes', 'room') ? 'c.room' : 'NULL AS room';
        $where = [];
        $values = [];
        if (($user['role'] ?? '') === 'instructor') {
            $where[] = 'c.instructor_id = ?';
            $values[] = (int) $user['user_id'];
        } elseif (!empty($filters['instructor_id'])) {
            $where[] = 'c.instructor_id = ?';
            $values[] = (int) $filters['instructor_id'];
        }
        if (!empty($filters['program_id'])) {
            $where[] = 'c.program_id = ?';
            $values[] = (int) $filters['program_id'];
        }

        $whereSql = $where ? ' AND ' . implode(' AND ', $where) : '';
        return $this->rows(
            "SELECT c.class_id, c.class_name, {$roomSelect}, c.start_date, c.end_date, c.status,
                    p.program_id, p.program_name,
                    COALESCE(enrolled.total, 0) AS enrolled_count,
                    COALESCE(today.present_count, 0) AS today_present,
                    COALESCE(today.late_count, 0) AS today_late,
                    COALESCE(today.total_count, 0) AS today_scanned,
                    schedule_summary.schedule_summary
             FROM training_classes c
             JOIN training_programs p ON p.program_id = c.program_id
             LEFT JOIN (
                SELECT class_id, COUNT(*) AS total
                FROM class_enrollments
                GROUP BY class_id
             ) enrolled ON enrolled.class_id = c.class_id
             LEFT JOIN (
                SELECT class_id,
                       SUM(status = 'Present') AS present_count,
                       SUM(status = 'Late') AS late_count,
                       COUNT(*) AS total_count
                FROM attendance_records
                WHERE attendance_date = CURDATE()
                GROUP BY class_id
             ) today ON today.class_id = c.class_id
             LEFT JOIN (
                SELECT class_id,
                       GROUP_CONCAT(CONCAT(DATE_FORMAT(schedule_date, '%a'), ' ', TIME_FORMAT(start_time, '%H:%i'), '-', TIME_FORMAT(end_time, '%H:%i')) ORDER BY schedule_date, start_time SEPARATOR ', ') AS schedule_summary
                FROM training_schedules
                GROUP BY class_id
             ) schedule_summary ON schedule_summary.class_id = c.class_id
             WHERE 1 = 1 {$whereSql}
             ORDER BY c.start_date IS NULL, c.start_date, c.class_name",
            $values
        );
    }

    public function classById($classId, array $user)
    {
        $roomSelect = $this->columnExists('training_classes', 'room') ? 'c.room' : 'NULL AS room';
        $sql = "SELECT c.class_id, c.class_name, {$roomSelect}, c.start_date, c.end_date, c.status, c.instructor_id, p.program_id, p.program_name FROM training_classes c JOIN training_programs p ON p.program_id = c.program_id WHERE c.class_id = ?";
        $values = [(int) $classId];
        if (($user['role'] ?? '') === 'instructor') {
            $sql .= ' AND c.instructor_id = ?';
            $values[] = (int) $user['user_id'];
        }
        return $this->row($sql, $values);
    }

    public function schedules($classId)
    {
        return $this->rows('SELECT schedule_id, schedule_date, start_time, end_time FROM training_schedules WHERE class_id = ? ORDER BY schedule_date, start_time', [(int) $classId]);
    }

    public function roster($classId)
    {
        return $this->rows(
            "SELECT t.trainee_id, t.first_name, t.last_name, t.email,
                    CAST(t.trainee_id AS CHAR) AS trainee_number,
                    COALESCE(attendance.total, 0) AS attendance_total,
                    COALESCE(attendance.attended, 0) AS attendance_attended,
                    COALESCE(assessments.passed, 0) AS assessments_passed,
                    COALESCE(assessments.failed, 0) AS assessments_failed
             FROM class_enrollments e
             JOIN trainees t ON t.trainee_id = e.trainee_id
             LEFT JOIN (
                SELECT trainee_id, class_id, COUNT(*) AS total,
                       SUM(status IN ('Present', 'Late')) AS attended
                FROM attendance_records
                GROUP BY trainee_id, class_id
             ) attendance ON attendance.trainee_id = e.trainee_id AND attendance.class_id = e.class_id
             LEFT JOIN (
                SELECT trainee_id, class_id,
                       SUM(outcome = 'pass') AS passed,
                       SUM(outcome = 'fail') AS failed
                FROM training_assessments
                GROUP BY trainee_id, class_id
             ) assessments ON assessments.trainee_id = e.trainee_id AND assessments.class_id = e.class_id
             WHERE e.class_id = ?
             ORDER BY t.last_name, t.first_name",
            [(int) $classId]
        );
    }

    public function attendance($classId)
    {
        return $this->rows(
            "SELECT a.attendance_date, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, a.status, a.time_in, a.time_out
             FROM attendance_records a
             JOIN trainees t ON t.trainee_id = a.trainee_id
             WHERE a.class_id = ? AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
             ORDER BY a.attendance_date DESC, t.last_name, t.first_name",
            [(int) $classId]
        );
    }

    public function assessments($classId)
    {
        return $this->rows(
            "SELECT CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, a.assessment_name, a.outcome, a.assessment_date, a.notes
             FROM training_assessments a
             JOIN trainees t ON t.trainee_id = a.trainee_id
             WHERE a.class_id = ? ORDER BY a.assessment_date DESC, trainee_name",
            [(int) $classId]
        );
    }

    public function documents($classId)
    {
        return $this->rows(
            "SELECT d.document_id, d.document_name, d.upload_date, d.review_status,
                    CONCAT(t.first_name, ' ', t.last_name) AS trainee_name
             FROM trainee_documents d
             LEFT JOIN trainees t ON t.trainee_id = d.trainee_id
             WHERE d.class_id = ? ORDER BY d.upload_date DESC",
            [(int) $classId]
        );
    }

    public function announcements($classId)
    {
        if (!$this->columnExists('announcements', 'class_id')) {
            return [];
        }
        return $this->rows(
            'SELECT a.announcement_id, a.title, a.content, a.created_at, a.status, u.full_name AS author
             FROM announcements a LEFT JOIN users u ON u.user_id = a.user_id
             WHERE a.class_id = ? ORDER BY a.created_at DESC',
            [(int) $classId]
        );
    }

    public function quickStats($classId)
    {
        return $this->row(
            "SELECT
                COALESCE(ROUND(SUM(a.status IN ('Present', 'Late')) / NULLIF(COUNT(a.attendance_id), 0) * 100, 1), 0) AS attendance_rate,
                (SELECT COUNT(*) FROM training_assessments WHERE class_id = ? AND outcome = 'pass') AS assessments_passed,
                (SELECT COUNT(*) FROM certificate_document_requirements r WHERE r.class_id = ?
                    AND NOT EXISTS (
                        SELECT 1 FROM trainee_documents d
                        WHERE d.class_id = r.class_id AND d.document_name = r.required_document_name AND d.review_status = 'approved'
                    )
                ) AS pending_documents
             FROM attendance_records a WHERE a.class_id = ?",
            [(int) $classId, (int) $classId, (int) $classId]
        ) ?: ['attendance_rate' => 0, 'assessments_passed' => 0, 'pending_documents' => 0];
    }

    private function columnExists($table, $column)
    {
        return (bool) $this->row(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        );
    }
}
