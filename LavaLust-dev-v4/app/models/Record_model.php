<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';

class Record_model extends Base_model
{
    private const RECORD_KEYS = [
        'users' => 'user_id',
        'trainees' => 'trainee_id',
        'training_programs' => 'program_id',
        'training_classes' => 'class_id',
        'training_schedules' => 'schedule_id',
        'class_enrollments' => 'enrollment_id',
        'attendance_records' => 'attendance_id',
        'trainee_documents' => 'document_id',
        'certificates' => 'certificate_id',
        'certificate_eligibility' => 'eligibility_id',
        'announcements' => 'announcement_id',
        'inquiries' => 'inquiry_id',
        'activity_logs' => 'log_id',
    ];

    public function transactional(callable $operation)
    {
        if (!$this->db->transaction()) {
            try {
                $this->db->roll_back();
            } catch (Throwable $rollbackError) {
                error_log('Record transaction initialization cleanup failed (' . get_class($rollbackError) . ').');
            }
            throw new RuntimeException('Unable to start the record transaction.');
        }

        try {
            $result = $operation();
            if (!$this->db->commit()) {
                throw new RuntimeException('Unable to commit the record transaction.');
            }
            return $result;
        } catch (Throwable $error) {
            try {
                $this->db->roll_back();
            } catch (Throwable $rollbackError) {
                error_log('Record transaction rollback failed (' . get_class($rollbackError) . ').');
            }
            throw $error;
        }
    }

    public function findRecord($table, $key, $id)
    {
        $this->assertRecordKey($table, $key);
        return $this->row("SELECT * FROM `{$table}` WHERE `{$key}` = ? LIMIT 1", [(int) $id]);
    }

    public function findRecordScoped($table, $key, $id, array $user)
    {
        $this->assertRecordKey($table, $key);
        $role = $user['role'] ?? '';
        $userId = (int) ($user['user_id'] ?? 0);
        $scope = '1 = 0';
        $values = [(int) $id];

        if (in_array($role, ['administrator', 'staff'], true)) {
            $scope = '1 = 1';
        } elseif ($role === 'instructor') {
            switch ($table) {
                case 'trainees':
                    $scope = 'EXISTS (SELECT 1 FROM class_enrollments e JOIN training_classes c ON c.class_id = e.class_id WHERE e.trainee_id = r.trainee_id AND c.instructor_id = ?)';
                    $values[] = $userId;
                    break;
                case 'training_classes':
                    $scope = 'r.instructor_id = ?';
                    $values[] = $userId;
                    break;
                case 'training_schedules':
                case 'class_enrollments':
                case 'attendance_records':
                    $scope = 'EXISTS (SELECT 1 FROM training_classes c WHERE c.class_id = r.class_id AND c.instructor_id = ?)';
                    $values[] = $userId;
                    break;
                case 'trainee_documents':
                    $scope = "r.trainee_id IS NOT NULL AND r.access_level <> 'staff' AND EXISTS (SELECT 1 FROM training_classes c WHERE c.class_id = r.class_id AND c.instructor_id = ?)";
                    $values[] = $userId;
                    break;
                case 'inquiries':
                    $scope = 'EXISTS (SELECT 1 FROM class_enrollments e JOIN training_classes c ON c.class_id = e.class_id WHERE e.trainee_id = r.trainee_id AND c.instructor_id = ?)';
                    $values[] = $userId;
                    break;
                case 'announcements':
                    $scope = 'r.user_id = ?';
                    $values[] = $userId;
                    break;
            }
        } elseif ($role === 'trainee') {
            switch ($table) {
                case 'trainees':
                    $scope = 'r.user_id = ?';
                    $values[] = $userId;
                    break;
                case 'training_classes':
                    $scope = 'EXISTS (SELECT 1 FROM class_enrollments e JOIN trainees t ON t.trainee_id = e.trainee_id WHERE e.class_id = r.class_id AND t.user_id = ?)';
                    $values[] = $userId;
                    break;
                case 'training_schedules':
                    $scope = 'EXISTS (SELECT 1 FROM class_enrollments e JOIN trainees t ON t.trainee_id = e.trainee_id WHERE e.class_id = r.class_id AND t.user_id = ?)';
                    $values[] = $userId;
                    break;
                case 'class_enrollments':
                case 'attendance_records':
                    $scope = 'r.trainee_id IN (SELECT t.trainee_id FROM trainees t WHERE t.user_id = ?)';
                    $values[] = $userId;
                    break;
                case 'trainee_documents':
                    $scope = "r.access_level <> 'staff' AND (r.trainee_id IN (SELECT t.trainee_id FROM trainees t WHERE t.user_id = ?) OR (r.access_level = 'class' AND r.class_id IN (SELECT e.class_id FROM class_enrollments e JOIN trainees t ON t.trainee_id = e.trainee_id WHERE t.user_id = ?)))";
                    $values[] = $userId;
                    $values[] = $userId;
                    break;
                case 'inquiries':
                    $scope = 'r.trainee_id IN (SELECT t.trainee_id FROM trainees t WHERE t.user_id = ?)';
                    $values[] = $userId;
                    break;
            }
        }

        return $this->row("SELECT r.* FROM `{$table}` r WHERE r.`{$key}` = ? AND ({$scope}) LIMIT 1", $values);
    }

