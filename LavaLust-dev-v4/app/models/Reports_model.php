<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';

class Reports_model extends Base_model
{
    public function availableReports()
    {
        return [
            'attendance' => [
                'name' => 'Attendance Report',
                'description' => 'Daily attendance records for trainees',
                'formats' => ['csv', 'pdf'],
                'filters' => ['start_date', 'end_date', 'class_id']
            ],
            'training' => [
                'name' => 'Training Report',
                'description' => 'Training enrollment and completion statistics',
                'formats' => ['csv', 'pdf'],
                'filters' => ['start_date', 'end_date', 'program_id', 'class_id']
            ],
            'documents' => [
                'name' => 'Document Report',
                'description' => 'Document submission and verification status',
                'formats' => ['csv', 'pdf'],
                'filters' => ['start_date', 'end_date', 'class_id', 'status']
            ],
            'certificates' => [
                'name' => 'Certificate Report',
                'description' => 'Certificate issuance and eligibility status',
                'formats' => ['csv', 'pdf'],
                'filters' => ['start_date', 'end_date', 'program_id', 'class_id', 'status']
            ],
            'payments' => [
                'name' => 'Payment Report',
                'description' => 'Payment transactions and collections',
                'formats' => ['csv', 'pdf'],
                'filters' => ['start_date', 'end_date', 'fee_kind', 'method']
            ],
            'announcements' => [
                'name' => 'Announcement Report',
                'description' => 'Published announcements and notification statistics',
                'formats' => ['csv', 'pdf'],
                'filters' => ['start_date', 'end_date', 'status']
            ],
            'trainees' => [
                'name' => 'Trainee Report',
                'description' => 'Trainee profiles and enrollment information',
                'formats' => ['csv', 'pdf'],
                'filters' => ['status', 'program_id', 'class_id']
            ],
            'classes' => [
                'name' => 'Class Report',
                'description' => 'Training classes and schedules',
                'formats' => ['csv', 'pdf'],
                'filters' => ['status', 'program_id']
            ]
        ];
    }

    public function getReportData($reportType, array $filters)
    {
        switch ($reportType) {
            case 'attendance':
                return $this->attendanceReport($filters);
            case 'training':
                return $this->trainingReport($filters);
            case 'documents':
                return $this->documentReport($filters);
            case 'certificates':
                return $this->certificateReport($filters);
            case 'payments':
                return $this->paymentReport($filters);
            case 'announcements':
                return $this->announcementReport($filters);
            case 'trainees':
                return $this->traineeReport($filters);
            case 'classes':
                return $this->classReport($filters);
            default:
                return [];
        }
    }

    private function attendanceReport(array $filters)
    {
        $where = ['a.attendance_date BETWEEN ? AND ?'];
        $values = [$filters['start_date'], $filters['end_date']];

        if (!empty($filters['class_id'])) {
            $where[] = 'a.class_id = ?';
            $values[] = (int) $filters['class_id'];
        }

        $sql = "SELECT 
            a.attendance_date,
            CONCAT(t.first_name, ' ', t.last_name) AS trainee_name,
            t.trainee_number,
            p.program_name,
            c.class_name,
            a.time_in,
            a.time_out,
            a.status,
            a.remarks
        FROM attendance_records a
        JOIN trainees t ON t.trainee_id = a.trainee_id
        LEFT JOIN training_classes c ON c.class_id = a.class_id
        LEFT JOIN training_programs p ON p.program_id = c.program_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.attendance_date DESC, c.class_name, t.last_name, t.first_name";

        return $this->rows($sql, $values);
    }

