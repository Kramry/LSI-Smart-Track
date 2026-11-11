<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'controllers/Base.php';

class Classes extends Base
{
    public function __construct()
    {
        parent::__construct();
        $this->classesModel = $this->modelRegistry->loadOptional($this->db, 'Classes_model');
    }

    public function index()
    {
        $this->requireRequestMethod('GET');
        if (!$this->requireUser() || !$this->inRoleGroup('teaching_staff')) {
            return $this->deny('You are not allowed to view teaching classes.');
        }
        $query = $this->queryParams();
        $filters = [
            'program_id' => $this->numericFilter($query['program_id'] ?? ''),
            'instructor_id' => $this->numericFilter($query['instructor_id'] ?? ''),
        ];
        $classes = $this->buildClassList($this->user, $filters);

        $editingClass = null;
        $editId = $this->numericFilter($query['edit'] ?? '');
        if ($editId && $this->canManage('classes')) {
            $editingClass = $this->classesModel->classById($editId, $this->user);
        }

        $this->render('classes', [
            'title' => $this->user['role'] === 'instructor' ? 'My classes' : 'Classes',
            'classes' => $classes,
            'filters' => $filters,
            'programs' => $this->recordModel->options('programs'),
            'instructors' => $this->recordModel->options('instructors'),
            'can_create' => $this->canManage('classes'),
            'show_create_form' => $this->canManage('classes') && (!empty($query['create']) || $editingClass),
            'editing_class' => $editingClass,
        ]);
    }

