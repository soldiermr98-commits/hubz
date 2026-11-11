<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
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

}
