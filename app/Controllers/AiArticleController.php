<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\AiArticleService;
use App\Services\AiProviderSettings;
use App\Services\AiQuotaService;
use RuntimeException;
use Throwable;

final class AiArticleController
{
    private static function service(): AiArticleService
    {
        $settings = new AiProviderSettings((array)Config::get('ai', []), BASE_PATH);
        return new AiArticleService($settings->effectiveConfig());
    }

    public static function status(Request $request): void
    {
        Auth::requireRole(['admin', 'editor']);
        header('Cache-Control: private, no-store');
        $service = self::service();
        Response::json(['ok'=>true, 'schema_ready'=>AiQuotaService::schemaReady(), 'providers'=>$service->status()]);
    }

    public static function generate(Request $request): void
    {
        $user = Auth::requireRole(['admin', 'editor']);
        Csrf::verifyOrFail($request);
        header('Cache-Control: private, no-store');
        if (!AiQuotaService::schemaReady()) Response::json(['ok'=>false, 'error'=>'ابتدا به‌روزرسانی اختصاصی دیتابیس را از پنل مدیر اجرا کنید.'], 409);
        if ($request->input('confirm_send') !== true || $request->input('confirm_quota') !== true) {
            Response::json(['ok'=>false, 'error'=>'ارسال متن و بررسی سهمیه و هزینه احتمالی را تأیید کنید.'], 422);
        }
        $input = [];
        foreach (['provider', 'title', 'brief', 'facts'] as $key) {
            $value = $request->input($key, '');
            if (!is_string($value)) Response::json(['ok'=>false, 'error'=>'نوع داده ارسالی معتبر نیست.'], 422);
            $input[$key] = $value;
        }
        $provider = $input['provider'];
        $service = self::service();
        try {
            $prepared = $service->prepare($provider, $input['title'], $input['brief'], $input['facts']);
        } catch (RuntimeException $e) {
            Response::json(['ok'=>false, 'error'=>$e->getMessage()], 422);
        }
        try {
            $reserved = AiQuotaService::reserve((int)$user['id'], $provider);
        } catch (Throwable $e) {
            // Database errors can contain host names or SQL details.
            Response::json(['ok'=>false, 'error'=>'محدودیت درخواست آماده نیست؛ وضعیت دیتابیس را بررسی کنید.'], 500);
        }
        if (!$reserved) Response::json(['ok'=>false, 'error'=>'محدودیت تعداد درخواست پنل رسیده است؛ بعداً دوباره تلاش کنید.'], 429);
        try {
            // Exactly one provider and one request. There is no retry or silent fallback.
            $text = $service->send($prepared);
            Response::json(['ok'=>true, 'text'=>$text, 'provider'=>$prepared['label'], 'model'=>$prepared['model']]);
        } catch (RuntimeException $e) {
            Response::json(['ok'=>false, 'error'=>$e->getMessage()], 502);
        } catch (Throwable $e) {
            // Do not log exceptions that may contain prompts, keys or provider responses.
            Response::json(['ok'=>false, 'error'=>'تولید متن انجام نشد؛ تنظیمات سرویس را بررسی کنید.'], 500);
        }
    }

    /**
     * S29: ترکیب‌های شهر×دسته که حداقل یک مرکز منتشرشده دارند — منوی انتخاب فکت از دیتابیس.
     * فقط خواندن و فقط پنل؛ هیچ دادهٔ پیش‌نویس یا داخلی در خروجی نیست.
     * GET /api/v1/ai/article/fact-combos
     */
    public static function factCombos(Request $request): void
    {
        Auth::requireRole(['admin', 'editor']);
        header('Cache-Control: private, no-store');
        $rows = DB::fetchAll(
            "SELECT ct.id AS city_id, ct.name_fa AS city_name, c.id AS category_id, c.name_fa AS category_name,
                    COUNT(*) AS total
             FROM businesses b
             JOIN cities ct ON ct.id = b.city_id
             JOIN categories c ON c.id = b.category_id
             WHERE b.status = 'published'
             GROUP BY ct.id, ct.name_fa, c.id, c.name_fa
             ORDER BY ct.name_fa ASC, total DESC, c.name_fa ASC
             LIMIT 500"
        );
        Response::json(['ok'=>true, 'combos'=>$rows]);
    }

