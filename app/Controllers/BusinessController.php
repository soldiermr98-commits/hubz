<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\BusinessDataReviewService;
use App\Services\BusinessSeoService;
use App\Services\BusinessUrlService;
use App\Services\MediaService;
use App\Services\MediaUploadException;
use App\Services\SlugService;
use InvalidArgumentException;
use Throwable;

/**
 * کنترلر مدیریت کسب‌وکارها و آپلود رسانه WebP (مرحله S1)
 * رعایت کامل قوانین UX بخش ۷:
 * - فرم یک‌صفحه‌ای با بخش‌های ثابت و دکمه ذخیره Sticky
 * - هیچ قانونی ذخیره را قفل نمی‌کند (چک‌لیست غیرمسدودکننده برای «بدون عکس»، «شماره تأیید نشده»، «بدون مختصات»)
 * - پیشنهاد خودکار اسلاگ لاتین و بررسی اسلاگ‌های رزروشده
 */
final class BusinessController
{
    /**
     * فهرست تمام کسب‌وکارها به همراه راه‌های ارتباطی، شهر، دسته و چک‌لیست غیرمسدودکننده
     * GET /api/v1/businesses
     */
    public static function index(Request $request): void
    {
        Auth::requireRole(['admin', 'editor']);

        // The older consumers still receive up to 300 rows. The manager uses
        // explicit server-side pagination so older businesses remain searchable.
        $paged = isset($_GET['page']);
        $page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
        $limit = $paged ? 50 : 300;
        $offset = $paged ? ($page - 1) * $limit : 0;
        $where = ['1=1']; $params = [];
        $city = (int)($_GET['city_id'] ?? 0);
        if ($city > 0) { $where[] = 'b.city_id=:city'; $params['city']=$city; }
        $category = (int)($_GET['category_id'] ?? 0);
        if ($category > 0) { $where[] = 'b.category_id=:category'; $params['category']=$category; }
        $status = (string)($_GET['status'] ?? '');
        if (in_array($status, ['draft', 'pending_review', 'published', 'hidden', 'rejected'], true)) {
            $where[] = 'b.status=:status'; $params['status']=$status;
        }
        if ((string)($_GET['article_picker'] ?? '') === '1') {
            // Article lists use INNER JOINs for city/category, so never offer
            // drafts or orphaned businesses as sponsor candidates.
            $where[] = "b.status='published'";
            $where[] = 'EXISTS (SELECT 1 FROM cities eligible_city WHERE eligible_city.id=b.city_id)';
            $where[] = 'EXISTS (SELECT 1 FROM categories eligible_category WHERE eligible_category.id=b.category_id)';
        }
        $search = trim((string)($_GET['q'] ?? ''));
        if ($search !== '') {
            $search = mb_substr($search, 0, 120);
            $where[] = '(b.name LIKE :q1 OR b.slug LIKE :q2 OR b.address LIKE :q3)';
            $params += ['q1'=>'%' . $search . '%','q2'=>'%' . $search . '%','q3'=>'%' . $search . '%'];
        }
        $reviewReady = BusinessDataReviewService::schemaReady();
        $reviewJoin = $reviewReady ? 'LEFT JOIN business_data_reviews dr ON dr.business_id=b.id' : '';
        $hash = BusinessDataReviewService::hashExpression();
        $missing = BusinessDataReviewService::missing();
        $quality = (string)($_GET['quality'] ?? '');
        if (isset($missing[$quality])) $where[] = '(' . $missing[$quality] . ')';
        elseif ($quality === 'incomplete') $where[] = '(' . implode(' OR ', $missing) . ')';
        elseif ($quality === 'unreviewed' && $reviewReady) $where[] = '(dr.business_id IS NULL OR dr.data_hash <> CONVERT(' . $hash . ' USING ascii) COLLATE ascii_bin)';
        $whereSql = implode(' AND ', $where);
        $total = (int)(DB::fetchOne("SELECT COUNT(*) AS cnt FROM businesses b {$reviewJoin} WHERE {$whereSql}", $params)['cnt'] ?? 0);
        $photoId = BusinessDataReviewService::effectivePhotoId();
        $reviewSelect = $reviewReady ? ", {$hash} AS review_hash, dr.data_hash AS reviewed_hash, dr.source_note AS review_source_note, dr.reviewed_at" : ", NULL AS review_hash, NULL AS reviewed_hash, NULL AS review_source_note, NULL AS reviewed_at";
        $score = implode(' + ', array_map(static fn(string $condition): string => '(' . $condition . ')', $missing));
        $scoreSelect = $quality !== '' ? ", ({$score}) AS quality_missing_score" : '';
        $order = $quality !== '' ? "(b.status='published') DESC, quality_missing_score DESC, b.updated_at DESC, b.id DESC" : 'b.updated_at DESC, b.id DESC';
        $rows = DB::fetchAll(
            "SELECT b.*,
                    c.name_fa AS city_name, c.slug AS city_slug,
                    cat.name_fa AS category_name,
                    n.name_fa AS neighborhood_name,
                    m.id AS effective_cover_media_id, m.file_path_480 AS cover_thumb_url,
                    m.file_path_800 AS cover_medium_url,
                    COALESCE(bp.active,0) AS promoted {$reviewSelect}{$scoreSelect}
             FROM businesses b
             LEFT JOIN cities c ON c.id = b.city_id
             LEFT JOIN categories cat ON cat.id = b.category_id
             LEFT JOIN neighborhoods n ON n.id = b.neighborhood_id
             LEFT JOIN media m ON m.id = {$photoId}
             LEFT JOIN business_promotions bp ON bp.business_id = b.id
             {$reviewJoin}
             WHERE {$whereSql}
             ORDER BY {$order}
             LIMIT {$limit} OFFSET {$offset}", $params
        );

        $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
        $contactsRows = $ids ? DB::fetchAll('SELECT * FROM business_contacts WHERE business_id IN (' . implode(',', $ids) . ') ORDER BY is_primary DESC, id ASC') : [];
        $contactsByBiz = [];
        foreach ($contactsRows as $contact) {
            $bizId = (int) $contact['business_id'];
            $contactsByBiz[$bizId][] = $contact;
        }

        foreach ($rows as &$row) {
            $bizId = (int) $row['id'];
            $contacts = $contactsByBiz[$bizId] ?? [];
            $row['contacts'] = $contacts;
            $row['warnings'] = self::buildNonBlockingWarnings($row, $contacts);
            $row['review_current'] = $reviewReady && !empty($row['reviewed_hash']) && hash_equals((string)$row['reviewed_hash'], (string)$row['review_hash']);
        }
        unset($row);

        Response::json([
            'ok' => true,
            'businesses' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $limit,
            'data_review_schema_ready' => $reviewReady,
        ]);
    }

