<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\CategorySyncService;
use App\Services\CollectorRunner;

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
        Response::json(['ok'=>true,'jobs'=>$jobs,'providers'=>$providers,'collector_bearer_token'=>'','photo_policy'=>'none']);
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

    public static function remoteDisabled(Request $request): void
    {
        Response::json(['ok'=>false,'error'=>'ورکر راه دور در این نسخه غیرفعال است؛ از ورکر هاست استفاده کنید.'],410);
    }
}
