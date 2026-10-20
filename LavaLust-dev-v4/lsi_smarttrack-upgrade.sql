-- Safe upgrade for an existing SmartTrack database that already has training_programs.
-- Creates the missing application tables and leaves existing legacy tables/data untouched.

CREATE TABLE IF NOT EXISTS `users` (
  `user_id` int unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) NOT NULL,
  `username` varchar(50) NOT NULL,
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
  `access_level` varchar(20) NOT NULL DEFAULT 'private',
  PRIMARY KEY (`document_id`),
  KEY `idx_smarttrack_document_trainee` (`trainee_id`),
  KEY `idx_smarttrack_document_class` (`class_id`),
  KEY `idx_smarttrack_document_uploader` (`uploaded_by`),
  CONSTRAINT `smarttrack_documents_trainee_fk` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`trainee_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_documents_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `smarttrack_documents_uploader_fk` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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