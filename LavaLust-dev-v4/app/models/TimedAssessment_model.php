<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';

class TimedAssessmentAuthorizationException extends RuntimeException
{
}

class TimedAssessmentNotFoundException extends RuntimeException
{
}

class TimedAssessmentConflictException extends RuntimeException
{
}

class TimedAssessmentValidationException extends InvalidArgumentException
{
}

class TimedAssessment_model extends Base_model
{
    public function classOptions(array $user): array
    {
        $query = $this->db->table('training_classes')
            ->select('class_id AS id, class_name AS label');
        if (($user['role'] ?? '') === 'instructor') {
            $query->where('instructor_id', (int) $user['user_id']);
        } elseif (!in_array($user['role'] ?? '', ['administrator', 'staff'], true)) {
            return [];
        }

        return $query->where('status', 'active')->order_by('class_name')->get_all();
    }

    public function bundle(array $user): array
    {
        $this->assertPortalRole($user);
        $now = $this->row('SELECT UTC_TIMESTAMP() AS server_time');
        $this->notifyElapsedDeadlines($user);
        $this->notifyDueSoon();

        if (($user['role'] ?? '') === 'trainee') {
            return [
                'server_time' => $this->utcIso($now['server_time'] ?? null),
                'role' => 'trainee',
                'assessments' => $this->traineeAssessments($user),
                'notifications' => $this->notifications($user),
            ];
        }

        $filters = [];
        return [
            'server_time' => $this->utcIso($now['server_time'] ?? null),
            'role' => $user['role'],
            'assessments' => $this->managedAssessments($user, $filters),
            'classes' => $this->classOptions($user),
            'trainees' => $this->traineeOptions($user),
            'instructors' => $this->instructorOptions($user),
            'notifications' => $this->notifications($user),
        ];
    }

