ALTER TABLE `temza_cast_resolved`
    MODIFY COLUMN `status` ENUM('pending','mapped','unmapped','ignored') NOT NULL DEFAULT 'pending';