    /**
     * S29: ساخت متن فکت از مراکز واقعی منتشرشده — فقط همان اطلاعات عمومی‌ای که در صفحات
     * عمومی سایت هم نمایش داده می‌شود (نام، دسته، محله/نشانی، تلفن عمومی، بولت/توضیح کوتاه).
     * خروجی یک متن آمادهٔ فیلد «اطلاعات و منابع بررسی‌شده» است؛ هیچ فراخوانی سرویس AI نیست.
     * GET /api/v1/ai/article/facts?city_id=1&category_id=2&limit=12  (category_id=0 یعنی همهٔ دسته‌های شهر)
     */
    public static function facts(Request $request): void
    {
        Auth::requireRole(['admin', 'editor']);
        header('Cache-Control: private, no-store');
        $cityId = (int)($_GET['city_id'] ?? 0);
        $categoryId = (int)($_GET['category_id'] ?? 0);
        $limit = max(1, min(20, (int)($_GET['limit'] ?? 12)));
        if ($cityId <= 0) Response::json(['ok'=>false, 'error'=>'ابتدا شهر را انتخاب کنید.'], 422);

        $city = DB::fetchOne("SELECT id, name_fa FROM cities WHERE id=:id", ['id'=>$cityId]);
        if ($city === null) Response::json(['ok'=>false, 'error'=>'شهر یافت نشد.'], 404);
        $category = null;
        if ($categoryId > 0) {
            $category = DB::fetchOne("SELECT id, name_fa FROM categories WHERE id=:id", ['id'=>$categoryId]);
            if ($category === null) Response::json(['ok'=>false, 'error'=>'دستهٔ انتخابی یافت نشد.'], 404);
        }

        $where = "b.status = 'published' AND b.city_id = :city";
        $params = ['city'=>$cityId];
        if ($category !== null) { $where .= " AND b.category_id = :cat"; $params['cat'] = $categoryId; }

        // $limit از قبل با min/max عدد صحیح گیرده شده؛ مستقیم در کوئری قرار می‌گیرد.
        $rows = DB::fetchAll(
            "SELECT b.name, b.address, b.short_desc, b.bullets_json, c.name_fa AS category_name,
                    n.name_fa AS neighborhood_name,
                    (SELECT bc.value FROM business_contacts bc
                      WHERE bc.business_id = b.id AND bc.type IN ('phone','mobile')
                      ORDER BY bc.is_primary DESC, bc.id ASC LIMIT 1) AS phone
             FROM businesses b
             JOIN categories c ON c.id = b.category_id
             LEFT JOIN neighborhoods n ON n.id = b.neighborhood_id
             WHERE {$where}
             ORDER BY FIELD(b.tier, 'A', 'B', 'C') ASC, b.internal_score DESC, b.id DESC
             LIMIT {$limit}",
            $params
        );

        $scope = 'مراکز واقعی منتشرشده در حامی‌هاب — ' . self::cleanLine((string)$city['name_fa'], 60)
            . ($category !== null ? ' — ' . self::cleanLine((string)$category['name_fa'], 60) : '')
            . ' (تنها منبع مجاز برای نام، نشانی و تلفن):';

        $lines = [$scope];
        $chars = mb_strlen($scope);
        $included = 0;
        $truncated = false;
        // زیر سقف ۸۰۰۰ نویسهٔ فیلد پنل می‌مانیم تا جا برای فکت‌های عمومیِ خود کاربر باز بماند.
        $charBudget = 7800;
        foreach ($rows as $row) {
            $line = self::faNumber($included + 1) . '. ' . self::cleanLine((string)$row['name'], 80);
            foreach ([
                self::cleanLine((string)$row['category_name'], 50),
                self::locationLine($row),
                self::phoneLine($row),
                self::featureLine($row),
            ] as $segment) {
                if ($segment !== '') $line .= ' | ' . $segment;
            }
            $lineLength = mb_strlen($line) + 1;
            if ($chars + $lineLength > $charBudget) { $truncated = true; break; }
            $lines[] = $line;
            $chars += $lineLength;
            $included++;
        }

        Response::json([
            'ok'=>true,
            'facts'=>implode("\n", $lines),
            'count'=>$included,
            'truncated'=>$truncated,
            'chars'=>$chars,
        ]);
    }

    /** S29: تک‌سطری‌سازی مقدار برای ردیف فکت — حذف خط‌وط جدید و فشرده‌سازی فاصله‌ها با سقف نویسه. */
    private static function cleanLine(string $text, int $maxChars): string
    {
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        $collapsed = preg_replace('/\s{2,}/u', ' ', $text);
        $text = trim(is_string($collapsed) ? $collapsed : $text);
        if (mb_strlen($text) > $maxChars) $text = rtrim(mb_substr($text, 0, $maxChars)) . '…';
        return $text;
    }

    private static function faNumber(int $n): string
    {
        return strtr((string)$n, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    }

    /** محله و/یا نشانی کوتاه؛ اگر هیچ‌کدام موجود نیست رشتهٔ خالی. */
    private static function locationLine(array $row): string
    {
        $neighborhood = self::cleanLine((string)($row['neighborhood_name'] ?? ''), 60);
        $address = self::cleanLine((string)($row['address'] ?? ''), 120);
        if ($neighborhood !== '' && $address !== '') return $neighborhood . '، ' . $address;
        if ($neighborhood !== '') return $neighborhood;
        return $address;
    }

    private static function phoneLine(array $row): string
    {
        $phone = self::cleanLine((string)($row['phone'] ?? ''), 30);
        return $phone !== '' ? 'تلفن: ' . $phone : '';
    }

    /** اولین بولت واقعی یا توضیح کوتاه ثبت‌شده؛ هیچ چیزی از خودش ساخته نمی‌شود. */
    private static function featureLine(array $row): string
    {
        $bullets = !empty($row['bullets_json']) ? (json_decode((string)$row['bullets_json'], true) ?: []) : [];
        if (is_array($bullets)) {
            foreach ($bullets as $bullet) {
                if (is_string($bullet) && trim($bullet) !== '') {
                    $line = self::cleanLine($bullet, 80);
                    if ($line !== '') return $line;
                }
            }
        }
        return self::cleanLine((string)($row['short_desc'] ?? ''), 90);
    }

}
