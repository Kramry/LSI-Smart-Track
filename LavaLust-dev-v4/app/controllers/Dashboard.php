<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'controllers/Base.php';

class Dashboard extends Base
{
    public function dashboard()
    {
        if (!$this->requireUser()) {
            return;
        }

        $data = [
            'stats' => $this->dashboardModel->dashboardStats($this->user),
            'attendance' => $this->dashboardModel->recentAttendance($this->user),
            'announcements' => $this->dashboardModel->announcements(),
        ];

        if ($this->inRoleGroup('teaching_staff')) {
            $data['charts'] = $this->dashboardModel->dashboardChartData($this->user);
        }

        $this->render('dashboard', $data);
    }

    public function module($section)
    {
        if (!$this->requireUser()) {
            return;
        }
        if (!$this->canAccess($section)) {
            return $this->deny('You do not have access to that section.');
        }

        $definition = $this->resourceDefinition($section);
        if (!$definition) {
            show_404();
            return;
        }
        $query = $this->queryParams();

        $fields = $this->fieldsFor($section);
        foreach ($fields as &$field) {
            if (!empty($field['options'])) {
                $field['choices'] = $this->recordModel->options($field['options'], $this->user);
            }
        }
        unset($field);

        if ($section === 'documents') {
            $filters = [];
            foreach (['search', 'document_type', 'class_id', 'access_level', 'status', 'review_status', 'date_from', 'date_to', 'sort'] as $filter) {
                $value = $query[$filter] ?? '';
                $filters[$filter] = is_scalar($value) ? trim((string) $value) : '';
            }
            $filters['sort'] = $filters['sort'] ?: 'newest';
            $records = $this->documentModel->filterDocuments($this->user, $filters);
        } else {
            $records = $this->recordModel->records($section, $this->user);
        }
        if ($section === 'classes') {
            $requirementsByClass = [];
            foreach ($this->documentModel->documentRequirements() as $requirement) {
                $requirementsByClass[(int) $requirement['class_id']][] = $requirement['required_document_name'];
            }
            foreach ($records as &$record) {
                $record['requirements'] = $requirementsByClass[(int) $record['class_id']] ?? [];
            }
            unset($record);
        }
        $columns = $definition['columns'];
        if ($section === 'classes') {
            $columns[] = 'requirements';
        }

        $editId = (int) ($query['edit'] ?? $this->session->flashdata('smarttrack_edit') ?? 0);
        $editing = $editId
            ? $this->recordModel->findRecordScoped($definition['table'], $definition['key'], $editId, $this->user)
            : null;

        $traineeId = 0;
        if ($section === 'attendance' && ($this->user['role'] ?? '') === 'trainee') {
            $trainee = $this->userModel->traineeForUser($this->user['user_id']);
            $traineeId = $trainee ? (int) $trainee['trainee_id'] : 0;
        }

        $data = [
            'section' => $section,
            'title' => $definition['title'],
            'primary_key' => $definition['key'],
            'can_manage' => $this->canManage($section),
            'can_create' => $this->canManage($section) && !in_array($section, ['activity', 'documents'], true) && !($section === 'inquiries' && $this->user['role'] !== 'trainee'),
            'fields' => $fields,
            'records' => $records,
            'editing' => $editing,
            'columns' => $columns,
            'options' => [
                'trainees' => $this->recordModel->options('trainees', $this->user),
                'classes' => $this->recordModel->options('classes', $this->user),
                'schedules' => $this->recordModel->options('schedules', $this->user),
            ],
            'summary' => $section === 'attendance' && $this->user['role'] === 'trainee'
                ? $this->attendanceModel->attendanceSummary($traineeId)
                : [],
            'followups' => $section === 'attendance' && $this->user['role'] !== 'trainee'
                ? $this->dashboardModel->absenceFollowUps($this->user)
                : [],
            'document_requirements' => $section === 'documents' && $this->user['role'] === 'trainee'
                ? $this->documentModel->requirementsForTrainee((int) $this->user['user_id'])
                : [],
        ];

        if ($section === 'documents' && $this->inRoleGroup('administrative_staff')) {
            $data['doc_stats'] = $this->documentModel->documentStats($this->user);
            $data['recent_docs'] = $this->documentModel->recentDocuments($this->user, 5);
        }

        $this->render('module', $data);
    }

