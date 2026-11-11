<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\Config;
use App\Core\DB;
use App\Core\WorkerLock;
use CollectorRemote\Providers\OsmProvider;
use CollectorRemote\Providers\NeshanProvider;
use RuntimeException;
use Throwable;

/** Runs one explicitly selected job on the shared host; failures never switch providers. */
final class CollectorRunner
{
    public static function runNext(?callable $providerFactory = null): array
    {
        // Shared with the panel, cron and stale-job recovery. A busy run is
        // skipped rather than starting another provider request.
        if (!WorkerLock::acquire('collector')) {
            return ['ran'=>false, 'status'=>'busy', 'message'=>'یک کار جمع‌آوری در حال اجراست؛ بعداً دوباره تلاش کنید.'];
        }
        try {
            CategorySyncService::ensureUnified();
            return self::runLocked($providerFactory);
        } finally {
            WorkerLock::release('collector');
        }
    }

    /** Do not process or finish a job after losing its lock or claim attempt. */
    private static function assertClaim(int $jobId, int $attempt): void
    {
        WorkerLock::assertHeld('collector');
        $current = DB::fetchOne('SELECT status,attempts FROM collector_jobs WHERE id=:id', ['id'=>$jobId]);
        if (!$current || $current['status'] !== 'running' || (int)$current['attempts'] !== $attempt) {
            throw new RuntimeException('مالکیت کار تغییر کرده است؛ نتیجه اجرا نیاز به بررسی دارد.');
        }
    }

