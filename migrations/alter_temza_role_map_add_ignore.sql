ALTER TABLE `temza_role_map`
    ADD COLUMN `ignore_role` TINYINT(1) NOT NULL DEFAULT 0 AFTER `split_comma`;
