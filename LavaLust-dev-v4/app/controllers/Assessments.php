<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'controllers/Base.php';

class Assessments extends Base
{
    public function assessments()
    {
        if (!$this->requireUser() || !$this->inRoleGroup('teaching_staff')) {
            return $this->deny('You are not allowed to manage assessments.');
        }

        $editing = null;
        $query = $this->queryParams();
        $classId = null;
        if (is_scalar($query['class_id'] ?? null) && ctype_digit((string) $query['class_id'])
            && (int) $query['class_id'] > 0) {
            $classId = (int) $query['class_id'];
        }
        if (array_key_exists('edit', $query)) {
            $editId = $query['edit'];
            if (!is_scalar($editId) || !ctype_digit((string) $editId) || (int) $editId < 1
                || !($editing = $this->assessmentModel->assessmentById((int) $editId, $this->user))) {
                show_404();
                return;
            }
        }

        $this->render('module', [
            'title' => 'Training Assessments',
            'section' => 'assessments',
            'records' => $this->assessmentModel->assessmentRecords($this->user, $classId),
            'editing' => $editing,
            'primary_key' => 'assessment_id',
            'options' => [
                'trainees' => $this->recordModel->options('trainees', $this->user),
                'classes' => $this->assessmentModel->classOptions($this->user),
            ],
            'columns' => ['trainee_name', 'trainee_number', 'program_name', 'class_name', 'assessment_name', 'outcome', 'assessment_date', 'assessor_name', 'notes'],
            'assessment_class_id' => $classId ?? 0,
        ]);
    }

    public function saveAssessment()
    {
        if (!$this->requireUser()) {
            return;
        }
        if (!$this->inRoleGroup('teaching_staff')) {
            return $this->deny('You are not allowed to record assessment results.');
        }
        if (!$this->apiRequest && !$this->validCsrf()) {
            return $this->actionError('Your security token is invalid or expired. Refresh the page and try again.', 'manage/assessments', 403);
        }

        $rawAssessmentId = $this->input('assessment_id');
        if (!is_scalar($rawAssessmentId)) {
            return $this->actionError('Choose a valid assessment record.', 'manage/assessments', 422);
        }
        $assessmentId = trim((string) $rawAssessmentId);
        if ($assessmentId !== '' && (!ctype_digit($assessmentId) || (int) $assessmentId < 1)) {
            return $this->actionError('Choose a valid assessment record.', 'manage/assessments', 422);
        }

        try {
            $savedAssessmentId = $this->assessmentModel->saveAssessment([
                'assessment_id' => $assessmentId === '' ? null : (int) $assessmentId,
                'trainee_id' => $this->input('trainee_id'),
                'class_id' => $this->input('class_id'),
                'assessment_name' => $this->input('assessment_name'),
                'outcome' => $this->input('outcome'),
                'assessment_date' => $this->input('assessment_date'),
                'notes' => $this->input('notes'),
                'time_limit_minutes' => $this->input('time_limit_minutes'),
            ], $this->user, $this->eligibilityModel);
        } catch (Throwable $error) {
            return $this->handleAssessmentError($error);
        }

        $isUpdate = $assessmentId !== '';
        $message = $isUpdate ? 'Assessment result updated.' : 'Assessment result saved.';
        $this->logAssessmentActivity(
            $isUpdate ? 'Updated training assessment' : 'Recorded training assessment',
            'Assessment record #' . $savedAssessmentId
        );
        if ($this->apiRequest) {
            return $this->jsonResponse([
                'message' => $message,
                'assessment_id' => $savedAssessmentId,
            ]);
        }

        $this->notice($message);
        redirect($this->url('manage/assessments'));
    }

    public function deleteAssessment($id)
    {
        if (!$this->requireUser()) {
            return;
        }
        if (!$this->inRoleGroup('teaching_staff')) {
            return $this->deny('You are not allowed to delete assessment results.');
        }
        if (!$this->apiRequest && !$this->validCsrf()) {
            return $this->actionError('Your security token is invalid or expired. Refresh the page and try again.', 'manage/assessments', 403);
        }
        if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id < 1) {
            return $this->actionError('Assessment record not found.', 'manage/assessments', 404);
        }

        try {
            $assessment = $this->assessmentModel->deleteAssessment((int) $id, $this->user, $this->eligibilityModel);
        } catch (Throwable $error) {
            return $this->handleAssessmentError($error);
        }

        $this->logAssessmentActivity('Deleted training assessment', $assessment['assessment_name'] . ', record #' . (int) $id);
        if ($this->apiRequest) {
            return $this->jsonResponse(['message' => 'Assessment record deleted.']);
        }

        $this->notice('Assessment record deleted.');
        redirect($this->url('manage/assessments'));
    }

    private function handleAssessmentError(Throwable $error)
    {
        if ($error instanceof AssessmentAuthorizationException) {
            return $this->actionError($error->getMessage(), 'manage/assessments', 403);
        }
        if ($error instanceof AssessmentNotFoundException) {
            return $this->actionError($error->getMessage(), 'manage/assessments', 404);
        }
        if ($error instanceof AssessmentConflictException) {
            return $this->actionError($error->getMessage(), 'manage/assessments', 409);
        }
        if ($error instanceof AssessmentValidationException) {
            return $this->actionError($error->getMessage(), 'manage/assessments', 422);
        }

        error_log('Assessment operation failed (' . get_class($error) . '): ' . $error->getMessage());
        return $this->actionError('The assessment could not be saved. Please try again.', 'manage/assessments', 500);
    }

    private function logAssessmentActivity($action, $details)
    {
        try {
            $this->recordModel->logActivity((int) $this->user['user_id'], $action, $details);
        } catch (Throwable $error) {
            error_log('Assessment activity logging failed (' . get_class($error) . ').');
        }
    }
}
