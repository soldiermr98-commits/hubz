<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Services\ApifyAutomationService;
use InvalidArgumentException;
use RuntimeException;
use PDOException;
use Throwable;

final class ApifyController
{
    private static function admin(bool $write, Request $request): void
    {
        Auth::requireRole(['admin']);
        if ($write) Csrf::verifyOrFail($request);
        if (!headers_sent()) header('Cache-Control: no-store, private');
    }

    private static function fail(Throwable $e): never
    {
        if (!($e instanceof PDOException) && ($e instanceof InvalidArgumentException || $e instanceof RuntimeException)) Response::json(['ok'=>false,'error'=>$e->getMessage()], 422);
        error_log('Apify operation failed: ' . get_class($e));
        Response::json(['ok'=>false,'error'=>'عملیات Apify ناموفق بود؛ مایگریشن و گزارش سرور را بررسی کنید.'], 500);
    }

    public static function status(Request $request): void
    {
        self::admin(false, $request);
        try { Response::json(['ok'=>true] + ApifyAutomationService::configured()->status()); }
        catch (Throwable $e) { self::fail($e); }
    }

    public static function saveSchedule(Request $request): void
    {
        self::admin(true, $request);
        try { ApifyAutomationService::configured()->saveSchedule($request->json()); Response::json(['ok'=>true]); }
        catch (Throwable $e) { self::fail($e); }
    }

    public static function startDirect(Request $request): void
    {
        self::admin(true, $request);
        try { Response::json(['ok'=>true] + ApifyAutomationService::configured()->startDirect($request->json())); }
        catch (Throwable $e) { self::fail($e); }
    }

    public static function importLatest(Request $request): void
    {
        self::admin(true, $request);
        try { $id=ApifyAutomationService::configured()->importLatest(); Response::json(['ok'=>true,'id'=>$id]); }
        catch (Throwable $e) { self::fail($e); }
    }

    public static function importRun(Request $request): void
    {
        self::admin(true, $request);
        try { $id = ApifyAutomationService::configured()->importExisting((string)$request->input('run_id', '')); Response::json(['ok'=>true,'id'=>$id]); }
        catch (Throwable $e) { self::fail($e); }
    }

    public static function run(Request $request, array $params): void
    {
        self::admin(false, $request);
        try { Response::json(['ok'=>true,'run'=>ApifyAutomationService::configured()->runData((int)($params['id'] ?? 0))]); }
        catch (Throwable $e) { self::fail($e); }
    }

    public static function refreshRun(Request $request): void
    {
        self::admin(true, $request);
        try { ApifyAutomationService::configured()->refreshRun((int)$request->input('id', 0)); Response::json(['ok'=>true]); }
        catch (Throwable $e) { self::fail($e); }
    }

    public static function deleteRun(Request $request): void
    {
        self::admin(true, $request);
        try { ApifyAutomationService::configured()->deleteReady((int)$request->input('id', 0)); Response::json(['ok'=>true]); }
        catch (Throwable $e) { self::fail($e); }
    }

    public static function resolve(Request $request): void
    {
        self::admin(true, $request);
        try {
            if ($request->input('confirmed') !== true) throw new InvalidArgumentException('ابتدا وضعیت اجرا را در حساب Apify بررسی و تأیید کنید.');
            ApifyAutomationService::configured()->resolveUnknown((int)$request->input('id', 0));
            Response::json(['ok'=>true]);
        } catch (Throwable $e) { self::fail($e); }
    }
}
