CREATE TABLE IF NOT EXISTS `smarttrack_gemini_generation_limits` (
  `user_id` int unsigned NOT NULL,
  `window_started_at` datetime NOT NULL,
  `request_count` tinyint unsigned NOT NULL DEFAULT 0,
  `last_granted` tinyint unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`user_id`),
  KEY `idx_smarttrack_gemini_limits_window` (`window_started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