    private function trainingReport(array $filters)
    {
        $where = ['e.enrolled_at BETWEEN ? AND ?'];
        $values = [$filters['start_date'], $filters['end_date']];

        if (!empty($filters['program_id'])) {
            $where[] = 'c.program_id = ?';
            $values[] = (int) $filters['program_id'];
        }

        if (!empty($filters['class_id'])) {
            $where[] = 'e.class_id = ?';
            $values[] = (int) $filters['class_id'];
        }

        $sql = "SELECT 
            e.enrolled_at,
            t.trainee_id,
            CONCAT(t.first_name, ' ', t.last_name) AS trainee_name,
            t.trainee_number,
            t.status AS trainee_status,
            p.program_name,
            c.class_name,
            c.start_date AS class_start,
            c.end_date AS class_end,
            COUNT(DISTINCT att.attendance_id) AS attendance_count,
            COALESCE(SUM(CASE WHEN att.status IN ('Present', 'Late') THEN 1 ELSE 0 END), 0) AS present_count,
            (SELECT COUNT(*) FROM certificates cert WHERE cert.trainee_id = e.trainee_id AND cert.class_id = e.class_id AND cert.status = 'issued') AS certificates_issued
        FROM class_enrollments e
        JOIN trainees t ON t.trainee_id = e.trainee_id
        JOIN training_classes c ON c.class_id = e.class_id
        JOIN training_programs p ON p.program_id = c.program_id
        LEFT JOIN attendance_records att ON att.trainee_id = e.trainee_id AND att.class_id = e.class_id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY e.enrolled_at, t.trainee_id, t.first_name, t.last_name, t.trainee_number, t.status, p.program_name, c.class_name, c.start_date, c.end_date
        ORDER BY e.enrolled_at DESC";

        return $this->rows($sql, $values);
    }

