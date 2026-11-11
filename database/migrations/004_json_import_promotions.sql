-- Unknown review counts are not the same as zero. Existing values are preserved.
ALTER TABLE `business_sources`
    MODIFY COLUMN `reviews_count` INT UNSIGNED NULL DEFAULT NULL;

-- Keep the previous source observation when a manual import updates it.
CREATE TABLE IF NOT EXISTS `business_source_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `business_source_id` INT UNSIGNED NOT NULL,
    `raw` LONGTEXT NULL,
    `rating` DECIMAL(3, 2) NULL DEFAULT NULL,
    `reviews_count` INT UNSIGNED NULL DEFAULT NULL,
    `fetched_at` DATETIME NOT NULL,
    `archived_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_source_history_source` (`business_source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A later metadata-only fetch must not make an older rating appear new.
CREATE TABLE IF NOT EXISTS `business_source_rating_dates` (
    `business_source_id` INT UNSIGNED NOT NULL,
    `observed_at` DATETIME NOT NULL,
    PRIMARY KEY (`business_source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A promotion is separate from the genuine score and cannot publish a business.
CREATE TABLE IF NOT EXISTS `business_promotions` (
    `business_id` INT UNSIGNED NOT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
