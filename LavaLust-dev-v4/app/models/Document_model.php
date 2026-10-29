<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';

class Document_model extends Base_model
{
    public function documentById($id)
    {
        return $this->row('SELECT d.*, t.user_id AS trainee_user_id FROM trainee_documents d LEFT JOIN trainees t ON t.trainee_id = d.trainee_id WHERE d.document_id = ? LIMIT 1', [(int) $id]);
    }

    public function documentStats(array $user)
    {
        $isAdminOrStaff = in_array($user['role'], ['administrator', 'staff'], true);

        if ($isAdminOrStaff) {
            return [
                'total' => (int) ($this->row("SELECT COUNT(*) AS total FROM trainee_documents")['total'] ?? 0),
                'active' => (int) ($this->row("SELECT COUNT(*) AS total FROM trainee_documents WHERE status = 'active'")['total'] ?? 0),
                'archived' => (int) ($this->row("SELECT COUNT(*) AS total FROM trainee_documents WHERE status = 'archived'")['total'] ?? 0),
                'pending_review' => (int) ($this->row("SELECT COUNT(*) AS total FROM trainee_documents WHERE trainee_id IS NOT NULL AND review_status = 'pending'")['total'] ?? 0),
                'private' => (int) ($this->row("SELECT COUNT(*) AS total FROM trainee_documents WHERE access_level = 'private'")['total'] ?? 0),
                'class' => (int) ($this->row("SELECT COUNT(*) AS total FROM trainee_documents WHERE access_level = 'class'")['total'] ?? 0),
                'staff' => (int) ($this->row("SELECT COUNT(*) AS total FROM trainee_documents WHERE access_level = 'staff'")['total'] ?? 0),
            ];
        }

        if ($user['role'] === 'instructor') {
            return [
                'total' => (int) ($this->row("SELECT COUNT(*) AS total FROM trainee_documents d JOIN training_classes c ON c.class_id = d.class_id WHERE c.instructor_id = ?", [(int) $user['user_id']])['total'] ?? 0),
                'active' => (int) ($this->row("SELECT COUNT(*) AS total FROM trainee_documents d JOIN training_classes c ON c.class_id = d.class_id WHERE c.instructor_id = ? AND d.status = 'active'", [(int) $user['user_id']])['total'] ?? 0),
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
            $whereClauses[] = 'd.access_level = "class"';
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

    public function traineeRequirementById($requirementId, $userId)
    {
        return $this->row('SELECT r.requirement_id, r.class_id, r.required_document_name FROM certificate_document_requirements r JOIN class_enrollments e ON e.class_id = r.class_id JOIN trainees t ON t.trainee_id = e.trainee_id WHERE r.requirement_id = ? AND t.user_id = ? LIMIT 1', [(int) $requirementId, (int) $userId]);
    }

    public function addDocumentRequirement($classId, $documentName, $createdBy)
    {
        $this->execute('INSERT INTO certificate_document_requirements (class_id, required_document_name, created_by) VALUES (?, ?, ?)', [
            (int) $classId,
            $documentName,
            (int) $createdBy,
        ]);
        $requirement = $this->row('SELECT requirement_id FROM certificate_document_requirements WHERE class_id = ? AND required_document_name = ?', [(int) $classId, $documentName]);
        return (int) ($requirement['requirement_id'] ?? 0);
    }

    public function deleteDocumentRequirement($requirementId)
    {
        return $this->execute('DELETE FROM certificate_document_requirements WHERE requirement_id = ?', [(int) $requirementId]);
    }
}
