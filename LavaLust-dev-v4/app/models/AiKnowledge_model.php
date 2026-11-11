<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'models/Base_model.php';

class AiKnowledge_model extends Base_model
{
    public function categories()
    {
        return $this->rows('SELECT category_id, name, description FROM ai_document_categories ORDER BY name');
    }

    public function documents($search = '')
    {
        $search = '%' . trim((string) $search) . '%';
        return $this->rows('SELECT d.document_id, d.category_id, d.title, d.original_filename, d.mime_type, d.file_size, d.status, d.audience, d.processing_status, d.processing_error, d.version, d.updated_at, c.name AS category_name FROM ai_knowledge_documents d LEFT JOIN ai_document_categories c ON c.category_id=d.category_id WHERE (? = "%%" OR d.title LIKE ? OR d.original_filename LIKE ? OR c.name LIKE ?) ORDER BY d.updated_at DESC', [$search, $search, $search, $search]);
    }

    public function findDocument($id)
    {
        return $this->row('SELECT * FROM ai_knowledge_documents WHERE document_id=? LIMIT 1', [(int) $id]);
    }

    public function createDocument(array $record)
    {
        $this->execute('INSERT INTO ai_knowledge_documents (category_id,title,original_filename,stored_path,mime_type,file_size,sha256,audience,status,processing_status,version,uploaded_by,approved_by,approved_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)', [
            $record['category_id'], $record['title'], $record['original_filename'], $record['stored_path'], $record['mime_type'], $record['file_size'], $record['sha256'], $record['audience'], $record['status'], $record['processing_status'], $record['version'], $record['uploaded_by'], $record['approved_by'],
        ]);
        return (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
    }

    public function updateProcessing($id, $status, $error = null)
    {
        return $this->execute('UPDATE ai_knowledge_documents SET processing_status=?, processing_error=?, updated_at=CURRENT_TIMESTAMP WHERE document_id=?', [$status, $error, (int) $id]);
    }

    public function setStatus($id, $status)
    {
        return $this->execute('UPDATE ai_knowledge_documents SET status=?, updated_at=CURRENT_TIMESTAMP WHERE document_id=?', [$status, (int) $id]);
    }

    public function deleteDocument($id)
    {
        return $this->execute('DELETE FROM ai_knowledge_documents WHERE document_id=?', [(int) $id]);
    }

    public function storeForAudience($audience)
    {
        return $this->row('SELECT store_name FROM ai_knowledge_references WHERE audience=? LIMIT 1', [$audience]);
    }

    public function saveStore($audience, $storeName)
    {
        $this->execute('INSERT INTO ai_knowledge_references (audience,store_name) VALUES (?,?) ON DUPLICATE KEY UPDATE store_name=VALUES(store_name)', [$audience, $storeName]);
    }

    public function linkDocument($id, $audience, $remoteName)
    {
        $this->execute('INSERT INTO ai_knowledge_document_refs (document_id,audience,remote_document_name) VALUES (?,?,?) ON DUPLICATE KEY UPDATE remote_document_name=VALUES(remote_document_name)', [(int) $id, $audience, $remoteName]);
    }

    public function documentRefs($id)
    {
        return $this->rows('SELECT audience,remote_document_name FROM ai_knowledge_document_refs WHERE document_id=?', [(int) $id]);
    }

    public function deleteRefs($id)
    {
        return $this->execute('DELETE FROM ai_knowledge_document_refs WHERE document_id=?', [(int) $id]);
    }

    public function allowedStores($audiences)
    {
        if (!$audiences) return [];
        $marks = implode(',', array_fill(0, count($audiences), '?'));
        return $this->rows('SELECT DISTINCT r.store_name FROM ai_knowledge_references r WHERE r.audience IN (' . $marks . ') AND EXISTS (SELECT 1 FROM ai_knowledge_documents d JOIN ai_knowledge_document_refs dr ON dr.document_id=d.document_id WHERE dr.audience=r.audience AND d.status="active" AND d.processing_status="ready")', array_values($audiences));
    }

    public function createConversation($userId, $role, $title)
    {
        $this->execute('INSERT INTO ai_chat_conversations (user_id,role,title) VALUES (?,?,?)', [(int) $userId, $role, $title]);
        return (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
    }

    public function conversation($id, $userId)
    {
        return $this->row('SELECT * FROM ai_chat_conversations WHERE conversation_id=? AND user_id=? AND status="active" LIMIT 1', [(int) $id, (int) $userId]);
    }

    public function messages($conversationId, $limit = 12)
    {
        $limit = max(1, min(30, (int) $limit));
        return $this->rows('SELECT role,content FROM ai_chat_messages WHERE conversation_id=? ORDER BY message_id DESC LIMIT ' . $limit, [(int) $conversationId]);
    }

    public function addMessage($conversationId, $role, $content, $tokens = null, $citations = null)
    {
        $this->execute('INSERT INTO ai_chat_messages (conversation_id,role,content,token_count,citations) VALUES (?,?,?,?,?)', [(int) $conversationId, $role, $content, $tokens, $citations === null ? null : json_encode($citations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $this->execute('UPDATE ai_chat_conversations SET updated_at=CURRENT_TIMESTAMP WHERE conversation_id=?', [(int) $conversationId]);
        return (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
    }

    public function feedback($userId, $messageId, $rating, $comment)
    {
        $ownedMessage = $this->row('SELECT m.message_id FROM ai_chat_messages m JOIN ai_chat_conversations c ON c.conversation_id=m.conversation_id WHERE m.message_id=? AND c.user_id=? AND m.role="assistant" LIMIT 1', [(int) $messageId, (int) $userId]);
        if (!$ownedMessage) return false;
        $this->execute('INSERT INTO ai_user_feedback (user_id,message_id,rating,comment) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE rating=VALUES(rating),comment=VALUES(comment)', [(int) $userId, (int) $messageId, $rating, $comment]);
        return true;
    }

    public function consumeChatRequest($userId, $limit = 25)
    {
        $this->execute('INSERT INTO ai_chat_rate_limits (user_id,window_started_at,request_count) VALUES (?,CURRENT_TIMESTAMP,1) ON DUPLICATE KEY UPDATE request_count=IF(window_started_at < CURRENT_TIMESTAMP - INTERVAL 1 MINUTE,1,request_count+1),window_started_at=IF(window_started_at < CURRENT_TIMESTAMP - INTERVAL 1 MINUTE,CURRENT_TIMESTAMP,window_started_at)', [(int) $userId]);
        $row = $this->row('SELECT request_count, GREATEST(1,60-TIMESTAMPDIFF(SECOND,window_started_at,CURRENT_TIMESTAMP)) AS retry_after FROM ai_chat_rate_limits WHERE user_id=?', [(int) $userId]);
        return ['allowed' => (int) ($row['request_count'] ?? 0) <= $limit, 'retry_after' => (int) ($row['retry_after'] ?? 60)];
    }
}
