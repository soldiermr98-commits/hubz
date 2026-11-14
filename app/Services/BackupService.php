<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\WorkerLock;
use RuntimeException;
use Throwable;

/**
 * S24 — بکاپ خودکار دیتابیس برای هاست اشتراکی (بدون exec/mysqldump).
 *
 * طراحی:
 * - خروجی: فایل SQL فشرده با gzip در storage/backups — قابل بازخوانی با mysql CLI یا phpMyAdmin.
 * - هر جدول در یک تراکنشِ خواندنی (SELECT) با LIMIT-چانک خوانده می‌شود تا حافظه در دیتابیس‌های بزرگ منفجر نشود.
 * - جدول‌های ناسازگار با بکاپ دیتایی (لاگ‌های خام پرحجم) به‌صورت پیش‌فرض از داده جا می‌خورند و فقط ساختارشان بکاپ می‌شود.
 * - حذف خودکار نسخه‌های قدیمی‌تر از backup_retention_days.
 * - ثبت در system_backups (مایگریشن ۰۲۲) و قفل MySQL برای جلوگیری از اجرای هم‌زمان.
 * - کل عملیات fail-safe است: خطای بکاپ نباید بقیهٔ کرون را از کار بیندازد.
 */
final class BackupService
{
    private const DIRECTORY = 'storage/backups';
    private const CHUNK = 500;
    private const MAX_ROW_BYTES = 1048576; // سطرهای بزرگ‌تر از ۱ مگابایت به‌صورت NULL#LARGE# ثبت نمی‌شوند
    /** جداولی که دادهٔ آن‌ها لاگ خام است؛ فقط ساختار بکاپ می‌شود تا فایل بکاپ قابل‌مدیریت بماند. */
    private const DATA_SKIP_TABLES = ['analytics_events', 'analytics_visitor_days', 'login_attempts', 'ai_service_logs', 'collector_http_log'];

    /** اجرای بکاپ در صورت رسیدن نوبت روزانه (مخصوص کرون). خروجی خلاصهٔ کوچک برمی‌گرداند. */
    public static function runDue(string $source = 'cron'): array
    {
        $last = DB::fetchOne("SELECT v FROM settings WHERE k = 'backup_last_run'");
        $lastRun = (string) ($last['v'] ?? '');
        if ($lastRun !== '' && strtotime($lastRun) > strtotime('-20 hours')) {
            return ['status' => 'skipped', 'last_run' => $lastRun];
        }
        return self::run($source);
    }

