<?php
declare(strict_types=1);

/**
 * S27 فاز ۶ — ابزار خط فرمان بازمحاسبهٔ امتیاز بیزی و Tier برای همهٔ ترکیب‌های شهر×دسته.
 *
 * هدف: پس از استقرار بستهٔ S27، این اسکریپت یک‌بار اجرا می‌شود تا مرتب‌سازی
 * «بهترین‌های واقعی اول» روی صفحات فرود شهر×دسته از همین لحظه فعال باشد.
 * پس از آن، کرون جمع‌آوری و تاییدهای بازبینی، Tierها را خودکار تازه نگه می‌دارند
 * (هوک‌های CollectorRunner و ReviewQueueController) و اجرای مجدد این ابزار
 * فقط در مواقع خاص (مثلاً پس از ایمپورت انبوه) لازم است.
 *
 * اجرا روی هاست اشتراکی:
 *   /usr/local/bin/php ~/bin/recalc-tiers.php   (مسیر php را طبق پنل هاست تنظیم کنید)
 * یا از پنل ادمین می‌توانید معادل آن را با دکمه‌های موجود در بخش بازبینی نتیجه بگیرید.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once dirname(__DIR__) . '/app/Core/Bootstrap.php';

use App\Core\Bootstrap;
use App\Core\DB;
use App\Services\PipelineService;

$basePath = dirname(__DIR__);
Bootstrap::init($basePath);

$combos = DB::fetchAll(
    "SELECT b.city_id, b.category_id, c.name_fa AS city_name, cat.name_fa AS cat_name, COUNT(*) AS n
     FROM businesses b
     INNER JOIN cities c ON c.id = b.city_id
     INNER JOIN categories cat ON cat.id = b.category_id
     WHERE b.status = 'published'
     GROUP BY b.city_id, b.category_id, c.name_fa, cat.name_fa
     ORDER BY n DESC"
);

if (!$combos) {
    echo "هیچ ترکیب شهر×دسته منتشرشده‌ای پیدا نشد؛ کاری انجام نشد.\n";
    exit(0);
}

echo "بازمحاسبهٔ امتیاز بیزی و Tier برای " . count($combos) . " ترکیب شهر×دسته:\n";
$okCount = 0;
$failCount = 0;
foreach ($combos as $combo) {
    try {
        PipelineService::recalculateTiersForCityCategory((int)$combo['city_id'], (int)$combo['category_id']);
        $okCount++;
        echo sprintf(
            " + [%d کسب‌وکار] %s × %s\n",
            (int)$combo['n'],
            (string)$combo['city_name'],
            (string)$combo['cat_name']
        );
    } catch (Throwable $e) {
        $failCount++;
        echo sprintf(
            " ! خطا در %s × %s: %s\n",
            (string)$combo['city_name'],
            (string)$combo['cat_name'],
            $e->getMessage()
        );
    }
}

echo "---\n";
echo "پایان: $okCount ترکیب موفق، $failCount ناموفق.\n";
exit($failCount > 0 ? 1 : 0);
