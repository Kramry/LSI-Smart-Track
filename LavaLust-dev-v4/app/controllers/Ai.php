<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'controllers/Base.php';
require_once APP_DIR . 'models/AiKnowledge_model.php';
require_once APP_DIR . 'libraries/GeminiKnowledgeService.php';

class Ai extends Base
{
    private $knowledge;
    private $gemini;

    public function __construct()
    {
        parent::__construct();
        $this->knowledge = new AiKnowledge_model($this->db);
        $this->gemini = new GeminiKnowledgeService();
    }

    public function manage()
    {
        $this->requireRequestMethod('GET');
        if (!$this->requireUser() || ($this->user['role'] ?? '') !== 'administrator') return $this->deny('AI knowledge management is restricted to administrators.');
        $query = $this->queryParams();
        $this->render('ai_management', [
            'title' => 'AI Management', 'user' => $this->user, 'navigation' => $this->navigationForUser(),
            'app_url' => $this->url(''), 'csrf' => $this->session->userdata('csrf'),
            'categories' => $this->knowledge->categories(), 'documents' => $this->knowledge->documents($query['search'] ?? ''),
        ]);
    }

    public function categories()
    {
        $this->requireRequestMethod('GET');
        if (!$this->requireApiUser()) return;
        return $this->jsonResponse(['categories' => $this->knowledge->categories()]);
    }

    public function documents()
    {
        $this->requireRequestMethod('GET');
        if (!$this->requireAdminApiUser()) return;
        return $this->jsonResponse(['documents' => $this->knowledge->documents($this->queryParams()['search'] ?? '')]);
    }

