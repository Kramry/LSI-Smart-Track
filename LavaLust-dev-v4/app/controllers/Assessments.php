<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'controllers/Base.php';
require_once APP_DIR . 'libraries/SmartTrackPdf.php';

class Assessments extends Base
{
    public function apiTimedAssessments()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }
        if (!$this->requireUser()) {
            return;
        }

        try {
            $data = $this->timedAssessmentModel->bundle($this->user);
            if ($this->inRoleGroup('teaching_staff')) {
                $filters = $this->timedAssessmentFilters($this->queryParams());
                $data['monitoring'] = $this->timedAssessmentModel->monitoring($this->user, $filters);
                $data['statistics'] = $this->timedAssessmentStatistics($data['monitoring']);
                $data['filters'] = $filters;
            }
            return $this->jsonResponse($data);
        } catch (Throwable $error) {
            return $this->handleTimedAssessmentError($error);
        }
    }

    public function apiSaveTimedAssessment()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }
        if (!$this->requireUser()) {
            return;
        }
        if (!$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'Your session expired. Refresh and try again.'], 403);
        }

        try {
            $id = $this->timedAssessmentModel->save($this->requestData(), $this->user);
            return $this->jsonResponse(['timed_assessment_id' => $id, 'message' => 'Timed assessment saved.']);
        } catch (Throwable $error) {
            return $this->handleTimedAssessmentError($error);
        }
    }

    public function apiPublishTimedAssessment($id)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }
        if (!$this->requireUser()) {
            return;
        }
        if (!$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'Your session expired. Refresh and try again.'], 403);
        }
        $id = $this->validTimedAssessmentId($id);
        if ($id === null) {
            return $this->jsonResponse(['message' => 'Choose a valid assessment.'], 422);
        }

        try {
            $assigned = $this->timedAssessmentModel->publish($id, $this->user);
            return $this->jsonResponse(['message' => 'Assessment published.', 'assigned_count' => $assigned]);
        } catch (Throwable $error) {
            return $this->handleTimedAssessmentError($error);
        }
    }

    public function apiCloseTimedAssessment($id)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }
        if (!$this->requireUser()) {
            return;
        }
        if (!$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'Your session expired. Refresh and try again.'], 403);
        }
        $id = $this->validTimedAssessmentId($id);
        if ($id === null) {
            return $this->jsonResponse(['message' => 'Choose a valid assessment.'], 422);
        }

        try {
            $this->timedAssessmentModel->close($id, $this->user);
            return $this->jsonResponse(['message' => 'Assessment closed.']);
        } catch (Throwable $error) {
            return $this->handleTimedAssessmentError($error);
        }
    }

    public function apiSubmitTimedAssessment($id)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }
        if (!$this->requireUser()) {
            return;
        }
        if (!$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'Your session expired. Refresh and try again.'], 403);
        }
        $id = $this->validTimedAssessmentId($id);
        if ($id === null) {
            return $this->jsonResponse(['message' => 'Choose a valid assessment.'], 422);
        }

        try {
            $result = $this->timedAssessmentModel->submit($id, $this->requestData(), $this->user);
            return $this->jsonResponse(['message' => 'Assessment submitted successfully.', 'submission' => $result]);
        } catch (Throwable $error) {
            return $this->handleTimedAssessmentError($error);
        }
    }

    public function apiAdjustTimedAssessmentRecipient($id, $traineeId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }
        if (!$this->requireUser()) {
            return;
        }
        if (!$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'Your session expired. Refresh and try again.'], 403);
        }
        $id = $this->validTimedAssessmentId($id);
        $traineeId = $this->validTimedAssessmentId($traineeId);
        if ($id === null || $traineeId === null) {
            return $this->jsonResponse(['message' => 'Choose a valid assessment and trainee.'], 422);
        }

        try {
            $this->timedAssessmentModel->adjustRecipient($id, $traineeId, $this->requestData(), $this->user);
            return $this->jsonResponse(['message' => 'Trainee assessment status updated.']);
        } catch (Throwable $error) {
            return $this->handleTimedAssessmentError($error);
        }
    }

    public function apiTimedAssessmentReport()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }
        if (!$this->requireUser()) {
            return;
        }

        try {
            $query = $this->queryParams();
            $filters = $this->timedAssessmentFilters($query);
            $rows = $this->timedAssessmentModel->report($this->user, $filters);
            $format = is_scalar($query['format'] ?? null) ? strtolower(trim((string) $query['format'])) : 'csv';
            if ($format === 'csv' || $format === 'excel') {
                return $this->jsonResponse([
                    'filename' => 'assessment-compliance-' . gmdate('Y-m-d') . '.csv',
                    'content_type' => 'text/csv;charset=utf-8',
                    'content_base64' => base64_encode($this->timedAssessmentCsv($rows)),
                ]);
            }
            if ($format === 'pdf') {
                return $this->jsonResponse([
                    'filename' => 'assessment-compliance-' . gmdate('Y-m-d') . '.pdf',
                    'content_type' => 'application/pdf',
                    'content_base64' => base64_encode($this->timedAssessmentPdf($rows)),
                ]);
            }
            return $this->jsonResponse(['message' => 'Choose CSV, Excel, or PDF format.'], 422);
        } catch (Throwable $error) {
            return $this->handleTimedAssessmentError($error);
        }
    }

    private function timedAssessmentFilters(array $query): array
    {
        $filters = [];
        foreach (['timed_assessment_id', 'class_id', 'instructor_id'] as $field) {
            $value = $query[$field] ?? null;
            if (is_scalar($value) && (string) $value !== '' && ctype_digit((string) $value) && (int) $value > 0) {
                $filters[$field] = (int) $value;
            }
        }
        foreach (['submission_status' => ['on_time', 'late', 'not_submitted', 'pending', 'excused']] as $field => $allowed) {
            $value = is_scalar($query[$field] ?? null) ? strtolower(trim((string) $query[$field])) : '';
            if ($value !== '' && in_array($value, $allowed, true)) {
                $filters[$field] = $value;
            }
        }
        foreach (['search', 'trainee'] as $field) {
            if (is_scalar($query[$field] ?? null)) {
                $value = trim((string) $query[$field]);
                if ($value !== '') {
                    $filters[$field] = mb_substr($value, 0, 100);
                }
            }
        }
        $date = is_scalar($query['assessment_date'] ?? null) ? (string) $query['assessment_date'] : '';
        if ($date !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (!$parsed || ($dateErrors !== false && ($dateErrors['error_count'] || $dateErrors['warning_count']))
                || $parsed->format('Y-m-d') !== $date) {
                throw new TimedAssessmentValidationException('Choose a valid assessment date.');
            }
            $filters['assessment_date'] = $date;
        }
        return $filters;
    }

    private function timedAssessmentStatistics(array $rows): array
    {
        $statistics = ['assigned' => count($rows), 'on_time' => 0, 'late' => 0, 'not_submitted' => 0, 'pending' => 0, 'excused' => 0, 'compliance_percentage' => 0];
        foreach ($rows as $row) {
            if (isset($statistics[$row['submission_status']])) {
                $statistics[$row['submission_status']]++;
            }
        }
        $eligible = $statistics['assigned'] - $statistics['excused'];
        $statistics['compliance_percentage'] = $eligible > 0
            ? round(($statistics['on_time'] / $eligible) * 100, 1)
            : 0;
        return $statistics;
    }

    private function timedAssessmentCsv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Unable to prepare compliance export.');
        }
        $columns = [
            'assessment_title' => 'Assessment',
            'assessment_type' => 'Type',
            'instructor_name' => 'Instructor',
            'class_name' => 'Class',
            'trainee_name' => 'Trainee',
            'effective_deadline' => 'Deadline (UTC)',
            'submitted_at' => 'Submitted (UTC)',
            'submission_status' => 'Status',
            'submitted_score' => 'Score',
            'result_text' => 'Result',
        ];
        fputcsv($stream, array_values($columns));
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $key => $_label) {
                $value = (string) ($row[$key] ?? '');
                $values[] = preg_match('/^\s*[=+\-@]/u', $value) ? "'" . $value : $value;
            }
            fputcsv($stream, $values);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        if ($csv === false) {
            throw new RuntimeException('Unable to read compliance export.');
        }
        return "\xEF\xBB\xBF" . $csv;
    }

    private function timedAssessmentPdf(array $rows): string
    {
        $pdf = SmartTrackPdf::create('L');
        $pdf->setTitle('Assessment Compliance Report');
        $pdf->setAuthor('LSI SmartTrack');
        $pdf->setCreator('LSI SmartTrack');
        $html = '<h1 style="color:#173a60">Assessment Compliance Report</h1>';
        $html .= '<p>Generated: ' . gmdate('Y-m-d H:i:s') . ' UTC · Trainee assignments: ' . count($rows) . '</p>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0"><thead><tr>';
        foreach (['Assessment', 'Instructor', 'Class', 'Trainee', 'Deadline (UTC)', 'Submission (UTC)', 'Status'] as $heading) {
            $html .= '<th>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach (['assessment_title', 'instructor_name', 'class_name', 'trainee_name', 'effective_deadline', 'submitted_at', 'submission_status'] as $field) {
                $html .= '<td>' . htmlspecialchars((string) ($row[$field] ?? '—'), ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $pdf->addHTMLCell(html: $html, posx: 10, posy: 10);
        return $pdf->getOutPDFString();
    }

    private function validTimedAssessmentId($value): ?int
    {
        if (!is_scalar($value) || !ctype_digit((string) $value) || (int) $value < 1) {
            return null;
        }
        return (int) $value;
    }

    private function handleTimedAssessmentError(Throwable $error)
    {
        if ($error instanceof TimedAssessmentAuthorizationException) {
            return $this->jsonResponse(['message' => $error->getMessage()], 403);
        }
        if ($error instanceof TimedAssessmentNotFoundException) {
            return $this->jsonResponse(['message' => $error->getMessage()], 404);
        }
        if ($error instanceof TimedAssessmentConflictException) {
            return $this->jsonResponse(['message' => $error->getMessage()], 409);
        }
        if ($error instanceof TimedAssessmentValidationException) {
            return $this->jsonResponse(['message' => $error->getMessage()], 422);
        }
        error_log('Timed assessment operation failed (' . get_class($error) . '): ' . $error->getMessage());
        return $this->jsonResponse(['message' => 'The timed assessment request could not be completed.'], 500);
    }

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
