<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'controllers/Base.php';

class Assessments extends Base
{
    public function assessments()
    {
        if (!$this->requireUser() || !in_array($this->user['role'], ['administrator', 'staff', 'instructor'], true)) {
            return $this->deny('You are not allowed to manage assessments.');
        }

        $editing = null;
        $query = $this->queryParams();
        if (array_key_exists('edit', $query)) {
            $editId = is_scalar($query['edit']) ? (string) $query['edit'] : '';
            if (!ctype_digit($editId) || !(int) $editId || !($editing = $this->assessmentModel->assessmentById((int) $editId))) {
                show_404();
                return;
            }
        }
        $this->render('module', [
            'title' => 'Training Assessments',
            'section' => 'assessments',
            'records' => $this->assessmentModel->assessmentRecords(),
            'editing' => $editing,
            'primary_key' => 'assessment_id',
            'options' => [
                'trainees' => $this->recordModel->options('trainees', $this->user),
                'classes' => $this->assessmentModel->classOptions(),
            ],
            'columns' => ['trainee_name', 'trainee_number', 'program_name', 'class_name', 'assessment_name', 'outcome', 'assessment_date', 'assessor_name', 'notes'],
            'assessment_class_id' => is_scalar($query['class_id'] ?? '') && ctype_digit((string) ($query['class_id'] ?? ''))
                ? (int) $query['class_id']
                : 0,
        ]);
    }

    public function saveAssessment()
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff', 'instructor'], true)) {
            return $this->deny('You are not allowed to record assessment results.');
        }

        $traineeId = (int) $this->input('trainee_id');
        $classId = (int) $this->input('class_id');
        $name = trim((string) $this->input('assessment_name'));
        $outcome = strtolower(trim((string) $this->input('outcome')));
        $assessmentDate = trim((string) $this->input('assessment_date'));
        $notes = trim((string) $this->input('notes'));
        $timeLimit = trim((string) $this->input('time_limit_minutes'));
        $assessmentId = trim((string) $this->input('assessment_id'));
        $editing = null;
        if ($assessmentId !== '') {
            if (!ctype_digit($assessmentId) || !(int) $assessmentId || !($editing = $this->assessmentModel->assessmentById((int) $assessmentId))) {
                return $this->actionError('The assessment record could not be found.', 'manage/assessments', 404);
            }
        }
        $enrollment = $this->assessmentModel->enrollmentForTraineeClass($traineeId, $classId);
        if ($this->user['role'] === 'instructor' && !$this->attendanceModel->attendanceClass($classId, $this->user)) {
            return $this->actionError('That class is not assigned to your instructor account.', 'manage/assessments', 403);
        }
        $date = DateTime::createFromFormat('!Y-m-d', $assessmentDate);

        // Validate time limit if provided
        $timeLimitMinutes = null;
        if ($timeLimit !== '') {
            if (!ctype_digit($timeLimit) || (int) $timeLimit < 1 || (int) $timeLimit > 1440) {
                return $this->actionError('Time limit must be between 1 and 1440 minutes (24 hours).', 'manage/assessments');
            }
            $timeLimitMinutes = (int) $timeLimit;
        }

        if (!$traineeId || !$classId || !$enrollment || $name === '' || strlen($name) > 150
            || !in_array($outcome, ['pass', 'fail'], true)
            || !$date || $date->format('Y-m-d') !== $assessmentDate
            || strlen($notes) > 2000) {
            return $this->actionError('Select an enrolled trainee and class, enter an assessment name and date, and choose Pass or Fail.', 'manage/assessments');
        }

        $duplicate = $editing
            ? $this->assessmentModel->duplicateAssessment($traineeId, $classId, $name, (int) $editing['assessment_id'])
            : null;
        if ($duplicate) {
            return $this->actionError('That trainee already has an assessment with this name in the selected class.', 'manage/assessments');
        }

        $savedAssessmentId = $this->assessmentModel->saveAssessment([
            'assessment_id' => $editing ? (int) $editing['assessment_id'] : null,
            'trainee_id' => $traineeId,
            'class_id' => $classId,
            'assessment_name' => $name,
            'outcome' => $outcome,
            'assessed_by' => (int) $this->user['user_id'],
            'assessment_date' => $assessmentDate,
            'notes' => $notes,
            'time_limit_minutes' => $timeLimitMinutes,
        ]);
        if ($editing && ((int) $editing['trainee_id'] !== $traineeId || (int) $editing['class_id'] !== $classId)) {
            $this->recalculateEligibilityPair($editing['trainee_id'], $editing['class_id']);
        }
        $this->eligibilityModel->calculateEligibility($traineeId, $classId);
        $this->recordModel->logActivity((int) $this->user['user_id'], $editing ? 'Updated training assessment' : 'Recorded training assessment', $name . ' (' . strtoupper($outcome) . '), record #' . $savedAssessmentId);
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => $editing ? 'Assessment result updated.' : 'Assessment result saved.']);
        }
        $this->notice($editing ? 'Assessment result updated.' : 'Assessment result saved.');
        redirect($this->url('manage/assessments'));
    }

    public function deleteAssessment($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff', 'instructor'], true)) {
            return $this->deny('You are not allowed to delete assessment results.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            show_404();
            return;
        }

        $assessment = $this->assessmentModel->assessmentById((int) $id);
        if (!$assessment || !$this->assessmentModel->deleteAssessment((int) $id)) {
            return $this->actionError('Assessment record not found or could not be deleted.', 'manage/assessments', 404);
        }

        $this->recalculateEligibilityPair($assessment['trainee_id'], $assessment['class_id']);
        $this->recordModel->logActivity((int) $this->user['user_id'], 'Deleted training assessment', $assessment['assessment_name'] . ', record #' . (int) $id);
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => 'Assessment record deleted.']);
        }
        $this->notice('Assessment record deleted.');
        redirect($this->url('manage/assessments'));
    }
}
