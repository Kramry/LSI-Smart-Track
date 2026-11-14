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

    public function dashboardStats(array $user, ?string $today = null, ?string $since = null)
    {
        $today = $today ?? date('Y-m-d');
        $since = $since ?? date('Y-m-d', strtotime('-7 days'));

        if ($user['role'] === 'trainee') {
            $trainee = $this->userModel->traineeForUser($user['user_id']);
            if (!$trainee) {
                return ['trainees' => 0, 'programs' => 0, 'present_today' => 0, 'follow_up' => 0];
            }
            return [
                'trainees' => 1,
                'programs' => (int) ($this->db->table('trainees')
                    ->select('COUNT(DISTINCT program_id) AS total')
                    ->where('user_id', (int) $user['user_id'])
                    ->row_array()['total'] ?? 0),
                'present_today' => (int) ($this->row("SELECT COUNT(*) AS total FROM attendance_records WHERE trainee_id = ? AND attendance_date = ? AND status IN ('Present', 'Late')", [(int) $trainee['trainee_id'], $today])['total'] ?? 0),
                'follow_up' => (int) ($this->row("SELECT COUNT(*) AS total FROM attendance_records WHERE trainee_id = ? AND status = 'Absent' AND attendance_date >= ?", [(int) $trainee['trainee_id'], $since])['total'] ?? 0),
            ];
        }
        if ($user['role'] === 'instructor') {
            $id = (int) $user['user_id'];
            return [
                'trainees' => (int) ($this->db->table('trainees t')
                    ->select('COUNT(DISTINCT t.trainee_id) AS total')
                    ->join('class_enrollments e', 'e.trainee_id = t.trainee_id')
                    ->join('training_classes c', 'c.class_id = e.class_id')
                    ->where('c.instructor_id', $id)
                    ->where('t.status', 'active')
                    ->row_array()['total'] ?? 0),
                'programs' => (int) ($this->db->table('training_classes')
                    ->select('COUNT(DISTINCT program_id) AS total')
                    ->where('instructor_id', $id)
                    ->where('status', 'active')
                    ->row_array()['total'] ?? 0),
                'present_today' => (int) ($this->row("SELECT COUNT(*) AS total FROM attendance_records a JOIN training_classes c ON c.class_id = a.class_id WHERE c.instructor_id = ? AND a.attendance_date = ? AND a.status IN ('Present', 'Late')", [$id, $today])['total'] ?? 0),
                'follow_up' => (int) ($this->row("SELECT COUNT(*) AS total FROM attendance_records a JOIN training_classes c ON c.class_id = a.class_id WHERE c.instructor_id = ? AND a.status = 'Absent' AND a.attendance_date >= ?", [$id, $since])['total'] ?? 0),
            ];
        }
        return [
            'trainees' => (int) ($this->db->table('trainees')
                ->select('COUNT(*) AS total')
                ->where('status', 'active')
                ->row_array()['total'] ?? 0),
            'programs' => (int) ($this->db->table('training_programs')
                ->select('COUNT(*) AS total')
                ->where('status', 'active')
                ->row_array()['total'] ?? 0),
            'present_today' => (int) ($this->row("SELECT COUNT(*) AS total FROM attendance_records WHERE attendance_date = ? AND status IN ('Present', 'Late')", [$today])['total'] ?? 0),
            'follow_up' => (int) ($this->row("SELECT COUNT(*) AS total FROM attendance_records WHERE status = 'Absent' AND attendance_date >= ?", [$since])['total'] ?? 0),
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
        return $this->db->table('announcements')
            ->select('announcement_id, title, content, created_at')
            ->where('status', 'published')
            ->order_by('created_at', 'DESC')
            ->limit(5)
            ->result_array();
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

            $query = $this->db->table('attendance_records a')
                ->select('COUNT(*) AS total')
                ->where('a.attendance_date', $date);
            if ($isInstructor) {
                $query->join('training_classes c', 'c.class_id = a.class_id')
                    ->where('c.instructor_id', (int) $user['user_id']);
            }

            $total = (int) ($query->row_array()['total'] ?? 0);

            $weeklyData[] = [
                'date' => $dateLabel,
                'total' => $total
            ];
        }

        // Attendance by status (last 30 days)
        $statusSql = 'SELECT status, COUNT(*) AS total FROM attendance_records WHERE attendance_date >= ?';
        $statusValues = [date('Y-m-d', strtotime('-30 days'))];

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
            $programRows = $this->db->table('training_programs p')
                ->select('p.program_id, p.program_name, p.program_code, COUNT(DISTINCT t.trainee_id) AS trainee_count, COUNT(DISTINCT a.attendance_id) AS attendance_count')
                ->left_join('trainees t', "t.program_id = p.program_id AND t.status = 'active'")
                ->left_join('attendance_records a', 't.trainee_id = a.trainee_id')
                ->where('p.status', 'active')
                ->group_by(['p.program_id', 'p.program_name', 'p.program_code'])
                ->order_by('attendance_count', 'DESC')
                ->result_array();

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
        $lateSql = "SELECT COUNT(*) AS total FROM attendance_records WHERE status = 'Late' AND attendance_date >= ?";
        $lateValues = [date('Y-m-d', strtotime('-7 days'))];

        if ($isInstructor) {
            $lateSql .= " AND class_id IN (SELECT class_id FROM training_classes WHERE instructor_id = ?)";
            $lateValues[] = (int) $user['user_id'];
        }

        $lateCount = (int) ($this->row($lateSql, $lateValues)['total'] ?? 0);

        // Absences this week
        $absentSql = "SELECT COUNT(*) AS total FROM attendance_records WHERE status = 'Absent' AND attendance_date >= ?";
        $absentValues = [date('Y-m-d', strtotime('-7 days'))];

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

    public function absenceFollowUps(array $user, ?string $since = null)
    {
        $sql = "SELECT a.attendance_date, t.first_name, t.last_name, c.class_name, a.remarks FROM attendance_records a JOIN trainees t ON t.trainee_id = a.trainee_id LEFT JOIN training_classes c ON c.class_id = a.class_id WHERE a.status = 'Absent' AND a.attendance_date >= ?";
        $values = [$since ?? date('Y-m-d', strtotime('-7 days'))];
        if ($user['role'] === 'instructor') {
            $sql .= ' AND c.instructor_id = ?';
            $values[] = (int) $user['user_id'];
        } elseif ($user['role'] === 'trainee') {
            $sql .= ' AND t.user_id = ?';
            $values[] = (int) $user['user_id'];
        }
        $rows = $this->rows($sql . ' ORDER BY a.attendance_date DESC, t.last_name, t.first_name', $values);
        foreach ($rows as &$row) {
            $row['trainee_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            unset($row['first_name'], $row['last_name']);
        }
        unset($row);
        return $rows;
    }
}