    public function save(array $input, array $user): int
    {
        error_log('TimedAssessment_model::save - Starting save process');
        $this->assertTeachingStaff($user);
        error_log('TimedAssessment_model::save - Teaching staff assertion passed');
        $data = $this->validateAssessment($input);
        error_log('TimedAssessment_model::save - Validation passed, data: ' . json_encode($data));
        $id = $data['id'];
        $this->begin();

        try {
            $previous = null;
            if ($id !== null) {
                error_log('TimedAssessment_model::save - Updating existing assessment ID: ' . $id);
                $previous = $this->assessmentById($id, $user, true);
                if (!$previous) {
                    throw new TimedAssessmentNotFoundException('Timed assessment not found.');
                }
                if ($previous['status'] !== 'draft') {
                    if ((int) $previous['class_id'] !== $data['class_id']
                        || $previous['start_at'] !== $data['start_at']
                        || $previous['scheduled_end_at'] !== $data['scheduled_end_at']
                        || (int) $previous['duration_minutes'] !== $data['duration_minutes']) {
                        throw new TimedAssessmentConflictException('Published assessment schedule cannot be changed.');
                    }
                }
            } else {
                error_log('TimedAssessment_model::save - Creating new assessment');
            }

            $this->assertClassAccess($data['class_id'], $user);
            error_log('TimedAssessment_model::save - Class access assertion passed for class_id: ' . $data['class_id']);
            if ($previous) {
                error_log('TimedAssessment_model::save - Executing UPDATE query');
                $this->execute(
                    'UPDATE timed_assessments
                     SET class_id = ?, assessment_title = ?, assessment_type = ?, description = ?, instructions = ?,
                         start_at = ?, scheduled_end_at = ?, deadline_at = ?, duration_minutes = ?,
                         allow_late_submission = ?, updated_at = UTC_TIMESTAMP()
                     WHERE timed_assessment_id = ?',
                    [
                        $data['class_id'], $data['title'], $data['type'], $data['description'], $data['instructions'],
                        $data['start_at'], $data['scheduled_end_at'], $data['deadline_at'], $data['duration_minutes'],
                        $data['allow_late_submission'], $id,
                    ]
                );
                $this->writeAudit($id, null, 'assessment_updated', $this->auditValues($previous), $this->auditValues($data), $user);
            } else {
                error_log('TimedAssessment_model::save - Executing INSERT query');
                $this->execute(
                    'INSERT INTO timed_assessments
                     (class_id, created_by, assessment_title, assessment_type, description, instructions,
                      start_at, scheduled_end_at, deadline_at, duration_minutes, allow_late_submission, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft")',
                    [
                        $data['class_id'], (int) $user['user_id'], $data['title'], $data['type'], $data['description'],
                        $data['instructions'], $data['start_at'], $data['scheduled_end_at'], $data['deadline_at'],
                        $data['duration_minutes'], $data['allow_late_submission'],
                    ]
                );
                $id = (int) $this->db->last_id();
                error_log('TimedAssessment_model::save - Insert completed, new ID: ' . $id);
                if ($id < 1) {
                    throw new RuntimeException('Timed assessment insert did not return an ID.');
                }
                $this->writeAudit($id, null, 'assessment_created', null, $this->auditValues($data), $user);
            }
            $this->commit();
            error_log('TimedAssessment_model::save - Transaction committed successfully');
            return (int) $id;
        } catch (Throwable $error) {
            error_log('TimedAssessment_model::save - Error: ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine());
            $this->rollback();
            throw $error;
        }
    }

    public function publish(int $assessmentId, array $user): int
    {
        $this->assertTeachingStaff($user);
        $this->begin();
        try {
            $assessment = $this->assessmentById($assessmentId, $user, true);
            if (!$assessment) {
                throw new TimedAssessmentNotFoundException('Timed assessment not found.');
            }
            if ($assessment['status'] !== 'draft') {
                throw new TimedAssessmentConflictException('Only draft assessments can be published.');
            }

            $this->assertClassAccess((int) $assessment['class_id'], $user);
            $now = $this->row('SELECT UTC_TIMESTAMP() AS now_utc')['now_utc'];
            if ($assessment['deadline_at'] <= $now) {
                throw new TimedAssessmentValidationException('The assessment deadline has already passed.');
            }
            $count = $this->execute(
                'INSERT IGNORE INTO timed_assessment_recipients (timed_assessment_id, trainee_id, deadline_at)
                 SELECT ?, e.trainee_id, ?
                 FROM class_enrollments e
                 JOIN trainees t ON t.trainee_id = e.trainee_id
                 WHERE e.class_id = ? AND t.status = "active"',
                [$assessmentId, $assessment['deadline_at'], (int) $assessment['class_id']]
            );
            if ($count < 1) {
                throw new TimedAssessmentValidationException('This class has no active enrolled trainees.');
            }

            $this->execute(
                'UPDATE timed_assessments SET status = "published", updated_at = UTC_TIMESTAMP()
                 WHERE timed_assessment_id = ? AND status = "draft"',
                [$assessmentId]
            );
            $this->writeAudit($assessmentId, null, 'assessment_published', 'draft', 'published', $user);
            $this->notifyNewAssignment($assessmentId);
            $this->commit();
            return $count;
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function close(int $assessmentId, array $user): void
    {
        $this->assertTeachingStaff($user);
        $this->begin();
        try {
            $assessment = $this->assessmentById($assessmentId, $user, true);
            if (!$assessment) {
                throw new TimedAssessmentNotFoundException('Timed assessment not found.');
            }
            if ($assessment['status'] !== 'published') {
                throw new TimedAssessmentConflictException('Only published assessments can be closed.');
            }
            $this->execute(
                'UPDATE timed_assessments SET status = "closed", updated_at = UTC_TIMESTAMP()
                 WHERE timed_assessment_id = ? AND status = "published"',
                [$assessmentId]
            );
            $this->writeAudit($assessmentId, null, 'assessment_closed', 'published', 'closed', $user);
            $this->commit();
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function submit(int $assessmentId, array $input, array $user): array
    {
        $this->assertTrainee($user);
        $traineeId = $this->traineeIdForUser((int) $user['user_id']);
        if (!$traineeId) {
            throw new TimedAssessmentAuthorizationException('No trainee profile is linked to your account.');
        }

        $scoreValue = $input['score'] ?? '';
        $score = null;
        if ($scoreValue !== '' && $scoreValue !== null) {
            if (!is_scalar($scoreValue) || !is_numeric((string) $scoreValue)
                || (float) $scoreValue < 0 || (float) $scoreValue > 100) {
                throw new TimedAssessmentValidationException('Score must be between 0 and 100.');
            }
            $score = number_format((float) $scoreValue, 2, '.', '');
        }
        $resultValue = $input['result_text'] ?? '';
        if (!is_string($resultValue)) {
            throw new TimedAssessmentValidationException('Enter a valid assessment result.');
        }
        $result = trim($resultValue);
        if (mb_strlen($result) > 5000) {
            throw new TimedAssessmentValidationException('Assessment result must be 5000 characters or fewer.');
        }
        if ($score === null && $result === '') {
            throw new TimedAssessmentValidationException('Enter a score or assessment result before submitting.');
        }

        $this->begin();
        try {
            $assignment = $this->row(
                'SELECT a.timed_assessment_id, a.assessment_title, a.status, a.allow_late_submission,
                        a.start_at, r.deadline_at, r.is_excused,
                        EXISTS(SELECT 1 FROM timed_assessment_extensions x
                               WHERE x.timed_assessment_id = r.timed_assessment_id
                                 AND x.trainee_id = r.trainee_id
                                 AND x.extended_deadline > r.deadline_at) AS has_extension,
                        COALESCE((SELECT MAX(x.extended_deadline) FROM timed_assessment_extensions x
                                  WHERE x.timed_assessment_id = r.timed_assessment_id AND x.trainee_id = r.trainee_id),
                                 r.deadline_at) AS effective_deadline
                 FROM timed_assessments a
                 JOIN timed_assessment_recipients r ON r.timed_assessment_id = a.timed_assessment_id
                 WHERE a.timed_assessment_id = ? AND r.trainee_id = ? LIMIT 1 FOR UPDATE',
                [$assessmentId, $traineeId]
            );
            if (!$assignment || $assignment['status'] !== 'published') {
                throw new TimedAssessmentNotFoundException('This assessment is not available for submission.');
            }
            if ((int) $assignment['is_excused'] === 1) {
                throw new TimedAssessmentConflictException('You are excused from this assessment.');
            }
            if ($this->row(
                'SELECT submission_id FROM timed_assessment_submissions
                 WHERE timed_assessment_id = ? AND trainee_id = ? LIMIT 1 FOR UPDATE',
                [$assessmentId, $traineeId]
            )) {
                throw new TimedAssessmentConflictException('A submission has already been recorded.');
            }
            $now = $this->row('SELECT UTC_TIMESTAMP() AS now_utc')['now_utc'];
            if ($now < $assignment['start_at']) {
                throw new TimedAssessmentConflictException('The assessment submission period has not started.');
            }
            $isLate = $now > $assignment['effective_deadline'];
            if ($isLate && !(int) $assignment['allow_late_submission'] && !(int) $assignment['has_extension']) {
                throw new TimedAssessmentConflictException('The submission deadline has passed.');
            }

            $this->execute(
                'INSERT INTO timed_assessment_submissions
                 (timed_assessment_id, trainee_id, submitted_score, result_text, submitted_at)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
                [$assessmentId, $traineeId, $score, $result === '' ? null : $result]
            );
            $submissionId = (int) $this->db->last_id();
            $this->writeAudit(
                $assessmentId,
                $traineeId,
                $isLate ? 'submission_late' : 'submission_on_time',
                null,
                json_encode(['submission_id' => $submissionId, 'score' => $score], JSON_THROW_ON_ERROR),
                $user
            );
            $this->notifySubmission($assessmentId, $traineeId, $isLate);
            $this->commit();
            return ['submission_id' => $submissionId, 'status' => $isLate ? 'late' : 'on_time'];
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function adjustRecipient(int $assessmentId, int $traineeId, array $input, array $user): void
    {
        $this->assertTeachingStaff($user);
        $assessment = $this->assessmentById($assessmentId, $user);
        if (!$assessment) {
            throw new TimedAssessmentNotFoundException('Timed assessment not found.');
        }
        $recipient = $this->row(
            'SELECT r.deadline_at, r.is_excused, r.excuse_reason,
                    COALESCE((SELECT MAX(x.extended_deadline) FROM timed_assessment_extensions x
                              WHERE x.timed_assessment_id = r.timed_assessment_id AND x.trainee_id = r.trainee_id),
                             r.deadline_at) AS effective_deadline
             FROM timed_assessment_recipients r
             WHERE r.timed_assessment_id = ? AND r.trainee_id = ?',
            [$assessmentId, $traineeId]
        );
        if (!$recipient) {
            throw new TimedAssessmentNotFoundException('Trainee is not assigned to this assessment.');
        }

        $action = $input['action'] ?? '';
        $reason = $this->validatedText($input['reason'] ?? '', 'Provide a reason.', 500);
        $this->begin();
        try {
            if ($action === 'excuse') {
                $this->execute(
                    'UPDATE timed_assessment_recipients SET is_excused = 1, excuse_reason = ?
                     WHERE timed_assessment_id = ? AND trainee_id = ?',
                    [$reason, $assessmentId, $traineeId]
                );
                $this->writeAudit($assessmentId, $traineeId, 'trainee_excused', $recipient['excuse_reason'], $reason, $user);
                $this->notifyTrainee($assessmentId, $traineeId, 'excused', 'You have been excused from this assessment.');
                $this->commit();
                return;
            }
            if ($action !== 'extend') {
                throw new TimedAssessmentValidationException('Choose an extension or exemption action.');
            }

            $extendedLocal = $this->requiredString($input['extended_deadline'] ?? null, 'Choose an extended deadline.');
            $extendedDeadline = $this->localDateTimeToUtc($extendedLocal, 'Choose a valid extended deadline.');
            if ($extendedDeadline <= $recipient['effective_deadline']) {
                throw new TimedAssessmentValidationException('The extended deadline must be later than the current deadline.');
            }
            $this->execute(
                'INSERT INTO timed_assessment_extensions
                 (timed_assessment_id, trainee_id, previous_deadline, extended_deadline, approved_by, reason)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$assessmentId, $traineeId, $recipient['effective_deadline'], $extendedDeadline, (int) $user['user_id'], $reason]
            );
            $extensionId = (int) $this->db->last_id();
            $this->writeAudit(
                $assessmentId,
                $traineeId,
                'deadline_extended',
                $recipient['effective_deadline'],
                $extendedDeadline,
                $user
            );
            $this->notifyTrainee($assessmentId, $traineeId, 'extension_' . $extensionId, 'Your assessment deadline has been extended.');
            $this->commit();
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function monitoring(array $user, array $filters = []): array
    {
        $this->assertTeachingStaff($user);
        return $this->filteredMonitoringRows($user, $filters);
    }

    public function report(array $user, array $filters = []): array
    {
        return $this->monitoring($user, $filters);
    }

    public function notifications(array $user): array
    {
        return $this->rows(
            'SELECT notification_id, timed_assessment_id, trainee_id, event_key, message,
                    created_at, read_at
             FROM timed_assessment_notifications
             WHERE recipient_user_id = ?
             ORDER BY created_at DESC LIMIT 50',
            [(int) $user['user_id']]
        );
    }

    private function traineeAssessments(array $user): array
    {
        $traineeId = $this->traineeIdForUser((int) $user['user_id']);
        if (!$traineeId) {
            return [];
        }
        $rows = $this->rows(
            'SELECT a.timed_assessment_id, a.assessment_title, a.assessment_type, a.description,
                    a.instructions, a.start_at, a.scheduled_end_at, a.deadline_at,
                    a.duration_minutes, a.allow_late_submission, a.status,
                    c.class_name, u.full_name AS instructor_name, r.is_excused, r.excuse_reason,
                    COALESCE((SELECT MAX(x.extended_deadline) FROM timed_assessment_extensions x
                              WHERE x.timed_assessment_id = r.timed_assessment_id AND x.trainee_id = r.trainee_id),
                             r.deadline_at) AS effective_deadline,
                    s.submission_id, s.submitted_score, s.result_text, s.submitted_at,
                    CASE
                      WHEN r.is_excused = 1 THEN "excused"
                      WHEN s.submission_id IS NOT NULL AND s.submitted_at <= COALESCE(
                        (SELECT MAX(x.extended_deadline) FROM timed_assessment_extensions x
                         WHERE x.timed_assessment_id = r.timed_assessment_id AND x.trainee_id = r.trainee_id),
                        r.deadline_at
                      ) THEN "on_time"
                      WHEN s.submission_id IS NOT NULL THEN "late"
                      WHEN a.status = "closed" THEN "not_submitted"
                      WHEN UTC_TIMESTAMP() <= COALESCE(
                        (SELECT MAX(x.extended_deadline) FROM timed_assessment_extensions x
                         WHERE x.timed_assessment_id = r.timed_assessment_id AND x.trainee_id = r.trainee_id),
                        r.deadline_at
                      ) THEN "pending"
                      ELSE "not_submitted"
                    END AS submission_status
             FROM timed_assessment_recipients r
             JOIN timed_assessments a ON a.timed_assessment_id = r.timed_assessment_id
             JOIN training_classes c ON c.class_id = a.class_id
             LEFT JOIN users u ON u.user_id = c.instructor_id
             LEFT JOIN timed_assessment_submissions s
               ON s.timed_assessment_id = r.timed_assessment_id AND s.trainee_id = r.trainee_id
             WHERE r.trainee_id = ? AND a.status IN ("published", "closed")
             ORDER BY a.start_at DESC, a.timed_assessment_id DESC',
            [$traineeId]
        );
        foreach ($rows as &$row) {
            foreach (['start_at', 'scheduled_end_at', 'deadline_at', 'effective_deadline', 'submitted_at'] as $dateField) {
                $row[$dateField] = $this->utcIso($row[$dateField] ?? null);
            }
        }
        unset($row);
        return $rows;
    }

    private function managedAssessments(array $user, array $filters): array
    {
        $where = [];
        $values = [];
        if (($user['role'] ?? '') === 'instructor') {
            $where[] = 'c.instructor_id = ?';
            $values[] = (int) $user['user_id'];
        }
        if (!empty($filters['class_id'])) {
            $where[] = 'a.class_id = ?';
            $values[] = (int) $filters['class_id'];
        }
        $sql = 'SELECT a.timed_assessment_id, a.class_id, a.assessment_title, a.assessment_type,
                       a.description, a.instructions, a.start_at, a.scheduled_end_at, a.deadline_at,
                       a.duration_minutes, a.allow_late_submission, a.status,
                       c.class_name, instructor.full_name AS instructor_name,
                       COUNT(r.recipient_id) AS assigned_count,
                       SUM(CASE WHEN r.recipient_id IS NOT NULL AND r.is_excused = 1 THEN 1 ELSE 0 END) AS excused_count
                FROM timed_assessments a
                JOIN training_classes c ON c.class_id = a.class_id
                LEFT JOIN users instructor ON instructor.user_id = c.instructor_id
                LEFT JOIN timed_assessment_recipients r ON r.timed_assessment_id = a.timed_assessment_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' GROUP BY a.timed_assessment_id ORDER BY a.start_at DESC, a.timed_assessment_id DESC';

        $rows = $this->rows($sql, $values);
        foreach ($rows as &$row) {
            foreach (['start_at', 'scheduled_end_at', 'deadline_at'] as $dateField) {
                $row[$dateField] = $this->utcIso($row[$dateField] ?? null);
            }
        }
        unset($row);
        return $rows;
    }

    private function filteredMonitoringRows(array $user, array $filters): array
    {
        $innerWhere = ['a.status IN ("published", "closed")'];
        $values = [];
        if (($user['role'] ?? '') === 'instructor') {
            $innerWhere[] = 'c.instructor_id = ?';
            $values[] = (int) $user['user_id'];
        }
        foreach (['timed_assessment_id' => 'a.timed_assessment_id', 'class_id' => 'a.class_id', 'instructor_id' => 'c.instructor_id'] as $field => $column) {
            if (!empty($filters[$field])) {
                $innerWhere[] = $column . ' = ?';
                $values[] = (int) $filters[$field];
            }
        }
        if (!empty($filters['trainee'])) {
            $innerWhere[] = 'CONCAT(t.first_name, " ", t.last_name) LIKE ?';
            $values[] = '%' . $filters['trainee'] . '%';
        }
        if (!empty($filters['assessment_date'])) {
            $innerWhere[] = 'DATE(DATE_ADD(a.start_at, INTERVAL 8 HOUR)) = ?';
            $values[] = $filters['assessment_date'];
        }

        $sql = 'SELECT * FROM (
                  SELECT a.timed_assessment_id, a.assessment_title, a.assessment_type, a.class_id,
                         a.start_at, a.deadline_at, a.status AS assessment_status,
                         c.class_name, c.instructor_id, instructor.full_name AS instructor_name,
                         r.trainee_id, CONCAT(t.first_name, " ", t.last_name) AS trainee_name,
                         t.qr_code AS trainee_number, r.is_excused, r.excuse_reason,
                         COALESCE((SELECT MAX(x.extended_deadline) FROM timed_assessment_extensions x
                                   WHERE x.timed_assessment_id = r.timed_assessment_id AND x.trainee_id = r.trainee_id),
                                  r.deadline_at) AS effective_deadline,
                         s.submission_id, s.submitted_score, s.result_text, s.remarks, s.submitted_at,
                         CASE
                           WHEN r.is_excused = 1 THEN "excused"
                           WHEN s.submission_id IS NOT NULL AND s.submitted_at <= COALESCE(
                             (SELECT MAX(x.extended_deadline) FROM timed_assessment_extensions x
                              WHERE x.timed_assessment_id = r.timed_assessment_id AND x.trainee_id = r.trainee_id),
                             r.deadline_at
                           ) THEN "on_time"
                           WHEN s.submission_id IS NOT NULL THEN "late"
                           WHEN a.status = "closed" THEN "not_submitted"
                           WHEN UTC_TIMESTAMP() <= COALESCE(
                             (SELECT MAX(x.extended_deadline) FROM timed_assessment_extensions x
                              WHERE x.timed_assessment_id = r.timed_assessment_id AND x.trainee_id = r.trainee_id),
                             r.deadline_at
                           ) THEN "pending"
                           ELSE "not_submitted"
                         END AS submission_status
                  FROM timed_assessment_recipients r
                  JOIN timed_assessments a ON a.timed_assessment_id = r.timed_assessment_id
                  JOIN training_classes c ON c.class_id = a.class_id
                  JOIN trainees t ON t.trainee_id = r.trainee_id
                  LEFT JOIN users instructor ON instructor.user_id = c.instructor_id
                  LEFT JOIN timed_assessment_submissions s
                    ON s.timed_assessment_id = r.timed_assessment_id AND s.trainee_id = r.trainee_id
                  WHERE ' . implode(' AND ', $innerWhere) . '
                ) monitoring WHERE 1 = 1';
        if (!empty($filters['submission_status'])) {
            $sql .= ' AND submission_status = ?';
            $values[] = $filters['submission_status'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (trainee_name LIKE ? OR assessment_title LIKE ? OR class_name LIKE ?)';
            $search = '%' . $filters['search'] . '%';
            array_push($values, $search, $search, $search);
        }
        $sql .= ' ORDER BY start_at DESC, assessment_title, trainee_name LIMIT 2000';
        $rows = $this->rows($sql, $values);
        foreach ($rows as &$row) {
            foreach (['start_at', 'deadline_at', 'effective_deadline', 'submitted_at'] as $dateField) {
                $row[$dateField] = $this->utcIso($row[$dateField] ?? null);
            }
        }
        unset($row);
        return $rows;
    }

    private function notifyElapsedDeadlines(array $user): void
    {
        $rows = $this->rows(
            'SELECT r.timed_assessment_id, r.trainee_id, a.assessment_title, c.instructor_id,
                    t.user_id AS trainee_user_id,
                    COALESCE((SELECT MAX(x.extended_deadline) FROM timed_assessment_extensions x
                              WHERE x.timed_assessment_id = r.timed_assessment_id AND x.trainee_id = r.trainee_id),
                             r.deadline_at) AS effective_deadline
             FROM timed_assessment_recipients r
             JOIN timed_assessments a ON a.timed_assessment_id = r.timed_assessment_id AND a.status = "published"
             JOIN training_classes c ON c.class_id = a.class_id
             JOIN trainees t ON t.trainee_id = r.trainee_id
             LEFT JOIN timed_assessment_submissions s
               ON s.timed_assessment_id = r.timed_assessment_id AND s.trainee_id = r.trainee_id
             WHERE r.is_excused = 0 AND s.submission_id IS NULL
               AND UTC_TIMESTAMP() > COALESCE((SELECT MAX(x.extended_deadline) FROM timed_assessment_extensions x
                                               WHERE x.timed_assessment_id = r.timed_assessment_id AND x.trainee_id = r.trainee_id),
                                              r.deadline_at)
               AND t.user_id IS NOT NULL
               AND (t.user_id = ? OR c.instructor_id = ? OR ? IN ("administrator", "staff"))',
            [(int) $user['user_id'], (int) $user['user_id'], (string) $user['role']]
        );
        foreach ($rows as $row) {
            $this->insertNotification(
                (int) $row['timed_assessment_id'],
                (int) $row['trainee_user_id'],
                (int) $row['trainee_id'],
                'missed_deadline',
                'You did not submit "' . $row['assessment_title'] . '" before its deadline.'
            );
            if (!empty($row['instructor_id'])) {
                $this->insertNotification(
                    (int) $row['timed_assessment_id'],
                    (int) $row['instructor_id'],
                    (int) $row['trainee_id'],
                    'not_submitted_' . (int) $row['trainee_id'],
                    'A trainee did not submit "' . $row['assessment_title'] . '" before its deadline.'
                );
            }
        }
    }

    private function notifyDueSoon(): void
    {
        $rows = $this->rows(
            'SELECT r.timed_assessment_id, r.trainee_id, t.user_id, a.assessment_title,
                    COALESCE((SELECT MAX(x.extended_deadline) FROM timed_assessment_extensions x
                              WHERE x.timed_assessment_id = r.timed_assessment_id AND x.trainee_id = r.trainee_id),
                             r.deadline_at) AS effective_deadline,
                    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), COALESCE(
                      (SELECT MAX(x.extended_deadline) FROM timed_assessment_extensions x
                       WHERE x.timed_assessment_id = r.timed_assessment_id AND x.trainee_id = r.trainee_id),
                      r.deadline_at
                    )) AS seconds_remaining
             FROM timed_assessment_recipients r
             JOIN timed_assessments a ON a.timed_assessment_id = r.timed_assessment_id AND a.status = "published"
             JOIN trainees t ON t.trainee_id = r.trainee_id
             LEFT JOIN timed_assessment_submissions s
               ON s.timed_assessment_id = r.timed_assessment_id AND s.trainee_id = r.trainee_id
             WHERE r.is_excused = 0 AND s.submission_id IS NULL AND t.user_id IS NOT NULL
               AND UTC_TIMESTAMP() < COALESCE((SELECT MAX(x.extended_deadline) FROM timed_assessment_extensions x
                                               WHERE x.timed_assessment_id = r.timed_assessment_id AND x.trainee_id = r.trainee_id),
                                              r.deadline_at)
               AND COALESCE((SELECT MAX(x.extended_deadline) FROM timed_assessment_extensions x
                             WHERE x.timed_assessment_id = r.timed_assessment_id AND x.trainee_id = r.trainee_id),
                            r.deadline_at) <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)'
        );
        foreach ($rows as $row) {
            $assessmentId = (int) $row['timed_assessment_id'];
            $traineeId = (int) $row['trainee_id'];
            $this->insertNotification(
                $assessmentId,
                (int) $row['user_id'],
                $traineeId,
                'reminder_5m',
                'Five minutes or less remain to submit "' . $row['assessment_title'] . '".'
            );
            if ((int) $row['seconds_remaining'] <= 60) {
                $this->insertNotification(
                    $assessmentId,
                    (int) $row['user_id'],
                    $traineeId,
                    'reminder_1m',
                    'One minute or less remains to submit "' . $row['assessment_title'] . '".'
                );
            }
        }
    }

    private function notifyNewAssignment(int $assessmentId): void
    {
        $rows = $this->rows(
            'SELECT a.assessment_title, r.trainee_id, t.user_id
             FROM timed_assessment_recipients r
             JOIN timed_assessments a ON a.timed_assessment_id = r.timed_assessment_id
             JOIN trainees t ON t.trainee_id = r.trainee_id
             WHERE r.timed_assessment_id = ? AND t.user_id IS NOT NULL',
            [$assessmentId]
        );
        foreach ($rows as $row) {
            $this->insertNotification(
                $assessmentId,
                (int) $row['user_id'],
                (int) $row['trainee_id'],
                'assigned',
                'A new assessment has been assigned: ' . $row['assessment_title'] . '.'
            );
        }
    }

    private function notifySubmission(int $assessmentId, int $traineeId, bool $isLate): void
    {
        $row = $this->row(
            'SELECT a.assessment_title, c.instructor_id, t.user_id AS trainee_user_id,
                    CONCAT(t.first_name, " ", t.last_name) AS trainee_name
             FROM timed_assessments a
             JOIN training_classes c ON c.class_id = a.class_id
             JOIN trainees t ON t.trainee_id = ?
             WHERE a.timed_assessment_id = ?',
            [$traineeId, $assessmentId]
        );
        if (!$row) {
            return;
        }
        if (!empty($row['instructor_id'])) {
            $this->insertNotification(
                $assessmentId,
                (int) $row['instructor_id'],
                $traineeId,
                ($isLate ? 'late_submission_' : 'submission_') . $traineeId,
                $row['trainee_name'] . ($isLate ? ' submitted late for "' : ' submitted "') . $row['assessment_title'] . '".'
            );
        }
        $this->insertNotification(
            $assessmentId,
            (int) $row['trainee_user_id'],
            $traineeId,
            'submission_recorded',
            'Your submission for "' . $row['assessment_title'] . '" was recorded' . ($isLate ? ' late.' : '.')
        );
    }

    private function notifyTrainee(int $assessmentId, int $traineeId, string $eventKey, string $message): void
    {
        $row = $this->row('SELECT user_id FROM trainees WHERE trainee_id = ?', [$traineeId]);
        if ($row && !empty($row['user_id'])) {
            $this->insertNotification($assessmentId, (int) $row['user_id'], $traineeId, $eventKey, $message);
        }
    }

    private function insertNotification(int $assessmentId, int $userId, ?int $traineeId, string $eventKey, string $message): void
    {
        $this->execute(
            'INSERT IGNORE INTO timed_assessment_notifications
             (timed_assessment_id, recipient_user_id, trainee_id, event_key, message)
             VALUES (?, ?, ?, ?, ?)',
            [$assessmentId, $userId, $traineeId, $eventKey, mb_substr($message, 0, 500)]
        );
    }

    private function traineeOptions(array $user): array
    {
        $sql = 'SELECT DISTINCT t.trainee_id AS id, CONCAT(t.first_name, " ", t.last_name) AS label
                FROM trainees t JOIN class_enrollments e ON e.trainee_id = t.trainee_id
                JOIN training_classes c ON c.class_id = e.class_id';
        $values = [];
        if (($user['role'] ?? '') === 'instructor') {
            $sql .= ' WHERE c.instructor_id = ?';
            $values[] = (int) $user['user_id'];
        }
        return $this->rows($sql . ' ORDER BY label', $values);
    }

    private function instructorOptions(array $user): array
    {
        if (($user['role'] ?? '') === 'instructor') {
            return [[
                'id' => (int) $user['user_id'],
                'label' => (string) ($user['full_name'] ?? ''),
            ]];
        }
        return $this->rows(
            'SELECT DISTINCT u.user_id AS id, u.full_name AS label
             FROM users u JOIN training_classes c ON c.instructor_id = u.user_id
             ORDER BY u.full_name'
        );
    }

    private function assessmentById(int $id, array $user, bool $lock = false): ?array
    {
        $sql = 'SELECT a.* FROM timed_assessments a
                JOIN training_classes c ON c.class_id = a.class_id
                WHERE a.timed_assessment_id = ?';
        $values = [$id];
        if (($user['role'] ?? '') === 'instructor') {
            $sql .= ' AND c.instructor_id = ?';
            $values[] = (int) $user['user_id'];
        }
        $sql .= ' LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
        return $this->row($sql, $values);
    }

    private function validateAssessment(array $input): array
    {
        $idValue = $input['timed_assessment_id'] ?? '';
        $id = $idValue === '' || $idValue === null ? null : $this->positiveInteger($idValue, 'assessment');
        $classId = $this->positiveInteger($input['class_id'] ?? null, 'class');
        $title = $this->validatedText($input['assessment_title'] ?? '', 'Enter an assessment title.', 150);
        $type = $input['assessment_type'] ?? '';
        if (!is_string($type) || !in_array($type, ['quiz', 'examination', 'class_assessment'], true)) {
            throw new TimedAssessmentValidationException('Choose a valid assessment type.');
        }
        $description = $this->validatedText($input['description'] ?? '', 'Enter a valid description.', 5000, true);
        $instructions = $this->validatedText($input['instructions'] ?? '', 'Enter assessment instructions.', 5000);
        $duration = $this->positiveInteger($input['duration_minutes'] ?? null, 'duration');
        if ($duration > 1440) {
            throw new TimedAssessmentValidationException('Duration must not exceed 24 hours.');
        }
        $startLocal = $this->requiredString($input['start_at'] ?? null, 'Choose an assessment start time.');
        $endLocal = $this->requiredString($input['scheduled_end_at'] ?? null, 'Choose an assessment end time.');
        $start = $this->localDateTimeToUtc($startLocal, 'Choose a valid start date and time.');
        $scheduledEnd = $this->localDateTimeToUtc($endLocal, 'Choose a valid end date and time.');
        if ($scheduledEnd <= $start) {
            throw new TimedAssessmentValidationException('The end time must be after the start time.');
        }
        $durationDeadline = (new DateTimeImmutable($start, new DateTimeZone('UTC')))
            ->modify('+' . $duration . ' minutes')->format('Y-m-d H:i:s');
        $deadline = min($scheduledEnd, $durationDeadline);
        $late = filter_var($input['allow_late_submission'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

        return [
            'id' => $id,
            'class_id' => $classId,
            'title' => $title,
            'type' => $type,
            'description' => $description === '' ? null : $description,
            'instructions' => $instructions,
            'start_at' => $start,
            'scheduled_end_at' => $scheduledEnd,
            'deadline_at' => $deadline,
            'duration_minutes' => $duration,
            'allow_late_submission' => $late,
        ];
    }

    private function localDateTimeToUtc(string $value, string $errorMessage): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('Asia/Manila'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i') !== $value) {
            throw new TimedAssessmentValidationException($errorMessage);
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function validatedText($value, string $errorMessage, int $maxLength, bool $allowEmpty = false): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new TimedAssessmentValidationException($errorMessage);
        }
        $value = trim((string) $value);
        if ((!$allowEmpty && $value === '') || mb_strlen($value) > $maxLength
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            throw new TimedAssessmentValidationException($errorMessage);
        }
        return $value;
    }

    private function requiredString($value, string $errorMessage): string
    {
        if (!is_string($value) || $value === '') {
            throw new TimedAssessmentValidationException($errorMessage);
        }
        return $value;
    }

    private function positiveInteger($value, string $field): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9]\d*$/D', (string) $value)) {
            throw new TimedAssessmentValidationException('Choose a valid ' . $field . '.');
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($number === false) {
            throw new TimedAssessmentValidationException('Choose a valid ' . $field . '.');
        }
        return (int) $number;
    }

    private function traineeIdForUser(int $userId): ?int
    {
        $row = $this->row('SELECT trainee_id FROM trainees WHERE user_id = ? LIMIT 1', [$userId]);
        return $row ? (int) $row['trainee_id'] : null;
    }

    private function assertClassAccess(int $classId, array $user): void
    {
        $sql = 'SELECT class_id FROM training_classes WHERE class_id = ?';
        $values = [$classId];
        if (($user['role'] ?? '') === 'instructor') {
            $sql .= ' AND instructor_id = ?';
            $values[] = (int) $user['user_id'];
        }
        if (!$this->row($sql . ' LIMIT 1', $values)) {
            throw new TimedAssessmentAuthorizationException('That class is not assigned to your account.');
        }
    }

    private function assertPortalRole(array $user): void
    {
        if (!in_array($user['role'] ?? '', ['administrator', 'staff', 'instructor', 'trainee'], true)) {
            throw new TimedAssessmentAuthorizationException('You are not allowed to view assessments.');
        }
    }

    private function assertTeachingStaff(array $user): void
    {
        if (!in_array($user['role'] ?? '', ['administrator', 'staff', 'instructor'], true)) {
            throw new TimedAssessmentAuthorizationException('You are not allowed to manage assessments.');
        }
    }

    private function assertTrainee(array $user): void
    {
        if (($user['role'] ?? '') !== 'trainee') {
            throw new TimedAssessmentAuthorizationException('Only trainees can submit assessment results.');
        }
    }

    private function writeAudit(int $assessmentId, ?int $traineeId, string $action, ?string $previous, ?string $next, array $user): void
    {
        $this->execute(
            'INSERT INTO timed_assessment_audit_logs
             (timed_assessment_id, trainee_id, action, previous_value, new_value, performed_by)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$assessmentId, $traineeId, $action, $previous, $next, (int) $user['user_id']]
        );
    }

    private function auditValues(array $values): string
    {
        return json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function utcIso(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        return str_replace(' ', 'T', $value) . 'Z';
    }

    private function begin(): void
    {
        if (!$this->db->transaction()) {
            throw new RuntimeException('Unable to start assessment transaction.');
        }
    }

    private function commit(): void
    {
        if (!$this->db->commit()) {
            throw new RuntimeException('Unable to commit assessment transaction.');
        }
    }

    private function rollback(): void
    {
        try {
            $this->db->roll_back();
        } catch (Throwable $error) {
            error_log('Timed assessment rollback failed (' . get_class($error) . ').');
        }
    }
}
