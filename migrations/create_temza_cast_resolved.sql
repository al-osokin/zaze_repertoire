CREATE TABLE IF NOT EXISTS `temza_cast_resolved` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `temza_event_id` int(11) NOT NULL,
  `temza_role_raw` varchar(255) NOT NULL,
  `temza_actor` varchar(255) NOT NULL,
  `temza_role_notes` varchar(255) DEFAULT NULL,
  `is_debut` tinyint(1) NOT NULL DEFAULT 0,
  `mapped_role_id` int(11) DEFAULT NULL,
  `mapped_group` varchar(255) DEFAULT NULL,
  `status` enum('pending','mapped','unmapped') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_temza_cast_event` (`temza_event_id`),
  KEY `idx_temza_cast_role` (`mapped_role_id`),
  CONSTRAINT `temza_cast_event_fk` FOREIGN KEY (`temza_event_id`) REFERENCES `temza_events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `temza_cast_role_fk` FOREIGN KEY (`mapped_role_id`) REFERENCES `roles` (`role_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
