-- Safe upgrade for an existing SmartTrack database that already has training_programs.
-- Creates the missing application tables and leaves existing legacy tables/data untouched.
-- Compatible with both MySQL 5.7+/8.x and MariaDB, and safe to re-run.

SET NAMES utf8mb4;

-- JWT Refresh Token Table for API authentication
CREATE TABLE IF NOT EXISTS refresh_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_id (user_id),
  INDEX idx_token_hash (token_hash),
  INDEX idx_expires_at (expires_at),
  CONSTRAINT refresh_tokens_user_fk FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Portable helper: "ADD COLUMN IF NOT EXISTS" only exists in MariaDB, so we emulate it
-- through information_schema. Usage: SET @tbl=..., @col=..., @def=...; then run the 4 lines.

CREATE TABLE IF NOT EXISTS `users` (
  `user_id` int unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(30) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_smarttrack_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `trainees` (
  `trainee_id` int unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `sex` varchar(20) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `contact_number` varchar(20) NOT NULL,
  `course` varchar(100) NOT NULL,
  `program_id` int unsigned DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `user_id` int unsigned DEFAULT NULL,
  `qr_code` varchar(100) NOT NULL,
  PRIMARY KEY (`trainee_id`),
  UNIQUE KEY `uq_smarttrack_trainee_user` (`user_id`),
  UNIQUE KEY `uq_smarttrack_trainee_qr` (`qr_code`),
  KEY `idx_smarttrack_trainee_program` (`program_id`),
  CONSTRAINT `smarttrack_trainees_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_trainees_program_fk` FOREIGN KEY (`program_id`) REFERENCES `training_programs` (`program_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `training_classes` (
  `class_id` int unsigned NOT NULL AUTO_INCREMENT,
  `program_id` int unsigned NOT NULL,
  `instructor_id` int unsigned DEFAULT NULL,
  `class_name` varchar(150) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`class_id`),
  KEY `idx_smarttrack_class_program` (`program_id`),
  KEY `idx_smarttrack_class_instructor` (`instructor_id`),
  CONSTRAINT `smarttrack_classes_program_fk` FOREIGN KEY (`program_id`) REFERENCES `training_programs` (`program_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_classes_instructor_fk` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `training_schedules` (
  `schedule_id` int unsigned NOT NULL AUTO_INCREMENT,
  `program_id` int unsigned DEFAULT NULL,
  `class_id` int unsigned DEFAULT NULL,
  `schedule_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  PRIMARY KEY (`schedule_id`),
  KEY `idx_smarttrack_schedule_program` (`program_id`),
  KEY `idx_smarttrack_schedule_class` (`class_id`),
  CONSTRAINT `smarttrack_schedules_program_fk` FOREIGN KEY (`program_id`) REFERENCES `training_programs` (`program_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_schedules_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `class_enrollments` (
  `enrollment_id` int unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int unsigned NOT NULL,
  `trainee_id` int unsigned NOT NULL,
  `enrolled_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`enrollment_id`),
  UNIQUE KEY `uq_smarttrack_class_trainee` (`class_id`, `trainee_id`),
  KEY `idx_smarttrack_enrollment_trainee` (`trainee_id`),
  CONSTRAINT `smarttrack_enrollments_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_enrollments_trainee_fk` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`trainee_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `attendance_records` (
  `attendance_id` int unsigned NOT NULL AUTO_INCREMENT,
  `trainee_id` int unsigned NOT NULL,
  `class_id` int unsigned DEFAULT NULL,
  `schedule_id` int unsigned DEFAULT NULL,
  `attendance_date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `status` varchar(30) NOT NULL,
  `remarks` varchar(200) NOT NULL DEFAULT '',
  `recorded_by` int unsigned DEFAULT NULL,
  PRIMARY KEY (`attendance_id`),
  UNIQUE KEY `uq_smarttrack_attendance_class_day` (`trainee_id`, `class_id`, `attendance_date`),
  KEY `idx_smarttrack_attendance_class` (`class_id`),
  KEY `idx_smarttrack_attendance_schedule` (`schedule_id`),
  CONSTRAINT `smarttrack_attendance_trainee_fk` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`trainee_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_attendance_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_attendance_schedule_fk` FOREIGN KEY (`schedule_id`) REFERENCES `training_schedules` (`schedule_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_attendance_recorder_fk` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `trainee_documents` (
  `document_id` int unsigned NOT NULL AUTO_INCREMENT,
  `trainee_id` int unsigned DEFAULT NULL,
  `class_id` int unsigned DEFAULT NULL,
  `uploaded_by` int unsigned DEFAULT NULL,
  `document_name` varchar(150) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `upload_date` date NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'active',
  `review_status` varchar(20) NOT NULL DEFAULT 'approved',
  `reviewed_by` int unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `access_level` varchar(20) NOT NULL DEFAULT 'private',
  PRIMARY KEY (`document_id`),
  KEY `idx_smarttrack_document_trainee` (`trainee_id`),
  KEY `idx_smarttrack_document_class` (`class_id`),
  KEY `idx_smarttrack_document_uploader` (`uploaded_by`),
  CONSTRAINT `smarttrack_documents_trainee_fk` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`trainee_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_documents_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_documents_uploader_fk` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @tbl = 'trainee_documents', @col = 'review_status', @def = 'varchar(20) NOT NULL DEFAULT ''approved''';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @tbl = 'trainee_documents', @col = 'reviewed_by', @def = 'int unsigned DEFAULT NULL';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @tbl = 'trainee_documents', @col = 'reviewed_at', @def = 'timestamp NULL DEFAULT NULL';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `announcements` (
  `announcement_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `content` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` varchar(20) NOT NULL DEFAULT 'published',
  PRIMARY KEY (`announcement_id`),
  KEY `idx_smarttrack_announcement_user` (`user_id`),
  CONSTRAINT `smarttrack_announcements_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `inquiries` (
  `inquiry_id` int unsigned NOT NULL AUTO_INCREMENT,
  `trainee_id` int unsigned NOT NULL,
  `subject` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `reply` text DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`inquiry_id`),
  KEY `idx_smarttrack_inquiry_trainee` (`trainee_id`),
  CONSTRAINT `smarttrack_inquiries_trainee_fk` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`trainee_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `notifications` (
  `notification_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `message` text NOT NULL,
  `notification_type` varchar(50) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notification_id`),
  KEY `idx_smarttrack_notification_user` (`user_id`),
  CONSTRAINT `smarttrack_notifications_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `activity_logs` (
  `log_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `action` varchar(150) NOT NULL,
  `details` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_smarttrack_activity_user` (`user_id`),
  CONSTRAINT `smarttrack_activity_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add missing columns to existing tables if they don't exist
SET @tbl = 'users', @col = 'email', @def = 'varchar(100) DEFAULT NULL AFTER `username`';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl = 'trainees', @col = 'date_of_birth', @def = 'date DEFAULT NULL AFTER `sex`';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @tbl = 'trainees', @col = 'address', @def = 'varchar(255) DEFAULT NULL AFTER `date_of_birth`';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @tbl = 'trainees', @col = 'email', @def = 'varchar(100) DEFAULT NULL AFTER `address`';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl = 'training_programs', @col = 'program_code', @def = 'varchar(50) DEFAULT NULL AFTER `program_id`';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Certificate management tables
CREATE TABLE IF NOT EXISTS `certificates` (
  `certificate_id` int unsigned NOT NULL AUTO_INCREMENT,
  `trainee_id` int unsigned NOT NULL,
  `class_id` int unsigned NOT NULL,
  `program_id` int unsigned NOT NULL,
  `certificate_number` varchar(50) NOT NULL,
  `completion_date` date NOT NULL,
  `issue_date` date NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `file_path` varchar(255) DEFAULT NULL,
  `qr_code` varchar(100) NOT NULL,
  `revoked_at` date DEFAULT NULL,
  `revoked_by` int unsigned DEFAULT NULL,
  `revocation_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`certificate_id`),
  UNIQUE KEY `uq_smarttrack_certificate_number` (`certificate_number`),
  UNIQUE KEY `uq_smarttrack_certificate_trainee_class` (`trainee_id`, `class_id`),
  KEY `idx_smarttrack_certificate_trainee` (`trainee_id`),
  KEY `idx_smarttrack_certificate_class` (`class_id`),
  KEY `idx_smarttrack_certificate_program` (`program_id`),
  CONSTRAINT `smarttrack_certificates_trainee_fk` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`trainee_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_certificates_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_certificates_program_fk` FOREIGN KEY (`program_id`) REFERENCES `training_programs` (`program_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_certificates_revoker_fk` FOREIGN KEY (`revoked_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @tbl = 'certificates', @col = 'status', @def = 'varchar(30) NOT NULL DEFAULT ''draft''';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `certificate_signatories` (
  `signatory_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `name` varchar(150) NOT NULL,
  `position` varchar(150) NOT NULL,
  `signature_path` varchar(255) NOT NULL,
  `x_position` decimal(5,2) NOT NULL DEFAULT 50.00,
  `y_position` decimal(5,2) NOT NULL DEFAULT 80.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`signatory_id`),
  UNIQUE KEY `uq_smarttrack_signatory_user` (`user_id`),
  CONSTRAINT `smarttrack_signatory_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_signatory_creator_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `certificate_signatures` (
  `signature_id` int unsigned NOT NULL AUTO_INCREMENT,
  `certificate_id` int unsigned NOT NULL,
  `signatory_id` int unsigned NOT NULL,
  `signatory_user_id` int unsigned NOT NULL,
  `signatory_name` varchar(150) NOT NULL,
  `signatory_position` varchar(150) NOT NULL,
  `signature_path` varchar(255) NOT NULL,
  `x_position` decimal(5,2) NOT NULL,
  `y_position` decimal(5,2) NOT NULL,
  `decision` varchar(20) NOT NULL DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `signed_at` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `certificate_data_hash` char(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`signature_id`),
  KEY `idx_smarttrack_signature_certificate` (`certificate_id`),
  KEY `idx_smarttrack_signature_user` (`signatory_user_id`),
  KEY `idx_smarttrack_signature_slot` (`certificate_id`, `signatory_id`, `signature_id`),
  CONSTRAINT `smarttrack_signature_certificate_fk` FOREIGN KEY (`certificate_id`) REFERENCES `certificates` (`certificate_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_signature_signatory_fk` FOREIGN KEY (`signatory_id`) REFERENCES `certificate_signatories` (`signatory_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_signature_user_fk` FOREIGN KEY (`signatory_user_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Existing installations may already have a certificate_signatures table.
-- Add the account link separately because CREATE TABLE IF NOT EXISTS does not
-- update an existing table definition.
SET @tbl = 'certificate_signatures', @col = 'signatory_user_id', @def = 'int unsigned DEFAULT NULL';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl = 'certificate_signatures', @col = 'decision', @def = 'varchar(20) NOT NULL DEFAULT ''pending''';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @tbl = 'certificate_signatures', @col = 'signatory_name', @def = 'varchar(150) DEFAULT NULL';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @tbl = 'certificate_signatures', @col = 'signatory_position', @def = 'varchar(150) DEFAULT NULL';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @tbl = 'certificate_signatures', @col = 'signature_path', @def = 'varchar(255) DEFAULT NULL';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @tbl = 'certificate_signatures', @col = 'x_position', @def = 'decimal(5,2) DEFAULT NULL';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @tbl = 'certificate_signatures', @col = 'y_position', @def = 'decimal(5,2) DEFAULT NULL';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @tbl = 'certificate_signatures', @col = 'remarks', @def = 'text DEFAULT NULL';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @tbl = 'certificate_signatures', @col = 'signed_at', @def = 'timestamp NULL DEFAULT NULL';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @tbl = 'certificate_signatures', @col = 'ip_address', @def = 'varchar(45) DEFAULT NULL';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @tbl = 'certificate_signatures', @col = 'certificate_data_hash', @def = 'char(64) DEFAULT NULL';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @tbl = 'certificate_signatures', @col = 'created_at', @def = 'timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `certificate_signatures` cs
JOIN `certificate_signatories` s ON s.`signatory_id` = cs.`signatory_id`
SET cs.`signatory_user_id` = s.`user_id`
WHERE cs.`signatory_user_id` IS NULL;

UPDATE `certificate_signatures` cs
JOIN `certificate_signatories` s ON s.`signatory_id` = cs.`signatory_id`
SET cs.`signatory_name` = COALESCE(NULLIF(cs.`signatory_name`, ''), s.`name`),
    cs.`signatory_position` = COALESCE(NULLIF(cs.`signatory_position`, ''), s.`position`),
    cs.`signature_path` = COALESCE(NULLIF(cs.`signature_path`, ''), s.`signature_path`),
    cs.`x_position` = COALESCE(cs.`x_position`, s.`x_position`),
    cs.`y_position` = COALESCE(cs.`y_position`, s.`y_position`);

CREATE TABLE IF NOT EXISTS `certificate_eligibility` (
  `eligibility_id` int unsigned NOT NULL AUTO_INCREMENT,
  `trainee_id` int unsigned NOT NULL,
  `class_id` int unsigned NOT NULL,
  `program_id` int unsigned NOT NULL,
  `attendance_percentage` decimal(5,2) NOT NULL,
  `documents_submitted` int NOT NULL DEFAULT 0,
  `documents_required` int NOT NULL DEFAULT 0,
  `documents_approved` int NOT NULL DEFAULT 0,
  `assessments_completed` int NOT NULL DEFAULT 0,
  `assessments_required` int NOT NULL DEFAULT 0,
  `is_eligible` tinyint(1) NOT NULL DEFAULT 0,
  `ineligibility_reasons` text DEFAULT NULL,
  `approval_status` varchar(20) NOT NULL DEFAULT 'pending',
  `approval_reason` text DEFAULT NULL,
  `approved_by` int unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`eligibility_id`),
  UNIQUE KEY `uq_smarttrack_eligibility_trainee_class` (`trainee_id`, `class_id`),
  KEY `idx_smarttrack_eligibility_trainee` (`trainee_id`),
  KEY `idx_smarttrack_eligibility_class` (`class_id`),
  CONSTRAINT `smarttrack_eligibility_trainee_fk` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`trainee_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_eligibility_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_eligibility_reviewer_fk` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_eligibility_approver_fk` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @tbl = 'certificate_eligibility', @col = 'approval_status', @def = 'varchar(20) NOT NULL DEFAULT ''pending''';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @tbl = 'certificate_eligibility', @col = 'approval_reason', @def = 'text DEFAULT NULL';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @tbl = 'certificate_eligibility', @col = 'approved_by', @def = 'int unsigned DEFAULT NULL';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @tbl = 'certificate_eligibility', @col = 'approved_at', @def = 'timestamp NULL DEFAULT NULL';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `training_assessments` (
  `assessment_id` int unsigned NOT NULL AUTO_INCREMENT,
  `trainee_id` int unsigned NOT NULL,
  `class_id` int unsigned NOT NULL,
  `assessment_name` varchar(150) NOT NULL,
  `outcome` varchar(10) NOT NULL,
  `assessed_by` int unsigned NOT NULL,
  `assessment_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`assessment_id`),
  UNIQUE KEY `uq_smarttrack_assessment_trainee_class_name` (`trainee_id`, `class_id`, `assessment_name`),
  KEY `idx_smarttrack_assessment_class_outcome` (`class_id`, `outcome`),
  CONSTRAINT `smarttrack_assessments_trainee_fk` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`trainee_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_assessments_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_assessments_assessor_fk` FOREIGN KEY (`assessed_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `certificate_document_requirements` (
  `requirement_id` int unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int unsigned NOT NULL,
  `required_document_name` varchar(150) NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`requirement_id`),
  UNIQUE KEY `uq_smarttrack_required_document_class_name` (`class_id`, `required_document_name`),
  CONSTRAINT `smarttrack_document_requirement_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_document_requirement_creator_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `certificate_audit_logs` (
  `audit_id` int unsigned NOT NULL AUTO_INCREMENT,
  `actor_id` int unsigned DEFAULT NULL,
  `certificate_id` int unsigned DEFAULT NULL,
  `eligibility_id` int unsigned DEFAULT NULL,
  `action` varchar(30) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`audit_id`),
  KEY `idx_smarttrack_certificate_audit_certificate` (`certificate_id`),
  KEY `idx_smarttrack_certificate_audit_eligibility` (`eligibility_id`),
  CONSTRAINT `smarttrack_certificate_audit_actor_fk` FOREIGN KEY (`actor_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_certificate_audit_certificate_fk` FOREIGN KEY (`certificate_id`) REFERENCES `certificates` (`certificate_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_certificate_audit_eligibility_fk` FOREIGN KEY (`eligibility_id`) REFERENCES `certificate_eligibility` (`eligibility_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @tbl = 'certificate_audit_logs', @col = 'eligibility_id', @def = 'int unsigned DEFAULT NULL';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `certificate_config` (
  `config_id` int unsigned NOT NULL AUTO_INCREMENT,
  `config_key` varchar(100) NOT NULL,
  `config_value` text NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`config_id`),
  UNIQUE KEY `uq_smarttrack_config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `certificate_templates` (
  `template_id` int unsigned NOT NULL AUTO_INCREMENT,
  `mode` varchar(20) NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `field_layout` JSON NOT NULL,
  `settings` JSON NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `version` int unsigned NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`template_id`),
  UNIQUE KEY `uq_smarttrack_certificate_template_version` (`version`),
  KEY `idx_smarttrack_certificate_template_active` (`is_active`),
  CONSTRAINT `smarttrack_certificate_template_creator_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @tbl = 'certificates', @col = 'template_id', @def = 'int unsigned DEFAULT NULL';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @tbl = 'certificates', @col = 'template_snapshot', @def = 'JSON DEFAULT NULL';
SET @sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` ', @def), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Default certificate configuration (existing values are preserved)
INSERT INTO `certificate_config` (`config_key`, `config_value`, `description`) VALUES
('institution_name', 'LSI Training Center', 'Name of the institution'),
('logo_path', '', 'Path to institution logo'),
('signatory_name', 'Training Director', 'Name of authorized signatory'),
('signatory_designation', 'Director', 'Designation of authorized signatory'),
('min_attendance_percentage', '75', 'Minimum attendance percentage required for certificate'),
('require_documents', '1', 'Whether documents are required for certificate'),
('require_assessments', '0', 'Whether assessments are required for certificate'),
('required_assessment_count', '1', 'Number of passed assessments required per class')
ON DUPLICATE KEY UPDATE `config_key` = `config_key`; -- keep any values already customised

-- Payments and refundable bond ledger
CREATE TABLE IF NOT EXISTS `fee_types` (
  `fee_type_id` int unsigned NOT NULL AUTO_INCREMENT,
  `program_id` int unsigned DEFAULT NULL,
  `class_id` int unsigned DEFAULT NULL,
  `fee_kind` varchar(20) NOT NULL,
  `fee_name` varchar(150) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `due_date` date DEFAULT NULL,
  `required_for_certificate` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`fee_type_id`),
  KEY `idx_smarttrack_fee_program` (`program_id`),
  KEY `idx_smarttrack_fee_class` (`class_id`),
  KEY `idx_smarttrack_fee_scope` (`fee_kind`, `is_active`),
  CONSTRAINT `smarttrack_fee_program_fk` FOREIGN KEY (`program_id`) REFERENCES `training_programs` (`program_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_fee_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_fee_creator_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `trainee_fees` (
  `trainee_fee_id` int unsigned NOT NULL AUTO_INCREMENT,
  `fee_type_id` int unsigned DEFAULT NULL,
  `trainee_id` int unsigned NOT NULL,
  `class_id` int unsigned NOT NULL,
  `fee_kind` varchar(20) NOT NULL,
  `fee_name` varchar(150) NOT NULL,
  `amount_due` decimal(12,2) NOT NULL,
  `due_date` date DEFAULT NULL,
  `bond_status` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`trainee_fee_id`),
  UNIQUE KEY `uq_smarttrack_trainee_fee_instance` (`trainee_id`, `class_id`, `fee_type_id`),
  KEY `idx_smarttrack_trainee_fee_trainee` (`trainee_id`),
  KEY `idx_smarttrack_trainee_fee_class` (`class_id`),
  KEY `idx_smarttrack_trainee_fee_kind_status` (`fee_kind`, `bond_status`),
  CONSTRAINT `smarttrack_trainee_fee_type_fk` FOREIGN KEY (`fee_type_id`) REFERENCES `fee_types` (`fee_type_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_trainee_fee_trainee_fk` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`trainee_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_trainee_fee_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payments` (
  `payment_id` int unsigned NOT NULL AUTO_INCREMENT,
  `trainee_fee_id` int unsigned NOT NULL,
  `entry_type` varchar(20) NOT NULL DEFAULT 'payment',
  `amount` decimal(12,2) NOT NULL,
  `method` varchar(20) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `payment_date` date NOT NULL,
  `receipt_number` varchar(50) DEFAULT NULL,
  `reversal_of_payment_id` int unsigned DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `recorded_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`payment_id`),
  UNIQUE KEY `uq_smarttrack_payment_reference` (`reference_number`),
  UNIQUE KEY `uq_smarttrack_payment_receipt` (`receipt_number`),
  KEY `idx_smarttrack_payment_fee_date` (`trainee_fee_id`, `payment_date`),
  KEY `idx_smarttrack_payment_reversal` (`reversal_of_payment_id`),
  CONSTRAINT `smarttrack_payment_fee_fk` FOREIGN KEY (`trainee_fee_id`) REFERENCES `trainee_fees` (`trainee_fee_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_payment_reversal_fk` FOREIGN KEY (`reversal_of_payment_id`) REFERENCES `payments` (`payment_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_payment_recorder_fk` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payment_proofs` (
  `proof_id` int unsigned NOT NULL AUTO_INCREMENT,
  `trainee_fee_id` int unsigned NOT NULL,
  `uploaded_by` int unsigned NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `method` varchar(20) NOT NULL,
  `reference_number` varchar(100) NOT NULL,
  `payment_date` date NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending_verification',
  `remarks` text DEFAULT NULL,
  `payment_id` int unsigned DEFAULT NULL,
  `verified_by` int unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`proof_id`),
  UNIQUE KEY `uq_smarttrack_proof_reference` (`reference_number`),
  KEY `idx_smarttrack_proof_fee_status` (`trainee_fee_id`, `status`),
  CONSTRAINT `smarttrack_proof_fee_fk` FOREIGN KEY (`trainee_fee_id`) REFERENCES `trainee_fees` (`trainee_fee_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_proof_uploader_fk` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_proof_payment_fk` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_proof_verifier_fk` FOREIGN KEY (`verified_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bond_transactions` (
  `bond_transaction_id` int unsigned NOT NULL AUTO_INCREMENT,
  `trainee_fee_id` int unsigned NOT NULL,
  `transaction_type` varchar(20) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_id` int unsigned DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `method` varchar(20) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `performed_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`bond_transaction_id`),
  UNIQUE KEY `uq_smarttrack_bond_reference` (`reference_number`),
  KEY `idx_smarttrack_bond_fee_date` (`trainee_fee_id`, `transaction_date`),
  CONSTRAINT `smarttrack_bond_fee_fk` FOREIGN KEY (`trainee_fee_id`) REFERENCES `trainee_fees` (`trainee_fee_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_bond_payment_fk` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_bond_performer_fk` FOREIGN KEY (`performed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;