-- =====================================================================
-- HamiHubX.ir — مرحله S17: منشأ مختصات و قفل مختصات دستی ادمین
-- coords_source: مختصات از کجا آمده است (osm / neshan / google_maps / gosom / csv /
--   wikidata / balad / manual). مقدار 'manual' یعنی ادمین با نقشه یا Paste سریع،
--   نقطه را خودش تأیید/اصلاح کرده است و در ایمپورت‌های بعدی هرگز Overwrite نمی‌شود.
-- coords_verified_at: آخرین زمان تأیید/اصلاح دستی نقطه توسط ادمین.
-- اجرا: از دکمه «اجرای مایگریشن‌ها» در پنل مدیریت (تکرارپذیر و بدون خطا در اجرای مجدد).
-- شماره 014: ادامهٔ مایگریشن‌های موجود (001 تا 013)؛ بدون تداخل با 020 دیدگاه‌ها.
-- =====================================================================

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'businesses'
      AND COLUMN_NAME = 'coords_source'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `businesses` ADD COLUMN `coords_source` VARCHAR(20) NULL DEFAULT NULL AFTER `lng`',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'businesses'
      AND COLUMN_NAME = 'coords_verified_at'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `businesses` ADD COLUMN `coords_verified_at` DATETIME NULL DEFAULT NULL AFTER `coords_source`',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- بازپُر کردن منشأ مختصات رکوردهای موجود از نخستین منبع ثبت‌شده (بدون دست زدن به مقادیر manual)
UPDATE businesses b
SET b.coords_source = (
    SELECT s.provider
    FROM business_sources s
    WHERE s.business_id = b.id
      AND s.provider IN ('osm', 'neshan', 'google_maps', 'gosom', 'csv', 'wikidata', 'balad')
    ORDER BY s.fetched_at ASC, s.id ASC
    LIMIT 1
)
WHERE b.lat IS NOT NULL AND b.lng IS NOT NULL AND b.coords_source IS NULL;
