<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Cache;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\BusinessUrlService;
use App\Services\SlugService;

/**
 * کنترلر مدیریت شهرها و محله‌های استان گیلان (بخش ۷ و ۸ مشخصات)
 * ویژگی کلیدی UX: ساخت شهر جدید فقط با یک فیلد «نام فارسی» + پیشنهاد خودکار اسلاگ و مختصات
 */
final class CityController
{
    /**
     * مختصات و محدوده پیش‌فرض شهرهای استان گیلان برای پر کردن فوری هنگام افزودن شهر با یک نام
     */
    private const GILAN_CITIES_PRESETS = [
        'رشت' => ['lat' => 37.280834, 'lng' => 49.583057, 'bbox' => [37.1900, 49.4800, 37.3600, 49.6800]],
        'لاهیجان' => ['lat' => 37.207085, 'lng' => 50.003868, 'bbox' => [37.1600, 49.9500, 37.2500, 50.0600]],
        'بندر انزلی' => ['lat' => 37.472725, 'lng' => 49.462223, 'bbox' => [37.4300, 49.3600, 37.5100, 49.5400]],
        'انزلی' => ['lat' => 37.472725, 'lng' => 49.462223, 'bbox' => [37.4300, 49.3600, 37.5100, 49.5400]],
        'لنگرود' => ['lat' => 37.196944, 'lng' => 50.153611, 'bbox' => [37.1600, 50.1100, 37.2300, 50.1900]],
        'تالش' => ['lat' => 37.799167, 'lng' => 48.905278, 'bbox' => [37.7500, 48.8500, 37.8400, 48.9500]],
        'آستارا' => ['lat' => 38.429167, 'lng' => 48.871944, 'bbox' => [38.3900, 48.8300, 38.4600, 48.9100]],
        'فومن' => ['lat' => 37.223889, 'lng' => 49.312500, 'bbox' => [37.1800, 49.2700, 37.2600, 49.3500]],
        'ماسال' => ['lat' => 37.363056, 'lng' => 49.132778, 'bbox' => [37.3200, 49.0800, 37.4000, 49.1800]],
        'صومعه‌سرا' => ['lat' => 37.301667, 'lng' => 49.315000, 'bbox' => [37.2700, 49.2800, 37.3400, 49.3500]],
        'آستانه اشرفیه' => ['lat' => 37.259722, 'lng' => 49.944444, 'bbox' => [37.2200, 49.9000, 37.2900, 49.9800]],
        'رودسر' => ['lat' => 37.137500, 'lng' => 50.288056, 'bbox' => [37.1000, 50.2400, 37.1700, 50.3300]],
        'سیاهکل' => ['lat' => 37.152778, 'lng' => 49.870833, 'bbox' => [37.1200, 49.8300, 37.1800, 49.9100]],
        'رودبار' => ['lat' => 36.821111, 'lng' => 49.426389, 'bbox' => [36.7800, 49.3800, 36.8600, 49.4700]],
        'رضوانشهر' => ['lat' => 37.551111, 'lng' => 49.139444, 'bbox' => [37.5100, 49.0900, 37.5900, 49.1800]],
        'شفت' => ['lat' => 37.164167, 'lng' => 49.402778, 'bbox' => [37.1300, 49.3600, 37.2000, 49.4400]],
        'خمام' => ['lat' => 37.389167, 'lng' => 49.658611, 'bbox' => [37.3500, 49.6200, 37.4200, 49.7000]],
    ];

    /**
     * فهرست تمام شهرها به همراه تعداد کسب‌وکارها و محله‌هایشان
     * GET /api/v1/cities
     */
    public static function index(Request $request): void
    {
        Auth::requireRole(['admin', 'editor']);

        $cities = DB::fetchAll(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM businesses b WHERE b.city_id = c.id) AS businesses_count,
                    (SELECT COUNT(*) FROM neighborhoods n WHERE n.city_id = c.id) AS neighborhoods_count
             FROM cities c
             ORDER BY c.is_active DESC, c.id ASC"
        );

        $neighborhoods = DB::fetchAll("SELECT * FROM neighborhoods ORDER BY city_id ASC, id ASC");