    private function documentReport(array $filters)
    {
        $where = ['d.upload_date BETWEEN ? AND ?'];
        $values = [$filters['start_date'], $filters['end_date']];

        if (!empty($filters['class_id'])) {
            $where[] = 'd.class_id = ?';
            $values[] = (int) $filters['class_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'd.review_status = ?';
            $values[] = $filters['status'];
        }

        $sql = "SELECT 
            d.upload_date,
            d.document_name,
            d.file_path,
            d.status,
            d.review_status,
            d.reviewed_at,
            CONCAT(t.first_name, ' ', t.last_name) AS trainee_name,
            t.trainee_number,
            c.class_name,
            p.program_name,
            CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by
        FROM trainee_documents d
        JOIN trainees t ON t.trainee_id = d.trainee_id
        LEFT JOIN training_classes c ON c.class_id = d.class_id
        LEFT JOIN training_programs p ON p.program_id = c.program_id
        LEFT JOIN users u ON u.user_id = d.uploaded_by
        WHERE " . implode(' AND ', $where) . "
        ORDER BY d.upload_date DESC, d.document_name";

        return $this->rows($sql, $values);
    }

    private function certificateReport(array $filters)
    {
        $where = ['cert.issue_date BETWEEN ? AND ?'];
        $values = [$filters['start_date'], $filters['end_date']];

        if (!empty($filters['program_id'])) {
            $where[] = 'cert.program_id = ?';
            $values[] = (int) $filters['program_id'];
        }

        if (!empty($filters['class_id'])) {
            $where[] = 'cert.class_id = ?';
            $values[] = (int) $filters['class_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'cert.status = ?';
            $values[] = $filters['status'];
        }

        $sql = "SELECT 
            cert.certificate_number,
            cert.issue_date,
            cert.completion_date,
            cert.status,
            cert.revoked_at,
            cert.revocation_reason,
            CONCAT(t.first_name, ' ', t.last_name) AS trainee_name,
            t.trainee_number,
            p.program_name,
            c.class_name,
            cert.eligibility_id,
            e.attendance_percentage,
            e.documents_approved,
            e.assessments_completed,
            e.is_eligible
        FROM certificates cert
        JOIN trainees t ON t.trainee_id = cert.trainee_id
        JOIN training_classes c ON c.class_id = cert.class_id
        JOIN training_programs p ON p.program_id = cert.program_id
        LEFT JOIN certificate_eligibility e ON e.eligibility_id = cert.eligibility_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY cert.issue_date DESC";

        return $this->rows($sql, $values);
    }

    private function paymentReport(array $filters)
    {
        $where = ['p.payment_date BETWEEN ? AND ?'];
        $values = [$filters['start_date'], $filters['end_date']];

        if (!empty($filters['fee_kind'])) {
            $where[] = 'f.fee_kind = ?';
            $values[] = $filters['fee_kind'];
        }

        if (!empty($filters['method'])) {
            $where[] = 'p.method = ?';
            $values[] = $filters['method'];
        }

        $sql = "SELECT 
            p.payment_date,
            p.entry_type,
            p.amount,
            p.method,
            p.reference_number,
            p.receipt_number,
            p.remarks,
            CONCAT(t.first_name, ' ', t.last_name) AS trainee_name,
            t.trainee_number,
            f.fee_name,
            f.fee_kind,
            f.amount_due,
            c.class_name,
            p.program_id
        FROM payments p
        JOIN trainee_fees f ON f.trainee_fee_id = p.trainee_fee_id
        JOIN trainees t ON t.trainee_id = f.trainee_id
        LEFT JOIN training_classes c ON c.class_id = f.class_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.payment_date DESC";

        return $this->rows($sql, $values);
    }

    private function announcementReport(array $filters)
    {
        $where = ['a.created_at BETWEEN ? AND ?'];
        $values = [$filters['start_date'], $filters['end_date']];

        if (!empty($filters['status'])) {
            $where[] = 'a.status = ?';
            $values[] = $filters['status'];
        }

        $sql = "SELECT 
            a.created_at,
            a.published_at,
            a.title,
            a.content,
            a.status,
            CONCAT(u.first_name, ' ', u.last_name) AS created_by,
            (SELECT COUNT(*) FROM announcement_notifications n WHERE n.announcement_id = a.announcement_id) AS notification_count,
            (SELECT COUNT(*) FROM announcement_notifications n WHERE n.announcement_id = a.announcement_id AND n.status = 'sent') AS sent_count,
            (SELECT COUNT(*) FROM announcement_notifications n WHERE n.announcement_id = a.announcement_id AND n.status = 'failed') AS failed_count
        FROM announcements a
        LEFT JOIN users u ON u.user_id = a.user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.created_at DESC";

        return $this->rows($sql, $values);
    }

    private function traineeReport(array $filters)
    {
        $where = ['1 = 1'];
        $values = [];

        if (!empty($filters['status'])) {
            $where[] = 't.status = ?';
            $values[] = $filters['status'];
        }

        if (!empty($filters['program_id'])) {
            $where[] = 't.program_id = ?';
            $values[] = (int) $filters['program_id'];
        }

        if (!empty($filters['class_id'])) {
            $where[] = 't.trainee_id IN (SELECT trainee_id FROM class_enrollments WHERE class_id = ?)';
            $values[] = (int) $filters['class_id'];
        }

        $sql = "SELECT 
            t.trainee_id,
            t.trainee_number,
            t.first_name,
            t.last_name,
            t.sex,
            t.date_of_birth,
            t.contact_number,
            t.email,
            t.course,
            t.status,
            t.qr_code,
            t.created_at,
            p.program_name,
            (SELECT COUNT(*) FROM class_enrollments e WHERE e.trainee_id = t.trainee_id) AS enrollment_count,
            (SELECT COUNT(DISTINCT class_id) FROM class_enrollments e WHERE e.trainee_id = t.trainee_id) AS class_count
        FROM trainees t
        LEFT JOIN training_programs p ON p.program_id = t.program_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY t.last_name, t.first_name";

        return $this->rows($sql, $values);
    }

    private function classReport(array $filters)
    {
        $where = ['1 = 1'];
        $values = [];

        if (!empty($filters['status'])) {
            $where[] = 'c.status = ?';
            $values[] = $filters['status'];
        }

        if (!empty($filters['program_id'])) {
            $where[] = 'c.program_id = ?';
            $values[] = (int) $filters['program_id'];
        }

        $sql = "SELECT 
            c.class_id,
            c.class_name,
            c.start_date,
            c.end_date,
            c.status,
            p.program_name,
            p.program_code,
            CONCAT(u.first_name, ' ', u.last_name) AS instructor_name,
            (SELECT COUNT(*) FROM class_enrollments e WHERE e.class_id = c.class_id) AS enrolled_count,
            (SELECT COUNT(DISTINCT trainee_id) FROM attendance_records a WHERE a.class_id = c.class_id) AS attendance_count
        FROM training_classes c
        JOIN training_programs p ON p.program_id = c.program_id
        LEFT JOIN users u ON u.user_id = c.instructor_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.start_date DESC, c.class_name";

        return $this->rows($sql, $values);
    }
}
