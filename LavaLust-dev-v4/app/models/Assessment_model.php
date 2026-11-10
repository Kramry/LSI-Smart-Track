<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';

class AssessmentAuthorizationException extends RuntimeException
{
}

class AssessmentConflictException extends RuntimeException
{
}

class AssessmentNotFoundException extends RuntimeException
{
}

class AssessmentValidationException extends InvalidArgumentException
{
}

class Assessment_model extends Base_model
{
    public function classOptions(array $user)
    {
        $this->assertTeachingStaff($user);
        $query = $this->db->table('training_classes')
            ->select('class_id AS id, class_name AS label');
        if ($user['role'] === 'instructor') {
            $query->where('instructor_id', (int) $user['user_id']);
        }

        return $query->order_by('class_name')->get_all();
    }

    public function enrollmentForTraineeClass($traineeId, $classId, array $user)
    {
        $this->assertTeachingStaff($user);
        $traineeId = $this->positiveInteger($traineeId, 'trainee_id');
        $classId = $this->positiveInteger($classId, 'class_id');
        $this->assertClassAccess($classId, $user);

        return $this->db->table('class_enrollments')
            ->select('enrollment_id')
            ->where('trainee_id', $traineeId)
            ->where('class_id', $classId)
            ->get() ?: null;
    }

    public function duplicateAssessment($traineeId, $classId, $assessmentName, array $user, $excludeAssessmentId = null)
    {
        $this->assertTeachingStaff($user);
        $classId = $this->positiveInteger($classId, 'class_id');
        $this->assertClassAccess($classId, $user);

        $query = $this->db->table('training_assessments')
            ->select('assessment_id')
            ->where('trainee_id', $this->positiveInteger($traineeId, 'trainee_id'))
            ->where('class_id', $classId)
            ->where('assessment_name', (string) $assessmentName);

        if ($excludeAssessmentId !== null) {
            $query->where('assessment_id', '<>', $this->positiveInteger($excludeAssessmentId, 'assessment_id'));
        }

        return $query->get() ?: null;
    }

    public function assessmentRecords(array $user, $classId = null)
    {
        $this->assertTeachingStaff($user);
        $sql = 'SELECT a.*, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, t.qr_code AS trainee_number, c.class_name, p.program_name, u.full_name AS assessor_name
            FROM training_assessments a
            JOIN trainees t ON t.trainee_id = a.trainee_id
            JOIN training_classes c ON c.class_id = a.class_id
            JOIN training_programs p ON p.program_id = c.program_id
            JOIN users u ON u.user_id = a.assessed_by';
        $conditions = [];
        $values = [];

        if ($user['role'] === 'instructor') {
            $conditions[] = 'c.instructor_id = ?';
            $values[] = (int) $user['user_id'];
        }
        if ($classId !== null && $classId !== '') {
            $classId = $this->positiveInteger($classId, 'class_id');
            $this->assertClassAccess($classId, $user);
            $conditions[] = 'a.class_id = ?';
            $values[] = $classId;
        }
        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        return $this->rows($sql . ' ORDER BY a.assessment_date DESC, a.assessment_id DESC', $values);
    }

