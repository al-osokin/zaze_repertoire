ALTER TABLE `temza_role_map`
    ADD COLUMN `temza_role_normalized` varchar(255) NOT NULL AFTER `temza_role`,
    DROP INDEX `uniq_play_role`,
    ADD UNIQUE KEY `uniq_play_role_normalized` (`play_id`,`temza_role_normalized`);