    public function save($section)
    {
        if (!$this->requireUser() || !$this->canAccess($section) || !$this->canManage($section) || !$this->validCsrf()) {
            return $this->deny('You are not allowed to change this section.');
        }
        $definition = $this->resourceDefinition($section);
        if (!$definition) {
            show_404();
            return;
        }

        $existing = null;
        $recordId = $this->positiveInteger($this->input($definition['key'])) ?? 0;
        if ($recordId) {
            if ($section === 'users' && $recordId === (int) $this->user['user_id']) {
                return $this->deny('Use My Profile to change your own account details.');
            }
            $existing = $this->recordModel->findRecordScoped($definition['table'], $definition['key'], $recordId, $this->user);
            if (!$existing) {
                if ($section === 'inquiries' && in_array($this->user['role'], ['trainee', 'instructor'], true)) {
                    return $this->deny('That inquiry is not assigned to a trainee or class you can access.');
                }
                return $this->deny('That record is not available to your account.');
            }
            if ($section === 'users' && $this->user['role'] !== 'administrator' && $existing['role'] === 'administrator') {
                return $this->deny('Only an administrator can change another administrator account.');
            }
        }
        $fields = $this->fieldsFor($section);
        $data = [];
        $errors = [];
        foreach ($fields as $field) {
            $name = $field['name'];
            $inputValue = $this->input($name);
            $value = is_scalar($inputValue) ? trim((string) $inputValue) : '';
            if (!empty($field['required']) && $value === '' && !($name === 'password' && $recordId > 0)) {
                $errors[] = $field['label'] . ' is required.';
                continue;
            }
            if ($value !== '' && ($field['type'] ?? '') === 'select' && !in_array($value, $field['values'], true)) {
                $errors[] = 'Choose a valid ' . strtolower($field['label']) . '.';
                continue;
            }
            if ($value !== '' && ($field['type'] ?? '') === 'options') {
                $validIds = array_map(static function ($option) { return (string) $option['id']; }, $this->recordModel->options($field['options'], $this->user));
                if (!in_array($value, $validIds, true)) {
                    $errors[] = 'Choose a valid ' . strtolower($field['label']) . '.';
                    continue;
                }
            }
            if ($value !== '' && ($field['type'] ?? '') === 'date') {
                $date = DateTime::createFromFormat('!Y-m-d', $value);
                $dateErrors = DateTime::getLastErrors();
                if (!$date || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $date->format('Y-m-d') !== $value) {
                    $errors[] = 'Enter a valid ' . strtolower($field['label']) . '.';
                    continue;
                }
            }
            if ($value !== '' && ($field['type'] ?? '') === 'time') {
                if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/', $value)) {
                    $errors[] = 'Enter a valid ' . strtolower($field['label']) . '.';
                    continue;
                }
                if (mb_strlen($value) === 5) {
                    $value .= ':00';
                }
            }
            if ($value !== '' && $name === 'published_at') {
                $value = str_replace('T', ' ', $value);
                if (mb_strlen($value) === 16) {
                    $value .= ':00';
                }
                $publishedAt = DateTime::createFromFormat('!Y-m-d H:i:s', $value);
                if (!$publishedAt || $publishedAt->format('Y-m-d H:i:s') !== $value) {
                    $errors[] = 'Enter a valid publication date and time.';
                    continue;
                }
            }
            if (isset($field['max_length']) && mb_strlen($value) > $field['max_length']) {
                $errors[] = $field['label'] . ' is too long.';
                continue;
            }
            if ($value !== '' || in_array($name, ['time_in', 'time_out', 'class_id', 'schedule_id', 'program_id', 'instructor_id', 'user_id', 'reply'], true)) {
                $data[$name] = $value === '' ? null : $value;
            }
        }