    public function assessmentById($assessmentId, array $user)
    {
        $this->assertTeachingStaff($user);
        $assessmentId = filter_var($assessmentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($assessmentId === false) {
            return null;
        }

        $sql = 'SELECT a.* FROM training_assessments a
            JOIN training_classes c ON c.class_id = a.class_id
            WHERE a.assessment_id = ?';
        $values = [(int) $assessmentId];
        if ($user['role'] === 'instructor') {
            $sql .= ' AND c.instructor_id = ?';
            $values[] = (int) $user['user_id'];
        }

        return $this->row($sql, $values);
    }

    public function saveAssessment(array $assessment, array $user, Eligibility_model $eligibilityModel)
    {
        $this->assertTeachingStaff($user);
        $assessment = $this->validatedAssessment($assessment, $user);

        $this->beginTransaction();
        try {
            $previous = null;
            if ($assessment['assessment_id'] !== null) {
                $previous = $this->assessmentById($assessment['assessment_id'], $user);
                if (!$previous) {
                    throw new AssessmentNotFoundException('Assessment record not found.');
                }
            }

            $this->assertClassAccess($assessment['class_id'], $user);
            if (!$this->enrollmentForTraineeClass($assessment['trainee_id'], $assessment['class_id'], $user)) {
                throw new AssessmentValidationException('The selected trainee is not enrolled in that class.');
            }

            if ($this->duplicateAssessment(
                $assessment['trainee_id'],
                $assessment['class_id'],
                $assessment['assessment_name'],
                $user,
                $assessment['assessment_id']
            )) {
                throw new AssessmentConflictException('That trainee already has an assessment with this name in the selected class.');
            }

            if ($previous) {
                $this->updateAssessmentRecord($assessment, $user);
                $assessmentId = (int) $previous['assessment_id'];
            } else {
                $assessmentId = $this->insertAssessmentRecord($assessment);
            }

            if ($previous && (
                (int) $previous['trainee_id'] !== $assessment['trainee_id']
                || (int) $previous['class_id'] !== $assessment['class_id']
            )) {
                $eligibilityModel->calculateEligibility((int) $previous['trainee_id'], (int) $previous['class_id']);
            }
            $eligibilityModel->calculateEligibility($assessment['trainee_id'], $assessment['class_id']);

            $this->commitTransaction();
            return $assessmentId;
        } catch (Throwable $error) {
            $this->rollbackTransaction();
            if ($this->isAssessmentDuplicateError($error)) {
                throw new AssessmentConflictException('That trainee already has an assessment with this name in the selected class.', 0, $error);
            }
            throw $error;
        }
    }

    public function deleteAssessment($assessmentId, array $user, Eligibility_model $eligibilityModel)
    {
        $this->assertTeachingStaff($user);
        $assessmentId = $this->positiveInteger($assessmentId, 'assessment_id');

        $this->beginTransaction();
        try {
            $assessment = $this->assessmentById($assessmentId, $user);
            if (!$assessment) {
                throw new AssessmentNotFoundException('Assessment record not found.');
            }

            $sql = 'DELETE FROM training_assessments WHERE assessment_id = ?';
            $values = [$assessmentId];
            if ($user['role'] === 'instructor') {
                $sql .= ' AND EXISTS (
                    SELECT 1 FROM training_classes c
                    WHERE c.class_id = training_assessments.class_id AND c.instructor_id = ?
                )';
                $values[] = (int) $user['user_id'];
            }
            if ($this->execute($sql, $values) !== 1) {
                throw new AssessmentNotFoundException('Assessment record not found.');
            }

            $eligibilityModel->calculateEligibility((int) $assessment['trainee_id'], (int) $assessment['class_id']);
            $this->commitTransaction();
            return $assessment;
        } catch (Throwable $error) {
            $this->rollbackTransaction();
            throw $error;
        }
    }

    private function validatedAssessment(array $assessment, array $user)
    {
        $assessmentId = $assessment['assessment_id'] ?? null;
        if ($assessmentId !== null && $assessmentId !== '') {
            $assessmentId = $this->positiveInteger($assessmentId, 'assessment_id');
        } else {
            $assessmentId = null;
        }

        $traineeId = $this->positiveInteger($assessment['trainee_id'] ?? null, 'trainee_id');
        $classId = $this->positiveInteger($assessment['class_id'] ?? null, 'class_id');
        $nameValue = $assessment['assessment_name'] ?? null;
        $outcomeValue = $assessment['outcome'] ?? null;
        $dateValue = $assessment['assessment_date'] ?? null;
        $notesValue = $assessment['notes'] ?? '';
        $timeLimitValue = $assessment['time_limit_minutes'] ?? null;

        if (!is_string($nameValue) && !is_int($nameValue)) {
            throw new AssessmentValidationException('Enter a valid assessment name.');
        }
        $name = trim((string) $nameValue);
        if ($name === '' || preg_match('/^.{1,150}$/us', $name) !== 1 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new AssessmentValidationException('Assessment name must be between 1 and 150 characters.');
        }

        if (!is_string($outcomeValue) && !is_int($outcomeValue)) {
            throw new AssessmentValidationException('Choose Pass or Fail.');
        }
        $outcome = strtolower(trim((string) $outcomeValue));
        if (!in_array($outcome, ['pass', 'fail'], true)) {
            throw new AssessmentValidationException('Choose Pass or Fail.');
        }

        if (!is_string($dateValue) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateValue)) {
            throw new AssessmentValidationException('Enter a valid assessment date.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$date || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))
            || $date->format('Y-m-d') !== $dateValue) {
            throw new AssessmentValidationException('Enter a valid assessment date.');
        }

        if (!is_string($notesValue) && !is_int($notesValue)) {
            throw new AssessmentValidationException('Enter valid assessment notes.');
        }
        $notes = trim((string) $notesValue);
        if (preg_match('/^.{0,2000}$/us', $notes) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $notes) === 1) {
            throw new AssessmentValidationException('Assessment notes must not exceed 2000 characters or contain invalid control characters.');
        }

        $timeLimit = null;
        if ($timeLimitValue !== null && $timeLimitValue !== '') {
            if ((!is_int($timeLimitValue) && !is_string($timeLimitValue))
                || !ctype_digit((string) $timeLimitValue)
                || (int) $timeLimitValue < 1
                || (int) $timeLimitValue > 1440) {
                throw new AssessmentValidationException('Time limit must be between 1 and 1440 minutes.');
            }
            $timeLimit = (int) $timeLimitValue;
        }

