-- Additive article topic taxonomy; no existing article or business state changes.
CREATE TABLE IF NOT EXISTS article_tags (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name_fa VARCHAR(100) NOT NULL,
    slug VARCHAR(140) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_article_tags_name (name_fa),
    UNIQUE KEY uq_article_tags_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS article_tag_links (
    article_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (article_id,tag_id),
    KEY idx_article_tag_links_tag (tag_id,article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
