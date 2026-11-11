-- Additive tables only: no existing articles, businesses or publication settings are changed.
-- All statements are safe to repeat after an interrupted deployment.
CREATE TABLE IF NOT EXISTS ai_article_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    provider VARCHAR(32) NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_requests_user_time (user_id, requested_at),
    KEY idx_ai_requests_time (requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A single row serializes quota checks across different users/sessions.
CREATE TABLE IF NOT EXISTS ai_article_quota_lock (
    id TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO ai_article_quota_lock (id) VALUES (1);

CREATE TABLE IF NOT EXISTS advertising_banners (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(150) NOT NULL,
    alt_text VARCHAR(255) NOT NULL,
    media_id INT UNSIGNED NOT NULL,
    target_url VARCHAR(2048) NOT NULL,
    placement VARCHAR(40) NOT NULL,
    city_id INT UNSIGNED NULL DEFAULT NULL,
    category_id INT UNSIGNED NULL DEFAULT NULL,
    starts_at DATETIME NULL DEFAULT NULL,
    ends_at DATETIME NULL DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 0,
    priority INT NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ad_placement (placement, active, starts_at, ends_at),
    KEY idx_ad_targeting (city_id, category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS advertising_inquiries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(32) NOT NULL,
    message TEXT NULL,
    status ENUM('new', 'handled') NOT NULL DEFAULT 'new',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ad_inquiry_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A human review is an explicit snapshot with a source; it is not inferred from
-- collection, saving a record, or simply having data present.
CREATE TABLE IF NOT EXISTS business_data_reviews (
    business_id INT UNSIGNED NOT NULL,
    source_note VARCHAR(1000) NOT NULL,
    data_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reviewed_by INT UNSIGNED NOT NULL,
    reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (business_id),
    KEY idx_business_reviews_date (reviewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