        if ($section === 'users' && isset($data['password']) && mb_strlen((string) $data['password']) < 10) {
            $errors[] = 'Passwords must contain at least 10 characters.';
        }
        if ($section === 'users' && isset($data['username']) && !preg_match('/^[A-Za-z0-9_.-]{4,50}$/', (string) $data['username'])) {
            $errors[] = 'Usernames must be 4-50 letters, numbers, dots, underscores, or hyphens.';
        }
        if ($section === 'users' && !$recordId && empty($data['password'])) {
            $errors[] = 'A temporary password is required for a new account.';
        }
        if ($section === 'attendance' && !empty($data['class_id']) && !empty($data['trainee_id'])) {
            $enrollment = $this->attendanceModel->enrollmentForClass((int) $data['class_id'], (int) $data['trainee_id'], $this->user);
            if (!$enrollment) {
                $errors[] = 'The trainee must be enrolled in the selected class.';
            }
            if (!empty($data['schedule_id'])) {
                $schedule = $this->attendanceModel->scheduleForClassOnDate(
                    (int) $data['schedule_id'],
                    (int) $data['class_id'],
                    $data['attendance_date']
                );
                if (!$schedule) {
                    $errors[] = 'The session must belong to the selected class and attendance date.';
                }
            }
        }
        if ($section === 'attendance') {
            if (in_array($data['status'] ?? '', ['Present', 'Late'], true) && empty($data['time_in'])) {
                $errors[] = 'Present and late records require a time-in.';
            }
            if (!empty($data['time_out']) && empty($data['time_in'])) {
                $errors[] = 'A time-out requires a time-in.';
            }
            if (!empty($data['time_in']) && !empty($data['time_out']) && $data['time_out'] < $data['time_in']) {
                $errors[] = 'Time-out cannot be earlier than time-in.';
            }
        }
        if ($section === 'classes' && !empty($data['start_date']) && !empty($data['end_date']) && $data['end_date'] < $data['start_date']) {
            $errors[] = 'Class end date cannot be earlier than its start date.';
        }
        if ($section === 'schedules' && !empty($data['start_time']) && !empty($data['end_time']) && $data['end_time'] <= $data['start_time']) {
            $errors[] = 'Session end time must be later than its start time.';
        }
        if ($section === 'users' && !$recordId) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        if ($section === 'users' && isset($data['password'])) {
            $data['password'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        }
        if ($section === 'attendance') {
            $data['recorded_by'] = (int) $this->user['user_id'];
            if (empty($data['remarks'])) {
                $data['remarks'] = 'Manual entry';
            }
        }
        if ($section === 'announcements') {
            $data['user_id'] = (int) $this->user['user_id'];
            if (($data['status'] ?? '') === 'published' && $this->user['role'] !== 'administrator') {
                $errors[] = 'Only administrators can publish announcements.';
            }
            if (($data['status'] ?? '') === 'published' && empty($data['published_at'])) {
                $data['published_at'] = date('Y-m-d H:i:s');
            }
        }
        if ($section === 'inquiries') {
            if ($this->user['role'] === 'trainee') {
                $trainee = $this->userModel->traineeForUser($this->user['user_id']);
                if (!$trainee) {
                    $errors[] = 'Your trainee profile is not linked to this account.';
                } else {
                    $data['trainee_id'] = (int) $trainee['trainee_id'];
                    unset($data['reply'], $data['status']);
                    if (!$recordId) {
                        $data['status'] = 'open';
                    }
                }
            } elseif ($recordId) {
                if (!$existing) {
                    return $this->deny('That inquiry is not assigned to a trainee or class you can access.');
                }
                $data = array_intersect_key($data, ['reply' => true, 'status' => true]);
            }
        }

        if ($errors) {
            if ($this->apiRequest) {
                return $this->jsonResponse(['message' => implode(' ', $errors), 'errors' => $errors], 422);
            }
            return $this->moduleWithError($section, implode(' ', $errors), $recordId);
        }

        try {
            $savedId = $this->recordModel->transactional(function () use ($section, $definition, $data, $recordId, $existing) {
                $savedId = $this->saveRecordWithQrRetry($section, $definition, $data, $recordId);
                if ($section === 'classes') {
                    $this->eligibilityModel->recalculateClassEligibility((int) $savedId);
                } elseif (in_array($section, ['enrollments', 'attendance'], true)) {
                    if ($existing) {
                        $this->recalculateEligibilityPair($existing['trainee_id'] ?? 0, $existing['class_id'] ?? 0);
                    }
                    $savedRecord = $this->recordModel->findRecord($definition['table'], $definition['key'], (int) $savedId);
                    if ($savedRecord) {
                        $this->recalculateEligibilityPair($savedRecord['trainee_id'] ?? 0, $savedRecord['class_id'] ?? 0);
                    }
                }
                $this->recordModel->logActivity((int) $this->user['user_id'], ($recordId ? 'Updated ' : 'Created ') . $section, 'Record #' . $savedId);
                return $savedId;
            });
            if ($this->apiRequest) {
                return $this->jsonResponse([
                    'message' => ucfirst(rtrim($definition['title'], 's')) . ' saved.',
                    'id' => (int) $savedId,
                ]);
            }
            $this->notice(ucfirst(rtrim($definition['title'], 's')) . ' saved.');
            redirect($this->url('manage/' . $section));
        } catch (Throwable $error) {
            error_log('Module record save failed for ' . $section . ' (' . get_class($error) . '): ' . $error->getMessage());
            if ($this->apiRequest) {
                return $this->jsonResponse(['message' => 'The record could not be saved. Check for duplicate values and valid relationships.'], 422);
            }
            return $this->moduleWithError($section, 'The record could not be saved. Check for duplicate values and valid relationships.', $recordId);
        }
    }

