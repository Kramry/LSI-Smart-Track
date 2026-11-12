<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';

class Document_model extends Base_model
{
    public function traineeById($traineeId)
    {
        return $this->db->table('trainees')
            ->select('trainee_id')
            ->where('trainee_id', (int) $traineeId)
            ->get() ?: null;
    }

    public function classForDocumentScope($classId, $instructorId = null)
    {
        $query = $this->db->table('training_classes')
            ->select('class_id')
            ->where('class_id', (int) $classId);
        if ($instructorId !== null) {
            $query->where('instructor_id', (int) $instructorId);
        }
        return $query->get() ?: null;
    }

    public function instructorOwnsClass($classId, $instructorId)
    {
        return (bool) $this->classForDocumentScope($classId, $instructorId);
    }

    public function traineeEnrolledInClass($classId, $userId)
    {
        return (bool) $this->db->table('class_enrollments e')
            ->select('e.enrollment_id')
            ->join('trainees t', 't.trainee_id = e.trainee_id')
            ->where('e.class_id', (int) $classId)
            ->where('t.user_id', (int) $userId)
            ->get();
    }

    public function createUploadedDocument(array $document)
    {
        return $this->execute(
            'INSERT INTO trainee_documents (trainee_id, class_id, uploaded_by, document_name, file_path, upload_date, status, review_status, access_level) VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?)',
            [
                $document['trainee_id'],
                $document['class_id'],
                (int) $document['uploaded_by'],
                $document['document_name'],
                $document['file_path'],
                $document['status'],
                $document['review_status'],
                $document['access_level'],
            ]
        );
    }

    public function deleteDocument($documentId)
    {
        return $this->db->table('trainee_documents')
            ->where('document_id', (int) $documentId)
            ->delete();
    }

    public function updateDocumentStatus($documentId, $status)
    {
        return $this->db->table('trainee_documents')
            ->where('document_id', (int) $documentId)
            ->update(['status' => $status]);
    }

    public function reviewDocument($documentId, $decision, $reviewedBy)
    {
        try {
            return $this->execute(
                'UPDATE trainee_documents SET review_status = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE document_id = ?',
                [$decision, (int) $reviewedBy, (int) $documentId]
            );
        } catch (Throwable $error) {
            $message = strtolower($error->getMessage());
            $missingAuditColumn = strpos($message, 'unknown column') !== false
                && (strpos($message, 'reviewed_by') !== false || strpos($message, 'reviewed_at') !== false);
            if (!$missingAuditColumn) {
                throw $error;
            }

            error_log('Document review audit columns are unavailable; saving the review decision without audit metadata.');
            return $this->execute(
                'UPDATE trainee_documents SET review_status = ? WHERE document_id = ?',
                [$decision, (int) $documentId]
            );
        }
    }

    public function classById($classId)
    {
        return $this->db->table('training_classes')
            ->select('class_id')
            ->where('class_id', (int) $classId)
            ->get() ?: null;
    }

    public function documentById($id)
    {
        return $this->db->table('trainee_documents d')
            ->select('d.*, t.user_id AS trainee_user_id')
            ->left_join('trainees t', 't.trainee_id = d.trainee_id')
            ->where('d.document_id', (int) $id)
            ->limit(1)
            ->get() ?: null;
    }

