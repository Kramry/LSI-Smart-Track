<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';

class Assessment_model extends Base_model
{
        public function __construct()
    {
        parent::__construct(lava_instance()->db);
    }

    public function all()
    {
        return $this->rows("SELECT * FROM training_assessments ORDER BY date_assessed DESC");
    }

    public function insert($data)
    {
        return $this->db->table('training_assessments')->insert($data);
    }
    
    public function assessmentRecords()
    {
        return $this->rows('SELECT a.*, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, t.qr_code AS trainee_number, c.class_name, p.program_name, u.full_name AS assessor_name FROM training_assessments a JOIN trainees t ON t.trainee_id = a.trainee_id JOIN training_classes c ON c.class_id = a.class_id JOIN training_programs p ON p.program_id = c.program_id JOIN users u ON u.user_id = a.assessed_by ORDER BY a.assessment_date DESC, a.assessment_id DESC');
    }

    public function assessmentById($assessmentId)
    {
        return $this->row('SELECT * FROM training_assessments WHERE assessment_id = ?', [(int) $assessmentId]);
    }

    public function saveAssessment(array $assessment)
    {
        if (!empty($assessment['assessment_id'])) {
            $this->execute('UPDATE training_assessments SET trainee_id = ?, class_id = ?, assessment_name = ?, outcome = ?, assessed_by = ?, assessment_date = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE assessment_id = ?', [
                (int) $assessment['trainee_id'],
                (int) $assessment['class_id'],
                $assessment['assessment_name'],
                $assessment['outcome'],
                (int) $assessment['assessed_by'],
                $assessment['assessment_date'],
                $assessment['notes'],
                (int) $assessment['assessment_id'],
            ]);
            return (int) $assessment['assessment_id'];
        }

        $existing = $this->row('SELECT assessment_id FROM training_assessments WHERE trainee_id = ? AND class_id = ? AND assessment_name = ?', [
            (int) $assessment['trainee_id'],
            (int) $assessment['class_id'],
            $assessment['assessment_name'],
        ]);

        if ($existing) {
            $this->execute('UPDATE training_assessments SET outcome = ?, assessed_by = ?, assessment_date = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE assessment_id = ?', [
                $assessment['outcome'],
                (int) $assessment['assessed_by'],
                $assessment['assessment_date'],
                $assessment['notes'],
                (int) $existing['assessment_id'],
            ]);
            return (int) $existing['assessment_id'];
        }

        $this->execute('INSERT INTO training_assessments (trainee_id, class_id, assessment_name, outcome, assessed_by, assessment_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            (int) $assessment['trainee_id'],
            (int) $assessment['class_id'],
            $assessment['assessment_name'],
            $assessment['outcome'],
            (int) $assessment['assessed_by'],
            $assessment['assessment_date'],
            $assessment['notes'],
        ]);
        return (int) $this->row('SELECT assessment_id FROM training_assessments WHERE trainee_id = ? AND class_id = ? AND assessment_name = ?', [
            (int) $assessment['trainee_id'],
            (int) $assessment['class_id'],
            $assessment['assessment_name'],
        ])['assessment_id'];
    }

    public function deleteAssessment($assessmentId)
    {
        return $this->execute('DELETE FROM training_assessments WHERE assessment_id = ?', [(int) $assessmentId]) > 0;
    }
}