    public function detail($id)
    {
        $this->requireRequestMethod('GET');
        if (!$this->requireUser() || !$this->inRoleGroup('teaching_staff')) {
            return $this->deny('You are not allowed to view class details.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            show_404();
            return;
        }
        $detail = $this->loadClassDetail((int) $id, $this->user);
        if ($detail === null) {
            show_404();
            return;
        }
        $this->render('class_detail', [
            'title' => $detail['class']['class_name'],
            'class' => $detail['class'],
            'schedules' => $detail['schedules'],
            'roster' => $detail['roster'],
            'attendance' => $detail['attendance'],
            'assessments' => $detail['assessments'],
            'documents' => $detail['documents'],
            'announcements' => $detail['announcements'],
            'stats' => $detail['quickStats'],
        ]);
    }

    public function apiIndex()
    {
        $this->requireRequestMethod('GET');
        if (!$this->requireUser()) {
            return;
        }
        if (!$this->inRoleGroup('teaching_staff')) {
            return $this->jsonResponse(['message' => 'You are not allowed to view classes.'], 403);
        }
        $query = $this->queryParams();
        $filters = [
            'program_id' => $this->numericFilter($query['program_id'] ?? ''),
            'instructor_id' => $this->numericFilter($query['instructor_id'] ?? ''),
        ];
        $classes = $this->buildClassList($this->user, $filters);
        return $this->jsonResponse([
            'classes' => $classes,
            'programs' => $this->recordModel->options('programs'),
            'instructors' => $this->recordModel->options('instructors'),
        ]);
    }

    public function apiDetail($id)
    {
        $this->requireRequestMethod('GET');
        if (!$this->requireUser()) {
            return;
        }
        if (!$this->inRoleGroup('teaching_staff')
            || !ctype_digit((string) $id) || !(int) $id) {
            return $this->jsonResponse(['message' => 'Class not found.'], 404);
        }
        $detail = $this->loadClassDetail((int) $id, $this->user);
        if ($detail === null) {
            return $this->jsonResponse(['message' => 'Class not found.'], 404);
        }
        return $this->jsonResponse([
            'class' => $detail['class'],
            'schedules' => $detail['schedules'],
            'roster' => $detail['roster'],
            'attendance' => $detail['attendance'],
            'assessments' => $detail['assessments'],
            'documents' => $detail['documents'],
            'announcements' => $detail['announcements'],
            'stats' => $detail['quickStats'],
        ]);
    }

    private function numericFilter($value)
    {
        if (!is_scalar($value)) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        return $integer !== false && $integer > 0 ? $integer : null;
    }

    private function buildClassList(array $user, array $filters)
    {
        $classes = $this->classesModel->classes($user, $filters);
        foreach ($classes as &$class) {
            $class['teaching_status'] = $this->teachingStatus($class);
            $class['today_not_scanned'] = max(0, (int) ($class['enrolled_count'] ?? 0) - (int) ($class['today_scanned'] ?? 0));
        }
        unset($class);
        return $classes;
    }

    private function loadClassDetail($classId, array $user)
    {
        $class = $this->classesModel->classById($classId, $user);
        if (!$class) {
            return null;
        }
        return [
            'class' => $class,
            'schedules' => $this->classesModel->schedules($classId),
            'roster' => $this->classesModel->roster($classId),
            'attendance' => $this->classesModel->attendance($classId),
            'assessments' => $this->classesModel->assessments($classId),
            'documents' => $this->classesModel->documents($classId),
            'announcements' => $this->classesModel->announcements($classId),
            'quickStats' => $this->classesModel->quickStats($classId),
        ];
    }

    private function validateClassData(array $input)
    {
        $errors = [];
        $classIdValue = $input['class_id'] ?? '';
        $classId = $classIdValue === '' || $classIdValue === null ? 0 : $this->numericFilter($classIdValue);
        if ($classId === null) {
            $errors[] = 'Choose a valid class.';
            $classId = 0;
        }

        $classNameValue = $input['class_name'] ?? '';
        $className = is_scalar($classNameValue) ? trim((string) $classNameValue) : '';
        if ($className === '') {
            $errors[] = 'Class name is required.';
        } elseif (strlen($className) > 255) {
            $errors[] = 'Class name must be 255 bytes or fewer.';
        }

        $programId = $this->numericFilter($input['program_id'] ?? null);
        if ($programId === null) {
            $errors[] = 'Program is required.';
        }

        $instructorValue = $input['instructor_id'] ?? '';
        $instructorId = $instructorValue === '' || $instructorValue === null
            ? null
            : $this->numericFilter($instructorValue);
        if ($instructorValue !== '' && $instructorValue !== null && $instructorId === null) {
            $errors[] = 'Choose a valid instructor.';
        }

        $startDateValue = $input['start_date'] ?? '';
        $startDate = is_scalar($startDateValue) ? trim((string) $startDateValue) : '';
        $endDateValue = $input['end_date'] ?? '';
        $endDate = is_scalar($endDateValue) ? trim((string) $endDateValue) : '';
        if (!is_scalar($startDateValue) || ($startDate !== '' && !$this->isValidDate($startDate))) {
            $errors[] = 'Enter a valid start date.';
        }
        if (!is_scalar($endDateValue) || ($endDate !== '' && !$this->isValidDate($endDate))) {
            $errors[] = 'Enter a valid end date.';
        }
        if ($startDate !== '' && $endDate !== '' && $this->isValidDate($startDate) && $this->isValidDate($endDate) && $endDate < $startDate) {
            $errors[] = 'Class end date cannot be earlier than its start date.';
        }

        $statusValue = $input['status'] ?? '';
        $status = is_scalar($statusValue) ? trim((string) $statusValue) : '';
        if (!in_array($status, ['active', 'inactive'], true)) {
            $errors[] = 'Status is required.';
        }

        return [
            'errors' => $errors,
            'data' => [
                'class_name' => $className,
                'program_id' => $programId,
                'instructor_id' => $instructorId,
                'start_date' => $startDate !== '' ? $startDate : null,
                'end_date' => $endDate !== '' ? $endDate : null,
                'status' => $status,
            ],
            'class_id' => $classId,
        ];
    }

    private function isValidDate($value)
    {
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        $errors = DateTime::getLastErrors();
        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }

    private function persistClass($classId, array $data, array $user)
    {
        if (!$this->db->transaction()) {
            try {
                $this->db->roll_back();
            } catch (Throwable $rollbackError) {
                error_log('Class transaction initialization cleanup failed (' . get_class($rollbackError) . ').');
            }
            throw new RuntimeException('Unable to start the class transaction.');
        }

        try {
            if ($classId) {
                if (!$this->classesModel->classById($classId, $user)) {
                    $this->db->roll_back();
                    return ['success' => false, 'reason' => 'not_found'];
                }
                $this->classesModel->updateClass($classId, $data);
                $savedId = $classId;
                $action = 'Updated class';
            } else {
                $this->classesModel->createClass($data);
                $savedId = (int) $this->db->last_id();
                if ($savedId < 1) {
                    throw new RuntimeException('Class insert did not return a valid ID.');
                }
                $action = 'Created class';
            }

            $this->recordModel->logActivity((int) $user['user_id'], $action, 'Class #' . $savedId);
            $this->eligibilityModel->recalculateClassEligibility($savedId);
            if (!$this->db->commit()) {
                throw new RuntimeException('Unable to commit the class transaction.');
            }
            return ['success' => true, 'id' => $savedId];
        } catch (Throwable $error) {
            try {
                $this->db->roll_back();
            } catch (Throwable $rollbackError) {
                error_log('Class transaction rollback failed (' . get_class($rollbackError) . ').');
            }
            throw $error;
        }
    }

    private function teachingStatus(array $class)
    {
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        if (!empty($class['end_date']) && $class['end_date'] < $today) {
            return 'completed';
        }
        if (!empty($class['start_date']) && $class['start_date'] > $today) {
            return 'upcoming';
        }
        return 'ongoing';
    }

    public function save()
    {
        $this->requireRequestMethod('POST');
        if (!$this->requireUser() || !$this->canManage('classes') || !$this->validCsrf()) {
            return $this->deny('You are not allowed to manage classes.');
        }

        $validated = $this->validateClassData($this->requestData());
        $classId = $validated['class_id'];
        if ($validated['errors']) {
            $this->session->set_flashdata('error', implode(' ', $validated['errors']));
            redirect($this->url('manage/classes' . ($classId ? '?edit=' . $classId : '?create=1')));
            return;
        }

        if ($classId && !$this->classesModel->classById($classId, $this->user)) {
            return $this->deny('That class is not available to your account.');
        }

        try {
            $result = $this->persistClass($classId, $validated['data'], $this->user);
            if (!$result['success']) {
                return $this->deny('That class is not available to your account.');
            }
            $this->notice($classId ? 'Class updated.' : 'Class created.');
            redirect($this->url('manage/classes'));
        } catch (Throwable $error) {
            error_log('Class save error: ' . $error->getMessage());
            $this->session->set_flashdata('error', 'The class could not be saved. Check for duplicate values and valid relationships.');
            redirect($this->url('manage/classes' . ($classId ? '?edit=' . $classId : '?create=1')));
        }
    }

    public function delete($id)
    {
        $this->requireRequestMethod('POST');
        if (!$this->requireUser() || !$this->validCsrf() || !$this->canManage('classes')) {
            return $this->deny('You are not allowed to delete classes.');
        }

        if (!ctype_digit((string) $id) || !(int) $id) {
            show_404();
            return;
        }

        $classId = (int) $id;
        $class = $this->classesModel->classById($classId, $this->user);

        if (!$class) {
            return $this->deny('That class is not available to your account.');
        }

        try {
            $this->classesModel->deleteClass($classId);
            $this->recordModel->logActivity((int) $this->user['user_id'], 'Deleted class', 'Class #' . $classId);
            $this->notice('Class deleted.');
            redirect($this->url('manage/classes'));
        } catch (Throwable $error) {
            $this->session->set_flashdata('error', 'The class could not be deleted. It may have related records.');
            redirect($this->url('manage/classes'));
        }
    }

    public function apiSave()
    {
        $this->requireRequestMethod('POST');
        if (!$this->requireUser() || !$this->canManage('classes') || !$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'You are not allowed to manage classes.'], 403);
        }

