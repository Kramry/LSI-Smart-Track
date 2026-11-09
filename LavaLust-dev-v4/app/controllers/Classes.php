<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'controllers/Base.php';
require_once APP_DIR . 'models/Classes_model.php';

class Classes extends Base
{
    private $classesModel;

    public function before_action()
    {
        parent::before_action();
        $this->classesModel = new Classes_model($this->db);
    }

    public function index()
    {
        if (!$this->requireUser() || !in_array($this->user['role'], ['administrator', 'staff', 'instructor'], true)) {
            return $this->deny('You are not allowed to view teaching classes.');
        }
        $filters = [
            'program_id' => $this->numericFilter($_GET['program_id'] ?? ''),
            'instructor_id' => $this->numericFilter($_GET['instructor_id'] ?? ''),
        ];
        $classes = $this->classesModel->classes($this->user, $filters);
        foreach ($classes as &$class) {
            $class['teaching_status'] = $this->teachingStatus($class);
            $class['today_not_scanned'] = max(0, (int) $class['enrolled_count'] - (int) $class['today_scanned']);
        }
        unset($class);

        $editingClass = null;
        $editId = $this->numericFilter($_GET['edit'] ?? '');
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
            'show_create_form' => $this->canManage('classes') && (!empty($_GET['create']) || $editingClass),
            'editing_class' => $editingClass,
        ]);
    }

    public function detail($id)
    {
        if (!$this->requireUser() || !in_array($this->user['role'], ['administrator', 'staff', 'instructor'], true)) {
            return $this->deny('You are not allowed to view class details.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            show_404();
            return;
        }
        $classId = (int) $id;
        $class = $this->classesModel->classById($classId, $this->user);
        if (!$class) {
            show_404();
            return;
        }
        $this->render('class_detail', [
            'title' => $class['class_name'],
            'class' => $class,
            'schedules' => $this->classesModel->schedules($classId),
            'roster' => $this->classesModel->roster($classId),
            'attendance' => $this->classesModel->attendance($classId),
            'assessments' => $this->classesModel->assessments($classId),
            'documents' => $this->classesModel->documents($classId),
            'announcements' => $this->classesModel->announcements($classId),
            'stats' => $this->classesModel->quickStats($classId),
        ]);
    }

    public function apiIndex()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !$this->requireUser()) {
            return;
        }
        if (!in_array($this->user['role'], ['administrator', 'staff', 'instructor'], true)) {
            return $this->jsonResponse(['message' => 'You are not allowed to view classes.'], 403);
        }
        $filters = [
            'program_id' => $this->numericFilter($_GET['program_id'] ?? ''),
            'instructor_id' => $this->numericFilter($_GET['instructor_id'] ?? ''),
        ];
        $classes = $this->classesModel->classes($this->user, $filters);
        foreach ($classes as &$class) {
            $class['teaching_status'] = $this->teachingStatus($class);
            $class['today_not_scanned'] = max(0, (int) $class['enrolled_count'] - (int) $class['today_scanned']);
        }
        unset($class);
        return $this->jsonResponse([
            'classes' => $classes,
            'programs' => $this->recordModel->options('programs'),
            'instructors' => $this->recordModel->options('instructors'),
        ]);
    }

    public function apiDetail($id)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !$this->requireUser()) {
            return;
        }
        if (!in_array($this->user['role'], ['administrator', 'staff', 'instructor'], true)
            || !ctype_digit((string) $id) || !(int) $id) {
            return $this->jsonResponse(['message' => 'Class not found.'], 404);
        }
        $classId = (int) $id;
        $class = $this->classesModel->classById($classId, $this->user);
        if (!$class) {
            return $this->jsonResponse(['message' => 'Class not found.'], 404);
        }
        return $this->jsonResponse([
            'class' => $class,
            'schedules' => $this->classesModel->schedules($classId),
            'roster' => $this->classesModel->roster($classId),
            'attendance' => $this->classesModel->attendance($classId),
            'assessments' => $this->classesModel->assessments($classId),
            'documents' => $this->classesModel->documents($classId),
            'announcements' => $this->classesModel->announcements($classId),
            'stats' => $this->classesModel->quickStats($classId),
        ]);
    }

    private function numericFilter($value)
    {
        return is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : null;
    }

    private function teachingStatus(array $class)
    {
        $today = date('Y-m-d');
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
        if (!$this->requireUser() || !$this->validCsrf() || !$this->canManage('classes')) {
            return $this->deny('You are not allowed to manage classes.');
        }

        $classId = $this->numericFilter($this->input('class_id'));
        $className = trim((string) $this->input('class_name'));
        $programId = $this->numericFilter($this->input('program_id'));
        $instructorId = $this->numericFilter($this->input('instructor_id'));
        $startDate = trim((string) $this->input('start_date'));
        $endDate = trim((string) $this->input('end_date'));
        $status = trim((string) $this->input('status'));

        $errors = [];

        if ($className === '') {
            $errors[] = 'Class name is required.';
        }
        if (!$programId) {
            $errors[] = 'Program is required.';
        }
        if ($status === '' || !in_array($status, ['active', 'inactive'], true)) {
            $errors[] = 'Status is required.';
        }
        if ($startDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $errors[] = 'Enter a valid start date.';
        }
        if ($endDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            $errors[] = 'Enter a valid end date.';
        }
        if ($startDate !== '' && $endDate !== '' && $endDate < $startDate) {
            $errors[] = 'Class end date cannot be earlier than its start date.';
        }

        if ($errors) {
            $this->session->set_flashdata('error', implode(' ', $errors));
            redirect($this->url('manage/classes' . ($classId ? '?edit=' . $classId : '?create=1')));
            return;
        }

        try {
            $data = [
                'class_name' => $className,
                'program_id' => $programId,
                'instructor_id' => $instructorId ?: null,
                'start_date' => $startDate ?: null,
                'end_date' => $endDate ?: null,
                'status' => $status,
            ];

            if ($classId) {
                $this->db->table('training_classes')->where('class_id', $classId)->update($data);
                $savedId = $classId;
                $this->recordModel->logActivity((int) $this->user['user_id'], 'Updated class', 'Class #' . $savedId);
            } else {
                $savedId = $this->db->table('training_classes')->insert($data);
                $this->recordModel->logActivity((int) $this->user['user_id'], 'Created class', 'Class #' . $savedId);
            }

            $this->eligibilityModel->recalculateClassEligibility((int) $savedId);
            $this->notice($classId ? 'Class updated.' : 'Class created.');
            redirect($this->url('manage/classes'));
        } catch (Throwable $error) {
            $this->session->set_flashdata('error', 'The class could not be saved. Check for duplicate values and valid relationships.');
            redirect($this->url('manage/classes' . ($classId ? '?edit=' . $classId : '?create=1')));
        }
    }

    public function delete($id)
    {
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
            $this->db->table('training_classes')->where('class_id', $classId)->delete();
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
        $this->apiRequest = true;
        if (!$this->requireUser() || !$this->validCsrf() || !$this->canManage('classes')) {
            return $this->jsonResponse(['message' => 'You are not allowed to manage classes.'], 403);
        }

        $classId = $this->numericFilter($this->input('class_id'));
        $className = trim((string) $this->input('class_name'));
        $programId = $this->numericFilter($this->input('program_id'));
        $instructorId = $this->numericFilter($this->input('instructor_id'));
        $startDate = trim((string) $this->input('start_date'));
        $endDate = trim((string) $this->input('end_date'));
        $status = trim((string) $this->input('status'));

        $errors = [];

        if ($className === '') {
            $errors[] = 'Class name is required.';
        }
        if (!$programId) {
            $errors[] = 'Program is required.';
        }
        if ($status === '' || !in_array($status, ['active', 'inactive'], true)) {
            $errors[] = 'Status is required.';
        }
        if ($startDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $errors[] = 'Enter a valid start date.';
        }
        if ($endDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            $errors[] = 'Enter a valid end date.';
        }
        if ($startDate !== '' && $endDate !== '' && $endDate < $startDate) {
            $errors[] = 'Class end date cannot be earlier than its start date.';
        }

        if ($errors) {
            return $this->jsonResponse(['message' => implode(' ', $errors)], 422);
        }

        try {
            $data = [
                'class_name' => $className,
                'program_id' => $programId,
                'instructor_id' => $instructorId ?: null,
                'start_date' => $startDate ?: null,
                'end_date' => $endDate ?: null,
                'status' => $status,
            ];

            if ($classId) {
                $this->db->table('training_classes')->where('class_id', $classId)->update($data);
                $savedId = $classId;
                $this->recordModel->logActivity((int) $this->user['user_id'], 'Updated class', 'Class #' . $savedId);
            } else {
                $savedId = $this->db->table('training_classes')->insert($data);
                $this->recordModel->logActivity((int) $this->user['user_id'], 'Created class', 'Class #' . $savedId);
            }

            $this->eligibilityModel->recalculateClassEligibility((int) $savedId);
            return $this->jsonResponse([
                'message' => $classId ? 'Class updated.' : 'Class created.',
                'id' => (int) $savedId,
            ]);
        } catch (Throwable $error) {
            error_log('Class save error: ' . $error->getMessage());
            return $this->jsonResponse(['message' => 'The class could not be saved: ' . $error->getMessage()], 422);
        }
    }

    public function apiDelete($id)
    {
        $this->apiRequest = true;
        if (!$this->requireUser() || !$this->validCsrf() || !$this->canManage('classes')) {
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
            $this->db->table('training_classes')->where('class_id', $classId)->delete();
            $this->recordModel->logActivity((int) $this->user['user_id'], 'Deleted class', 'Class #' . $classId);
            return $this->jsonResponse(['message' => 'Class deleted.']);
        } catch (Throwable $error) {
            return $this->jsonResponse(['message' => 'The class could not be deleted. It may have related records.'], 422);
        }
    }
}