    public function documentStats(array $user)
    {
        $isAdminOrStaff = in_array($user['role'], ['administrator', 'staff'], true);

        if ($isAdminOrStaff) {
            return [
                'total' => (int) $this->db->table('trainee_documents')->count(),
                'active' => (int) $this->db->table('trainee_documents')->where('status', 'active')->count(),
                'archived' => (int) $this->db->table('trainee_documents')->where('status', 'archived')->count(),
                'pending_review' => (int) $this->db->table('trainee_documents')->where_not_null('trainee_id')->where('review_status', 'pending')->count(),
                'private' => (int) $this->db->table('trainee_documents')->where('access_level', 'private')->count(),
                'class' => (int) $this->db->table('trainee_documents')->where('access_level', 'class')->count(),
                'staff' => (int) $this->db->table('trainee_documents')->where('access_level', 'staff')->count(),
            ];
        }

        if ($user['role'] === 'instructor') {
            return [
                'total' => (int) ($this->db->table('trainee_documents d')
                    ->select_count('d.document_id', 'total')
                    ->join('training_classes c', 'c.class_id = d.class_id')
                    ->where('c.instructor_id', (int) $user['user_id'])
                    ->get()['total'] ?? 0),
                'active' => (int) ($this->db->table('trainee_documents d')
                    ->select_count('d.document_id', 'total')
                    ->join('training_classes c', 'c.class_id = d.class_id')
                    ->where('c.instructor_id', (int) $user['user_id'])
                    ->where('d.status', 'active')
                    ->get()['total'] ?? 0),
            ];
        }

        return ['total' => 0, 'active' => 0];
    }

    public function recentDocuments(array $user, $limit = 10)
    {
        $sql = 'SELECT d.*, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, c.class_name, u.full_name AS uploader_name FROM trainee_documents d LEFT JOIN trainees t ON t.trainee_id = d.trainee_id LEFT JOIN training_classes c ON c.class_id = d.class_id LEFT JOIN users u ON u.user_id = d.uploaded_by';
        $values = [];

        if ($user['role'] === 'trainee') {
            $sql .= ' WHERE t.user_id = ?';
            $values[] = (int) $user['user_id'];
        } elseif ($user['role'] === 'instructor') {
            $sql .= ' WHERE d.access_level = "class" AND c.instructor_id = ?';
            $values[] = (int) $user['user_id'];
        }

        return $this->rows($sql . ' ORDER BY d.upload_date DESC LIMIT ' . (int) $limit, $values);
    }

