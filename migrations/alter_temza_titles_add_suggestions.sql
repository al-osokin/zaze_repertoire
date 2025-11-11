ALTER TABLE `temza_titles`
    ADD COLUMN `suggested_play_id` int(11) DEFAULT NULL AFTER `play_id`,
    ADD COLUMN `suggestion_confidence` tinyint(3) unsigned DEFAULT NULL AFTER `suggested_play_id`,
    ADD COLUMN `is_confirmed` tinyint(1) NOT NULL DEFAULT 0 AFTER `suggestion_confidence`,
    ADD CONSTRAINT `temza_titles_suggested_play_fk` FOREIGN KEY (`suggested_play_id`) REFERENCES `plays` (`id`) ON DELETE SET NULL;
