ALTER TABLE `temza_cast_resolved`
    ADD COLUMN `temza_role_source` varchar(255) DEFAULT NULL AFTER `temza_role_raw`;
