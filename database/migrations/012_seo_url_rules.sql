-- Additive SEO URL rules (410 Gone tombstones and 301 permanent redirects)
-- for deleted published businesses. Safe to repeat; does not modify existing content.
CREATE TABLE IF NOT EXISTS seo_url_rules (
    path VARCHAR(320) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    http_status SMALLINT UNSIGNED NOT NULL DEFAULT 410,
    target_path VARCHAR(320) NULL DEFAULT NULL,
    target_business_id INT UNSIGNED NULL DEFAULT NULL,
    fallback_path VARCHAR(320) NULL DEFAULT NULL,
    fallback_label VARCHAR(200) NULL DEFAULT NULL,
    entity_title VARCHAR(200) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (path),
    KEY idx_seo_rules_status (http_status),
    KEY idx_seo_rules_target_biz (target_business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