    /** اجرای صریح بکاپ (پنل یا CLI یا کرون). */
    public static function run(string $source = 'panel'): array
    {
        if (!in_array($source, ['cron', 'panel', 'cli'], true)) {
            throw new RuntimeException('منبع اجرای بکاپ معتبر نیست.');
        }
        if (!WorkerLock::acquire('backup')) {
            return ['status' => 'busy', 'message' => 'بکاپ دیگری در حال اجراست.'];
        }
        $started = microtime(true);
        try {
            $dir = BASE_PATH . '/' . self::DIRECTORY;
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('ساخت پوشهٔ storage/backups ممکن نشد؛ دسترسی نوشتن را بررسی کنید.');
            }
            $filename = 'hamihub-db-' . date('Y-m-d_His') . '.sql.gz';
            $target = $dir . '/' . $filename;
            $tmp = $target . '.tmp';

            $gz = gzopen($tmp, 'wb9');
            if ($gz === false) {
                throw new RuntimeException('ساخت فایل بکاپ ممکن نشد.');
            }
            $tablesCount = 0;
            $rowsCount = 0;
            try {
                gzwrite($gz, "-- HamiHub database backup\n-- created: " . date('Y-m-d H:i:s') . "\n-- host: HamiHubX.ir (shared hosting safe, no routines/triggers)\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
                $tables = DB::fetchAll('SHOW TABLES');
                foreach ($tables as $row) {
                    $table = (string) array_values($row)[0];
                    if ($table === '' || !preg_match('/^[A-Za-z0-9_$]+$/', $table)) {
                        continue; // نام جدول غیرمتعارف هرگز بدون escape به فایل نمی‌رود
                    }
                    $tablesCount++;
                    self::dumpTable($gz, $table, $rowsCount);
                }
                gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
            } finally {
                gzclose($gz);
            }

            $size = filesize($tmp);
            if ($size === false || $size < 40) {
                @unlink($tmp);
                throw new RuntimeException('فایل بکاپ خالی یا غیرقابل خواندن است.');
            }
            if (!rename($tmp, $target)) {
                @unlink($tmp);
                throw new RuntimeException('جابه‌جایی فایل بکاپ ناموفق بود.');
            }

            $durationMs = (int) round((microtime(true) - $started) * 1000);
            DB::query(
                'INSERT INTO system_backups (filename, size_bytes, tables_count, rows_count, trigger_source, status, error_message, duration_ms, created_at)
                 VALUES (:f, :s, :t, :r, :src, :st, :err, :dur, NOW())',
                ['f' => $filename, 's' => $size, 't' => $tablesCount, 'r' => $rowsCount, 'src' => $source, 'st' => 'ok', 'err' => '', 'dur' => $durationMs]
            );
            DB::query(
                "INSERT INTO settings (k, v, updated_at) VALUES ('backup_last_run', :v, NOW())
                 ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = NOW()",
                ['v' => date('Y-m-d H:i:s')]
            );
            $pruned = self::pruneOld($dir);
            return ['status' => 'ok', 'filename' => $filename, 'size_bytes' => $size,
                'tables' => $tablesCount, 'rows' => $rowsCount, 'duration_ms' => $durationMs, 'pruned_files' => $pruned];
        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $started) * 1000);
            try {
                DB::query(
                    'INSERT INTO system_backups (filename, size_bytes, tables_count, rows_count, trigger_source, status, error_message, duration_ms, created_at)
                     VALUES (:f, 0, 0, 0, :src, :st, :err, :dur, NOW())',
                    ['f' => 'backup-' . date('Y-m-d_His') . '.failed', 'src' => $source, 'st' => 'failed', 'err' => mb_substr($e->getMessage(), 0, 500), 'dur' => $durationMs]
                );
            } catch (Throwable) {
                error_log('BackupService: failed to record failure — ' . get_class($e));
            }
            error_log('HamiHub backup failed: ' . get_class($e));
            return ['status' => 'failed', 'error' => $e->getMessage(), 'duration_ms' => $durationMs];
        } finally {
            WorkerLock::release('backup');
        }
    }

    /** خواندن ساختار و دادهٔ یک جدول با چانک و نوشتن INSERTهای امن. */
    private static function dumpTable($gz, string $table, int &$rowsCount): void
    {
        $create = DB::fetchOne('SHOW CREATE TABLE `' . $table . '`');
        if ($create === null) {
            return;
        }
        $schema = (string) ($create['Create Table'] ?? '');
        gzwrite($gz, "\nDROP TABLE IF EXISTS `{$table}`;\n{$schema};\n");

        if (in_array($table, self::DATA_SKIP_TABLES, true)) {
            gzwrite($gz, "-- data skipped for {$table} (raw log table)\n");
            return;
        }

        $columns = DB::fetchAll('SHOW COLUMNS FROM `' . $table . '`');
        $colNames = array_map(static fn(array $c): string => '`' . (string) $c['Field'] . '`', $columns);
        $colList = implode(', ', $colNames);

        $offset = 0;
        while (true) {
            $rows = DB::fetchAll(
                'SELECT ' . $colList . ' FROM `' . $table . '` LIMIT ' . self::CHUNK . ' OFFSET ' . $offset
            );
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $values = [];
                $oversize = false;
                foreach ($row as $v) {
                    if ($v === null) {
                        $values[] = 'NULL';
                        continue;
                    }
                    if (is_string($v) && strlen($v) > self::MAX_ROW_BYTES) {
                        $oversize = true;
                        break;
                    }
                    $values[] = is_numeric($v) && !is_string($v)
                        ? (string) $v
                        : "'" . DB::connection()->quote(is_string($v) ? $v : (string) $v) . "'";
                }
                if ($oversize) {
                    gzwrite($gz, "-- row skipped (oversize) in {$table}\n");
                    continue;
                }
                gzwrite($gz, 'INSERT INTO `' . $table . '` (' . $colList . ') VALUES (' . implode(', ', $values) . ");\n");
                $rowsCount++;
            }
            $offset += self::CHUNK;
            if (count($rows) < self::CHUNK) {
                break;
            }
        }
    }

    /** حذف فایل‌ها و رکوردهای قدیمی‌تر از بازهٔ نگهداری. */
    private static function pruneOld(string $dir): int
    {
        $row = DB::fetchOne("SELECT v FROM settings WHERE k = 'backup_retention_days'");
        $days = max(1, min(365, (int) ($row['v'] ?? 14)));
        $cutoff = strtotime('-' . $days . ' days');
        $removed = 0;
        $files = glob($dir . '/hamihub-db-*.sql.gz') ?: [];
        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                if (@unlink($file)) {
                    $removed++;
                }
            }
        }
        try {
            DB::query(
                'DELETE FROM system_backups WHERE created_at < FROM_UNIXTIME(:cutoff) AND id NOT IN (SELECT id FROM (SELECT id FROM system_backups ORDER BY id DESC LIMIT 3) keep)',
                ['cutoff' => $cutoff]
            );
        } catch (Throwable) {
            // حذف رکوردهای قدیمی حیاتی نیست؛ خطای آن بکاپ را ناموفق نمی‌کند.
        }
        return $removed;
    }

    /** فهرست متادیتای بکاپ‌های اخیر برای پنل (بدون مسیر مطلق). */
    public static function recent(int $limit = 30): array
    {
        $rows = DB::fetchAll(
            'SELECT id, filename, size_bytes, tables_count, rows_count, trigger_source, status, duration_ms, created_at
             FROM system_backups ORDER BY id DESC LIMIT ' . max(1, min(100, $limit))
        );
        return $rows;
    }

    public static function directory(): string
    {
        return self::DIRECTORY;
    }

    public static function retentionDays(): int
    {
        $row = DB::fetchOne("SELECT v FROM settings WHERE k = 'backup_retention_days'");
        return max(1, min(365, (int) ($row['v'] ?? 14)));
    }
}
