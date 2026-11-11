<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Services\AnalyticsService;
use Throwable;

/**
 * S9 — مسیرهای پنل مدیریت برای داشبورد آمار، لاگ جزئیات، خروجی اکسل و تنظیم پاک‌سازی خودکار.
 * همهٔ مسیرها فقط برای مدیر ارشد؛ خروجی اکسل از طریق دانلود GET با نشست فعال انجام می‌شود.
 */
final class AnalyticsController
{
    /** GET /api/v1/analytics/dashboard — خلاصهٔ تجمیعی، سری روزانه و برترین‌ها.
     * S21: خطای جزئی هر بخش داشبورد را نمی‌شکند؛ پاسخ شامل warnings و در صورت نیاز detail است.
     */
    public static function dashboard(Request $request): void
    {
        Auth::requireRole(['admin']);
        try {
            $filters = [
                'days' => (int) $request->query('days', 7),
                'from' => $request->query('from'),
                'to' => $request->query('to'),
                'target_type' => $request->query('target_type'),
                'target_id' => (int) $request->query('target_id', 0),
            ];
            $data = AnalyticsService::dashboard($filters);
            // S21: پاسخ همیشه ۲۰۰ است؛ هشدارهای جزئی در warnings به پنل می‌روند تا داشبورد با دادهٔ موجود رندر شود.
            Response::json(['ok' => true] + $data);
        } catch (Throwable $e) {
            error_log('Analytics dashboard failed: ' . $e->getMessage());
            // S21: دلیل واقعی برای مدیر در پنل نمایش داده می‌شود (این مسیر فقط برای admin است).
            Response::json([
                'ok' => false,
                'error' => 'خواندن آمار ناموفق بود؛ وضعیت دیتابیس را بررسی کنید.',
                'detail' => 'خطای سرور: ' . mb_substr(trim($e->getMessage()) !== '' ? $e->getMessage() : get_class($e), 0, 240),
            ], 500);
        }
    }

    /** GET /api/v1/analytics/diagnose — بررسی سلامت جدول‌ها و کوئری‌های آمار (فقط admin). */
    public static function diagnose(Request $request): void
    {
        Auth::requireRole(['admin']);
        try {
            Response::json(['ok' => true] + AnalyticsService::diagnose());
        } catch (Throwable $e) {
            error_log('Analytics diagnose failed: ' . $e->getMessage());
            Response::json([
                'ok' => false,
                'error' => 'اجرای بررسی دیتابیس ناموفق بود.',
                'detail' => 'خطای سرور: ' . mb_substr(trim($e->getMessage()) !== '' ? $e->getMessage() : get_class($e), 0, 240),
            ], 500);
        }
    }

