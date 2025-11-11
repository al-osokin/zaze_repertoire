ALTER TABLE `plays`
    ADD COLUMN `is_concert_program` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_subscription`;
