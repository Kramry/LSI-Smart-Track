<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'controllers/Base.php';

class Documents extends Base
{
    public function uploadDocument()
    {
        $this->requireRequestMethod('POST');
        if (!$this->requireUser() || !$this->validCsrf() || !$this->inRoleGroup('all_accounts')) {
            return $this->deny('You are not allowed to upload documents.');
        }
        $traineeId = (int) $this->input('trainee_id');
        $scope = $this->user['role'] === 'trainee' ? 'trainee' : $this->input('document_scope');
        if ($this->user['role'] === 'trainee') {
            $trainee = $this->db->from('trainees')->where('user_id', (int) $this->user['user_id'])->limit(1)->get();
            $traineeId = (int) ($trainee['trainee_id'] ?? 0);
        }
        if ($scope === 'institution') {
            $traineeId = 0;
        }
        $trainee = $traineeId ? $this->db->select('trainee_id')->from('trainees')->where('trainee_id', $traineeId)->limit(1)->get() : null;
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
            $instructorId = $this->user['role'] === 'instructor' ? (int) $this->user['user_id'] : null;
            if (!$classId || !$this->classForDocumentScope($classId, $instructorId)) {
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
        $this->db->from('trainee_documents')->insert([
            'trainee_id' => $traineeId ?: null,
            'class_id' => $classId,
            'uploaded_by' => (int) $this->user['user_id'],
            'document_name' => substr($label, 0, 150),
            'file_path' => $dbPath,
            'status' => 'active',
            'review_status' => $reviewStatus,
            'access_level' => $access,
            'upload_date' => date('Y-m-d'),
        ]);
        try {
            $this->recalculateEligibilityPair($traineeId, $classId);
        } catch (Throwable $error) {
            error_log('Document upload eligibility recalculation failed (' . get_class($error) . '): ' . $error->getMessage());
        }
        $this->model->logActivity((int) $this->user['user_id'], 'Uploaded document', substr($label, 0, 150) . ($useCloud ? ' (Cloud)' : ' (Local)'));
        $message = $useCloud
            ? 'Document uploaded to cloud storage.'
            : 'Document uploaded locally.' . ($cloudWarning ? ' ' . $cloudWarning : '');
        if ($this->user['role'] === 'trainee') {
            $message = 'Requirement submitted and pending staff review. ' . $message;
        }
        if ($this->apiRequest) {
            return $this->jsonResponse(['message' => $message]);
        }
        $this->notice($message);
        redirect($this->url('manage/documents'));
    }

    public function downloadDocument($id)
    {
        $this->requireRequestMethod('GET');
        if (!$this->requireUser() || !ctype_digit((string) $id)) {
            return $this->deny('The document could not be found.');
        }
        $document = $this->documentById((int) $id);
        $isAdmin = $this->inRoleGroup('administrative_staff');
        $isOwner = $document && $this->user['role'] === 'trainee' && $document['access_level'] !== 'staff' && (int) $document['trainee_user_id'] === (int) $this->user['user_id'];
        $isClassDocument = false;
        if ($document && $this->user['role'] === 'instructor' && !empty($document['trainee_id']) && $document['access_level'] !== 'staff' && !empty($document['class_id'])) {
            $isClassDocument = $this->classForDocumentScope((int) $document['class_id'], (int) $this->user['user_id']);
        } elseif ($document && $this->user['role'] === 'trainee' && $document['access_level'] === 'class' && !empty($document['class_id'])) {
            $isClassDocument = $this->traineeEnrolledInClass((int) $document['class_id'], (int) $this->user['user_id']);
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
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $document['document_name']) . '"');
        readfile($path);
        exit;
    }

