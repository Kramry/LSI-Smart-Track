<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';

class Assessment_model extends Base_model
{
    public function classOptions()
    {
        return $this->db->table('training_classes')
            ->select('class_id AS id, class_name AS label')
            ->order_by('class_name')
            ->get_all();
    }

    public function enrollmentForTraineeClass($traineeId, $classId)
    {
        return $this->db->table('class_enrollments')
            ->select('enrollment_id')
            ->where('trainee_id', (int) $traineeId)
            ->where('class_id', (int) $classId)
            ->get() ?: null;
    }

    public function duplicateAssessment($traineeId, $classId, $assessmentName, $excludeAssessmentId = null)
    {
        $query = $this->db->table('training_assessments')
            ->select('assessment_id')
            ->where('trainee_id', (int) $traineeId)
            ->where('class_id', (int) $classId)
            ->where('assessment_name', $assessmentName);

        if ($excludeAssessmentId !== null) {
            $query->where('assessment_id', '<>', (int) $excludeAssessmentId);
        }

        return $query->get() ?: null;
    }

    public function assessmentRecords()
    {
        return $this->rows('SELECT a.*, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, t.qr_code AS trainee_number, c.class_name, p.program_name, u.full_name AS assessor_name FROM training_assessments a JOIN trainees t ON t.trainee_id = a.trainee_id JOIN training_classes c ON c.class_id = a.class_id JOIN training_programs p ON p.program_id = c.program_id JOIN users u ON u.user_id = a.assessed_by ORDER BY a.assessment_date DESC, a.assessment_id DESC');
    }

    public function assessmentById($assessmentId)
    {
        return $this->db->table('training_assessments')
            ->where('assessment_id', (int) $assessmentId)
            ->get() ?: null;
    }

    public function saveAssessment(array $assessment)
    {
        if (!empty($assessment['assessment_id'])) {
            $this->execute('UPDATE training_assessments SET trainee_id = ?, class_id = ?, assessment_name = ?, outcome = ?, assessed_by = ?, assessment_date = ?, notes = ?, time_limit_minutes = ?, updated_at = CURRENT_TIMESTAMP WHERE assessment_id = ?', [
                (int) $assessment['trainee_id'],
                (int) $assessment['class_id'],
                $assessment['assessment_name'],
                $assessment['outcome'],
                (int) $assessment['assessed_by'],
                $assessment['assessment_date'],
                $assessment['notes'],
                !empty($assessment['time_limit_minutes']) ? (int) $assessment['time_limit_minutes'] : null,
                (int) $assessment['assessment_id'],
            ]);
            return (int) $assessment['assessment_id'];
        }

        $existing = $this->duplicateAssessment(
            (int) $assessment['trainee_id'],
            (int) $assessment['class_id'],
            $assessment['assessment_name']
        );

        if ($existing) {
            $this->execute('UPDATE training_assessments SET outcome = ?, assessed_by = ?, assessment_date = ?, notes = ?, time_limit_minutes = ?, updated_at = CURRENT_TIMESTAMP WHERE assessment_id = ?', [
                $assessment['outcome'],
                (int) $assessment['assessed_by'],
                $assessment['assessment_date'],
                $assessment['notes'],
                !empty($assessment['time_limit_minutes']) ? (int) $assessment['time_limit_minutes'] : null,
                (int) $existing['assessment_id'],
            ]);
            return (int) $existing['assessment_id'];
        }

        $this->db->table('training_assessments')->insert([
            'trainee_id' => (int) $assessment['trainee_id'],
            'class_id' => (int) $assessment['class_id'],
            'assessment_name' => $assessment['assessment_name'],
            'outcome' => $assessment['outcome'],
            'assessed_by' => (int) $assessment['assessed_by'],
            'assessment_date' => $assessment['assessment_date'],
            'notes' => $assessment['notes'],
            'time_limit_minutes' => !empty($assessment['time_limit_minutes']) ? (int) $assessment['time_limit_minutes'] : null,
        ]);
        return (int) $this->duplicateAssessment(
            (int) $assessment['trainee_id'],
            (int) $assessment['class_id'],
            $assessment['assessment_name']
        )['assessment_id'];
    }

    public function deleteAssessment($assessmentId)
    {
        return $this->db->table('training_assessments')
            ->where('assessment_id', (int) $assessmentId)
            ->delete() > 0;
    }
}
