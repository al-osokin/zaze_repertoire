ALTER TABLE template_elements
    ADD COLUMN special_group VARCHAR(32) DEFAULT NULL AFTER use_previous_cast;
