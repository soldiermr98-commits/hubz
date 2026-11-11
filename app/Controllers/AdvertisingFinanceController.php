<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\PaymentSecurity;
use App\Core\PaymentSandbox;
use App\Core\Request;
use App\Core\Response;
use App\Services\AdvertisingFinanceService;
use DomainException;
use InvalidArgumentException;
use Throwable;

/** Financial information and mutations are available only to an authenticated administrator. */
final class AdvertisingFinanceController
{
    private static function authorize(Request $request, bool $mutating): array
    {
        header('Cache-Control: private, no-store, max-age=0');
        $user = Auth::requireRole(['admin']);
        PaymentSecurity::requireHttps($request);
        if ($mutating) {
            Csrf::verifyOrFail($request);
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768 || strlen($request->rawBody()) > 32768) Response::json(['ok'=>false,'error'=>'حجم درخواست بیش از حد مجاز است.'], 413);
        }
        return $user;
    }

    private static function run(callable $fn): never
    {
        try {
            if (!AdvertisingFinanceService::schemaReady()) Response::json(['ok'=>false,'error'=>'ابتدا به‌روزرسانی اختصاصی گزارش و قراردادها را اجرا کنید.'], 409);
            $result = $fn();
            Response::json(['ok'=>true] + (is_array($result) ? $result : []));
        } catch (InvalidArgumentException $e) {
            Response::json(['ok'=>false,'error'=>$e->getMessage()], 422);
        } catch (DomainException $e) {
            Response::json(['ok'=>false,'error'=>$e->getMessage()], 409);
        } catch (Throwable $e) {
            // No request bodies, account details, credentials, or raw DB exceptions in logs/responses.
            Response::json(['ok'=>false,'error'=>'عملیات انجام نشد؛ وضعیت دیتابیس را بررسی و سپس فهرست را تازه کنید.'], 500);
        }
    }

    public static function index(Request $request): void
    {
        self::authorize($request, false);
        if (!AdvertisingFinanceService::schemaReady()) Response::json(['ok'=>true,'schema_ready'=>false,'online_sandbox_enabled'=>PaymentSandbox::enabled(),'contracts'=>[],'manual_payments'=>[],'online_payments'=>[],'status_log'=>[],'online_audit'=>[]]);
        self::run(static fn()=>['schema_ready'=>true,'online_sandbox_enabled'=>PaymentSandbox::enabled()] + AdvertisingFinanceService::listing());
    }

    public static function create(Request $request): void
    {
        $user = self::authorize($request, true);
        self::run(static fn()=>['contract'=>AdvertisingFinanceService::createContract($request->json(), (int)$user['id']),
            'message'=>'قرارداد ثبت شد؛ ایجاد قرارداد، بنر را خودکار فعال نمی‌کند.']);
    }

    public static function status(Request $request): void
    {
        $user = self::authorize($request, true);
        self::run(static function () use ($request, $user): array {
            AdvertisingFinanceService::changeStatus($request->json(), (int)$user['id']);
            return ['message'=>'وضعیت قرارداد با دلیل ثبت شد؛ برگشت وجه خودکار انجام نمی‌شود.'];
        });
    }

    public static function recordManual(Request $request): void
    {
        $user = self::authorize($request, true);
        self::run(static fn()=>['payment'=>AdvertisingFinanceService::recordManual($request->json(), (int)$user['id']),
            'message'=>'ثبت دستی انجام شد؛ این ثبت جایگزین تأیید بانکی یا رسید واقعی نیست.']);
    }

    public static function voidManual(Request $request): void
    {
        $user = self::authorize($request, true);
        self::run(static function () use ($request, $user): array {
            AdvertisingFinanceService::voidManual($request->json(), (int)$user['id']);
            return ['message'=>'ثبت دستی با دلیل ابطال شد؛ وجهی خودکار برگشت داده نمی‌شود.'];
        });
    }
}
