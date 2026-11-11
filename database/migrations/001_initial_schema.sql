-- ============================================================================
-- HamiHubX.ir - Migration 001: ساختار کامل جداول پایه (مرحله S0)
-- موتور: InnoDB | کدگذاری: utf8mb4_unicode_ci | سازگار با MySQL 8.0+ و MariaDB 10.4+
-- ============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+03:30';

-- ۱. جدول مدیران و ویرایشگران سیستم (بدون ثبت‌نام عمومی)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(64) NOT NULL,
    `display_name` VARCHAR(120) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'editor') NOT NULL DEFAULT 'editor',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۲. جدول ثبت تلاش‌های ورود برای محدودیت ۵ بار در ۱۰ دقیقه (IP + username)
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(64) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `is_successful` TINYINT(1) NOT NULL DEFAULT 0,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_login_attempts_lookup` (`username`, `ip_address`, `is_successful`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۳. جدول تنظیمات کلید-مقدار سیستم
CREATE TABLE IF NOT EXISTS `settings` (
    `k` VARCHAR(100) NOT NULL,
    `v` LONGTEXT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۴. جدول شهرهای استان گیلان
CREATE TABLE IF NOT EXISTS `cities` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name_fa` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    `lat` DECIMAL(10, 7) NULL DEFAULT NULL,
    `lng` DECIMAL(10, 7) NULL DEFAULT NULL,
    `bbox_json` LONGTEXT NULL,
    `publish_mode` ENUM('manual', 'rules', 'full_auto') NOT NULL DEFAULT 'manual',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cities_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۵. جدول محله‌های هر شهر
CREATE TABLE IF NOT EXISTS `neighborhoods` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `city_id` INT UNSIGNED NOT NULL,
    `name_fa` VARCHAR(120) NOT NULL,
    `slug` VARCHAR(120) NOT NULL,
    `lat` DECIMAL(10, 7) NULL DEFAULT NULL,
    `lng` DECIMAL(10, 7) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_neighborhoods_city_slug` (`city_id`, `slug`),
    KEY `idx_neighborhoods_city` (`city_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۶. جدول دسته‌بندی کسب‌وکارها
CREATE TABLE IF NOT EXISTS `categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_id` INT UNSIGNED NULL DEFAULT NULL,
    `name_fa` VARCHAR(120) NOT NULL,
    `slug` VARCHAR(120) NOT NULL,
    `default_icon_svg` LONGTEXT NULL,
    `sort` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_categories_slug` (`slug`),
    KEY `idx_categories_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۷. جدول بسته‌های دسته (Category Packs) برای جمع‌آوری خودکار
