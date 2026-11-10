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
        $query = $this->db->table('attendance_records a')
            ->select('a.attendance_date, t.first_name, t.last_name, t.trainee_number, p.program_name, c.class_name, a.time_in, a.time_out, a.status, a.remarks')
            ->join('trainees t', 't.trainee_id = a.trainee_id')
            ->left_join('training_classes c', 'c.class_id = a.class_id')
            ->left_join('training_programs p', 'p.program_id = c.program_id')
            ->between('a.attendance_date', $filters['start_date'], $filters['end_date']);
        if (!empty($filters['class_id'])) {
            $query->where('a.class_id', (int) $filters['class_id']);
        }
        $records = $query->result_array();

        $sortRanks = [
            'attendance_date' => $this->columnOrderRanks('attendance_records', 'attendance_date', 'DESC'),
            'class_name' => $this->columnOrderRanks('training_classes', 'class_name'),
            'last_name' => $this->columnOrderRanks('trainees', 'last_name'),
            'first_name' => $this->columnOrderRanks('trainees', 'first_name'),
        ];
        foreach ($records as $index => &$record) {
            $record['trainee_name'] = $record['first_name'] === null || $record['last_name'] === null
                ? null
                : $record['first_name'] . ' ' . $record['last_name'];
            $record['_sort_order'] = $index;
        }
        unset($record);
        usort($records, static function ($left, $right) use ($sortRanks) {
            foreach (['attendance_date', 'class_name', 'last_name', 'first_name'] as $field) {
                $leftRank = $sortRanks[$field][serialize($left[$field])] ?? PHP_INT_MAX;
                $rightRank = $sortRanks[$field][serialize($right[$field])] ?? PHP_INT_MAX;
                if ($leftRank !== $rightRank) {
                    return $leftRank <=> $rightRank;
                }
            }
            return $left['_sort_order'] <=> $right['_sort_order'];
        });

        foreach ($records as &$record) {
            $record = [
                'attendance_date' => $record['attendance_date'],
                'trainee_name' => $record['trainee_name'],
                'trainee_number' => $record['trainee_number'],
                'program_name' => $record['program_name'],
                'class_name' => $record['class_name'],
                'time_in' => $record['time_in'],
                'time_out' => $record['time_out'],
                'status' => $record['status'],
                'remarks' => $record['remarks'],
            ];
        }
        unset($record);
        return $records;
    }

    private function trainingReport(array $filters)
    {
        $query = $this->db->table('class_enrollments e')
            ->select("e.enrolled_at, e.class_id, t.trainee_id, t.first_name, t.last_name, t.trainee_number, t.status AS trainee_status, p.program_name, c.class_name, c.start_date AS class_start, c.end_date AS class_end, COUNT(DISTINCT att.attendance_id) AS attendance_count, SUM(CASE WHEN att.status = 'Present' OR att.status = 'Late' THEN 1 ELSE 0 END) AS present_count")
            ->join('trainees t', 't.trainee_id = e.trainee_id')
            ->join('training_classes c', 'c.class_id = e.class_id')
            ->join('training_programs p', 'p.program_id = c.program_id')
            ->left_join('attendance_records att', 'att.trainee_id = e.trainee_id AND att.class_id = e.class_id')
            ->between('e.enrolled_at', $filters['start_date'], $filters['end_date']);
        if (!empty($filters['program_id'])) {
            $query->where('c.program_id', (int) $filters['program_id']);
        }
        if (!empty($filters['class_id'])) {
            $query->where('e.class_id', (int) $filters['class_id']);
        }
        $records = $query->group_by([
            'e.enrolled_at',
            't.trainee_id',
            't.first_name',
            't.last_name',
            't.trainee_number',
            't.status',
            'p.program_name',
            'c.class_name',
            'c.start_date',
            'c.end_date',
        ])->order_by('e.enrolled_at', 'DESC')->result_array();

        $certificateCounts = $this->db->table('certificates')
            ->select('trainee_id, class_id, COUNT(*) AS certificates_issued')
            ->where('status', 'issued')
            ->group_by(['trainee_id', 'class_id'])
            ->result_array();
        $certificatesByEnrollment = [];
        foreach ($certificateCounts as $count) {
            $certificatesByEnrollment[$count['trainee_id'] . ':' . $count['class_id']] = $count['certificates_issued'];
        }
        foreach ($records as &$record) {
            $traineeName = $record['first_name'] === null || $record['last_name'] === null
                ? null
                : $record['first_name'] . ' ' . $record['last_name'];
            $record = [
                'enrolled_at' => $record['enrolled_at'],
                'trainee_id' => $record['trainee_id'],
                'trainee_name' => $traineeName,
                'trainee_number' => $record['trainee_number'],
                'trainee_status' => $record['trainee_status'],
                'program_name' => $record['program_name'],
                'class_name' => $record['class_name'],
                'class_start' => $record['class_start'],
                'class_end' => $record['class_end'],
                'attendance_count' => $record['attendance_count'],
                'present_count' => $record['present_count'],
                'certificates_issued' => $certificatesByEnrollment[$record['trainee_id'] . ':' . $record['class_id']] ?? 0,
            ];
        }
        unset($record);
        return $records;
    }

    private function documentReport(array $filters)
    {
        $query = $this->db->table('trainee_documents d')
            ->select('d.upload_date, d.document_name, d.file_path, d.status, d.review_status, d.reviewed_at, t.first_name, t.last_name, t.trainee_number, c.class_name, p.program_name, u.first_name AS uploader_first_name, u.last_name AS uploader_last_name')
            ->join('trainees t', 't.trainee_id = d.trainee_id')
            ->left_join('training_classes c', 'c.class_id = d.class_id')
            ->left_join('training_programs p', 'p.program_id = c.program_id')
            ->left_join('users u', 'u.user_id = d.uploaded_by')
            ->between('d.upload_date', $filters['start_date'], $filters['end_date']);
        if (!empty($filters['class_id'])) {
            $query->where('d.class_id', (int) $filters['class_id']);
        }
        if (!empty($filters['status'])) {
            $query->where('d.review_status', $filters['status']);
        }
        $records = $query->order_by('d.document_name')->result_array();
        foreach ($records as $index => &$record) {
            $record['trainee_name'] = $record['first_name'] === null || $record['last_name'] === null
                ? null
                : $record['first_name'] . ' ' . $record['last_name'];
            $record['uploaded_by'] = $record['uploader_first_name'] === null || $record['uploader_last_name'] === null
                ? null
                : $record['uploader_first_name'] . ' ' . $record['uploader_last_name'];
            unset($record['first_name'], $record['last_name'], $record['uploader_first_name'], $record['uploader_last_name']);
            $record['_sort_order'] = $index;
        }
        unset($record);
        usort($records, static function ($left, $right) {
            $byDate = $right['upload_date'] <=> $left['upload_date'];
            return $byDate !== 0 ? $byDate : $left['_sort_order'] <=> $right['_sort_order'];
        });
        foreach ($records as &$record) {
            $record = [
                'upload_date' => $record['upload_date'],
                'document_name' => $record['document_name'],
                'file_path' => $record['file_path'],
                'status' => $record['status'],
                'review_status' => $record['review_status'],
                'reviewed_at' => $record['reviewed_at'],
                'trainee_name' => $record['trainee_name'],
                'trainee_number' => $record['trainee_number'],
                'class_name' => $record['class_name'],
                'program_name' => $record['program_name'],
                'uploaded_by' => $record['uploaded_by'],
            ];
        }
        unset($record);
        return $records;
    }

    private function certificateReport(array $filters)
    {
        $query = $this->db->table('certificates cert')
            ->select('cert.certificate_number, cert.issue_date, cert.completion_date, cert.status, cert.revoked_at, cert.revocation_reason, t.first_name, t.last_name, t.trainee_number, p.program_name, c.class_name, cert.eligibility_id, e.attendance_percentage, e.documents_approved, e.assessments_completed, e.is_eligible')
            ->join('trainees t', 't.trainee_id = cert.trainee_id')
            ->join('training_classes c', 'c.class_id = cert.class_id')
            ->join('training_programs p', 'p.program_id = cert.program_id')
            ->left_join('certificate_eligibility e', 'e.eligibility_id = cert.eligibility_id')
            ->between('cert.issue_date', $filters['start_date'], $filters['end_date']);
        if (!empty($filters['program_id'])) {
            $query->where('cert.program_id', (int) $filters['program_id']);
        }
        if (!empty($filters['class_id'])) {
            $query->where('cert.class_id', (int) $filters['class_id']);
        }
        if (!empty($filters['status'])) {
            $query->where('cert.status', $filters['status']);
        }
        $records = $query->order_by('cert.issue_date', 'DESC')->result_array();
        foreach ($records as &$record) {
            $traineeName = $record['first_name'] === null || $record['last_name'] === null
                ? null
                : $record['first_name'] . ' ' . $record['last_name'];
            $record = [
                'certificate_number' => $record['certificate_number'],
                'issue_date' => $record['issue_date'],
                'completion_date' => $record['completion_date'],
                'status' => $record['status'],
                'revoked_at' => $record['revoked_at'],
                'revocation_reason' => $record['revocation_reason'],
                'trainee_name' => $traineeName,
                'trainee_number' => $record['trainee_number'],
                'program_name' => $record['program_name'],
                'class_name' => $record['class_name'],
                'eligibility_id' => $record['eligibility_id'],
                'attendance_percentage' => $record['attendance_percentage'],
                'documents_approved' => $record['documents_approved'],
                'assessments_completed' => $record['assessments_completed'],
                'is_eligible' => $record['is_eligible'],
            ];
        }
        unset($record);
        return $records;
    }

    private function paymentReport(array $filters)
    {
        $query = $this->db->table('payments p')
            ->select('p.payment_date, p.entry_type, p.amount, p.method, p.reference_number, p.receipt_number, p.remarks, t.first_name, t.last_name, t.trainee_number, f.fee_name, f.fee_kind, f.amount_due, c.class_name, p.program_id')
            ->join('trainee_fees f', 'f.trainee_fee_id = p.trainee_fee_id')
            ->join('trainees t', 't.trainee_id = f.trainee_id')
            ->left_join('training_classes c', 'c.class_id = f.class_id')
            ->between('p.payment_date', $filters['start_date'], $filters['end_date']);
        if (!empty($filters['fee_kind'])) {
            $query->where('f.fee_kind', $filters['fee_kind']);
        }
        if (!empty($filters['method'])) {
            $query->where('p.method', $filters['method']);
        }
        $records = $query->order_by('p.payment_date', 'DESC')->result_array();
        foreach ($records as &$record) {
            $traineeName = $record['first_name'] === null || $record['last_name'] === null
                ? null
                : $record['first_name'] . ' ' . $record['last_name'];
            $record = [
                'payment_date' => $record['payment_date'],
                'entry_type' => $record['entry_type'],
                'amount' => $record['amount'],
                'method' => $record['method'],
                'reference_number' => $record['reference_number'],
                'receipt_number' => $record['receipt_number'],
                'remarks' => $record['remarks'],
                'trainee_name' => $traineeName,
                'trainee_number' => $record['trainee_number'],
                'fee_name' => $record['fee_name'],
                'fee_kind' => $record['fee_kind'],
                'amount_due' => $record['amount_due'],
                'class_name' => $record['class_name'],
                'program_id' => $record['program_id'],
            ];
        }
        unset($record);
        return $records;
    }

    private function announcementReport(array $filters)
    {
        $query = $this->db->table('announcements a')
            ->select("a.announcement_id, a.created_at, a.published_at, a.title, a.content, a.status, u.first_name, u.last_name, COUNT(n.notification_id) AS notification_count, COUNT(CASE WHEN n.status = 'sent' THEN n.notification_id END) AS sent_count, COUNT(CASE WHEN n.status = 'failed' THEN n.notification_id END) AS failed_count")
            ->left_join('users u', 'u.user_id = a.user_id')
            ->left_join('announcement_notifications n', 'n.announcement_id = a.announcement_id')
            ->between('a.created_at', $filters['start_date'], $filters['end_date']);
        if (!empty($filters['status'])) {
            $query->where('a.status', $filters['status']);
        }
        $records = $query->group_by([
            'a.announcement_id',
            'a.created_at',
            'a.published_at',
            'a.title',
            'a.content',
            'a.status',
            'u.first_name',
            'u.last_name',
        ])->order_by('a.created_at', 'DESC')->result_array();
        foreach ($records as &$record) {
            $createdBy = $record['first_name'] === null || $record['last_name'] === null
                ? null
                : $record['first_name'] . ' ' . $record['last_name'];
            $record = [
                'created_at' => $record['created_at'],
                'published_at' => $record['published_at'],
                'title' => $record['title'],
                'content' => $record['content'],
                'status' => $record['status'],
                'created_by' => $createdBy,
                'notification_count' => $record['notification_count'],
                'sent_count' => $record['sent_count'],
                'failed_count' => $record['failed_count'],
            ];
        }
        unset($record);
        return $records;
    }

    private function traineeReport(array $filters)
    {
        $query = $this->db->table('trainees t')
            ->select('t.trainee_id, t.trainee_number, t.first_name, t.last_name, t.sex, t.date_of_birth, t.contact_number, t.email, t.course, t.status, t.qr_code, t.created_at, p.program_name, COUNT(e.enrollment_id) AS enrollment_count, COUNT(DISTINCT e.class_id) AS class_count');
        if (!empty($filters['class_id'])) {
            $query->join('class_enrollments class_filter', 'class_filter.trainee_id = t.trainee_id')
                ->where('class_filter.class_id', (int) $filters['class_id']);
        }

        $query->left_join('training_programs p', 'p.program_id = t.program_id')
            ->left_join('class_enrollments e', 'e.trainee_id = t.trainee_id');
        if (!empty($filters['status'])) {
            $query->where('t.status', $filters['status']);
        }
        if (!empty($filters['program_id'])) {
            $query->where('t.program_id', (int) $filters['program_id']);
        }
        $records = $query->group_by([
            't.trainee_id',
            't.trainee_number',
            't.first_name',
            't.last_name',
            't.sex',
            't.date_of_birth',
            't.contact_number',
            't.email',
            't.course',
            't.status',
            't.qr_code',
            't.created_at',
            'p.program_name',
        ])->result_array();
        $sortRanks = [
            'last_name' => $this->columnOrderRanks('trainees', 'last_name'),
            'first_name' => $this->columnOrderRanks('trainees', 'first_name'),
        ];
        foreach ($records as $index => &$record) {
            $record['_sort_order'] = $index;
        }
        unset($record);
        usort($records, static function ($left, $right) use ($sortRanks) {
            foreach (['last_name', 'first_name'] as $field) {
                $leftRank = $sortRanks[$field][serialize($left[$field])] ?? PHP_INT_MAX;
                $rightRank = $sortRanks[$field][serialize($right[$field])] ?? PHP_INT_MAX;
                if ($leftRank !== $rightRank) {
                    return $leftRank <=> $rightRank;
                }
            }
            return $left['_sort_order'] <=> $right['_sort_order'];
        });
        foreach ($records as &$record) {
            unset($record['_sort_order']);
        }
        unset($record);
        return $records;
    }

    private function classReport(array $filters)
    {
        $query = $this->db->table('training_classes c')
            ->select('c.class_id, c.class_name, c.start_date, c.end_date, c.status, p.program_name, p.program_code, u.first_name AS instructor_first_name, u.last_name AS instructor_last_name, COUNT(DISTINCT e.enrollment_id) AS enrolled_count, COUNT(DISTINCT a.trainee_id) AS attendance_count')
            ->join('training_programs p', 'p.program_id = c.program_id')
            ->left_join('users u', 'u.user_id = c.instructor_id')
            ->left_join('class_enrollments e', 'e.class_id = c.class_id')
            ->left_join('attendance_records a', 'a.class_id = c.class_id');
        if (!empty($filters['status'])) {
            $query->where('c.status', $filters['status']);
        }
        if (!empty($filters['program_id'])) {
            $query->where('c.program_id', (int) $filters['program_id']);
        }
        $records = $query->group_by([
            'c.class_id',
            'c.class_name',
            'c.start_date',
            'c.end_date',
            'c.status',
            'p.program_name',
            'p.program_code',
            'u.first_name',
            'u.last_name',
        ])->result_array();
        $sortRanks = [
            'start_date' => $this->columnOrderRanks('training_classes', 'start_date', 'DESC'),
            'class_name' => $this->columnOrderRanks('training_classes', 'class_name'),
        ];
        foreach ($records as $index => &$record) {
            $record['instructor_name'] = $record['instructor_first_name'] === null || $record['instructor_last_name'] === null
                ? null
                : $record['instructor_first_name'] . ' ' . $record['instructor_last_name'];
            $record['_sort_order'] = $index;
        }
        unset($record);
        usort($records, static function ($left, $right) use ($sortRanks) {
            foreach (['start_date', 'class_name'] as $field) {
                $leftRank = $sortRanks[$field][serialize($left[$field])] ?? PHP_INT_MAX;
                $rightRank = $sortRanks[$field][serialize($right[$field])] ?? PHP_INT_MAX;
                if ($leftRank !== $rightRank) {
                    return $leftRank <=> $rightRank;
                }
            }
            return $left['_sort_order'] <=> $right['_sort_order'];
        });
        foreach ($records as &$record) {
            $record = [
                'class_id' => $record['class_id'],
                'class_name' => $record['class_name'],
                'start_date' => $record['start_date'],
                'end_date' => $record['end_date'],
                'status' => $record['status'],
                'program_name' => $record['program_name'],
                'program_code' => $record['program_code'],
                'instructor_name' => $record['instructor_name'],
                'enrolled_count' => $record['enrolled_count'],
                'attendance_count' => $record['attendance_count'],
            ];
        }
        unset($record);
        return $records;
    }

    private function columnOrderRanks($table, $column, $direction = 'ASC')
    {
        $values = $this->db->table($table)
            ->select($column)
            ->order_by($column, $direction)
            ->result_array();
        $ranks = [];
        foreach ($values as $index => $value) {
            $key = serialize($value[$column]);
            if (!array_key_exists($key, $ranks)) {
                $ranks[$key] = $index;
            }
        }
        return $ranks;
    }
}
