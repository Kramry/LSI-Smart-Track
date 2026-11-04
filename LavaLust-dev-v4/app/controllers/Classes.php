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
        $this->render('classes', [
            'title' => $this->user['role'] === 'instructor' ? 'My classes' : 'Classes',
            'classes' => $classes,
            'filters' => $filters,
            'programs' => $this->recordModel->options('programs'),
            'instructors' => $this->recordModel->options('instructors'),
            'can_create' => $this->canManage('classes'),
            'show_create_form' => $this->canManage('classes') && !empty($_GET['create']),
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
}
