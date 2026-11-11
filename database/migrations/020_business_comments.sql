-- =====================================================================
-- HamiHubX.ir — مرحله S16: جدول دیدگاه‌های کاربران روی کسب‌وکارها
-- دیدگاه پیش‌فرض در انتظار تایید تحریریه است (status='pending') و فقط
-- با تایید در پنل مدیریت (status='approved') در صفحه عمومی نمایش داده می‌شود.
-- IP فقط برای مهار اسپم نگهداری می‌شود و هرگز در خروجی عمومی نمی‌آید.
-- اجرا: از دکمه «اجرای مایگریشن‌ها» در پنل مدیریت (عمومی و تکرارپذیر با IF NOT EXISTS)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `business_comments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `business_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(60) NOT NULL,
    `rating` TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `body` TEXT NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `ip_address` VARCHAR(64) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_bc_biz_status_date` (`business_id`, `status`, `created_at`),
    KEY `idx_bc_status` (`status`),
    CONSTRAINT `fk_bc_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
