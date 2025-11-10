ALTER TABLE `temza_events`
    ADD COLUMN `published_at` DATETIME NULL AFTER `updated_at`,
    ADD COLUMN `published_by` INT NULL AFTER `published_at`,
    ADD KEY `idx_temza_events_published_at` (`published_at`),
    ADD KEY `idx_temza_events_published_by` (`published_by`),
    ADD CONSTRAINT `temza_events_published_by_fk` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