    public function filterDocuments(array $user, array $filters = [])
    {
        $sql = 'SELECT d.*, CONCAT(t.first_name, " ", t.last_name) AS trainee_name, c.class_name, u.full_name AS uploader_name FROM trainee_documents d LEFT JOIN trainees t ON t.trainee_id = d.trainee_id LEFT JOIN training_classes c ON c.class_id = d.class_id LEFT JOIN users u ON u.user_id = d.uploaded_by';
        $values = [];
        $whereClauses = [];

        // Role-based filtering
        if ($user['role'] === 'trainee') {
            $whereClauses[] = "d.access_level <> 'staff' AND (t.user_id = ? OR (d.access_level = 'class' AND d.class_id IN (SELECT e.class_id FROM class_enrollments e JOIN trainees mine ON mine.trainee_id = e.trainee_id WHERE mine.user_id = ?)))";
            $values[] = (int) $user['user_id'];
            $values[] = (int) $user['user_id'];
        } elseif ($user['role'] === 'instructor') {
            $whereClauses[] = "d.trainee_id IS NOT NULL AND d.access_level <> 'staff'";
            $whereClauses[] = 'c.instructor_id = ?';
            $values[] = (int) $user['user_id'];
        }

        // Search by document name or trainee name
        if (!empty($filters['search'])) {
            $whereClauses[] = '(d.document_name LIKE ? OR CONCAT(t.first_name, " ", t.last_name) LIKE ?)';
            $searchTerm = '%' . $filters['search'] . '%';
            $values[] = $searchTerm;
            $values[] = $searchTerm;
        }

        // Filter by document type (file extension)
        if (!empty($filters['document_type'])) {
            $whereClauses[] = 'd.file_path LIKE ?';
            $values[] = '%' . $filters['document_type'];
        }

        // Filter by class
        if (!empty($filters['class_id'])) {
            $whereClauses[] = 'd.class_id = ?';
            $values[] = (int) $filters['class_id'];
        }

        // Filter by access level
        if (!empty($filters['access_level'])) {
            $whereClauses[] = 'd.access_level = ?';
            $values[] = $filters['access_level'];
        }

        // Filter by status
        if (!empty($filters['status'])) {
            $whereClauses[] = 'd.status = ?';
            $values[] = $filters['status'];
        }

        if (!empty($filters['review_status'])) {
            $whereClauses[] = 'd.review_status = ?';
            $values[] = $filters['review_status'];
        }

        // Filter by upload date range
        if (!empty($filters['date_from'])) {
            $whereClauses[] = 'd.upload_date >= ?';
            $values[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $whereClauses[] = 'd.upload_date <= ?';
            $values[] = $filters['date_to'];
        }

        // Add WHERE clauses
        if (!empty($whereClauses)) {
            $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
        }

        // Sorting
        $sort = $filters['sort'] ?? 'newest';
        $sql .= ' ORDER BY d.upload_date ' . ($sort === 'oldest' ? 'ASC' : 'DESC');

        return $this->rows($sql, $values);
    }

    public function documentRequirements()
    {
        return $this->rows('SELECT r.*, c.class_name FROM certificate_document_requirements r JOIN training_classes c ON c.class_id = r.class_id ORDER BY c.class_name, r.required_document_name');
    }

    public function requirementsForTrainee($userId)
    {
        return $this->rows('SELECT r.requirement_id, r.class_id, r.required_document_name, c.class_name FROM certificate_document_requirements r JOIN training_classes c ON c.class_id = r.class_id JOIN class_enrollments e ON e.class_id = c.class_id JOIN trainees t ON t.trainee_id = e.trainee_id WHERE t.user_id = ? ORDER BY c.class_name, r.required_document_name', [(int) $userId]);
    }

    public function classesForTrainee($userId)
    {
        return $this->rows('SELECT DISTINCT c.class_id, c.class_name, c.program_id, p.program_name FROM training_classes c JOIN training_programs p ON p.program_id = c.program_id JOIN class_enrollments e ON e.class_id = c.class_id JOIN trainees t ON t.trainee_id = e.trainee_id WHERE t.user_id = ? ORDER BY p.program_name, c.class_name', [(int) $userId]);
    }

    public function traineeRequirementById($requirementId, $userId)
    {
        return $this->db->table('certificate_document_requirements r')
            ->select('r.requirement_id, r.class_id, r.required_document_name')
            ->join('class_enrollments e', 'e.class_id = r.class_id')
            ->join('trainees t', 't.trainee_id = e.trainee_id')
            ->where('r.requirement_id', (int) $requirementId)
            ->where('t.user_id', (int) $userId)
            ->limit(1)
            ->get() ?: null;
    }

    public function requirementClassById($requirementId)
    {
        return $this->db->table('certificate_document_requirements')
            ->select('class_id')
            ->where('requirement_id', (int) $requirementId)
            ->get() ?: null;
    }

    public function addDocumentRequirement($classId, $documentName, $createdBy)
    {
        $inserted = $this->db->table('certificate_document_requirements')->insert([
            'class_id' => (int) $classId,
            'required_document_name' => $documentName,
            'created_by' => (int) $createdBy,
        ]);
        if (!$inserted) {
            $existingRequirement = $this->db->table('certificate_document_requirements')
                ->select('requirement_id')
                ->where('class_id', (int) $classId)
                ->where('required_document_name', $documentName)
                ->limit(1)
                ->get();
            if ($existingRequirement) {
                throw new RuntimeException('Duplicate document requirement.');
            }
            throw new RuntimeException('Unable to add the document requirement.');
        }
        $requirement = $this->db->table('certificate_document_requirements')
            ->select('requirement_id')
            ->where('class_id', (int) $classId)
            ->where('required_document_name', $documentName)
            ->get();
        return (int) ($requirement['requirement_id'] ?? 0);
    }

    public function deleteDocumentRequirement($requirementId)
    {
        return $this->db->table('certificate_document_requirements')
            ->where('requirement_id', (int) $requirementId)
            ->delete();
    }
}
