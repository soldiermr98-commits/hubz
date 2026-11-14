<?php
declare(strict_types=1);

/**
 * نقطه ورود تمام درخواست‌های API نسخه ۱ (/api/v1/*)
 * شامل مسیرهای مراحل S0، S1، S2، S3 و S4 (مقالات لیستی، اسکیما و رهگیری رویدادها)
 */

if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/app/Core/Bootstrap.php';

use App\Controllers\AdvertisingController;
use App\Controllers\AnalyticsController;
use App\Controllers\AdminMailController;
use App\Controllers\StaffController;
use App\Controllers\AdvertisingFinanceController;
use App\Controllers\AiArticleController;
use App\Controllers\AiProviderController;
use App\Controllers\AiWorkerController;
use App\Controllers\ApifyController;
use App\Controllers\ArticleController;
use App\Controllers\AuthController;
use App\Controllers\BusinessController;
use App\Controllers\CategoryController;
use App\Controllers\CityController;
use App\Controllers\CollectorController;
use App\Controllers\ManualImportController;
use App\Controllers\MediaController;
use App\Controllers\PaymentGatewayController;
use App\Controllers\PublicPaymentController;
use App\Controllers\PublicSiteController;
use App\Controllers\ReviewQueueController;
use App\Controllers\SystemController;
use App\Core\Bootstrap;
use App\Core\Cache;
use App\Core\PaymentSandbox;
use App\Core\Request;
use App\Core\Router;

Bootstrap::init(BASE_PATH);

$request = new Request();
$router = new Router();

// ۱. مسیرهای احراز هویت و عملیات سیستمی بدون ترمینال (S0)
$router->get('/api/v1/auth/me', [AuthController::class, 'me']);
$router->post('/api/v1/auth/login', [AuthController::class, 'login']);
$router->post('/api/v1/auth/logout', [AuthController::class, 'logout']);
$router->post('/api/v1/cache/flush', [AuthController::class, 'flushCache']);
$router->post('/api/v1/system/migrate', [AuthController::class, 'runMigrations']);
$router->post('/api/v1/system/update-article-schema', [AuthController::class, 'updateArticleSchema']);
$router->post('/api/v1/system/update-article-tags-schema', [AuthController::class, 'updateArticleTagsSchema']);
$router->post('/api/v1/system/update-feature-schema', [AuthController::class, 'updateFeatureSchema']);
$router->post('/api/v1/system/update-advertising-schema', [AuthController::class, 'updateAdvertisingSchema']);
$router->post('/api/v1/system/update-seo-schema', [AuthController::class, 'updateSeoSchema']);

// ۲. مسیرهای CRUD شهر، دسته، بسته دسته، کسب‌وکار و رسانه WebP (S1)
$router->get('/api/v1/cities', [CityController::class, 'index']);
$router->post('/api/v1/cities', [CityController::class, 'storeOrUpdate']);
$router->delete('/api/v1/cities/{id}', [CityController::class, 'destroy']);

$router->get('/api/v1/categories', [CategoryController::class, 'index']);
$router->post('/api/v1/categories', [CategoryController::class, 'storeOrUpdateCategory']);
$router->post('/api/v1/category-packs', [CategoryController::class, 'storeOrUpdatePack']);

$router->get('/api/v1/businesses', [BusinessController::class, 'index']);
$router->post('/api/v1/businesses', [BusinessController::class, 'storeOrUpdate']);
$router->post('/api/v1/businesses/publish', [BusinessController::class, 'publishSaved']);
$router->post('/api/v1/businesses/promotion', [BusinessController::class, 'setPromotion']);
$router->post('/api/v1/businesses/data-review', [BusinessController::class, 'recordDataReview']);
$router->post('/api/v1/businesses/delete', [BusinessController::class, 'deleteBusinesses']);
$router->post('/api/v1/import/json/prepare', [ManualImportController::class, 'prepare']);
$router->post('/api/v1/import/json/preview', [ManualImportController::class, 'preview']);
$router->post('/api/v1/import/json/apply', [ManualImportController::class, 'apply']);
$router->get('/api/v1/import/apify/status', [ApifyController::class, 'status']);
$router->post('/api/v1/import/apify/schedule', [ApifyController::class, 'saveSchedule']);
$router->post('/api/v1/import/apify/start', [ApifyController::class, 'startDirect']);
$router->post('/api/v1/import/apify/latest', [ApifyController::class, 'importLatest']);
$router->post('/api/v1/import/apify/run', [ApifyController::class, 'importRun']);
$router->get('/api/v1/import/apify/runs/{id}', [ApifyController::class, 'run']);
$router->post('/api/v1/import/apify/refresh', [ApifyController::class, 'refreshRun']);
$router->post('/api/v1/import/apify/delete', [ApifyController::class, 'deleteRun']);
$router->post('/api/v1/import/apify/resolve', [ApifyController::class, 'resolve']);
$router->get('/api/v1/media', [MediaController::class, 'index']);
$router->post('/api/v1/media/upload', [BusinessController::class, 'uploadMedia']);
$router->post('/api/v1/media/update', [MediaController::class, 'update']);
$router->post('/api/v1/media/delete', [MediaController::class, 'delete']);
$router->get('/api/v1/slug/suggest', [BusinessController::class, 'suggestSlug']);