    /** Admin-only, status-only recovery action for a saved business draft. */
    public static function publishSaved(Request $request): void
    {
        $user = Auth::requireRole(['admin']);
        Csrf::verifyOrFail($request);
        $id = (int)$request->input('business_id', 0);
        if ($id <= 0) Response::json(['ok'=>false,'error'=>'شناسه کسب‌وکار معتبر نیست.'], 422);

        $pdo = DB::connection();
        $pdo->beginTransaction();
        try {
            $business = DB::fetchOne(
                'SELECT b.id, b.slug, c.slug AS city_slug
                 FROM businesses b
                 LEFT JOIN cities c ON c.id = b.city_id
                 WHERE b.id = :id FOR UPDATE',
                ['id' => $id]
            );
            if (!$business) {
                $pdo->rollBack();
                Response::json(['ok'=>false,'error'=>'کسب‌وکار پیدا نشد.'], 404);
            }
            DB::query("UPDATE businesses SET status='published',updated_at=NOW() WHERE id=:id", ['id'=>$id]);
            DB::query("UPDATE review_queue SET status='approved',decided_by=:user,decided_at=NOW() WHERE kind='business' AND target_id=:id AND status='pending'", ['user'=>(int)$user['id'],'id'=>$id]);
            BusinessSeoService::clearForLiveBusiness((string)($business['city_slug'] ?? ''), (string)($business['slug'] ?? ''));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::json(['ok'=>false,'error'=>'انتشار کسب‌وکار ثبت نشد؛ وضعیت فرم تغییری نکرد.'], 500);
        }

        Cache::flushAll();
        $saved = DB::fetchOne('SELECT id,status FROM businesses WHERE id=:id', ['id'=>$id]);
        if (!$saved || $saved['status'] !== 'published') {
            Response::json(['ok'=>false,'error'=>'سرور انتشار را تأیید نکرد؛ وضعیت فعلی همچنان منتشرنشده است.'], 409);
        }
        Response::json(['ok'=>true,'message'=>'کسب‌وکار ذخیره‌شده منتشر شد.','business'=>$saved]);
    }

    public static function setPromotion(Request $request): void
    {
        Auth::requireRole(['admin']);
        Csrf::verifyOrFail($request);
        $id = (int)$request->input('business_id', 0);
        $active = $request->input('active');
        if ($id <= 0 || !in_array($active, [0, 1, false, true], true)) Response::json(['ok'=>false,'error'=>'درخواست معتبر نیست.'], 422);
        if (!DB::fetchOne('SELECT id FROM businesses WHERE id=:id', ['id'=>$id])) Response::json(['ok'=>false,'error'=>'کسب‌وکار یافت نشد.'], 404);
        DB::query('INSERT INTO business_promotions (business_id,active,updated_at) VALUES (:id,:active,NOW()) ON DUPLICATE KEY UPDATE active=VALUES(active),updated_at=NOW()', ['id'=>$id,'active'=>$active ? 1 : 0]);
        Cache::flushAll();
        Response::json(['ok'=>true,'message'=>$active ? 'جایگاه ویژه فعال شد.' : 'جایگاه ویژه غیرفعال شد.']);
    }

    /**
     * ساخت چک‌لیست غیرمسدودکننده کیفیت داده (بخش ۷ قوانین UX: هیچ قانونی ذخیره را قفل نمی‌کند)
     */
    public static function buildNonBlockingWarnings(array $business, array $contacts): array
    {
        $warnings = [];

        $hasPhoto = array_key_exists('effective_cover_media_id', $business)
            ? !empty($business['effective_cover_media_id']) : !empty($business['cover_media_id']);
        if (!$hasPhoto) {
            $warnings[] = 'بدون عکس (تصویر پیش‌فرض دسته نمایش داده می‌شود)';
        }
        if (array_key_exists('category_name', $business) && empty($business['category_name'])) {
            $warnings[] = 'دسته‌بندی پیدا نشد';
        }

        $hasPhone = false;
        foreach ($contacts as $c) {
            if (in_array(($c['type'] ?? ''), ['phone', 'mobile'], true) && (int)($c['is_primary'] ?? 0) === 1 && trim((string) ($c['value'] ?? '')) !== '') {
                $hasPhone = true;
                break;
            }
        }
        if (!$hasPhone) {
            $warnings[] = 'شماره تماس ثبت یا تأیید نشده';
        }

        if (empty($business['lat']) || empty($business['lng'])) {
            $warnings[] = 'مختصات نقشه (lat/lng) وارد نشده';
        }

        if (trim((string) ($business['address'] ?? '')) === '') {
            $warnings[] = 'آدرس دقیق متنی خالی است';
        }

        if (trim((string) ($business['short_desc'] ?? '')) === '') {
            $warnings[] = 'توضیح کوتاه وارد نشده است';
        }

        return $warnings;
    }

