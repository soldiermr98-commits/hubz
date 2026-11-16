<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\CategorySyncService;
use App\Services\CollectorRunner;
use App\Services\MediaUploadException;
use App\Services\PhotoBackfillService;
use InvalidArgumentException;
use Throwable;

final class CollectorController
{
    public static function index(Request $request): void
    {
        Auth::requireRole(['admin','editor']);
        CategorySyncService::ensureUnified();
        $jobs = DB::fetchAll(
            "SELECT j.*, c.name_fa AS city_name,
                    COALESCE(cat.name_fa, p.name) AS pack_name,
                    cat.id AS category_id,
                    cat.name_fa AS category_name
             FROM collector_jobs j
             LEFT JOIN cities c ON c.id=j.city_id
             LEFT JOIN category_packs p ON p.id=j.pack_id
             LEFT JOIN category_pack_items cpi ON cpi.pack_id=p.id
             LEFT JOIN categories cat ON cat.id=cpi.category_id
             ORDER BY j.id DESC LIMIT 100"
        );
        $providers = DB::fetchAll("SELECT * FROM provider_state WHERE provider IN ('osm','neshan') ORDER BY priority ASC");
        // S30 — آمار عکس: چند کسب‌وکار منتشرشده هنوز هیچ تصویری ندارند
        $photoStats = [
            'published_without_photo' => (int) (DB::fetchOne(
                "SELECT COUNT(*) AS n
                 FROM businesses b
                 LEFT JOIN business_photos bp ON bp.business_id = b.id AND bp.status = 'approved'
                 WHERE b.status = 'published' AND b.cover_media_id IS NULL AND bp.id IS NULL"
            )['n'] ?? 0),
            'provider_photos' => (int) (DB::fetchOne(
                "SELECT COUNT(*) AS n FROM business_photos WHERE source = 'provider' AND status = 'approved'"
            )['n'] ?? 0),
        ];
        Response::json(['ok'=>true,'jobs'=>$jobs,'providers'=>$providers,'collector_bearer_token'=>'','photo_policy'=>'provider_push','photo_stats'=>$photoStats]);
    }

    public static function createJob(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin','editor']);
        $cityId = (int)$request->input('city_id', 0);
        $categoryId = (int)$request->input('category_id', 0);
        $packId = (int)$request->input('pack_id', 0);
        $provider = strtolower(trim((string)$request->input('provider', '')));
        $customQuery = trim((string)$request->input('custom_query', ''));
        if (!in_array($provider, ['osm','neshan'], true)) {
            Response::json(['ok'=>false,'error'=>'یک منبع پشتیبانی‌شده را به‌صورت دستی انتخاب کنید.'], 422);
        }
        $city = DB::fetchOne("SELECT * FROM cities WHERE id=:id AND is_active=1", ['id'=>$cityId]);
        $resolved = CategorySyncService::resolveCategoryAndPack($categoryId, $packId);
        $state = DB::fetchOne("SELECT * FROM provider_state WHERE provider=:p", ['p'=>$provider]);
        if (!$city || !$resolved) Response::json(['ok'=>false,'error'=>'شهر یا دسته‌بندی فعال معتبر را انتخاب کنید.'], 422);
        if (!$state || !(int)$state['enabled']) Response::json(['ok'=>false,'error'=>'منبع انتخاب‌شده غیرفعال است.'], 422);
        if (!empty($state['paused_until']) && strtotime((string)$state['paused_until']) > time()) Response::json(['ok'=>false,'error'=>'منبع انتخاب‌شده موقتاً متوقف است.'], 422);
        $finalPackId = $resolved['pack_id'];
        $finalCategoryId = $resolved['category_id'];
        $params = ['city_name_fa'=>$city['name_fa'],'city_slug'=>$city['slug'],'pack_id'=>$finalPackId,
            'pack_name'=>$resolved['category_name_fa'],'category_id'=>$finalCategoryId,'custom_query'=>$customQuery];
        DB::query("INSERT INTO collector_jobs (city_id,pack_id,provider,params_json,status,attempts,created_at,updated_at) VALUES (:city,:pack,:provider,:params,'queued',0,NOW(),NOW())",
            ['city'=>$cityId,'pack'=>$finalPackId,'provider'=>$provider,'params'=>json_encode($params, JSON_UNESCAPED_UNICODE)]);
        $id = DB::lastInsertId();
        $job = DB::fetchOne(
            "SELECT j.*, c.name_fa AS city_name,
                    COALESCE(cat.name_fa, p.name) AS pack_name,
                    cat.id AS category_id,
                    cat.name_fa AS category_name
             FROM collector_jobs j
             JOIN cities c ON c.id=j.city_id
             LEFT JOIN category_packs p ON p.id=j.pack_id
             LEFT JOIN category_pack_items cpi ON cpi.pack_id=p.id
             LEFT JOIN categories cat ON cat.id=cpi.category_id
             WHERE j.id=:id",
            ['id'=>$id]
        );
        Response::json(['ok'=>true,'message'=>'کار جمع‌آوری در دسته «' . $resolved['category_name_fa'] . '» با منبع انتخاب‌شده در صف قرار گرفت.','job'=>$job]);
    }