    private function saveRecordWithQrRetry($section, array $definition, array $data, $recordId)
    {
        $isNewTrainee = $section === 'trainees' && !$recordId;
        for ($retry = 0; ; $retry++) {
            if ($isNewTrainee) {
                $data['qr_code'] = 'LSI-' . strtoupper(bin2hex(random_bytes(6)));
            }
            try {
                return $this->recordModel->saveRecord(
                    $definition['table'],
                    $definition['key'],
                    $data,
                    $recordId ?: null
                );
            } catch (Throwable $error) {
                if (!$isNewTrainee || $retry >= 5 || !$this->isDuplicateQrError($error)) {
                    throw $error;
                }
            }
        }
    }

    private function isDuplicateQrError(Throwable $error)
    {
        $sqlState = (string) $error->getCode();
        $message = $error->getMessage();
        $driverCode = null;
        if ($error instanceof PDOException && isset($error->errorInfo[1])) {
            $sqlState = (string) ($error->errorInfo[0] ?? $sqlState);
            $driverCode = (int) $error->errorInfo[1];
        }
        if ($sqlState !== '23000' && preg_match('/SQLSTATE\[([A-Z0-9]{5})\]/', $message, $matches)) {
            $sqlState = $matches[1];
        }
        $duplicateKey = $driverCode === 1062
            || preg_match('/duplicate (?:entry|key)/i', $message) === 1;
        return $sqlState === '23000' && $duplicateKey;
    }

    private function positiveInteger($value)
    {
        if (!is_scalar($value)) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        return $integer !== false && $integer > 0 ? $integer : null;
    }

    public function delete($section, $id)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            if ($this->apiRequest) {
                return $this->jsonResponse(['message' => 'Method not allowed.'], 405);
            }
            http_response_code(405);
            header('Allow: POST');
            exit('Method not allowed.');
        }
        if (!$this->requireUser() || !$this->canAccess($section) || !$this->canManage($section) || !$this->validCsrf()) {
            return $this->deny('You are not allowed to delete records in this section.');
        }
        $definition = $this->resourceDefinition($section);
        if (!$definition || !ctype_digit((string) $id) || !(int) $id) {
            show_404();
            return;
        }
        $existing = $this->recordModel->findRecordScoped($definition['table'], $definition['key'], (int) $id, $this->user);
        if (!$existing) {
            return $this->deny('That record is not available to your account.');
        }
        $deactivate = in_array($section, ['users', 'trainees'], true);
        if ($section === 'users' && (int) $id === (int) $this->user['user_id']) {
            return $this->deny('You cannot deactivate your own account.');
        }
        if ($section === 'users' && $this->user['role'] !== 'administrator' && $existing['role'] === 'administrator') {
            return $this->deny('Only an administrator can deactivate another administrator account.');
        }
        $deleted = $this->recordModel->deleteRecord($definition['table'], $definition['key'], (int) $id, $deactivate);
        if ($section === 'documents' && $deleted && !empty($existing['file_path'])) {
            $path = $this->privateDocumentPath($existing['file_path']);
            if ($path) {
                @unlink($path);
            }
        }
        if (in_array($section, ['enrollments', 'attendance', 'documents'], true)) {
            $this->recalculateEligibilityPair($existing['trainee_id'] ?? 0, $existing['class_id'] ?? 0);
        }
        $this->recordModel->logActivity((int) $this->user['user_id'], 'Deleted ' . $section, 'Record #' . (int) $id . ($deactivate ? ' deactivated' : ' removed'));
        if ($this->apiRequest) {
            return $this->jsonResponse(['message' => $deactivate ? 'Record deactivated.' : 'Record deleted.']);
        }
        $this->notice($deactivate ? 'Record deactivated.' : 'Record deleted.');
        redirect($this->url('manage/' . $section));
    }
}