    /** A review is an explicit human attestation about this exact data snapshot. */
    public static function recordDataReview(Request $request): void
    {
        $user = Auth::requireRole(['admin', 'editor']);
        Csrf::verifyOrFail($request);
        if (!BusinessDataReviewService::schemaReady()) Response::json(['ok'=>false, 'error'=>'به‌روزرسانی بخش بازبینی هنوز نصب نشده است.'], 409);
        $id = (int)$request->input('business_id', 0);
        $noteInput = $request->input('source_note', '');
        $note = is_string($noteInput) ? trim($noteInput) : '';
        $expectedInput = $request->input('review_hash', '');
        $expected = is_string($expectedInput) ? $expectedInput : '';
        if ($id <= 0 || mb_strlen($note) < 8 || mb_strlen($note) > 1000 || !preg_match('/\A[a-f0-9]{64}\z/', $expected) || $request->input('confirmed') !== true) {
            Response::json(['ok'=>false, 'error'=>'منبع معتبر، وضعیت مجوز تصویر و تأیید بازبینی انسانی را وارد کنید.'], 422);
        }
        $hash = BusinessDataReviewService::hashExpression();
        $phone = BusinessDataReviewService::phone();
        $photo = BusinessDataReviewService::imagePath();
        $pdo = DB::connection(); $pdo->beginTransaction();
        try {
            $row = DB::fetchOne("SELECT b.id,b.name,b.address,b.short_desc,c.id AS city_found,cat.id AS category_found,{$phone} AS public_phone,{$photo} AS photo_path,{$hash} AS current_hash
                FROM businesses b LEFT JOIN cities c ON c.id=b.city_id LEFT JOIN categories cat ON cat.id=b.category_id WHERE b.id=:id FOR UPDATE", ['id'=>$id]);
            if (!$row) { $pdo->rollBack(); Response::json(['ok'=>false, 'error'=>'کسب‌وکار پیدا نشد.'], 404); }
            if (!hash_equals((string)$row['current_hash'], $expected)) { $pdo->rollBack(); Response::json(['ok'=>false, 'error'=>'اطلاعات تغییر کرده است؛ فهرست را تازه‌سازی و دوباره بازبینی کنید.'], 409); }
            $image = (string)$row['photo_path'];
            if (trim((string)$row['name']) === '' || empty($row['city_found']) || empty($row['category_found'])
                || trim((string)$row['address']) === '' || trim((string)$row['short_desc']) === ''
                || SlugService::normalizePhone((string)$row['public_phone']) === ''
                || !\App\Services\AdvertisingService::validImagePath($image) || !is_file(BASE_PATH . '/public_html' . $image)) {
                $pdo->rollBack(); Response::json(['ok'=>false, 'error'=>'پیش از ثبت بازبینی، عکس قابل نمایش، توضیح، شماره معتبر، شهر، دسته و آدرس را کامل کنید.'], 422);
            }
            DB::query('INSERT INTO business_data_reviews (business_id,source_note,data_hash,reviewed_by,reviewed_at) VALUES (:id,:note,:hash,:user,NOW()) ON DUPLICATE KEY UPDATE source_note=VALUES(source_note),data_hash=VALUES(data_hash),reviewed_by=VALUES(reviewed_by),reviewed_at=NOW()',
                ['id'=>$id,'note'=>$note,'hash'=>$row['current_hash'],'user'=>(int)$user['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::json(['ok'=>false, 'error'=>'ثبت بازبینی ناموفق بود؛ دوباره تلاش کنید.'], 500);
        }
        Response::json(['ok'=>true, 'message'=>'بازبینی انسانی برای همین نسخه از اطلاعات ثبت شد؛ تغییر داده‌ها نیاز به بازبینی دوباره دارد.']);
    }

    /**
     * ذخیره یا ویرایش کسب‌وکار (بدون مسدود کردن کاربر به خاطر نبود عکس یا مختصات)
     * POST /api/v1/businesses
     */
    public static function storeOrUpdate(Request $request): void
    {
        Csrf::verifyOrFail($request);
        $user = Auth::requireRole(['admin', 'editor']);

        $id = (int) $request->input('id', 0);
        $name = SlugService::normalizePersianText((string) $request->input('name', ''));
        $cityId = (int) $request->input('city_id', 0);
        $categoryId = (int) $request->input('category_id', 0);

        if ($name === '') {
            Response::json([
                'ok' => false,
                'error' => 'لطفاً نام کسب‌وکار را وارد کنید (مثلاً: کافه لمیز رشت).',
                'field' => 'name',
            ], 422);
        }

        if ($cityId <= 0) {
            Response::json([
                'ok' => false,
                'error' => 'لطفاً شهر کسب‌وکار را از لیست انتخاب کنید.',
                'field' => 'city_id',
            ], 422);
        }

        if ($categoryId <= 0) {
            Response::json([
                'ok' => false,
                'error' => 'لطفاً دسته‌بندی کسب‌وکار را انتخاب کنید.',
                'field' => 'category_id',
            ], 422);
        }

        $selectedCity = DB::fetchOne('SELECT id,slug FROM cities WHERE id=:id', ['id'=>$cityId]);
        if (!$selectedCity || !DB::fetchOne('SELECT id FROM categories WHERE id=:id', ['id'=>$categoryId])) {
            Response::json(['ok'=>false,'error'=>'شهر یا دسته‌بندی یافت نشد.'],422);
        }
        $websiteInput = trim((string) $request->input('website', ''));
        if ($websiteInput !== '' && !preg_match('~^https?://~i', $websiteInput)) Response::json(['ok'=>false,'error'=>'نشانی وب‌سایت باید با http یا https شروع شود.','field'=>'website'],422);
        $phoneInput = trim((string) $request->input('phone', ''));
        if ($phoneInput !== '' && SlugService::normalizePhone($phoneInput) === '') {
            Response::json(['ok' => false, 'error' => 'شماره تماس معتبر نیست.', 'field' => 'phone'], 422);
        }

        $rawSlug = trim((string) $request->input('slug', ''));
        if ($rawSlug === '') {
            $rawSlug = SlugService::suggestSlug($name, 'biz');
        }

        $slugCheck = SlugService::validateSlug($rawSlug);
        if (!$slugCheck['ok']) {
            Response::json([
                'ok' => false,
                'error' => $slugCheck['error'],
                'field' => 'slug',
            ], 422);
        }

        $uniqueSlug = SlugService::ensureUniqueSlug('businesses', strtolower($rawSlug), $id > 0 ? $id : null);

        $neighborhoodId = $request->input('neighborhood_id') ? (int) $request->input('neighborhood_id') : null;
        $address = SlugService::normalizePersianText((string) $request->input('address', ''));
        $latInput = $request->input('lat'); $lngInput = $request->input('lng');
        if (($latInput !== null && $latInput !== '' && !is_numeric($latInput)) || ($lngInput !== null && $lngInput !== '' && !is_numeric($lngInput))) Response::json(['ok'=>false,'error'=>'مختصات معتبر نیست.'],422);
        $lat = ($latInput !== null && $latInput !== '') ? (float)$latInput : null;
        $lng = ($lngInput !== null && $lngInput !== '') ? (float)$lngInput : null;
        if (($lat === null) !== ($lng === null) || ($lat !== null && abs($lat) > 90) || ($lng !== null && abs($lng) > 180)) Response::json(['ok'=>false,'error'=>'عرض و طول جغرافیایی باید هر دو معتبر باشند.'],422);
        if ($neighborhoodId && !DB::fetchOne('SELECT id FROM neighborhoods WHERE id=:id AND city_id=:city', ['id'=>$neighborhoodId,'city'=>$cityId])) Response::json(['ok'=>false,'error'=>'محله به شهر انتخاب‌شده تعلق ندارد.'],422);
        $hours = SlugService::normalizePersianText((string) $request->input('hours', ''));
        $shortDesc = SlugService::normalizePersianText((string) $request->input('short_desc', ''));
        $coverMediaId = $request->input('cover_media_id') ? (int) $request->input('cover_media_id') : null;
        if ($coverMediaId && !DB::fetchOne('SELECT id FROM media WHERE id=:id', ['id'=>$coverMediaId])) Response::json(['ok'=>false,'error'=>'تصویر یافت نشد.'],422);

        $requestedStatus = (string) $request->input('status', 'draft');
        if (!in_array($requestedStatus, ['draft', 'pending_review', 'published', 'hidden', 'rejected'], true)) {
            $requestedStatus = 'draft';
        }

        // اگر کاربر نقش editor دارد و وضعیت published انتخاب کرده، به pending_review هدایت شود
        if ($user['role'] === 'editor' && $requestedStatus === 'published') {
            $requestedStatus = 'pending_review';
        }

        $tier = (string) $request->input('tier', 'B');
        if (!in_array($tier, ['A', 'B', 'C'], true)) {
            $tier = 'B';
        }

        $normalizedName = \App\Services\PipelineService::canonicalNameKey($name);
        if ($id > 0 && !DB::fetchOne('SELECT id FROM businesses WHERE id=:id', ['id'=>$id])) Response::json(['ok'=>false,'error'=>'کسب‌وکار یافت نشد.'], 404);
        $pdo = DB::connection();
        $pdo->beginTransaction();

        if ($id > 0) {
            $previous = DB::fetchOne('SELECT b.slug,b.city_id,c.slug AS city_slug FROM businesses b INNER JOIN cities c ON c.id=b.city_id WHERE b.id=:id FOR UPDATE', ['id'=>$id]);
            if (!$previous) { $pdo->rollBack(); Response::json(['ok'=>false,'error'=>'کسب‌وکار یافت نشد.'], 404); }
            if ((string)$previous['slug'] !== $uniqueSlug || (int)$previous['city_id'] !== $cityId) {
                try {
                    BusinessUrlService::remember($id, (string)$previous['city_slug'], (string)$previous['slug']);
                } catch (\RuntimeException $e) {
                    $pdo->rollBack();
                    Response::json(['ok'=>false,'error'=>$e->getMessage()], 409);
                }
            }
            DB::query(
                "UPDATE businesses
                 SET name = :name,
                     normalized_name = :norm,
                     slug = :slug,
                     category_id = :cat_id,
                     city_id = :city_id,
                     neighborhood_id = :nid,
                     address = :addr,
                     lat = :lat,
                     lng = :lng,
                     hours = :hours,
                     short_desc = :desc,
                     cover_media_id = :media_id,
                     status = :status,
                     tier = :tier,
                     updated_at = NOW()
                 WHERE id = :id",
                [
                    'name' => $name,
                    'norm' => $normalizedName,
                    'slug' => $uniqueSlug,
                    'cat_id' => $categoryId,
                    'city_id' => $cityId,
                    'nid' => $neighborhoodId,
                    'addr' => $address !== '' ? $address : null,
                    'lat' => $lat,
                    'lng' => $lng,
                    'hours' => $hours !== '' ? $hours : null,
                    'desc' => $shortDesc !== '' ? $shortDesc : null,
                    'media_id' => $coverMediaId,
                    'status' => $requestedStatus,
                    'tier' => $tier,
                    'id' => $id,
                ]
            );
            $bizId = $id;
            $msg = "کسب‌وکار «{$name}» با موفقیت به‌روزرسانی شد.";
        } else {
            DB::query(
                "INSERT INTO businesses
                    (name, normalized_name, slug, category_id, city_id, neighborhood_id, address, lat, lng, hours, short_desc, cover_media_id, status, tier, internal_score, confidence, publish_reason, created_at, updated_at)
                 VALUES
                    (:name, :norm, :slug, :cat_id, :city_id, :nid, :addr, :lat, :lng, :hours, :desc, :media_id, :status, :tier, 0.000, 'low', 'manual_entry', NOW(), NOW())",
                [
                    'name' => $name,
                    'norm' => $normalizedName,
                    'slug' => $uniqueSlug,
                    'cat_id' => $categoryId,
                    'city_id' => $cityId,
                    'nid' => $neighborhoodId,
                    'addr' => $address !== '' ? $address : null,
                    'lat' => $lat,
                    'lng' => $lng,
                    'hours' => $hours !== '' ? $hours : null,
                    'desc' => $shortDesc !== '' ? $shortDesc : null,
                    'media_id' => $coverMediaId,
                    'status' => $requestedStatus,
                    'tier' => $tier,
                ]
            );
            $bizId = DB::lastInsertId();
            $msg = "کسب‌وکار «{$name}» با نشانی «" . BusinessUrlService::path((string)$selectedCity['slug'], $uniqueSlug) . "» ذخیره شد.";
        }

        // ذخیره راه‌های ارتباطی (تلفن ثابت، موبایل، اینستاگرام، واتساپ، وب‌سایت)
        DB::query("DELETE FROM business_contacts WHERE business_id = :bid AND source = 'manual'", ['bid' => $bizId]);

        $phone = SlugService::normalizePhone((string) $request->input('phone', ''));
        $instagram = trim((string) $request->input('instagram', ''), " @/");
        $whatsapp = SlugService::normalizePhone((string) $request->input('whatsapp', ''));
        $website = trim((string) $request->input('website', ''));

        // Preserve provider-supplied contacts as evidence, but make the editor's
        // chosen phone authoritative (including an explicit clear).
        DB::query("UPDATE business_contacts SET is_primary=0 WHERE business_id=:id AND type IN ('phone','mobile')", ['id'=>$bizId]);
        if ($phone === '' && DB::fetchOne("SELECT id FROM business_contacts WHERE business_id=:id AND type IN ('phone','mobile') LIMIT 1", ['id'=>$bizId])) {
            DB::query("INSERT INTO business_contacts (business_id,type,value,is_primary,source,confidence,last_verified_at) VALUES (:id,'phone','',1,'manual','high',NOW())", ['id'=>$bizId]);
        }
        if ($phone !== '') {
            $phoneType = str_starts_with($phone, '+989') ? 'mobile' : 'phone';
            DB::query(
                "INSERT INTO business_contacts (business_id, type, value, is_primary, source, confidence, last_verified_at)
                 VALUES (:bid, :type, :val, 1, 'manual', 'high', NOW())",
                ['bid' => $bizId, 'type' => $phoneType, 'val' => $phone]
            );
        }

        if ($instagram !== '') {
            DB::query(
                "INSERT INTO business_contacts (business_id, type, value, is_primary, source, confidence, last_verified_at)
                 VALUES (:bid, 'instagram', :val, 0, 'manual', 'high', NOW())",
                ['bid' => $bizId, 'val' => $instagram]
            );
        }

        if ($whatsapp !== '') {
            DB::query(
                "INSERT INTO business_contacts (business_id, type, value, is_primary, source, confidence, last_verified_at)
                 VALUES (:bid, 'whatsapp', :val, 0, 'manual', 'high', NOW())",
                ['bid' => $bizId, 'val' => $whatsapp]
            );
        }

        if ($website !== '') {
            DB::query(
                "INSERT INTO business_contacts (business_id, type, value, is_primary, source, confidence, last_verified_at)
                 VALUES (:bid, 'website', :val, 0, 'manual', 'high', NOW())",
                ['bid' => $bizId, 'val' => $website]
            );
        }

        if ($requestedStatus === 'pending_review') {
            \App\Services\PipelineService::enqueueReviewIfNotExists('business', $bizId, 'manual_entry', ['business_id'=>$bizId,'business_name'=>$name,'address'=>$address]);
        } else {
            DB::query("UPDATE review_queue SET status=:status,decided_by=:user,decided_at=NOW() WHERE kind='business' AND target_id=:id AND status='pending'", ['status'=>$requestedStatus === 'published' ? 'approved' : 'rejected','user'=>(int)$user['id'],'id'=>$bizId]);
        }
        if ($requestedStatus === 'published') {
            BusinessSeoService::clearForLiveBusiness((string)($selectedCity['slug'] ?? ''), $uniqueSlug);
        }
        $pdo->commit();

        // ابطال کش صفحات عمومی (صفحه کسب‌وکار، صفحه شهر، دسته و مقالات لیستی) در صورت تغییر
        Cache::flushAll();

        $savedBiz = DB::fetchOne("SELECT * FROM businesses WHERE id = :id", ['id' => $bizId]);
        $savedContacts = DB::fetchAll("SELECT * FROM business_contacts WHERE business_id = :id", ['id' => $bizId]);
        if ($savedBiz !== null) {
            $savedBiz['contacts'] = $savedContacts;
            $savedBiz['warnings'] = self::buildNonBlockingWarnings($savedBiz, $savedContacts);
        }

        Response::json([
            'ok' => true,
            'message' => $msg,
            'business' => $savedBiz,
        ]);
    }

    /**
     * حذف تکی یا دسته‌ای کسب‌وکارها با استراتژی سئوی امن (۴۱۰ Gone / ریدایرکت ۳۰۱ / پاک‌سازی کامل پیش‌نویس‌ها)
     * POST /api/v1/businesses/delete
     */
    public static function deleteBusinesses(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin']);
        $input = $request->json();

        $rawIds = $input['ids'] ?? (isset($input['id']) ? [$input['id']] : []);
        if (!is_array($rawIds)) {
            Response::json(['ok' => false, 'error' => 'لیست شناسه‌های کسب‌وکار معتبر نیست.'], 422);
        }

        $ids = array_values(array_unique(array_filter(
            array_map(static fn($v): int => (int) $v, $rawIds),
            static fn(int $id): bool => $id > 0
        )));

        if (!$ids) {
            Response::json(['ok' => false, 'error' => 'هیچ کسب‌وکاری برای حذف انتخاب نشده است.'], 422);
        }
        if (count($ids) > 200) {
            Response::json(['ok' => false, 'error' => 'در هر نوبت حداکثر ۲۰۰ کسب‌وکار قابل حذف است.'], 422);
        }

        $seoMode = trim((string) ($input['seo_mode'] ?? 'auto_seo'));
        if (!in_array($seoMode, ['auto_seo', 'redirect_category', 'redirect_business', 'purge_clean'], true)) {
            Response::json(['ok' => false, 'error' => 'استراتژی سئوی انتخاب‌شده معتبر نیست.'], 422);
        }

        $redirectBusinessId = (int) ($input['redirect_business_id'] ?? 0);
        $replacementTarget = null;
        if ($seoMode === 'redirect_business') {
            if ($redirectBusinessId <= 0 || in_array($redirectBusinessId, $ids, true)) {
                Response::json([
                    'ok' => false,
                    'error' => 'برای ریدایرکت ۳۰۱ به کسب‌وکار جایگزین، یک کسب‌وکار منتشرشدهٔ دیگر انتخاب کنید.',
                ], 422);
            }
            $replacementTarget = DB::fetchOne(
                "SELECT b.id, b.name, b.slug, c.slug AS city_slug
                 FROM businesses b
                 INNER JOIN cities c ON c.id = b.city_id
                 WHERE b.id = :id AND b.status = 'published'
                 LIMIT 1",
                ['id' => $redirectBusinessId]
            );
            if (!$replacementTarget) {
                Response::json([
                    'ok' => false,
                    'error' => 'کسب‌وکار جایگزین باید در وضعیت «منتشرشده» باشد تا ریدایرکت ۳۰۱ به خطای ۴۰۴ ختم نشود.',
                ], 422);
            }
        }

        $deleteUnusedMedia = !empty($input['delete_unused_media']);

        $placeholders = [];
        $params = [];
        foreach ($ids as $idx => $id) {
            $k = 'b' . $idx;
            $placeholders[] = ':' . $k;
            $params[$k] = $id;
        }
        $inClause = implode(',', $placeholders);

        $pdo = DB::connection();
        $pdo->beginTransaction();

        try {
            $businesses = DB::fetchAll(
                "SELECT b.id, b.name, b.slug, b.status, b.cover_media_id,
                        c.slug AS city_slug, c.name_fa AS city_name,
                        cat.slug AS category_slug, cat.name_fa AS category_name
                 FROM businesses b
                 LEFT JOIN cities c ON c.id = b.city_id
                 LEFT JOIN categories cat ON cat.id = b.category_id
                 WHERE b.id IN ({$inClause})
                 FOR UPDATE",
                $params
            );

            if (!$businesses) {
                $pdo->rollBack();
                Response::json(['ok' => false, 'error' => 'هیچ‌یک از کسب‌وکارهای انتخاب‌شده در دیتابیس یافت نشد.'], 404);
            }

            $foundIds = [];
            $candidateMediaIds = [];
            $publishedDeletedCount = 0;
            $draftDeletedCount = 0;
            $seoRulesRecorded = 0;

            foreach ($businesses as $biz) {
                $bid = (int) $biz['id'];
                $foundIds[] = $bid;
                if (!empty($biz['cover_media_id'])) {
                    $candidateMediaIds[] = (int) $biz['cover_media_id'];
                }

                $isPublished = ((string) ($biz['status'] ?? '')) === 'published';
                if ($isPublished) {
                    $publishedDeletedCount++;
                } else {
                    $draftDeletedCount++;
                }

                if ($isPublished && $seoMode !== 'purge_clean') {
                    $citySlug = trim((string) ($biz['city_slug'] ?? ''));
                    $catSlug = trim((string) ($biz['category_slug'] ?? ''));
                    $bizSlug = trim((string) ($biz['slug'] ?? ''));
                    $cityName = trim((string) ($biz['city_name'] ?? ''));
                    $catName = trim((string) ($biz['category_name'] ?? ''));

                    if ($citySlug !== '' && $catSlug !== '') {
                        $fallbackPath = '/city/' . rawurlencode($citySlug) . '/' . rawurlencode($catSlug) . '/';
                        $fallbackLabel = 'مشاهده سایر ' . ($catName !== '' ? $catName : 'کسب‌وکارها') . ($cityName !== '' ? ' در ' . $cityName : '');
                    } elseif ($citySlug !== '') {
                        $fallbackPath = '/city/' . rawurlencode($citySlug) . '/';
                        $fallbackLabel = 'مشاهده کسب‌وکارهای ' . ($cityName !== '' ? $cityName : $citySlug);
                    } else {
                        $fallbackPath = '/';
                        $fallbackLabel = 'بازگشت به صفحه اصلی حامی‌هاب گیلان';
                    }

                    $paths = BusinessSeoService::collectBusinessPaths($bid, $citySlug, $bizSlug);

                    if ($seoMode === 'auto_seo') {
                        $seoRulesRecorded += BusinessSeoService::recordRules(
                            $paths,
                            410,
                            null,
                            null,
                            $fallbackPath,
                            $fallbackLabel,
                            (string) ($biz['name'] ?? '')
                        );
                    } elseif ($seoMode === 'redirect_category') {
                        $seoRulesRecorded += BusinessSeoService::recordRules(
                            $paths,
                            301,
                            $fallbackPath,
                            null,
                            $fallbackPath,
                            $fallbackLabel,
                            (string) ($biz['name'] ?? '')
                        );
                    } elseif ($seoMode === 'redirect_business' && $replacementTarget !== null) {
                        $targetPath = BusinessUrlService::path(
                            (string) $replacementTarget['city_slug'],
                            (string) $replacementTarget['slug']
                        );
                        $seoRulesRecorded += BusinessSeoService::recordRules(
                            $paths,
                            301,
                            $targetPath,
                            (int) $replacementTarget['id'],
                            $fallbackPath,
                            $fallbackLabel,
                            (string) ($biz['name'] ?? '')
                        );
                    }
                }
            }

            $delPlaceholders = [];
            $delParams = [];
            foreach ($foundIds as $idx => $fid) {
                $k = 'd' . $idx;
                $delPlaceholders[] = ':' . $k;
                $delParams[$k] = $fid;
            }
            $delIn = implode(',', $delPlaceholders);

            if ($deleteUnusedMedia) {
                $galleryRows = DB::fetchAll(
                    "SELECT media_id FROM business_photos WHERE business_id IN ({$delIn})",
                    $delParams
                );
                foreach ($galleryRows as $gr) {
                    if (!empty($gr['media_id'])) {
                        $candidateMediaIds[] = (int) $gr['media_id'];
                    }
                }
            }

            $sourceRows = DB::fetchAll(
                "SELECT id FROM business_sources WHERE business_id IN ({$delIn})",
                $delParams
            );
            if ($sourceRows) {
                $srcPlaceholders = [];
                $srcParams = [];
                foreach ($sourceRows as $idx => $sr) {
                    $k = 's' . $idx;
                    $srcPlaceholders[] = ':' . $k;
                    $srcParams[$k] = (int) $sr['id'];
                }
                $srcIn = implode(',', $srcPlaceholders);
                if (self::optionalTableExists('business_source_history')) {
                    DB::query("DELETE FROM business_source_history WHERE business_source_id IN ({$srcIn})", $srcParams);
                }
                if (self::optionalTableExists('business_source_rating_dates')) {
                    DB::query("DELETE FROM business_source_rating_dates WHERE business_source_id IN ({$srcIn})", $srcParams);
                }
            }

            DB::query("DELETE FROM business_sources WHERE business_id IN ({$delIn})", $delParams);
            DB::query("DELETE FROM business_contacts WHERE business_id IN ({$delIn})", $delParams);
            DB::query("DELETE FROM business_photos WHERE business_id IN ({$delIn})", $delParams);

            if (self::optionalTableExists('business_promotions')) {
                DB::query("DELETE FROM business_promotions WHERE business_id IN ({$delIn})", $delParams);
            }
            if (self::optionalTableExists('business_data_reviews')) {
                DB::query("DELETE FROM business_data_reviews WHERE business_id IN ({$delIn})", $delParams);
            }
            if (BusinessUrlService::schemaReady()) {
                DB::query("DELETE FROM business_url_aliases WHERE business_id IN ({$delIn})", $delParams);
            }
            if (self::optionalTableExists('seo_url_rules')) {
                DB::query("UPDATE seo_url_rules SET target_business_id = NULL WHERE target_business_id IN ({$delIn})", $delParams);
            }

            DB::query("DELETE FROM article_items WHERE business_id IN ({$delIn})", $delParams);
            DB::query("DELETE FROM review_queue WHERE kind = 'business' AND target_id IN ({$delIn})", $delParams);
            DB::query("DELETE FROM change_log WHERE business_id IN ({$delIn})", $delParams);
            DB::query("DELETE FROM claims WHERE business_id IN ({$delIn})", $delParams);
            DB::query("DELETE FROM reports WHERE business_id IN ({$delIn})", $delParams);
            DB::query("DELETE FROM ai_tasks WHERE type = 'business_text' AND target_id IN ({$delIn})", $delParams);
            DB::query("DELETE FROM events_daily WHERE business_id IN ({$delIn})", $delParams);
            DB::query("DELETE FROM businesses WHERE id IN ({$delIn})", $delParams);

            $pdo->commit();
        } catch (InvalidArgumentException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Business delete failed: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'حذف کسب‌وکارها با خطا مواجه شد؛ هیچ تغییری اعمال نشد.'], 500);
        }

        $mediaCleanResult = null;
        if ($deleteUnusedMedia && $candidateMediaIds) {
            try {
                $mediaCleanResult = MediaService::deleteMediaItems($candidateMediaIds, false);
            } catch (Throwable $e) {
                error_log('Optional media cleanup after business delete failed: ' . $e->getMessage());
            }
        }

        Cache::flushAll();

        $parts = [sprintf('%d کسب‌وکار با موفقیت حذف شد.', count($foundIds))];
        if ($publishedDeletedCount > 0) {
            if ($seoMode === 'auto_seo') {
                $parts[] = sprintf('برای %d کسب‌وکار منتشرشده قانون سئوی 410 Gone ثبت شد.', $publishedDeletedCount);
            } elseif ($seoMode === 'redirect_category' || $seoMode === 'redirect_business') {
                $parts[] = sprintf('برای %d کسب‌وکار منتشرشده ریدایرکت دائمی 301 ثبت شد.', $publishedDeletedCount);
            }
        }
        if ($draftDeletedCount > 0) {
            $parts[] = sprintf('اسلاگ %d مورد پیش‌نویس/منتشرنشده برای ایمپورت مجدد آزاد شد.', $draftDeletedCount);
        }
        if (is_array($mediaCleanResult) && ($mediaCleanResult['deleted_count'] ?? 0) > 0) {
            $parts[] = sprintf('%d تصویر بلااستفاده نیز از هاست پاک شد.', (int) $mediaCleanResult['deleted_count']);
        }

        Response::json([
            'ok' => true,
            'deleted_ids' => $foundIds,
            'deleted_count' => count($foundIds),
            'published_deleted_count' => $publishedDeletedCount,
            'draft_deleted_count' => $draftDeletedCount,
            'seo_mode' => $seoMode,
            'seo_rules_recorded' => $seoRulesRecorded,
            'media_cleanup' => $mediaCleanResult,
            'message' => implode(' ', $parts),
        ]);
    }

    private static function optionalTableExists(string $table): bool
    {
        try {
            return DB::fetchOne("SHOW TABLES LIKE '{$table}'") !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * آپلود تصویر و تبدیل اجباری ۱۰۰٪ به WebP بهینه در اندازه‌های 480 / 800 / 1200 با GD
     * POST /api/v1/media/upload
     */
    public static function uploadMedia(Request $request): void
    {
        Csrf::verifyOrFail($request);
        $user = Auth::requireRole(['admin', 'editor']);

        $uploadedFile = $_FILES['image'] ?? $_FILES['file'] ?? null;
        if (empty($uploadedFile) || !is_array($uploadedFile)) {
            Response::json([
                'ok' => false,
                'error' => 'هیچ فایلی برای آپلود ارسال نشده است. لطفاً یک تصویر استاندارد (JPG، PNG، WebP، GIF، BMP یا AVIF) انتخاب کنید.',
                'field' => 'cover_media_id',
            ], 422);
        }

        $altText = $_POST['alt_text'] ?? '';
        if (!is_string($altText)) {
            Response::json([
                'ok' => false,
                'error' => 'متن جایگزین تصویر معتبر نیست.',
                'field' => 'cover_media_id',
            ], 422);
        }

        try {
            $media = MediaService::handleUpload($uploadedFile, $altText);
            $isAnimatedGif = (($media['mime_type'] ?? '') === 'image/gif')
                || !empty($media['is_animated_gif']);
            Response::json([
                'ok' => true,
                'message' => $isAnimatedGif
                    ? 'GIF متحرک با حفظ کامل انیمیشن و اعتبارسنجی امنیتی ذخیره شد و در بنرها قابل استفاده است.'
                    : 'تصویر با موفقیت به فرمت بهینه WebP (در ۳ سایز ۴۸۰، ۸۰۰ و ۱۲۰۰ پیکسل) تبدیل و ذخیره شد.',
                'media' => $media,
            ]);
        } catch (MediaUploadException | InvalidArgumentException $e) {
            Response::json([
                'ok' => false,
                'error' => $e->getMessage(),
                'field' => 'cover_media_id',
            ], 422);
        } catch (Throwable $e) {
            error_log('Unexpected media upload error: ' . get_class($e) . ': ' . $e->getMessage());
            Response::json([
                'ok' => false,
                'error' => 'خطای غیرمنتظره در پردازش تصویر؛ وضعیت سرور را بررسی کنید.',
                'field' => 'cover_media_id',
            ], 500);
        }
    }

    /**
     * پیشنهاد خودکار اسلاگ لاتین از طریق API
     * GET /api/v1/slug/suggest?title=...
     */
    public static function suggestSlug(Request $request): void
    {
        Auth::requireRole(['admin', 'editor']);
        $title = (string) ($_GET['title'] ?? '');
        $suggested = SlugService::suggestSlug($title);
        $validation = SlugService::validateSlug($suggested);

        Response::json([
            'ok' => true,
            'slug' => $suggested,
            'valid' => $validation['ok'],
        ]);
    }
}