    public function deleteDocument($id)
    {
        $this->requireRequestMethod($this->apiRequest ? 'DELETE' : 'POST');
        if (!$this->requireUser() || !$this->validCsrf() || !$this->inRoleGroup('administrative_staff')) {
            return $this->deny('You are not allowed to delete documents.');
        }

        $document = $this->documentById((int) $id);
        if (!$document) {
            return $this->actionError('Document not found.', 'manage/documents', 404);
        }

        $filePath = $document['file_path'];
        $isCloud = strpos($filePath, 'cloud://') === 0;

        try {
            $this->recordModel->transactional(function () use ($id, $document, $isCloud) {
                if (!$this->db->from('trainee_documents')->where('document_id', (int) $id)->delete()) {
                    throw new RuntimeException('Unable to delete the document record.');
                }
                try {
                    $this->recalculateEligibilityPair($document['trainee_id'] ?? 0, $document['class_id'] ?? 0);
                } catch (Throwable $error) {
                    error_log('Document deletion eligibility recalculation failed (' . get_class($error) . '): ' . $error->getMessage());
                }
                $this->model->logActivity((int) $this->user['user_id'], 'Deleted document', $document['document_name'] . ($isCloud ? ' (Cloud)' : ' (Local)'));
            });
        } catch (Throwable $error) {
            error_log('Unable to delete document record: ' . $error->getMessage());
            return $this->actionError('The document could not be deleted.', 'manage/documents', 500);
        }

        $fileCleanupFailed = false;
        if ($isCloud) {
            $this->call->library('CloudStorage');
            $cloudStorage = new CloudStorage();
            $remotePath = substr($filePath, 8);
            if (!$cloudStorage->delete($remotePath)) {
                error_log('Document row deleted but cloud object removal failed for document #' . (int) $id . '.');
                $fileCleanupFailed = true;
            }
        } else {
            $path = $this->privateDocumentPath($filePath);
            if ($path && file_exists($path) && !@unlink($path)) {
                error_log('Document row deleted but local file removal failed for document #' . (int) $id . '.');
                $fileCleanupFailed = true;
            }
        }
        if ($fileCleanupFailed) {
            return $this->actionError('The document record was deleted, but its file could not be removed.', 'manage/documents', 500);
        }
        if ($this->apiRequest) {
            return $this->jsonResponse(['message' => 'Document deleted.']);
        }
        $this->notice('Document deleted.');
        redirect($this->url('manage/documents'));
    }

