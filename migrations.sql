--
-- Таблица 'artists' - для всех исполнителей (актеры, дирижеры, пианисты)
--
CREATE TABLE `artists` (
  `artist_id` INT(11) NOT NULL AUTO_INCREMENT,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `type` ENUM('artist', 'conductor', 'pianist', 'other') DEFAULT 'artist', -- Категория исполнителя
  `vk_link` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`artist_id`),
  UNIQUE KEY `idx_unique_artist_name_type` (`first_name`, `last_name`, `type`) -- Защита от дубликатов
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Таблица 'roles' - список уникальных ролей для каждого спектакля
--
CREATE TABLE `roles` (
  `role_id` INT(11) NOT NULL AUTO_INCREMENT,
  `play_id` INT(11) NOT NULL,                               -- Ссылка на plays.id
  `role_name` VARCHAR(255) NOT NULL,                        -- Название роли (например, "Герман", "Пушкин")
  `role_description` VARCHAR(255) NULL DEFAULT NULL,        -- Дополнительный текст (например, ", офицер", ", внучка графини")
  `expected_artist_type` ENUM('artist', 'conductor', 'pianist', 'other') DEFAULT 'artist', -- Ожидаемый тип исполнителя
  `sort_order` INT(11) DEFAULT 0,                           -- Порядок вывода ролей в карточке
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `idx_unique_role_per_play` (`play_id`, `role_name`, `role_description`), -- Уникальность роли в рамках спектакля
  CONSTRAINT `fk_roles_play_id` FOREIGN KEY (`play_id`) REFERENCES `plays` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Таблица 'performance_roles_artists' - назначение артистов на роли для конкретного представления
--
CREATE TABLE `performance_roles_artists` (
  `performance_role_artist_id` INT(11) NOT NULL AUTO_INCREMENT,
  `performance_id` INT(11) NOT NULL,                     -- Ссылка на events_raw.id
  `role_id` INT(11) NOT NULL,                            -- Ссылка на roles.role_id
  `artist_id` INT(11) NULL DEFAULT NULL,                 -- Ссылка на artists.artist_id (NULL, если "СОСТАВ УТОЧНЯЕТСЯ")
  `custom_artist_name` VARCHAR(255) NULL DEFAULT NULL,   -- Для временного хранения имени нового артиста
  `sort_order_in_role` INT(11) DEFAULT 0,                -- Порядок, если несколько артистов на одной роли (для групповых ролей)
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`performance_role_artist_id`),
  UNIQUE KEY `idx_unique_performance_role_artist` (`performance_id`, `role_id`, `artist_id`, `sort_order_in_role`), -- Защита от дубликатов
  CONSTRAINT `fk_pra_performance_id` FOREIGN KEY (`performance_id`) REFERENCES `events_raw` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pra_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pra_artist_id` FOREIGN KEY (`artist_id`) REFERENCES `artists` (`artist_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Таблица 'role_artist_history' - история назначений артистов на роли (для формирования "частых" исполнителей)
--
CREATE TABLE `role_artist_history` (
  `role_artist_history_id` INT(11) NOT NULL AUTO_INCREMENT,
  `role_id` INT(11) NOT NULL,
  `artist_id` INT(11) NOT NULL,
  `last_assigned_date` DATETIME DEFAULT CURRENT_TIMESTAMP(),
  `assignment_count` INT(11) DEFAULT 1,
  PRIMARY KEY (`role_artist_history_id`),
  UNIQUE KEY `idx_unique_role_artist_combo` (`role_id`, `artist_id`), -- Один артист на одну роль, история ведется по паре
  CONSTRAINT `fk_rah_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rah_artist_id` FOREIGN KEY (`artist_id`) REFERENCES `artists` (`artist_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Модификация `events_raw`
--
ALTER TABLE `events_raw`
ADD COLUMN `vk_post_text` TEXT NULL DEFAULT NULL AFTER `background_url`,
ADD COLUMN `is_published_vk` TINYINT(1) DEFAULT 0 AFTER `vk_post_text`;
