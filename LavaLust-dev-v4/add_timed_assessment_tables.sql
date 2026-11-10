-- Select the same database used by the application (DB_NAME) before running this migration.

CREATE TABLE IF NOT EXISTS timed_assessments (
  timed_assessment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  class_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  assessment_title VARCHAR(150) NOT NULL,
  assessment_type VARCHAR(30) NOT NULL,
  description TEXT NULL,
  instructions TEXT NOT NULL,
  start_at DATETIME NOT NULL,
  scheduled_end_at DATETIME NOT NULL,
  deadline_at DATETIME NOT NULL,
  duration_minutes INT UNSIGNED NOT NULL,
  allow_late_submission TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (timed_assessment_id),
  KEY idx_timed_assessment_class (class_id, status, start_at),
  KEY idx_timed_assessment_creator (created_by, created_at),
  CONSTRAINT fk_timed_assessment_class FOREIGN KEY (class_id) REFERENCES training_classes (class_id),
  CONSTRAINT fk_timed_assessment_creator FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS timed_assessment_recipients (
  recipient_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  timed_assessment_id INT UNSIGNED NOT NULL,
  trainee_id INT UNSIGNED NOT NULL,
  deadline_at DATETIME NOT NULL,
  is_excused TINYINT(1) NOT NULL DEFAULT 0,
  excuse_reason VARCHAR(500) NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (recipient_id),
  UNIQUE KEY uq_timed_assessment_recipient (timed_assessment_id, trainee_id),
  KEY idx_timed_recipient_trainee (trainee_id, timed_assessment_id),
  CONSTRAINT fk_timed_recipient_assessment FOREIGN KEY (timed_assessment_id) REFERENCES timed_assessments (timed_assessment_id) ON DELETE CASCADE,
  CONSTRAINT fk_timed_recipient_trainee FOREIGN KEY (trainee_id) REFERENCES trainees (trainee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS timed_assessment_submissions (
  submission_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  timed_assessment_id INT UNSIGNED NOT NULL,
  trainee_id INT UNSIGNED NOT NULL,
  submitted_score DECIMAL(6,2) NULL,
  result_text TEXT NULL,
  remarks VARCHAR(1000) NULL,
  submitted_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (submission_id),
  UNIQUE KEY uq_timed_submission_assessment_trainee (timed_assessment_id, trainee_id),
  KEY idx_timed_submission_time (timed_assessment_id, submitted_at),
  CONSTRAINT fk_timed_submission_recipient FOREIGN KEY (timed_assessment_id, trainee_id)
    REFERENCES timed_assessment_recipients (timed_assessment_id, trainee_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS timed_assessment_extensions (
  extension_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  timed_assessment_id INT UNSIGNED NOT NULL,
  trainee_id INT UNSIGNED NOT NULL,
  previous_deadline DATETIME NOT NULL,
  extended_deadline DATETIME NOT NULL,
  approved_by INT UNSIGNED NOT NULL,
  reason VARCHAR(500) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (extension_id),
  KEY idx_timed_extension_latest (timed_assessment_id, trainee_id, extended_deadline),
  CONSTRAINT fk_timed_extension_recipient FOREIGN KEY (timed_assessment_id, trainee_id)
    REFERENCES timed_assessment_recipients (timed_assessment_id, trainee_id) ON DELETE CASCADE,
  CONSTRAINT fk_timed_extension_approver FOREIGN KEY (approved_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS timed_assessment_audit_logs (
  audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  timed_assessment_id INT UNSIGNED NOT NULL,
  trainee_id INT UNSIGNED NULL,
  action VARCHAR(50) NOT NULL,
  previous_value TEXT NULL,
  new_value TEXT NULL,
  performed_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (audit_id),
  KEY idx_timed_audit_assessment (timed_assessment_id, created_at),
  KEY idx_timed_audit_actor (performed_by, created_at),
  CONSTRAINT fk_timed_audit_assessment FOREIGN KEY (timed_assessment_id) REFERENCES timed_assessments (timed_assessment_id) ON DELETE CASCADE,
  CONSTRAINT fk_timed_audit_trainee FOREIGN KEY (trainee_id) REFERENCES trainees (trainee_id),
  CONSTRAINT fk_timed_audit_actor FOREIGN KEY (performed_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS timed_assessment_notifications (
  notification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  timed_assessment_id INT UNSIGNED NOT NULL,
  recipient_user_id INT UNSIGNED NOT NULL,
  trainee_id INT UNSIGNED NULL,
  event_key VARCHAR(100) NOT NULL,
  message VARCHAR(500) NOT NULL,
  read_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (notification_id),
  UNIQUE KEY uq_timed_notification_event (timed_assessment_id, recipient_user_id, event_key),
  KEY idx_timed_notification_user (recipient_user_id, read_at, created_at),
  CONSTRAINT fk_timed_notification_assessment FOREIGN KEY (timed_assessment_id) REFERENCES timed_assessments (timed_assessment_id) ON DELETE CASCADE,
  CONSTRAINT fk_timed_notification_user FOREIGN KEY (recipient_user_id) REFERENCES users (user_id),
  CONSTRAINT fk_timed_notification_trainee FOREIGN KEY (trainee_id) REFERENCES trainees (trainee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
