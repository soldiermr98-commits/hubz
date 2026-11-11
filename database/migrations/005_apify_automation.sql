-- Apify is opt-in: no remote run is started by installing this migration.
CREATE TABLE IF NOT EXISTS `apify_automation_settings` (
    `id` TINYINT UNSIGNED NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `task_id` VARCHAR(100) NOT NULL DEFAULT '',
    `city_id` INT UNSIGNED NULL DEFAULT NULL,
    `category_id` INT UNSIGNED NULL DEFAULT NULL,
    `max_charge_usd` DECIMAL(8,2) NULL DEFAULT NULL,
    `source_categories_json` LONGTEXT NULL,
    `min_rating` DECIMAL(3,1) NOT NULL DEFAULT 0,
    `min_reviews` INT UNSIGNED NOT NULL DEFAULT 0,
    `next_run_at` DATETIME NULL DEFAULT NULL,
    `last_error` VARCHAR(500) NULL DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `apify_automation_settings` (`id`, `enabled`) VALUES (1, 0);

-- Results are staged for an administrator. Nothing is published by the worker.
CREATE TABLE IF NOT EXISTS `apify_import_runs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `task_id` VARCHAR(100) NOT NULL DEFAULT '',
    `remote_run_id` VARCHAR(100) NULL DEFAULT NULL,
    `dataset_id` VARCHAR(100) NULL DEFAULT NULL,
    `status` VARCHAR(30) NOT NULL,
    `city_id` INT UNSIGNED NULL DEFAULT NULL,
    `category_id` INT UNSIGNED NULL DEFAULT NULL,
    `source_categories_json` LONGTEXT NULL,
    `min_rating` DECIMAL(3,1) NOT NULL DEFAULT 0,
    `min_reviews` INT UNSIGNED NOT NULL DEFAULT 0,
    `started_at_iso` VARCHAR(40) NULL DEFAULT NULL,
    `finished_at_iso` VARCHAR(40) NULL DEFAULT NULL,
    `item_count` INT UNSIGNED NULL DEFAULT NULL,
    `dataset_json` LONGTEXT NULL,
    `error` VARCHAR(500) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_apify_remote_run` (`remote_run_id`),
    KEY `idx_apify_status` (`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
