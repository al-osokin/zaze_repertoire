ALTER TABLE `temza_cast_resolved`
    ADD COLUMN `play_id` int(11) DEFAULT NULL AFTER `temza_event_id`,
    ADD COLUMN `temza_role_normalized` varchar(255) DEFAULT NULL AFTER `temza_role_raw`,
    ADD KEY `idx_temza_cast_play` (`play_id`),
    ADD KEY `idx_temza_cast_role_norm` (`temza_role_normalized`),
    ADD CONSTRAINT `temza_cast_play_fk` FOREIGN KEY (`play_id`) REFERENCES `plays` (`id`) ON DELETE SET NULL;
