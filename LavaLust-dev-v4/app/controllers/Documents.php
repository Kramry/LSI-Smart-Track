<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'controllers/Base.php';

class Documents extends Base
{
    public function uploadDocument()
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff', 'instructor', 'trainee'], true)) {
            return $this->deny('You are not allowed to upload documents.');
        }
        $traineeId = (int) $this->input('trainee_id');
        $scope = $this->user['role'] === 'trainee' ? 'trainee' : $this->input('document_scope');
        if ($this->user['role'] === 'trainee') {
            $trainee = $this->userModel->traineeForUser($this->user['user_id']);
            $traineeId = (int) ($trainee['trainee_id'] ?? 0);
        }
        if ($scope === 'institution') {
            $traineeId = 0;
        }
        $trainee = $traineeId ? $this->model->row('SELECT trainee_id FROM trainees WHERE trainee_id = ?', [$traineeId]) : null;
        $file = $_FILES['document'] ?? null;
        if (($scope !== 'institution' && !$trainee) || !in_array($scope, ['trainee', 'institution'], true) || !$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 10 * 1024 * 1024) {
            return $this->actionError('Choose a valid document under 10 MB and a trainee record.', 'manage/documents');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'];
        if (!isset($allowed[$mime])) {
            return $this->actionError('Only PDF, JPG, PNG, and DOCX files are accepted.', 'manage/documents');
        }

        $requirement = null;
        if ($this->user['role'] === 'trainee') {
            $requirement = $this->documentModel->traineeRequirementById((int) $this->input('requirement_id'), (int) $this->user['user_id']);
            if (!$requirement) {
                return $this->actionError('Choose a required document for one of your enrolled classes.', 'manage/documents');
            }
            $classId = (int) $requirement['class_id'];
        }
        $label = $requirement
            ? $requirement['required_document_name']
            : (trim((string) $this->input('document_name')) ?: basename($file['name']));
        $access = $this->user['role'] === 'instructor' ? 'class' : (in_array($this->input('access_level'), ['private', 'class', 'staff'], true) ? $this->input('access_level') : 'private');
        if ($this->user['role'] !== 'trainee') {
            $classId = (int) $this->input('class_id');
        }
        if ($access === 'class') {
            $classSql = 'SELECT class_id FROM training_classes WHERE class_id = ?';
            $classValues = [$classId];
            if ($this->user['role'] === 'instructor') {
                $classSql .= ' AND instructor_id = ?';
                $classValues[] = (int) $this->user['user_id'];
            }
            if (!$classId || !$this->model->row($classSql, $classValues)) {
                return $this->actionError('Select a class you are permitted to share this document with.', 'manage/documents');
            }
        } elseif ($this->user['role'] !== 'trainee') {
            $classId = null;
        }
        if ($this->user['role'] === 'trainee') {
            $access = 'private';
        }

        // Cloud Storage or Local Storage
        $this->call->library('CloudStorage');
        $cloudStorage = new CloudStorage();
        $useCloud = $cloudStorage->isEnabled();
        $cloudRequested = getenv('CLOUD_STORAGE_ENABLED') === 'true';
        $cloudWarning = $cloudRequested && !$useCloud
            ? 'Cloud storage is enabled but its provider, AWS settings, or SDK are incomplete.'
            : null;

        $filename = bin2hex(random_bytes(20)) . '.' . $allowed[$mime];
        $remotePath = 'documents/' . date('Y/m') . '/' . $filename;
        $dbPath = $remotePath;

        if ($useCloud) {
            // Upload to cloud storage
            $cloudUploaded = $cloudStorage->upload($file['tmp_name'], $remotePath, $mime);
            if (!$cloudUploaded) {
                $cloudWarning = 'Cloud upload failed. The document was stored locally instead.';
                $useCloud = false;
            } else {
                $dbPath = 'cloud://' . $remotePath;
            }
        }

        if (!$useCloud) {
            // Fallback to local storage
            $directory = ROOT_DIR . 'runtime/uploads/private';
            if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                return $this->deny('The private upload directory is unavailable.');
            }
            $localFilename = bin2hex(random_bytes(20)) . '.' . $allowed[$mime];
            $destination = $directory . DIRECTORY_SEPARATOR . $localFilename;
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                return $this->actionError('The document could not be stored.', 'manage/documents', 500);
            }
            $dbPath = 'runtime/uploads/private/' . $localFilename;
        }

        $reviewStatus = $this->user['role'] === 'trainee' ? 'pending' : 'approved';
        $this->model->execute('INSERT INTO trainee_documents (trainee_id, class_id, uploaded_by, document_name, file_path, upload_date, status, review_status, access_level) VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?)', [$traineeId ?: null, $classId, (int) $this->user['user_id'], substr($label, 0, 150), $dbPath, 'active', $reviewStatus, $access]);
        $this->recalculateEligibilityPair($traineeId, $classId);
        $this->model->logActivity((int) $this->user['user_id'], 'Uploaded document', substr($label, 0, 150) . ($useCloud ? ' (Cloud)' : ' (Local)'));
        $message = $useCloud
            ? 'Document uploaded to cloud storage.'
            : 'Document uploaded locally.' . ($cloudWarning ? ' ' . $cloudWarning : '');
        if ($this->user['role'] === 'trainee') {
            $message = 'Requirement submitted and pending staff review. ' . $message;
        }
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => $message]);
        }
        $this->notice($message);
        redirect($this->url('manage/documents'));
    }

    public function downloadDocument($id)
    {
        if (!$this->requireUser() || !ctype_digit((string) $id)) {
            return $this->deny('The document could not be found.');
        }
        $document = $this->documentModel->documentById((int) $id);
        $isAdmin = in_array($this->user['role'], ['administrator', 'staff'], true);
        $isOwner = $document && $this->user['role'] === 'trainee' && $document['access_level'] !== 'staff' && (int) $document['trainee_user_id'] === (int) $this->user['user_id'];
        $isClassDocument = false;
        if ($document && in_array($this->user['role'], ['instructor', 'trainee'], true) && $document['access_level'] === 'class' && !empty($document['class_id'])) {
            if ($this->user['role'] === 'instructor') {
                $isClassDocument = (bool) $this->model->row('SELECT class_id FROM training_classes WHERE class_id = ? AND instructor_id = ?', [(int) $document['class_id'], (int) $this->user['user_id']]);
            } else {
                $isClassDocument = (bool) $this->model->row('SELECT e.enrollment_id FROM class_enrollments e JOIN trainees t ON t.trainee_id = e.trainee_id WHERE e.class_id = ? AND t.user_id = ?', [(int) $document['class_id'], (int) $this->user['user_id']]);
            }
        }
        if (!$document || (!$isAdmin && !$isOwner && !$isClassDocument)) {
            http_response_code(404);
            exit('Document not found.');
        }

        $filePath = $document['file_path'];
        $isCloud = strpos($filePath, 'cloud://') === 0;

        if ($isCloud) {
            // Cloud storage - generate temporary URL
            $this->call->library('CloudStorage');
            $cloudStorage = new CloudStorage();
            $remotePath = substr($filePath, 8); // Remove 'cloud://' prefix
            $tempUrl = $cloudStorage->getTemporaryUrl($remotePath, 3600);

            if ($tempUrl) {
                header('Location: ' . $tempUrl);
                exit;
            } else {
                http_response_code(500);
                exit('Could not generate download URL.');
            }
        }

        // Local storage
        $path = $this->privateDocumentPath($filePath);
        if (!$path) {
            http_response_code(404);
            exit('Document not found.');
        }
        header('Content-Type: ' . (mime_content_type($path) ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $document['document_name']) . '"');
        readfile($path);
        exit;
    }

    public function deleteDocument($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to delete documents.');
        }

        $document = $this->documentModel->documentById((int) $id);
        if (!$document) {
            return $this->actionError('Document not found.', 'manage/documents', 404);
        }

        $filePath = $document['file_path'];
        $isCloud = strpos($filePath, 'cloud://') === 0;

        if ($isCloud) {
            // Delete from cloud storage
            $this->call->library('CloudStorage');
            $cloudStorage = new CloudStorage();
            $remotePath = substr($filePath, 8); // Remove 'cloud://' prefix
            if (!$cloudStorage->delete($remotePath)) {
                return $this->actionError('The cloud document could not be deleted. No database records were removed.', 'manage/documents', 500);
            }
        } else {
            // Delete from local storage
            $path = $this->privateDocumentPath($filePath);
            if ($path && file_exists($path)) {
                unlink($path);
            }
        }

        $this->model->execute('DELETE FROM trainee_documents WHERE document_id = ?', [(int) $id]);
        $this->recalculateEligibilityPair($document['trainee_id'] ?? 0, $document['class_id'] ?? 0);
        $this->model->logActivity((int) $this->user['user_id'], 'Deleted document', $document['document_name'] . ($isCloud ? ' (Cloud)' : ' (Local)'));
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => 'Document deleted.']);
        }
        $this->notice('Document deleted.');
        redirect($this->url('manage/documents'));
    }

    public function updateDocumentStatus($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to update document status.');
        }

        $status = $this->input('status');
        if (!in_array($status, ['active', 'inactive', 'archived'], true)) {
            return $this->actionError('Invalid status.', 'manage/documents');
        }

        $document = $this->documentModel->documentById((int) $id);
        if (!$document) {
            return $this->actionError('Document not found.', 'manage/documents', 404);
        }

        $this->model->execute('UPDATE trainee_documents SET status = ? WHERE document_id = ?', [$status, (int) $id]);
        $this->recalculateEligibilityPair($document['trainee_id'] ?? 0, $document['class_id'] ?? 0);
        $this->model->logActivity((int) $this->user['user_id'], 'Updated document status', $document['document_name'] . ' -> ' . $status);
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => 'Document status updated.']);
        }
        $this->notice('Document status updated.');
        redirect($this->url('manage/documents'));
    }

    public function reviewDocument($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !in_array($this->user['role'], ['administrator', 'staff'], true)) {
            return $this->deny('You are not allowed to review uploaded requirements.');
        }
        if (!ctype_digit((string) $id)) {
            return $this->actionError('Document not found.', 'manage/documents', 404);
        }

        $decision = (string) $this->input('decision');
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            return $this->actionError('Choose approve or reject for this document.', 'manage/documents');
        }

        $document = $this->documentModel->documentById((int) $id);
        if (!$document || empty($document['trainee_id'])) {
            return $this->actionError('Trainee requirement not found.', 'manage/documents', 404);
        }

        $this->model->execute('UPDATE trainee_documents SET review_status = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE document_id = ?', [$decision, (int) $this->user['user_id'], (int) $id]);
        $this->recalculateEligibilityPair((int) $document['trainee_id'], (int) ($document['class_id'] ?? 0));
        $this->model->logActivity((int) $this->user['user_id'], ucfirst($decision) . ' trainee document', $document['document_name']);
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => 'Document ' . $decision . '.']);
        }
        $this->notice('Document ' . $decision . '.');
        redirect($this->url('manage/documents'));
    }

    public function saveDocumentRequirement()
    {
        if (!$this->requireUser() || !$this->validCsrf() || $this->user['role'] !== 'administrator') {
            return $this->deny('Only administrators can configure required documents.');
        }

        $classId = (int) $this->input('class_id');
        $documentName = trim((string) $this->input('required_document_name'));
        $class = $this->model->row('SELECT class_id FROM training_classes WHERE class_id = ?', [$classId]);
        if (!$class || $documentName === '' || strlen($documentName) > 150) {
            return $this->actionError('Choose a class and enter a document name up to 150 characters.', 'manage/certificate-config');
        }

        try {
            $this->documentModel->addDocumentRequirement($classId, $documentName, (int) $this->user['user_id']);
        } catch (Throwable $error) {
            error_log('Unable to add certificate document requirement: ' . $error->getMessage());
            return $this->actionError('That required document is already listed for this class.', 'manage/certificate-config');
        }
        $this->eligibilityModel->recalculateClassEligibility($classId);
        $this->model->logActivity((int) $this->user['user_id'], 'Added certificate document requirement', $documentName);
        if ($this->apiRequest) {
            $this->jsonResponse(['message' => 'Required document added.']);
        }
        $this->notice('Required document added.');
        redirect($this->url('manage/certificate-config'));
    }

    public function deleteDocumentRequirement($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || $this->user['role'] !== 'administrator') {
            return $this->deny('Only administrators can configure required documents.');
        }
        $requirement = ctype_digit((string) $id)
            ? $this->documentModel->row('SELECT class_id FROM certificate_document_requirements WHERE requirement_id = ?', [(int) $id])
            : null;
        if (!$requirement || !$this->documentModel->deleteDocumentRequirement((int) $id)) {
            if ($this->apiRequest) {
                $this->jsonResponse(['message' => 'Required document not found.'], 404);
            }
            $this->notice('Required document not found.');
        } else {
            $this->eligibilityModel->recalculateClassEligibility((int) $requirement['class_id']);
            $this->model->logActivity((int) $this->user['user_id'], 'Removed certificate document requirement', 'Requirement #' . (int) $id);
            if ($this->apiRequest) {
                $this->jsonResponse(['message' => 'Required document removed.']);
            }
            $this->notice('Required document removed.');
        }
        redirect($this->url('manage/certificate-config'));
    }
}