    public function updateDocumentStatus($id)
    {
        $this->requireRequestMethod('POST');
        if (!$this->requireUser() || !$this->inRoleGroup('administrative_staff') || !$this->validCsrf()) {
            return $this->deny('You are not allowed to update document status.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            return $this->actionError('Document not found.', 'manage/documents', 404);
        }

        $status = $this->input('status');
        if (!is_string($status) || !in_array($status, ['active', 'inactive', 'archived'], true)) {
            return $this->actionError('Invalid status.', 'manage/documents');
        }

        $document = $this->documentById((int) $id);
        if (!$document) {
            return $this->actionError('Document not found.', 'manage/documents', 404);
        }

        $this->db->from('trainee_documents')->where('document_id', (int) $id)->update(['status' => $status]);
        try {
            $this->recalculateEligibilityPair($document['trainee_id'] ?? 0, $document['class_id'] ?? 0);
        } catch (Throwable $error) {
            error_log('Document status eligibility recalculation failed (' . get_class($error) . '): ' . $error->getMessage());
        }
        $this->model->logActivity((int) $this->user['user_id'], 'Updated document status', $document['document_name'] . ' -> ' . $status);
        if ($this->apiRequest) {
            return $this->jsonResponse(['message' => 'Document status updated.']);
        }
        $this->notice('Document status updated.');
        redirect($this->url('manage/documents'));
    }

    public function reviewDocument($id)
    {
        $this->requireRequestMethod('POST');
        if (!$this->requireUser() || (!$this->inRoleGroup('administrative_staff') && !$this->inRoleGroup('teaching_staff')) || !$this->validCsrf()) {
            return $this->deny('You are not allowed to review uploaded requirements.');
        }
        if (!ctype_digit((string) $id)) {
            return $this->actionError('Document not found.', 'manage/documents', 404);
        }

        $decision = (string) $this->input('decision');
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            return $this->actionError('Choose approve or reject for this document.', 'manage/documents');
        }

        $document = $this->documentById((int) $id);
        if (!$document || empty($document['trainee_id'])) {
            return $this->actionError('Trainee requirement not found.', 'manage/documents', 404);
        }
        if (($this->user['role'] ?? '') === 'instructor' && (
            empty($document['class_id'])
            || $document['access_level'] === 'staff'
            || !$this->classForDocumentScope((int) $document['class_id'], (int) $this->user['user_id'])
        )) {
            return $this->deny('You can only review trainee documents for your assigned classes.');
        }

        try {
            $this->db->from('trainee_documents')->where('document_id', (int) $id)->update([
                'review_status' => $decision,
                'reviewed_by' => (int) $this->user['user_id'],
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $error) {
            $message = strtolower($error->getMessage());
            $missingAuditColumn = strpos($message, 'unknown column') !== false
                && (strpos($message, 'reviewed_by') !== false || strpos($message, 'reviewed_at') !== false);
            if (!$missingAuditColumn) throw $error;
            error_log('Document review audit columns are unavailable; saving the review decision without audit metadata.');
            $this->db->from('trainee_documents')->where('document_id', (int) $id)->update(['review_status' => $decision]);
        }
        try {
            $this->recalculateEligibilityPair((int) $document['trainee_id'], (int) ($document['class_id'] ?? 0));
        } catch (Throwable $error) {
            error_log('Document review eligibility recalculation failed (' . get_class($error) . '): ' . $error->getMessage());
        }
        $this->model->logActivity((int) $this->user['user_id'], ucfirst($decision) . ' trainee document', $document['document_name']);
        if ($this->apiRequest) {
            return $this->jsonResponse(['message' => 'Document ' . $decision . '.']);
        }
        $this->notice('Document ' . $decision . '.');
        redirect($this->url('manage/documents'));
    }

    public function saveDocumentRequirement()
    {
        $this->requireRequestMethod('POST');
        if (!$this->requireUser() || !$this->validCsrf() || !$this->inRoleGroup('teaching_staff')) {
            return $this->deny('You are not allowed to configure required documents.');
        }

        $classId = (int) $this->input('class_id');
        $documentName = trim((string) $this->input('required_document_name'));
        $class = $this->db->select('class_id')->from('training_classes')->where('class_id', $classId)->limit(1)->get();
        if (!$class || $documentName === '' || mb_strlen($documentName) > 150) {
            return $this->actionError('Choose a class and enter a document name up to 150 characters.', 'manage/certificate-config');
        }

        try {
            $this->recordModel->transactional(function () use ($classId, $documentName) {
                $this->documentModel->addDocumentRequirement($classId, $documentName, (int) $this->user['user_id']);
                try {
                    $this->eligibilityModel->recalculateClassEligibility($classId);
                } catch (Throwable $error) {
                    error_log('Document requirement eligibility recalculation failed (' . get_class($error) . '): ' . $error->getMessage());
                }
                $this->model->logActivity((int) $this->user['user_id'], 'Added certificate document requirement', $documentName);
            });
        } catch (Throwable $error) {
            error_log('Unable to add certificate document requirement: ' . $error->getMessage());
            $isDuplicate = stripos($error->getMessage(), 'duplicate') !== false
                || stripos($error->getMessage(), 'unique') !== false;
            return $this->actionError(
                $isDuplicate ? 'That required document is already listed for this class.' : 'The required document could not be added.',
                'manage/certificate-config',
                422
            );
        }
        if ($this->apiRequest) {
            return $this->jsonResponse(['message' => 'Required document added.']);
        }
        $this->notice('Required document added.');
        redirect($this->url('manage/certificate-config'));
    }

    public function deleteDocumentRequirement($id)
    {
        $this->requireRequestMethod($this->apiRequest ? 'DELETE' : 'POST');
        if (!$this->requireUser() || !$this->validCsrf() || !$this->inRoleGroup('teaching_staff')) {
            return $this->deny('You are not allowed to configure required documents.');
        }
        $requirement = ctype_digit((string) $id)
            ? $this->documentModel->requirementClassById((int) $id)
            : null;
        if (!$requirement || !$this->documentModel->deleteDocumentRequirement((int) $id)) {
            if ($this->apiRequest) {
                return $this->jsonResponse(['message' => 'Required document not found.'], 404);
            }
            $this->notice('Required document not found.');
            return redirect($this->url('manage/certificate-config'));
        } else {
            try {
                $this->eligibilityModel->recalculateClassEligibility((int) $requirement['class_id']);
            } catch (Throwable $error) {
                error_log('Deleted document requirement eligibility recalculation failed (' . get_class($error) . '): ' . $error->getMessage());
            }
            $this->model->logActivity((int) $this->user['user_id'], 'Removed certificate document requirement', 'Requirement #' . (int) $id);
            if ($this->apiRequest) {
                return $this->jsonResponse(['message' => 'Required document removed.']);
            }
            $this->notice('Required document removed.');
            return redirect($this->url('manage/certificate-config'));
        }
    }

    private function documentById(int $id)
    {
        return $this->db->select('d.*, t.user_id AS trainee_user_id')->from('trainee_documents d')
            ->left_join('trainees t', 't.trainee_id = d.trainee_id')
            ->where('d.document_id', $id)->limit(1)->get() ?: null;
    }

    private function classForDocumentScope(int $classId, ?int $instructorId = null): bool
    {
        $query = $this->db->select('class_id')->from('training_classes')->where('class_id', $classId);
        if ($instructorId !== null) $query->where('instructor_id', $instructorId);
        return (bool) $query->limit(1)->get();
    }

    private function traineeEnrolledInClass(int $classId, int $userId): bool
    {
        return (bool) $this->db->select('e.enrollment_id')->from('class_enrollments e')
            ->join('trainees t', 't.trainee_id = e.trainee_id')
            ->where('e.class_id', $classId)->where('t.user_id', $userId)->limit(1)->get();
    }
}






