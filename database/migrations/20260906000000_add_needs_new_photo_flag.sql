ALTER TABLE drinks
    ADD COLUMN needs_new_photo TINYINT(1) NOT NULL DEFAULT 0 AFTER notes,
    ADD KEY idx_drinks_needs_new_photo (needs_new_photo),
    ADD CONSTRAINT chk_drinks_needs_new_photo CHECK (needs_new_photo IN (0, 1));
