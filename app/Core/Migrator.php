<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * سرویس اجرای افزایشی مایگریشن‌های SQL (قابل فراخوانی از وب، پنل ادمین، install.php و CLI)
 * بدون نیاز به دسترسی ترمینال یا SSH روی هاست اشتراکی DirectAdmin
 */
final class Migrator
{
    /**
     * اجرای تمام فایل‌های .sql داخل database/migrations که هنوز در جدول schema_migrations ثبت نشده‌اند
     *
     * @return array{ok: bool, applied: string[], skipped: string[], total_applied: int}
     */
    public static function runIncremental(): array
    {
        $pdo = DB::connection();

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `schema_migrations` (
                `migration` VARCHAR(190) NOT NULL,
                `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $executedRows = DB::fetchAll("SELECT migration FROM schema_migrations");
        $executedMap = [];
        foreach ($executedRows as $row) {
            $executedMap[(string) $row['migration']] = true;
        }

        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $migrationsDir = $base . '/database/migrations';
        $files = glob($migrationsDir . '/*.sql');
        if ($files === false) {
            $files = [];
        }
        sort($files, SORT_STRING);

        $applied = [];
        $skipped = [];

        // Migration 003 changes publication states, provider settings and jobs.
        // Never run it implicitly on an existing installation just to apply a later update.
        $legacy = '003_manual_collection_defaults.sql';
        if (!isset($executedMap[$legacy]) && is_file($migrationsDir . '/' . $legacy)) {
            $installed = is_file($base . '/storage/installed.lock');
            foreach (['businesses', 'articles'] as $table) {
                if (DB::fetchOne("SHOW TABLES LIKE '{$table}'") !== null && DB::fetchOne("SELECT id FROM `{$table}` WHERE status='published' LIMIT 1") !== null) $installed = true;
            }
            if ($installed) throw new RuntimeException('مایگریشن قدیمی 003 هنوز در سابقه ثبت نشده و می‌تواند محتوا و تنظیمات را تغییر دهد. اجرای کلی متوقف شد؛ ابتدا پشتیبان بگیرید و وضعیت آن را با متخصص بررسی کنید. برای این نسخه از دکمه به‌روزرسانی اختصاصی مقاله استفاده کنید.');
        }

        foreach ($files as $filePath) {
            $fileName = basename($filePath);
            if (isset($executedMap[$fileName])) {
                $skipped[] = $fileName;
                continue;
            }

            $sql = file_get_contents($filePath);
            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException("فایل مایگریشن خالی یا غیرقابل خواندن است: {$fileName}");
            }

            if ($fileName === '010_advertising_receipt_uniqueness.sql') self::assertNoActiveReceiptDuplicates();
            $pdo->exec($sql);
            DB::query(
                "INSERT INTO schema_migrations (migration, executed_at) VALUES (:m, NOW())",
                ['m' => $fileName]
            );
            $applied[] = $fileName;
        }

        if ($applied) Cache::flushAll();

        return [
            'ok' => true,
            'applied' => $applied,
            'skipped' => $skipped,
            'total_applied' => count($applied),
        ];
    }

    /** Apply only the additive AI/advertising/review tables, never legacy migrations. */
    public static function runFeatureUpdate(): array
    {
        $pdo = DB::connection();
        foreach (['users','businesses','business_contacts','business_photos','media','cities','categories','login_attempts'] as $table) {
            if (DB::fetchOne("SHOW TABLES LIKE '{$table}'") === null) {
                throw new RuntimeException('پیش‌نیاز دیتابیس «' . $table . '» موجود نیست. ابتدا وضعیت نسخه قبلی را بررسی کنید.');
            }
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(190) NOT NULL, executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $file = '007_ai_advertising_quality.sql';
        if (DB::fetchOne('SELECT migration FROM schema_migrations WHERE migration=:m', ['m'=>$file])) {
            return ['ok'=>true, 'applied'=>[], 'skipped'=>[$file], 'total_applied'=>0];
        }
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $sql = @file_get_contents($base . '/database/migrations/' . $file);
        if (!is_string($sql) || trim($sql) === '') throw new RuntimeException('فایل به‌روزرسانی دیتابیس یافت نشد.');
        $pdo->exec($sql);
        DB::query('INSERT IGNORE INTO schema_migrations (migration,executed_at) VALUES (:m,NOW())', ['m'=>$file]);
        Cache::flushAll();
        return ['ok'=>true, 'applied'=>[$file], 'skipped'=>[], 'total_applied'=>1];
    }

    /** Refuse to bless an already over-credited ledger without human accounting review. */
    private static function assertNoActiveReceiptDuplicates(): void
    {
        $duplicate = DB::fetchOne("SELECT reference FROM (
            SELECT reference COLLATE utf8mb4_unicode_ci AS reference FROM advertising_manual_payments WHERE status='recorded'
            UNION ALL
            SELECT verified_reference COLLATE utf8mb4_unicode_ci AS reference FROM advertising_online_payments
              WHERE mode='live' AND state IN ('verified','reconciled') AND verified_reference IS NOT NULL AND verified_reference<>''
        ) AS active_receipts GROUP BY reference HAVING COUNT(*) > 1 LIMIT 1");
        if ($duplicate) throw new FinanceMigrationConflict('رسید تکراری در سوابق مالی فعال پیدا شد؛ به‌روزرسانی متوقف شد. پیش از ادامه، با پشتیبان و بررسی حسابداری مستقل تعارض را رفع کنید.');
    }

    /** Apply only the additive banner-reporting and manual-contract tables. */
    public static function runAdvertisingUpdate(): array
    {
        $pdo = DB::connection();
        foreach (['users','advertising_banners','advertising_inquiries'] as $table) {
            if (DB::fetchOne("SHOW TABLES LIKE '{$table}'") === null) {
                throw new RuntimeException('پیش‌نیاز دیتابیس «' . $table . '» موجود نیست. ابتدا نسخه قبلی را بررسی کنید.');
            }
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(190) NOT NULL, executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $files = ['008_advertising_reporting_contracts.sql', '009_advertising_finance.sql', '010_advertising_receipt_uniqueness.sql'];
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $applied = []; $skipped = [];
        foreach ($files as $file) {
            if (DB::fetchOne('SELECT migration FROM schema_migrations WHERE migration=:m', ['m'=>$file])) {
                $skipped[] = $file;
                continue;
            }
            $sql = @file_get_contents($base . '/database/migrations/' . $file);
            if (!is_string($sql) || trim($sql) === '') throw new RuntimeException('فایل به‌روزرسانی دیتابیس یافت نشد.');
            if ($file === '010_advertising_receipt_uniqueness.sql') self::assertNoActiveReceiptDuplicates();
            $pdo->exec($sql); // Each statement is repeatable after an interrupted update.
            DB::query('INSERT IGNORE INTO schema_migrations (migration,executed_at) VALUES (:m,NOW())', ['m'=>$file]);
            $applied[] = $file;
        }
        if ($applied) Cache::flushAll();
        return ['ok'=>true, 'applied'=>$applied, 'skipped'=>$skipped, 'total_applied'=>count($applied)];
    }

    /** Apply only the non-destructive editor/URL migration, never older migrations. */
    public static function runArticleUpdate(): array
    {
        $pdo = DB::connection();
        foreach (['articles','article_items','article_faqs','businesses','cities','categories','media','review_queue','business_promotions','business_source_rating_dates'] as $table) {
            if (DB::fetchOne("SHOW TABLES LIKE '{$table}'") === null) {
                throw new RuntimeException('پیش‌نیاز دیتابیس «' . $table . '» موجود نیست. ابتدا نسخه قبلی را با پشتیبان و بررسی مایگریشن‌ها تکمیل کنید.');
            }
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(190) NOT NULL, executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $file = '006_article_editor_city_urls.sql';
        if (DB::fetchOne('SELECT migration FROM schema_migrations WHERE migration=:m', ['m'=>$file])) {
            return ['ok'=>true, 'applied'=>[], 'skipped'=>[$file], 'total_applied'=>0];
        }
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $sql = @file_get_contents($base . '/database/migrations/' . $file);
        if (!is_string($sql) || trim($sql) === '') throw new RuntimeException('فایل به‌روزرسانی دیتابیس یافت نشد.');
        $pdo->exec($sql);
        DB::query('INSERT IGNORE INTO schema_migrations (migration,executed_at) VALUES (:m,NOW())', ['m'=>$file]);
        Cache::flushAll();
        return ['ok'=>true, 'applied'=>[$file], 'skipped'=>[], 'total_applied'=>1];
    }

    /** Apply only the additive article topic-tag tables; never execute legacy migrations. */
    public static function runArticleTagsUpdate(): array
    {
        $pdo = DB::connection();
        if (DB::fetchOne("SHOW TABLES LIKE 'articles'") === null) {
            throw new RuntimeException('جدول مقاله پیدا نشد؛ ابتدا وضعیت نصب را بررسی کنید.');
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(190) NOT NULL, executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $file = '011_article_topic_tags.sql';
        if (DB::fetchOne('SELECT migration FROM schema_migrations WHERE migration=:m', ['m'=>$file])) {
            return ['ok'=>true, 'applied'=>[], 'skipped'=>[$file], 'total_applied'=>0];
        }
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $sql = @file_get_contents($base . '/database/migrations/' . $file);
        if (!is_string($sql) || trim($sql) === '') throw new RuntimeException('فایل به‌روزرسانی برچسب‌های مقاله یافت نشد.');
        $pdo->exec($sql);
        DB::query('INSERT IGNORE INTO schema_migrations (migration,executed_at) VALUES (:m,NOW())', ['m'=>$file]);
        Cache::flushAll();
        return ['ok'=>true, 'applied'=>[$file], 'skipped'=>[], 'total_applied'=>1];
    }

    /** Apply only the additive SEO tombstone/redirect rules table; never execute legacy migrations. */
    public static function runSeoUpdate(): array
    {
        $pdo = DB::connection();
        if (DB::fetchOne("SHOW TABLES LIKE 'businesses'") === null) {
            throw new RuntimeException('جدول کسب‌وکارها پیدا نشد؛ ابتدا وضعیت نصب را بررسی کنید.');
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(190) NOT NULL, executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $file = '012_seo_url_rules.sql';
        if (DB::fetchOne('SELECT migration FROM schema_migrations WHERE migration=:m', ['m'=>$file])) {
            \App\Services\BusinessSeoService::migrateFallbackRulesToTable();
            return ['ok'=>true, 'applied'=>[], 'skipped'=>[$file], 'total_applied'=>0];
        }
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $sql = @file_get_contents($base . '/database/migrations/' . $file);
        if (!is_string($sql) || trim($sql) === '') throw new RuntimeException('فایل به‌روزرسانی قوانین سئو یافت نشد.');
        $pdo->exec($sql);
        \App\Services\BusinessSeoService::migrateFallbackRulesToTable();
        DB::query('INSERT IGNORE INTO schema_migrations (migration,executed_at) VALUES (:m,NOW())', ['m'=>$file]);
        Cache::flushAll();
        return ['ok'=>true, 'applied'=>[$file], 'skipped'=>[], 'total_applied'=>1];
    }
}
