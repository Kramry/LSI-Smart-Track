CREATE TABLE IF NOT EXISTS `smarttrack_attendance_scan_limits` (
  `rate_key` char(64) NOT NULL,
  `window_started_at` datetime NOT NULL,
  `request_count` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`rate_key`),
  KEY `idx_smarttrack_attendance_scan_window` (`window_started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
