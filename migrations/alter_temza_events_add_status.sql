ALTER TABLE `temza_events`
    ADD COLUMN `status` ENUM('scheduled','cancelled') NOT NULL DEFAULT 'scheduled' AFTER `matched_event_id`,
    ADD KEY `idx_temza_events_status` (`status`);
