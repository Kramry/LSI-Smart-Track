<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'controllers/Base.php';

class Assessments extends Base
{
    public function assessments()
    {
        if (!$this->requireUser() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to manage assessments.');
        }

        $editing = null;
        if (array_key_exists('edit', $_GET)) {
            $editId = is_scalar($_GET['edit']) ? (string) $_GET['edit'] : '';
            if (!ctype_digit($editId) || !(int) $editId || !($editing = $this->model->assessmentById((int) $editId))) {
                show_404();
                return;
            }
        }
        $this->render('module', [
            'title' => 'Training Assessments',
            'section' => 'assessments',
            'records' => $this->model->assessmentRecords(),
            'editing' => $editing,
            'primary_key' => 'assessment_id',
            'options' => [
                'trainees' => $this->model->options('trainees', $this->user),
                'classes' => $this->model->rows('SELECT class_id AS id, class_name AS label FROM training_classes ORDER BY class_name'),
            ],
            'columns' => ['trainee_name', 'trainee_number', 'program_name', 'class_name', 'assessment_name', 'outcome', 'assessment_date', 'assessor_name', 'notes'],
        ]);
    }

    public function saveAssessment()
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to record assessment results.');
        }

        $traineeId = (int) $this->input('trainee_id');
        $classId = (int) $this->input('class_id');
        $name = trim((string) $this->input('assessment_name'));
        $outcome = strtolower(trim((string) $this->input('outcome')));
        $assessmentDate = trim((string) $this->input('assessment_date'));
        $notes = trim((string) $this->input('notes'));
        $assessmentId = trim((string) $this->input('assessment_id'));
        $editing = null;
        if ($assessmentId !== '') {
            if (!ctype_digit($assessmentId) || !(int) $assessmentId || !($editing = $this->model->assessmentById((int) $assessmentId))) {
                return $this->actionError('The assessment record could not be found.', 'manage/assessments', 404);
            }
        }
        $enrollment = $this->model->row('SELECT enrollment_id FROM class_enrollments WHERE trainee_id = ? AND class_id = ?', [$traineeId, $classId]);
        $date = DateTime::createFromFormat('!Y-m-d', $assessmentDate);

        if (!$traineeId || !$classId || !$enrollment || $name === '' || strlen($name) > 150
            || !in_array($outcome, ['pass', 'fail'], true)
            || !$date || $date->format('Y-m-d') !== $assessmentDate
            || strlen($notes) > 2000) {
            return $this->actionError('Select an enrolled trainee and class, enter an assessment name and date, and choose Pass or Fail.', 'manage/assessments');
        }

        $duplicate = $editing
            ? $this->model->row('SELECT assessment_id FROM training_assessments WHERE trainee_id = ? AND class_id = ? AND assessment_name = ? AND assessment_id <> ?', [
                $traineeId,
                $classId,
                $name,
                (int) $editing['assessment_id'],
            ])
            : null;
        if ($duplicate) {
            return $this->actionError('That trainee already has an assessment with this name in the selected class.', 'manage/assessments');
        }

        $savedAssessmentId = $this->model->saveAssessment([
            'assessment_id' => $editing ? (int) $editing['assessment_id'] : null,
            'trainee_id' => $traineeId,
            'class_id' => $classId,
            'assessment_name' => $name,
            'outcome' => $outcome,
            'assessed_by' => (int) $this->user['user_id'],
            'assessment_date' => $assessmentDate,
            'notes' => $notes,
        ]);
        if ($editing && ((int) $editing['trainee_id'] !== $traineeId || (int) $editing['class_id'] !== $classId)) {
            $this->recalculateEligibilityPair($editing['trainee_id'], $editing['class_id']);
        }
        $this->model->calculateEligibility($traineeId, $classId);
        $this->model->logActivity((int) $this->user['user_id'], $editing ? 'Updated training assessment' : 'Recorded training assessment', $name . ' (' . strtoupper($outcome) . '), record #' . $savedAssessmentId);
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => $editing ? 'Assessment result updated.' : 'Assessment result saved.']);
        }
        $this->notice($editing ? 'Assessment result updated.' : 'Assessment result saved.');
        redirect($this->url('manage/assessments'));
    }

    public function deleteAssessment($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to delete assessment results.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            show_404();
            return;
        }

        $assessment = $this->model->assessmentById((int) $id);
        if (!$assessment || !$this->model->deleteAssessment((int) $id)) {
            return $this->actionError('Assessment record not found or could not be deleted.', 'manage/assessments', 404);
        }

        $this->recalculateEligibilityPair($assessment['trainee_id'], $assessment['class_id']);
        $this->model->logActivity((int) $this->user['user_id'], 'Deleted training assessment', $assessment['assessment_name'] . ', record #' . (int) $id);
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => 'Assessment record deleted.']);
        }
        $this->notice('Assessment record deleted.');
        redirect($this->url('manage/assessments'));
    }
}
