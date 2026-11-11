<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\AdvertisingService;
use App\Services\AdminMailService;
use App\Services\AdvertisingReportingService;
use App\Services\AnalyticsService;
use App\Services\MediaLibraryService;
use App\Services\Normalizer;
use DateTimeImmutable;
use Throwable;

final class AdvertisingController
{
    private static function text(Request $request, string $key): string
    {
        $value = $request->input($key, '');
        return is_string($value) ? trim($value) : '';
    }

    private static function requireSchema(): void
    {
        if (!AdvertisingService::schemaReady()) Response::json(['ok'=>false, 'error'=>'ابتدا به‌روزرسانی اختصاصی بخش‌های جدید را اجرا کنید.'], 409);
    }

    public static function index(Request $request): void
    {
        Auth::requireRole(['admin']);
        header('Cache-Control: private, no-store');
        $ready = AdvertisingService::schemaReady();
        $reportingReady = $ready && AdvertisingReportingService::schemaReady();
        $banners = [];
        if ($ready) {
            $stats = $reportingReady ? "COALESCE(d.views,0) AS views_total, COALESCE(d.clicks,0) AS clicks_total,
                COALESCE(d.views_30,0) AS views_30, COALESCE(d.clicks_30,0) AS clicks_30" :
                'NULL AS views_total,NULL AS clicks_total,NULL AS views_30,NULL AS clicks_30';
            $join = $reportingReady ? "LEFT JOIN (SELECT banner_id,SUM(views) AS views,SUM(clicks) AS clicks,
                SUM(CASE WHEN day>=DATE_SUB(CURDATE(),INTERVAL 29 DAY) THEN views ELSE 0 END) AS views_30,
                SUM(CASE WHEN day>=DATE_SUB(CURDATE(),INTERVAL 29 DAY) THEN clicks ELSE 0 END) AS clicks_30
                FROM advertising_banner_daily GROUP BY banner_id) d ON d.banner_id=a.id" : '';
            $banners = DB::fetchAll("SELECT a.*,m.file_path_480 AS image_url,{$stats} FROM advertising_banners a LEFT JOIN media m ON m.id=a.media_id {$join} ORDER BY a.updated_at DESC,a.id DESC LIMIT 200");
        }
        $inquiries = $ready ? DB::fetchAll('SELECT id,name,phone,message,status,created_at FROM advertising_inquiries ORDER BY (status=\'new\') DESC,id DESC LIMIT 100') : [];
        Response::json(['ok'=>true, 'schema_ready'=>$ready, 'reporting_schema_ready'=>$reportingReady, 'placements'=>AdvertisingService::PLACEMENTS, 'banners'=>$banners, 'inquiries'=>$inquiries]);
    }

    private static function parseDate(string $value): ?string
    {
        if ($value === '') return null;
        $formats = ['Y-m-d\\TH:i', 'Y-m-d H:i:s'];
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date && $date->format($format) === $value) return $date->format('Y-m-d H:i:s');
        }
        Response::json(['ok'=>false, 'error'=>'زمان شروع یا پایان معتبر نیست.'], 422);
    }

    public static function saveBanner(Request $request): void
    {
        Auth::requireRole(['admin']); Csrf::verifyOrFail($request); self::requireSchema();
        $id = (int)$request->input('id', 0);
        $title = self::text($request, 'title'); $alt = self::text($request, 'alt_text');
        $target = self::text($request, 'target_url'); $slot = self::text($request, 'placement');
        $mediaId = (int)$request->input('media_id', 0);
        $cityId = (int)$request->input('city_id', 0); $categoryId = (int)$request->input('category_id', 0);
        $priority = (int)$request->input('priority', 0); $active = $request->input('active', false);
        if ($title === '' || mb_strlen($title) > 150 || $alt === '' || mb_strlen($alt) > 255 || !isset(AdvertisingService::PLACEMENTS[$slot])
            || !AdvertisingService::validTarget($target) || !is_bool($active) || $priority < -100 || $priority > 100) {
            Response::json(['ok'=>false, 'error'=>'عنوان، متن جایگزین، جایگاه یا نشانی بنر معتبر نیست. نشانی باید HTTPS یا مسیر داخلی باشد.'], 422);
        }
        $media = DB::fetchOne('SELECT file_path_1200 FROM media WHERE id=:id', ['id'=>$mediaId]);
        if (!$media || !AdvertisingService::validImagePath((string)$media['file_path_1200']) || !is_file(BASE_PATH . '/public_html' . $media['file_path_1200'])) {
            Response::json(['ok'=>false, 'error'=>'تصویر معتبر در هاست پیدا نشد؛ تصویر بنر را دوباره آپلود کنید.'], 422);
        }
        if ($cityId > 0 && !DB::fetchOne('SELECT id FROM cities WHERE id=:id', ['id'=>$cityId])) Response::json(['ok'=>false, 'error'=>'شهر انتخاب‌شده یافت نشد.'], 422);
        if ($categoryId > 0 && !DB::fetchOne('SELECT id FROM categories WHERE id=:id', ['id'=>$categoryId])) Response::json(['ok'=>false, 'error'=>'دسته انتخاب‌شده یافت نشد.'], 422);
        if (in_array($slot, AdvertisingService::HOME_ONLY_SLOTS, true) && ($cityId > 0 || $categoryId > 0)) Response::json(['ok'=>false, 'error'=>'برای جایگاه‌های صفحه اصلی فیلتر شهر و دسته را خالی بگذارید.'], 422);
        if ($slot === 'city' && $categoryId > 0) Response::json(['ok'=>false, 'error'=>'صفحه شهر دسته مشخصی ندارد؛ فیلتر دسته را بردارید.'], 422);
        $start = self::parseDate(self::text($request, 'starts_at'));
        $end = self::parseDate(self::text($request, 'ends_at'));
        if ($start !== null && $end !== null && $start >= $end) Response::json(['ok'=>false, 'error'=>'پایان باید بعد از شروع باشد.'], 422);
        $oldMediaId = 0;
        if ($id > 0) {
            $existing = DB::fetchOne('SELECT media_id FROM advertising_banners WHERE id=:id', ['id'=>$id]);
            if (!$existing) Response::json(['ok'=>false, 'error'=>'بنر موردنظر یافت نشد؛ فهرست را تازه‌سازی کنید.'], 404);
            $oldMediaId = (int)($existing['media_id'] ?? 0);
        }
        $params = ['title'=>$title,'alt'=>$alt,'media'=>$mediaId,'url'=>$target,'slot'=>$slot,
            'city'=>$cityId > 0 ? $cityId : null,'category'=>$categoryId > 0 ? $categoryId : null,
            'starts'=>$start,'ends'=>$end,'active'=>$active ? 1 : 0,'priority'=>$priority];
        if ($id > 0) {
            $params += ['id'=>$id,'version'=>(int)$request->input('version', 0)];
            $changed = DB::query('UPDATE advertising_banners SET title=:title,alt_text=:alt,media_id=:media,target_url=:url,placement=:slot,city_id=:city,category_id=:category,starts_at=:starts,ends_at=:ends,active=:active,priority=:priority,version=version+1,updated_at=NOW() WHERE id=:id AND version=:version', $params)->rowCount();
            if ($changed !== 1) Response::json(['ok'=>false, 'error'=>'این بنر تغییر کرده یا حذف شده است؛ فهرست را تازه‌سازی و دوباره بررسی کنید.'], 409);
        } else {
            DB::query('INSERT INTO advertising_banners (title,alt_text,media_id,target_url,placement,city_id,category_id,starts_at,ends_at,active,priority) VALUES (:title,:alt,:media,:url,:slot,:city,:category,:starts,:ends,:active,:priority)', $params);
            $id = DB::lastInsertId();
        }
        Cache::flushAll();
        // پاک‌سازی خودکار تصویر قبلی هنگام تعویض تصویر بنر تا هیچ فایل بی‌استفاده‌ای روی هاست باقی نماند.
        $cleanupNote = '';
        if ($oldMediaId > 0 && $oldMediaId !== $mediaId) {
            $cleanupNote = self::cleanupUnreferencedBannerMedia($oldMediaId);
        }
        Response::json(['ok'=>true, 'id'=>$id, 'message'=>'بنر ذخیره شد؛ فقط در صورت فعال بودن و رسیدن زمان، نمایش داده می‌شود.' . ($cleanupNote !== '' ? ' ' . $cleanupNote : '')]);
    }

    /**
     * حذف کامل تصویر بنر (رکورد دیتابیس + فایل‌های فیزیکی روی هاست) وقتی هیچ بنر دیگری به آن متصل نیست.
     * اگر تصویر در کسب‌وکار/مقاله دیگری استفاده شده باشد، حذف نمی‌شود (محافظت پیش‌فرض کتابخانه رسانه).
     */
    private static function cleanupUnreferencedBannerMedia(int $mediaId): string
    {
        if ($mediaId <= 0) return '';
        try {
            $stillLinked = DB::fetchOne(
                'SELECT id FROM advertising_banners WHERE media_id=:m LIMIT 1',
                ['m'=>$mediaId]
            );
            if ($stillLinked) return '';
            $res = MediaLibraryService::deleteMediaItems([$mediaId], false);
            $deletedCount = (int)($res['deleted_count'] ?? 0);
            if ($deletedCount > 0) {
                return sprintf('تصویر قبلی بنر نیز به‌طور کامل حذف شد (%d فایل از هاست پاک شد).', (int)($res['deleted_files_count'] ?? 0));
            }
            if (!empty($res['blocked'])) return 'تصویر قبلی بنر در کسب‌وکار یا مقاله‌ای استفاده می‌شود و در کتابخانه رسانه باقی ماند.';
            return '';
        } catch (Throwable $e) {
            error_log('Banner media cleanup failed: ' . get_class($e) . ': ' . $e->getMessage());
            return '';
        }
    }

    public static function deleteBanner(Request $request): void
    {
        Auth::requireRole(['admin']); Csrf::verifyOrFail($request); self::requireSchema();
        $id = (int)$request->input('id', 0);
        $version = (int)$request->input('version', 0);
        $banner = DB::fetchOne('SELECT id, media_id FROM advertising_banners WHERE id=:id AND version=:version', ['id'=>$id, 'version'=>$version]);
        if (!$banner) Response::json(['ok'=>false, 'error'=>'بنر تغییر کرده یا یافت نشد؛ فهرست را تازه‌سازی کنید.'], 409);
        $pdo = DB::connection();
        $pdo->beginTransaction();
        try {
            $deleted = DB::query('DELETE FROM advertising_banners WHERE id=:id AND version=:version', ['id'=>$id,'version'=>$version])->rowCount();
            if ($deleted !== 1) {
                $pdo->rollBack();
                Response::json(['ok'=>false, 'error'=>'بنر تغییر کرده یا یافت نشد؛ فهرست را تازه‌سازی کنید.'], 409);
            }
            if (AdvertisingReportingService::schemaReady()) DB::query('DELETE FROM advertising_banner_daily WHERE banner_id=:id', ['id'=>$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        Cache::flushAll();
        // حذف کامل اثر تصویر: رکورد media و همه فایل‌های فیزیکی آن از هاست پاک می‌شوند.
        $mediaNote = '';
        $bannerMediaId = (int)($banner['media_id'] ?? 0);
        if ($bannerMediaId > 0) {
            $mediaNote = self::cleanupUnreferencedBannerMedia($bannerMediaId);
        }
        Response::json(['ok'=>true, 'message'=>$mediaNote !== ''
            ? 'بنر و آمار آن حذف شد. ' . $mediaNote
            : 'بنر و آمار آن حذف شد. تصویر رسانه در جای دیگری استفاده می‌شود یا فاقد تصویر معتبر بود.']);
    }

    /** Public best-effort aggregate; intentionally no personal data or per-event log. */
    public static function trackBanner(Request $request): void
    {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1024 || strlen($request->rawBody()) > 1024) Response::noContent(413);
        $id = $request->input('banner_id');
        $event = $request->input('event');
        if ((!is_int($id) && !(is_string($id) && preg_match('/^[1-9][0-9]{0,9}$/', $id)))
            || (int)$id < 1 || !is_string($event) || !in_array($event, ['view','click'], true)) Response::noContent(422);
        // Per-session ceiling limits accidental loops without storing IPs. It is
        // not bot/fraud protection and these counts are never billing evidence.
        Auth::startSessionIfNeeded();
        $period = (int)floor(time() / 600);
        $counter = $_SESSION['ad_counter_limit'] ?? ['period'=>$period,'count'=>0];
        if (!is_array($counter) || (int)($counter['period'] ?? 0) !== $period) $counter = ['period'=>$period,'count'=>0];
        $counter['count'] = (int)($counter['count'] ?? 0) + 1;
        $_SESSION['ad_counter_limit'] = $counter;
        session_write_close();
        if ($counter['count'] > 300) Response::noContent(429);
        try {
            AdvertisingReportingService::countBanner((int)$id, $event);
            // S9: ثبت بنر در ماژول جامع آمار (نمایش → views، کلیک → clicks_banner) — بدون وقفه در پاسخ عمومی.
            AnalyticsService::record($event === 'view' ? 'view' : 'banner', ['banner_id' => (int)$id] + AnalyticsService::requestContext($request));
        }
        catch (Throwable $e) { /* Optional schema or database unavailable: public pages remain usable. */ }
        Response::noContent();
    }

    public static function submitInquiry(Request $request): void
    {
        Csrf::verifyOrFail($request); self::requireSchema();
        if (self::text($request, 'website_hp') !== '') Response::json(['ok'=>false, 'error'=>'درخواست پذیرفته نشد.'], 422);
        if (Auth::isRateLimited('@public-advertising', $request->ip())) Response::json(['ok'=>false, 'error'=>'تعداد درخواست‌ها بیش از حد مجاز است؛ بعداً تلاش کنید.'], 429);
        Auth::recordAttempt('@public-advertising', $request->ip(), false);
        // S19: سوال امنیتی امضاشده (همان سازوکار دیدگاه‌ها) — سپر دوم ضد اسپم پس از honeypot و محدودیت نرخ
        if (!\App\Services\CommentService::verifyQuestion(
            (string)$request->input('hx_a', ''), (string)$request->input('hx_b', ''),
            (string)$request->input('hx_ts', ''), (string)$request->input('hx_sig', ''),
            (string)$request->input('answer', '')
        )) {
            Response::json(['ok'=>false, 'error'=>'پاسخ سوال امنیتی درست نیست یا منقضی شده است؛ فرم را ببندید و دوباره باز کنید.'], 422);
        }
        $name = Normalizer::cleanPersianText(self::text($request, 'name'));
        $phone = Normalizer::normalizePhone(self::text($request, 'phone'));
        $message = Normalizer::cleanPersianText(self::text($request, 'message'));
        $consent = $request->input('consent');
        if ($name === '' || mb_strlen($name) > 150 || $phone === '' || mb_strlen($message) > 1000 || !in_array($consent, [true, 'on', '1'], true)) {
            Response::json(['ok'=>false, 'error'=>'نام، شماره تماس معتبر و رضایت برای ثبت درخواست الزامی است.'], 422);
        }
        DB::query('INSERT INTO advertising_inquiries (name,phone,message,status,created_at) VALUES (:name,:phone,:message,\'new\',NOW())',
            ['name'=>$name, 'phone'=>$phone, 'message'=>$message !== '' ? $message : null]);
        AdminMailService::notify('advertising');
        Response::json(['ok'=>true, 'message'=>'درخواست تبلیغات ثبت شد و در پنل مدیر قابل بررسی است.']);
    }

    public static function updateInquiry(Request $request): void
    {
        Auth::requireRole(['admin']); Csrf::verifyOrFail($request); self::requireSchema();
        $id = (int)$request->input('id', 0); $op = self::text($request, 'operation');
        if ($op === 'delete') $n = DB::query('DELETE FROM advertising_inquiries WHERE id=:id', ['id'=>$id])->rowCount();
        elseif (in_array($op, ['new','handled'], true)) $n = DB::query('UPDATE advertising_inquiries SET status=:status,updated_at=NOW() WHERE id=:id', ['status'=>$op,'id'=>$id])->rowCount();
        else Response::json(['ok'=>false, 'error'=>'عملیات نامعتبر است.'], 422);
        // UPDATE may be a no-op if the requested status was already set.
        if (!$n && !DB::fetchOne('SELECT id FROM advertising_inquiries WHERE id=:id', ['id'=>$id]) && $op !== 'delete') Response::json(['ok'=>false, 'error'=>'درخواست پیدا نشد.'], 404);
        Response::json(['ok'=>true, 'message'=>$op === 'delete' ? 'درخواست حذف شد.' : 'وضعیت درخواست ثبت شد.']);
    }
}
