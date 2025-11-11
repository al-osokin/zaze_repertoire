ALTER TABLE `template_elements`
    ADD COLUMN `use_previous_cast` TINYINT(1) NOT NULL DEFAULT 0 AFTER `element_value`;