    public static function runNext(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin','editor']);
        try {
            $result = CollectorRunner::runNext();
            Response::json(array_merge(['ok'=>($result['status'] ?? '') !== 'failed'], $result));
        } catch (\Throwable $e) {
            // A database disconnect can make the result uncertain. Do not retry
            // automatically or expose connection details to the browser.
            error_log('Collector run could not be confirmed: ' . get_class($e));
            Response::json(['ok'=>false, 'error'=>'نتیجه اجرای جمع‌آوری مشخص نیست؛ پیش از تلاش دوباره وضعیت صف را بررسی کنید.'], 500);
        }
    }

    /**
     * POST /api/v1/collector/jobs/delete (S25)
     * حذف یک رکورد Job جمع‌آوری از دیتابیس — بازگشت‌ناپذیر.
     * کسب‌وکارهایی که از این کار قبلاً ثبت/تأیید شده‌اند دست‌نخورده می‌مانند؛
     * فقط سابقهٔ خود صف (collector_jobs) پاک می‌شود.
     */
    public static function deleteJob(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin','editor']);
        $id = (int)$request->input('id', 0);
        if ($id <= 0) Response::json(['ok'=>false,'error'=>'شناسهٔ کار جمع‌آوری نامعتبر است.'], 422);
        $job = DB::fetchOne("SELECT j.id, j.status, j.provider, c.name_fa AS city_name
            FROM collector_jobs j LEFT JOIN cities c ON c.id=j.city_id WHERE j.id=:id", ['id'=>$id]);
        if (!$job) Response::json(['ok'=>false,'error'=>'این کار جمع‌آوری در دیتابیس پیدا نشد (احتمالاً قبلاً حذف شده است).'], 404);
        DB::query("DELETE FROM collector_jobs WHERE id=:id", ['id'=>$id]);
        Response::json(['ok'=>true,'message'=>'رکورد کار #'.$id.' ('.($job['city_name'] ?: 'شهر نامشخص').' · '.$job['provider'].') برای همیشه از دیتابیس حذف شد.']);
    }

    /**
     * POST /api/v1/collector/jobs/purge (S25)
     * پاک‌سازی کامل صف جمع‌آوری: همهٔ رکوردهای collector_jobs از دیتابیس حذف
     * می‌شوند و شمارندهٔ شناسه از ۱ از نو شروع می‌شود. کسب‌وکارهای ثبت‌شده
     * دست‌نخورده می‌مانند. فقط ادمین (سطح دسترسی بالاتر از حذف تکی).
     */
    public static function purgeJobs(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin']);
        $row = DB::fetchOne("SELECT COUNT(*) AS c FROM collector_jobs");
        $count = (int)($row['c'] ?? 0);
        if ($count === 0) Response::json(['ok'=>true,'deleted'=>0,'message'=>'صف جمع‌آوری از قبل خالی است؛ چیزی برای پاک‌کردن نبود.']);
        DB::query("DELETE FROM collector_jobs");
        // شمارندهٔ AUTO_INCREMENT را هم صفر می‌کنیم تا هیچ ردپایی از شناسه‌های قبلی نماند
        // (ALTER مستقیم با exec اجرا می‌شود؛ روی هاست اشتراکی با دسترسی معمولی MySQL مجاز است)
        DB::connection()->exec("ALTER TABLE `collector_jobs` AUTO_INCREMENT = 1");
        Response::json(['ok'=>true,'deleted'=>$count,'message'=>$count.' رکورد از صف جمع‌آوری برای همیشه از دیتابیس حذف شد و شناسه‌ها از ۱ دوباره شروع می‌شوند.']);
    }

    public static function updateProvider(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin']);
        $provider = (string)$request->input('provider','');
        if (!in_array($provider, ['osm','neshan'], true)) Response::json(['ok'=>false,'error'=>'منبع پشتیبانی نمی‌شود.'],422);
        $enabled = (int)$request->input('enabled',1) === 1 ? 1 : 0;
        $priority = (int)$request->input('priority',10);
        if ($request->input('clear_pause',false)) {
            DB::query("UPDATE provider_state SET enabled=:en,priority=:prio,paused_until=NULL,last_error=NULL WHERE provider=:p", ['en'=>$enabled,'prio'=>$priority,'p'=>$provider]);
        } else DB::query("UPDATE provider_state SET enabled=:en,priority=:prio WHERE provider=:p", ['en'=>$enabled,'prio'=>$priority,'p'=>$provider]);
        Response::json(['ok'=>true,'message'=>'وضعیت منبع ذخیره شد.','providers'=>DB::fetchAll("SELECT * FROM provider_state WHERE provider IN ('osm','neshan') ORDER BY priority")]);
    }

    /**
     * S30 — ثبت/تعویض توکن Bearer کولکتور (اندپوینت قبلاً غیرفعال بود)
     * POST /api/v1/collector/token — فقط ادمین + CSRF
     * توکن فقط یک‌بار در پاسخ نمایش داده می‌شود؛ باید در collector-remote/config.php کپی شود.
     */
    public static function setToken(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin']);
        $token = trim((string) $request->input('token', ''));
        if ($token === '') {
            $token = bin2hex(random_bytes(24)); // ۴۸ کاراکتر تصادفی
        }
        if (strlen($token) < 32) {
            Response::json(['ok'=>false,'error'=>'توکن باید حداقل ۳۲ کاراکتر باشد؛ یا خالی بگذارید تا خودکار ساخته شود.'], 422);
        }
        DB::query(
            "INSERT INTO settings (k, v, updated_at) VALUES ('collector_bearer_token', :v, NOW())
             ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = NOW()",
            ['v' => $token]
        );
        Response::json([
            'ok'      => true,
            'token'   => $token, // فقط همین یک‌بار
            'message' => 'توکن کولکتور ذخیره شد. همین مقدار را در collector-remote/config.php (کلید bearer_token) کپی کنید؛ دوباره نمایش داده نمی‌شود.',
        ]);
    }

    /**
     * S30 — لیست کسب‌وکارهای بی‌عکس + کاندید URL عکس از منابع جمع‌آوری‌شده
     * GET /api/v1/collector/photos/backfill?limit=50 — نشست مدیر یا توکن Bearer
     * (فقط خواندن؛ دانلود و پوش واقعی عکس روی PC مدیر انجام می‌شود)
     */
    public static function photosBackfill(Request $request): void
    {
        self::verifyTokenOrSession($request);
        $limit = max(1, min(100, (int) $request->input('limit', 50)));
        Response::json(array_merge(['ok' => true], PhotoBackfillService::candidates($limit)));
    }

    /**
     * S30 — دریافت تصویر بهینه‌شده از collector-remote و اتصال آن به کسب‌وکار
     * POST /api/v1/collector/photo (multipart) — نشست مدیر یا توکن Bearer
     *
     * طبق سیاست S2: هاست هیچ عکسی را خودش دانلود نمی‌کند؛ فقط bytes پوش‌شده را
     * با MediaService (فحش MIME واقعی + تبدیل WebP سه‌سایز) ذخیره می‌کند.
     * کاور خودکار: اگر کسب‌وکار کاور نداشت، همین تصویر کاور می‌شود.
     */
    public static function remotePhoto(Request $request): void
    {
        self::verifyTokenOrSession($request);

        $businessId = (int) $request->input('business_id', 0);
        $sourceUrl = trim((string) $request->input('source_url', ''));
        $attribution = trim((string) $request->input('attribution', ''));
        if ($businessId <= 0) {
            Response::json(['ok'=>false,'error'=>'شناسه کسب‌وکار (business_id) الزامی است.'], 422);
        }
        if ($sourceUrl !== '' && (strlen($sourceUrl) > 2048 || !preg_match('#^https://[^\s"\'<>]+$#iD', $sourceUrl))) {
            Response::json(['ok'=>false,'error'=>'source_url معتبر نیست.'], 422);
        }
        if (mb_strlen($attribution) > 255) {
            Response::json(['ok'=>false,'error'=>'attribution حداکثر ۲۵۵ کاراکتر است.'], 422);
        }

        $uploadedFile = $_FILES['image'] ?? null;
        if (empty($uploadedFile) || !is_array($uploadedFile)) {
            Response::json(['ok'=>false,'error'=>'فایل تصویر (فیلد image) در درخواست multipart نیست.'], 422);
        }

        try {
            $result = PhotoBackfillService::attachUploadedPhoto(
                $businessId,
                [
                    'error'    => UPLOAD_ERR_OK,
                    'tmp_name' => (string) $uploadedFile['tmp_name'],
                    'size'     => (int) ($uploadedFile['size'] ?? 0),
                ],
                $sourceUrl,
                $attribution,
                trim((string) $request->input('alt_text', ''))
            );
        } catch (MediaUploadException | InvalidArgumentException $e) {
            Response::json(['ok'=>false,'error'=>$e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('Collector photo push failed: ' . get_class($e));
            Response::json(['ok'=>false,'error'=>'پردازش و اتصال تصویر انجام نشد؛ دوباره تلاش کنید.'], 500);
        }

        if (!empty($result['duplicate'])) {
            Response::json([
                'ok'        => true,
                'duplicate' => true,
                'message'   => 'این عکس قبلاً برای این کسب‌وکار ثبت شده و رد شد.',
            ]);
        }

        Response::json([
            'ok'           => true,
            'duplicate'    => false,
            'media_id'     => (int) $result['media']['id'],
            'set_as_cover' => (bool) $result['set_as_cover'],
            'message'      => !empty($result['set_as_cover'])
                ? 'تصویر ذخیره و به‌عنوان کاور کسب‌وکار ثبت شد.'
                : 'تصویر به گالری کسب‌وکار اضافه شد.',
        ]);
    }

    /**
     * احراز هویت مسیرهای راه دور: نشست مدیر یا توکن Bearer (الگوی fail-closed)
     */
    private static function verifyTokenOrSession(Request $request): void
    {
        if (Auth::user() !== null) {
            return;
        }
        $bearer = $request->bearerToken();
        $tokenRow = DB::fetchOne("SELECT v FROM settings WHERE k = 'collector_bearer_token' LIMIT 1");
        $expected = $tokenRow !== null && (string) $tokenRow['v'] !== ''
            ? (string) $tokenRow['v']
            : (string) Config::get('security.collector_bearer_token', '');
        if ($bearer === null || $expected === '' || !hash_equals($expected, $bearer)) {
            Response::json(['ok'=>false,'error'=>'توکن Bearer کولکتور معتبر نیست.'], 401);
        }
    }

    public static function remoteDisabled(Request $request): void
    {
        Response::json(['ok'=>false,'error'=>'ورکر راه دور در این نسخه غیرفعال است؛ از ورکر هاست استفاده کنید.'],410);
    }
}