CREATE TABLE IF NOT EXISTS `category_packs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `queries_json` LONGTEXT NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۸. جدول آیتم‌های هر بسته دسته
CREATE TABLE IF NOT EXISTS `category_pack_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pack_id` INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `queries_json` LONGTEXT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pack_category` (`pack_id`, `category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۹. جدول فایل‌های رسانه (WebP در اندازه‌های 480 / 800 / 1200)
CREATE TABLE IF NOT EXISTS `media` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `file_path_480` VARCHAR(255) NOT NULL,
    `file_path_800` VARCHAR(255) NOT NULL,
    `file_path_1200` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(64) NOT NULL DEFAULT 'image/webp',
    `width` INT UNSIGNED NOT NULL DEFAULT 800,
    `height` INT UNSIGNED NOT NULL DEFAULT 600,
    `size_bytes` INT UNSIGNED NOT NULL DEFAULT 0,
    `alt_text` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۱۰. جدول اصلی کسب‌وکارها
CREATE TABLE IF NOT EXISTS `businesses` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(200) NOT NULL,
    `normalized_name` VARCHAR(200) NOT NULL DEFAULT '',
    `slug` VARCHAR(200) NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `city_id` INT UNSIGNED NOT NULL,
    `neighborhood_id` INT UNSIGNED NULL DEFAULT NULL,
    `address` TEXT NULL,
    `lat` DECIMAL(10, 7) NULL DEFAULT NULL,
    `lng` DECIMAL(10, 7) NULL DEFAULT NULL,
    `hours` TEXT NULL,
    `short_desc` LONGTEXT NULL,
    `bullets_json` LONGTEXT NULL,
    `faqs_json` LONGTEXT NULL,
    `cover_media_id` INT UNSIGNED NULL DEFAULT NULL,
    `status` ENUM('draft', 'pending_review', 'published', 'hidden', 'rejected') NOT NULL DEFAULT 'draft',
    `tier` ENUM('A', 'B', 'C') NOT NULL DEFAULT 'C',
    `internal_score` DECIMAL(6, 3) NOT NULL DEFAULT 0.000,
    `confidence` ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'low',
    `publish_reason` VARCHAR(120) NULL DEFAULT NULL,
    `last_collected_at` DATETIME NULL DEFAULT NULL,
    `last_verified_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_businesses_slug` (`slug`),
    KEY `idx_businesses_city_cat_status` (`city_id`, `category_id`, `status`),
    KEY `idx_businesses_score` (`internal_score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۱۱. جدول راه‌های ارتباطی هر کسب‌وکار (تلفن، موبایل، اینستاگرام، واتساپ، وب‌سایت)
CREATE TABLE IF NOT EXISTS `business_contacts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `business_id` INT UNSIGNED NOT NULL,
    `type` ENUM('phone', 'mobile', 'instagram', 'whatsapp', 'website') NOT NULL,
    `value` VARCHAR(255) NOT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `source` VARCHAR(64) NOT NULL DEFAULT 'manual',
    `confidence` ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    `last_verified_at` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_business_contacts_biz` (`business_id`),
    KEY `idx_business_contacts_val` (`value`(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۱۲. جدول منابع جمع‌آوری هر کسب‌وکار (چندمنبعی)
CREATE TABLE IF NOT EXISTS `business_sources` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `business_id` INT UNSIGNED NOT NULL,
    `provider` VARCHAR(50) NOT NULL,
    `source_id` VARCHAR(190) NOT NULL,
    `raw` LONGTEXT NULL,
    `rating` DECIMAL(3, 2) NULL DEFAULT NULL,
    `reviews_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `fetched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_business_sources_provider_sid` (`provider`, `source_id`),
    KEY `idx_business_sources_biz` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۱۳. جدول تصاویر متصل به کسب‌وکار
CREATE TABLE IF NOT EXISTS `business_photos` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `business_id` INT UNSIGNED NOT NULL,
    `media_id` INT UNSIGNED NOT NULL,
    `source` ENUM('manual', 'zip', 'owner', 'provider') NOT NULL DEFAULT 'manual',
    `source_url` TEXT NULL,
    `attribution` VARCHAR(255) NULL DEFAULT NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved',
    `sort` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_business_photos_biz` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۱۴. جدول مقالات لیستی («بهترین کافه‌های رشت»)
CREATE TABLE IF NOT EXISTS `articles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(200) NOT NULL,
    `meta_title` VARCHAR(255) NULL DEFAULT NULL,
    `meta_description` VARCHAR(320) NULL DEFAULT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `city_id` INT UNSIGNED NOT NULL,
    `intro` LONGTEXT NULL,
    `tips_html` LONGTEXT NULL,
    `year_label` VARCHAR(32) NOT NULL DEFAULT '',
    `status` ENUM('draft', 'review', 'published') NOT NULL DEFAULT 'draft',
    `ai_generated` TINYINT(1) NOT NULL DEFAULT 0,
    `published_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_articles_slug` (`slug`),
    KEY `idx_articles_city_cat_status` (`city_id`, `category_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۱۵. جدول آیتم‌های درون هر مقاله لیستی (همراه با جایگاه ویژه تبلیغاتی)
CREATE TABLE IF NOT EXISTS `article_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `article_id` INT UNSIGNED NOT NULL,
    `business_id` INT UNSIGNED NOT NULL,
    `position` INT NOT NULL DEFAULT 1,
    `custom_desc` LONGTEXT NULL,
    `highlights` LONGTEXT NULL,
    `is_sponsored` TINYINT(1) NOT NULL DEFAULT 0,
    `sponsor_label` VARCHAR(80) NULL DEFAULT 'تبلیغ',
    `sponsor_start` DATETIME NULL DEFAULT NULL,
    `sponsor_end` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_article_items_art_biz` (`article_id`, `business_id`),
    KEY `idx_article_items_sponsor_window` (`is_sponsored`, `sponsor_start`, `sponsor_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۱۶. جدول سؤالات متداول هر مقاله (FAQPage Schema)
CREATE TABLE IF NOT EXISTS `article_faqs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `article_id` INT UNSIGNED NOT NULL,
    `question` VARCHAR(500) NOT NULL,
    `answer` LONGTEXT NOT NULL,
    `sort` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_article_faqs_article` (`article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۱۷. جدول صف کارهای جمع‌آوری (Collector Jobs - مدل Pull)
CREATE TABLE IF NOT EXISTS `collector_jobs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `city_id` INT UNSIGNED NOT NULL,
    `pack_id` INT UNSIGNED NULL DEFAULT NULL,
    `provider` VARCHAR(50) NOT NULL,
    `params_json` LONGTEXT NULL,
    `status` ENUM('queued', 'claimed', 'running', 'done', 'failed', 'paused') NOT NULL DEFAULT 'queued',
    `claimed_at` DATETIME NULL DEFAULT NULL,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `result_json` LONGTEXT NULL,
    `error` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_collector_jobs_status` (`status`, `provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۱۸. جدول صف کارهای هوش مصنوعی (AI Tasks - مدل Pull)
CREATE TABLE IF NOT EXISTS `ai_tasks` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` ENUM('business_text', 'article_draft') NOT NULL,
    `target_id` INT UNSIGNED NOT NULL,
    `input_json` LONGTEXT NOT NULL,
    `status` ENUM('queued', 'claimed', 'done', 'failed') NOT NULL DEFAULT 'queued',
    `output_json` LONGTEXT NULL,
    `used_fields_json` LONGTEXT NULL,
    `model` VARCHAR(100) NULL DEFAULT NULL,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `error` TEXT NULL,
    `claimed_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ai_tasks_status_type` (`status`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۱۹. جدول صندوق بازبینی متمرکز (Review Queue)
CREATE TABLE IF NOT EXISTS `review_queue` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kind` ENUM('business', 'change', 'duplicate', 'article', 'claim', 'report') NOT NULL,
    `target_id` INT UNSIGNED NOT NULL,
    `reason_code` VARCHAR(80) NOT NULL,
    `payload_json` LONGTEXT NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `decided_by` INT UNSIGNED NULL DEFAULT NULL,
    `decided_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_review_queue_status_kind` (`status`, `kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۲۰. جدول تاریخچه تغییرات فیلدهای حساس کسب‌وکار (شماره و آدرس)
CREATE TABLE IF NOT EXISTS `change_log` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `business_id` INT UNSIGNED NOT NULL,
    `field` VARCHAR(64) NOT NULL,
    `old_value` TEXT NULL,
    `new_value` TEXT NULL,
    `source` VARCHAR(64) NOT NULL,
    `status` ENUM('pending', 'auto_applied', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_change_log_biz_status` (`business_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۲۱. جدول وضعیت پرووایدرهای جمع‌آوری (توقف ۲۴ ساعته در صورت کپچا/429)
CREATE TABLE IF NOT EXISTS `provider_state` (
    `provider` VARCHAR(50) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `priority` INT NOT NULL DEFAULT 10,
    `paused_until` DATETIME NULL DEFAULT NULL,
    `last_error` TEXT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۲۲. جدول درخواست‌های ادعای مالکیت کسب‌وکار
CREATE TABLE IF NOT EXISTS `claims` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `business_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(32) NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `note` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_claims_biz_status` (`business_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۲۳. جدول گزارش‌های خطای اطلاعات توسط کاربران عمومی
CREATE TABLE IF NOT EXISTS `reports` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `business_id` INT UNSIGNED NOT NULL,
    `message` TEXT NOT NULL,
    `status` ENUM('pending', 'resolved', 'rejected') NOT NULL DEFAULT 'pending',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_reports_biz_status` (`business_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۲۴. جدول آمار روزانه کلیک و بازدید (بدون NULL، بدون FOREIGN KEY، مقدار ۰ یعنی نامربوط)
CREATE TABLE IF NOT EXISTS `events_daily` (
    `day` DATE NOT NULL,
    `business_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `article_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `type` ENUM('view', 'call', 'map', 'instagram', 'whatsapp', 'website') NOT NULL,
    `cnt` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`day`, `business_id`, `article_id`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- درج مقادیر پیش‌فرض در settings و provider_state
INSERT IGNORE INTO `settings` (`k`, `v`) VALUES
    ('site_title', 'حامی‌هاب گیلان — معرفی بهترین‌های استان گیلان'),
    ('photo_policy', 'none'),
    ('map_tile_url', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'),
    ('sponsor_last_check', NULL);

INSERT IGNORE INTO `provider_state` (`provider`, `enabled`, `priority`) VALUES
    ('gosom', 0, 1),
    ('osm', 1, 2),
    ('neshan', 0, 3),
    ('csv', 0, 4),
    ('wikidata', 0, 5),
    ('google_places', 0, 6),
    ('balad', 0, 7);
