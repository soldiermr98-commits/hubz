<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\ArticleService;
use App\Services\PipelineService;

/**
 * کنترلر صندوق بازبینی متمرکز (Review Queue) و تاریخچه تغییرات (change_log) در مرحله S3
 * مهم‌ترین صفحه پنل مدیریت:
 * - نمایش یکپارچه همه موارد نیازمند تصمیم (business, change, duplicate, article, claim, report)
 * - تایید و رد تکی و گروهی (Bulk Approve / Reject)
 * - اجرای بنچمارک ۱۰۰ رکورد نمونه با نتیجه قابل‌پیش‌بینی برای اثبات معیار قبولی مرحله S3
 */
final class ReviewQueueController
{
    /**
     * فهرست آیتم‌های صندوق بازبینی، تاریخچه تغییرات (change_log) و آمار وضعیت‌ها
     * GET /api/v1/review-queue
     */
    public static function index(Request $request): void
    {
        Auth::requireRole(['admin', 'editor']);

        $statusFilter = trim((string) ($_GET['status'] ?? 'pending'));
        $kindFilter = trim((string) ($_GET['kind'] ?? 'all'));

        $where = ["1=1"];
        $params = [];

        if (in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
            $where[] = "rq.status = :st";
            $params['st'] = $statusFilter;
        }

        if ($kindFilter !== '' && $kindFilter !== 'all') {
            $where[] = "rq.kind = :k";
            $params['k'] = $kindFilter;
        }
        $search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 120);
        if ($search !== '') {
            $where[] = "(rq.reason_code LIKE :search_reason OR rq.payload_json LIKE :search_payload OR b.name LIKE :search_name OR b.slug LIKE :search_slug OR b.address LIKE :search_address";
            $pattern = '%' . $search . '%';
            $params += ['search_reason'=>$pattern,'search_payload'=>$pattern,'search_name'=>$pattern,'search_slug'=>$pattern,'search_address'=>$pattern];
            if (ctype_digit($search)) {
                $where[ count($where)-1 ] .= ' OR rq.target_id=:search_target';
                $params['search_target'] = (int)$search;
            }
            $where[count($where)-1] .= ')';
        }

        $whereSql = implode(' AND ', $where);

        $items = DB::fetchAll(
            "SELECT rq.*,
                    b.name AS business_name,
                    b.slug AS business_slug,
                    b.address AS business_address,
                    b.internal_score AS business_score,
                    b.tier AS business_tier,
                    b.confidence AS business_confidence,
                    b.category_id AS business_category_id,
                    cat.name_fa AS category_name,
                    c.name_fa AS city_name
             FROM review_queue rq
             LEFT JOIN businesses b ON (rq.kind IN ('business', 'change', 'duplicate') AND b.id = rq.target_id)
             LEFT JOIN cities c ON c.id = b.city_id
             LEFT JOIN categories cat ON cat.id = b.category_id
             WHERE {$whereSql}
             ORDER BY rq.id DESC
             LIMIT 250",
            $params
        );

        $changeLogs = DB::fetchAll(
            "SELECT cl.*, b.name AS business_name, b.slug AS business_slug
             FROM change_log cl
             LEFT JOIN businesses b ON b.id = cl.business_id
             ORDER BY cl.id DESC
             LIMIT 100"
        );

        $countsRows = DB::fetchAll(
            "SELECT kind, COUNT(*) AS cnt
             FROM review_queue
             WHERE status = 'pending'
             GROUP BY kind"
        );
        $pendingByKind = [
            'all' => 0,
            'business' => 0,
            'change' => 0,
            'duplicate' => 0,
            'article' => 0,
            'claim' => 0,
            'report' => 0,
        ];
        foreach ($countsRows as $r) {
            $k = (string) $r['kind'];
            $c = (int) $r['cnt'];
            $pendingByKind[$k] = $c;
            $pendingByKind['all'] += $c;
        }