    public function upload()
    {
        $this->requireRequestMethod('POST');
        if (!$this->requireAdminApiUser(true)) return;
        $file = $_FILES['document'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            return $this->jsonResponse(['message' => 'Choose a PDF, DOCX, or TXT document to upload.'], 422);
        }
        $filename = basename((string) $file['name']);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'docx', 'txt'], true) || (int) $file['size'] > 15 * 1024 * 1024) {
            return $this->jsonResponse(['message' => 'Only PDF, DOCX, and TXT files up to 15 MB are allowed.'], 422);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $validMime = ['pdf' => ['application/pdf'], 'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'], 'txt' => ['text/plain', 'application/octet-stream']];
        if (!in_array($mime, $validMime[$ext], true)) return $this->jsonResponse(['message' => 'The file content does not match its file type.'], 422);
        $formData = $this->requestData();
        foreach (['approved_for_ai', 'title', 'category_id', 'audience', 'version'] as $field) {
            $postedValue = $this->request->post($field);
            if ($postedValue !== null) {
                $formData[$field] = $postedValue;
            }
        }
        if (($formData['approved_for_ai'] ?? '') !== '1') return $this->jsonResponse(['message' => 'Confirm that the document is approved for AI use and contains no unnecessary personal information.'], 422);
        $title = trim((string) ($formData['title'] ?? pathinfo($filename, PATHINFO_FILENAME)));
        $categoryId = (int) ($formData['category_id'] ?? 0);
        $audience = (string) ($formData['audience'] ?? 'public');
        if ($title === '' || mb_strlen($title) > 180 || !in_array($audience, ['public', 'teaching', 'staff', 'admin'], true)) return $this->jsonResponse(['message' => 'Enter a valid title and audience.'], 422);
        $categoryIds = array_map(static function ($item) { return (int) $item['category_id']; }, $this->knowledge->categories());
        if (!in_array($categoryId, $categoryIds, true)) return $this->jsonResponse(['message' => 'Choose a valid document category.'], 422);

        $dir = ROOT_DIR . 'runtime/uploads/ai-knowledge';
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) return $this->jsonResponse(['message' => 'Private document storage is unavailable.'], 503);
        $storedName = bin2hex(random_bytes(24)) . '.' . $ext;
        $storedPath = $dir . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $storedPath)) return $this->jsonResponse(['message' => 'The document could not be stored securely.'], 500);
        require_once APP_DIR . 'libraries/CloudStorage.php';
        $cloudStorage = new CloudStorage();
        $remotePath = 'ai-knowledge/' . $storedName;
        $persistentPath = 'runtime/uploads/ai-knowledge/' . $storedName;
        if ($cloudStorage->isEnabled()) {
            if (!$cloudStorage->upload($storedPath, $remotePath, $mime)) {
                unlink($storedPath);
                return $this->jsonResponse(['message' => 'The document could not be saved to private cloud storage.'], 503);
            }
            $persistentPath = 'cloud://' . $remotePath;
        }
        $documentId = 0;
        try {
            $documentId = $this->knowledge->createDocument([
                'category_id' => $categoryId, 'title' => $title, 'original_filename' => $filename,
                'stored_path' => $persistentPath, 'mime_type' => $mime,
                'file_size' => (int) $file['size'], 'sha256' => hash_file('sha256', $storedPath),
                'audience' => $audience, 'status' => 'inactive', 'processing_status' => 'processing',
                'version' => max(1, (int) ($formData['version'] ?? 1)), 'uploaded_by' => (int) $this->user['user_id'], 'approved_by' => (int) $this->user['user_id'],
            ]);
            $indexPath = $storedPath;
            $indexMime = $mime;
            $indexName = $filename;
            $tempPath = null;
            if ($ext === 'docx') {
                $text = $this->extractDocxText($storedPath);
                $tempPath = tempnam(sys_get_temp_dir(), 'lsi-ai-');
                file_put_contents($tempPath, $text);
                $indexPath = $tempPath; $indexMime = 'text/plain'; $indexName = pathinfo($filename, PATHINFO_FILENAME) . '.txt';
            }
            $store = $this->knowledge->storeForAudience($audience);
            if (!$store) {
                $storeName = $this->gemini->createStore('LSI SmartTrack ' . ucfirst($audience) . ' Knowledge');
                $this->knowledge->saveStore($audience, $storeName);
            } else $storeName = $store['store_name'];
            $remoteName = $this->gemini->uploadToStore($storeName, $indexPath, $indexMime, $indexName);
            if ($tempPath && is_file($tempPath)) unlink($tempPath);
            $this->knowledge->linkDocument($documentId, $audience, $remoteName);
            $this->knowledge->updateProcessing($documentId, 'ready');
            $this->knowledge->setStatus($documentId, 'active');
            if (strpos($persistentPath, 'cloud://') === 0 && is_file($storedPath)) unlink($storedPath);
            $this->knowledge->logActivity((int) $this->user['user_id'], 'ai_document_uploaded', 'AI knowledge document ' . $documentId . ' (' . $audience . ') indexed');
            return $this->jsonResponse(['document_id' => $documentId, 'status' => 'ready']);
        } catch (Throwable $error) {
            if (isset($tempPath) && $tempPath && is_file($tempPath)) unlink($tempPath);
            if ($documentId) $this->knowledge->updateProcessing($documentId, 'failed', substr($error->getMessage(), 0, 500));
            error_log('AI document indexing failed: ' . get_class($error) . ': ' . $error->getMessage());
            return $this->jsonResponse(['message' => 'The document could not be indexed. Check Gemini configuration and try again.'], 502);
        }
    }

    public function status($id)
    {
        $this->requireRequestMethod('POST');
        if (!$this->requireAdminApiUser(true)) return;
        $doc = $this->knowledge->findDocument((int) $id);
        if (!$doc) return $this->jsonResponse(['message' => 'Document not found.'], 404);
        $status = (string) ($this->requestData()['status'] ?? '');
        if (!in_array($status, ['active', 'inactive'], true) || ($status === 'active' && $doc['processing_status'] !== 'ready')) return $this->jsonResponse(['message' => 'Only successfully indexed documents can be activated.'], 422);
        if ($status === 'inactive') {
            try {
                foreach ($this->knowledge->documentRefs((int) $id) as $ref) $this->gemini->deleteRemoteDocument($ref['remote_document_name']);
            } catch (Throwable $error) {
                error_log('Gemini document deactivation failed: ' . $error->getMessage());
                return $this->jsonResponse(['message' => 'Gemini could not deactivate this document.'], 502);
            }
            $this->knowledge->deleteRefs((int) $id);
        } else {
            $rateLimit = $this->knowledge->consumeChatRequest((int) $this->user['user_id']);
            if (!$rateLimit['allowed']) return $this->jsonResponse(['message' => 'AI indexing limit reached. Please wait before retrying.'], 429);
            try {
                $path = $this->materializeKnowledgeFile($doc['stored_path']);
                if (!$path) throw new RuntimeException('Stored document is unavailable.');
                $indexPath = $path; $indexMime = $doc['mime_type']; $indexName = $doc['original_filename']; $tempPath = null;
                if (strtolower(pathinfo($doc['original_filename'], PATHINFO_EXTENSION)) === 'docx') {
                    $tempPath = tempnam(sys_get_temp_dir(), 'lsi-ai-');
                    file_put_contents($tempPath, $this->extractDocxText($path));
                    $indexPath = $tempPath; $indexMime = 'text/plain'; $indexName = pathinfo($doc['original_filename'], PATHINFO_FILENAME) . '.txt';
                }
                $store = $this->knowledge->storeForAudience($doc['audience']);
                if (!$store) {
                    $storeName = $this->gemini->createStore('LSI SmartTrack ' . ucfirst($doc['audience']) . ' Knowledge');
                    $this->knowledge->saveStore($doc['audience'], $storeName);
                } else $storeName = $store['store_name'];
                $remoteName = $this->gemini->uploadToStore($storeName, $indexPath, $indexMime, $indexName);
                if ($tempPath && is_file($tempPath)) unlink($tempPath);
                if (strpos($doc['stored_path'], 'cloud://') === 0 && is_file($path)) unlink($path);
                $this->knowledge->linkDocument((int) $id, $doc['audience'], $remoteName);
            } catch (Throwable $error) {
                if (isset($tempPath) && $tempPath && is_file($tempPath)) unlink($tempPath);
                if (isset($path) && $path && strpos($doc['stored_path'], 'cloud://') === 0 && is_file($path)) unlink($path);
                $this->knowledge->updateProcessing((int) $id, 'failed', substr($error->getMessage(), 0, 500));
                return $this->jsonResponse(['message' => 'Gemini could not reindex this document.'], 502);
            }
        }
        $this->knowledge->setStatus((int) $id, $status);
        $this->knowledge->logActivity((int) $this->user['user_id'], 'ai_document_' . $status, 'AI knowledge document ' . (int) $id);
        return $this->jsonResponse(['document_id' => (int) $id, 'status' => $status]);
    }

    public function delete($id)
    {
        $this->requireRequestMethod($this->apiRequest ? 'DELETE' : 'POST');
        if (!$this->requireAdminApiUser(true)) return;
        $doc = $this->knowledge->findDocument((int) $id);
        if (!$doc) return $this->jsonResponse(['message' => 'Document not found.'], 404);
        try {
            foreach ($this->knowledge->documentRefs((int) $id) as $ref) $this->gemini->deleteRemoteDocument($ref['remote_document_name']);
        } catch (Throwable $error) {
            error_log('AI remote document removal failed: ' . $error->getMessage());
            return $this->jsonResponse(['message' => 'Gemini could not remove this document. It remains in the library.'], 502);
        }
        if (strpos($doc['stored_path'], 'cloud://') === 0) {
            require_once APP_DIR . 'libraries/CloudStorage.php';
            $storage = new CloudStorage();
            if (!$storage->delete(substr($doc['stored_path'], 8))) return $this->jsonResponse(['message' => 'The source file could not be deleted from cloud storage.'], 502);
            $path = null;
        } else $path = $this->privateDocumentPath($doc['stored_path']);
        $this->knowledge->deleteRefs((int) $id);
        $this->knowledge->deleteDocument((int) $id);
        if ($path && is_file($path)) unlink($path);
        $this->knowledge->logActivity((int) $this->user['user_id'], 'ai_document_deleted', 'AI knowledge document ' . (int) $id);
        return $this->jsonResponse(['document_id' => (int) $id, 'deleted' => true]);
    }

    public function chat()
    {
        $this->requireRequestMethod('POST');
        if (!$this->requireApiUser(true)) return;
        $data = $this->requestData();
        $question = trim((string) ($data['message'] ?? ''));
        $conversationId = (int) ($data['conversation_id'] ?? 0);
        if ($question === '' || mb_strlen($question) > 4000) return $this->jsonResponse(['message' => 'Enter a question up to 4,000 characters.'], 422);
        $userId = (int) $this->user['user_id'];
        $role = (string) $this->user['role'];
        $rateLimit = $this->knowledge->consumeChatRequest($userId);
        if (!$rateLimit['allowed']) {
            header('Retry-After: ' . $rateLimit['retry_after']);
            return $this->jsonResponse(['message' => 'AI chat limit reached. Please wait a minute before sending another message.'], 429);
        }
        if ($conversationId) {
            if (!$this->knowledge->conversation($conversationId, $userId)) return $this->jsonResponse(['message' => 'Conversation not found.'], 404);
        } else $conversationId = $this->knowledge->createConversation($userId, $role, mb_substr($question, 0, 100));
        $history = array_reverse($this->knowledge->messages($conversationId));
        $audiences = ['trainee' => ['public'], 'instructor' => ['public', 'teaching'], 'staff' => ['public', 'teaching', 'staff'], 'administrator' => ['public', 'teaching', 'staff', 'admin']][$role] ?? ['public'];
        $timezone = is_string($data['timezone'] ?? null) ? trim($data['timezone']) : 'Asia/Manila';
        if ($timezone === '' || strlen($timezone) > 64) $timezone = 'Asia/Manila';
        try {
            $this->gemini->assertConfigured();
            $this->knowledge->addMessage($conversationId, 'user', $question);
            $answer = $this->gemini->answer($question, $this->knowledge->allowedStores($audiences), $history, $role, $timezone);
            $messageId = $this->knowledge->addMessage($conversationId, 'assistant', $answer['text'], $answer['tokens'], $answer['citations']);
            return $this->jsonResponse(['conversation_id' => $conversationId, 'message_id' => $messageId, 'answer' => $answer['text'], 'citations' => $answer['citations']]);
        } catch (Throwable $error) {
            error_log('Gemini chat request failed: ' . get_class($error) . ': ' . $error->getMessage());
            return $this->jsonResponse(['message' => 'The AI assistant is temporarily unavailable. Please try again.'], 503);
        }
    }

    public function test()
    {
        $this->requireRequestMethod('POST');
        if (!$this->requireAdminApiUser(true)) return;
        $question = trim((string) ($this->requestData()['message'] ?? ''));
        if ($question === '' || mb_strlen($question) > 2000) return $this->jsonResponse(['message' => 'Enter a test question up to 2,000 characters.'], 422);
        $rateLimit = $this->knowledge->consumeChatRequest((int) $this->user['user_id']);
        if (!$rateLimit['allowed']) return $this->jsonResponse(['message' => 'AI test limit reached. Please wait before retrying.'], 429);
        try {
            $answer = $this->gemini->answer($question, $this->knowledge->allowedStores(['public', 'teaching', 'staff', 'admin']), [], 'administrator');
            $this->knowledge->logActivity((int) $this->user['user_id'], 'ai_response_tested', 'AI response test submitted');
            return $this->jsonResponse(['answer' => $answer['text'], 'citations' => $answer['citations']]);
        } catch (Throwable $error) {
            error_log('Gemini test request failed: ' . get_class($error) . ': ' . $error->getMessage());
            return $this->jsonResponse(['message' => 'Gemini could not answer the test question.'], 503);
        }
    }

    public function feedback()
    {
        $this->requireRequestMethod('POST');
        if (!$this->requireApiUser(true)) return;
        $data = $this->requestData();
        $messageId = (int) ($data['message_id'] ?? 0);
        $rating = (string) ($data['rating'] ?? '');
        if (!$messageId || !in_array($rating, ['helpful', 'not_helpful'], true)) return $this->jsonResponse(['message' => 'Choose a valid feedback rating.'], 422);
        if (!$this->knowledge->feedback((int) $this->user['user_id'], $messageId, $rating, mb_substr(trim((string) ($data['comment'] ?? '')), 0, 1000))) return $this->jsonResponse(['message' => 'That AI response is not available to your account.'], 404);
        return $this->jsonResponse(['saved' => true]);
    }

    private function requireApiUser($csrf = false)
    {
        if (!$this->requireUser()) return false;
        if ($csrf && $this->apiLibrary()->get_bearer_token() === null && !$this->validCsrf()) {
            $this->jsonResponse(['message' => 'Your session expired. Refresh and try again.'], 403); return false;
        }
        return true;
    }

    private function requireAdminApiUser($csrf = false)
    {
        if (!$this->requireApiUser($csrf)) return false;
        if (($this->user['role'] ?? '') !== 'administrator') {
            $this->jsonResponse(['message' => 'Only administrators can manage AI knowledge.'], 403); return false;
        }
        return true;
    }

    private function extractDocxText($path)
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('The PHP ZIP extension is required to process DOCX files.');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('The DOCX file is invalid.');
        $xml = $zip->getFromName('word/document.xml'); $zip->close();
        if (!is_string($xml)) throw new RuntimeException('The DOCX file has no readable document text.');
        $xml = preg_replace('/<w:tab\b[^>]*\/>|<w:br\b[^>]*\/>|<w:p\b[^>]*>/', "\n", $xml);
        return trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    private function materializeKnowledgeFile($storedPath)
    {
        $storedPath = (string) $storedPath;
        if (strpos($storedPath, 'cloud://') !== 0) return $this->privateDocumentPath($storedPath);
        require_once APP_DIR . 'libraries/CloudStorage.php';
        $storage = new CloudStorage();
        if (!$storage->isEnabled()) return null;
        $tempPath = tempnam(sys_get_temp_dir(), 'lsi-ai-source-');
        if (!$tempPath) return null;
        if (!$storage->download(substr($storedPath, 8), $tempPath)) { unlink($tempPath); return null; }
        return $tempPath;
    }
}

