ALTER TABLE `temza_events`
    ADD COLUMN `ignore_in_schedule` TINYINT(1) NOT NULL DEFAULT 0 AFTER `matched_event_id`;
