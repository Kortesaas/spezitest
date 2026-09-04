ALTER TABLE drink_tests
    MODIFY price_amount DECIMAL(12,5) NULL;

CREATE TABLE legacy_import_runs (
    run_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    plan_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    primaerliste_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    beschaffungsliste_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    summary_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (run_id),
    UNIQUE KEY uq_legacy_import_runs_plan_sha256 (plan_sha256),
    CONSTRAINT chk_legacy_import_runs_summary_json CHECK (JSON_VALID(summary_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
