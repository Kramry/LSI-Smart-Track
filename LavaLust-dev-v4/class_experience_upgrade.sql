SET @db = DATABASE();
SET @sql = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE training_classes ADD COLUMN room VARCHAR(150) NULL AFTER class_name',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'training_classes' AND COLUMN_NAME = 'room'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE announcements ADD COLUMN class_id INT UNSIGNED NULL AFTER user_id, ADD INDEX idx_smarttrack_announcement_class (class_id)',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'announcements' AND COLUMN_NAME = 'class_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
