-- Operational planning for a Spezistream.
--
-- A selected bottle is not a test result and must not change the drink's
-- lifecycle.  This join table records only which acquired drinks were put on
-- tonight's lineup.  Completed results remain authoritative in drink_tests.
ALTER TABLE test_runs
    ADD COLUMN wheel_image_path VARCHAR(255) NULL AFTER notes,
    ADD COLUMN wheel_image_mime VARCHAR(50) NULL AFTER wheel_image_path,
    ADD UNIQUE KEY uq_test_runs_wheel_image_path (wheel_image_path),
    ADD CONSTRAINT chk_test_runs_wheel_image_pair CHECK (
        (wheel_image_path IS NULL AND wheel_image_mime IS NULL)
        OR (wheel_image_path IS NOT NULL AND wheel_image_mime IS NOT NULL)
    );

CREATE TABLE test_run_drinks (
    test_run_number SMALLINT UNSIGNED NOT NULL,
    drink_id BIGINT UNSIGNED NOT NULL,
    selection_order INT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (test_run_number, drink_id),
    UNIQUE KEY uq_test_run_drinks_order (test_run_number, selection_order),
    KEY idx_test_run_drinks_drink (drink_id),
    CONSTRAINT fk_test_run_drinks_run
        FOREIGN KEY (test_run_number) REFERENCES test_runs (number) ON DELETE RESTRICT,
    CONSTRAINT fk_test_run_drinks_drink
        FOREIGN KEY (drink_id) REFERENCES drinks (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
