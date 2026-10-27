<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'models/Base_model.php';
require_once APP_DIR . 'models/User_model.php';

class Dashboard_model extends Base_model
{
    private $userModel;

    public function __construct($db)
    {
        parent::__construct($db);
        $this->userModel = new User_model($db);
    }

    public function dashboardStats(array $user)
    {
        if ($user['role'] === 'trainee') {
            $trainee = $this->userModel->traineeForUser($user['user_id']);
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
}