// ۳. مسیرهای صف جمع‌آوری خودکار (پنل ادمین + کلاینت collector-remote در مرحله S2)
$router->get('/api/v1/collector/jobs', [CollectorController::class, 'index']);
$router->post('/api/v1/collector/jobs', [CollectorController::class, 'createJob']);
$router->post('/api/v1/collector/providers', [CollectorController::class, 'updateProvider']);
$router->post('/api/v1/collector/run-next', [CollectorController::class, 'runNext']);
$router->post('/api/v1/collector/token', [CollectorController::class, 'remoteDisabled']);

$router->get('/api/v1/collector/jobs/next', [CollectorController::class, 'remoteDisabled']);
$router->post('/api/v1/collector/ingest', [CollectorController::class, 'remoteDisabled']);
$router->post('/api/v1/collector/photo', [CollectorController::class, 'remoteDisabled']);

// ۴. مسیرهای صندوق بازبینی، تاریخچه تغییرات و پایپ‌لاین ۱۰۰ رکورد نمونه (مرحله S3)
$router->get('/api/v1/review-queue', [ReviewQueueController::class, 'index']);
$router->post('/api/v1/review-queue/decide', [ReviewQueueController::class, 'decide']);
$router->post('/api/v1/pipeline/benchmark-100', [ReviewQueueController::class, 'runBenchmark100']);

// ۵. مسیرهای مدیریت مقالات لیستی سئو و رهگیری کلیک‌های عمومی (مرحله S4)
$router->get('/api/v1/articles', [ArticleController::class, 'index']);
$router->get('/api/v1/articles/preview/{id}', [PublicSiteController::class, 'articlePreview']);
$router->post('/api/v1/articles', [ArticleController::class, 'storeOrUpdate']);
$router->post('/api/v1/articles/delete', [ArticleController::class, 'destroy']);
$router->post('/api/v1/articles/auto-build', [ArticleController::class, 'autoBuild']);
$router->post('/api/v1/events/track', [PublicSiteController::class, 'trackEvent']);

// Limited staff accounts and their server-enforced permissions.
$router->get('/api/v1/staff', [StaffController::class, 'index']);
$router->post('/api/v1/staff', [StaffController::class, 'save']);

// Admin notification delivery settings (administrator only).
$router->get('/api/v1/admin-mail', [AdminMailController::class, 'index']);
$router->post('/api/v1/admin-mail', [AdminMailController::class, 'save']);
$router->post('/api/v1/admin-mail/test', [AdminMailController::class, 'test']);

// Independent banner inventory and advertiser inquiries (admin-only management).
$router->get('/api/v1/advertising', [AdvertisingController::class, 'index']);
$router->post('/api/v1/advertising/banner', [AdvertisingController::class, 'saveBanner']);
$router->post('/api/v1/advertising/banner/delete', [AdvertisingController::class, 'deleteBanner']);
$router->post('/api/v1/advertising/inquiry', [AdvertisingController::class, 'updateInquiry']);
$router->post('/api/v1/public/advertising-inquiry', [AdvertisingController::class, 'submitInquiry']);
$router->post('/api/v1/public/banner-event', [AdvertisingController::class, 'trackBanner']);
$router->get('/api/v1/advertising/finance', [AdvertisingFinanceController::class, 'index']);
$router->post('/api/v1/advertising/contracts', [AdvertisingFinanceController::class, 'create']);
$router->post('/api/v1/advertising/contracts/status', [AdvertisingFinanceController::class, 'status']);
$router->post('/api/v1/advertising/payments/manual', [AdvertisingFinanceController::class, 'recordManual']);
$router->post('/api/v1/advertising/payments/manual/void', [AdvertisingFinanceController::class, 'voidManual']);
// Explicit, disabled-by-default sandbox experiment. These handlers and the
// underlying service reject live settings/attempts even when the switch is on.
if (PaymentSandbox::enabled()) {
    $router->get('/api/v1/advertising/gateways', [PaymentGatewayController::class, 'index']);
    $router->post('/api/v1/advertising/gateways/save', [PaymentGatewayController::class, 'save']);
    $router->post('/api/v1/advertising/payments/online', [PaymentGatewayController::class, 'createLink']);
    $router->post('/api/v1/advertising/payments/online/resolve', [PaymentGatewayController::class, 'resolve']);
    $router->get('/api/v1/public/advertising-payment/{token}', [PublicPaymentController::class, 'view']);
    $router->post('/api/v1/public/advertising-payment/{token}', [PublicPaymentController::class, 'start']);
    $router->get('/api/v1/public/advertising-payment/callback/{token}', [PublicPaymentController::class, 'callback']);
    $router->post('/api/v1/public/advertising-payment/callback/{token}', [PublicPaymentController::class, 'callback']);
}

