<?php
declare(strict_types=1);

/**
 * درگاه مستقیم و OPcache-fresh مدیریت کتابخانه رسانه و تصاویر WebP هاست (/api/media-library.php).
 *
 * این نقطه ورود فیزیکی مستقل از کش قدیمی OPcache روی api/index.php و MediaController.php عمل می‌کند،
 * در صورت فعال بودن opcache_invalidate فایل‌های هسته را همگام می‌کند و حتی در صورت خطای Fatal موتور PHP
 * همیشه پاسخ معتبر JSON برمی‌گرداند تا هرگز خطای مبهم «پاسخ نامعتبر از سرور (HTTP 500)» رخ ندهد.
 */

$__hhxMediaCompleted = false;
$__hhxMediaReserve = str_repeat('x', 65536);
$__hhxStartObLevel = ob_get_level();
ob_start();

register_shutdown_function(static function () use (&$__hhxMediaCompleted, &$__hhxMediaReserve, $__hhxStartObLevel): void {
    $__hhxMediaReserve = '';
    if ($__hhxMediaCompleted) {
        return;
    }
    $last = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    $isFatal = is_array($last) && in_array((int) ($last['type'] ?? 0), $fatalTypes, true);
    if (!$isFatal) {
        return;
    }
    while (ob_get_level() > $__hhxStartObLevel) {
        @ob_end_clean();
    }
    $loc = is_array($last)
        ? basename((string) ($last['file'] ?? 'unknown')) . ':' . (int) ($last['line'] ?? 0)
        : 'unknown';
    error_log('Media endpoint fatal error at ' . $loc . ': ' . (string) ($last['message'] ?? ''));
    if (!headers_sent()) {
        http_response_code(500);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private, max-age=0');
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok' => false,
        'error' => 'خطای داخلی PHP در اجرای بخش رسانه (' . $loc . ').',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/app/Core/Bootstrap.php';

use App\Core\Auth;
use App\Core\Bootstrap;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Services\MediaLibraryService;

try {
    Bootstrap::init(BASE_PATH);
    $request = new Request();
    $method = $request->method();

    if ($method === 'GET') {
        Auth::requireRole(['admin', 'editor']);

        if (function_exists('opcache_invalidate')) {
            foreach ([
                BASE_PATH . '/app/Controllers/MediaController.php',
                BASE_PATH . '/app/Core/Request.php',
                BASE_PATH . '/app/Core/Response.php',
                BASE_PATH . '/app/Services/MediaService.php',
                BASE_PATH . '/public_html/api/index.php',
            ] as $targetPhp) {
                if (@is_file($targetPhp)) {
                    @opcache_invalidate($targetPhp, true);
                }
            }
        }

        $data = MediaLibraryService::listLibrary([
            'page' => isset($_GET['page']) && is_scalar($_GET['page']) ? (string) $_GET['page'] : '1',
            'per_page' => isset($_GET['per_page']) && is_scalar($_GET['per_page']) ? (string) $_GET['per_page'] : '24',
            'usage' => isset($_GET['usage']) && is_scalar($_GET['usage']) ? (string) $_GET['usage'] : 'all',
            'q' => isset($_GET['q']) && is_scalar($_GET['q']) ? (string) $_GET['q'] : '',
        ]);

        while (ob_get_level() > $__hhxStartObLevel) {
            @ob_end_clean();
        }
        $__hhxMediaCompleted = true;
        Response::json([
            'ok' => true,
            'items' => $data['items'],
            'pagination' => $data['pagination'],
            'summary' => $data['summary'],
            'dimension_guides' => $data['dimension_guides'],
        ]);
    }

    if ($method === 'POST') {
        $action = isset($_GET['action']) && is_scalar($_GET['action']) ? trim((string) $_GET['action']) : '';

        if ($action === 'update') {
            Csrf::verifyOrFail($request);
            Auth::requireRole(['admin', 'editor']);
            $input = $request->json();
            $id = (int) ($input['id'] ?? 0);
            $altText = (string) ($input['alt_text'] ?? '');

            try {
                $updated = MediaLibraryService::updateAltText($id, $altText);
                while (ob_get_level() > $__hhxStartObLevel) {
                    @ob_end_clean();
                }
                $__hhxMediaCompleted = true;
                if (!$updated) {
                    Response::json(['ok' => false, 'error' => 'تصویر موردنظر در دیتابیس یافت نشد.'], 404);
                }
                Cache::flushAll();
                Response::json([
                    'ok' => true,
                    'message' => 'متن جایگزین (Alt) تصویر ذخیره شد.',
                ]);
            } catch (InvalidArgumentException $e) {
                while (ob_get_level() > $__hhxStartObLevel) {
                    @ob_end_clean();
                }
                $__hhxMediaCompleted = true;
                Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
        }

        if ($action === 'delete') {
            Csrf::verifyOrFail($request);
            Auth::requireRole(['admin']);
            $input = $request->json();

            if (!empty($input['purge_orphans'])) {
                $orphanRes = MediaLibraryService::purgeOrphanUploadFiles();
                while (ob_get_level() > $__hhxStartObLevel) {
                    @ob_end_clean();
                }
                $__hhxMediaCompleted = true;
                Response::json([
                    'ok' => true,
                    'orphan_result' => $orphanRes,
                    'message' => sprintf(
                        'پاک‌سازی فایل‌های بدون رکورد دیتابیس انجام شد (%d فایل حذف شد).',
                        $orphanRes['deleted_files']
                    ),
                ]);
            }

            $rawIds = $input['ids'] ?? (isset($input['id']) ? [$input['id']] : []);
            if (!is_array($rawIds)) {
                while (ob_get_level() > $__hhxStartObLevel) {
                    @ob_end_clean();
                }
                $__hhxMediaCompleted = true;
                Response::json(['ok' => false, 'error' => 'لیست شناسه‌های رسانه نامعتبر است.'], 422);
            }
            $detachUsages = !empty($input['detach_usages']);

            try {
                $result = MediaLibraryService::deleteMediaItems($rawIds, $detachUsages);
                while (ob_get_level() > $__hhxStartObLevel) {
                    @ob_end_clean();
                }
                $__hhxMediaCompleted = true;
                if ($result['deleted_count'] === 0 && !empty($result['blocked'])) {
                    Response::json([
                        'ok' => false,
                        'error' => 'تصاویر انتخاب‌شده در حال استفاده هستند؛ برای جداسازی خودکار از کسب‌وکار/مقاله گزینه «جداسازی و حذف» را فعال کنید (تصاویر متصل به بنر تبلیغاتی ابتدا باید در بخش تبلیغات جایگزین شوند).',
                        'result' => $result,
                    ], 409);
                }

                Cache::flushAll();

                $msg = sprintf(
                    '%d رکورد تصویر از دیتابیس و %d فایل WebP از هاست حذف شد.',
                    $result['deleted_count'],
                    $result['deleted_files_count']
                );
                if (!empty($result['blocked'])) {
                    $msg .= sprintf(' (%d تصویر در حال استفاده نادیده گرفته شد.)', count($result['blocked']));
                }

                Response::json([
                    'ok' => true,
                    'result' => $result,
                    'message' => $msg,
                ]);
            } catch (InvalidArgumentException $e) {
                while (ob_get_level() > $__hhxStartObLevel) {
                    @ob_end_clean();
                }
                $__hhxMediaCompleted = true;
                Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
        }

        while (ob_get_level() > $__hhxStartObLevel) {
            @ob_end_clean();
        }
        $__hhxMediaCompleted = true;
        Response::json(['ok' => false, 'error' => 'عملیات درخواستی در بخش رسانه معتبر نیست.'], 400);
    }

    while (ob_get_level() > $__hhxStartObLevel) {
        @ob_end_clean();
    }
    $__hhxMediaCompleted = true;
    Response::json(['ok' => false, 'error' => 'متد درخواست مجاز نیست.'], 405);
} catch (Throwable $e) {
    while (ob_get_level() > $__hhxStartObLevel) {
        @ob_end_clean();
    }
    $__hhxMediaCompleted = true;
    $loc = basename($e->getFile()) . ':' . $e->getLine();
    error_log('Media endpoint exception (' . $loc . '): ' . get_class($e) . ': ' . $e->getMessage());
    Response::json([
        'ok' => false,
        'error' => 'خطا در اجرای سرویس کتابخانه رسانه (' . get_class($e) . ' در ' . $loc . ').',
    ], 500);
}