        Response::json([
            'ok' => true,
            'items' => $items,
            'change_logs' => $changeLogs,
            'pending_counts' => $pendingByKind,
        ]);
    }

    /**
     * تایید یا رد تکی یا گروهی آیتم‌های صندوق بازبینی
     * POST /api/v1/review-queue/decide
     */
    public static function decide(Request $request): void
    {
        Csrf::verifyOrFail($request);
        $user = Auth::requireRole(['admin']);

        $idsInput = $request->input('ids', []);
        if (!is_array($idsInput) || empty($idsInput)) {
            $singleId = (int) $request->input('id', 0);
            if ($singleId > 0) {
                $idsInput = [$singleId];
            }
        }

        $decision = (string) $request->input('decision', 'approved');
        $mergeIntoId = (int) $request->input('merge_into_id', 0);
        if (!in_array($decision, ['approved', 'rejected', 'merge'], true)) {
            Response::json(['ok' => false, 'error' => 'تصمیم ارسالی باید approved، rejected یا merge باشد.'], 422);
        }

        $cleanIds = array_values(array_filter(array_map('intval', $idsInput), static fn(int $v): bool => $v > 0));
        if (empty($cleanIds)) {
            Response::json(['ok' => false, 'error' => 'هیچ آیتمی برای تصمیم‌گیری انتخاب نشده است.'], 422);
        }

        $processedCount = 0;
        $skipped = 0;
        $conflicts = 0;
        foreach (array_unique($cleanIds) as $rqId) {
            $pdo = DB::connection();
            $pdo->beginTransaction();
            $commitAttempted = false;
            try {
                $item = DB::fetchOne("SELECT * FROM review_queue WHERE id=:id FOR UPDATE", ['id'=>$rqId]);
                if (!$item || $item['status'] !== 'pending') { $pdo->rollBack(); $skipped++; continue; }
                $kind = (string)$item['kind'];
                $targetId = (int)$item['target_id'];
                $payload = json_decode((string)($item['payload_json'] ?? '{}'), true) ?: [];
                $finalQueueStatus = $decision === 'merge' ? 'approved' : $decision;
                if ($decision === 'merge' || ($kind === 'duplicate' && $decision === 'approved')) {
                    $destId = $mergeIntoId > 0 ? $mergeIntoId : (int)($payload['primary_candidate_id'] ?? 0);
                    if ($destId <= 0 || $destId === $targetId || !in_array($kind, ['business', 'duplicate'], true)) {
                        $pdo->rollBack(); $skipped++; continue;
                    }
                    $lockedPair = DB::fetchAll("SELECT * FROM businesses WHERE id IN (:a, :b) ORDER BY id ASC FOR UPDATE", ['a'=>$targetId, 'b'=>$destId]);
                    $dupBiz = null;
                    $destBiz = null;
                    foreach ($lockedPair as $lb) {
                        if ((int)$lb['id'] === $targetId) $dupBiz = $lb;
                        if ((int)$lb['id'] === $destId) $destBiz = $lb;
                    }
                    if (!$dupBiz || !$destBiz || (int)$dupBiz['city_id'] !== (int)$destBiz['city_id'] || !in_array((string)$dupBiz['status'], ['pending_review', 'draft', 'rejected'], true)) {
                        $pdo->rollBack(); $skipped++; continue;
                    }
                    // Move non-conflicting sources & contacts from duplicate pending row into canonical business
                    $dupSources = DB::fetchAll("SELECT * FROM business_sources WHERE business_id=:id FOR UPDATE", ['id'=>$targetId]);
                    foreach ($dupSources as $ds) {
                        $alreadyOnDest = DB::fetchOne("SELECT id FROM business_sources WHERE business_id=:bid AND provider=:p AND source_id=:sid LIMIT 1",
                            ['bid'=>$destId, 'p'=>(string)$ds['provider'], 'sid'=>(string)$ds['source_id']]);
                        if ($alreadyOnDest) {
                            self::deleteIfTableExists('business_source_rating_dates', 'business_source_id=:sid', ['sid'=>(int)$ds['id']]);
                            DB::query("DELETE FROM business_sources WHERE id=:id", ['id'=>(int)$ds['id']]);
                        } else {
                            DB::query("UPDATE business_sources SET business_id=:dest WHERE id=:id", ['dest'=>$destId, 'id'=>(int)$ds['id']]);
                        }
                    }
                    $dupContacts = DB::fetchAll("SELECT * FROM business_contacts WHERE business_id=:id", ['id'=>$targetId]);
                    foreach ($dupContacts as $dc) {
                        $existsContact = DB::fetchOne("SELECT id FROM business_contacts WHERE business_id=:bid AND value=:v LIMIT 1",
                            ['bid'=>$destId, 'v'=>(string)$dc['value']]);
                        if (!$existsContact) {
                            DB::query("UPDATE business_contacts SET business_id=:dest, is_primary=0 WHERE id=:id", ['dest'=>$destId, 'id'=>(int)$dc['id']]);
                        } else {
                            DB::query("DELETE FROM business_contacts WHERE id=:id", ['id'=>(int)$dc['id']]);
                        }
                    }
                    DB::query("UPDATE businesses SET last_collected_at=NOW(), updated_at=NOW() WHERE id=:id", ['id'=>$destId]);
                    DB::query("UPDATE review_queue SET status='approved', decided_by=:uid, decided_at=NOW() WHERE target_id=:tid AND kind IN ('business','duplicate','change') AND status='pending'",
                        ['uid'=>(int)$user['id'], 'tid'=>$targetId]);
                    self::deleteIfTableExists('business_promotions', 'business_id=:tid', ['tid'=>$targetId]);
                    self::deleteIfTableExists('business_data_reviews', 'business_id=:tid', ['tid'=>$targetId]);
                    self::deleteIfTableExists('business_url_aliases', 'business_id=:tid', ['tid'=>$targetId]);
                    if (self::tableExists('seo_url_rules')) {
                        DB::query("UPDATE seo_url_rules SET target_business_id=NULL WHERE target_business_id=:tid", ['tid'=>$targetId]);
                    }
                    DB::query("DELETE FROM article_items WHERE business_id=:tid", ['tid'=>$targetId]);
                    DB::query("DELETE FROM change_log WHERE business_id=:tid", ['tid'=>$targetId]);
                    DB::query("DELETE FROM claims WHERE business_id=:tid", ['tid'=>$targetId]);
                    DB::query("DELETE FROM reports WHERE business_id=:tid", ['tid'=>$targetId]);
                    DB::query("DELETE FROM ai_tasks WHERE type='business_text' AND target_id=:tid", ['tid'=>$targetId]);
                    DB::query("DELETE FROM events_daily WHERE business_id=:tid", ['tid'=>$targetId]);
                    DB::query("DELETE FROM business_photos WHERE business_id=:tid", ['tid'=>$targetId]);
                    DB::query("DELETE FROM businesses WHERE id=:tid", ['tid'=>$targetId]);
                } elseif ($kind === 'duplicate' && $decision === 'rejected') {
                    // Reviewer marked borderline duplicate warning as distinct ("مجزا است") — keep business intact
                } elseif ($kind === 'business') {
                    $target = DB::fetchOne("SELECT status FROM businesses WHERE id=:id FOR UPDATE", ['id'=>$targetId]);
                    if (!$target || $target['status'] !== 'pending_review') { $pdo->rollBack(); $skipped++; continue; }
                    DB::query("UPDATE businesses SET status=:status,publish_reason=:reason,last_verified_at=NOW(),updated_at=NOW() WHERE id=:id", ['status'=>$decision === 'approved'?'published':'rejected','reason'=>$decision . '_in_review_queue','id'=>$targetId]);
                    if ($decision === 'rejected') {
                        DB::query("UPDATE review_queue SET status='rejected',decided_by=:uid,decided_at=NOW() WHERE target_id=:tid AND kind='duplicate' AND status='pending'", ['uid'=>(int)$user['id'],'tid'=>$targetId]);
                    }
                } elseif ($kind === 'article') {
                    $result = ArticleService::decideReview($targetId, $decision);
                    if ($result !== 'applied') {
                        $pdo->rollBack();
                        $skipped++;
                        if ($result === 'conflict') $conflicts++;
                        continue;
                    }
                } elseif ($kind === 'change') {
                    $field = (string)($payload['field'] ?? '');
                    $new = (string)($payload['new_value'] ?? '');
                    $old = (string)($payload['old_value'] ?? '');
                    $target = DB::fetchOne("SELECT * FROM businesses WHERE id=:id FOR UPDATE", ['id'=>$targetId]);
                    if (!$target || !in_array($field, ['address','name','hours','coordinates','status','phone'], true) || $new === '') { $pdo->rollBack(); $skipped++; continue; }
                    $phoneRow = null;
                    if ($field === 'phone') {
                        $phoneRow = DB::fetchOne("SELECT id,value FROM business_contacts WHERE business_id=:id AND type IN ('phone','mobile') ORDER BY is_primary DESC,id ASC LIMIT 1 FOR UPDATE", ['id'=>$targetId]);
                        $current = (string)($phoneRow['value'] ?? '');
                    } elseif ($field === 'coordinates') $current = ($target['lat'] ?? '') . ',' . ($target['lng'] ?? '');
                    else $current = (string)($target[$field] ?? '');
                    // Never apply an old proposal over a newer manual or approved value.
                    if ($decision === 'approved' && $current !== $old) { $pdo->rollBack(); $skipped++; continue; }
                    if ($decision === 'approved') {
                        if ($field === 'name') DB::query("UPDATE businesses SET name=:v,normalized_name=:n,updated_at=NOW() WHERE id=:id", ['v'=>$new,'n'=>PipelineService::canonicalNameKey($new),'id'=>$targetId]);
                        elseif (in_array($field, ['address','hours'], true)) DB::query("UPDATE businesses SET {$field}=:v,updated_at=NOW() WHERE id=:id", ['v'=>$new,'id'=>$targetId]);
                        elseif ($field === 'coordinates') {
                            $coords=explode(',', $new);
                            if (count($coords)!==2 || !is_numeric($coords[0]) || !is_numeric($coords[1]) || abs((float)$coords[0])>90 || abs((float)$coords[1])>180) { $pdo->rollBack(); $skipped++; continue; }
                            // S17: قفل مختصات دستی ادمین — پیشنهاد ایمپورت هرگز روی نقطهٔ manual اعمال نمی‌شود
                            if (($target['coords_source'] ?? null) === 'manual') { $pdo->rollBack(); $skipped++; continue; }
                            $srcProvider = (string)($payload['source'] ?? '');
                            $coordsSource = \App\Services\MapLinks::sanitizeProvider($srcProvider);
                            DB::query("UPDATE businesses SET lat=:lat,lng=:lng,coords_source=COALESCE(:src,coords_source),updated_at=NOW() WHERE id=:id", ['lat'=>(float)$coords[0],'lng'=>(float)$coords[1],'src'=>$coordsSource,'id'=>$targetId]);
                        } elseif ($field === 'status') {
                            if ($new !== 'hidden') { $pdo->rollBack(); $skipped++; continue; }
                            DB::query("UPDATE businesses SET status='hidden',updated_at=NOW() WHERE id=:id", ['id'=>$targetId]);
                        } elseif ($field === 'phone') {
                            $phone=\App\Services\SlugService::normalizePhone($new);
                            if ($phone === '') { $pdo->rollBack(); $skipped++; continue; }
                            $type = str_starts_with($phone, '+989') ? 'mobile' : 'phone';
                            if ($phoneRow) DB::query("UPDATE business_contacts SET value=:v,type=:type,source='manual',confidence='high',last_verified_at=NOW() WHERE id=:id", ['v'=>$phone,'type'=>$type,'id'=>(int)$phoneRow['id']]);
                            else DB::query("INSERT INTO business_contacts (business_id,type,value,is_primary,source,confidence,last_verified_at) VALUES (:id,:type,:v,1,'manual','high',NOW())", ['id'=>$targetId,'type'=>$type,'v'=>$phone]);
                        }
                    }
                    DB::query("UPDATE change_log SET status=:status WHERE id=:id AND business_id=:bid AND status='pending'", ['status'=>$decision,'id'=>(int)($payload['change_id'] ?? 0),'bid'=>$targetId]);
                } elseif ($kind === 'claim') DB::query("UPDATE claims SET status=:status WHERE id=:id AND status='pending'", ['status'=>$decision,'id'=>$targetId]);
                elseif ($kind === 'report') DB::query("UPDATE reports SET status=:status WHERE id=:id AND status='pending'", ['status'=>$decision==='approved'?'resolved':'rejected','id'=>$targetId]);
                else { $pdo->rollBack(); $skipped++; continue; }
                DB::query("UPDATE review_queue SET status=:status,decided_by=:uid,decided_at=NOW() WHERE id=:id", ['status'=>$finalQueueStatus,'uid'=>(int)$user['id'],'id'=>$rqId]);
                // The connection can fail while COMMIT's outcome is unknown.
                // Invalidate even then, and before processing the next item.
                $commitAttempted = true;
                $pdo->commit();
                $processedCount++;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            } finally {
                if ($commitAttempted) Cache::flushAll();
            }
        }

        $actionLabel = $decision === 'merge' ? 'ادغام در کسب‌وکار اصلی' : ($decision === 'approved' ? 'تأیید' : 'رد');
        Response::json([
            'ok' => true,
            'processed_count' => $processedCount,
            'skipped_count' => $skipped,
            'conflict_count' => $conflicts,
            'decision' => $decision,
            'message' => sprintf(
                '%d مورد «%s» شد؛ %d مورد قبلاً بررسی شده یا تغییر کرده و اعمال نشد.%s',
                $processedCount,
                $actionLabel,
                $skipped,
                $conflicts ? ' نسخه منتشرشده یا نشانی بعضی مقالات تغییر کرده است؛ ابتدا نسخه‌ها را بررسی کنید.' : ''
            ),
        ]);
    }

    /**
     * اجرای بنچمارک ۱۰۰ رکورد نمونه با نتیجه قابل‌پیش‌بینی (معیار قبولی مرحله S3)
     * POST /api/v1/pipeline/benchmark-100
     */
    public static function runBenchmark100(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin']);
        Response::json(['ok' => false, 'error' => 'بنچمارک داده‌ساز در محیط عملیاتی غیرفعال است.'], 410);
    }

    private static function tableExists(string $table): bool
    {
        // DB::tableExists falls back to SHOW TABLES on hosts that hide information_schema.
        return DB::tableExists($table);
    }

    private static function deleteIfTableExists(string $table, string $whereSql, array $params): void
    {
        if (self::tableExists($table)) {
            DB::query("DELETE FROM {$table} WHERE {$whereSql}", $params);
        }
    }
}
