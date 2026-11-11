-- ============================================================
-- S9 — ماژول جامع آمار بازدید و کلیک + لاگ سرویس‌های هوش مصنوعی
-- افزودنی، قابل تکرار و مستقل از مایگریشن‌های قدیمی؛ هیچ داده یا
-- جدول موجودی تغییر نمی‌کند. آمار سطح اول (تجمیعی روزانه) ماندگار
-- است و پاک‌سازی خودکار فقط لاگ جزئیات (سطح دوم) را هدف می‌گیرد.
-- ============================================================

-- ۱. آمار تجمیعی روزانه (سطح اول — ماندگار): به تفکیک کسب‌وکار، مقاله، شهر، دسته و بنر
CREATE TABLE IF NOT EXISTS `analytics_daily` (
    `day` DATE NOT NULL,
    `target_type` ENUM('business','article','city','category','banner') NOT NULL,
    `target_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `views` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `unique_visitors` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `clicks_call` INT UNSIGNED NOT NULL DEFAULT 0,
    `clicks_map` INT UNSIGNED NOT NULL DEFAULT 0,
    `clicks_website` INT UNSIGNED NOT NULL DEFAULT 0,
    `clicks_instagram` INT UNSIGNED NOT NULL DEFAULT 0,
    `clicks_whatsapp` INT UNSIGNED NOT NULL DEFAULT 0,
    `clicks_banner` INT UNSIGNED NOT NULL DEFAULT 0,
    `searches` INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`day`,`target_type`,`target_id`),
    KEY `idx_an_daily_target` (`target_type`,`target_id`,`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۲. دفتر بازدیدکننده یکتای هر روز (هش ناشناس؛ پس از تجمیع روز پاک می‌شود)
CREATE TABLE IF NOT EXISTS `analytics_visitor_days` (
    `day` DATE NOT NULL,
    `visitor_hash` CHAR(32) NOT NULL,
    `target_type` ENUM('business','article','city','category','banner') NOT NULL,
    `target_id` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`day`,`visitor_hash`,`target_type`,`target_id`),
    KEY `idx_an_visitor_day` (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۳. لاگ جزئیات رویدادها (سطح دوم — با بازه نگهداری کوتاه از پنل مدیریت)
CREATE TABLE IF NOT EXISTS `analytics_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `occurred_at` DATETIME NOT NULL,
    `event_type` ENUM('view','call','map','website','instagram','whatsapp','banner','search') NOT NULL,
    `target_type` ENUM('business','article','city','category','banner','none') NOT NULL DEFAULT 'none',
    `target_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `target_title` VARCHAR(190) NOT NULL DEFAULT '',
    `path` VARCHAR(190) NOT NULL DEFAULT '',
    `visitor_hash` CHAR(32) NOT NULL DEFAULT '',
    `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
    `referrer` VARCHAR(500) NOT NULL DEFAULT '',
    `ref_source` VARCHAR(20) NOT NULL DEFAULT 'direct',
    `ref_host` VARCHAR(120) NOT NULL DEFAULT '',
    `utm_source` VARCHAR(100) NOT NULL DEFAULT '',
    `utm_medium` VARCHAR(100) NOT NULL DEFAULT '',
    `utm_campaign` VARCHAR(100) NOT NULL DEFAULT '',
    `utm_term` VARCHAR(100) NOT NULL DEFAULT '',
    `device` VARCHAR(10) NOT NULL DEFAULT 'desktop',
    `browser` VARCHAR(30) NOT NULL DEFAULT '',
    `search_query` VARCHAR(190) NOT NULL DEFAULT '',
    `user_agent` VARCHAR(300) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `idx_an_events_time` (`occurred_at`),
    KEY `idx_an_events_target` (`target_type`,`target_id`,`occurred_at`),
    KEY `idx_an_events_type` (`event_type`,`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۴. لاگ تلاش‌های اتصال به سرویس‌های هوش مصنوعی (فقط کد وضعیت و راهنمای ثابت؛ بدون کلید و متن درخواست)
CREATE TABLE IF NOT EXISTS `ai_service_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `provider` VARCHAR(20) NOT NULL,
    `model` VARCHAR(120) NOT NULL DEFAULT '',
    `ok` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `status_code` INT NULL DEFAULT NULL,
    `message` VARCHAR(500) NOT NULL DEFAULT '',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ai_logs_provider` (`provider`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۵. تنظیمات پیش‌فرض پاک‌سازی خودکار (از پنل مدیریت قابل تغییر است)
INSERT IGNORE INTO `settings` (`k`, `v`) VALUES
    ('analytics_raw_retention_days', '7'),
    ('analytics_visitor_retention_days', '2'),
    ('ai_logs_retention_days', '30');
