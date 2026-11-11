<?php
declare(strict_types=1);

/**
 * S9 smoke test — منطق خالص ماژول آمار و هوش مصنوعی بدون نیاز به دیتابیس یا شبکه.
 * اجرا: php tests/s9_analytics_smoke.php
 * نکته: فقط توابع خالص و مسیرهای بدون اثر جانبی آزموده می‌شوند؛ آزمون دیتابیس روی هاست با مایگریشن انجام می‌شود.
 */

error_reporting(E_ALL);
$failures = 0;
$checks = 0;

function check(bool $cond, string $label): void
{
    global $failures, $checks;
    $checks++;
    if ($cond) {
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label}\n";
    }
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Services/AnalyticsService.php';
require_once BASE_PATH . '/app/Services/AiServiceLogger.php';
require_once BASE_PATH . '/app/Services/AiArticleService.php';

use App\Services\AiArticleService;
use App\Services\AnalyticsService;

echo "== فیلتر ربات‌ها ==\n";
$chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
$iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';
check(AnalyticsService::isBot($chrome) === false, 'کروم دسکتاپ ربات نیست');
check(AnalyticsService::isBot($iphone) === false, 'سافاری آیفون ربات نیست');
check(AnalyticsService::isBot('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)') === true, 'Googlebot ربات است');
check(AnalyticsService::isBot('Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)') === true, 'bingbot ربات است');
check(AnalyticsService::isBot('facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)') === true, 'کراولر فیس‌بوک ربات است');
check(AnalyticsService::isBot('python-requests/2.31.0') === true, 'python-requests ربات است');
check(AnalyticsService::isBot('curl/8.5.0') === true, 'curl ربات است');
check(AnalyticsService::isBot('') === true, 'UA خالی ربات است (ثبت نمیشود)');
check(AnalyticsService::isBot('Mozilla/5.0') === true, 'UA خیلی کوتاه ربات در نظر گرفته می‌شود');
check(AnalyticsService::isBot('Mozilla/5.0 (iPhone; AppleWebKit) Telegram-iOS/9.0') === false, 'مرورگر درون تلگرام انسانی است');
check(AnalyticsService::isBot('Mozilla/5.0 (Linux; Android 10; SM-G975F) AppleWebKit/537.36 WhatsApp/2.24.1') === false, 'مرورگر درون واتساپ انسانی است');
check(AnalyticsService::isBot('Mozilla/5.0 (Linux; Android 10; Pixel 4) AppleWebKit/537.36 Chrome/124 Mobile Safari/537.36 Instagram 300.0') === false, 'اپ اینستاگرام انسانی است');

echo "== نوع دستگاه ==\n";
check(AnalyticsService::parseDevice($iphone) === 'mobile', 'آیفون موبایل');
check(AnalyticsService::parseDevice('Mozilla/5.0 (iPad; CPU OS 17_4) AppleWebKit/605.1.15 Safari/604.1') === 'tablet', 'آیپد تبلت');
check(AnalyticsService::parseDevice('Mozilla/5.0 (Linux; Android 10; SM-G975F) AppleWebKit/537.36 Chrome/124.0 Mobile Safari/537.36') === 'mobile', 'اندروید موبایل');
check(AnalyticsService::parseDevice('Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 Chrome/124.0 Safari/537.36') === 'tablet', 'اندروید تبلت');
check(AnalyticsService::parseDevice($chrome) === 'desktop', 'ویندوز دسکتاپ');

echo "== مرورگر ==\n";
check(AnalyticsService::parseBrowser($chrome) === 'Chrome', 'کروم');
$edge = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0';
check(AnalyticsService::parseBrowser($edge) === 'Edge', 'اج (نه کروم)');
check(AnalyticsService::parseBrowser($iphone) === 'Safari', 'سافاری آیفون');
check(AnalyticsService::parseBrowser('Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 300.0.0.0') === 'Instagram', 'اپ اینستاگرام');
$ff = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:126.0) Gecko/20100101 Firefox/126.0';
check(AnalyticsService::parseBrowser($ff) === 'Firefox', 'فایرفاکس');

echo "== دسته‌بندی منبع ورودی ==\n";
check(AnalyticsService::classifyReferrer('')['source'] === 'direct', 'بدون رفرر = مستقیم');
check(AnalyticsService::classifyReferrer('https://www.google.com/search?q=test')['source'] === 'search', 'گوگل = موتور جستجو');
check(AnalyticsService::classifyReferrer('https://www.instagram.com/')['source'] === 'instagram', 'اینستاگرام');
check(AnalyticsService::classifyReferrer('https://t.me/hamihub')['source'] === 'telegram', 'تلگرام');
check(AnalyticsService::classifyReferrer('https://www.facebook.com/')['source'] === 'social', 'فیس‌بوک = شبکه اجتماعی');
check(AnalyticsService::classifyReferrer('https://hamihubx.ir/city/rasht/', 'hamihubx.ir')['source'] === 'internal', 'لینک داخلی');
check(AnalyticsService::classifyReferrer('https://example.org/page', 'hamihubx.ir')['source'] === 'referral', 'سایت دیگر = ارجاع');
check(AnalyticsService::classifyReferrer('https://www.google.com/search')['host'] === 'google.com', 'دامنه منبع بدون www');

