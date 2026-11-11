<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\DB;
use App\Core\WorkerLock;
use Throwable;

final class MaintenanceService
{
    public static function run(): array
    {
        if (!WorkerLock::acquire('maintenance')) {
            return ['status'=>'busy', 'message'=>'نگهداری دیگری در حال اجراست؛ بعداً دوباره تلاش کنید.'];
        }
        try {
            return self::runLocked();
        } finally {
            WorkerLock::release('maintenance');
        }
    }

    private static function runLocked(): array
    {
        $now = date('Y-m-d H:i:s');
        $last = DB::fetchOne("SELECT v FROM settings WHERE k = 'sponsor_last_check'");
        $previous = (string) ($last['v'] ?? '2000-01-01 00:00:00');
        $transitions = DB::fetchAll(
            "SELECT id FROM article_items WHERE is_sponsored = 1 AND
              ((sponsor_start IS NOT NULL AND sponsor_start > :prev1 AND sponsor_start <= :now1)
              OR (sponsor_end IS NOT NULL AND sponsor_end > :prev2 AND sponsor_end <= :now2))",
            ['prev1' => $previous, 'now1' => $now, 'prev2' => $previous, 'now2' => $now]
        );
        $flushed = count($transitions) ? Cache::flushAll() : 0;
        // Retry this interval on the next maintenance run if invalidation failed.
        if ($flushed >= 0) {
            DB::query("INSERT INTO settings (k, v, updated_at) VALUES ('sponsor_last_check', :val, NOW()) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = NOW()", ['val' => $now]);
        }
        $released = 0;
        $recoverySkipped = !WorkerLock::acquire('collector');
        if (!$recoverySkipped) {
            try {
                // A long-running collector holds this same lock, so it cannot be
                // requeued merely because its claimed_at is over 15 minutes old.
                $released = DB::query("UPDATE collector_jobs SET status = 'queued', claimed_at = NULL, error = 'مهلت اجرا به پایان رسید', updated_at = NOW() WHERE status IN ('claimed','running') AND claimed_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND attempts < 3")->rowCount();
                DB::query("UPDATE collector_jobs SET status = 'failed', error = 'تعداد تلاش‌ها به حد مجاز رسید', updated_at = NOW() WHERE status IN ('claimed','running') AND claimed_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND attempts >= 3");
            } finally {
                WorkerLock::release('collector');
            }
        }
        $ai = DB::query("UPDATE ai_tasks SET status = 'queued', updated_at = NOW() WHERE status = 'claimed' AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->rowCount();
        $logins = DB::query("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)")->rowCount();
        // S9: پاک‌سازی خودکار لاگ جزئیات آمار و لاگ سرویس‌های هوش مصنوعی (حداکثر هر ساعت یک‌بار).
        $analyticsPrune = ['status' => 'skipped'];
        if (AnalyticsService::pruneDue()) {
            try {
                $analyticsPrune = AnalyticsService::prune();
            } catch (Throwable $e) {
                error_log('Analytics prune failed: ' . get_class($e));
                $analyticsPrune = ['status' => 'error'];
            }
        }
        return ['status'=>$flushed < 0 ? 'cache_error' : 'ok', 'checked_at' => $now, 'sponsor_transitions' => count($transitions), 'flushed_files' => $flushed,
            'released_jobs' => $released, 'collector_recovery_skipped'=>$recoverySkipped,
            'released_ai_tasks' => $ai, 'cleaned_logins' => $logins, 'analytics_prune' => $analyticsPrune];
    }
}