    /** GET /api/v1/analytics/logs — لاگ جزئیات صفحه‌بندی‌شده با فیلترها. */
    public static function logs(Request $request): void
    {
        Auth::requireRole(['admin']);
        try {
            $filters = self::logFilters($request);
            $page = max(1, (int) $request->query('page', 1));
            $perPage = (int) $request->query('per_page', 25);
            Response::json(['ok' => true] + AnalyticsService::logs($filters, $page, $perPage));
        } catch (Throwable $e) {
            error_log('Analytics logs failed: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'خواندن لاگ‌ها ناموفق بود.'], 500);
        }
    }

    /** GET /api/v1/analytics/export — خروجی اکسل (CSV با UTF-8 BOM) برای گزارش تجمیعی یا لاگ جزئیات. */
    public static function export(Request $request): void
    {
        Auth::requireRole(['admin']);
        $type = (string) $request->query('type', 'aggregate');
        if (!in_array($type, ['aggregate', 'logs'], true)) {
            Response::json(['ok' => false, 'error' => 'نوع خروجی معتبر نیست.'], 422);
        }
        $filters = self::logFilters($request);
        $stamp = date('Ymd-Hi');

        header('Content-Type: text/csv; charset=UTF-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        if ($type === 'aggregate') {
            header('Content-Disposition: attachment; filename="hamihub-analytics-aggregate-' . $stamp . '.csv"');
        } else {
            header('Content-Disposition: attachment; filename="hamihub-analytics-logs-' . $stamp . '.csv"');
        }

        // BOM برای نمایش درست فارسی در اکسل
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            Response::json(['ok' => false, 'error' => 'ساخت فایل خروجی ناموفق بود.'], 500);
        }
        try {
            if ($type === 'aggregate') {
                $data = AnalyticsService::aggregateRows($filters);
                fputcsv($out, ['بازه از', $data['from'], 'تا', $data['to']]);
                fputcsv($out, [
                    'نوع هدف', 'شناسه', 'عنوان', 'بازدید', 'بازدیدکننده یکتا',
                    'کلیک تماس', 'کلیک نقشه', 'کلیک وب‌سایت', 'کلیک اینستاگرام',
                    'کلیک واتساپ', 'کلیک بنر', 'جستجوها',
                ]);
                $labels = ['business' => 'کسب‌وکار', 'article' => 'مقاله', 'city' => 'شهر', 'category' => 'دسته‌بندی', 'banner' => 'بنر تبلیغاتی'];
                foreach ($data['rows'] as $row) {
                    fputcsv($out, [
                        $labels[(string) $row['target_type']] ?? (string) $row['target_type'],
                        (int) $row['target_id'],
                        (string) ($row['title'] ?? ''),
                        (int) $row['views'], (int) $row['uniques'],
                        (int) $row['call'], (int) $row['map'], (int) $row['website'],
                        (int) $row['instagram'], (int) $row['whatsapp'], (int) $row['banner'],
                        (int) $row['searches'],
                    ]);
                }
            } else {
                fputcsv($out, [
                    'زمان', 'نوع رویداد', 'نوع هدف', 'شناسه هدف', 'عنوان صفحه', 'مسیر',
                    'آدرس IP', 'منبع ورودی', 'دامنه منبع', 'Referrer',
                    'UTM Source', 'UTM Medium', 'UTM Campaign', 'UTM Term',
                    'دستگاه', 'مرورگر', 'عبارت جستجو',
                ]);
                $stmt = AnalyticsService::logStream($filters);
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    fputcsv($out, array_map(static fn($v) => (string) ($v ?? ''), array_values($row)));
                }
            }
        } catch (Throwable $e) {
            error_log('Analytics export failed: ' . $e->getMessage());
        } finally {
            fclose($out);
        }
        exit;
    }

    /** GET /api/v1/analytics/settings — تنظیمات فعلی پاک‌سازی خودکار. */
    public static function settings(Request $request): void
    {
        Auth::requireRole(['admin']);
        Response::json([
            'ok' => true,
            'raw_retention_days' => AnalyticsService::rawRetentionDays(),
            'visitor_retention_days' => AnalyticsService::visitorRetentionDays(),
            'ai_logs_retention_days' => AnalyticsService::aiRetentionDays(),
        ]);
    }

    /** POST /api/v1/analytics/settings — ذخیرهٔ بازه نگهداری لاگ جزئیات (روز). */
    public static function saveSettings(Request $request): void
    {
        Auth::requireRole(['admin']);
        Csrf::verifyOrFail($request);
        $days = $request->input('raw_retention_days', null);
        if (!is_int($days)) {
            Response::json(['ok' => false, 'error' => 'مقدار بازه نگهداری معتبر نیست.'], 422);
        }
        try {
            AnalyticsService::setRawRetentionDays($days);
            Response::json([
                'ok' => true,
                'message' => 'بازه نگهداری لاگ جزئیات روی ' . AnalyticsService::rawRetentionDays() . ' روز تنظیم شد؛ آمار تجمیعی روزانه همیشه حفظ می‌شود.',
                'raw_retention_days' => AnalyticsService::rawRetentionDays(),
            ]);
        } catch (Throwable $e) {
            error_log('Analytics settings save failed: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'ذخیره تنظیمات ناموفق بود.'], 500);
        }
    }

    /** @return array<string, mixed> فیلترهای مشترک لاگ/خروجی از پارامترهای درخواست. */
    private static function logFilters(Request $request): array
    {
        return [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'days' => (int) $request->query('days', 7),
            'event_type' => $request->query('event_type'),
            'target_type' => $request->query('target_type'),
            'target_id' => (int) $request->query('target_id', 0),
            'ref_source' => $request->query('ref_source'),
            'q' => $request->query('q'),
        ];
    }
}
