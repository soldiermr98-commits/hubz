<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Response;
use Throwable;

final class AdvertisingService
{
    // New placements can be registered here without changing the DB schema.
    public const PLACEMENTS = [
        'home'            => 'صفحه اصلی — بالای هیرو',
        'home_below_hero' => 'صفحه اصلی — زیر هیرو سکشن',
        'home_mid'        => 'صفحه اصلی — بین مقالات و کسب‌وکارها',
        'city'            => 'صفحه شهر — بالای صفحه',
        'category'        => 'صفحه شهر و دسته — بالای صفحه',
        'business'        => 'صفحه کسب‌وکار — بالای صفحه',
        'article'         => 'صفحه مقاله — بالای صفحه',
        'footer_above'    => 'بالای فوتر سایت (سراسری یا هدفمند)',
    ];

    public const HOME_ONLY_SLOTS = ['home', 'home_below_hero', 'home_mid'];
    public const MAX_BANNERS_PER_SLOT = 2;

    public static function schemaReady(): bool
    {
        try {
            return DB::fetchOne("SHOW TABLES LIKE 'advertising_banners'") !== null
                && DB::fetchOne("SHOW TABLES LIKE 'advertising_inquiries'") !== null;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function validTarget(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return false;
        if (str_starts_with($url, '/')) return !str_starts_with($url, '//');
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
        $parts = parse_url($url);
        return is_array($parts) && strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
    }

    public static function validImagePath(string $path): bool
    {
        // .webp for optimized static images, .gif for optimized animated banners.
        return (bool)preg_match('~\A/uploads/[a-zA-Z0-9_\-/]+\.(?:webp|gif)\z~', $path);
    }

    public static function earliestBoundary(int ...$boundaries): int
    {
        $earliest = 0;
        foreach ($boundaries as $ts) {
            if ($ts > 0 && ($earliest === 0 || $ts < $earliest)) {
                $earliest = $ts;
            }
        }
        return $earliest;
    }

    /** نمایش فارسی و خوانا برای حجم آزادشدهٔ دیسک در پیام‌های پنل */
    public static function formatBytesFa(int $bytes): string
    {
        $b = max(0, $bytes);
        if ($b < 1024) {
            return $b . ' بایت';
        }
        if ($b < 1048576) {
            return number_format($b / 1024, 1) . ' کیلوبایت';
        }
        return number_format($b / 1048576, 2) . ' مگابایت';
    }

    /**
     * Returns fully escaped SSR banner(s) (up to 2 side-by-side on desktop) and
     * the next relevant schedule boundary so cached pages expire on time without cron.
     *
     * @return array{html:string,until:int}
     */
    public static function placement(
        string $slot,
        ?int $cityId,
        ?int $categoryId,
        ?string $now = null,
        bool $withContainer = true,
        string $extraWrapClass = ''
    ): array {
        $empty = ['html' => '', 'until' => 0];
        if (!isset(self::PLACEMENTS[$slot]) || !self::schemaReady()) return $empty;
        $now ??= date('Y-m-d H:i:s');
        $target = 'a.placement=:slot AND a.active=1 AND (a.city_id IS NULL OR a.city_id=:city) AND (a.category_id IS NULL OR a.category_id=:category)';
        $params = ['slot' => $slot, 'city' => $cityId ?? 0, 'category' => $categoryId ?? 0];
        $future = DB::fetchOne(
            "SELECT MIN(CASE WHEN a.starts_at > :now_start THEN a.starts_at ELSE NULL END) AS next_start,
                    MIN(CASE WHEN a.ends_at > :now_end THEN a.ends_at ELSE NULL END) AS next_end
             FROM advertising_banners a WHERE {$target}",
            ['now_start' => $now, 'now_end' => $now] + $params
        );
        $until = 0;
        foreach (['next_start', 'next_end'] as $key) {
            if (!empty($future[$key])) {
                $timestamp = strtotime((string)$future[$key]);
                if ($timestamp !== false && ($until === 0 || $timestamp < $until)) $until = $timestamp;
            }
        }
        $rows = DB::fetchAll(
            "SELECT a.id,a.title,a.alt_text,a.target_url,m.file_path_1200,m.width,m.height
             FROM advertising_banners a INNER JOIN media m ON m.id=a.media_id
             WHERE {$target} AND (a.starts_at IS NULL OR a.starts_at <= :starts_now)
               AND (a.ends_at IS NULL OR a.ends_at > :ends_now)
             ORDER BY a.priority DESC,a.id DESC LIMIT 6",
            $params + ['starts_now' => $now, 'ends_now' => $now]
        );
        $validBanners = [];
        foreach ($rows as $banner) {
            if (self::validTarget((string)$banner['target_url']) && self::validImagePath((string)$banner['file_path_1200'])) {
                $validBanners[] = $banner;
                if (count($validBanners) >= self::MAX_BANNERS_PER_SLOT) {
                    break;
                }
            }
        }
        if (!$validBanners) {
            return ['html' => '', 'until' => $until];
        }

        $count = count($validBanners);
        $gridClass = $count > 1 ? 'hx-ad-grid hx-ad-grid-2' : 'hx-ad-grid hx-ad-grid-1';
        $wrapClasses = ['hx-ad-wrap'];
        if ($withContainer) {
            $wrapClasses[] = 'hx-container';
        }
        if ($extraWrapClass !== '') {
            $wrapClasses[] = $extraWrapClass;
        }
        $label = $count > 1 ? 'تبلیغات' : 'تبلیغ';

        $cardsHtml = '';
        foreach ($validBanners as $banner) {
            $cardsHtml .= '<a class="hx-ad-banner" data-hx-banner-id="' . (int)$banner['id']
                . '" href="' . Response::e((string)$banner['target_url'])
                . '" target="_blank" rel="sponsored noopener noreferrer">'
                . '<img src="' . Response::e((string)$banner['file_path_1200'])
                . '" alt="' . Response::e((string)$banner['alt_text'])
                . '" loading="lazy" decoding="async" width="' . max(1, (int)$banner['width'])
                . '" height="' . max(1, (int)$banner['height']) . '">'
                . '</a>';
        }

        $html = '<aside class="' . Response::e(implode(' ', $wrapClasses)) . '" aria-label="تبلیغات">'
            . '<span class="hx-ad-label">' . $label . '</span>'
            . '<div class="' . $gridClass . '">' . $cardsHtml . '</div>'
            . '</aside>';

        return ['html' => $html, 'until' => $until];
    }
}