// The old batch/worker mutation routes stay disabled; this new path is manual and draft-only.
$router->get('/api/v1/ai/article/providers', [AiArticleController::class, 'status']);
$router->post('/api/v1/ai/article/generate', [AiArticleController::class, 'generate']);
// Admin-only credential management; keys are never returned to the browser.
$router->get('/api/v1/ai/providers/manage', [AiProviderController::class, 'index']);
$router->post('/api/v1/ai/providers/save', [AiProviderController::class, 'save']);
$router->post('/api/v1/ai/providers/remove', [AiProviderController::class, 'remove']);
$router->post('/api/v1/ai/providers/test', [AiProviderController::class, 'test']);
$router->get('/api/v1/ai/providers/logs', [AiProviderController::class, 'logs']);

// ۶. مسیرهای صف کارهای هوش مصنوعی بدون توهم و ورکر راه دور (S5 — فعال‌سازی در فاز ۳ از S24)
// دسترسی‌ها در خود کنترلر اجرا می‌شوند: مشاهده/ثبت/اجرای دسته‌ای فقط admin|editor با CSRF؛
// اندپوینت‌های Pull ورکر راه دور با توکن Bearer (یا نشست مدیر) و بدون CSRF (جزء استثناهای Csrf.php).
$router->get('/api/v1/ai/tasks', [AiWorkerController::class, 'index']);
$router->post('/api/v1/ai/tasks/enqueue', [AiWorkerController::class, 'enqueue']);
$router->post('/api/v1/ai/tasks/run-batch', [AiWorkerController::class, 'runBatch']);
$router->get('/api/v1/ai/tasks/next', [AiWorkerController::class, 'pullNextTask']);
$router->post('/api/v1/ai/tasks/complete', [AiWorkerController::class, 'completeTask']);

// S24 — سلامت سیستم و بکاپ خودکار (فقط مدیر ارشد)
$router->get('/api/v1/system/health', [SystemController::class, 'health']);
$router->get('/api/v1/system/backups', [SystemController::class, 'backupsList']);
$router->post('/api/v1/system/backup', [SystemController::class, 'backupRun']);

// S9 — ماژول جامع آمار بازدید و کلیک (داشبورد، لاگ جزئیات، خروجی اکسل و پاک‌سازی خودکار)
$router->get('/api/v1/analytics/dashboard', [AnalyticsController::class, 'dashboard']);
$router->get('/api/v1/analytics/logs', [AnalyticsController::class, 'logs']);
$router->get('/api/v1/analytics/export', [AnalyticsController::class, 'export']);
$router->get('/api/v1/analytics/settings', [AnalyticsController::class, 'settings']);
$router->post('/api/v1/analytics/settings', [AnalyticsController::class, 'saveSettings']);

// S21 — بررسی سلامت دیتابیس برای صفحهٔ آمار (فقط مدیر ارشد)
$router->get('/api/v1/analytics/diagnose', [AnalyticsController::class, 'diagnose']);

// ۷. مسیرهای مرحله S6 (ادعای مالکیت، گزارش خطا، آمار کلیک‌های روزانه و کرون ۵ دقیقه‌ای)
$router->post('/api/v1/public/claim', [PublicSiteController::class, 'submitClaim']);
$router->post('/api/v1/public/report', [PublicSiteController::class, 'submitReport']);
$router->get('/api/v1/analytics/summary', [PublicSiteController::class, 'analyticsSummary']);
$router->post('/api/v1/system/cron-run', [PublicSiteController::class, 'runCronMaintenance']);

// S16 — دیدگاه‌های کاربران روی کسب‌وکارها (ثبت عمومی + مدیریت پنل)
$router->post('/api/v1/public/comments', [PublicSiteController::class, 'submitComment']);
$router->get('/api/v1/comments', [PublicSiteController::class, 'adminCommentsList']);
$router->post('/api/v1/comments/moderate', [PublicSiteController::class, 'adminCommentsModerate']);

// S19 — سوال امنیتی تازه برای مودال درخواست تبلیغات روی صفحات کش‌شده
$router->get('/api/v1/public/ad-question', [PublicSiteController::class, 'adQuestion']);

// S20 — سوال امنیتی تازه برای فرم دیدگاه درون‌صفحه‌ای روی صفحات کش‌شده
$router->get('/api/v1/public/comment-question', [PublicSiteController::class, 'commentQuestion']);

try {
    $router->dispatch($request);
} catch (\Throwable $e) {
    $loc = basename($e->getFile()) . ':' . $e->getLine();
    error_log('Unhandled API exception (' . $loc . '): ' . get_class($e) . ': ' . $e->getMessage());
    \App\Core\Response::json([
        'ok' => false,
        'error' => 'خطای غیرمنتظره سرور در اجرای درخواست API (' . get_class($e) . ' در ' . $loc . ').',
    ], 500);
}
