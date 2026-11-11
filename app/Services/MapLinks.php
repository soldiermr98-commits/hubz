<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * S17: سازندهٔ لینک‌های نقشه و مسیریابی (تک‌منطقه‌ای برای کل سایت)
 *
 * منطق دکمهٔ اصلی مسیریابی گوگل:
 *   - اگر Place ID معتبر گوگل برای کسب‌وکار در منابع ثبت شده باشد:
 *       https://www.google.com/maps/dir/?api=1&destination=LAT,LNG&destination_place_id=PLACE_ID
 *     گوگل دقیقاً به همان POI خودش مسیریابی می‌کند (دقیق‌ترین حالت).
 *   - در غیر این صورت مسیریابی مستقیم با مختصات:
 *       https://www.google.com/maps/dir/?api=1&destination=LAT,LNG
 *   جستجوی متنی «نام کسب‌وکار + شهر» فقط دکمهٔ کمکی است (تشابه اسمی محلی را مسئولیت کاربر می‌کند).
 *
 * پروایدرهای ایرانی: نشان و بلد با مختصات مستقیم (هر دو WGS84؛ یک نقطهٔ درست همه را درست می‌کند).
 */
final class MapLinks
{
    private const PROVIDER_PATTERN = '/^[a-z_]{2,20}$/';
    /** سازگاری کوئری پارام‌ها بدون خطای رُند اعشاری؛ DECIMAL(10,7) با ۷ رقم اعشار */
    public static function coord(float $value): string
    {
        return rtrim(rtrim(sprintf('%.7F', $value), '0'), '.');
    }

    /**
     * استخراج Place ID واقعی گوگل از raw منابع ثبت‌شده (source_id هش‌شده است و بازگشت‌ناپذیر؛
     * مقدار اصلی فقط داخل JSON ستون raw می‌ماند). شناسهٔ نامعتبر/غیرمنطبق نادیده گرفته می‌شود.
     */
    public static function googlePlaceId(int $businessId): ?string
    {
        if ($businessId <= 0) return null;
        try {
            $rows = DB::fetchAll(
                "SELECT raw FROM business_sources
                 WHERE business_id = :bid AND provider IN ('google_maps', 'gosom')
                 ORDER BY fetched_at DESC, id DESC LIMIT 8",
                ['bid' => $businessId]
            );
        } catch (\Throwable $e) {
            return null; // نبود جدول منابع یا خطای گذرا هرگز صفحهٔ عمومی را نمی‌شکند
        }
        foreach ($rows as $row) {
            $raw = json_decode((string) ($row['raw'] ?? ''), true);
            if (!is_array($raw)) continue;
            foreach (['google_place_id', 'placeId', 'place_id', 'googlePlaceId'] as $key) {
                if (!isset($raw[$key]) || !is_string($raw[$key])) continue;
                $pid = trim($raw[$key]);
                if ($pid !== '' && preg_match('/^[A-Za-z0-9_\-]{8,200}$/', $pid)) return $pid;
            }
        }
        return null;
    }

    /** دکمهٔ اصلی مسیریابی گوگل: با Place ID اگر موجود بود، وگرنه با مختصات */
    public static function googleDirections(?float $lat, ?float $lng, ?string $placeId = null): string
    {
        $url = 'https://www.google.com/maps/dir/?api=1';
        if ($lat !== null && $lng !== null) {
            $url .= '&destination=' . self::coord($lat) . ',' . self::coord($lng);
        }
        if ($placeId !== null && $placeId !== '') {
            $url .= '&destination_place_id=' . rawurlencode($placeId);
        }
        return $url;
    }

    /** دکمهٔ کمکی: جستجوی متنی نام + شهر در گوگل‌مپ (گوگل pin خودش را پیدا می‌کند) */
    public static function googleSearch(string $name, string $city): string
    {
        $q = trim($name . ' ' . $city);
        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q);
    }

    /** مسیریابی با نشان (لینک اشتراک رسمی nshn.ir) */
    public static function neshan(?float $lat, ?float $lng): string
    {
        return 'https://nshn.ir/?lat=' . self::coord((float) $lat) . '&lng=' . self::coord((float) $lng);
    }

    /** مسیریابی با بلد */
    public static function balad(?float $lat, ?float $lng): string
    {
        return 'https://balad.ir/location?latitude=' . self::coord((float) $lat) . '&longitude=' . self::coord((float) $lng);
    }

    /** نمایش نقطه در OpenStreetMap */
    public static function osm(?float $lat, ?float $lng): string
    {
        $latS = self::coord((float) $lat);
        $lngS = self::coord((float) $lng);
        return 'https://www.openstreetmap.org/?mlat=' . $latS . '&mlon=' . $lngS . '#map=17/' . $latS . '/' . $lngS;
    }

    /**
     * اعمال قفل مختصات دستی: آیا مختصات فعلی رکورد قابل پیشنهاد/بازنویسی توسط ایمپورت است؟
     * اگر ادمین نقطه را دستی تأیید کرده باشد ('manual')، هیچ ایمپورتی حق پیشنهاد تغییر ندارد.
     */
    public static function coordsLocked(?string $coordsSource): bool
    {
        return $coordsSource === 'manual';
    }

    /** پالایش نام پروایدر برای ذخیرهٔ امن در ستون coords_source */
    public static function sanitizeProvider(?string $provider): ?string
    {
        $provider = trim((string) $provider);
        if ($provider === '' || !preg_match(self::PROVIDER_PATTERN, $provider)) return null;
        return $provider;
    }
}
