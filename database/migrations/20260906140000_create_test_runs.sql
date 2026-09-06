-- Testabende (livestream episodes).
--
-- `drink_tests` already records, per tested Spezi, which stream it was tested
-- in (`stream_reference`), where in that stream the segment starts
-- (`recorded_time`) and how long it ran (`duration_value`). What was missing is
-- the episode itself: its title, recording date and — the point of the whole
-- exercise — the video URL a "watch this segment" link needs.
--
-- The table is keyed by the same stream number `drink_tests.stream_reference`
-- already stores. No foreign key is added on that column on purpose: the
-- reviewed data-only seed inserts `drink_tests` rows during a fresh install,
-- and migrations run before that import, so a constraint here would reject the
-- seed. Metadata is therefore optional per stream number, and the application
-- treats a missing row as "this episode has no details recorded yet".
CREATE TABLE test_runs (
    number SMALLINT UNSIGNED NOT NULL,
    title VARCHAR(190) NULL,
    recorded_on DATE NULL,
    stream_url VARCHAR(500) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'open',
    notes TEXT NULL,
    completed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (number),
    KEY idx_test_runs_status (status),
    CONSTRAINT chk_test_runs_status CHECK (status IN ('open', 'completed')),
    CONSTRAINT chk_test_runs_number_positive CHECK (number > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
