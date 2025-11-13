-- Schema snapshot generated 2025-11-13 06:25:04Z
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE `artists` (
  `artist_id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `type` enum('artist','conductor','pianist','other','group') NOT NULL DEFAULT 'artist',
  `vk_link` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`artist_id`),
  UNIQUE KEY `idx_unique_artist_name_type` (`first_name`,`last_name`,`type`)
) ENGINE=InnoDB AUTO_INCREMENT=195 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `events_raw` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_token` char(36) NOT NULL,
  `event_date` date NOT NULL,
  `event_time` time NOT NULL,
  `title` varchar(255) NOT NULL,
  `normalized_title` varchar(255) DEFAULT NULL,
  `age_category` varchar(100) DEFAULT NULL,
  `ticket_code` varchar(20) DEFAULT NULL,
  `ticket_url` varchar(255) DEFAULT NULL,
  `repertoire_url` varchar(255) DEFAULT NULL,
  `background_url` varchar(255) DEFAULT NULL,
  `vk_post_text` text DEFAULT NULL,
  `is_published_vk` tinyint(1) DEFAULT 0,
  `play_id` int(11) DEFAULT NULL,
  `play_short_name` varchar(50) DEFAULT NULL,
  `month` tinyint(4) NOT NULL,
  `year` smallint(6) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `vk_page_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_batch_event` (`batch_token`,`event_date`,`event_time`,`title`),
  KEY `idx_events_raw_month_year` (`year`,`month`),
  KEY `idx_events_raw_ticket_code` (`ticket_code`),
  KEY `events_raw_ibfk_1` (`play_id`),
  CONSTRAINT `events_raw_ibfk_1` FOREIGN KEY (`play_id`) REFERENCES `plays` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=122 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `performance_roles_artists` (
  `performance_role_artist_id` int(11) NOT NULL AUTO_INCREMENT,
  `performance_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `artist_id` int(11) DEFAULT NULL,
  `custom_artist_name` varchar(255) DEFAULT NULL,
  `sort_order_in_role` int(11) DEFAULT 0,
  `is_first_time` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`performance_role_artist_id`),
  UNIQUE KEY `idx_unique_performance_role_artist` (`performance_id`,`role_id`,`artist_id`,`sort_order_in_role`),
  KEY `fk_pra_role_id` (`role_id`),
  KEY `fk_pra_artist_id` (`artist_id`),
  CONSTRAINT `fk_pra_artist_id` FOREIGN KEY (`artist_id`) REFERENCES `artists` (`artist_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pra_performance_id` FOREIGN KEY (`performance_id`) REFERENCES `events_raw` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pra_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3302 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `play_role_last_cast` (
  `play_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `sort_order_in_role` int(11) NOT NULL DEFAULT 0,
  `artist_id` int(11) DEFAULT NULL,
  `custom_artist_name` varchar(255) DEFAULT NULL,
  `is_first_time` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`play_id`,`role_id`,`sort_order_in_role`),
  KEY `idx_play_role_last_cast_play` (`play_id`),
  KEY `fk_play_role_last_cast_role` (`role_id`),
  CONSTRAINT `fk_play_role_last_cast_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `play_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `play_id` int(11) NOT NULL,
  `template_text` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `play_id` (`play_id`),
  CONSTRAINT `play_templates_ibfk_1` FOREIGN KEY (`play_id`) REFERENCES `plays` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `plays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `short_name` varchar(50) NOT NULL,
  `site_title` varchar(255) DEFAULT NULL,
  `full_name` varchar(255) NOT NULL,
  `wiki_link` varchar(255) DEFAULT NULL,
  `hall` varchar(100) NOT NULL,
  `special_mark` varchar(100) DEFAULT '',
  `is_subscription` tinyint(1) DEFAULT 0,
  `is_concert_program` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `short_name` (`short_name`),
  KEY `idx_plays_short_name` (`short_name`)
) ENGINE=InnoDB AUTO_INCREMENT=67 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `repertoire_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `month_year` varchar(50) NOT NULL,
  `source_text` text NOT NULL,
  `result_wiki` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_repertoire_history_month_year` (`month_year`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `role_artist_history` (
  `role_artist_history_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `artist_id` int(11) NOT NULL,
  `last_assigned_date` datetime DEFAULT current_timestamp(),
  `assignment_count` int(11) DEFAULT 1,
  PRIMARY KEY (`role_artist_history_id`),
  UNIQUE KEY `idx_unique_role_artist_combo` (`role_id`,`artist_id`),
  KEY `fk_rah_artist_id` (`artist_id`),
  CONSTRAINT `fk_rah_artist_id` FOREIGN KEY (`artist_id`) REFERENCES `artists` (`artist_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rah_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12304 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `roles` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `play_id` int(11) NOT NULL,
  `role_name` varchar(500) NOT NULL,
  `role_description` varchar(255) DEFAULT NULL,
  `expected_artist_type` enum('artist','conductor','pianist','other') DEFAULT 'artist',
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `idx_unique_role_per_play` (`play_id`,`role_name`,`role_description`),
  CONSTRAINT `fk_roles_play_id` FOREIGN KEY (`play_id`) REFERENCES `plays` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=724 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `system_settings` (
  `setting_key` varchar(255) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `template_elements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `play_id` int(11) NOT NULL,
  `element_type` enum('role','heading','image','newline') NOT NULL,
  `heading_level` int(1) DEFAULT NULL,
  `element_value` text DEFAULT NULL,
  `use_previous_cast` tinyint(1) NOT NULL DEFAULT 0,
  `special_group` varchar(32) DEFAULT NULL,
  `sort_order` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `play_id` (`play_id`),
  CONSTRAINT `template_elements_ibfk_1` FOREIGN KEY (`play_id`) REFERENCES `plays` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13905 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `temza_cast_resolved` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `temza_event_id` int(11) NOT NULL,
  `play_id` int(11) DEFAULT NULL,
  `temza_role_raw` varchar(255) NOT NULL,
  `temza_role_source` varchar(255) DEFAULT NULL,
  `temza_role_normalized` varchar(255) DEFAULT NULL,
  `temza_actor` varchar(512) NOT NULL,
  `temza_role_notes` varchar(255) DEFAULT NULL,
  `is_debut` tinyint(1) NOT NULL DEFAULT 0,
  `mapped_role_id` int(11) DEFAULT NULL,
  `mapped_group` varchar(255) DEFAULT NULL,
  `status` enum('pending','mapped','unmapped','ignored') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_temza_cast_event` (`temza_event_id`),
  KEY `idx_temza_cast_role` (`mapped_role_id`),
  KEY `idx_temza_cast_play` (`play_id`),
  KEY `idx_temza_cast_role_norm` (`temza_role_normalized`),
  CONSTRAINT `temza_cast_event_fk` FOREIGN KEY (`temza_event_id`) REFERENCES `temza_events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `temza_cast_play_fk` FOREIGN KEY (`play_id`) REFERENCES `plays` (`id`) ON DELETE SET NULL,
  CONSTRAINT `temza_cast_role_fk` FOREIGN KEY (`mapped_role_id`) REFERENCES `roles` (`role_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=39305 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `temza_change_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `temza_event_id` int(11) NOT NULL,
  `changes_json` longtext NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_temza_change_event` (`temza_event_id`),
  CONSTRAINT `temza_change_event_fk` FOREIGN KEY (`temza_event_id`) REFERENCES `temza_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `temza_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `temza_title_id` int(11) DEFAULT NULL,
  `temza_title` varchar(255) NOT NULL,
  `preview_title` varchar(255) DEFAULT NULL,
  `preview_details` varchar(255) DEFAULT NULL,
  `date_label` varchar(100) DEFAULT NULL,
  `time_label` varchar(50) DEFAULT NULL,
  `month_label` char(7) NOT NULL,
  `event_date` date DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `hall` varchar(100) DEFAULT NULL,
  `chips_json` longtext DEFAULT NULL,
  `cast_json` longtext DEFAULT NULL,
  `responsibles_json` longtext DEFAULT NULL,
  `department_tasks_json` longtext DEFAULT NULL,
  `called_json` longtext DEFAULT NULL,
  `notes_json` longtext DEFAULT NULL,
  `raw_html` longtext DEFAULT NULL,
  `raw_json` longtext DEFAULT NULL,
  `scraped_at` datetime NOT NULL,
  `matched_event_id` int(11) DEFAULT NULL,
  `status` enum('scheduled','cancelled') NOT NULL DEFAULT 'scheduled',
  `ignore_in_schedule` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `published_at` datetime DEFAULT NULL,
  `published_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_temza_events_month` (`month_label`),
  KEY `idx_temza_events_date_time` (`event_date`,`start_time`),
  KEY `idx_temza_events_title` (`temza_title`),
  KEY `idx_temza_events_title_id` (`temza_title_id`),
  KEY `idx_temza_events_matched_event` (`matched_event_id`),
  KEY `idx_temza_events_published_at` (`published_at`),
  KEY `idx_temza_events_published_by` (`published_by`),
  KEY `idx_temza_events_status` (`status`),
  CONSTRAINT `temza_events_events_raw_fk` FOREIGN KEY (`matched_event_id`) REFERENCES `events_raw` (`id`) ON DELETE SET NULL,
  CONSTRAINT `temza_events_published_by_fk` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `temza_events_title_fk` FOREIGN KEY (`temza_title_id`) REFERENCES `temza_titles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2119 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `temza_role_map` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `play_id` int(11) NOT NULL,
  `temza_role` varchar(255) NOT NULL,
  `temza_role_normalized` varchar(255) NOT NULL,
  `target_role_id` int(11) DEFAULT NULL,
  `target_group_name` varchar(255) DEFAULT NULL,
  `split_comma` tinyint(1) NOT NULL DEFAULT 1,
  `ignore_role` tinyint(1) NOT NULL DEFAULT 0,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_play_role_normalized` (`play_id`,`temza_role_normalized`),
  KEY `idx_temza_role_map_target_role` (`target_role_id`),
  CONSTRAINT `temza_role_map_play_fk` FOREIGN KEY (`play_id`) REFERENCES `plays` (`id`) ON DELETE CASCADE,
  CONSTRAINT `temza_role_map_target_fk` FOREIGN KEY (`target_role_id`) REFERENCES `roles` (`role_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=635 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `temza_titles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `temza_title` varchar(255) NOT NULL,
  `play_id` int(11) DEFAULT NULL,
  `suggested_play_id` int(11) DEFAULT NULL,
  `suggestion_confidence` tinyint(3) unsigned DEFAULT NULL,
  `is_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  `is_subscription` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_temza_title` (`temza_title`),
  KEY `idx_temza_titles_play_id` (`play_id`),
  KEY `temza_titles_suggested_play_fk` (`suggested_play_id`),
  CONSTRAINT `temza_titles_play_fk` FOREIGN KEY (`play_id`) REFERENCES `plays` (`id`) ON DELETE SET NULL,
  CONSTRAINT `temza_titles_suggested_play_fk` FOREIGN KEY (`suggested_play_id`) REFERENCES `plays` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS=1;
