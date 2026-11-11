-- Additive AI knowledge, chat, and feedback schema for LSI SmartTrack.
-- Run once against the existing MySQL 8 database. Existing tables are untouched.

CREATE TABLE IF NOT EXISTS ai_document_categories (
  category_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (category_id),
  UNIQUE KEY uq_ai_document_category_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ai_document_categories (name, description) VALUES
('Student Handbook', 'Student rules and regulations'),
('English Training', 'English lessons and explanations'),
('Grammar', 'Grammar tutoring modules'),
('Vocabulary', 'Vocabulary explanations and practice'),
('Assessment', 'Quiz and examination instructions'),
('Institutional Policy', 'Official institutional information'),
('FAQ', 'Frequently asked questions'),
('SmartTrack User Manual', 'How to use LSI SmartTrack');

CREATE TABLE IF NOT EXISTS ai_knowledge_documents (
  document_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id INT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  stored_path VARCHAR(500) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  audience ENUM('public','teaching','staff','admin') NOT NULL DEFAULT 'public',
  status ENUM('active','inactive') NOT NULL DEFAULT 'inactive',
  processing_status ENUM('queued','processing','ready','failed') NOT NULL DEFAULT 'queued',
  processing_error VARCHAR(500) NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  uploaded_by INT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED NOT NULL,
  approved_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (document_id),
  KEY idx_ai_documents_search_status (status, processing_status, audience),
  KEY idx_ai_documents_category (category_id),
  CONSTRAINT fk_ai_documents_category FOREIGN KEY (category_id) REFERENCES ai_document_categories(category_id),
  CONSTRAINT fk_ai_documents_uploader FOREIGN KEY (uploaded_by) REFERENCES users(user_id)
  ,CONSTRAINT fk_ai_documents_approver FOREIGN KEY (approved_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_knowledge_references (
  audience ENUM('public','teaching','staff','admin') NOT NULL,
  store_name VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (audience),
  UNIQUE KEY uq_ai_store_name (store_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_knowledge_document_refs (
  document_id BIGINT UNSIGNED NOT NULL,
  audience ENUM('public','teaching','staff','admin') NOT NULL,
  remote_document_name VARCHAR(500) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (document_id, audience),
  CONSTRAINT fk_ai_doc_ref_document FOREIGN KEY (document_id) REFERENCES ai_knowledge_documents(document_id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_doc_ref_store FOREIGN KEY (audience) REFERENCES ai_knowledge_references(audience) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_chat_conversations (
  conversation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  role ENUM('administrator','staff','instructor','trainee') NOT NULL,
  title VARCHAR(180) NOT NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (conversation_id),
  KEY idx_ai_conversation_user (user_id, updated_at),
  CONSTRAINT fk_ai_conversation_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_chat_messages (
  message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  role ENUM('user','assistant') NOT NULL,
  content MEDIUMTEXT NOT NULL,
  citations JSON NULL,
  token_count INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (message_id),
  KEY idx_ai_messages_conversation (conversation_id, message_id),
  CONSTRAINT fk_ai_message_conversation FOREIGN KEY (conversation_id) REFERENCES ai_chat_conversations(conversation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_user_feedback (
  feedback_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  rating ENUM('helpful','not_helpful') NOT NULL,
  comment VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (feedback_id),
  UNIQUE KEY uq_ai_feedback_user_message (user_id, message_id),
  CONSTRAINT fk_ai_feedback_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_feedback_message FOREIGN KEY (message_id) REFERENCES ai_chat_messages(message_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_chat_rate_limits (
  user_id INT UNSIGNED NOT NULL,
  window_started_at DATETIME NOT NULL,
  request_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_ai_chat_limit_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
