-- =====================================================================
-- HamiHubX.ir — مرحله S24: دفتر بکاپ‌های خودکار دیتابیس
-- فایل‌های بکاپ در storage/backups ساخته می‌شوند و فقط متادیتای امن
-- (نام فایل، حجم، تعداد جداول/سطرها، منبع اجرا، وضعیت) اینجا ثبت می‌شود.
-- هیچ مسیر مطلق، کلید یا محتوای حساسی در این جدول ذخیره نمی‌شود.
-- اجرا: دکمهٔ «اجرای مایگریشن‌ها» در پنل مدیریت (بدون نیاز به SSH)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `system_backups` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `filename` VARCHAR(190) NOT NULL,
    `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `tables_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `rows_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `trigger_source` ENUM('cron','panel','cli') NOT NULL DEFAULT 'cron',
    `status` ENUM('ok','failed') NOT NULL DEFAULT 'ok',
    `error_message` VARCHAR(500) NOT NULL DEFAULT '',
    `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_system_backups_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تنظیمات پیش‌فرض بکاپ (از پنل با ویرایش مستقیم settings قابل تغییر است)
INSERT IGNORE INTO `settings` (`k`, `v`) VALUES
    ('backup_retention_days', '14'),
    ('backup_last_run', '');
