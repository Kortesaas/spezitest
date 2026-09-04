CREATE TABLE drinks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    lifecycle_status VARCHAR(16) NOT NULL,
    manufacturer VARCHAR(255) NULL,
    origin_location VARCHAR(255) NULL,
    origin_region VARCHAR(128) NULL,
    notes TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_drinks_lifecycle_status (lifecycle_status),
    KEY idx_drinks_manufacturer (manufacturer),
    CONSTRAINT chk_drinks_name_not_blank CHECK (CHAR_LENGTH(TRIM(name)) > 0),
    CONSTRAINT chk_drinks_lifecycle_status CHECK (
        lifecycle_status IN ('identified', 'acquired', 'tested')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE testers (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(32) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    display_order TINYINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_testers_code (code),
    UNIQUE KEY uq_testers_display_order (display_order),
    CONSTRAINT chk_testers_code CHECK (code IN ('manu', 'fabi', 'schorsch')),
    CONSTRAINT chk_testers_display_name_not_blank CHECK (CHAR_LENGTH(TRIM(display_name)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE drink_tests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    drink_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'draft',
    price_amount DECIMAL(12,4) NULL,
    recorded_time TIME NULL,
    duration_value INT UNSIGNED NULL,
    stream_reference SMALLINT UNSIGNED NULL,
    completed_at DATETIME(6) NULL,
    notes TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_drink_tests_drink_status (drink_id, status),
    KEY idx_drink_tests_status (status),
    CONSTRAINT fk_drink_tests_drink FOREIGN KEY (drink_id)
        REFERENCES drinks (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_drink_tests_status CHECK (status IN ('draft', 'completed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ratings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    test_id BIGINT UNSIGNED NOT NULL,
    tester_id SMALLINT UNSIGNED NOT NULL,
    optik DECIMAL(8,4) NOT NULL,
    sueffigkeit DECIMAL(8,4) NOT NULL,
    geschmack DECIMAL(8,4) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_ratings_test_tester (test_id, tester_id),
    KEY idx_ratings_tester (tester_id),
    CONSTRAINT fk_ratings_test FOREIGN KEY (test_id)
        REFERENCES drink_tests (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_ratings_tester FOREIGN KEY (tester_id)
        REFERENCES testers (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE drink_images (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    drink_id BIGINT UNSIGNED NOT NULL,
    storage_path VARCHAR(512) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_drink_images_storage_path (storage_path),
    UNIQUE KEY uq_drink_images_drink_order (drink_id, display_order),
    CONSTRAINT fk_drink_images_drink FOREIGN KEY (drink_id)
        REFERENCES drinks (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_drink_images_storage_path CHECK (
        CHAR_LENGTH(TRIM(storage_path)) > 0
        AND storage_path NOT LIKE '/%'
        AND storage_path NOT LIKE '%://%'
    ),
    CONSTRAINT chk_drink_images_mime_type CHECK (CHAR_LENGTH(TRIM(mime_type)) > 0),
    CONSTRAINT chk_drink_images_dimensions CHECK (width > 0 AND height > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
