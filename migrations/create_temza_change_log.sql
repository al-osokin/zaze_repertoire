CREATE TABLE IF NOT EXISTS `temza_change_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `temza_event_id` int(11) NOT NULL,
  `changes_json` longtext NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_temza_change_event` (`temza_event_id`),
  CONSTRAINT `temza_change_event_fk` FOREIGN KEY (`temza_event_id`) REFERENCES `temza_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