    private static function runLocked(?callable $providerFactory): array
    {
        $pdo = DB::connection();
        $pdo->beginTransaction();
        try {
            $job = DB::fetchOne("SELECT j.*, c.name_fa AS city_name, c.slug AS city_slug, c.lat AS city_lat,
                    c.lng AS city_lng, c.bbox_json AS city_bbox, p.queries_json AS pack_queries
                FROM collector_jobs j INNER JOIN cities c ON c.id=j.city_id
                LEFT JOIN category_packs p ON p.id=j.pack_id
                INNER JOIN provider_state ps ON ps.provider=j.provider
                WHERE j.status='queued' AND j.provider IN ('osm','neshan') AND ps.enabled=1
                  AND (ps.paused_until IS NULL OR ps.paused_until < NOW())
                ORDER BY j.id ASC LIMIT 1 FOR UPDATE");
            if ($job === null) {
                $pdo->commit();
                return ['ran'=>false, 'message'=>'کار آماده‌ای برای اجرا وجود ندارد.'];
            }
            DB::query("UPDATE collector_jobs SET status='running',claimed_at=NOW(),attempts=attempts+1,error=NULL,updated_at=NOW() WHERE id=:id", ['id'=>(int)$job['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $jobId = (int) $job['id'];
        $attempt = (int)$job['attempts'] + 1;
        $providerName = (string) $job['provider'];
        $pipelineAttempted = false;
        try {
            self::assertClaim($jobId, $attempt);
            $params = json_decode((string) ($job['params_json'] ?? '{}'), true) ?: [];
            $pack = json_decode((string) ($job['pack_queries'] ?? '{}'), true) ?: [];
            $queries = !empty($params['custom_query']) ? [(string) $params['custom_query']] : ($pack[$providerName] ?? []);
            if (!$queries) throw new RuntimeException('برای این provider عبارت جستجو در بسته انتخاب‌شده تنظیم نشده است.');
            require_once BASE_PATH . '/collector-remote/Providers/ProviderInterface.php';
            require_once BASE_PATH . '/collector-remote/Providers/OsmProvider.php';
            require_once BASE_PATH . '/collector-remote/Providers/NeshanProvider.php';
            $config = (array) Config::get('providers.' . $providerName, []);
            $provider = $providerFactory ? $providerFactory($providerName, $config) : ($providerName === 'osm' ? new OsmProvider($config, 25) : new NeshanProvider($config));
            if (!$provider instanceof \CollectorRemote\Providers\ProviderInterface) throw new RuntimeException('Provider نامعتبر است.');
            $items = $provider->search(['city_name_fa'=>$job['city_name'],'city_slug'=>$job['city_slug'],
                'lat'=>$job['city_lat'],'lng'=>$job['city_lng'],'bbox'=>json_decode((string)$job['city_bbox'], true), 'queries'=>$queries]);
            self::assertClaim($jobId, $attempt);
            $linkedCat = !empty($job['pack_id'])
                ? DB::fetchOne('SELECT category_id FROM category_pack_items WHERE pack_id=:pid ORDER BY id ASC LIMIT 1', ['pid'=>(int)$job['pack_id']])
                : null;
            $targetCategoryId = (int)($linkedCat['category_id'] ?? $params['category_id'] ?? 1);
            $summary = ['found'=>count($items),'inserted'=>0,'duplicates'=>0,'pending_review'=>0,'errors'=>0,'last_error'=>null];
            foreach ($items as $item) {
                self::assertClaim($jobId, $attempt);
                try {
                    $pipelineAttempted = true;
                    $r = PipelineService::processIncomingRecord((int)$job['city_id'], $targetCategoryId, $providerName, $item, 'manual');
                    if ($r['is_new']) { $summary['inserted']++; $summary['pending_review']++; }
                    else $summary['duplicates']++;
                } catch (Throwable $e) {
                    $summary['errors']++;
                    $summary['last_error'] = $e->getMessage();
                }
            }
            self::assertClaim($jobId, $attempt);
            $status = $summary['errors'] > 0 ? 'failed' : 'done';
            if ($provider instanceof OsmProvider && $provider->possiblyTruncated()) $summary['warning'] = 'حداکثر ۲۰۰ نتیجه دریافت شد؛ ممکن است نتایج بیشتری وجود داشته باشد.';
            $finished = DB::query("UPDATE collector_jobs SET status=:status,result_json=:result,error=:error,updated_at=NOW() WHERE id=:id AND status='running' AND attempts=:attempt",
                ['status'=>$status,'result'=>json_encode($summary, JSON_UNESCAPED_UNICODE),'error'=>$summary['last_error'],'id'=>$jobId,'attempt'=>$attempt])->rowCount();
            if ($finished !== 1) throw new RuntimeException('مالکیت کار تغییر کرده است؛ نتیجه اجرا نیاز به بررسی دارد.');
            return ['ran'=>true,'job_id'=>$jobId,'status'=>$status,'summary'=>$summary,
                'message'=>$status === 'done' ? 'کار اجرا شد؛ موارد جدید منتظر بازبینی‌اند.' : 'کار با خطاهای ثبت‌شده پایان یافت.'];
        } catch (Throwable $e) {
            // Never mark a newer attempt failed or pause a provider after losing
            // the lock. If ownership is uncertain, let the caller report it.
            self::assertClaim($jobId, $attempt);
            $code = (int) $e->getCode();
            if (in_array($code, [403,429], true)) {
                DB::query("UPDATE provider_state SET paused_until=DATE_ADD(NOW(), INTERVAL 24 HOUR),last_error=:e WHERE provider=:p", ['e'=>$e->getMessage(),'p'=>$providerName]);
            }
            $failed = DB::query("UPDATE collector_jobs SET status='failed',error=:error,updated_at=NOW() WHERE id=:id AND status='running' AND attempts=:attempt", ['error'=>$e->getMessage(),'id'=>$jobId,'attempt'=>$attempt])->rowCount();
            if ($failed !== 1) throw new RuntimeException('مالکیت کار تغییر کرده است؛ نتیجه اجرا نیاز به بررسی دارد.', 0, $e);
            return ['ran'=>true,'job_id'=>$jobId,'status'=>'failed','message'=>$e->getMessage()];
        } finally {
            // Each record may commit independently, including an uncertain
            // commit before a disconnect. Never leave earlier changes cached.
            if ($pipelineAttempted) Cache::flushAll();
        }
    }
}