echo "== اعتبارسنجی نشانی جایگزین AI (S9) ==\n";
check(AiArticleService::validBaseUrl('https://proxy.example.com/groq/openai/v1/chat/completions') === true, 'نشانی https معتبر');
check(AiArticleService::validBaseUrl('https://my-worker.example') === true, 'نشانی پایه بدون مسیر');
check(AiArticleService::validBaseUrl('http://proxy.example.com') === false, 'http بدون امنیت رد می‌شود');
check(AiArticleService::validBaseUrl('https://proxy.example.com/?a=b') === false, 'پارامتر ورودی رد می‌شود');
check(AiArticleService::validBaseUrl('https://proxy.example.com/#frag') === false, 'fragment رد می‌شود');
check(AiArticleService::validBaseUrl('javascript:alert(1)') === false, 'اسکیمای خطرناک رد می‌شود');
check(AiArticleService::validBaseUrl('') === false, 'رشته خالی رد می‌شود');
check(AiArticleService::validBaseUrl(str_repeat('a', 400)) === false, 'طول بیش از حد رد می‌شود');

echo "== ارسال AI با ترنسپورت جعلی + لاگ بی‌صدا ==\n";
$svc = new AiArticleService(['providers' => [
    'groq' => ['enabled' => true, 'free_tier_confirmed' => true, 'api_key' => 'gsk_test', 'model' => 'openai/gpt-oss-120b', 'base_url' => 'https://proxy.example/groq/openai/v1/chat/completions'],
]]);
$prepared = $svc->prepareConnectionTest('groq');
check(str_contains($prepared['request']['url'], 'proxy.example/groq/openai/v1/chat/completions'), 'نشانی جایگزین در ساخت درخواست اعمال شد');
check(str_contains($prepared['request']['headers'][1] ?? '', 'Bearer gsk_test'), 'هدر احراز هویت ساخته شد');
$fakeOk = static fn(array $req): array => ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => 'OK']]]])];
check($svc->send($prepared, $fakeOk) === 'OK', 'پاسخ موفق ترنسپورت جعلی برگشت');
$fake403 = static fn(array $req): array => ['status' => 403, 'body' => 'forbidden'];
$threw = false;
try {
    $svc->send($prepared, $fake403);
} catch (RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'HTTP 403');
}
check($threw, 'خطای ۴۰۳ با پیام فارسی ثابت بازگردانده شد');
$fakeNet = static function (array $req): array { throw new RuntimeException('ارتباط با سرویس ناموفق یا زمان آن تمام شد؛ وضعیت شبکه را بررسی کنید.'); };
$netThrew = false;
try {
    $svc->send($prepared, $fakeNet);
} catch (RuntimeException) {
    $netThrew = true;
}
check($netThrew, 'خطای ارتباطی به بیرون پرتاب می‌شود (لاگ بی‌صدا داخل send انجام شده)');

echo "== نگاشت رویدادها و رد رویداد نامعتبر ==\n";
check(AnalyticsService::record('click', [])['status'] === 'invalid_event', 'رویداد ناشناخته رد می‌شود');
check(AnalyticsService::record('view', ['ip' => '', 'user_agent' => $chrome])['status'] === 'skipped_no_ip', 'نبود IP ثبت نمی‌شود');
check(AnalyticsService::record('view', ['ip' => '1.2.3.4', 'user_agent' => 'Googlebot/2.1'])['status'] === 'skipped_bot', 'ربات در سطح سرویس فیلتر می‌شود');
check(AnalyticsService::record('view', ['ip' => '1.2.3.4', 'user_agent' => $chrome, 'path' => '/admin/businesses/'])['status'] === 'skipped_path', 'مسیر پنل ثبت نمی‌شود');

echo "== مایگریشن ۰۱۳ ==\n";
$sql = (string) file_get_contents(BASE_PATH . '/database/migrations/013_analytics_and_ai_logs.sql');
check(substr_count($sql, 'CREATE TABLE IF NOT EXISTS') === 4, 'چهار جدول جدید تعریف شده است');
check(str_contains($sql, 'analytics_daily') && str_contains($sql, 'analytics_events') && str_contains($sql, 'analytics_visitor_days') && str_contains($sql, 'ai_service_logs'), 'نام جدول‌ها درست است');
check(str_contains($sql, 'analytics_raw_retention_days'), 'تنظیم پیش‌فرض بازه نگهداری درج می‌شود');
check(!str_contains($sql, 'DROP TABLE') && !str_contains($sql, 'ALTER TABLE'), 'مایگریشن افزودنی است (بدون DROP/ALTER)');

echo "== خروجی اکسل با BOM ==\n";
$ctl = (string) file_get_contents(BASE_PATH . '/app/Controllers/AnalyticsController.php');
check(str_contains($ctl, '\xEF\xBB\xBF') || str_contains($ctl, "\xEF\xBB\xBF"), 'BOM فارسی در خروجی CSV نوشته می‌شود');
check(str_contains($ctl, 'text/csv; charset=UTF-8'), 'هدر Content-Type درست است');

echo "\nنتایج: {$checks} بررسی — ";
echo $failures === 0 ? "همه پاس شد ✓\n" : "{$failures} مورد ناموفق ✗\n";
exit($failures === 0 ? 0 : 1);