        $validated = $this->validateClassData($this->requestData());
        $classId = $validated['class_id'];
        if ($validated['errors']) {
            return $this->jsonResponse([
                'message' => implode(' ', $validated['errors']),
                'errors' => $validated['errors'],
            ], 422);
        }

        try {
            $result = $this->persistClass($classId, $validated['data'], $this->user);
            if (!$result['success']) {
                return $this->jsonResponse(['message' => 'That class is not available to your account.'], 403);
            }
            return $this->jsonResponse([
                'message' => $classId ? 'Class updated.' : 'Class created.',
                'id' => (int) $result['id'],
            ]);
        } catch (Throwable $error) {
            error_log('Class save error: ' . $error->getMessage());
            return $this->jsonResponse(['message' => 'The class could not be saved. Check for duplicate values and valid relationships.'], 422);
        }
    }

    public function apiDelete($id)
    {
        $this->requireRequestMethod('DELETE');
        if (!$this->requireUser() || !$this->canManage('classes') || !$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'You are not allowed to delete classes.'], 403);
        }

        if (!ctype_digit((string) $id) || !(int) $id) {
            return $this->jsonResponse(['message' => 'Class not found.'], 404);
        }

        $classId = (int) $id;
        $class = $this->classesModel->classById($classId, $this->user);

        if (!$class) {
            return $this->jsonResponse(['message' => 'That class is not available to your account.'], 404);
        }

        try {
            $this->classesModel->deleteClass($classId);
            $this->recordModel->logActivity((int) $this->user['user_id'], 'Deleted class', 'Class #' . $classId);
            return $this->jsonResponse(['message' => 'Class deleted.']);
        } catch (Throwable $error) {
            return $this->jsonResponse(['message' => 'The class could not be deleted. It may have related records.'], 422);
        }
    }
}






