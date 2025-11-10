CREATE TABLE IF NOT EXISTS `temza_role_map` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `play_id` int(11) NOT NULL,
  `temza_role` varchar(255) NOT NULL,
  `target_role_id` int(11) DEFAULT NULL,
  `target_group_name` varchar(255) DEFAULT NULL,
  `split_comma` tinyint(1) NOT NULL DEFAULT 1,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_play_role` (`play_id`,`temza_role`),
  KEY `idx_temza_role_map_target_role` (`target_role_id`),
  CONSTRAINT `temza_role_map_play_fk` FOREIGN KEY (`play_id`) REFERENCES `plays` (`id`) ON DELETE CASCADE,
  CONSTRAINT `temza_role_map_target_fk` FOREIGN KEY (`target_role_id`) REFERENCES `roles` (`role_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