        return [
            'assessment_id' => $assessmentId,
            'trainee_id' => $traineeId,
            'class_id' => $classId,
            'assessment_name' => $name,
            'outcome' => $outcome,
            'assessed_by' => (int) $user['user_id'],
            'assessment_date' => $dateValue,
            'notes' => $notes,
            'time_limit_minutes' => $timeLimit,
        ];
    }

    private function positiveInteger($value, $field)
    {
        if ((!is_int($value) && !is_string($value))
            || !preg_match('/^[1-9]\d*$/D', (string) $value)) {
            throw new AssessmentValidationException('Choose a valid ' . str_replace('_', ' ', $field) . '.');
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validated === false) {
            throw new AssessmentValidationException('Choose a valid ' . str_replace('_', ' ', $field) . '.');
        }

        return (int) $validated;
    }

    private function assertTeachingStaff(array $user)
    {
        if (!in_array($user['role'] ?? '', ['administrator', 'staff', 'instructor'], true)
            || filter_var($user['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new AssessmentAuthorizationException('You are not allowed to manage assessments.');
        }
    }

    private function assertClassAccess($classId, array $user)
    {
        $query = $this->db->table('training_classes')
            ->select('class_id')
            ->where('class_id', (int) $classId);
        if ($user['role'] === 'instructor') {
            $query->where('instructor_id', (int) $user['user_id']);
        }
        if (!$query->get()) {
            throw new AssessmentAuthorizationException('That class is not assigned to your account.');
        }
    }

    private function insertAssessmentRecord(array $assessment)
    {
        $query = $this->db->table('training_assessments');
        $query->insert([
            'trainee_id' => $assessment['trainee_id'],
            'class_id' => $assessment['class_id'],
            'assessment_name' => $assessment['assessment_name'],
            'outcome' => $assessment['outcome'],
            'assessed_by' => $assessment['assessed_by'],
            'assessment_date' => $assessment['assessment_date'],
            'notes' => $assessment['notes'],
            'time_limit_minutes' => $assessment['time_limit_minutes'],
        ]);
        $assessmentId = (int) $query->last_id();
        if ($assessmentId < 1) {
            throw new RuntimeException('The assessment insert did not return a valid record ID.');
        }

        return $assessmentId;
    }

    private function updateAssessmentRecord(array $assessment, array $user)
    {
        $sql = 'UPDATE training_assessments
            SET trainee_id = ?, class_id = ?, assessment_name = ?, outcome = ?, assessed_by = ?, assessment_date = ?, notes = ?, time_limit_minutes = ?, updated_at = CURRENT_TIMESTAMP
            WHERE assessment_id = ?';
        $values = [
            $assessment['trainee_id'],
            $assessment['class_id'],
            $assessment['assessment_name'],
            $assessment['outcome'],
            $assessment['assessed_by'],
            $assessment['assessment_date'],
            $assessment['notes'],
            $assessment['time_limit_minutes'],
            $assessment['assessment_id'],
        ];
        if ($user['role'] === 'instructor') {
            $sql .= ' AND EXISTS (
                SELECT 1 FROM training_classes c
                WHERE c.class_id = training_assessments.class_id AND c.instructor_id = ?
            ) AND EXISTS (
                SELECT 1 FROM training_classes target_class
                WHERE target_class.class_id = ? AND target_class.instructor_id = ?
            )';
            $values[] = (int) $user['user_id'];
            $values[] = $assessment['class_id'];
            $values[] = (int) $user['user_id'];
        }
        if ($this->execute($sql, $values) === 0) {
            $this->assertClassAccess($assessment['class_id'], $user);
            if (!$this->assessmentById($assessment['assessment_id'], $user)) {
                throw new AssessmentNotFoundException('Assessment record not found.');
            }
        }
    }

    private function beginTransaction()
    {
        if (!$this->db->transaction()) {
            try {
                $this->db->roll_back();
            } catch (Throwable $rollbackError) {
                error_log('Assessment transaction initialization cleanup failed (' . get_class($rollbackError) . ').');
            }
            throw new RuntimeException('Unable to start the assessment transaction.');
        }
    }

    private function commitTransaction()
    {
        if (!$this->db->commit()) {
            throw new RuntimeException('Unable to commit the assessment transaction.');
        }
    }

    private function rollbackTransaction()
    {
        try {
            $this->db->roll_back();
        } catch (Throwable $rollbackError) {
            error_log('Assessment transaction rollback failed (' . get_class($rollbackError) . ').');
        }
    }

    private function isAssessmentDuplicateError(Throwable $error)
    {
        $message = strtolower($error->getMessage());
        return strpos($message, 'uq_smarttrack_assessment_trainee_class_name') !== false
            || (strpos($message, 'duplicate entry') !== false && strpos($message, 'training_assessments') !== false);
    }
}
