<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\PaymentSecurity;
use App\Core\PaymentSandbox;
use App\Core\Request;
use App\Core\Response;
use App\Services\AdvertisingFinanceService;
use App\Services\AdvertisingOnlinePaymentService;
use App\Services\PaymentGatewayClient;
use App\Services\PaymentGatewaySettings;
use App\Services\PaymentVaultException;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class PaymentGatewayController
{
    private static function authorize(Request $request, bool $mutating): array
    {
        header('Cache-Control: private, no-store, max-age=0');
        if (!PaymentSandbox::enabled()) Response::json(['ok'=>false,'error'=>'پرداخت آزمایشی فعال نیست.'], 404);
        $user = Auth::requireRole(['admin']);
        PaymentSecurity::requireHttps($request);
        if ($mutating) {
            Csrf::verifyOrFail($request);
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384 || strlen($request->rawBody()) > 16384) Response::json(['ok'=>false,'error'=>'حجم درخواست بیش از حد مجاز است.'], 413);
        }
        return $user;
    }
    private static function settings(): PaymentGatewaySettings { return new PaymentGatewaySettings(BASE_PATH); }
    private static function service(): AdvertisingOnlinePaymentService { return new AdvertisingOnlinePaymentService(self::settings(), new PaymentGatewayClient(), true); }
    private static function run(callable $fn): never
    {
        try {
            if (!AdvertisingFinanceService::schemaReady()) Response::json(['ok'=>false,'error'=>'ابتدا به‌روزرسانی اختصاصی گزارش و قراردادها را اجرا کنید.'], 409);
            $data = $fn(); Response::json(['ok'=>true] + (is_array($data) ? $data : []));
        } catch (InvalidArgumentException $e) {
            Response::json(['ok'=>false,'error'=>$e->getMessage()], 422);
        } catch (DomainException | PaymentVaultException $e) {
            Response::json(['ok'=>false,'error'=>$e->getMessage()], 409);
        } catch (Throwable $e) {
            // Do not expose or log credentials, request bodies, tokens or provider responses.
            Response::json(['ok'=>false,'error'=>'عملیات پرداخت انجام نشد؛ تنظیمات و وضعیت دیتابیس را بررسی کنید.'], 500);
        }
    }

    public static function index(Request $request): void
    {
        self::authorize($request, false);
        if (!AdvertisingFinanceService::schemaReady()) Response::json(['ok'=>true,'schema_ready'=>false,'providers'=>[],'log'=>[]]);
        self::run(static fn()=>['schema_ready'=>true, 'providers'=>self::settings()->statuses(),
            'log'=>DB::fetchAll('SELECT provider,old_mode,new_mode,key_action,version,changed_by,changed_at FROM advertising_gateway_settings_log ORDER BY id DESC LIMIT 100')]);
    }
    public static function save(Request $request): void
    {
        $user = self::authorize($request, true);
        self::run(static function () use ($request, $user): array {
            $data = $request->json();
            if (!is_string($data['provider'] ?? null) || !is_string($data['mode'] ?? null) || !is_string($data['key'] ?? null)
                || !is_int($data['version'] ?? null) || !is_bool($data['confirm_live'] ?? null) || !is_bool($data['remove'] ?? null)
                || (($data['remove'] ?? false) && ($data['remove_confirmed'] ?? null) !== true)) throw new InvalidArgumentException('اطلاعات و تأیید تنظیمات معتبر نیست.');
            if ($data['mode'] === 'live' || $data['confirm_live']) throw new InvalidArgumentException('فعال‌سازی درگاه واقعی در این نسخه مجاز نیست.');
            self::settings()->save($data['provider'], $data['mode'], $data['key'], $data['version'], false, (int)$user['id'], $data['remove']);
            return ['message'=>'تنظیمات ذخیره شد. تغییر تنظیمات، تراکنش‌های قبلی را تغییر نمی‌دهد؛ نسخه پشتیبان کلید خصوصی را محافظت کنید.'];
        });
    }
    public static function createLink(Request $request): void
    {
        $user = self::authorize($request, true);
        self::run(static fn()=>['link'=>self::service()->createLink($request->json(), (int)$user['id']),
            'message'=>'لینک را فقط برای پرداخت‌کننده ارسال کنید؛ ایجاد لینک به معنی دریافت وجه نیست.']);
    }
    public static function resolve(Request $request): void
    {
        $user = self::authorize($request, true);
        self::run(static function () use ($request, $user): array {
            AdvertisingOnlinePaymentService::resolve($request->json(), (int)$user['id'], true);
            return ['message'=>'تصمیم مدیر و دلیل آن ثبت شد. بازگشت وجه خودکار انجام نمی‌شود.'];
        });
    }
}