        Response::json([
            'ok' => true,
            'cities' => $cities,
            'neighborhoods' => $neighborhoods,
        ]);
    }

    /**
     * افزودن شهر جدید فقط با یک فیلد نام (یا ویرایش شهر موجود)
     * POST /api/v1/cities
     */
    public static function storeOrUpdate(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin', 'editor']);

        $id = (int) $request->input('id', 0);
        $nameFa = SlugService::normalizePersianText((string) $request->input('name_fa', ''));

        if ($nameFa === '') {
            Response::json([
                'ok' => false,
                'error' => 'لطفاً نام فارسی شهر را وارد کنید (مثلاً: لاهیجان).',
                'field' => 'name_fa',
            ], 422);
        }

        $rawSlug = trim((string) $request->input('slug', ''));
        if ($rawSlug === '') {
            $rawSlug = SlugService::suggestSlug($nameFa, 'city');
        }

        $slugCheck = SlugService::validateSlug($rawSlug);
        if (!$slugCheck['ok']) {
            Response::json([
                'ok' => false,
                'error' => $slugCheck['error'],
                'field' => 'slug',
            ], 422);
        }

        $uniqueSlug = SlugService::ensureUniqueSlug('cities', strtolower($rawSlug), $id > 0 ? $id : null);

        $preset = self::GILAN_CITIES_PRESETS[$nameFa] ?? null;
        $latInput = $request->input('lat');
        $lngInput = $request->input('lng');
        $bboxInput = (string) $request->input('bbox_json', '');

        $lat = ($latInput !== null && $latInput !== '') ? (is_numeric($latInput) ? (float)$latInput : null) : ($preset['lat'] ?? null);
        $lng = ($lngInput !== null && $lngInput !== '') ? (is_numeric($lngInput) ? (float)$lngInput : null) : ($preset['lng'] ?? null);
        if (($latInput !== null && $latInput !== '' && !is_numeric($latInput)) || ($lngInput !== null && $lngInput !== '' && !is_numeric($lngInput)) || ($lat !== null && abs($lat) > 90) || ($lng !== null && abs($lng) > 180) || (($lat === null) !== ($lng === null))) {
            Response::json(['ok'=>false,'error'=>'مختصات شهر معتبر نیست.'], 422);
        }
        $bbox = $bboxInput !== '' ? json_decode($bboxInput, true) : ($preset['bbox'] ?? null);
        if ($bbox !== null && (!is_array($bbox) || count($bbox)!==4 || count(array_filter($bbox, 'is_numeric'))!==4 || $bbox[0] < -90 || $bbox[2] > 90 || $bbox[1] < -180 || $bbox[3] > 180 || $bbox[0] >= $bbox[2] || $bbox[1] >= $bbox[3] || $bbox[2]-$bbox[0] > 1 || $bbox[3]-$bbox[1] > 1)) {
            Response::json(['ok'=>false,'error'=>'محدوده شهر باید چهار عدد معتبر [جنوب، غرب، شمال، شرق] باشد.'], 422);
        }
        if ($bboxInput !== '' && $bbox === null) Response::json(['ok'=>false,'error'=>'JSON محدوده شهر معتبر نیست.'],422);
        $bboxJson = $bbox !== null ? json_encode(array_values($bbox)) : null;
        $publishMode = 'manual';
        if ($id > 0 && !DB::fetchOne('SELECT id FROM cities WHERE id=:id', ['id'=>$id])) Response::json(['ok'=>false,'error'=>'شهر یافت نشد.'], 404);
        $isActive = (int) $request->input('is_active', 1) === 1 ? 1 : 0;
        $pdo = DB::connection();
        $pdo->beginTransaction();

        if ($id > 0) {
            $previous = DB::fetchOne('SELECT slug FROM cities WHERE id=:id FOR UPDATE', ['id'=>$id]);
            if (!$previous) { $pdo->rollBack(); Response::json(['ok'=>false,'error'=>'شهر یافت نشد.'], 404); }
            if ((string)$previous['slug'] !== $uniqueSlug) {
                try {
                    BusinessUrlService::rememberCity($id, (string)$previous['slug']);
                } catch (\RuntimeException $e) {
                    $pdo->rollBack();
                    Response::json(['ok'=>false,'error'=>$e->getMessage()], 409);
                }
            }
            DB::query(
                "UPDATE cities
                 SET name_fa = :name_fa,
                     slug = :slug,
                     lat = :lat,
                     lng = :lng,
                     bbox_json = :bbox,
                     publish_mode = :pm,
                     is_active = :active,
                     updated_at = NOW()
                 WHERE id = :id",
                [
                    'name_fa' => $nameFa,
                    'slug' => $uniqueSlug,
                    'lat' => $lat,
                    'lng' => $lng,
                    'bbox' => $bboxJson,
                    'pm' => $publishMode,
                    'active' => $isActive,
                    'id' => $id,
                ]
            );
            $cityId = $id;
            $msg = "اطلاعات شهر «{$nameFa}» با موفقیت به‌روزرسانی شد.";
        } else {
            DB::query(
                "INSERT INTO cities (name_fa, slug, lat, lng, bbox_json, publish_mode, is_active, created_at, updated_at)
                 VALUES (:name_fa, :slug, :lat, :lng, :bbox, :pm, :active, NOW(), NOW())",
                [
                    'name_fa' => $nameFa,
                    'slug' => $uniqueSlug,
                    'lat' => $lat,
                    'lng' => $lng,
                    'bbox' => $bboxJson,
                    'pm' => $publishMode,
                    'active' => $isActive,
                ]
            );
            $cityId = DB::lastInsertId();
            $msg = "شهر «{$nameFa}» با اسلاگ «{$uniqueSlug}» اضافه شد؛ برای جمع‌آوری، محدوده و مختصات شهر را بررسی کنید.";
        }

        $pdo->commit();
        Cache::flushAll();
        $city = DB::fetchOne("SELECT * FROM cities WHERE id = :id", ['id' => $cityId]);

        Response::json([
            'ok' => true,
            'message' => $msg,
            'city' => $city,
        ]);
    }

    /**
     * حذف شهر (در صورتی که کسب‌وکاری به آن متصل نباشد)
     * DELETE /api/v1/cities/{id}
     */
    public static function destroy(Request $request, array $params): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin']);

        $id = (int) ($params['id'] ?? 0);
        foreach (['businesses','articles','collector_jobs','neighborhoods'] as $table) {
            $count = DB::fetchOne("SELECT COUNT(*) AS cnt FROM {$table} WHERE city_id=:id", ['id'=>$id]);
            if ((int)$count['cnt'] > 0) Response::json(['ok'=>false,'error'=>'این شهر دارای اطلاعات وابسته است؛ برای حفظ داده‌ها آن را غیرفعال کنید.'],422);
        }
        $deleted = DB::query("DELETE FROM cities WHERE id=:id", ['id'=>$id])->rowCount();
        if (!$deleted) Response::json(['ok'=>false,'error'=>'شهر یافت نشد.'],404);
        Cache::flushAll();

        Response::json([
            'ok' => true,
            'message' => 'شهر مورد نظر با موفقیت حذف شد.',
        ]);
    }
}
