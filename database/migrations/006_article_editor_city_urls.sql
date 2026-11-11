-- Non-destructive support for ordinary articles, private revisions, cover images
-- and permanent redirects after a business or article address changes.
-- Existing records retain their current publication states and slugs.
ALTER TABLE articles
    MODIFY COLUMN city_id INT UNSIGNED NULL DEFAULT NULL,
    MODIFY COLUMN category_id INT UNSIGNED NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS article_extras (
    article_id INT UNSIGNED NOT NULL,
    cover_media_id INT UNSIGNED NULL DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS article_revisions (
    article_id INT UNSIGNED NOT NULL,
    payload_json LONGTEXT NOT NULL,
    base_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('draft', 'review') NOT NULL DEFAULT 'draft',
    updated_by INT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS business_url_aliases (
    path VARCHAR(320) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    business_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (path),
    KEY idx_business_url_aliases_business (business_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS article_url_aliases (
    slug VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    article_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (slug),
    KEY idx_article_url_aliases_article (article_id)
) ENGINE=InnoDB;