    public function saveRecord($table, $key, array $data, $id = null)
    {
        $this->assertRecordKey($table, $key);
        if (!$data) {
            throw new InvalidArgumentException('Cannot save a record without fields.');
        }
        unset($data[$key]);
        foreach (array_keys($data) as $field) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
                throw new InvalidArgumentException('Invalid record field.');
            }
        }

        if ($id !== null) {
            $this->db->table($table)->where($key, (int) $id)->update($data);
            return (int) $id;
        }

        $this->db->table($table)->insert($data);
        return (int) $this->db->last_id();
    }

    public function deleteRecord($table, $key, $id, $deactivate = false)
    {
        $this->assertRecordKey($table, $key);
        if ($deactivate && in_array($table, ['users', 'trainees', 'training_programs'], true)) {
            return $this->db->table($table)->where($key, (int) $id)->update(['status' => 'inactive']);
        }
        return $this->db->table($table)->where($key, (int) $id)->delete();
    }

    private function assertRecordKey($table, $key)
    {
        if (!isset(self::RECORD_KEYS[$table]) || self::RECORD_KEYS[$table] !== $key) {
            throw new InvalidArgumentException('Unsupported record table or key.');
        }
    }

    public function records($section, array $user)
    {
        $userId = (int) $user['user_id'];
        $role = $user['role'];

        if ($section === 'users') {
            return $this->db->table('users')
                ->select('user_id, full_name, username, role, status, created_at')
                ->get_all();
        }

        if ($section === 'trainees' && $role === 'instructor') {
            $rows = $this->db->table('trainees')
                ->select('trainees.trainee_id, trainees.first_name, trainees.last_name, trainees.sex, trainees.contact_number, trainees.course, trainees.status, trainees.qr_code, trainees.user_id, trainees.program_id, training_programs.program_name')
                ->join('class_enrollments', 'class_enrollments.trainee_id = trainees.trainee_id')
                ->join('training_classes', 'training_classes.class_id = class_enrollments.class_id')
                ->left_join('training_programs', 'training_programs.program_id = trainees.program_id')
                ->where('training_classes.instructor_id', $userId)
                ->group_by(['trainees.trainee_id', 'trainees.first_name', 'trainees.last_name', 'trainees.sex', 'trainees.contact_number', 'trainees.course', 'trainees.status', 'trainees.qr_code', 'trainees.user_id', 'trainees.program_id', 'training_programs.program_name'])
                ->get_all();
            usort($rows, static function ($left, $right) {
                $lastName = strcasecmp($left['last_name'], $right['last_name']);
                return $lastName !== 0 ? $lastName : strcasecmp($left['first_name'], $right['first_name']);
            });
            return $rows;
        }

        if ($section === 'trainees') {
            $query = $this->db->table('trainees')
                ->select('trainees.trainee_id, trainees.first_name, trainees.last_name, trainees.sex, trainees.date_of_birth, trainees.address, trainees.email, trainees.contact_number, trainees.course, trainees.status, trainees.qr_code, trainees.user_id, trainees.program_id, training_programs.program_name')
                ->left_join('training_programs', 'training_programs.program_id = trainees.program_id');
            if ($role === 'trainee') {
                return $query->where('trainees.user_id', $userId)->limit(1)->get_all();
            }
            return $query->get_all();
        }

        if ($section === 'programs') {
            return $this->db->table('training_programs')
                ->select('program_id, program_code, program_name, description, status')
                ->get_all();
        }

        if ($section === 'classes') {
            $query = $this->db->table('training_classes')
                ->select('training_classes.class_id, training_classes.class_name, training_classes.program_id, training_programs.program_name, training_classes.instructor_id, users.full_name AS instructor_name, training_classes.start_date, training_classes.end_date, training_classes.status')
                ->join('training_programs', 'training_programs.program_id = training_classes.program_id')
                ->left_join('users', 'users.user_id = training_classes.instructor_id');
            if ($role === 'instructor') {
                return $query->where('training_classes.instructor_id', $userId)->get_all();
            }
            if ($role === 'trainee') {
                return $this->rows(
                    'SELECT c.class_id, c.class_name, c.program_id, p.program_name, c.instructor_id, u.full_name AS instructor_name, c.start_date, c.end_date, c.status FROM training_classes c JOIN training_programs p ON p.program_id = c.program_id LEFT JOIN users u ON u.user_id = c.instructor_id JOIN class_enrollments e ON e.class_id = c.class_id WHERE e.trainee_id = (SELECT trainee_id FROM trainees WHERE user_id = ? LIMIT 1)',
                    [$userId]
                );
            }
            return $query->get_all();
        }

        if ($section === 'schedules' && $role === 'instructor') {
            return $this->db->table('training_schedules')
                ->select('training_schedules.schedule_id, training_schedules.class_id, training_classes.class_name, training_schedules.schedule_date, training_schedules.start_time, training_schedules.end_time')
                ->left_join('training_classes', 'training_classes.class_id = training_schedules.class_id')
                ->join('training_classes owned_class', 'owned_class.class_id = training_schedules.class_id')
                ->where('owned_class.instructor_id', $userId)
                ->get_all();
        }

        if ($section === 'schedules') {
            return $this->db->table('training_schedules')
                ->select('training_schedules.schedule_id, training_schedules.class_id, training_classes.class_name, training_schedules.schedule_date, training_schedules.start_time, training_schedules.end_time')
                ->left_join('training_classes', 'training_classes.class_id = training_schedules.class_id')
                ->get_all();
        }

        if ($section === 'announcements' && $role !== 'administrator' && $role !== 'staff') {
            $rows = $this->db->table('announcements')
                ->select('announcement_id, title, content, status, created_at')
                ->where('status', 'published')
                ->order_by('created_at', 'DESC')
                ->get_all();
            foreach ($rows as &$row) {
                $row['author'] = null;
            }
            unset($row);
            return $rows;
        }

        if ($section === 'announcements') {
            return $this->db->table('announcements')
                ->select('announcements.announcement_id, announcements.title, announcements.content, announcements.status, announcements.created_at, users.full_name AS author')
                ->left_join('users', 'users.user_id = announcements.user_id')
                ->get_all();
        }

        if ($section === 'enrollments') {
            $sql = 'SELECT e.enrollment_id, e.class_id, c.class_name, e.trainee_id, CONCAT(t.first_name, \' \', t.last_name) AS trainee_name, e.enrolled_at, CASE WHEN NOT EXISTS (SELECT 1 FROM trainee_fees tf WHERE tf.trainee_id = e.trainee_id AND tf.class_id = e.class_id AND tf.fee_kind = \'registration\') THEN \'Pending payment\' WHEN (SELECT COALESCE(SUM(GREATEST(tf.amount_due - COALESCE((SELECT SUM(CASE WHEN pay.entry_type = \'payment\' THEN pay.amount WHEN pay.entry_type = \'reversal\' THEN -pay.amount ELSE 0 END) FROM payments pay WHERE pay.trainee_fee_id = tf.trainee_fee_id), 0), 0)), 0) FROM trainee_fees tf WHERE tf.trainee_id = e.trainee_id AND tf.class_id = e.class_id AND tf.fee_kind = \'registration\') > 0 THEN \'Pending payment\' ELSE \'Paid\' END AS registration_payment_status FROM class_enrollments e JOIN training_classes c ON c.class_id = e.class_id JOIN trainees t ON t.trainee_id = e.trainee_id';
            if ($role === 'instructor') {
                return $this->rows($sql . ' WHERE c.instructor_id = ?', [$userId]);
            }
            return $this->rows($sql);
        }

        if ($section === 'attendance') {
            $query = $this->db->table('attendance_records')
                ->select('attendance_records.attendance_id, attendance_records.trainee_id, trainees.first_name, trainees.last_name, attendance_records.class_id, training_classes.class_name, attendance_records.schedule_id, attendance_records.attendance_date, attendance_records.time_in, attendance_records.time_out, attendance_records.status, attendance_records.remarks')
                ->join('trainees', 'trainees.trainee_id = attendance_records.trainee_id')
                ->left_join('training_classes', 'training_classes.class_id = attendance_records.class_id');
            if ($role === 'trainee') {
                $query->where('trainees.user_id', $userId);
            } elseif ($role === 'instructor') {
                $query->join('training_classes owned_class', 'owned_class.class_id = attendance_records.class_id')
                    ->where('owned_class.instructor_id', $userId);
            }
            return $this->addTraineeName($query->get_all());
        }

        if ($section === 'documents') {
            if ($role === 'trainee') {
                return $this->rows("SELECT d.document_id, d.trainee_id, d.class_id, COALESCE(CONCAT(t.first_name, ' ', t.last_name), 'Institution') AS trainee_name, d.document_name, d.upload_date, d.review_status, d.status, d.access_level FROM trainee_documents d LEFT JOIN trainees t ON t.trainee_id = d.trainee_id WHERE d.access_level <> 'staff' AND (t.user_id = ? OR (d.access_level = 'class' AND d.class_id IN (SELECT e.class_id FROM class_enrollments e JOIN trainees mine ON mine.trainee_id = e.trainee_id WHERE mine.user_id = ?)))", [$userId, $userId]);
            }
            $query = $this->db->table('trainee_documents')
                ->select('trainee_documents.document_id, trainee_documents.trainee_id, trainee_documents.class_id, trainees.first_name, trainees.last_name, trainee_documents.document_name, trainee_documents.upload_date, trainee_documents.review_status, trainee_documents.status, trainee_documents.access_level')
                ->left_join('trainees', 'trainees.trainee_id = trainee_documents.trainee_id');
            if ($role === 'instructor') {
                $query->join('training_classes', 'training_classes.class_id = trainee_documents.class_id')
                    ->where_not_null('trainee_documents.trainee_id')
                    ->where('trainee_documents.access_level', '<>', 'staff')
                    ->where('training_classes.instructor_id', $userId);
            }
            return $this->addTraineeName($query->get_all(), 'Institution');
        }

        if ($section === 'inquiries') {
            if ($role === 'instructor') {
                return $this->addTraineeName($this->rows(
                    'SELECT i.inquiry_id, i.trainee_id, t.first_name, t.last_name, i.subject, i.message, i.reply, i.status, i.created_at FROM inquiries i JOIN trainees t ON t.trainee_id = i.trainee_id WHERE EXISTS (SELECT 1 FROM class_enrollments e JOIN training_classes c ON c.class_id = e.class_id WHERE e.trainee_id = i.trainee_id AND c.instructor_id = ?)',
                    [$userId]
                ));
            }
            $query = $this->db->table('inquiries')
                ->select('inquiries.inquiry_id, inquiries.trainee_id, trainees.first_name, trainees.last_name, inquiries.subject, inquiries.message, inquiries.reply, inquiries.status, inquiries.created_at')
                ->join('trainees', 'trainees.trainee_id = inquiries.trainee_id');
            if ($role === 'trainee') {
                $query->where('trainees.user_id', $userId);
            }
            return $this->addTraineeName($query->get_all());
        }

        if ($section === 'activity') {
            $rows = $this->db->table('activity_logs')
                ->select('activity_logs.log_id, activity_logs.user_id, users.full_name AS user_name, activity_logs.action, activity_logs.details, activity_logs.created_at')
                ->left_join('users', 'users.user_id = activity_logs.user_id')
                ->limit(200)
                ->get_all();
            foreach ($rows as &$row) {
                $row['user_name'] = $row['user_name'] ?? 'System';
            }
            unset($row);
            return $rows;
        }

        return [];
    }

    public function options($section, $user = null)
    {
        if ($section === 'programs') {
            return $this->db->table('training_programs')
                ->select('program_id AS id, program_name AS label')
                ->where('status', 'active')
                ->order_by('program_name')
                ->get_all();
        }
        if ($section === 'classes') {
            $query = $this->db->table('training_classes')
                ->select('class_id AS id, class_name AS label')
                ->where('status', 'active');
            if ($user && $user['role'] === 'instructor') {
                $query->where('instructor_id', (int) $user['user_id']);
            }
            return $query->order_by('class_name')->get_all();
        }
        if ($section === 'certificate_classes') {
            return $this->certificateClassOptions();
        }
        if ($section === 'instructors') {
            return $this->db->table('users')
                ->select('user_id AS id, full_name AS label')
                ->where(['role' => 'instructor', 'status' => 'active'])
                ->order_by('full_name')
                ->get_all();
        }

        if ($section === 'trainees') {
            $query = $this->db->table('trainees')
                ->select('trainees.trainee_id AS id, trainees.first_name, trainees.last_name')
                ->where('trainees.status', 'active');
            if ($user && $user['role'] === 'instructor') {
                $query->join('class_enrollments', 'class_enrollments.trainee_id = trainees.trainee_id')
                    ->join('training_classes', 'training_classes.class_id = class_enrollments.class_id')
                    ->where('training_classes.instructor_id', (int) $user['user_id'])
                    ->group_by(['trainees.trainee_id', 'trainees.first_name', 'trainees.last_name']);
            } elseif ($user && $user['role'] === 'trainee') {
                $query->where('trainees.user_id', (int) $user['user_id']);
            }
            $rows = $query->get_all();
            if (!$user || $user['role'] !== 'trainee') {
                usort($rows, static function ($left, $right) use ($user) {
                    if ($user && $user['role'] === 'instructor') {
                        $firstName = strcasecmp($left['first_name'], $right['first_name']);
                        return $firstName !== 0 ? $firstName : strcasecmp($left['last_name'], $right['last_name']);
                    }
                    $lastName = strcasecmp($left['last_name'], $right['last_name']);
                    return $lastName !== 0 ? $lastName : strcasecmp($left['first_name'], $right['first_name']);
                });
            }
            return $this->formatOptions($rows, static function ($row) {
                return $row['first_name'] . ' ' . $row['last_name'];
            });
        }

        if ($section === 'schedules') {
            $query = $this->db->table('training_schedules')
                ->select('training_schedules.schedule_id AS id, training_schedules.schedule_date, training_schedules.start_time');
            if ($user && $user['role'] === 'instructor') {
                $query->join('training_classes', 'training_classes.class_id = training_schedules.class_id')
                    ->where('training_classes.instructor_id', (int) $user['user_id']);
            } elseif ($user && $user['role'] === 'trainee') {
                $query->join('class_enrollments', 'class_enrollments.class_id = training_schedules.class_id')
                    ->join('trainees', 'trainees.trainee_id = class_enrollments.trainee_id')
                    ->where('trainees.user_id', (int) $user['user_id']);
            }
            $rows = $query->order_by('training_schedules.schedule_date', 'DESC')->get_all();
            return $this->formatOptions($rows, static function ($row) {
                return $row['schedule_date'] . ' - ' . $row['start_time'];
            });
        }

        if ($section === 'user_accounts') {
            $rows = $this->db->table('users')
                ->select('user_id AS id, full_name, username')
                ->where(['role' => 'trainee', 'status' => 'active'])
                ->order_by('full_name')
                ->get_all();
            return $this->formatOptions($rows, static function ($row) {
                return $row['full_name'] . ' (' . $row['username'] . ')';
            });
        }

        return [];
    }

    public function announcementForNotifications($announcementId)
    {
        return $this->db->table('announcements')
            ->select('announcement_id, title, content, published_at, status')
            ->where('announcement_id', (int) $announcementId)
            ->get() ?: null;
    }

    public function failedNotification($notificationId)
    {
        return $this->db->table('announcement_notifications')
            ->select('announcement_id')
            ->where(['id' => (int) $notificationId, 'status' => 'failed'])
            ->get() ?: null;
    }

    public function notificationExists($notificationId)
    {
        return $this->db->table('announcement_notifications')
            ->select('id')
            ->where('id', (int) $notificationId)
            ->get() ?: null;
    }

    public function retryNotification($notificationId)
    {
        return $this->db->table('announcement_notifications')
            ->where('id', (int) $notificationId)
            ->update(['status' => 'pending', 'error_message' => null]);
    }

    public function deleteNotification($notificationId)
    {
        return $this->db->table('announcement_notifications')
            ->where('id', (int) $notificationId)
            ->delete();
    }

    public function saveNotificationSettings($emailEnabled)
    {
        return $this->execute(
            'INSERT INTO notification_settings (id, email_enabled) VALUES (1, ?) ON DUPLICATE KEY UPDATE email_enabled = VALUES(email_enabled)',
            [(int) (bool) $emailEnabled]
        );
    }

    public function isActiveProgram($programId)
    {
        return (bool) $this->db->table('training_programs')
            ->select('program_id')
            ->where(['program_id' => (int) $programId, 'status' => 'active'])
            ->get();
    }

    public function certificateClassOptions()
    {
        return $this->db->table('training_classes')
            ->select('class_id AS id, class_name AS label')
            ->order_by('class_name')
            ->get_all();
    }

    private function addTraineeName(array $rows, $nullFallback = null)
    {
        foreach ($rows as &$row) {
            $name = $row['first_name'] === null || $row['last_name'] === null
                ? $nullFallback
                : $row['first_name'] . ' ' . $row['last_name'];
            $position = array_search('first_name', array_keys($row), true);
            $row = array_slice($row, 0, $position, true)
                + ['trainee_name' => $name]
                + array_slice($row, $position + 2, null, true);
        }
        unset($row);
        return $rows;
    }

    private function formatOptions(array $rows, callable $label)
    {
        $options = [];
        foreach ($rows as $row) {
            $options[] = ['id' => $row['id'], 'label' => $label($row)];
        }
        return $options;
    }
}
