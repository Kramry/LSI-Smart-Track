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
            $editingClass = $this->classById($editId, $this->user);
        }

        $this->render('classes', [
            'title' => $this->user['role'] === 'instructor' ? 'My classes' : 'Classes',
            'classes' => $classes,
            'filters' => $filters,
            'programs' => $this->programOptions(),
            'instructors' => $this->instructorOptions(),
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
            'programs' => $this->programOptions(),
            'instructors' => $this->instructorOptions(),
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

    private function classById(int $classId, array $user)
    {
        $room = $this->hasClassRoomColumn() ? 'c.room' : 'NULL AS room';
        $query = $this->db->select('c.class_id, c.class_name, ' . $room . ', c.start_date, c.end_date, c.status, c.instructor_id, p.program_id, p.program_name')
            ->from('training_classes c')->join('training_programs p', 'p.program_id = c.program_id')
            ->where('c.class_id', $classId);
        if (($user['role'] ?? '') === 'instructor') {
            $query->where('c.instructor_id', (int) $user['user_id']);
        }
        return $query->limit(1)->get() ?: null;
    }

    private function hasClassRoomColumn(): bool
    {
        $database = (string) (getenv('DB_NAME') ?: 'lsi-smart-track');
        $result = $this->db->select('COUNT(*) AS total')->from('information_schema.COLUMNS')
            ->where(['TABLE_SCHEMA' => $database, 'TABLE_NAME' => 'training_classes', 'COLUMN_NAME' => 'room'])
            ->get();
        return (int) ($result['total'] ?? 0) > 0;
    }

    private function programOptions(): array
    {
        return $this->db->select('program_id AS id, program_name AS label')->from('training_programs')
            ->where('status', 'active')->order_by('program_name')->get_all();
    }

    private function instructorOptions(): array
    {
        return $this->db->select('user_id AS id, full_name AS label')->from('users')
            ->where(['role' => 'instructor', 'status' => 'active'])->order_by('full_name')->get_all();
    }

    private function loadClassDetail($classId, array $user)
    {
        $class = $this->classById((int) $classId, $user);
        if (!$class) {
            return null;
        }
        return [
            'class' => $class,
            'schedules' => $this->db->select('schedule_id, schedule_date, start_time, end_time')->from('training_schedules')
                ->where('class_id', (int) $classId)->order_by('schedule_date')->order_by('start_time')->get_all(),
            'roster' => $this->classRoster($classId),
            'attendance' => $this->classAttendance($classId),
            'assessments' => $this->classAssessments($classId),
            'documents' => $this->classDocuments($classId),
            'announcements' => $this->classAnnouncements($classId),
            'quickStats' => $this->classQuickStats($classId),
        ];
    }

    private function classRoster(int $classId): array
    {
        $rows = $this->db->select('t.trainee_id, t.first_name, t.last_name, t.email, COUNT(DISTINCT a.attendance_id) AS attendance_total, COUNT(DISTINCT CASE WHEN a.status IN (\'Present\', \'Late\') THEN a.attendance_id END) AS attendance_attended, COUNT(DISTINCT CASE WHEN ta.outcome = \'pass\' THEN ta.assessment_id END) AS assessments_passed, COUNT(DISTINCT CASE WHEN ta.outcome = \'fail\' THEN ta.assessment_id END) AS assessments_failed')
            ->from('class_enrollments e')->join('trainees t', 't.trainee_id = e.trainee_id')
            ->left_join('attendance_records a', 'a.trainee_id = e.trainee_id AND a.class_id = e.class_id')
            ->left_join('training_assessments ta', 'ta.trainee_id = e.trainee_id AND ta.class_id = e.class_id')
            ->where('e.class_id', $classId)
            ->group_by(['t.trainee_id', 't.first_name', 't.last_name', 't.email'])
            ->order_by('t.last_name')->order_by('t.first_name')->get_all();
        foreach ($rows as &$row) $row['trainee_number'] = (string) $row['trainee_id'];
        unset($row);
        return $rows;
    }

    private function classAttendance(int $classId): array
    {
        return $this->db->select("a.attendance_date, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, a.status, a.time_in, a.time_out")
            ->from('attendance_records a')->join('trainees t', 't.trainee_id = a.trainee_id')
            ->where('a.class_id', $classId)->where('a.attendance_date', '>=', date('Y-m-d', strtotime('-6 days')))
            ->order_by('a.attendance_date', 'DESC')->order_by('t.last_name')->order_by('t.first_name')->get_all();
    }

    private function classAssessments(int $classId): array
    {
        return $this->db->select("CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, a.assessment_name, a.outcome, a.assessment_date, a.notes")
            ->from('training_assessments a')->join('trainees t', 't.trainee_id = a.trainee_id')
            ->where('a.class_id', $classId)->order_by('a.assessment_date', 'DESC')->order_by('trainee_name')->get_all();
    }

    private function classDocuments(int $classId): array
    {
        return $this->db->select("d.document_id, d.document_name, d.upload_date, d.review_status, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name")
            ->from('trainee_documents d')->left_join('trainees t', 't.trainee_id = d.trainee_id')
            ->where('d.class_id', $classId)->order_by('d.upload_date', 'DESC')->get_all();
    }

    private function classAnnouncements(int $classId): array
    {
        if (!$this->hasDatabaseColumn('announcements', 'class_id')) return [];
        return $this->db->select('a.announcement_id, a.title, a.content, a.created_at, a.status, u.full_name AS author')
            ->from('announcements a')->left_join('users u', 'u.user_id = a.user_id')
            ->where('a.class_id', $classId)->order_by('a.created_at', 'DESC')->get_all();
    }

    private function classQuickStats(int $classId): array
    {
        $attendance = $this->db->select("COALESCE(ROUND(SUM(CASE WHEN status IN ('Present', 'Late') THEN 1 ELSE 0 END) / NULLIF(COUNT(attendance_id), 0) * 100, 1), 0) AS attendance_rate")
            ->from('attendance_records')->where('class_id', $classId)->get();
        $passed = $this->db->from('training_assessments')->where(['class_id' => $classId, 'outcome' => 'pass'])->count();
        $requirements = $this->db->select('r.required_document_name')->from('certificate_document_requirements r')
            ->where('r.class_id', $classId)->get_all();
        $pending = 0;
        foreach ($requirements as $requirement) {
            $approved = $this->db->from('trainee_documents')->where([
                'class_id' => $classId,
                'document_name' => $requirement['required_document_name'],
                'review_status' => 'approved',
            ])->count();
            if (!$approved) $pending++;
        }
        return [
            'attendance_rate' => $attendance['attendance_rate'] ?? 0,
            'assessments_passed' => (int) $passed,
            'pending_documents' => $pending,
        ];
    }

    private function hasDatabaseColumn(string $table, string $column): bool
    {
        $database = (string) (getenv('DB_NAME') ?: 'lsi-smart-track');
        $result = $this->db->select('COUNT(*) AS total')->from('information_schema.COLUMNS')
            ->where(['TABLE_SCHEMA' => $database, 'TABLE_NAME' => $table, 'COLUMN_NAME' => $column])->get();
        return (int) ($result['total'] ?? 0) > 0;
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
                if (!$this->classById((int) $classId, $user)) {
                    $this->db->roll_back();
                    return ['success' => false, 'reason' => 'not_found'];
                }
                $this->db->from('training_classes')->where('class_id', (int) $classId)->update($this->classFields($data));
                $savedId = $classId;
                $action = 'Updated class';
            } else {
                $this->db->from('training_classes')->insert($this->classFields($data));
                $savedId = (int) $this->db->last_id();
                if ($savedId < 1) {
                    throw new RuntimeException('Class insert did not return a valid ID.');
                }
                $action = 'Created class';
            }

            $this->logClassActivity((int) $user['user_id'], $action, 'Class #' . $savedId);
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

    private function classFields(array $data): array
    {
        $fields = [];
        foreach (['class_name', 'program_id', 'instructor_id', 'start_date', 'end_date', 'status'] as $field) {
            if (array_key_exists($field, $data)) $fields[$field] = $data[$field];
        }
        return $fields;
    }

    private function logClassActivity(int $userId, string $action, string $details): void
    {
        $this->db->from('activity_logs')->insert([
            'user_id' => $userId,
            'action' => $action,
            'details' => $details,
        ]);
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

        if ($classId && !$this->classById($classId, $this->user)) {
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
        $class = $this->classById($classId, $this->user);

        if (!$class) {
            return $this->deny('That class is not available to your account.');
        }

        try {
            $this->classesModel->deleteClass($classId);
            $this->logClassActivity((int) $this->user['user_id'], 'Deleted class', 'Class #' . $classId);
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
        $class = $this->classById($classId, $this->user);

        if (!$class) {
            return $this->jsonResponse(['message' => 'That class is not available to your account.'], 404);
        }

        try {
            $this->classesModel->deleteClass($classId);
            $this->logClassActivity((int) $this->user['user_id'], 'Deleted class', 'Class #' . $classId);
            return $this->jsonResponse(['message' => 'Class deleted.']);
        } catch (Throwable $error) {
            return $this->jsonResponse(['message' => 'The class could not be deleted. It may have related records.'], 422);
        }
    }
}






