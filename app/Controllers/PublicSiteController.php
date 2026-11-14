<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\AdvertisingService;
use App\Services\AdminMailService;
use App\Services\AnalyticsService;
use App\Services\ArticleService;
use App\Services\ArticleText;
use App\Services\BusinessSeoService;
use App\Services\BusinessUrlService;
use App\Services\CommentService;
use App\Services\MapLinks;
use App\Services\Normalizer;
use App\Services\SponsorshipStatus;
use Throwable;

/**
 * کنترلر صفحات عمومی سئو، کش کامل فایل HTML در دیسک، اسکیماهای JSON-LD، نقشه سایت و فرم‌های تعاملی S6
 * منطبق با ساختار دقیق جداول 001_initial_schema.sql، تم آبی لوگو و لاجوردی و آیکن‌های خطی سبک Iconsax
 */
final class PublicSiteController
{
    /**
     * رندر آیکن‌های برداری خطی به سبک Iconsax (بدون ایموجی رنگی، منطبق با رنگ متن currentColor)
     */
    public static function icon(string $name, string $cls = 'hx-icon'): string
    {
        $paths = match ($name) {
            'location' => '<path d="M12 13.43a3.12 3.12 0 1 0 0-6.24 3.12 3.12 0 0 0 0 6.24Z" stroke-width="1.75"/><path d="M3.62 8.49c1.97-8.66 14.8-8.65 16.76.01 1.15 5.08-2.01 9.38-4.78 12.04a5.193 5.193 0 0 1-7.21 0c-2.76-2.66-5.92-6.97-4.77-12.05Z" stroke-width="1.75"/>',
            'map' => '<path d="M2.29 7.78v9.73c0 1.9 1.35 2.68 2.99 1.74l2.35-1.34c.51-.29 1.36-.32 1.89-.05l5.25 2.63c.53.26 1.38.24 1.89-.05l4.33-2.48c.55-.32 1.01-1.1 1.01-1.74V6.49c0-1.9-1.35-2.68-2.99-1.74l-2.35 1.34c-.51.29-1.36.32-1.89.05L9.52 3.51c-.53-.26-1.38-.24-1.89.05L3.3 6.04c-.56.32-1.01 1.1-1.01 1.74Z" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="M8.56 4v13M15.73 6.62V20" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>',
            'call' => '<path d="M21.97 18.33c0 .36-.08.73-.25 1.09-.17.36-.39.7-.68 1.02-.49.54-1.03.93-1.64 1.18-.6.25-1.25.38-1.95.38-1.02 0-2.11-.24-3.26-.73s-2.3-1.15-3.44-1.98a28.75 28.75 0 0 1-3.28-2.8 28.414 28.414 0 0 1-2.79-3.27c-.82-1.14-1.48-2.28-1.96-3.41C2.24 8.67 2 7.58 2 6.54c0-.68.12-1.33.36-1.93.24-.61.62-1.17 1.15-1.67.64-.63 1.34-.94 2.08-.94.28 0 .56.06.81.18.26.12.49.3.67.56l2.32 3.27c.18.25.31.48.4.7.09.21.14.42.14.61 0 .24-.07.48-.21.71-.13.23-.32.47-.56.71l-.76.79c-.11.11-.16.24-.16.4 0 .08.01.15.03.23.03.08.06.14.08.2.18.33.49.76.93 1.28.45.52.93 1.05 1.45 1.58.54.53 1.06 1.02 1.59 1.47.52.44.95.74 1.29.92.05.02.11.05.18.08.08.03.16.04.25.04.17 0 .3-.06.41-.17l.76-.75c.25-.25.49-.44.72-.56.23-.14.46-.21.71-.21.19 0 .39.04.61.13.22.09.45.22.7.39l3.31 2.35c.26.18.44.39.55.64.1.25.16.5.16.78Z" stroke-width="1.75" stroke-miterlimit="10"/>',
            'routing' => '<path d="M2 9c0-3.87 3.13-7 7-7l-1.05 1.75M22 15c0 3.87-3.13 7-7 7l1.05-1.75" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="M5.47 13.71v3.62c0 .97.68 1.48 1.52 1.13l6.69-2.8c.51-.21.92-.78.92-1.33v-3.62c0-.97-.68-1.48-1.52-1.13l-6.69 2.8c-.51.21-.92.78-.92 1.33Z" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>',
            'global' => '<path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10Z" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 3h1a28.424 28.424 0 0 0 0 18H8M15 3a28.424 28.424 0 0 1 0 18" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 16v-1a28.424 28.424 0 0 0 18 0v1M3 9a28.424 28.424 0 0 1 18 0" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>',
            'instagram' => '<path d="M9 22h6c5 0 7-2 7-7V9c0-5-2-7-7-7H9C4 2 2 4 2 9v6c0 5 2 7 7 7Z" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="M17.636 7h.012" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
            'star' => '<path d="m13.73 3.51 1.76 3.52c.24.49.88.96 1.42 1.05l3.19.53c2.04.34 2.52 1.82 1.05 3.28l-2.48 2.48c-.42.42-.65 1.23-.52 1.81l.71 3.07c.56 2.43-.73 3.37-2.88 2.1l-2.99-1.77c-.54-.32-1.43-.32-1.98 0l-2.99 1.77c-2.14 1.27-3.44.32-2.88-2.1l.71-3.07c.13-.58-.1-1.39-.52-1.81l-2.48-2.48c-1.46-1.46-.99-2.94 1.05-3.28l3.19-.53c.53-.09 1.17-.56 1.41-1.05l1.76-3.52c.96-1.91 2.52-1.91 3.47 0Z" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>',
            'verify' => '<path d="m9.62 12 1.58 1.58 3.18-3.18" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="M10.75 2.45c.69-.59 1.82-.59 2.52 0l1.58 1.36c.3.26.87.47 1.27.47h1.7c1.06 0 1.93.87 1.93 1.93v1.7c0 .4.21.96.47 1.26l1.36 1.58c.59.69.59 1.82 0 2.52l-1.36 1.58c-.26.3-.47.86-.47 1.26v1.7c0 1.06-.87 1.93-1.93 1.93h-1.7c-.4 0-.96.21-1.27.47l-1.58 1.36c-.69.59-1.82.59-2.52 0l-1.58-1.36c-.3-.26-.87-.47-1.26-.47H6.18c-1.06 0-1.93-.87-1.93-1.93v-1.71c0-.39-.2-.95-.46-1.25l-1.35-1.59c-.58-.69-.58-1.81 0-2.5l1.35-1.59c.26-.3.46-.86.46-1.25V6.2c0-1.06.87-1.93 1.93-1.93H7.9c.4 0 .96-.21 1.26-.47l1.59-1.35Z" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>',
            'clock' => '<path d="M22 12c0 5.52-4.48 10-10 10S2 17.52 2 12 6.48 2 12 2s10 4.48 10 10Z" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="m15.71 15.18-3.1-1.85c-.54-.32-.98-1.09-.98-1.72v-4.1" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>',
            'lamp' => '<path d="M8.3 18.04h7.4M9.5 21.5h5M12 2.5c-4.42 0-8 3.58-8 8 0 2.86 1.51 5.37 3.77 6.77.38.23.62.64.62 1.08v.15h7.22v-.15c0-.44.24-.85.62-1.08A7.98 7.98 0 0 0 20 10.5c0-4.42-3.58-8-8-8Z" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>',
            'tick-circle' => '<path d="M12 22c5.5 0 10-4.5 10-10S17.5 2 12 2 2 6.5 2 12s4.5 10 10 10Z" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="m7.75 12 2.83 2.83 5.67-5.66" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>',
            'info-circle' => '<path d="M12 22c5.5 0 10-4.5 10-10S17.5 2 12 2 2 6.5 2 12s4.5 10 10 10Z" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 8v5M11.995 16h.009" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>',
            'message-question' => '<path d="M17 18.43h-4l-4.45 2.96A.997.997 0 0 1 7 20.56v-2.13c-3 0-5-2-5-5v-6c0-3 2-5 5-5h10c3 0 5 2 5 5v6c0 3-2 5-5 5Z" stroke-width="1.75" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 11.36v-.21c0-.68.42-1.04.84-1.33.41-.28.82-.64.82-1.3 0-.92-.74-1.66-1.66-1.66-.92 0-1.66.74-1.66 1.66M11.995 13.75h.01" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>',
            'award' => '<path d="M12 15c3.866 0 7-3.134 7-7s-3.134-7-7-7-7 3.134-7 7 3.134 7 7 7Z" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="M7.52 13.52v7.39c0 .9.63 1.34 1.4.96l2.68-1.27c.22-.11.58-.11.8 0l2.69 1.27c.77.37 1.4-.07 1.4-.96v-7.39" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>',
            'arrow-up' => '<path d="M12 20V4M12 4l-6.5 6.5M12 4l6.5 6.5" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>',
            'sparkles' => '<path d="M12 3.5l1.75 4.75L18.5 10l-4.75 1.75L12 16.5l-1.75-4.75L5.5 10l4.75-1.75L12 3.5Z" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="M18.5 15.5l.9 2.35L21.75 18.75l-2.35.9-.9 2.35-.9-2.35-2.35-.9 2.35-.9.9-2.35Z" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>',
            'announce' => '<path d="M4 10.25v3.5c0 .69.56 1.25 1.25 1.25h2.32l3.62 3.4c.81.76 2.14.19 2.14-.93V6.53c0-1.12-1.33-1.69-2.14-.93l-3.62 3.4H5.25c-.69 0-1.25.56-1.25 1.25Z" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="M16.5 8.75a4.75 4.75 0 0 1 0 6.5M18.75 6.5a8 8 0 0 1 0 11" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>',
            'arrow-left' => '<path d="M20 12H4M4 12l6.5-6.5M4 12l6.5 6.5" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>',
            'search' => '<circle cx="11" cy="11" r="7.25" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="M20.75 20.75 16.1 16.1" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>',
            default => '<circle cx="12" cy="12" r="9" stroke-width="1.75"/>',
        };
        return '<svg class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' . $paths . '</svg>';
    }

    /**
     * ایجاد مقاله لیستی پیش‌فرض («بهترین کافه‌ها و رستوران‌های رشت») در صورت خالی بودن جدول articles
     */
    public static function ensureDefaultListArticle(): void
    {
        // Content is created explicitly by an editor; public reads never seed records.
    }

    /** Render the public home page (S16 بازسازی مطابق نسخه زنده: هیرو + جستجو + تب‌های دسته + بنتو + هاب شهرها + CTA). */
    public static function home(Request $req, array $params = []): void
    {
        self::ensureDefaultListArticle();

        $cities = DB::fetchAll(
            "SELECT c.id, c.slug, c.name_fa, c.lat, c.lng,
                    (SELECT COUNT(*) FROM businesses b WHERE b.city_id = c.id AND b.status = 'published') AS biz_count
             FROM cities c
             WHERE c.is_active = 1
             ORDER BY c.id ASC"
        );

        $categories = DB::fetchAll(
            "SELECT id, slug, name_fa, sort
             FROM categories
             WHERE is_active = 1
             ORDER BY sort ASC, id ASC"
        );

        // مقاله راهنمای شاخص (بنتو) — آخرین راهنمای منتشرشده با شمار آیتم و کاور معتبر
        $featured = null;
        try {
            $featured = DB::fetchOne(
                "SELECT a.id, a.slug, a.title, a.meta_description, a.year_label, a.published_at,
                        c.name_fa AS city_name_fa, c.slug AS city_slug,
                        cat.name_fa AS category_name_fa, cat.slug AS category_slug,
                        (SELECT COUNT(*) FROM article_items ai WHERE ai.article_id = a.id) AS items_count,
                        (SELECT m.file_path_800 FROM article_extras x LEFT JOIN media m ON m.id = x.cover_media_id WHERE x.article_id = a.id) AS cover_url
                 FROM articles a
                 LEFT JOIN cities c ON c.id = a.city_id
                 LEFT JOIN categories cat ON cat.id = a.category_id
                 WHERE a.status = 'published'
                 ORDER BY a.published_at DESC, a.id DESC
                 LIMIT 1"
            );
        } catch (Throwable $e) {
            error_log('HX home featured: ' . $e->getMessage());
            $featured = null;
        }
        $featuredCover = (string)($featured['cover_url'] ?? '');
        if (!preg_match('~^/uploads/[a-zA-Z0-9_/-]+\.(?:webp|jpg|jpeg|png)$~iD', $featuredCover)) $featuredCover = '';

        $businesses = self::fetchPublishedBusinesses("b.status = 'published'", [], 12);

        $totalPublished = 0;
        foreach ($cities as $c) { $totalPublished += (int)$c['biz_count']; }
        $publishedArticlesTotal = (int)(DB::fetchOne("SELECT COUNT(*) AS n FROM articles WHERE status = 'published'", [])['n'] ?? 0);

        // شمارش کارت‌های هر دسته فقط از میان ۱۲ کارت نمایش‌داده‌شده تا تب‌ها دقیقاً با گرید هم‌خوان باشند
        $tabCounts = [];
        foreach ($businesses as $biz) {
            $slug = (string)$biz['category_slug'];
            if ($slug === '') continue;
            if (!isset($tabCounts[$slug])) $tabCounts[$slug] = ['name' => (string)$biz['category_name_fa'], 'n' => 0];
            $tabCounts[$slug]['n']++;
        }
        uasort($tabCounts, static fn(array $a, array $b): int => $b['n'] <=> $a['n']);

        // شمارش کسب‌وکار هر (شهر، دسته) برای چیپ‌های هاب شهرها
        $hubCategoryCounts = [];
        try {
            foreach (DB::fetchAll(
                "SELECT b.city_id, b.category_id, COUNT(*) AS n
                 FROM businesses b
                 WHERE b.status = 'published'
                 GROUP BY b.city_id, b.category_id"
            ) as $row) {
                $hubCategoryCounts[(int)$row['city_id']][(int)$row['category_id']] = (int)$row['n'];
            }
        } catch (Throwable $e) { $hubCategoryCounts = []; }

        $hubDots = ['#1d4ed8', '#00875a', '#b75e09', '#9f1239', '#4338ca', '#0369a1', '#7c2d12', '#0f766e', '#a21caf'];

        $baseUrl = self::baseUrl();
        $schemas = [
            [
                '@context'    => 'https://schema.org',
                '@type'       => 'WebSite',
                'name'        => 'حامی‌هاب گیلان | HamiHubX.ir',
                'url'         => $baseUrl . '/',
                'description' => 'فهرست کسب‌وکارها، کافه‌ها، رستوران‌ها و خدمات استان گیلان',
            ],
            [
                '@context'        => 'https://schema.org',
                '@type'           => 'ItemList',
                'name'            => 'کسب‌وکارهای منتشرشده استان گیلان',
                'itemListElement' => self::buildItemListElements($businesses, $baseUrl),
            ],
        ];

        ob_start();
        ?>
        <section class="hx-hero">
            <div class="hx-container hx-hero-inner">
                <span class="hx-hero-badge">
                    <?= self::icon('verify', 'hx-icon hx-icon-sm') ?>
                    <span>حامی‌هاب گیلان · فهرست کسب‌وکارهای محلی استان گیلان</span>
                </span>
                <h1>بهترین کافه‌ها، رستوران‌ها و خدمات استان گیلان</h1>
                <p>فهرست کسب‌وکارها بر اساس اطلاعات گردآوری‌شده، بازبینی تحریریه و موقعیت مکانی معتبر ارائه می‌شود.</p>
                <div class="hx-hero-stats" role="list" aria-label="آمار کلی حامی‌هاب">
                    <div class="hx-hero-stat" role="listitem">
                        <strong dir="ltr"><?= self::e(self::faDigits((string)$totalPublished)) ?></strong>
                        <span>کسب‌وکار منتشرشده</span>
                    </div>
                    <div class="hx-hero-stat" role="listitem">
                        <strong dir="ltr"><?= self::e(self::faDigits((string)count($cities))) ?></strong>
                        <span>شهر فعال</span>
                    </div>
                    <div class="hx-hero-stat" role="listitem">
                        <strong dir="ltr"><?= self::e(self::faDigits((string)$publishedArticlesTotal)) ?></strong>
                        <span>راهنمای منتشرشده</span>
                    </div>
                </div>
                <form class="hx-hero-search" action="/search" method="get" role="search" aria-label="جستجوی کسب‌وکارها و راهنماها">
                    <div class="hx-search-field">
                        <?= self::icon('search') ?>
                        <input type="search" name="q" maxlength="80" placeholder="نام کسب‌وکار، صنف (مثلاً سالن زیبایی، رستوران، ویلا)…" aria-label="عبارت جستجو">
                    </div>
                    <div class="hx-search-field hx-search-field-city">
                        <?= self::icon('location') ?>
                        <select name="city" aria-label="انتخاب شهر">
                            <option value="">همه شهرهای گیلان</option>
                            <?php foreach ($cities as $city): ?>
                                <?php $cnt = (int)$city['biz_count']; ?>
                                <option value="<?= self::e($city['slug']) ?>"><?= self::e($city['name_fa']) ?><?= $cnt > 0 ? ' (' . self::e(self::faDigits((string)$cnt)) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="hx-search-btn" type="submit">
                        <span>جستجو</span>
                        <?= self::icon('arrow-left', 'hx-icon hx-icon-sm') ?>
                    </button>
                </form>
                <div class="hx-hero-quick">
                    <span class="hx-hero-quick-label">دسترسی سریع شهری:</span>
                    <div class="hx-city-pills">
                        <?php foreach ($cities as $city): ?>
                            <a class="hx-city-pill" href="/city/<?= self::e($city['slug']) ?>/">
                                <?= self::icon('location', 'hx-icon hx-icon-sm') ?>
                                <span><?= self::e($city['name_fa']) ?></span>
                                <small style="opacity:0.75;">(<?= (int)$city['biz_count'] ?>)</small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>

        <main class="hx-container" data-hx-page-view>
            <section class="hx-section" id="top-lists">
                <div class="hx-section-head">
                    <div>
                        <h2 class="hx-section-title">
                            <?= self::icon('sparkles') ?>
                            <span>راهنماها و مقالات لیستی منتخب (Top Lists)</span>
                        </h2>
                        <p class="hx-section-sub">راهنماها و مقالات منتشرشده توسط تحریریه تخصصی حامی‌هاب گیلان</p>
                    </div>
                </div>
                <?php if (!empty($featured)): ?>
                <div class="hx-bento">
                    <article class="hx-bento-featured">
                        <div class="hx-bento-featured-media">
                            <?php if ($featuredCover !== ''): ?>
                                <img src="<?= self::e($featuredCover) ?>" alt="" loading="lazy" decoding="async" width="800" height="500">
                            <?php endif; ?>
                            <span class="hx-bento-flag"><?= self::icon('award', 'hx-icon hx-icon-sm') ?> <?= !empty($featured['category_name_fa']) ? self::e($featured['category_name_fa']) : 'راهنمای تحریریه' ?><?= !empty($featured['year_label']) ? ' · ' . self::e($featured['year_label']) : '' ?></span>
                            <span class="hx-bento-rank-note">رتبه‌بندی برترین‌ها</span>
                        </div>
                        <div class="hx-bento-featured-body">
                            <div class="hx-bento-meta">
                                <span class="hx-badge hx-badge-tier-a"><?= !empty($featured['city_name_fa']) ? self::e($featured['city_name_fa']) : 'گیلان' ?> · <?= self::e(self::faDigits((string)(int)$featured['items_count'])) ?> مورد برگزیده</span>
                                <?php if (!empty($featured['category_name_fa'])): ?><span class="hx-badge hx-badge-cat"><?= self::e($featured['category_name_fa']) ?></span><?php endif; ?>
                            </div>
                            <h3 class="hx-bento-title">
                                <a href="/<?= self::e($featured['slug']) ?>/"><?= self::e($featured['title']) ?></a>
                            </h3>
                            <?php if (!empty($featured['meta_description'])): ?>
                                <p class="hx-bento-desc"><?= self::e($featured['meta_description']) ?></p>
                            <?php endif; ?>
                            <div class="hx-bento-foot">
                                <span class="hx-bento-read"><?= self::icon('clock', 'hx-icon hx-icon-sm') ?> <?= self::e(self::faDigits((string)(int)$featured['items_count'])) ?> مورد برگزیده در این راهنما</span>
                                <a class="hx-btn hx-btn-primary" href="/<?= self::e($featured['slug']) ?>/">خواندن مقاله <?= self::icon('arrow-left', 'hx-icon hx-icon-sm') ?></a>
                            </div>
                        </div>
                    </article>
                    <aside class="hx-bento-trust">
                        <div>
                            <span class="hx-bento-trust-icon"><?= self::icon('verify') ?></span>
                            <span class="hx-bento-trust-label">اصالت داده‌ها و راستی‌آزمایی</span>
                            <h4 class="hx-bento-trust-title">ثبت تضمینی کسب‌وکارها</h4>
                            <p class="hx-bento-trust-desc">تمام اطلاعات تماس، موقعیت مکانی ثبت‌شده در نقشه‌های مرجع و تصاویر محیطی پس از بازبینی تحریریه در فهرست درج می‌شوند.</p>
                        </div>
                        <div class="hx-bento-trust-foot">
                            <span>آیا صاحب کسب‌وکاری هستید؟</span>
                            <a class="hx-bento-trust-link" href="#advertise">درخواست بررسی <?= self::icon('arrow-left', 'hx-icon hx-icon-sm') ?></a>
                        </div>
                    </aside>
                </div>
                <?php endif; ?>
            </section>

            <section class="hx-section">
                <div class="hx-section-head">
                    <div>
                        <h2 class="hx-section-title">
                            <?= self::icon('star') ?>
                            <span>برترین کسب‌وکارهای منتشرشده در گیلان</span>
                        </h2>
                        <p class="hx-section-sub">فهرست منتخبان با بیشترین رضایت، آدرس‌های اعتبارسنجی‌شده و دسترسی مستقیم</p>
                    </div>
                </div>
                <?php if (empty($businesses)): ?>
                    <div class="hx-card">
                        <p class="hx-card-desc">به‌زودی فهرست کسب‌وکارهای منتخب استان گیلان در این بخش نمایش داده می‌شود.</p>
                    </div>
                <?php else: ?>
                <div class="hx-filter-tabs" data-hx-filter-tabs role="group" aria-label="فیلتر دسته‌بندی کسب‌وکارها">
                    <button type="button" class="hx-tab-active" data-hx-cat="all">همه دسته‌ها</button>
                    <?php foreach ($tabCounts as $slug => $tab): ?>
                        <button type="button" data-hx-cat="<?= self::e($slug) ?>"><?= self::e($tab['name']) ?> <span class="hx-tab-count">(<?= self::e(self::faDigits((string)$tab['n'])) ?>)</span></button>
                    <?php endforeach; ?>
                </div>
                <div class="hx-grid hx-grid-3">
                    <?php foreach ($businesses as $idx => $biz): ?>
                        <?= self::renderBusinessCard($biz, $idx + 1, 'home') ?>
                    <?php endforeach; ?>
                </div>
                <div class="hx-view-all">
                    <a class="hx-btn hx-btn-outline hx-btn-lg" href="/businesses/">مشاهده همه <?= self::e(self::faDigits((string)$totalPublished)) ?> کسب‌وکار تاییدشده <?= self::icon('arrow-left', 'hx-icon hx-icon-sm') ?></a>
                </div>
                <?php endif; ?>
            </section>

            <section class="hx-section" id="city-hubs">
                <div class="hx-section-head">
                    <div>
                        <h2 class="hx-section-title">
                            <?= self::icon('map') ?>
                            <span>دسته‌بندی‌های تخصصی در شهرهای گیلان</span>
                        </h2>
                        <p class="hx-section-sub">انتخاب سریع بر اساس شهر و صنف خدماتی مورد نظر در استان گیلان</p>
                    </div>
                </div>
                <div class="hx-grid hx-grid-3">
                    <?php foreach ($cities as $cityIdx => $city): ?>
                        <?php
                            $cityId = (int)$city['id'];
                            $cityBizCount = (int)$city['biz_count'];
                            $dot = $hubDots[$cityIdx % count($hubDots)];
                        ?>
                        <div class="hx-hub-tile">
                            <div class="hx-hub-head">
                                <div class="hx-hub-title">
                                    <span class="hx-hub-dot" style="background: <?= self::e($dot) ?>;"></span>
                                    <h3><a href="/city/<?= self::e($city['slug']) ?>/">بهترین‌های <?= self::e($city['name_fa']) ?></a></h3>
                                </div>
                                <span class="hx-hub-count"><?= self::e(self::faDigits((string)$cityBizCount)) ?> کسب‌وکار</span>
                            </div>
                            <p class="hx-hub-sub">دسترسی مستقیم به فهرست تاییدشده <?= self::e($city['name_fa']) ?>:</p>
                            <div class="hx-hub-chips">
                                <?php foreach ($categories as $cat): ?>
                                    <?php $n = $hubCategoryCounts[$cityId][(int)$cat['id']] ?? 0; ?>
                                    <?php if ($n <= 0) continue; ?>
                                    <a class="hx-hub-chip" href="/city/<?= self::e($city['slug']) ?>/<?= self::e($cat['slug']) ?>/"><?= self::e($cat['name_fa']) ?> <?= self::e($city['name_fa']) ?></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="hx-cta-banner" id="sponsor">
                <span class="hx-cta-badge">
                    <?= self::icon('announce', 'hx-icon hx-icon-sm') ?>
                    <span>جایگاه ویژه · بنر · معرفی اختصاصی</span>
                </span>
                <h2>کسب‌وکار شما در برترین‌های گیلان دیده شود</h2>
                <p>صفحات شهر و دسته‌بندی حامی‌هاب روزانه بازدید واقعی دارند؛ کسب‌وکار شما را در اولین انتخاب کاربر قرار دهید.</p>
                <div class="hx-cta-actions">
                    <a class="hx-btn hx-btn-primary hx-btn-lg" href="/advertise" data-hx-ad-modal>درخواست تبلیغات در حامی‌هاب <?= self::icon('arrow-left', 'hx-icon hx-icon-sm') ?></a>
                    <a class="hx-btn hx-btn-ghost-light hx-btn-lg" href="#city-hubs">مشاهده صفحات شهرها</a>
                </div>
            </section>
        </main>
        <?php
        $bodyHtml = (string)ob_get_clean();

        $fullHtml = self::renderLayout([
            'title'          => 'حامی‌هاب گیلان (HamiHubX) | بهترین کافه‌ها، رستوران‌ها و خدمات استان گیلان',
            'description'    => 'پلتفرم هوشمند معرفی و رتبه‌بندی بهترین کسب‌وکارها، کافه‌ها، رستوران‌ها و مراکز خدماتی در رشت، انزلی، لاهیجان و سراسر استان گیلان.',
            'canonical'      => $baseUrl . '/',
            'og_type'        => 'website',
            'schemas'        => $schemas,
            'ad_placement'   => 'home',
            'body'           => $bodyHtml,
        ]);

        Cache::putPage('/', $fullHtml);
        Response::html($fullHtml);
    }

    /** Old business links remain permanent redirects to the current city-first address. */
    public static function legacyBusinessDetail(Request $req, array $params = []): void
    {
        $slug = rawurldecode((string)($params['slug'] ?? ''));
        $biz = DB::fetchOne("SELECT b.id,b.slug,c.slug AS city_slug FROM businesses b INNER JOIN cities c ON c.id=b.city_id WHERE b.slug=:slug AND b.status='published' LIMIT 1", ['slug'=>$slug]);
        $legacyPath = BusinessUrlService::legacyPath($slug);
        $biz ??= BusinessUrlService::resolveAlias($legacyPath);
        if (!$biz) {
            $seoRule = BusinessSeoService::resolveRule($legacyPath);
            if ($seoRule !== null) {
                if (($seoRule['http_status'] ?? 410) === 301 && !empty($seoRule['redirect_to'])) {
                    Response::redirect((string)$seoRule['redirect_to']);
                }
                self::render410($seoRule);
                return;
            }
            self::render404('کسب‌وکار مورد نظر یافت نشد یا هنوز منتشر نشده است.');
            return;
        }
        Response::redirect(BusinessUrlService::path((string)$biz['city_slug'], (string)$biz['slug']));
    }

    /** GET /{city}/{slug} — فقط شهر و کسب‌وکار واقعیِ منتشرشده حل می‌شود؛ ریدیزاین کامل مرحله S16. */
    public static function businessDetail(Request $req, array $params = []): void
    {
        $slug = rawurldecode((string)($params['slug'] ?? ''));
        $city = rawurldecode((string)($params['city'] ?? ''));

        $rows = self::fetchPublishedBusinesses("b.slug = :slug AND c.slug = :city AND b.status = 'published'", ['slug' => $slug, 'city' => $city], 1);
        $biz = $rows[0] ?? null;

        if (!$biz) {
            $candidatePath = BusinessUrlService::path($city, $slug);
            $alias = BusinessUrlService::resolveAlias($candidatePath);
            if ($alias) Response::redirect(BusinessUrlService::path((string)$alias['city_slug'], (string)$alias['slug']));
            $seoRule = BusinessSeoService::resolveRule($candidatePath);
            if ($seoRule !== null) {
                if (($seoRule['http_status'] ?? 410) === 301 && !empty($seoRule['redirect_to'])) {
                    Response::redirect((string)$seoRule['redirect_to']);
                }
                self::render410($seoRule);
                return;
            }
            self::render404('کسب‌وکار مورد نظر یافت نشد یا هنوز در وضعیت منتشرشده قرار نگرفته است.');
            return;
        }

        $mediaItems = DB::fetchAll(
            "SELECT m.*
             FROM media m
             LEFT JOIN business_photos bp ON bp.media_id = m.id AND bp.business_id = :bid AND bp.status = 'approved'
             WHERE m.id = :cover_id OR bp.business_id = :bid2
             ORDER BY (m.id = :cover_id2) DESC, bp.sort ASC, m.id DESC",
            [
                'bid'       => (int)$biz['id'],
                'cover_id'  => (int)($biz['cover_media_id'] ?? 0),
                'bid2'      => (int)$biz['id'],
                'cover_id2' => (int)($biz['cover_media_id'] ?? 0),
            ]
        );

        // S16: همه راه‌های تماس از business_contacts
        $contacts = [];
        foreach (DB::fetchAll("SELECT type, value FROM business_contacts WHERE business_id = :bid ORDER BY is_primary DESC, id ASC", ['bid' => (int)$biz['id']]) as $row) {
            $t = (string)$row['type'];
            if (!isset($contacts[$t])) $contacts[$t] = (string)$row['value'];
        }

        // S16: امتیازهای همه منابع بیرونی (برچسب ریز پروایدر زیر هر امتیاز)
        $providerRatings = DB::fetchAll(
            "SELECT provider, rating, reviews_count, fetched_at
             FROM business_sources
             WHERE business_id = :bid AND rating BETWEEN 1 AND 5
             ORDER BY reviews_count DESC, fetched_at DESC
             LIMIT 6",
            ['bid' => (int)$biz['id']]
        );

        // S16: دیدگاه‌های تاییدشده همین مجموعه + میانگین
        $comments = [];
        $commentStats = ['avg' => null, 'count' => 0];
        try {
            $comments = CommentService::publicList((int)$biz['id'], 20);
            $statsRow = DB::fetchOne("SELECT COALESCE(AVG(rating),0) AS avg_r, COUNT(*) AS n FROM business_comments WHERE business_id = :bid AND status = 'approved'", ['bid' => (int)$biz['id']]);
            $commentStats = ['avg' => (float)($statsRow['avg_r'] ?? 0), 'count' => (int)($statsRow['n'] ?? 0)];
            if ($commentStats['count'] === 0) $commentStats['avg'] = null;
        } catch (Throwable $e) {
            error_log('HX comments read (detail page kept intact): ' . $e->getMessage());
        }

        $related = self::fetchPublishedBusinesses(
            "b.city_id = :city_id AND b.category_id = :cat_id AND b.id != :id AND b.status = 'published'",
            [
                'city_id' => (int)$biz['city_id'],
                'cat_id'  => (int)$biz['category_id'],
                'id'      => (int)$biz['id'],
            ],
            3
        );

        $baseUrl = self::baseUrl();
        $canonical = $baseUrl . BusinessUrlService::path((string)$biz['city_slug'], (string)$biz['slug']);
        $detailUrl = BusinessUrlService::path((string)$biz['city_slug'], (string)$biz['slug']);

        // اسکیمای LocalBusiness استاندارد؛ aggregateRating فقط از امتیاز واقعی منبع بیرونی
        $localBizSchema = [
            '@context' => 'https://schema.org',
            '@type'    => 'LocalBusiness',
            '@id'      => $canonical . '#business',
            'name'     => (string)$biz['name'],
            'url'      => $canonical,
            'address'  => [
                '@type'           => 'PostalAddress',
                'addressLocality' => (string)$biz['city_name_fa'],
                'addressRegion'   => 'گیلان',
                'addressCountry'  => 'IR',
            ],
        ];
        if (!empty($biz['address'])) $localBizSchema['address']['streetAddress'] = (string)$biz['address'];
        if (!empty($biz['short_desc'])) {
            $localBizSchema['description'] = (string)$biz['short_desc'];
        }
        if (!empty($biz['phone'])) {
            $localBizSchema['telephone'] = (string)$biz['phone'];
        }
        if (!empty($biz['lat']) && !empty($biz['lng'])) {
            $localBizSchema['geo'] = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float)$biz['lat'],
                'longitude' => (float)$biz['lng'],
            ];
        }
        if (!empty($biz['hours'])) {
            $localBizSchema['openingHours'] = (string)$biz['hours'];
        }
        if (!empty($providerRatings)) {
            $best = $providerRatings[0];
            $localBizSchema['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (float)$best['rating'],
                'reviewCount' => (int)$best['reviews_count'],
                'bestRating'  => 5,
            ];
        }

        $breadcrumbSchema = self::buildBreadcrumbSchema([
            ['name' => 'حامی‌هاب گیلان', 'url' => $baseUrl . '/'],
            ['name' => (string)$biz['city_name_fa'], 'url' => $baseUrl . '/city/' . $biz['city_slug'] . '/'],
            ['name' => (string)$biz['category_name_fa'], 'url' => $baseUrl . '/city/' . $biz['city_slug'] . '/' . $biz['category_slug'] . '/'],
            ['name' => (string)$biz['name'], 'url' => $canonical],
        ]);

        $mapMarkers = self::extractMarkers([$biz]);
        $bullets = !empty($biz['bullets_json']) ? (json_decode((string)$biz['bullets_json'], true) ?: []) : [];
        $bizFaqs = !empty($biz['faqs_json']) ? (json_decode((string)$biz['faqs_json'], true) ?: []) : [];
        if (is_array($bullets)) { $bullets = array_values(array_filter(array_map('strval', $bullets), static fn(string $b): bool => trim($b) !== '')); }
        $gallery = array_slice($mediaItems, 0, 4);
        $galleryCount = count($mediaItems);

        $primaryPhone = (string)($contacts['phone'] ?? $contacts['mobile'] ?? $biz['phone'] ?? '');
        $providers = ['google_maps' => 'Google Maps', 'osm' => 'OpenStreetMap', 'neshan' => 'نشان', 'balad' => 'بلد', 'gosom' => 'Google Maps'];
        // S17: Place ID واقعی گوگل (اگر در منابع ثبت شده باشد) برای destination_place_id در مسیریابی
        $googlePlaceId = MapLinks::googlePlaceId((int)$biz['id']);

        ob_start();
        ?>
        <main class="hx-container hx-detail-page" data-hx-page-view data-business-id="<?= (int)$biz['id'] ?>">
            <nav class="hx-breadcrumb" aria-label="مسیر صفحه">
                <a href="/">حامی‌هاب گیلان</a>
                <span>/</span>
                <a href="/city/<?= self::e($biz['city_slug']) ?>/"><?= self::e($biz['city_name_fa']) ?></a>
                <span>/</span>
                <a href="/city/<?= self::e($biz['city_slug']) ?>/<?= self::e($biz['category_slug']) ?>/"><?= self::e($biz['category_name_fa']) ?></a>
                <span>/</span>
                <strong><?= self::e($biz['name']) ?></strong>
            </nav>

            <?php if (!empty($gallery)): ?>
            <section class="hx-detail-gallery" aria-label="تصاویر <?= self::e($biz['name']) ?>">
                <div class="hx-gallery-grid<?= count($gallery) === 1 ? ' hx-gallery-single' : '' ?>">
                    <?php foreach ($gallery as $gIdx => $g): ?>
                        <?php
                            $src = (string)($g['file_path_1200'] ?? $g['file_path_800'] ?? '');
                            if (!preg_match('~^/uploads/[a-zA-Z0-9_/-]+\.(?:webp|jpg|jpeg|png|gif)$~iD', $src)) continue;
                        ?>
                        <figure class="hx-gallery-item<?= $gIdx === 0 ? ' hx-gallery-main' : '' ?>">
                            <img src="<?= self::e($src) ?>" alt="<?= self::e((string)($g['alt_text'] ?? $biz['name'])) ?>" loading="<?= $gIdx === 0 ? 'eager' : 'lazy' ?>" decoding="async">
                        </figure>
                    <?php endforeach; ?>
                    <?php if ($galleryCount > 4): ?>
                        <figure class="hx-gallery-item hx-gallery-more">
                            <span>+<?= self::e(self::faDigits((string)($galleryCount - 4))) ?></span>
                            <small>تصویر دیگر</small>
                        </figure>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <article class="hx-detail-hero" id="hx-about">
                <div class="hx-card-top">
                    <div style="display:flex;gap:0.45rem;flex-wrap:wrap;align-items:center;">
                        <span class="hx-badge hx-badge-cat"><?= self::e($biz['category_name_fa']) ?> · <?= self::e($biz['city_name_fa']) ?></span>
                        <?php if (($biz['tier'] ?? '') === 'A'): ?>
                            <span class="hx-badge hx-badge-tier-a"><?= self::icon('verify', 'hx-icon hx-icon-sm') ?> تایید تحریریه حامی‌هاب</span>
                        <?php endif; ?>
                        <?php if (!empty($biz['promoted'])): ?><span class="hx-badge hx-badge-promoted">جایگاه ویژه</span><?php endif; ?>
                    </div>
                </div>
                <h1><?= self::e($biz['name']) ?></h1>
                <?php if (!empty($biz['short_desc'])): ?>
                    <p class="hx-detail-tagline"><?= self::e((string)$biz['short_desc']) ?></p>
                <?php endif; ?>
                <?php if (!empty($biz['address'])): ?>
                    <p class="hx-detail-address">
                        <?= self::icon('location', 'hx-icon hx-icon-sm') ?>
                        <span><?= self::e((string)$biz['address']) ?></span>
                        <a class="hx-detail-maplink" href="#hx-location">مشاهده روی نقشه</a>
                    </p>
                <?php endif; ?>

                <div class="hx-rating-summary">
                    <?php if (!empty($biz['source_rating'])): ?>
                        <div class="hx-rating-box">
                            <strong dir="ltr"><?= self::e(rtrim(rtrim(number_format((float)$biz['source_rating'], 2, '.', ''), '0'), '.')) ?></strong>
                            <span>از ۵ · <?= self::e($providers[(string)($biz['rating_provider'] ?? '')] ?? (string)($biz['rating_provider'] ?? '')) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($commentStats['count'] > 0): ?>
                        <div class="hx-rating-box hx-rating-own">
                            <strong dir="ltr"><?= self::e(rtrim(rtrim(number_format((float)$commentStats['avg'], 2, '.', ''), '0'), '.')) ?></strong>
                            <span>از ۵ · <?= self::e(self::faDigits((string)$commentStats['count'])) ?> دیدگاه حامی‌هاب</span>
                        </div>
                    <?php endif; ?>
                    <div class="hx-provider-chips">
                        <?php foreach ($providerRatings as $pr): ?>
                            <span class="hx-provider-chip">
                                <b><?= self::e($providers[(string)$pr['provider']] ?? (string)$pr['provider']) ?></b>
                                <span dir="ltr"><?= self::e(rtrim(rtrim(number_format((float)$pr['rating'], 2, '.', ''), '0'), '.')) ?></span>
                                <span>★</span>
                                <span><?= self::e(self::faDigits((string)(int)$pr['reviews_count'])) ?> نظر</span>
                                <?php if (!empty($pr['fetched_at'])): ?><small>دریافت <?= self::e(self::faJalali((string)$pr['fetched_at'])) ?></small><?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="hx-card-actions">
                    <?php if ($primaryPhone !== ''): ?>
                        <a class="hx-btn hx-btn-primary" href="tel:<?= self::e((string)($contacts['phone'] ?? $contacts['mobile'] ?? $primaryPhone)) ?>" data-hx-track="call" data-business-id="<?= (int)$biz['id'] ?>" data-source-page="business_detail">
                            <?= self::icon('call', 'hx-icon hx-icon-sm') ?>
                            <span>تماس مستقیم با <?= self::e($biz['name']) ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($biz['lat']) && !empty($biz['lng'])): ?>
                        <?= self::mapActionLinks($biz, $googlePlaceId, 'business_detail', 'hero') ?>
                    <?php endif; ?>
                    <button type="button" class="hx-btn hx-btn-outline" data-hx-open-comment-form>
                        <?= self::icon('message-question', 'hx-icon hx-icon-sm') ?>
                        <span>ثبت دیدگاه شما</span>
                    </button>
                </div>
            </article>

            <nav class="hx-subnav" aria-label="بخش‌های صفحه">
                <a href="#hx-about">معرفی</a>
                <?php if (!empty($bullets)): ?><a href="#hx-amenities">امکانات و خدمات</a><?php endif; ?>
                <?php if (!empty($biz['hours'])): ?><a href="#hx-hours">ساعات کاری</a><?php endif; ?>
                <a href="#hx-location">موقعیت روی نقشه</a>
                <a href="#hx-reviews">دیدگاه‌ها<?= $commentStats['count'] > 0 ? ' (' . self::e(self::faDigits((string)$commentStats['count'])) . ')' : '' ?></a>
                <?php if (!empty($related)): ?><a href="#hx-similar">گزینه‌های مشابه</a><?php endif; ?>
            </nav>

            <div class="hx-detail-grid-main">
                <div class="hx-detail-content">
                    <?php if (!empty($bullets)): ?>
                    <section class="hx-detail-section" id="hx-amenities">
                        <h2><?= self::icon('award') ?> امکانات و خدمات <?= self::e($biz['name']) ?></h2>
                        <div class="hx-amenity-grid">
                            <?php foreach (array_slice($bullets, 0, 12) as $bullet): ?>
                                <div class="hx-amenity-item">
                                    <span class="hx-amenity-dot"></span>
                                    <span><?= self::e(trim($bullet)) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <?php if (!empty($biz['hours'])): ?>
                    <section class="hx-detail-section" id="hx-hours">
                        <h2><?= self::icon('clock') ?> ساعات کاری</h2>
                        <div class="hx-info-panel"><?= self::e((string)$biz['hours']) ?></div>
                    </section>
                    <?php endif; ?>

                    <section class="hx-detail-section" id="hx-location">
                        <h2><?= self::icon('map') ?> موقعیت مکانی</h2>
                        <?php if (!empty($mapMarkers)): ?>
                            <?= self::renderLazyMapBox($mapMarkers, 'موقعیت ' . $biz['name'] . ' روی نقشه تعاملی') ?>
                        <?php else: ?>
                            <div class="hx-info-panel">مختصات دقیق این مجموعه هنوز ثبت نشده است؛ برای مسیریابی از نشانی بالا استفاده کنید.</div>
                        <?php endif; ?>
                    </section>

                    <?php if (!empty($bizFaqs) && is_array($bizFaqs)): ?>
                    <section class="hx-detail-section" id="hx-faqs">
                        <h2><?= self::icon('message-question') ?> پرسش‌های پرتکرار</h2>
                        <div class="hx-faq-list">
                            <?php foreach ($bizFaqs as $faq): ?>
                                <?php if (!is_array($faq)) continue; ?>
                                <details class="hx-faq-item">
                                    <summary><?= self::e((string)($faq['question'] ?? $faq['question_fa'] ?? '')) ?></summary>
                                    <p><?= self::e((string)($faq['answer'] ?? $faq['answer_fa'] ?? '')) ?></p>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <section class="hx-detail-section" id="hx-reviews">
                        <div class="hx-reviews-head">
                            <h2><?= self::icon('star') ?> دیدگاه‌ها و امتیازها</h2>
                            <button type="button" class="hx-btn hx-btn-primary" data-hx-open-comment-form>
                                <?= self::icon('message-question', 'hx-icon hx-icon-sm') ?>
                                <span>ثبت دیدگاه</span>
                            </button>
                        </div>

                        <?php /* S20: فرم ثبت دیدگاه درون‌صفحه‌ای؛ بدون تب جدید. سوال امنیتی برای صفحات کش‌شده با جاوااسکریپت تازه گرفته می‌شود. */ ?>
                        <div class="hx-inline-comment hx-comment-card" id="hx-inline-comment" hidden>
                            <h3>ثبت دیدگاه درباره «<?= self::e($biz['name']) ?>»</h3>
                            <p class="hx-comment-sub">دیدگاه شما مستقیماً برای تحریریه حامی‌هاب ثبت می‌شود؛ پس از تایید، با نشان «تایید حامی‌هاب» در همین صفحه نمایش داده می‌شود.</p>
                            <form data-hx-public-form="comment" data-hx-inline-comment-form method="post" action="/api/v1/public/comments">
                                <input type="hidden" name="business_id" value="<?= (int)$biz['id'] ?>">
                                <input type="hidden" name="hx_a" value="">
                                <input type="hidden" name="hx_b" value="">
                                <input type="hidden" name="hx_ts" value="">
                                <input type="hidden" name="hx_sig" value="">
                                <input type="text" name="website_hp" value="" tabindex="-1" autocomplete="off" style="display:none!important;" aria-hidden="true">
                                <div class="hx-comment-grid">
                                    <div>
                                        <label for="hx-i-name">نام شما *</label>
                                        <input id="hx-i-name" type="text" name="name" required maxlength="60" placeholder="مثلاً: سارا محمدی">
                                    </div>
                                    <div>
                                        <label id="hx-i-rating-label">امتیاز شما *</label>
                                        <?= self::starWidget('hx-i') ?>
                                    </div>
                                </div>
                                <div>
                                    <label for="hx-i-body">دیدگاه شما *</label>
                                    <textarea id="hx-i-body" name="body" required rows="4" minlength="10" maxlength="1000" placeholder="کیفیت خدمات، برخورد پرسنل، قیمت یا هر تجربه‌ای که برای دیگران مفید است…"></textarea>
                                </div>
                                <div>
                                    <label for="hx-i-answer"><span data-hx-c-q-label>سوال امنیتی در حال آماده‌سازی…</span></label>
                                    <input id="hx-i-answer" type="text" name="answer" required maxlength="4" inputmode="numeric" placeholder="پاسخ عددی" dir="ltr">
                                </div>
                                <div class="hx-comment-actions">
                                    <button type="submit" class="hx-btn hx-btn-primary">ارسال دیدگاه برای تایید تحریریه</button>
                                    <span data-hx-form-msg role="status" aria-live="polite"></span>
                                </div>
                                <noscript><a class="hx-btn hx-btn-outline" href="<?= self::e($baseUrl) ?>/comment/<?= self::e((string)$biz['city_slug']) ?>/<?= self::e((string)$biz['slug']) ?>/">رفتن به فرم ثبت دیدگاه</a></noscript>
                            </form>
                            <div data-hx-comment-done class="hx-comment-done" hidden>
                                <h2><?= self::icon('tick-circle') ?> دیدگاه شما ثبت شد</h2>
                                <p>دیدگاه شما ثبت شد و در انتظار تایید تحریریه است؛ پس از تایید، در همین صفحه با نشان «تایید حامی‌هاب» نمایش داده می‌شود.</p>
                            </div>
                        </div>

                        <?php if (!empty($comments)): ?>
                        <div class="hx-comments-list">
                            <?php foreach ($comments as $cm): ?>
                            <article class="hx-comment-item">
                                <div class="hx-comment-head">
                                    <span class="hx-comment-avatar"><?= self::e(mb_substr((string)$cm['name'], 0, 1)) ?></span>
                                    <div>
                                        <strong><?= self::e((string)$cm['name']) ?></strong>
                                        <div class="hx-comment-meta">
                                            <span class="hx-comment-stars" dir="ltr"><?= self::e(str_repeat('★', (int)$cm['rating'])) ?><?= self::e(str_repeat('☆', 5 - (int)$cm['rating'])) ?></span>
                                            <span class="hx-comment-date"><?= self::e(self::faJalali((string)$cm['created_at'])) ?></span>
                                            <span class="hx-comment-verified"><?= self::icon('verify', 'hx-icon hx-icon-sm') ?> تایید حامی‌هاب</span>
                                        </div>
                                    </div>
                                </div>
                                <p class="hx-comment-body"><?= self::e((string)$cm['body']) ?></p>
                            </article>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="hx-info-panel">
                            هنوز دیدگاهی از کاربران حامی‌هاب برای این مجموعه ثبت نشده است؛ <strong>اولین نفر باشید</strong> و با دکمه «ثبت دیدگاه» تجربه خود را بنویسید.
                        </div>
                        <?php endif; ?>

                        <div class="hx-provider-reviews">
                            <h3>امتیاز در منابع مرجع</h3>
                            <?php if (!empty($providerRatings)): ?>
                                <ul>
                                    <?php foreach ($providerRatings as $pr): ?>
                                    <li>
                                        <span class="hx-provider-chip">
                                            <b><?= self::e($providers[(string)$pr['provider']] ?? (string)$pr['provider']) ?></b>
                                            <span dir="ltr"><?= self::e(rtrim(rtrim(number_format((float)$pr['rating'], 2, '.', ''), '0'), '.')) ?></span>
                                            <span>★</span>
                                            <span><?= self::e(self::faDigits((string)(int)$pr['reviews_count'])) ?> نظر</span>
                                            <?php if (!empty($pr['fetched_at'])): ?><small>دریافت <?= self::e(self::faJalali((string)$pr['fetched_at'])) ?></small><?php endif; ?>
                                        </span>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <p class="hx-provider-note">امتیازها از منبع اعلام‌شده هر مجموعه ثبت می‌شود و میانگین منابع مختلف با هم جمع نمی‌شود.</p>
                            <?php else: ?>
                                <p class="hx-provider-note">امتیازی از منابع مرجع برای این مجموعه ثبت نشده است.</p>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>

                <aside class="hx-detail-side">
                    <div class="hx-side-card">
                        <h2><?= self::icon('call') ?> دسترسی سریع</h2>
                        <div class="hx-side-actions">
                            <?php if ($primaryPhone !== ''): ?>
                                <a class="hx-btn hx-btn-primary hx-btn-lg" href="tel:<?= self::e((string)($contacts['phone'] ?? $contacts['mobile'] ?? $primaryPhone)) ?>" data-hx-track="call" data-business-id="<?= (int)$biz['id'] ?>" data-source-page="business_detail">
                                    <?= self::icon('call', 'hx-icon hx-icon-sm') ?> تماس مستقیم
                                    <b dir="ltr"><?= self::e($primaryPhone) ?></b>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($contacts['website'])): ?>
                                <a class="hx-btn hx-btn-outline" target="_blank" rel="noopener nofollow" href="<?= self::e((string)$contacts['website']) ?>" data-hx-track="website" data-business-id="<?= (int)$biz['id'] ?>" data-source-page="business_detail">
                                    <?= self::icon('global', 'hx-icon hx-icon-sm') ?> وب‌سایت رسمی
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($contacts['instagram'])): ?>
                                <a class="hx-btn hx-btn-outline" target="_blank" rel="noopener nofollow" href="https://instagram.com/<?= self::e(ltrim((string)$contacts['instagram'], '@')) ?>" data-hx-track="instagram" data-business-id="<?= (int)$biz['id'] ?>" data-source-page="business_detail">
                                    <?= self::icon('instagram', 'hx-icon hx-icon-sm') ?> اینستاگرام
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($contacts['whatsapp'])): ?>
                                <?php $waNumber = preg_replace('/^0/', '98', preg_replace('/[^0-9]/', '', (string)$contacts['whatsapp']) ?? ''); ?>
                                <a class="hx-btn hx-btn-outline" target="_blank" rel="noopener nofollow" href="https://wa.me/<?= self::e((string)$waNumber) ?>" data-hx-track="whatsapp" data-business-id="<?= (int)$biz['id'] ?>" data-source-page="business_detail">
                                    <?= self::icon('message-question', 'hx-icon hx-icon-sm') ?> واتس‌اپ
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($biz['lat']) && !empty($biz['lng'])): ?>
                                <?= self::mapActionLinks($biz, $googlePlaceId, 'business_detail', 'sidebar') ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- بخش تعاملی S6: ادعای مالکیت و گزارش خطا (بدون تغییر منطق) -->
                    <div class="hx-s6-feedback-bar">
                        <div class="hx-s6-feedback-inner">
                            <div>
                                <strong><?= self::icon('verify', 'hx-icon hx-icon-sm') ?> <span>مالک «<?= self::e($biz['name']) ?>» هستید یا مغایرتی در اطلاعات مشاهده می‌کنید؟</span></strong>
                                <p>درخواست شما مستقیماً در صندوق بازبینی تحریریه حامی‌هاب ثبت شده و پس از بررسی اعمال می‌گردد.</p>
                            </div>
                            <div class="hx-s6-feedback-actions">
                                <button type="button" class="hx-btn hx-btn-primary" data-hx-toggle-form="claim-form-<?= (int)$biz['id'] ?>"><?= self::icon('verify', 'hx-icon hx-icon-sm') ?> <span>ثبت ادعای مالکیت</span></button>
                                <button type="button" class="hx-btn hx-btn-outline" data-hx-toggle-form="report-form-<?= (int)$biz['id'] ?>"><?= self::icon('info-circle', 'hx-icon hx-icon-sm') ?> <span>گزارش خطای اطلاعات</span></button>
                            </div>
                        </div>
                        <form id="claim-form-<?= (int)$biz['id'] ?>" data-hx-public-form="claim" style="display:none;margin-top:0.85rem;">
                            <input type="hidden" name="business_id" value="<?= (int)$biz['id'] ?>">
                            <input type="text" name="website_hp" value="" tabindex="-1" autocomplete="off" style="display:none!important;">
                            <label>نام و نام خانوادگی مالک / مدیر *</label>
                            <input type="text" name="name" required maxlength="150" placeholder="مثلاً: علی گیلانی">
                            <label>شماره موبایل یا تلفن ثابت جهت احراز *</label>
                            <input type="tel" name="phone" required maxlength="32" dir="ltr" placeholder="0911... / 013...">
                            <label>توضیحات تکمیلی (سمت، ساعت تماس یا اصلاحات مورد نیاز)</label>
                            <textarea name="note" rows="2" maxlength="800"></textarea>
                            <div class="hx-s6-submit-row">
                                <button type="submit" class="hx-btn hx-btn-primary">ارسال درخواست مالکیت</button>
                                <span data-hx-form-msg></span>
                            </div>
                        </form>
                        <form id="report-form-<?= (int)$biz['id'] ?>" data-hx-public-form="report" style="display:none;margin-top:0.85rem;">
                            <input type="hidden" name="business_id" value="<?= (int)$biz['id'] ?>">
                            <input type="text" name="website_hp" value="" tabindex="-1" autocomplete="off" style="display:none!important;">
                            <label>شرح مغایرت یا خطای اطلاعات *</label>
                            <textarea name="message" required rows="3" maxlength="1000" placeholder="مثلاً: شماره تلفن تغییر کرده یا مجموعه تعطیل شده است…"></textarea>
                            <div class="hx-s6-submit-row">
                                <button type="submit" class="hx-btn hx-btn-primary">ثبت گزارش خطا</button>
                                <span data-hx-form-msg></span>
                            </div>
                        </form>
                    </div>
                </aside>
            </div>

            <?php if (!empty($related)): ?>
            <section class="hx-section" id="hx-similar">
                <div class="hx-section-head">
                    <div>
                        <h2 class="hx-section-title">سایر گزینه‌های برتر <?= self::e($biz['category_name_fa']) ?> در <?= self::e($biz['city_name_fa']) ?></h2>
                    </div>
                    <a class="hx-btn hx-btn-outline" href="/city/<?= self::e($biz['city_slug']) ?>/<?= self::e($biz['category_slug']) ?>/">مشاهده همه <?= self::icon('arrow-left', 'hx-icon hx-icon-sm') ?></a>
                </div>
                <div class="hx-grid hx-grid-3">
                    <?php foreach ($related as $idx => $rel): ?>
                        <?= self::renderBusinessCard($rel, $idx + 1, 'business_related') ?>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
        </main>
        <?php
        $bodyHtml = (string)ob_get_clean();

        $fullHtml = self::renderLayout([
            'title'        => $biz['name'] . ' در ' . $biz['city_name_fa'] . ' | آدرس و تلفن | حامی‌هاب گیلان',
            'description'  => 'اطلاعات کامل، آدرس، شماره تماس و مسیریابی ' . $biz['name'] . ' در ' . $biz['city_name_fa'] . ' بر اساس رتبه‌بندی حامی‌هاب گیلان.',
            'canonical'    => $canonical,
            'og_type'      => 'place',
            'schemas'      => [$localBizSchema, $breadcrumbSchema],
            'ad_placement' => 'business',
            'ad_city_id'   => (int)$biz['city_id'],
            'ad_category_id' => (int)$biz['category_id'],
            'body'         => $bodyHtml,
        ]);

        Cache::putPage('/' . rawurlencode((string)$biz['city_slug']) . '/' . rawurlencode((string)$biz['slug']), $fullHtml);
        Response::html($fullHtml);
    }

    /**
     * GET /city/{city} — صفحه هاب هر شهر
     */
    public static function cityHub(Request $req, array $params = []): void
    {
        $citySlug = rawurldecode((string)($params['city'] ?? ''));
        $city = DB::fetchOne("SELECT * FROM cities WHERE slug = :slug AND is_active = 1 LIMIT 1", ['slug' => $citySlug]);

        if (!$city) {
            self::render404('شهر مورد نظر در استان گیلان یافت نشد.');
            return;
        }

        $categories = DB::fetchAll("SELECT * FROM categories WHERE is_active = 1 ORDER BY sort ASC, id ASC");
        $businesses = self::fetchPublishedBusinesses("b.city_id = :city_id AND b.status = 'published'", ['city_id' => (int)$city['id']], 24);
        $articles = DB::fetchAll(
            "SELECT id, slug, title, meta_description, year_label
             FROM articles
             WHERE city_id = :city_id AND status = 'published'
             ORDER BY published_at DESC, id DESC",
            ['city_id' => (int)$city['id']]
        );

        $baseUrl = self::baseUrl();
        $canonical = $baseUrl . '/city/' . rawurlencode((string)$city['slug']) . '/';

        $schemas = [
            self::buildBreadcrumbSchema([
                ['name' => 'حامی‌هاب گیلان', 'url' => $baseUrl . '/'],
                ['name' => 'بهترین‌های ' . $city['name_fa'], 'url' => $canonical],
            ]),
            [
                '@context'        => 'https://schema.org',
                '@type'           => 'ItemList',
                'name'            => 'بهترین کسب‌وکارهای ' . $city['name_fa'],
                'itemListElement' => self::buildItemListElements($businesses, $baseUrl),
            ],
        ];

        $mapMarkers = self::extractMarkers($businesses);

        ob_start();
        ?>
        <main class="hx-container" data-hx-page-view data-city-id="<?= (int)$city['id'] ?>">
            <nav class="hx-breadcrumb" aria-label="مسیر صفحه">
                <a href="/">حامی‌هاب گیلان</a>
                <span>/</span>
                <strong>شهر <?= self::e($city['name_fa']) ?></strong>
            </nav>

            <header class="hx-article-header">
                <span class="hx-badge hx-badge-tier-a"><?= self::icon('location', 'hx-icon hx-icon-sm') ?> راهنمای جامع شهری · استان گیلان</span>
                <h1>بهترین کافه‌ها، رستوران‌ها و مراکز خدماتی <?= self::e($city['name_fa']) ?></h1>
                <p style="margin:0;color:var(--hx-slate-600);">
                    فهرست کسب‌وکارهای منتشرشده شهر <?= self::e($city['name_fa']) ?>.
                </p>
                <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-top:1rem;">
                    <?php foreach ($categories as $cat): ?>
                        <a class="hx-btn hx-btn-outline" href="/city/<?= self::e($city['slug']) ?>/<?= self::e($cat['slug']) ?>/">
                            <?= self::e($cat['name_fa']) ?> در <?= self::e($city['name_fa']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </header>

            <?php if (!empty($articles)): ?>
            <section class="hx-section">
                <h2 class="hx-section-title" style="margin-bottom:0.85rem;">
                    <?= self::icon('award') ?>
                    <span>مقالات و لیست‌های مقایسه‌ای شهر <?= self::e($city['name_fa']) ?></span>
                </h2>
                <div class="hx-grid hx-grid-2">
                    <?php foreach ($articles as $art): ?>
                        <div class="hx-card">
                            <h3 class="hx-card-title"><a href="/<?= self::e($art['slug']) ?>/"><?= self::e($art['title']) ?></a></h3>
                            <?php if (!empty($art['meta_description'])): ?>
                                <p class="hx-card-desc"><?= self::e($art['meta_description']) ?></p>
                            <?php endif; ?>
                            <a class="hx-btn hx-btn-primary" href="/<?= self::e($art['slug']) ?>/">مطالعه راهنما ←</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php if (!empty($mapMarkers)): ?>
                <?= self::renderLazyMapBox($mapMarkers, 'نقشه تعاملی کسب‌وکارهای منتخب شهر ' . $city['name_fa']) ?>
            <?php endif; ?>

            <section class="hx-section">
                <div class="hx-section-head">
                    <h2 class="hx-section-title">
                        <?= self::icon('star') ?>
                        <span>رتبه‌بندی واحدهای منتخب در <?= self::e($city['name_fa']) ?> (<?= count($businesses) ?> مورد)</span>
                    </h2>
                </div>
                <?php if (empty($businesses)): ?>
                    <div class="hx-card">
                        <p class="hx-card-desc">در حال حاضر کسب‌وکار منتشرشده‌ای در شهر <?= self::e($city['name_fa']) ?> ثبت نشده است.</p>
                    </div>
                <?php else: ?>
                    <div class="hx-grid hx-grid-3">
                        <?php foreach ($businesses as $idx => $biz): ?>
                            <?= self::renderBusinessCard($biz, $idx + 1, 'city_' . $city['slug']) ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
        <?php
        $bodyHtml = (string)ob_get_clean();

        $fullHtml = self::renderLayout([
            'title'       => 'بهترین کافه‌ها، رستوران‌ها و خدمات ' . $city['name_fa'] . ' | حامی‌هاب گیلان',
            'description' => 'لیست کامل و رتبه‌بندی بهترین کسب‌وکارها، کافه‌ها، رستوران‌ها و خدمات شهر ' . $city['name_fa'] . ' همراه با آدرس، تلفن و نقشه تعاملی.',
            'canonical'   => $canonical,
            'og_type'     => 'website',
            'schemas'     => $schemas,
            'ad_placement'=> 'city',
            'ad_city_id'  => (int)$city['id'],
            'body'        => $bodyHtml,
        ]);

        Cache::putPage('/city/' . $city['slug'], $fullHtml);
        Response::html($fullHtml);
    }

    /**
     * GET /city/{city}/{category} — صفحه ترکیبی شهر و دسته‌بندی
     */
    public static function cityCategory(Request $req, array $params = []): void
    {
        $citySlug = rawurldecode((string)($params['city'] ?? ''));
        $catSlug = rawurldecode((string)($params['category'] ?? ''));

        $city = DB::fetchOne("SELECT * FROM cities WHERE slug = :slug AND is_active = 1 LIMIT 1", ['slug' => $citySlug]);
        $category = DB::fetchOne("SELECT * FROM categories WHERE slug = :slug AND is_active = 1 LIMIT 1", ['slug' => $catSlug]);

        if (!$city || !$category) {
            self::render404('شهر یا دسته‌بندی مورد نظر یافت نشد.');
            return;
        }

        $page = max(1, min(100000, is_scalar($_GET['page'] ?? 1) ? (int)($_GET['page'] ?? 1) : 1));
        $minRating = is_scalar($_GET['min_rating'] ?? 0) ? (string)($_GET['min_rating'] ?? '0') : '0';
        $minReviews = is_scalar($_GET['min_reviews'] ?? 0) ? (string)($_GET['min_reviews'] ?? '0') : '0';
        $search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
        if ($search !== '') {
            // S9: ثبت جستجوی داخلی در ماژول آمار؛ صفحات دارای پارامتر q کش نمی‌شوند و ثبت سمت سرور است.
            try {
                AnalyticsService::recordSearch([
                    'query' => $search,
                    'city_id' => (int)$city['id'],
                    'category_id' => (int)$category['id'],
                    'path' => '/city/' . rawurlencode((string)$city['slug']) . '/' . rawurlencode((string)$category['slug']) . '/',
                ] + AnalyticsService::requestContext($req));
            } catch (Throwable $e) { /* آمار هرگز جلوی رندر صفحه را نمی‌گیرد */ }
        }
        if (!in_array($minRating, ['0','3','4','4.5'], true)) $minRating = '0';
        if (!in_array($minReviews, ['0','10','50','100'], true)) $minReviews = '0';
        $where = "b.city_id = :city_id AND b.category_id = :cat_id AND b.status = 'published'";
        $query = ['city_id'=>(int)$city['id'], 'cat_id'=>(int)$category['id']];
        if ($minRating !== '0') { $where .= ' AND rs.rating >= :min_rating'; $query['min_rating'] = $minRating; }
        if ($minReviews !== '0') { $where .= ' AND rs.reviews_count >= :min_reviews'; $query['min_reviews'] = $minReviews; }
        if ($search !== '') { $where .= ' AND (b.name LIKE :search1 OR b.address LIKE :search2)'; $query['search1']='%' . $search . '%'; $query['search2']='%' . $search . '%'; }
        $businesses = self::fetchPublishedBusinesses($where, $query, 51, ($page - 1) * 50);
        $hasNext = count($businesses) > 50;
        if ($hasNext) array_pop($businesses);
        $baseUrl = self::baseUrl();
        $path = '/city/' . rawurlencode((string)$city['slug']) . '/' . rawurlencode((string)$category['slug']) . '/';
        $canonical = $baseUrl . $path . ($page > 1 ? '?page=' . $page : '');
        $pageUrl = static function (int $number) use ($path, $minRating, $minReviews, $search): string {
            $q = ['page'=>$number];
            if ($minRating !== '0') $q['min_rating']=$minRating;
            if ($minReviews !== '0') $q['min_reviews']=$minReviews;
            if ($search !== '') $q['q']=$search;
            return $path . '?' . http_build_query($q);
        };

        $schemas = [
            self::buildBreadcrumbSchema([
                ['name' => 'حامی‌هاب گیلان', 'url' => $baseUrl . '/'],
                ['name' => (string)$city['name_fa'], 'url' => $baseUrl . '/city/' . $city['slug'] . '/'],
                ['name' => (string)$category['name_fa'] . ' در ' . $city['name_fa'], 'url' => $canonical],
            ]),
            [
                '@context'        => 'https://schema.org',
                '@type'           => 'ItemList',
                'name'            => 'بهترین ' . $category['name_fa'] . ' در ' . $city['name_fa'],
                'itemListElement' => self::buildItemListElements($businesses, $baseUrl),
            ],
        ];

        $mapMarkers = self::extractMarkers($businesses);

        ob_start();
        ?>
        <main class="hx-container hx-catalog" data-hx-page-view data-city-id="<?= (int)$city['id'] ?>" data-category-id="<?= (int)$category['id'] ?>">
            <nav class="hx-breadcrumb" aria-label="مسیر صفحه">
                <a href="/">حامی‌هاب گیلان</a><span>/</span>
                <a href="/city/<?= self::e($city['slug']) ?>/"><?= self::e($city['name_fa']) ?></a><span>/</span>
                <strong><?= self::e($category['name_fa']) ?></strong>
            </nav>

            <header class="hx-catalog-hero">
                <div>
                    <div class="hx-catalog-eyebrow"><?= self::icon('location', 'hx-icon hx-icon-sm') ?> راهنمای <?= self::e($city['name_fa']) ?></div>
                    <h1><?= self::e($category['name_fa']) ?> در <?= self::e($city['name_fa']) ?></h1>
                    <p>گزینه‌ها را ببینید، امتیاز و نشانی را مقایسه کنید و با هر مجموعه مستقیم تماس بگیرید.</p>
                </div>
                <span class="hx-catalog-count"><?= count($businesses) ?> مورد در این صفحه</span>
            </header>

            <form method="get" action="<?= self::e($path) ?>" class="hx-catalog-filters" aria-label="جستجو و فیلتر نتایج">
                <label class="hx-filter-search"><span>جستجو</span><input name="q" maxlength="100" value="<?= self::e($search) ?>" placeholder="نام یا نشانی مجموعه"></label>
                <label><span>حداقل امتیاز</span><select name="min_rating">
                    <?php foreach (['0'=>'همه امتیازها','3'=>'۳ به بالا','4'=>'۴ به بالا','4.5'=>'۴٫۵ به بالا'] as $value=>$label): ?>
                        <option value="<?= self::e((string)$value) ?>" <?= $minRating === (string)$value ? 'selected' : '' ?>><?= self::e($label) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label><span>حداقل تعداد نظر</span><select name="min_reviews">
                    <?php foreach (['0'=>'همه','10'=>'۱۰ نظر','50'=>'۵۰ نظر','100'=>'۱۰۰ نظر'] as $value=>$label): ?>
                        <option value="<?= self::e((string)$value) ?>" <?= $minReviews === (string)$value ? 'selected' : '' ?>><?= self::e($label) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <button class="hx-btn hx-btn-primary" type="submit">جستجو و فیلتر</button>
                <?php if ($search !== '' || $minRating !== '0' || $minReviews !== '0'): ?><a class="hx-clear-filters" href="<?= self::e($path) ?>">پاک کردن فیلترها</a><?php endif; ?>
            </form>

            <div class="hx-catalog-results-heading"><div><h2>گزینه‌های <?= self::e($category['name_fa']) ?></h2><p>جایگاه ویژه مشخص است؛ بقیه بر اساس آخرین امتیاز معتبر و تعداد نظر مرتب شده‌اند.</p></div></div>
            <?php if (!empty($mapMarkers)): ?>
                <?= self::renderLazyMapBox($mapMarkers, 'موقعیت گزینه‌های این صفحه', true) ?>
            <?php endif; ?>

            <section class="hx-catalog-results" aria-label="نتایج <?= self::e($category['name_fa']) ?>">
                <?php if (empty($businesses)): ?>
                    <div class="hx-catalog-empty"><strong>نتیجه‌ای پیدا نشد</strong><p>فیلترها را تغییر دهید یا دوباره جستجو کنید.</p><?php if ($search !== '' || $minRating !== '0' || $minReviews !== '0'): ?><a class="hx-btn hx-btn-outline" href="<?= self::e($path) ?>">نمایش همه نتایج</a><?php endif; ?></div>
                <?php else: ?>
                    <div class="hx-grid hx-catalog-grid">
                        <?php foreach ($businesses as $idx => $biz): ?>
                            <?= self::renderBusinessCard($biz, ($page - 1) * 50 + $idx + 1, 'city_cat_' . $city['slug'] . '_' . $category['slug']) ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($page > 1 || $hasNext): ?>
                    <nav aria-label="صفحات نتایج" class="hx-pagination">
                        <?php if ($page > 1): ?><a class="hx-btn hx-btn-outline" href="<?= self::e($pageUrl($page - 1)) ?>">صفحه قبل</a><?php endif; ?>
                        <span>صفحه <?= $page ?></span>
                        <?php if ($hasNext): ?><a class="hx-btn hx-btn-outline" href="<?= self::e($pageUrl($page + 1)) ?>">صفحه بعد</a><?php endif; ?>
                    </nav>
                <?php endif; ?>
            </section>
        </main>
        <?php
        $bodyHtml = (string)ob_get_clean();

        $fullHtml = self::renderLayout([
            'title'       => 'بهترین ' . $category['name_fa'] . ' در ' . $city['name_fa'] . ' | رتبه‌بندی و آدرس | حامی‌هاب',
            'description' => 'معرفی بهترین ' . $category['name_fa'] . ' در شهر ' . $city['name_fa'] . ' با اطلاعات موجود، آدرس و شماره تماس.',
            'canonical'   => $canonical,
            'og_type'     => 'website',
            'schemas'     => $schemas,
            'ad_placement'=> 'category',
            'ad_city_id'  => (int)$city['id'],
            'ad_category_id' => (int)$category['id'],
            'body'        => $bodyHtml,
        ]);

        // Query-string pages must never poison the unfiltered category cache.
        if (empty($_GET)) Cache::putPage('/city/' . $city['slug'] . '/' . $category['slug'], $fullHtml);
        Response::html($fullHtml);
    }

    /**
     * GET /{slug} — صفحه مقاله لیستی («بهترین کافه‌های رشت») با اسکیمای Article + ItemList + FAQPage + BreadcrumbList
     */
    /** Private, authenticated preview; it never touches the public page cache. */
    /**
     * GET /search — جستجوی داخلی کسب‌وکارها و راهنماها (S16)
     * نسخه بازنویسی‌شده و ضدخطا: هیچ شکست دیتابیسی به ۵۰۰ تبدیل نمی‌شود؛
     * کوئری فقط روی جدول‌های هسته است و همه پرس‌وجوها در try/catch محافظت می‌شوند.
     */
    public static function searchPage(Request $req, array $params = []): void
    {
        $q = is_string($_GET['q'] ?? null) ? trim((string)$_GET['q']) : '';
        $q = mb_substr($q, 0, 80);
        $citySlug = is_string($_GET['city'] ?? null) ? trim((string)$_GET['city']) : '';
        if ($citySlug !== '' && preg_match('/^[a-z0-9-]{1,80}$/', $citySlug) !== 1) $citySlug = '';
        $page = max(1, min(50, is_scalar($_GET['page'] ?? 1) ? (int)($_GET['page'] ?? 1) : 1));
        $perPage = 24;

        $cities = [];
        try {
            $cities = DB::fetchAll(
                "SELECT c.id, c.slug, c.name_fa,
                        (SELECT COUNT(*) FROM businesses b WHERE b.city_id = c.id AND b.status = 'published') AS biz_count
                 FROM cities c
                 WHERE c.is_active = 1
                 ORDER BY c.id ASC"
            );
        } catch (Throwable $e) { error_log('HX search cities: ' . $e->getMessage()); }

        $city = null;
        if ($citySlug !== '') {
            foreach ($cities as $c) {
                if ((string)$c['slug'] === $citySlug) { $city = $c; break; }
            }
        }

        $hasQuery = mb_strlen($q) >= 2;
        $hasFilter = $hasQuery || $city !== null;
        $results = [];
        $articles = [];
        $dbError = false;
        $hasNext = false;

        if ($hasFilter) {
            try {
                if ($hasQuery) {
                    // S9: ثبت جستجوی داخلی؛ هیچ‌وقت جلوی رندر را نمی‌گیرد
                    try {
                        AnalyticsService::recordSearch([
                            'query' => $q,
                            'city_id' => $city !== null ? (int)$city['id'] : null,
                            'path' => '/search?q=' . rawurlencode($q) . ($city !== null ? '&city=' . rawurlencode((string)$city['slug']) : ''),
                        ] + AnalyticsService::requestContext($req));
                    } catch (Throwable $e) { /* آمار حیاتی نیست */ }
                }
                $like = '%' . addcslashes($q, '\\%_') . '%';
                if ($hasQuery) {
                    $where = "b.status = 'published' AND (b.name LIKE :q1 OR b.address LIKE :q2 OR b.short_desc LIKE :q3 OR cat.name_fa LIKE :q4)";
                    $paramsDb = ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
                    if ($city !== null) { $where .= ' AND b.city_id = :cid'; $paramsDb['cid'] = (int)$city['id']; }
                    $results = self::fetchPublishedBusinesses($where, $paramsDb, $perPage + 1, ($page - 1) * $perPage);
                    $articles = DB::fetchAll(
                        "SELECT id, slug, title, meta_description, year_label
                         FROM articles
                         WHERE status = 'published' AND (title LIKE :t1 OR meta_description LIKE :t2)
                         ORDER BY published_at DESC, id DESC
                         LIMIT 6",
                        ['t1' => $like, 't2' => $like]
                    );
                } else {
                    $results = self::fetchPublishedBusinesses("b.status = 'published' AND b.city_id = :cid", ['cid' => (int)$city['id']], $perPage + 1, ($page - 1) * $perPage);
                }
                $hasNext = count($results) > $perPage;
                if ($hasNext) array_pop($results);
            } catch (Throwable $e) {
                error_log('HX search safe-fail: [' . get_class($e) . '] ' . $e->getMessage());
                $dbError = true;
                $results = [];
                $articles = [];
            }
        }

        $baseUrl = self::baseUrl();
        $pageUrl = static function (int $number) use ($q, $citySlug): string {
            $parts = [];
            if ($q !== '') $parts[] = 'q=' . rawurlencode($q);
            if ($citySlug !== '') $parts[] = 'city=' . rawurlencode($citySlug);
            if ($number > 1) $parts[] = 'page=' . $number;
            return '/search' . ($parts !== [] ? '?' . implode('&', $parts) : '');
        };

        $heading = $hasQuery
            ? 'نتایج جستجو برای «' . $q . '»'
            : ($city !== null ? 'کسب‌وکارهای تاییدشده ' . (string)($city['name_fa'] ?? '') : 'جستجوی کسب‌وکارها و راهنماها');

        $schemas = [
            self::buildBreadcrumbSchema([
                ['name' => 'حامی‌هاب گیلان', 'url' => $baseUrl . '/'],
                ['name' => 'جستجو', 'url' => $baseUrl . '/search'],
            ]),
        ];

        ob_start();
        ?>
        <section class="hx-hero hx-hero-search-page">
            <div class="hx-container hx-hero-inner">
                <span class="hx-hero-badge">
                    <?= self::icon('search', 'hx-icon hx-icon-sm') ?>
                    <span>جستجو در حامی‌هاب گیلان</span>
                </span>
                <h1>جستجوی کسب‌وکارها و راهنماها</h1>
                <form class="hx-hero-search" action="/search" method="get" role="search" aria-label="جستجوی کسب‌وکارها و راهنماها">
                    <div class="hx-search-field">
                        <?= self::icon('search') ?>
                        <input type="search" name="q" maxlength="80" value="<?= self::e($q) ?>" placeholder="نام کسب‌وکار، صنف (مثلاً سالن زیبایی، رستوران، ویلا)…" aria-label="عبارت جستجو">
                    </div>
                    <div class="hx-search-field hx-search-field-city">
                        <?= self::icon('location') ?>
                        <select name="city" aria-label="انتخاب شهر">
                            <option value="">همه شهرهای گیلان</option>
                            <?php foreach ($cities as $c): ?>
                                <?php $cnt = (int)$c['biz_count']; ?>
                                <option value="<?= self::e($c['slug']) ?>" <?= ($city !== null && (string)$c['slug'] === $citySlug) ? 'selected' : '' ?>><?= self::e($c['name_fa']) ?><?= $cnt > 0 ? ' (' . self::e(self::faDigits((string)$cnt)) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="hx-search-btn" type="submit">
                        <span>جستجو</span>
                        <?= self::icon('arrow-left', 'hx-icon hx-icon-sm') ?>
                    </button>
                </form>
            </div>
        </section>
        <main class="hx-container">
            <?php if ($dbError): ?>
                <div class="hx-card hx-search-empty">
                    <h3 class="hx-card-title">در زمان جستجو خطایی رخ داد</h3>
                    <p class="hx-card-desc">چند لحظه بعد دوباره تلاش کنید یا از <a href="/businesses/">فهرست همه کسب‌وکارها</a> استفاده کنید.</p>
                </div>
            <?php elseif (!$hasFilter): ?>
                <div class="hx-card hx-search-empty">
                    <h3 class="hx-card-title">برای شروع، عبارت مورد نظر را بنویسید</h3>
                    <p class="hx-card-desc">نام کسب‌وکار یا صنف مورد نظر (حداقل ۲ نویسه) را در کادر بالا بنویسید و شهر را انتخاب کنید.</p>
                </div>
            <?php else: ?>
                <div class="hx-catalog-results-heading">
                    <div>
                        <h2><?= self::e($heading) ?></h2>
                        <p><?= self::e(self::faDigits((string)count($results))) ?> کسب‌وکار در این صفحه<?= $city !== null ? ' · شهر ' . self::e((string)$city['name_fa']) : '' ?></p>
                    </div>
                    <?php if ($citySlug !== '' || $q !== ''): ?><a class="hx-clear-filters" href="/search">پاک کردن فیلترها</a><?php endif; ?>
                </div>
                <?php if (empty($results) && empty($articles)): ?>
                    <div class="hx-card hx-search-empty">
                        <h3 class="hx-card-title">نتیجه‌ای پیدا نشد</h3>
                        <p class="hx-card-desc">عبارت کوتاه‌تری بنویسید یا فیلتر شهر را بردارید؛ می‌توانید از <a href="/businesses/">فهرست کامل کسب‌وکارها</a> هم استفاده کنید.</p>
                    </div>
                <?php else: ?>
                    <?php if (!empty($results)): ?>
                    <section class="hx-catalog-results" aria-label="نتایج کسب‌وکار">
                        <div class="hx-grid hx-grid-3">
                            <?php foreach ($results as $idx => $biz): ?>
                                <?= self::renderBusinessCard($biz, $idx + 1, 'search') ?>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>
                    <?php if (!empty($articles)): ?>
                    <section class="hx-section" aria-label="راهنماهای مرتبط">
                        <div class="hx-section-head"><div><h2 class="hx-section-title"><?= self::icon('award') ?> <span>راهنماهای مرتبط</span></h2></div></div>
                        <div class="hx-grid hx-grid-2">
                            <?php foreach ($articles as $art): ?>
                                <div class="hx-card">
                                    <h3 class="hx-card-title"><a href="/<?= self::e($art['slug']) ?>/"><?= self::e($art['title']) ?></a></h3>
                                    <?php if (!empty($art['meta_description'])): ?><p class="hx-card-desc"><?= self::e($art['meta_description']) ?></p><?php endif; ?>
                                    <a class="hx-btn hx-btn-primary" href="/<?= self::e($art['slug']) ?>/">مطالعه راهنما ←</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>
                    <?php if ($hasNext || $page > 1): ?>
                    <nav aria-label="صفحات نتایج" class="hx-pagination">
                        <span>صفحه <?= self::e(self::faDigits((string)$page)) ?></span>
                        <?php if ($page > 1): ?><a class="hx-btn hx-btn-outline" href="<?= self::e($pageUrl($page - 1)) ?>">صفحه قبل</a><?php endif; ?>
                        <?php if ($hasNext): ?><a class="hx-btn hx-btn-outline" href="<?= self::e($pageUrl($page + 1)) ?>">صفحه بعد</a><?php endif; ?>
                    </nav>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </main>
        <?php
        $bodyHtml = (string)ob_get_clean();

        Response::html(self::renderLayout([
            'title'       => ($hasQuery ? 'جستجو: ' . $q . ' | حامی‌هاب گیلان' : 'جستجوی کسب‌وکارها و راهنماها | حامی‌هاب گیلان'),
            'description' => 'جستجوی کسب‌وکارهای تاییدشده استان گیلان بر اساس نام، صنف و شهر؛ با امتیاز، نشانی و دسترسی مستقیم.',
            'canonical'   => $baseUrl . '/search',
            'og_type'     => 'website',
            'schemas'     => $schemas,
            'ad_placement'=> 'search',
            'body'        => $bodyHtml,
        ]));
    }

    /**
     * GET /businesses — فهرست کامل کسب‌وکارهای تاییدشده گیلان با فیلتر شهر و دسته و صفحه‌بندی (بازسازی مطابق نسخه زنده)
     */
    public static function businessesCatalog(Request $req, array $params = []): void
    {
        $citySlug = is_string($_GET['city'] ?? null) ? trim((string)$_GET['city']) : '';
        if ($citySlug !== '' && preg_match('/^[a-z0-9-]{1,80}$/', $citySlug) !== 1) $citySlug = '';
        $catSlug = is_string($_GET['cat'] ?? null) ? trim((string)$_GET['cat']) : '';
        if ($catSlug !== '' && preg_match('/^[a-z0-9-]{1,80}$/', $catSlug) !== 1) $catSlug = '';
        $page = max(1, min(100, is_scalar($_GET['page'] ?? 1) ? (int)($_GET['page'] ?? 1) : 1));
        $perPage = 24;

        $cities = DB::fetchAll(
            "SELECT c.id, c.slug, c.name_fa,
                    (SELECT COUNT(*) FROM businesses b WHERE b.city_id = c.id AND b.status = 'published') AS biz_count
             FROM cities c
             WHERE c.is_active = 1
             ORDER BY c.id ASC"
        );
        $categories = DB::fetchAll(
            "SELECT cat.id, cat.slug, cat.name_fa,
                    (SELECT COUNT(*) FROM businesses b WHERE b.category_id = cat.id AND b.status = 'published') AS biz_count
             FROM categories cat
             WHERE cat.is_active = 1
             ORDER BY cat.sort ASC, cat.id ASC"
        );

        $cityId = null; $catId = null; $cityName = ''; $catName = '';
        foreach ($cities as $c) { if ((string)$c['slug'] === $citySlug) { $cityId = (int)$c['id']; $cityName = (string)$c['name_fa']; break; } }
        foreach ($categories as $cat) { if ((string)$cat['slug'] === $catSlug) { $catId = (int)$cat['id']; $catName = (string)$cat['name_fa']; break; } }
        if ($citySlug !== '' && $cityId === null) $citySlug = '';
        if ($catSlug !== '' && $catId === null) $catSlug = '';

        $where = "b.status = 'published'";
        $bind = [];
        if ($cityId !== null) { $where .= ' AND b.city_id = :cid'; $bind['cid'] = $cityId; }
        if ($catId !== null) { $where .= ' AND b.category_id = :catid'; $bind['catid'] = $catId; }

        $results = self::fetchPublishedBusinesses($where, $bind, $perPage + 1, ($page - 1) * $perPage);
        $hasNext = count($results) > $perPage;
        if ($hasNext) array_pop($results);
        $total = (int)(DB::fetchOne(
            "SELECT COUNT(*) AS n FROM businesses b WHERE " . $where,
            $bind
        )['n'] ?? 0);
        $pageCount = max(1, (int)ceil($total / $perPage));

        $pageUrl = static function (int $number) use ($citySlug, $catSlug): string {
            $parts = [];
            if ($citySlug !== '') $parts[] = 'city=' . rawurlencode($citySlug);
            if ($catSlug !== '') $parts[] = 'cat=' . rawurlencode($catSlug);
            if ($number > 1) $parts[] = 'page=' . $number;
            return '/businesses/' . ($parts !== [] ? '?' . implode('&', $parts) : '');
        };

        $baseUrl = self::baseUrl();
        $title = 'همه کسب‌وکارهای تاییدشده گیلان | فهرست کامل به تفکیک شهر و صنف | حامی‌هاب';
        $h1 = 'همه کسب‌وکارهای تاییدشده استان گیلان';
        if ($cityId !== null && $catId !== null) { $h1 = $catName . ' در ' . $cityName; }
        elseif ($catId !== null) { $h1 = $catName . ' در استان گیلان'; }
        elseif ($cityId !== null) { $h1 = 'کسب‌وکارهای تاییدشده ' . $cityName; }

        $mapMarkers = self::extractMarkers($results);
        $schemas = [
            self::buildBreadcrumbSchema([
                ['name' => 'حامی‌هاب گیلان', 'url' => $baseUrl . '/'],
                ['name' => 'همه کسب‌وکارهای تاییدشده', 'url' => $baseUrl . '/businesses/'],
            ]),
            [
                '@context'        => 'https://schema.org',
                '@type'           => 'ItemList',
                'name'            => $h1,
                'itemListElement' => self::buildItemListElements($results, $baseUrl),
            ],
        ];

        ob_start();
        ?>
        <main class="hx-container hx-catalog" data-hx-page-view>
            <nav class="hx-breadcrumb" aria-label="مسیر صفحه">
                <a href="/">حامی‌هاب گیلان</a><span>/</span>
                <strong>همه کسب‌وکارهای تاییدشده</strong>
            </nav>
            <header class="hx-catalog-hero">
                <div>
                    <div class="hx-catalog-eyebrow"><?= self::icon('verify', 'hx-icon hx-icon-sm') ?> فهرست کامل و به‌روز</div>
                    <h1><?= self::e($h1) ?></h1>
                    <p>تمام مجموعه‌های منتشرشده در حامی‌هاب با امتیاز، نشانی و دسترسی مستقیم؛ با فیلتر شهر و صنف سریع‌تر به هدف برسید.</p>
                </div>
                <span class="hx-catalog-count"><?= self::e(self::faDigits((string)$total)) ?> کسب‌وکار</span>
            </header>
            <form method="get" action="/businesses/" class="hx-catalog-filters" aria-label="فیلتر شهر و دسته‌بندی">
                <label><span>شهر</span><select name="city">
                    <option value="">همه شهرهای گیلان</option>
                    <?php foreach ($cities as $c): ?>
                        <?php $cnt = (int)$c['biz_count']; ?>
                        <option value="<?= self::e($c['slug']) ?>" <?= $citySlug === (string)$c['slug'] ? 'selected' : '' ?>><?= self::e($c['name_fa']) ?><?= $cnt > 0 ? ' (' . self::e(self::faDigits((string)$cnt)) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label><span>دسته‌بندی</span><select name="cat">
                    <option value="">همه دسته‌ها</option>
                    <?php foreach ($categories as $cat): ?>
                        <?php $cnt = (int)$cat['biz_count']; ?>
                        <option value="<?= self::e($cat['slug']) ?>" <?= $catSlug === (string)$cat['slug'] ? 'selected' : '' ?>><?= self::e($cat['name_fa']) ?><?= $cnt > 0 ? ' (' . self::e(self::faDigits((string)$cnt)) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select></label>
                <button class="hx-btn hx-btn-primary" type="submit">اعمال فیلتر</button>
            </form>
            <section class="hx-catalog-results" aria-label="فهرست همه کسب‌وکارها">
                <?php if (empty($results)): ?>
                    <div class="hx-card hx-search-empty">
                        <h3 class="hx-card-title">در این فیلتر کسب‌وکاری یافت نشد</h3>
                        <p class="hx-card-desc">فیلتر شهر یا دسته‌بندی را تغییر دهید یا <a href="/businesses/">همه کسب‌وکارها</a> را ببینید.</p>
                    </div>
                <?php else: ?>
                    <div class="hx-grid hx-grid-3">
                        <?php foreach ($results as $idx => $biz): ?>
                            <?= self::renderBusinessCard($biz, $idx + 1, 'businesses_catalog') ?>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($pageCount > 1): ?>
                    <nav aria-label="صفحات فهرست" class="hx-pagination">
                        <span>صفحه <?= self::e(self::faDigits((string)$page)) ?> از <?= self::e(self::faDigits((string)$pageCount)) ?></span>
                        <?php if ($page > 1): ?><a class="hx-btn hx-btn-outline" href="<?= self::e($pageUrl($page - 1)) ?>">صفحه قبل</a><?php endif; ?>
                        <?php if ($hasNext): ?><a class="hx-btn hx-btn-outline" href="<?= self::e($pageUrl($page + 1)) ?>">صفحه بعد</a><?php endif; ?>
                    </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
            <?php if (!empty($mapMarkers)): ?>
                <?= self::renderLazyMapBox($mapMarkers, 'نقشه کسب‌وکارهای همین صفحه', true) ?>
            <?php endif; ?>
        </main>
        <?php
        $bodyHtml = (string)ob_get_clean();

        $fullHtml = self::renderLayout([
            'title'        => $title,
            'description'  => 'فهرست کامل کسب‌وکارهای تاییدشده استان گیلان با فیلتر شهر و دسته‌بندی؛ امتیاز، نشانی، تلفن و مسیریابی مستقیم.',
            'canonical'    => $baseUrl . '/businesses/',
            'og_type'      => 'website',
            'schemas'      => $schemas,
            'ad_placement' => 'businesses',
            'body'         => $bodyHtml,
        ]);

        Cache::putPage('/businesses', $fullHtml);
        Response::html($fullHtml);
    }

    /**
     * S20: ویجت ستاره‌های امتیاز — مقدار هر ستاره در data-star-value و بازخورد عددی زنده؛
     * پرشدن همهٔ ستاره‌ها تا انتخاب کاربر با public.js انجام می‌شود (بدون JS هم ستارهٔ انتخاب‌شده پر می‌ماند).
     */
    private static function starWidget(string $idPrefix): string
    {
        $html = '<div class="hx-star-widget" data-hx-star-widget>'
            . '<div class="hx-star-row" role="radiogroup" aria-labelledby="' . self::e($idPrefix) . '-rating-label">';
        for ($v = 5; $v >= 1; $v--) {
            $html .= '<label class="hx-star" data-star-value="' . $v . '">'
                . '<input type="radio" name="rating" value="' . $v . '" required aria-label="امتیاز ' . self::faDigits((string)$v) . ' از ۵">'
                . '<span>' . self::icon('star') . '</span></label>';
        }
        $html .= '</div>'
            . '<span class="hx-star-value" data-hx-star-value data-active="0">امتیاز را با کلیک روی ستاره‌ها انتخاب کنید</span>'
            . '</div>';
        return $html;
    }

    /**
     * GET /comment/{city}/{slug} — صفحه اختصاصی ثبت دیدگاه (S16؛ از S20 مسیر پشتیبان برای بدون‌جاوااسکریپت)
     * عمداً کش نمی‌شود؛ سوال امنیتی برای هر بازدید تازه ساخته می‌شود.
     */
    public static function commentPage(Request $req, array $params = []): void
    {
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');

        $slug = rawurldecode((string)($params['slug'] ?? ''));
        $citySlug = rawurldecode((string)($params['city'] ?? ''));

        $biz = DB::fetchOne(
            "SELECT b.id, b.name, b.slug, b.short_desc,
                    c.name_fa AS city_name_fa, c.slug AS city_slug,
                    cat.name_fa AS category_name_fa
             FROM businesses b
             INNER JOIN cities c ON c.id = b.city_id
             INNER JOIN categories cat ON cat.id = b.category_id
             WHERE b.slug = :slug AND c.slug = :city AND b.status = 'published'
             LIMIT 1",
            ['slug' => $slug, 'city' => $citySlug]
        );
        if (!$biz) {
            self::render404('برای ثبت دیدگاه، ابتدا صفحه کسب‌وکار مورد نظر را باز کنید.');
            return;
        }

        $question = CommentService::generateQuestion((int)$biz['id']);
        $detailUrl = BusinessUrlService::path((string)$biz['city_slug'], (string)$biz['slug']);
        $baseUrl = self::baseUrl();

        ob_start();
        ?>
        <main class="hx-container hx-comment-page" data-hx-page-view data-business-id="<?= (int)$biz['id'] ?>">
            <nav class="hx-breadcrumb" aria-label="مسیر صفحه">
                <a href="/">حامی‌هاب گیلان</a>
                <span>/</span>
                <a href="/city/<?= self::e($biz['city_slug']) ?>/"><?= self::e($biz['city_name_fa']) ?></a>
                <span>/</span>
                <a href="<?= self::e($detailUrl) ?>"><?= self::e($biz['name']) ?></a>
                <span>/</span>
                <strong>ثبت دیدگاه</strong>
            </nav>
            <div class="hx-comment-card">
                <span class="hx-hero-badge">
                    <?= self::icon('message-question', 'hx-icon hx-icon-sm') ?>
                    <span>دیدگاه شما مستقیماً برای تحریریه حامی‌هاب ثبت می‌شود</span>
                </span>
                <h1>ثبت دیدگاه درباره «<?= self::e($biz['name']) ?>»</h1>
                <p class="hx-comment-sub">
                    تجربه واقعی خود از این مجموعه را بنویسید؛ پس از تایید تحریریه، دیدگاه شما با نشان «تایید حامی‌هاب» در صفحه مجموعه نمایش داده می‌شود.
                </p>
                <form data-hx-public-form="comment" method="post" action="/api/v1/public/comments" id="hx-comment-form">
                    <input type="hidden" name="business_id" value="<?= (int)$biz['id'] ?>">
                    <input type="hidden" name="hx_a" value="<?= (int)$question['a'] ?>">
                    <input type="hidden" name="hx_b" value="<?= (int)$question['b'] ?>">
                    <input type="hidden" name="hx_ts" value="<?= (int)$question['ts'] ?>">
                    <input type="hidden" name="hx_sig" value="<?= self::e($question['sig']) ?>">
                    <input type="text" name="website_hp" value="" tabindex="-1" autocomplete="off" style="display:none!important;" aria-hidden="true">
                    <div class="hx-comment-grid">
                        <div>
                            <label for="hx-c-name">نام شما *</label>
                            <input id="hx-c-name" type="text" name="name" required maxlength="60" placeholder="مثلاً: سارا محمدی">
                        </div>
                        <div>
                            <label id="hx-c-rating-label">امتیاز شما *</label>
                            <?= self::starWidget('hx-c') ?>
                        </div>
                    </div>
                    <div>
                        <label for="hx-c-body">دیدگاه شما *</label>
                        <textarea id="hx-c-body" name="body" required rows="4" minlength="10" maxlength="1000" placeholder="کیفیت خدمات، برخورد پرسنل، قیمت یا هر تجربه‌ای که برای دیگران مفید است…"></textarea>
                    </div>
                    <div class="hx-comment-grid">
                        <div>
                            <label for="hx-c-answer">سوال امنیتی: <?= self::e($question['label']) ?> *</label>
                            <input id="hx-c-answer" type="text" name="answer" required maxlength="4" inputmode="numeric" placeholder="پاسخ عددی" dir="ltr">
                        </div>
                    </div>
                    <div class="hx-comment-actions">
                        <button type="submit" class="hx-btn hx-btn-primary hx-btn-lg">ارسال دیدگاه برای تایید تحریریه</button>
                        <span data-hx-form-msg role="status" aria-live="polite"></span>
                    </div>
                    <p class="hx-comment-note">
                        با ارسال دیدگاه، موافقت می‌کنید که متن آن پس از بازبینی منتشر شود؛ اطلاعات تماس خصوصی و تخلفات قوانین، پیش از انتشار حذف می‌شود.
                    </p>
                    <noscript>برای ارسال امن دیدگاه، جاوااسکریپت را فعال کنید.</noscript>
                </form>
                <div id="hx-comment-done" class="hx-comment-done" style="display:none;">
                    <h2><?= self::icon('tick-circle') ?> دیدگاه شما ثبت شد</h2>
                    <p>دیدگاه شما ثبت شد و در انتظار تایید تحریریه است؛ پس از تایید، در صفحه مجموعه با نشان «تایید حامی‌هاب» نمایش داده می‌شود.</p>
                    <a class="hx-btn hx-btn-outline" href="<?= self::e($detailUrl) ?>">بازگشت به صفحه «<?= self::e($biz['name']) ?>»</a>
                </div>
            </div>
        </main>
        <?php
        $bodyHtml = (string)ob_get_clean();

        Response::html(self::renderLayout([
            'title'       => 'ثبت دیدگاه درباره ' . $biz['name'] . ' | حامی‌هاب گیلان',
            'description' => 'تجربه خود از ' . $biz['name'] . ' در ' . $biz['city_name_fa'] . ' را ثبت کنید؛ پس از تایید تحریریه حامی‌هاب نمایش داده می‌شود.',
            'canonical'   => $baseUrl . '/comment/' . rawurlencode((string)$biz['city_slug']) . '/' . rawurlencode((string)$biz['slug']) . '/',
            'og_type'     => 'website',
            'noindex'     => true,
            'ad_placement'=> '',
            'body'        => $bodyHtml,
        ]));
    }

    /**
     * POST /api/v1/public/comments — ثبت دیدگاه کاربر برای کسب‌وکار (S16)
     * سپرها: honeypot + محدودیت نرخ + سوال امنیتی امضاشده HMAC + اعتبارسنجی سخت متن
     */
    public static function submitComment(Request $req, array $params = []): void
    {
        if (trim((string)$req->input('website_hp', '')) !== '') {
            Response::json(['ok' => false, 'error' => 'درخواست پذیرفته نشد.'], 422);
            return;
        }
        if (Auth::isRateLimited('@public-comment', $req->ip())) {
            Response::json(['ok' => false, 'error' => 'تعداد دیدگاه‌های ارسالی زیاد است؛ کمی بعد دوباره تلاش کنید.'], 429);
            return;
        }
        Auth::recordAttempt('@public-comment', $req->ip(), false);

        $bizId = (int)$req->input('business_id', 0);
        $name = Normalizer::cleanPersianText((string)$req->input('name', ''));
        $body = Normalizer::cleanPersianText((string)$req->input('body', ''));
        $rating = (int)$req->input('rating', 0);
        $answer = (string)$req->input('answer', '');

        if ($bizId <= 0 || mb_strlen($name) < 2) {
            Response::json(['ok' => false, 'error' => 'لطفاً نام خود را (حداقل ۲ نویسه) وارد کنید.'], 422);
            return;
        }
        if ($rating < 1 || $rating > 5) {
            Response::json(['ok' => false, 'error' => 'لطفاً امتیاز ۱ تا ۵ ستاره را انتخاب کنید.'], 422);
            return;
        }
        if (mb_strlen($body) < 10 || mb_strlen($body) > 1000) {
            Response::json(['ok' => false, 'error' => 'متن دیدگاه باید بین ۱۰ تا ۱۰۰۰ نویسه باشد.'], 422);
            return;
        }

        $ok = CommentService::verifyQuestion((string)$req->input('hx_a', ''), (string)$req->input('hx_b', ''), (string)$req->input('hx_ts', ''), (string)$req->input('hx_sig', ''), $answer);
        if (!$ok) {
            Response::json(['ok' => false, 'error' => 'پاسخ سوال امنیتی درست نیست؛ لطفاً صفحه را تازه‌سازی کنید.'], 422);
            return;
        }

        try {
            $result = CommentService::submit($bizId, $name, $rating, $body, $req->ip());
        } catch (Throwable $e) {
            error_log('HX comment submit failed: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'ثبت دیدگاه در این لحظه ممکن نشد؛ چند لحظه بعد تلاش کنید.'], 500);
            return;
        }
        if (!$result['ok']) {
            Response::json(['ok' => false, 'error' => (string)$result['error']], $result['status'] ?? 422);
            return;
        }
        AdminMailService::notify('comment');
        Response::json([
            'ok' => true,
            'comment_id' => $result['id'],
            'message' => 'دیدگاه شما ثبت شد و پس از تایید تحریریه در صفحه مجموعه نمایش داده می‌شود.',
        ]);
    }

    /**
     * GET /api/v1/comments — فهرست دیدگاه‌ها برای پنل مدیریت (S16)
     */
    public static function adminCommentsList(Request $req, array $params = []): void
    {
        Auth::requireRole(['admin', 'editor']);
        try {
            Response::json(array_merge(['ok' => true], CommentService::adminList(
                (string)$req->input('status', 'pending'),
                (string)$req->input('q', ''),
                max(1, min(10, (int)$req->input('page', 1)))
            )));
        } catch (Throwable $e) {
            error_log('HX admin comments list: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'خواندن فهرست دیدگاه‌ها ناموفق بود.'], 500);
        }
    }

    /**
     * POST /api/v1/comments/moderate — تایید، رد یا حذف دیدگاه (S16)
     */
    public static function adminCommentsModerate(Request $req, array $params = []): void
    {
        Csrf::verifyOrFail($req);
        Auth::requireRole(['admin', 'editor']);
        $id = (int)$req->input('id', 0);
        $action = (string)$req->input('action', '');
        if ($id <= 0 || !in_array($action, ['approve', 'reject', 'delete'], true)) {
            Response::json(['ok' => false, 'error' => 'پارامترهای بررسی دیدگاه نامعتبر است.'], 422);
            return;
        }
        try {
            $result = CommentService::moderate($id, $action);
        } catch (Throwable $e) {
            error_log('HX comment moderate failed: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'بررسی دیدگاه ناموفق بود؛ گزارش سرور را ببینید.'], 500);
            return;
        }
        if (!$result['ok']) {
            Response::json($result, 404);
            return;
        }
        Cache::flushAll();
        Response::json(['ok' => true, 'message' => $result['message']]);
    }

    /** صفحه فارسی مهربان ۵۰۰ برای سپر خطای سراسری index.php (بدون وابستگی به دیتابیس) */
    public static function renderShieldPage(): void
    {
        if (headers_sent()) { echo '<p style="font-family:Tahoma,sans-serif;text-align:center;">خطای موقت سرور؛ صفحه اصلی سایت در دسترس است.</p>'; return; }
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, follow');
        header('Retry-After: 60');
        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>خطای موقت | حامی‌هاب گیلان</title>'
            . '<style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:Vazirmatn,system-ui,Tahoma,sans-serif;background:#f6f8fb;color:#10131a;padding:1.25rem}'
            . '.card{max-width:520px;width:100%;background:#fff;border:1px solid #e3e8ef;border-radius:16px;box-shadow:0 18px 44px -20px rgba(4,8,20,.25);padding:2.25rem 1.5rem;text-align:center}'
            . '.code{font-size:2.4rem;font-weight:800;line-height:1;background:linear-gradient(135deg,#09132e,#0047cc);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}'
            . 'h1{font-size:1.15rem;margin:.9rem 0 .5rem}p{font-size:.9rem;color:#5c647a;line-height:1.9;margin:.35rem 0 1.4rem}'
            . '.btn{display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;border-radius:10px;padding:.65rem 1.4rem;font-weight:700;font-size:.9rem}</style></head><body>'
            . '<div class="card"><div class="code" dir="ltr">۵۰۰</div><h1>یک خطای موقت در سرور رخ داد</h1>'
            . '<p>صفحه‌های دیگر سایت سالم هستند. چند لحظه صبر کنید و دوباره تلاش کنید؛ اگر مشکل تکرار شد از صفحه اصلی مسیر مورد نظر را باز کنید.</p>'
            . '<a class="btn" href="/">بازگشت به صفحه اصلی حامی‌هاب</a></div></body></html>';
        exit;
    }

    public static function articlePreview(Request $req, array $params = []): void
    {
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        Auth::requireRole(['admin', 'editor']);
        // session_start() can replace Cache-Control; restore the stricter preview policy.
        header('Cache-Control: private, no-store, max-age=0');
        self::articleDetail($req, ['preview_id'=>(int)($params['id'] ?? 0)]);
    }

    public static function articleDetail(Request $req, array $params = []): void
    {
        $preview = isset($params['preview_id']);
        $slug = rawurldecode((string)($params['slug'] ?? ''));
        $query = "SELECT a.*,c.name_fa AS city_name_fa,c.slug AS city_slug,
                    cat.name_fa AS category_name_fa,cat.slug AS category_slug
                  FROM articles a LEFT JOIN cities c ON c.id=a.city_id
                  LEFT JOIN categories cat ON cat.id=a.category_id WHERE ";
        $article = $preview
            ? DB::fetchOne($query . 'a.id=:id LIMIT 1', ['id'=>(int)$params['preview_id']])
            : DB::fetchOne($query . "a.slug=:slug AND a.status='published' LIMIT 1", ['slug'=>$slug]);
        if (!$article && !$preview && ArticleService::schemaReady()) {
            $alias = DB::fetchOne("SELECT a.slug FROM article_url_aliases u INNER JOIN articles a ON a.id=u.article_id WHERE u.slug=:slug AND a.status='published' LIMIT 1", ['slug'=>$slug]);
            if ($alias) Response::redirect('/' . rawurlencode((string)$alias['slug']) . '/');
        }
        if (!$article) {
            self::render404('مقاله یا لیست مورد نظر یافت نشد.');
            return;
        }

        // Use one clock for SQL ordering, preview ordering and labels so a
        // transition cannot produce inconsistent order and badges in one page.
        $sponsorNow = date('Y-m-d H:i:s');
        $working = $preview ? ArticleService::load((int)$article['id']) : null;
        $revision = $working['revision']['data'] ?? null;
        if (is_array($revision)) {
            $article['title'] = $revision['title_fa'];
            $article['slug'] = $revision['slug'];
            $article['meta_title'] = $revision['meta_title'];
            $article['meta_description'] = $revision['meta_description'];
            $article['intro'] = $revision['intro_html'];
            $article['tips_html'] = $revision['methodology_html'];
            $city = !empty($revision['city_id']) ? DB::fetchOne('SELECT name_fa,slug FROM cities WHERE id=:id', ['id'=>(int)$revision['city_id']]) : null;
            $category = !empty($revision['category_id']) ? DB::fetchOne('SELECT name_fa,slug FROM categories WHERE id=:id', ['id'=>(int)$revision['category_id']]) : null;
            $article['city_name_fa'] = $city['name_fa'] ?? null;
            $article['city_slug'] = $city['slug'] ?? null;
            $article['category_name_fa'] = $category['name_fa'] ?? null;
            $article['category_slug'] = $category['slug'] ?? null;
            $items = self::previewArticleItems($revision['items'] ?? [], $sponsorNow);
            $faqs = array_map(static fn(array $f): array => ['question'=>$f['question_fa'], 'answer'=>$f['answer_fa']], $revision['faqs'] ?? []);
            $coverUrl = (string)($revision['cover_url'] ?? '');
        } else {
            $items = DB::fetchAll(
                "SELECT ai.*,b.slug AS biz_slug,b.name,b.short_desc,b.address,b.lat,b.lng,
                        b.internal_score,b.tier,b.confidence,n.name_fa AS neighborhood_name_fa,
                        c.name_fa AS city_name_fa,c.slug AS city_slug,cat.name_fa AS category_name_fa,cat.slug AS category_slug,
                        (SELECT bc.value FROM business_contacts bc WHERE bc.business_id=b.id AND bc.type IN ('phone','mobile') ORDER BY bc.is_primary DESC,bc.id ASC LIMIT 1) AS phone,
                        (SELECT COALESCE(SUM(bs.reviews_count),0) FROM business_sources bs WHERE bs.business_id=b.id) AS reviews_total
                 FROM article_items ai INNER JOIN businesses b ON b.id=ai.business_id
                 INNER JOIN cities c ON c.id=b.city_id INNER JOIN categories cat ON cat.id=b.category_id
                 LEFT JOIN neighborhoods n ON n.id=b.neighborhood_id
                 WHERE ai.article_id=:aid AND b.status='published'
                 ORDER BY CASE WHEN ai.is_sponsored=1 AND (ai.sponsor_start IS NULL OR ai.sponsor_start<=:sponsor_now_start)
                            AND (ai.sponsor_end IS NULL OR ai.sponsor_end>:sponsor_now_end) THEN 1 ELSE 0 END DESC,
                          ai.position ASC,b.internal_score DESC", [
                    'aid'=>(int)$article['id'],
                    'sponsor_now_start'=>$sponsorNow,
                    'sponsor_now_end'=>$sponsorNow,
                ]
            );
            $faqs = DB::fetchAll('SELECT * FROM article_faqs WHERE article_id=:aid ORDER BY sort,id', ['aid'=>(int)$article['id']]);
            $cover = ArticleService::schemaReady() ? DB::fetchOne('SELECT m.file_path_800 FROM article_extras x LEFT JOIN media m ON m.id=x.cover_media_id WHERE x.article_id=:id', ['id'=>(int)$article['id']]) : null;
            $coverUrl = (string)($cover['file_path_800'] ?? '');
        }
        $articleTags = is_array($revision) && is_array($revision['tags'] ?? null)
            ? array_values(array_map(static fn($tag): array => ['name_fa'=>(string)(is_array($tag) ? ($tag['name_fa'] ?? '') : $tag)], $revision['tags']))
            : ArticleService::tagsForArticle((int)$article['id']);
        $articleTags = array_values(array_filter($articleTags, static fn(array $tag): bool => trim((string)($tag['name_fa'] ?? '')) !== ''));
        if (!preg_match('~^/uploads/[a-zA-Z0-9_/-]+\.(?:webp|jpg|jpeg|png)$~iD', $coverUrl)) $coverUrl = '';

        $baseUrl = self::baseUrl();
        $canonical = $baseUrl . '/' . rawurlencode((string)$article['slug']) . '/';

        $articleSchema = [
            '@context'         => 'https://schema.org',
            '@type'            => 'Article',
            'headline'         => (string)$article['title'],
            'description'      => (string)($article['meta_description'] ?: $article['title']),
            'keywords'         => array_values(array_map(static fn(array $tag): string => (string)$tag['name_fa'], $articleTags)),
            'datePublished'    => (string)($article['published_at'] ?: $article['created_at']),
            'dateModified'     => (string)$article['updated_at'],
            'mainEntityOfPage' => $canonical,
            'author'           => [
                '@type' => 'Organization',
                'name'  => 'تحریریه حامی‌هاب گیلان (HamiHubX)',
                'url'   => $baseUrl . '/',
            ],
            'publisher'        => [
                '@type' => 'Organization',
                'name'  => 'HamiHubX.ir',
                'url'   => $baseUrl . '/',
            ],
        ];

        $itemListElements = [];
        foreach ($items as $idx => $it) {
            $itemListElements[] = [
                '@type'    => 'ListItem',
                'position' => $idx + 1,
                'name'     => (string)$it['name'],
                'url'      => $baseUrl . BusinessUrlService::path((string)$it['city_slug'], (string)$it['biz_slug']),
            ];
        }
        $itemListSchema = [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'name'            => (string)$article['title'],
            'numberOfItems'   => count($itemListElements),
            'itemListElement' => $itemListElements,
        ];

        $breadcrumbItems = [
            ['name' => 'حامی‌هاب گیلان', 'url' => $baseUrl . '/'],
        ];
        if (!empty($article['city_slug'])) {
            $breadcrumbItems[] = ['name' => (string)$article['city_name_fa'], 'url' => $baseUrl . '/city/' . $article['city_slug'] . '/'];
        }
        if (!empty($article['city_slug']) && !empty($article['category_slug'])) {
            $breadcrumbItems[] = [
                'name' => (string)$article['category_name_fa'],
                'url'  => $baseUrl . '/city/' . $article['city_slug'] . '/' . $article['category_slug'] . '/',
            ];
        }
        $breadcrumbItems[] = ['name' => (string)$article['title'], 'url' => $canonical];

        $schemas = [$articleSchema, self::buildBreadcrumbSchema($breadcrumbItems)];
        if ($items) $schemas[] = $itemListSchema;

        if (!empty($faqs)) {
            $faqEntities = [];
            foreach ($faqs as $f) {
                $faqEntities[] = [
                    '@type'          => 'Question',
                    'name'           => (string)$f['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text'  => (string)$f['answer'],
                    ],
                ];
            }
            $schemas[] = [
                '@context'   => 'https://schema.org',
                '@type'      => 'FAQPage',
                'mainEntity' => $faqEntities,
            ];
        }

        $mapMarkers = [];
        foreach ($items as $idx => $it) {
            if (!empty($it['lat']) && !empty($it['lng'])) {
                $mapMarkers[] = [
                    'rank'     => $idx + 1,
                    'name'     => (string)$it['name'],
                    'lat'      => (float)$it['lat'],
                    'lng'      => (float)$it['lng'],
                    'district' => (string)($it['neighborhood_name_fa'] ?: $it['city_name_fa']),
                    'url'      => BusinessUrlService::path((string)$it['city_slug'], (string)$it['biz_slug']),
                ];
            }
        }

        ob_start();
        ?>
        <main class="hx-container" <?= $preview ? '' : 'data-hx-page-view data-article-id="' . (int)$article['id'] . '"' ?>>
            <?php if ($preview): ?>
                <div class="hx-card" role="status" style="margin-top:1rem;border:2px solid #d97706;">پیش‌نمایش خصوصی؛ فقط کاربران مجاز پنل این نسخه را می‌بینند. ممکن است نسخه متفاوتی در سایت منتشر شده باشد.</div>
            <?php endif; ?>
            <nav class="hx-breadcrumb" aria-label="مسیر صفحه">
                <a href="/">حامی‌هاب گیلان</a>
                <?php if (!empty($article['city_slug'])): ?>
                    <span>/</span>
                    <a href="/city/<?= self::e($article['city_slug']) ?>/"><?= self::e($article['city_name_fa']) ?></a>
                <?php endif; ?>
                <?php if (!empty($article['city_slug']) && !empty($article['category_slug'])): ?>
                    <span>/</span>
                    <a href="/city/<?= self::e($article['city_slug']) ?>/<?= self::e($article['category_slug']) ?>/"><?= self::e($article['category_name_fa']) ?></a>
                <?php endif; ?>
                <span>/</span>
                <strong><?= self::e($article['title']) ?></strong>
            </nav>

            <article>
                <header class="hx-article-header">
                    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.5rem;">
                        <span class="hx-badge hx-badge-tier-a"><?= self::icon('verify', 'hx-icon hx-icon-sm') ?> <?= $items ? 'فهرست تحریریه' : 'مقاله تحریریه' ?><?= !empty($article['year_label']) ? ' · ' . self::e($article['year_label']) : '' ?></span>
                        <?php if (!empty($article['city_name_fa'])): ?><span class="hx-badge hx-badge-cat"><?= self::icon('location', 'hx-icon hx-icon-sm') ?> <?= self::e($article['city_name_fa']) ?></span><?php endif; ?>
                    </div>
                    <h1><?= self::e($article['title']) ?></h1>
                    <?php if ($articleTags): ?>
                        <div class="hx-article-topic-tags" aria-label="موضوع‌های مقاله" style="display:flex;flex-wrap:wrap;gap:.45rem;margin:.75rem 0;">
                            <?php foreach ($articleTags as $tag): ?><span><?= self::e((string)$tag['name_fa']) ?></span><?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($coverUrl !== ''): ?>
                        <img src="<?= self::e($coverUrl) ?>" alt="<?= self::e($article['title']) ?>" style="display:block;width:100%;max-height:460px;object-fit:cover;border-radius:16px;margin:1rem 0;" loading="eager">
                    <?php endif; ?>
                    <?php if ($items): ?>
                    <div class="hx-summary-box">
                        <?php if (!empty($article['city_name_fa']) || !empty($article['category_name_fa'])): ?>
                        <div class="hx-summary-item"><strong><?= self::icon('location', 'hx-icon hx-icon-sm') ?> حوزه بررسی</strong><span><?= self::e($article['city_name_fa'] ?? '') ?><?= !empty($article['city_name_fa']) && !empty($article['category_name_fa']) ? ' · ' : '' ?><?= self::e($article['category_name_fa'] ?? '') ?></span></div>
                        <?php endif; ?>
                        <div class="hx-summary-item"><strong><?= self::icon('award', 'hx-icon hx-icon-sm') ?> تعداد گزینه‌ها</strong><span><?= count($items) ?> واحد منتخب</span></div>
                        <div class="hx-summary-item"><strong><?= self::icon('star', 'hx-icon hx-icon-sm') ?> ترتیب نمایش</strong><span>انتخاب و ترتیب تحریریه</span></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($article['intro'])): ?>
                        <div class="hx-prose" style="margin-top:1rem;">
                            <?= self::renderArticleText((string)$article['intro']) ?>
                        </div>
                    <?php endif; ?>
                </header>

                <?php if (!empty($items)): ?>
                <section class="hx-section">
                    <h2 class="hx-section-title" style="margin-bottom:0.75rem;">
                        <?= self::icon('award') ?>
                        <span>جدول مقایسه سریع بهترین گزینه‌ها</span>
                    </h2>
                    <div class="hx-table-wrap">
                        <table class="hx-table">
                            <thead>
                                <tr>
                                    <th>رتبه</th>
                                    <th>نام مجموعه</th>
                                    <th>محله / منطقه</th>
                                    <th>ارتباط مستقیم</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $idx => $it):
                                    $isSponsor = self::isActiveSponsored($it, $sponsorNow);
                                    $hl = !empty($it['highlights']) ? (json_decode((string)$it['highlights'], true) ?: []) : [];
                                ?>
                                    <tr>
                                        <td><strong>#<?= $idx + 1 ?></strong></td>
                                        <td>
                                            <a href="<?= self::e(BusinessUrlService::path((string)$it['city_slug'], (string)$it['biz_slug'])) ?>" style="font-weight:800;color:var(--hx-lapis-900);">
                                                <?= self::e($it['name']) ?>
                                            </a>
                                            <?php if ($isSponsor): ?>
                                                <span class="hx-badge hx-badge-sponsor" style="margin-right:0.35rem;"><?= self::icon('star', 'hx-icon hx-icon-sm') ?> <?= self::e($it['sponsor_label'] ?: 'پیشنهاد ویژه') ?></span>
                                            <?php elseif (!empty($hl['badge'])): ?>
                                                <span class="hx-badge hx-badge-cat" style="margin-right:0.35rem;"><?= self::e($hl['badge']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= self::e($it['neighborhood_name_fa'] ?: $it['city_name_fa']) ?></td>
                                        <td>
                                            <?php if (!empty($it['phone'])): ?>
                                                <a class="hx-btn hx-btn-primary" style="padding:0.25rem 0.65rem;font-size:0.75rem;" href="tel:<?= self::e($it['phone']) ?>" data-hx-track="call" data-business-id="<?= (int)$it['business_id'] ?>" data-source-page="article_table">
                                                    <?= self::icon('call', 'hx-icon hx-icon-sm') ?>
                                                    <span>تماس</span>
                                                </a>
                                            <?php else: ?>
                                                <a class="hx-btn hx-btn-outline" style="padding:0.25rem 0.65rem;font-size:0.75rem;" href="<?= self::e(BusinessUrlService::path((string)$it['city_slug'], (string)$it['biz_slug'])) ?>">مشاهده</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <?php endif; ?>

                <?php if (!empty($mapMarkers)): ?>
                    <?= self::renderLazyMapBox($mapMarkers, 'نقشه تعاملی گزینه‌های منتخب این مقاله') ?>
                <?php endif; ?>

                <?php if ($items): ?>
                <section class="hx-section">
                    <h2 class="hx-section-title" style="margin-bottom:1rem;">
                        <?= self::icon('star') ?>
                        <span>بررسی تک‌به‌تک واحدهای منتخب</span>
                    </h2>
                    <div class="hx-grid" style="gap:1.25rem;">
                        <?php foreach ($items as $idx => $it):
                            $hl = !empty($it['highlights']) ? (json_decode((string)$it['highlights'], true) ?: []) : [];
                            $pros = is_array($hl['pros'] ?? null) ? $hl['pros'] : [];
                            $cons = is_array($hl['cons'] ?? null) ? $hl['cons'] : [];
                            $isSponsor = self::isActiveSponsored($it, $sponsorNow);
                        ?>
                            <div class="hx-card <?= $isSponsor ? 'hx-card-sponsored' : '' ?>" id="rank-<?= $idx + 1 ?>">
                                <div class="hx-card-top">
                                    <div style="display:flex;align-items:center;gap:0.55rem;flex-wrap:wrap;">
                                        <span class="hx-rank-num"><?= $idx + 1 ?></span>
                                        <h3 class="hx-card-title" style="margin:0;font-size:1.2rem;">
                                            <a href="<?= self::e(BusinessUrlService::path((string)$it['city_slug'], (string)$it['biz_slug'])) ?>"><?= self::e($it['name']) ?></a>
                                        </h3>
                                        <?php if ($isSponsor): ?>
                                            <span class="hx-badge hx-badge-sponsor"><?= self::icon('star', 'hx-icon hx-icon-sm') ?> <?= self::e($it['sponsor_label'] ?: 'پیشنهاد ویژه') ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($hl['badge'])): ?>
                                            <span class="hx-badge hx-badge-tier-a"><?= self::e((string)$hl['badge']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="hx-card-meta">
                                    <?= self::icon('location', 'hx-icon hx-icon-sm') ?>
                                    <span><?= self::e($it['city_name_fa']) ?><?= !empty($it['neighborhood_name_fa']) ? '، ' . self::e($it['neighborhood_name_fa']) : '' ?></span>
                                    <?php if (!empty($it['address'])): ?>
                                        <span>· <?= self::e($it['address']) ?></span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($hl['verdict'])): ?>
                                    <p style="margin:0;font-weight:700;color:var(--hx-brand-dark);font-size:0.9rem;display:flex;align-items:center;gap:0.4rem;">
                                        <?= self::icon('lamp', 'hx-icon hx-icon-sm') ?>
                                        <span>جمع‌بندی تحریریه: <?= self::e((string)$hl['verdict']) ?></span>
                                    </p>
                                <?php endif; ?>

                                <?php if (!empty($it['custom_desc'])): ?>
                                    <p class="hx-card-desc"><?= self::e((string)$it['custom_desc']) ?></p>
                                <?php elseif (!empty($it['short_desc'])): ?>
                                    <p class="hx-card-desc"><?= self::e((string)$it['short_desc']) ?></p>
                                <?php endif; ?>

                                <?php if (!empty($pros) || !empty($cons)): ?>
                                    <div class="hx-pros-cons">
                                        <?php if (!empty($pros)): ?>
                                            <div class="hx-pros">
                                                <strong><?= self::icon('tick-circle', 'hx-icon hx-icon-sm') ?> نقاط قوت</strong>
                                                <ul>
                                                    <?php foreach ($pros as $p): ?>
                                                        <li><?= self::e((string)$p) ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($cons)): ?>
                                            <div class="hx-cons">
                                                <strong><?= self::icon('info-circle', 'hx-icon hx-icon-sm') ?> نکات قابل توجه</strong>
                                                <ul>
                                                    <?php foreach ($cons as $c): ?>
                                                        <li><?= self::e((string)$c) ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="hx-card-actions">
                                    <a class="hx-btn hx-btn-outline" href="<?= self::e(BusinessUrlService::path((string)$it['city_slug'], (string)$it['biz_slug'])) ?>">صفحه اختصاصی و جزئیات</a>
                                    <?php if (!empty($it['phone'])): ?>
                                        <a class="hx-btn hx-btn-primary" href="tel:<?= self::e($it['phone']) ?>" data-hx-track="call" data-business-id="<?= (int)$it['business_id'] ?>" data-source-page="article_card">
                                            <?= self::icon('call', 'hx-icon hx-icon-sm') ?>
                                            <span>تماس مستقیم</span>
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!empty($it['lat']) && !empty($it['lng'])): ?>
                                        <?= self::mapActionLinks($it, null, 'article_card', 'compact') ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>

                <?php if (!empty($article['tips_html'])): ?>
                    <section class="hx-section">
                        <div class="hx-card">
                            <h2 class="hx-section-title" style="margin-bottom:0.5rem;">
                                <?= self::icon('verify') ?>
                                <span>توضیحات تکمیلی</span>
                            </h2>
                            <div class="hx-prose"><?= self::renderArticleText((string)$article['tips_html']) ?></div>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (!empty($faqs)): ?>
                    <section class="hx-section">
                        <h2 class="hx-section-title" style="margin-bottom:0.85rem;">
                            <?= self::icon('message-question') ?>
                            <span>سؤالات متداول (FAQ)</span>
                        </h2>
                        <?php foreach ($faqs as $f): ?>
                            <div class="hx-faq-item">
                                <p class="hx-faq-q">
                                    <?= self::icon('message-question', 'hx-icon hx-icon-sm') ?>
                                    <span><?= self::e($f['question']) ?></span>
                                </p>
                                <p class="hx-faq-a"><?= self::e($f['answer']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>
            </article>
        </main>
        <?php
        $bodyHtml = (string)ob_get_clean();

        $title = !empty($article['meta_title']) ? (string)$article['meta_title'] : ((string)$article['title'] . ' | حامی‌هاب گیلان');
        $desc = !empty($article['meta_description']) ? (string)$article['meta_description'] : (string)$article['title'];

        $fullHtml = self::renderLayout([
            'title'       => $title,
            'description' => $desc,
            'canonical'   => $canonical,
            'og_type'     => 'article',
            'schemas'     => $schemas,
            'ad_placement'=> 'article',
            'ad_city_id'  => !empty($article['city_id']) ? (int)$article['city_id'] : null,
            'ad_category_id' => !empty($article['category_id']) ? (int)$article['category_id'] : null,
            'sponsor_until' => SponsorshipStatus::nextBoundary($items, $sponsorNow),
            'body'        => $bodyHtml,
        ]);

        if (!$preview) Cache::putPage('/' . $article['slug'], $fullHtml);
        Response::html($fullHtml);
    }

    /**
     * GET /sitemap.xml
     */
    public static function sitemapXml(Request $req, array $params = []): void
    {
        self::ensureDefaultListArticle();
        $baseUrl = self::baseUrl();

        $urls = [
            ['loc' => $baseUrl . '/', 'priority' => '1.0', 'changefreq' => 'daily'],
            ['loc' => $baseUrl . '/advertise', 'priority' => '0.6', 'changefreq' => 'monthly'],
        ];

        $cities = DB::fetchAll("SELECT slug FROM cities WHERE is_active = 1 ORDER BY id ASC");
        $categories = DB::fetchAll("SELECT slug FROM categories WHERE is_active = 1 ORDER BY sort ASC");

        foreach ($cities as $c) {
            $urls[] = [
                'loc'        => $baseUrl . '/city/' . rawurlencode((string)$c['slug']) . '/',
                'priority'   => '0.9',
                'changefreq' => 'daily',
            ];
            foreach ($categories as $cat) {
                $urls[] = [
                    'loc'        => $baseUrl . '/city/' . rawurlencode((string)$c['slug']) . '/' . rawurlencode((string)$cat['slug']) . '/',
                    'priority'   => '0.8',
                    'changefreq' => 'weekly',
                ];
            }
        }

        $articles = DB::fetchAll("SELECT slug FROM articles WHERE status = 'published' ORDER BY id DESC");
        foreach ($articles as $a) {
            $urls[] = [
                'loc'        => $baseUrl . '/' . rawurlencode((string)$a['slug']) . '/',
                'priority'   => '0.9',
                'changefreq' => 'weekly',
            ];
        }

        $businesses = DB::fetchAll("SELECT b.slug,c.slug AS city_slug FROM businesses b INNER JOIN cities c ON c.id=b.city_id WHERE b.status = 'published' ORDER BY b.id DESC LIMIT 1000");
        foreach ($businesses as $b) {
            $urls[] = [
                'loc'        => $baseUrl . BusinessUrlService::path((string)$b['city_slug'], (string)$b['slug']),
                'priority'   => '0.8',
                'changefreq' => 'weekly',
            ];
        }

        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            echo "  <url>\n";
            echo "    <loc>" . htmlspecialchars($u['loc'], ENT_XML1, 'UTF-8') . "</loc>\n";
            echo "    <changefreq>{$u['changefreq']}</changefreq>\n";
            echo "    <priority>{$u['priority']}</priority>\n";
            echo "  </url>\n";
        }
        echo "</urlset>\n";
        exit;
    }

    /**
     * GET /robots.txt
     */
    public static function robotsTxt(Request $req, array $params = []): void
    {
        $baseUrl = self::baseUrl();
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\n";
        echo "Allow: /\n";
        echo "Disallow: /admin/\n";
        echo "Disallow: /api/\n";
        echo "Disallow: /install.php\n";
        echo "Disallow: /check.php\n\n";
        echo "Sitemap: {$baseUrl}/sitemap.xml\n";
        exit;
    }

    /**
     * POST /api/v1/events/track — ثبت سبک بازدید و کلیک‌های تماس، نقشه، اینستاگرام و وب‌سایت.
     * S9: علاوه بر جدول قدیمی events_daily، رویداد در ماژول جامع آمار (تجمیع روزانه + لاگ جزئیات) ثبت می‌شود.
     * بارنامه جدید شامل path، referrer، utm و شناسه شهر/دسته است؛ بارنامه قدیمی صفحات کش‌شده هم پذیرفته می‌شود.
     */
    public static function trackEvent(Request $req, array $params = []): void
    {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 2048 || strlen($req->rawBody()) > 2048) {
            Response::json(['ok'=>false,'error'=>'بارنامه رویداد بیش از حد مجاز است.'], 422);
        }
        $bizId = max(0, (int)$req->input('business_id', 0));
        $artId = max(0, (int)$req->input('article_id', 0));
        $cityId = max(0, (int)$req->input('city_id', 0));
        $catId = max(0, (int)$req->input('category_id', 0));
        $type = (string)$req->input('event_type', '');
        if (!in_array($type, ['view','call','map','instagram','whatsapp','website'], true)
            || (!$bizId && !$artId && !$cityId && !$catId)) {
            Response::json(['ok'=>false,'error'=>'رویداد نامعتبر است.'], 422);
        }
        Auth::startSessionIfNeeded();
        // S23: محدودساز نرخ فایل‌محور بر پایهٔ IP — لایهٔ واقعی ضد سوءاستفاده؛ سقف نشست ۶۰۰/۱۰دقیقه لایهٔ دوم است.
        // رد شدن بی‌سروصدا با ok=true پاس داده می‌شود تا بیکن کلاینت خطای کنسول نگیرد.
        if (!\App\Core\RateLimit::allow('events-track', $req->ip(), 120, 60)) {
            Response::json(['ok'=>true]);
        }
        $period = (int)floor(time() / 600);
        $counter = $_SESSION['hhx_track_limit'] ?? ['period'=>$period,'count'=>0];
        if (!is_array($counter) || (int)($counter['period'] ?? 0) !== $period) $counter = ['period'=>$period,'count'=>0];
        $counter['count'] = (int)($counter['count'] ?? 0) + 1;
        $_SESSION['hhx_track_limit'] = $counter;
        session_write_close();
        if ($counter['count'] > 600) Response::json(['ok'=>true]);
        try {
            $bizTitle = '';
            $artTitle = '';
            if ($bizId) {
                $row = DB::fetchOne("SELECT id, name FROM businesses WHERE id=:id AND status='published'", ['id'=>$bizId]);
                if (!$row) Response::json(['ok'=>false,'error'=>'مورد یافت نشد.'], 404);
                $bizTitle = (string)($row['name'] ?? '');
            }
            if ($artId) {
                $row = DB::fetchOne("SELECT id, title FROM articles WHERE id=:id AND status='published'", ['id'=>$artId]);
                if (!$row) Response::json(['ok'=>false,'error'=>'مورد یافت نشد.'], 404);
                $artTitle = (string)($row['title'] ?? '');
            }
            if ($bizId || $artId) {
                DB::query("INSERT INTO events_daily (day,business_id,article_id,type,cnt) VALUES (CURDATE(),:bid,:aid,:type,1) ON DUPLICATE KEY UPDATE cnt=cnt+1", ['bid'=>$bizId,'aid'=>$artId,'type'=>$type]);
            }
            // ثبت در ماژول جامع آمار S9 — شکستش هرگز پاسخ سایت را خراب نمی‌کند.
            AnalyticsService::record($type === 'website' ? 'website' : $type, [
                'business_id' => $bizId,
                'article_id' => $artId,
                'city_id' => $cityId,
                'category_id' => $catId,
                'target_title' => $bizTitle !== '' ? $bizTitle : $artTitle,
                'path' => $req->input('path', ''),
                'referrer' => $req->input('referrer', ''),
                'search_query' => $req->input('search_query', ''),
                'utm' => is_array($req->input('utm', null)) ? (array)$req->input('utm', null) : [],
            ] + AnalyticsService::requestContext($req));
            Response::json(['ok'=>true]);
        } catch (Throwable $e) {
            error_log('Event tracking failed: ' . $e->getMessage());
            Response::json(['ok'=>false,'error'=>'ثبت رویداد ناموفق بود.'], 500);
        }
    }

    /**
     * POST /api/v1/public/claim — ثبت ادعای مالکیت کسب‌وکار توسط صاحب صنف و ارجاع خودکار به صندوق بازبینی (مرحله S6)
     */
    public static function submitClaim(Request $req, array $params = []): void
    {
        // Honeypot ضد اسپم
        if (trim((string)$req->input('website_hp', '')) !== '') {
            Response::json(['ok' => false, 'error' => 'درخواست پذیرفته نشد.'], 422);
            return;
        }

        if (Auth::isRateLimited('@public-claim', $req->ip())) Response::json(['ok'=>false,'error'=>'تعداد درخواست‌ها بیش از حد مجاز است.'], 429);
        Auth::recordAttempt('@public-claim', $req->ip(), false);

        $bizId = (int)$req->input('business_id', 0);
        $name = Normalizer::cleanPersianText((string)$req->input('name', ''));
        $rawPhone = trim((string)$req->input('phone', ''));
        $note = Normalizer::cleanPersianText((string)$req->input('note', ''));

        if ($bizId <= 0 || $name === '' || $rawPhone === '') {
            Response::json(['ok' => false, 'error' => 'لطفاً نام و شماره تماس معتبر خود را وارد نمایید.'], 422);
            return;
        }

        $biz = DB::fetchOne(
            "SELECT b.id, b.name, b.address, b.internal_score, c.name_fa AS city_name
             FROM businesses b
             LEFT JOIN cities c ON c.id = b.city_id
             WHERE b.id = :id AND b.status = 'published' LIMIT 1",
            ['id' => $bizId]
        );
        if (!$biz) {
            Response::json(['ok' => false, 'error' => 'کسب‌وکار مورد نظر یافت نشد.'], 404);
            return;
        }

        $normalizedPhone = Normalizer::normalizePhone($rawPhone);
        if ($normalizedPhone === '') {
            Response::json(['ok' => false, 'error' => 'شماره تماس معتبر نیست.'], 422);
        }

        $pdo = DB::connection();
        $pdo->beginTransaction();
        DB::query(
            "INSERT INTO claims (business_id, name, phone, note, status, created_at)
             VALUES (:bid, :name, :phone, :note, 'pending', NOW())",
            [
                'bid'   => $bizId,
                'name'  => mb_substr($name, 0, 150),
                'phone' => mb_substr($normalizedPhone, 0, 32),
                'note'  => $note !== '' ? mb_substr($note, 0, 1000) : null,
            ]
        );
        $claimId = DB::lastInsertId();

        // درج هم‌زمان در صندوق بازبینی (review_queue) با نوع claim
        DB::query(
            "INSERT INTO review_queue (kind, target_id, reason_code, payload_json, status, created_at)
             VALUES ('claim', :tid, 'owner_claim_request', :payload, 'pending', NOW())",
            [
                'tid'     => $claimId,
                'payload' => json_encode([
                    'claim_id'       => $claimId,
                    'business_id'    => $bizId,
                    'business_name'  => (string)$biz['name'],
                    'city_name'      => (string)($biz['city_name'] ?? ''),
                    'address'        => (string)($biz['address'] ?? ''),
                    'score'          => (float)($biz['internal_score'] ?? 0),
                    'claimant_name'  => $name,
                    'claimant_phone' => $normalizedPhone,
                    'note'           => $note,
                ], JSON_UNESCAPED_UNICODE),
            ]
        );

        $pdo->commit();

        Response::json([
            'ok'       => true,
            'claim_id' => $claimId,
            'message'  => 'ادعای مالکیت شما با موفقیت ثبت و به صندوق بازبینی تحریریه حامی‌هاب ارجاع شد.',
        ]);
    }

    /**
     * POST /api/v1/public/report — ثبت گزارش خطای اطلاعات کسب‌وکار توسط کاربران و ارجاع به صندوق بازبینی (مرحله S6)
     */
    public static function submitReport(Request $req, array $params = []): void
    {
        if (trim((string)$req->input('website_hp', '')) !== '') {
            Response::json(['ok' => false, 'error' => 'درخواست پذیرفته نشد.'], 422);
            return;
        }

        if (Auth::isRateLimited('@public-report', $req->ip())) Response::json(['ok'=>false,'error'=>'تعداد درخواست‌ها بیش از حد مجاز است.'], 429);
        Auth::recordAttempt('@public-report', $req->ip(), false);

        $bizId = (int)$req->input('business_id', 0);
        $message = Normalizer::cleanPersianText((string)$req->input('message', ''));

        if ($bizId <= 0 || mb_strlen($message) < 5) {
            Response::json(['ok' => false, 'error' => 'لطفاً شرح دقیق مغایرت یا خطای اطلاعات را وارد کنید (حداقل ۵ کاراکتر).'], 422);
            return;
        }

        $biz = DB::fetchOne(
            "SELECT b.id, b.name, b.address, b.internal_score, c.name_fa AS city_name
             FROM businesses b
             LEFT JOIN cities c ON c.id = b.city_id
             WHERE b.id = :id AND b.status = 'published' LIMIT 1",
            ['id' => $bizId]
        );
        if (!$biz) {
            Response::json(['ok' => false, 'error' => 'کسب‌وکار مورد نظر یافت نشد.'], 404);
            return;
        }

        $pdo = DB::connection();
        $pdo->beginTransaction();
        DB::query(
            "INSERT INTO reports (business_id, message, status, created_at)
             VALUES (:bid, :msg, 'pending', NOW())",
            [
                'bid' => $bizId,
                'msg' => mb_substr($message, 0, 1000),
            ]
        );
        $reportId = DB::lastInsertId();

        DB::query(
            "INSERT INTO review_queue (kind, target_id, reason_code, payload_json, status, created_at)
             VALUES ('report', :tid, 'user_error_report', :payload, 'pending', NOW())",
            [
                'tid'     => $reportId,
                'payload' => json_encode([
                    'report_id'      => $reportId,
                    'business_id'    => $bizId,
                    'business_name'  => (string)$biz['name'],
                    'city_name'      => (string)($biz['city_name'] ?? ''),
                    'address'        => (string)($biz['address'] ?? ''),
                    'score'          => (float)($biz['internal_score'] ?? 0),
                    'report_message' => $message,
                ], JSON_UNESCAPED_UNICODE),
            ]
        );

        $pdo->commit();
        AdminMailService::notify('report');

        Response::json([
            'ok'        => true,
            'report_id' => $reportId,
            'message'   => 'گزارش خطای شما با موفقیت ثبت شد و در صندوق بازبینی بررسی خواهد شد.',
        ]);
    }

    /**
     * GET /api/v1/analytics/summary — داشبورد آمار کلیک‌های روزانه (events_daily)، جایگاه‌های اسپانسری و وضعیت کرون (مرحله S6)
     */
    public static function analyticsSummary(Request $req, array $params = []): void
    {
        Auth::requireRole(['admin', 'editor']);

        $byTypeRows = DB::fetchAll(
            "SELECT type, COALESCE(SUM(cnt), 0) AS total
             FROM events_daily
             WHERE day >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             GROUP BY type"
        );
        $totals = [
            'view'      => 0,
            'call'      => 0,
            'map'       => 0,
            'website'   => 0,
            'instagram' => 0,
            'whatsapp'  => 0,
        ];
        foreach ($byTypeRows as $r) {
            $t = (string)$r['type'];
            if (isset($totals[$t])) {
                $totals[$t] = (int)$r['total'];
            }
        }

        $topBusinesses = DB::fetchAll(
            "SELECT b.id, b.name, b.slug, b.tier, b.internal_score,
                    c.name_fa AS city_name, c.slug AS city_slug,
                    COALESCE(SUM(CASE WHEN e.type = 'view' THEN e.cnt ELSE 0 END), 0) AS views_cnt,
                    COALESCE(SUM(CASE WHEN e.type = 'call' THEN e.cnt ELSE 0 END), 0) AS calls_cnt,
                    COALESCE(SUM(CASE WHEN e.type = 'map' THEN e.cnt ELSE 0 END), 0) AS maps_cnt,
                    COALESCE(SUM(e.cnt), 0) AS total_interactions
             FROM businesses b
             LEFT JOIN cities c ON c.id = b.city_id
             LEFT JOIN events_daily e ON e.business_id = b.id
             WHERE b.status = 'published'
             GROUP BY b.id, b.name, b.slug, b.tier, b.internal_score, c.name_fa, c.slug
             ORDER BY total_interactions DESC, b.internal_score DESC
             LIMIT 8"
        );

        $sponsoredItems = DB::fetchAll(
            "SELECT ai.id, ai.article_id, ai.business_id, ai.position, ai.sponsor_label,
                    ai.sponsor_start, ai.sponsor_end,
                    b.name AS business_name, b.slug AS business_slug, b.status AS business_status,
                    a.title AS article_title, a.slug AS article_slug, a.status AS article_status,
                    c.name_fa AS city_name
             FROM article_items ai
             INNER JOIN businesses b ON b.id = ai.business_id
             INNER JOIN articles a ON a.id = ai.article_id
             LEFT JOIN cities c ON c.id = b.city_id
             WHERE ai.is_sponsored = 1
             ORDER BY ai.id DESC"
        );
        // Use the same PHP/site-local clock and boundaries as the public article.
        // article_items contains the published/current snapshot, not a private edit.
        $sponsorNow = date('Y-m-d H:i:s');
        foreach ($sponsoredItems as &$item) {
            $item['display_status'] = \App\Services\SponsorshipStatus::classify($item, $sponsorNow);
        }
        unset($item);

        $claimsCount = (int)(DB::fetchOne("SELECT COUNT(*) AS c FROM claims WHERE status = 'pending'")['c'] ?? 0);
        $reportsCount = (int)(DB::fetchOne("SELECT COUNT(*) AS c FROM reports WHERE status = 'pending'")['c'] ?? 0);
        $lastCronRow = DB::fetchOne("SELECT v FROM settings WHERE k = 'sponsor_last_check' LIMIT 1");

        Response::json([
            'ok'                  => true,
            'totals_30d'          => $totals,
            'top_businesses'      => $topBusinesses,
            'sponsored_items'     => $sponsoredItems,
            'pending_claims'      => $claimsCount,
            'pending_reports'     => $reportsCount,
            'sponsor_last_check'  => (string)($lastCronRow['v'] ?? 'اجرا نشده'),
        ]);
    }

    /**
     * S19: سوال امنیتی تازه برای مودال درخواست تبلیغات روی صفحات کش‌شده — GET /api/v1/public/ad-question
     * صفحات کش‌شده نباید سوال امضاشده با TTL در خود داشته باشند؛ مودال سوال را هنگام باز شدن می‌گیرد.
     */
    public static function adQuestion(Request $req, array $params = []): void
    {
        header('Cache-Control: no-store, max-age=0');
        if (Auth::isRateLimited('@public-adq', $req->ip())) {
            Response::json(['ok' => false, 'error' => 'درخواست‌ها زیاد است؛ کمی بعد تلاش کنید.'], 429);
            return;
        }
        try {
            $question = CommentService::generateQuestion(0);
        } catch (Throwable $e) {
            error_log('HX ad-question failed: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'ساخت سوال امنیتی ممکن نشد؛ دوباره تلاش کنید.'], 500);
            return;
        }
        Response::json(['ok' => true, 'question' => [
            'a' => (int)$question['a'], 'b' => (int)$question['b'], 'ts' => (int)$question['ts'],
            'sig' => (string)$question['sig'], 'label' => (string)$question['label'],
        ]]);
    }

    /** S19: بدنهٔ مشترک فرم درخواست تبلیغات (صفحهٔ اختصاصی + مودال). $question=null یعنی سوال با جاوااسکریپت هنگام باز شدن پر می‌شود. */
    private static function adFormInner(?array $question): string
    {
        $qa = (int)($question['a'] ?? 0);
        $qb = (int)($question['b'] ?? 0);
        $qts = (int)($question['ts'] ?? 0);
        $qsig = self::e((string)($question['sig'] ?? ''));
        $qLabelHtml = $question !== null
            ? 'سوال امنیتی: ' . self::e((string)$question['label']) . ' *'
            : '<span data-hx-ad-q-label>سوال امنیتی در حال آماده‌سازی…</span>';
        return '<div class="hx-ad-honeypot" aria-hidden="true"><label>Website <input name="website_hp" tabindex="-1" autocomplete="off"></label></div>'
            . '<input type="hidden" name="hx_a" value="' . $qa . '">'
            . '<input type="hidden" name="hx_b" value="' . $qb . '">'
            . '<input type="hidden" name="hx_ts" value="' . $qts . '">'
            . '<input type="hidden" name="hx_sig" value="' . $qsig . '">'
            . '<div class="hx-ad-grid">'
            . '<label>نام و نام خانوادگی *<input type="text" name="name" required maxlength="150" autocomplete="name" placeholder="مثلاً: رضا کریمی"></label>'
            . '<label>شماره تماس *<input name="phone" type="tel" required maxlength="32" autocomplete="tel" dir="ltr" inputmode="tel" placeholder="09xxxxxxxxx"></label>'
            . '</div>'
            . '<label>پیام شما (اختیاری)<textarea name="message" maxlength="1000" rows="4" placeholder="نوع کسب‌وکار، جایگاه مدنظر (بنر، جایگاه ویژه، معرفی اختصاصی) و بازهٔ زمانی…"></textarea></label>'
            . '<label>' . $qLabelHtml . '<input type="text" name="answer" required maxlength="4" inputmode="numeric" placeholder="پاسخ عددی" dir="ltr"></label>'
            . '<label class="hx-ad-consent"><input type="checkbox" name="consent" required> با ثبت اطلاعات بالا برای پیگیری درخواست تبلیغات موافقم.</label>'
            . '<button type="submit" class="hx-btn hx-btn-primary hx-btn-lg">ارسال درخواست تبلیغات</button>'
            . '<p data-hx-form-msg role="status" aria-live="polite"></p>';
    }

    /**
     * S19: صفحهٔ اختصاصی تبلیغات — GET /advertise
     * صفحه پویاست و کش نمی‌شود؛ سوال امنیتی سمت سرور تازه ساخته می‌شود و فرم بدون جاوااسکریپت هم کار می‌کند.
     */
    public static function advertisePage(Request $req, array $params = []): void
    {
        if (!AdvertisingService::schemaReady()) {
            self::render404('این بخش در حال حاضر فعال نیست.');
            return;
        }
        $question = CommentService::generateQuestion(0);
        $baseUrl = self::baseUrl();
        ob_start();
        ?>
        <main class="hx-advert-page">
            <section class="hx-container hx-advert-hero">
                <span class="hx-cta-badge">
                    <?= self::icon('announce', 'hx-icon hx-icon-sm') ?>
                    <span>تبلیغات در حامی‌هاب گیلان</span>
                </span>
                <h1>کسب‌وکار شما، دقیقاً وقتی مشتری در حال انتخاب است دیده می‌شود</h1>
                <p>حامی‌هاب مرجع معرفی کسب‌وکارهای استان گیلان است؛ کاربر پیش از انتخاب کافه، رستوران یا مرکز خدمات، صفحات شهر و دسته‌بندی را می‌بیند. فرم زیر را پر کنید تا تیم ما برای پیشنهاد جایگاه مناسب و جزئیات همکاری با شما تماس بگیرد.</p>
            </section>
            <section class="hx-container hx-advert-benefits" aria-label="چرا تبلیغ در حامی‌هاب">
                <div class="hx-advert-benefit">
                    <?= self::icon('location', 'hx-icon') ?>
                    <h2>مخاطب محلی و واقعی</h2>
                    <p>بازدیدکنندگان حامی‌هاب همان کسانی هستند که همین حالا در رشت، انزلی، لاهیجان و شهرهای گیلان در حال انتخاب هستند.</p>
                </div>
                <div class="hx-advert-benefit">
                    <?= self::icon('announce', 'hx-icon') ?>
                    <h2>شکل‌های متنوع دیده‌شدن</h2>
                    <p>بنر صفحات شهر و دسته‌بندی، جایگاه ویژه در فهرست‌ها و معرفی اختصاصی؛ متناسب با هدف شما پیشنهاد می‌شود.</p>
                </div>
                <div class="hx-advert-benefit">
                    <?= self::icon('message-question', 'hx-icon') ?>
                    <h2>شروع ساده و شفاف</h2>
                    <p>درخواست را ثبت کنید؛ تماس می‌گیریم، جایگاه و جزئیات را دقیق توضیح می‌دهیم و پس از تأیید شما منتشر می‌شود.</p>
                </div>
                <div class="hx-advert-benefit">
                    <?= self::icon('tick-circle', 'hx-icon') ?>
                    <h2>پیگیری با آمار</h2>
                    <p>پس از انتشار، نمایش و کلیک بنر شما در پنل ثبت می‌شود تا اثر تبلیغ قابل بررسی باشد.</p>
                </div>
            </section>
            <section class="hx-container hx-advert-formwrap" id="advertise-form">
                <h2>فرم درخواست تبلیغات</h2>
                <p class="hx-advert-formhint">کمتر از یک دقیقه؛ فقط نام، شماره تماس و در صورت تمایل یک پیام کوتاه.</p>
                <form class="hx-ad-form" data-hx-public-form="advertising" action="/api/v1/public/advertising-inquiry" method="post">
                    <?= self::adFormInner($question) ?>
                </form>
            </section>
        </main>
        <?php
        $bodyHtml = (string)ob_get_clean();

        Response::html(self::renderLayout([
            'title'       => 'تبلیغات در حامی‌هاب گیلان | بنر، جایگاه ویژه و معرفی اختصاصی',
            'description' => 'درخواست تبلیغ در حامی‌هاب: بنر صفحات شهر و دسته‌بندی، جایگاه ویژه در فهرست‌ها و معرفی اختصاصی کسب‌وکار شما در استان گیلان؛ ثبت سریع درخواست و پیگیری تلفنی.',
            'canonical'   => $baseUrl . '/advertise',
            'og_type'     => 'website',
            'ad_placement'=> '',
            'body'        => $bodyHtml,
        ]));
    }

    /**
     * S20: سوال امنیتی تازه برای فرم دیدگاه درون‌صفحه‌ای روی صفحات کش‌شده — GET /api/v1/public/comment-question
     * همان سازوکار HMAC دیدگاه‌ها؛ سوال هنگام باز شدن فرم گرفته می‌شود تا صفحات کش‌شده سوال منقضی نداشته باشند.
     */
    public static function commentQuestion(Request $req, array $params = []): void
    {
        header('Cache-Control: no-store, max-age=0');
        if (Auth::isRateLimited('@public-adq', $req->ip())) {
            Response::json(['ok' => false, 'error' => 'درخواست‌ها زیاد است؛ کمی بعد تلاش کنید.'], 429);
            return;
        }
        try {
            $question = CommentService::generateQuestion(0);
        } catch (Throwable $e) {
            error_log('HX comment-question failed: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'ساخت سوال امنیتی ممکن نشد؛ دوباره تلاش کنید.'], 500);
            return;
        }
        Response::json(['ok' => true, 'question' => [
            'a' => (int)$question['a'], 'b' => (int)$question['b'], 'ts' => (int)$question['ts'],
            'sig' => (string)$question['sig'], 'label' => (string)$question['label'],
        ]]);
    }

    /**
     * POST /api/v1/system/cron-run — اجرای دستی ورکر ۵ دقیقه‌ای S6 از داخل پنل مدیریت بدون نیاز به SSH
     */
    public static function runCronMaintenance(Request $req, array $params = []): void
    {
        Csrf::verifyOrFail($req);
        Auth::requireRole(['admin']);

        try {
            $summary = \App\Services\MaintenanceService::run();
            if (($summary['status'] ?? '') === 'busy') {
                Response::json(['ok'=>false, 'error'=>$summary['message']], 409);
            }
            if (($summary['status'] ?? '') === 'cache_error') {
                Response::json(['ok'=>false, 'error'=>'نگهداری اجرا شد، اما پاک‌سازی کش تأیید نشد؛ گزارش سرور را بررسی کنید.', 'summary'=>$summary], 500);
            }
            $message = !empty($summary['collector_recovery_skipped'])
                ? 'نگهداری انجام شد؛ بررسی کارهای قدیمی جمع‌آوری تا پایان اجرای فعلی به تعویق افتاد.'
                : 'نگهداری سیستم انجام شد.';
            Response::json(array_merge(['ok' => true, 'message' => $message], $summary));
        } catch (Throwable $e) {
            error_log('Maintenance failed: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'اجرای نگهداری ناموفق بود؛ گزارش سرور را بررسی کنید.'], 500);
        }
    }

    /** Hydrate a private revision's list without changing the live article_items. */
    private static function previewArticleItems(array $draftItems, string $sponsorNow): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn($item): int => is_array($item) ? (int)($item['business_id'] ?? 0) : 0, $draftItems))));
        if (!$ids) return [];
        $rows = DB::fetchAll("SELECT b.id AS business_id,b.slug AS biz_slug,b.name,b.short_desc,b.address,b.lat,b.lng,
                b.internal_score,b.tier,b.confidence,n.name_fa AS neighborhood_name_fa,c.name_fa AS city_name_fa,c.slug AS city_slug,
                cat.name_fa AS category_name_fa,cat.slug AS category_slug,
                (SELECT bc.value FROM business_contacts bc WHERE bc.business_id=b.id AND bc.type IN ('phone','mobile') ORDER BY bc.is_primary DESC,bc.id ASC LIMIT 1) AS phone,
                (SELECT COALESCE(SUM(bs.reviews_count),0) FROM business_sources bs WHERE bs.business_id=b.id) AS reviews_total
            FROM businesses b INNER JOIN cities c ON c.id=b.city_id INNER JOIN categories cat ON cat.id=b.category_id
            LEFT JOIN neighborhoods n ON n.id=b.neighborhood_id
            WHERE b.status='published' AND b.id IN (" . implode(',', $ids) . ')');
        $byId = [];
        foreach ($rows as $row) $byId[(int)$row['business_id']] = $row;
        $items = [];
        foreach ($draftItems as $draft) {
            if (!is_array($draft)) continue;
            $id = (int)($draft['business_id'] ?? 0);
            if (!isset($byId[$id])) continue;
            $items[] = array_merge($byId[$id], [
                'position'=>(int)($draft['rank_num'] ?? 1), 'custom_desc'=>(string)($draft['custom_desc_html'] ?? ''),
                'highlights'=>(string)($draft['highlights'] ?? '{}'), 'is_sponsored'=>(int)($draft['is_sponsored'] ?? 0),
                'sponsor_label'=>(string)($draft['sponsor_label'] ?? 'تبلیغ'),
                'sponsor_start'=>$draft['sponsor_start'] ?? null, 'sponsor_end'=>$draft['sponsor_end'] ?? null,
            ]);
        }
        usort($items, static function (array $a, array $b) use ($sponsorNow): int {
            return (int)self::isActiveSponsored($b, $sponsorNow) <=> (int)self::isActiveSponsored($a, $sponsorNow)
                ?: ((int)$a['position'] <=> (int)$b['position'])
                ?: ((float)$b['internal_score'] <=> (float)$a['internal_score']);
        });
        return $items;
    }

    /** Legacy text is unchanged; explicitly formatted text is reconstructed from safe markup. */
    private static function renderArticleText(string $text): string
    {
        return ArticleText::render($text);
    }

    private static function fetchPublishedBusinesses(string $whereClause, array $params, int $limit, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $sql = "
            SELECT b.*,
                   c.name_fa AS city_name_fa, c.slug AS city_slug,
                   cat.name_fa AS category_name_fa, cat.slug AS category_slug,
                   n.name_fa AS neighborhood_name_fa,
                   cover.file_path_480 AS cover_thumb_url, cover.alt_text AS cover_alt_text,
                   (SELECT bc.value FROM business_contacts bc WHERE bc.business_id = b.id AND bc.type IN ('phone','mobile') ORDER BY bc.is_primary DESC, bc.id ASC LIMIT 1) AS phone,
                   (SELECT bc.value FROM business_contacts bc WHERE bc.business_id = b.id AND bc.type = 'website' ORDER BY bc.is_primary DESC, bc.id ASC LIMIT 1) AS website,
                   (SELECT bc.value FROM business_contacts bc WHERE bc.business_id = b.id AND bc.type = 'instagram' ORDER BY bc.is_primary DESC, bc.id ASC LIMIT 1) AS instagram,
                   (SELECT bc.value FROM business_contacts bc WHERE bc.business_id = b.id AND bc.type IN ('phone','mobile') ORDER BY bc.is_primary DESC, bc.id ASC LIMIT 1) AS phone,
                   rs.rating AS source_rating, rs.reviews_count AS source_reviews_count,
                   rs.provider AS rating_provider, COALESCE(rd.observed_at,rs.fetched_at) AS rating_fetched_at,
                   COALESCE(bp.active,0) AS promoted
            FROM businesses b
            INNER JOIN cities c ON c.id = b.city_id
            INNER JOIN categories cat ON cat.id = b.category_id
            LEFT JOIN neighborhoods n ON n.id = b.neighborhood_id
            LEFT JOIN media cover ON cover.id = COALESCE(b.cover_media_id, (
                SELECT photo.media_id FROM business_photos photo
                WHERE photo.business_id=b.id AND photo.status='approved'
                ORDER BY photo.sort ASC, photo.id ASC LIMIT 1
            ))
            LEFT JOIN business_promotions bp ON bp.business_id = b.id
            LEFT JOIN business_sources rs ON rs.id = (
                SELECT s.id FROM business_sources s
                LEFT JOIN business_source_rating_dates d ON d.business_source_id=s.id
                WHERE s.business_id=b.id AND s.rating BETWEEN 1 AND 5 AND s.reviews_count > 0
                ORDER BY COALESCE(d.observed_at,s.fetched_at) DESC, s.id DESC LIMIT 1
            )
            LEFT JOIN business_source_rating_dates rd ON rd.business_source_id=rs.id
            WHERE {$whereClause}
            ORDER BY COALESCE(bp.active,0) DESC, (rs.rating IS NULL) ASC,
                     rs.rating DESC, rs.reviews_count DESC, b.name ASC, b.id ASC
            LIMIT {$limit} OFFSET {$offset}
        ";
        return DB::fetchAll($sql, $params);
    }

    private static function ratingLabel(array $biz): string
    {
        if (($biz['source_rating'] ?? null) === null) return 'امتیاز ثبت نشده';
        $rating = rtrim(rtrim(number_format((float)$biz['source_rating'], 2, '.', ''), '0'), '.');
        $providers = ['google_maps'=>'Google Maps', 'osm'=>'OpenStreetMap', 'neshan'=>'نشان', 'balad'=>'بلد'];
        $provider = (string)($biz['rating_provider'] ?? '');
        $observed = !empty($biz['rating_fetched_at']) ? ' · دریافت ' . substr((string)$biz['rating_fetched_at'], 0, 10) : '';
        return 'امتیاز ' . $rating . ' از ۵ · ' . (int)$biz['source_reviews_count'] . ' نظر · ' . ($providers[$provider] ?? $provider) . $observed;
    }

    private static function renderBusinessCard(array $biz, int $rank, string $sourcePage): string
    {
        $slugUrl = BusinessUrlService::path((string)$biz['city_slug'], (string)$biz['slug']);
        $coverPath = (string)($biz['cover_thumb_url'] ?? '');
        // Never substitute a stock photo or load arbitrary external URLs as a place's photo.
        $hasCover = (bool)preg_match('~^/uploads/[a-zA-Z0-9_/-]+\.(?:webp|jpg|jpeg|png)$~iD', $coverPath);
        $providers = ['google_maps'=>'Google Maps', 'osm'=>'OpenStreetMap', 'neshan'=>'نشان', 'balad'=>'بلد'];
        $provider = (string)($biz['rating_provider'] ?? '');
        $rating = $biz['source_rating'] ?? null;
        ob_start();
        ?>
        <article class="hx-card hx-business-card"<?= !empty($biz['category_slug']) ? ' data-hx-cat-card data-hx-cat="' . self::e((string)$biz['category_slug']) . '"' : '' ?>>
            <a class="hx-place-media" href="<?= self::e($slugUrl) ?>" tabindex="-1" aria-hidden="true">
                <?php if ($hasCover): ?>
                    <img src="<?= self::e($coverPath) ?>" alt="" loading="lazy" decoding="async" width="480" height="300">
                <?php else: ?>
                    <span class="hx-place-no-image"><?= self::icon('location', 'hx-icon hx-icon-lg') ?><span>تصویر ثبت نشده</span></span>
                <?php endif; ?>
                <?php if (!empty($biz['city_name_fa'])): ?><span class="hx-place-city-chip"><?= self::icon('location', 'hx-icon hx-icon-sm') ?><?= self::e((string)$biz['city_name_fa']) ?></span><?php endif; ?>
                <?php if (!empty($biz['promoted'])): ?><span class="hx-place-sponsored">جایگاه ویژه</span><?php endif; ?>
                <?php if ($rank > 0 && $rank <= 3): ?><span class="hx-place-rank" title="رتبه <?= self::e((string)$rank) ?> در این فهرست"><?= self::e((string)$rank) ?></span><?php endif; ?>
            </a>
            <div class="hx-place-body">
                <?php if (!empty($biz['promoted'])): ?><span class="hx-visually-hidden">جایگاه ویژه</span><?php endif; ?>
                <div class="hx-place-context"><?= self::e((string)($biz['city_name_fa'] ?? '')) ?><?= !empty($biz['neighborhood_name_fa']) ? '، ' . self::e((string)$biz['neighborhood_name_fa']) : '' ?><?php if (!empty($biz['category_name_fa'])): ?> <span>·</span> <?= self::e((string)$biz['category_name_fa']) ?><?php endif; ?></div>
                <h3 class="hx-place-title"><a href="<?= self::e($slugUrl) ?>"><?= self::e((string)$biz['name']) ?></a></h3>
                <?php if ($rating !== null): ?>
                    <div class="hx-place-rating">
                        <?= self::icon('star', 'hx-icon hx-icon-sm') ?>
                        <strong dir="ltr"><?= self::e(rtrim(rtrim(number_format((float)$rating, 2, '.', ''), '0'), '.')) ?></strong><span>از ۵</span>
                        <span class="hx-place-divider">·</span><span><?= (int)$biz['source_reviews_count'] ?> نظر</span>
                    </div>
                    <div class="hx-place-source"><?= self::e($providers[$provider] ?? $provider) ?><?php if (!empty($biz['rating_fetched_at'])): ?> <span>· دریافت </span><time dir="ltr" datetime="<?= self::e(substr((string)$biz['rating_fetched_at'], 0, 10)) ?>"><?= self::e(substr((string)$biz['rating_fetched_at'], 0, 10)) ?></time><?php endif; ?></div>
                <?php else: ?>
                    <div class="hx-place-rating hx-place-rating-empty"><?= self::icon('star', 'hx-icon hx-icon-sm') ?> امتیاز ثبت نشده</div>
                <?php endif; ?>
                <p class="hx-place-address"><?= self::icon('location', 'hx-icon hx-icon-sm') ?><span><?= self::e(!empty($biz['address']) ? (string)$biz['address'] : 'نشانی دقیق ثبت نشده') ?></span></p>
                <?php if (!empty($biz['short_desc'])): ?><p class="hx-place-description"><?= self::e((string)$biz['short_desc']) ?></p><?php endif; ?>
                <div class="hx-place-actions">
                    <a class="hx-btn hx-btn-primary hx-place-details" href="<?= self::e($slugUrl) ?>">مشاهده جزئیات</a>
                    <?php if (!empty($biz['phone'])): ?>
                        <a class="hx-btn hx-btn-outline" href="tel:<?= self::e((string)$biz['phone']) ?>" data-hx-track="call" data-business-id="<?= (int)$biz['id'] ?>" data-source-page="<?= self::e($sourcePage) ?>"><?= self::icon('call', 'hx-icon hx-icon-sm') ?> تماس</a>
                    <?php endif; ?>
                    <?php if (!empty($biz['lat']) && !empty($biz['lng'])): ?>
                        <?= self::mapActionLinks($biz, null, $sourcePage, 'compact') ?>
                    <?php endif; ?>
                </div>
            </div>
        </article>
        <?php
        return (string)ob_get_clean();
    }

    private static function renderLazyMapBox(array $markers, string $title, bool $compact = false): string
    {
        $jsonMarkers = htmlspecialchars((string)json_encode($markers, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        ob_start();
        ?>
        <section class="hx-map-box<?= $compact ? ' hx-map-compact' : '' ?>">
            <div class="hx-map-placeholder" data-hx-map="1" data-markers="<?= $jsonMarkers ?>">
                <strong style="display:flex;align-items:center;gap:0.45rem;">
                    <?= self::icon('map') ?>
                    <span><?= self::e($title) ?> (<?= count($markers) ?> نقطه)</span>
                </strong>
                <?php if (!$compact): ?><p style="margin:0;font-size:0.84rem;color:var(--hx-slate-600);">نقشه OpenStreetMap فقط با کلیک شما بارگذاری می‌شود.</p><?php endif; ?>
                <button type="button" class="hx-btn hx-btn-outline" data-hx-load-map="1">
                    <?= self::icon('location', 'hx-icon hx-icon-sm') ?>
                    <span>نمایش نقشه</span>
                </button>
            </div>
        </section>
        <?php
        return (string)ob_get_clean();
    }

    private static function renderLayout(array $data): string
    {
        $title = str_replace(['همی‌هاب', 'همی هاب', 'همیهاب'], 'حامی‌هاب', (string)($data['title'] ?? 'حامی‌هاب گیلان'));
        $description = str_replace(['همی‌هاب', 'همی هاب', 'همیهاب'], 'حامی‌هاب', (string)($data['description'] ?? ''));
        $canonical = (string)($data['canonical'] ?? self::baseUrl() . '/');
        $noindex = !empty($data['noindex']);
        $ogType = (string)($data['og_type'] ?? 'website');
        $schemas = (array)($data['schemas'] ?? []);
        $body = str_replace(['همی‌هاب', 'همی هاب', 'همیهاب'], 'حامی‌هاب', (string)($data['body'] ?? ''));
        $baseUrl = self::baseUrl();
        $ad = AdvertisingService::placement((string)($data['ad_placement'] ?? ''), $data['ad_city_id'] ?? null, $data['ad_category_id'] ?? null);
        $footerAd = AdvertisingService::placement('footer_above', $data['ad_city_id'] ?? null, $data['ad_category_id'] ?? null, null, true, 'hx-ad-wrap-footer');
        $adUntil = AdvertisingService::earliestBoundary((int)$ad['until'], (int)$footerAd['until'], (int)($data['extra_ad_until'] ?? 0));
        $advertisingReady = AdvertisingService::schemaReady();

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= self::e($title) ?></title>
    <meta name="description" content="<?= self::e($description) ?>">
    <?php if ($noindex): ?>
    <meta name="robots" content="noindex, follow">
    <?php else: ?>
    <link rel="canonical" href="<?= self::e($canonical) ?>">
    <?php endif; ?>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="icon" type="image/png" sizes="128x128" href="/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="/assets/img/favicon.png">
    <meta name="theme-color" content="#040814">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:type" content="<?= self::e($ogType) ?>">
    <meta property="og:title" content="<?= self::e($title) ?>">
    <meta property="og:description" content="<?= self::e($description) ?>">
    <meta property="og:url" content="<?= self::e($canonical) ?>">
    <meta property="og:site_name" content="حامی‌هاب گیلان | HamiHubX.ir">
    <meta property="og:image" content="<?= self::e($baseUrl) ?>/assets/img/logo-hx-card.webp">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= self::e($title) ?>">
    <meta name="twitter:description" content="<?= self::e($description) ?>">
    <meta name="twitter:image" content="<?= self::e($baseUrl) ?>/assets/img/logo-hx-card.webp">
    <link rel="preload" href="/assets/fonts/Vazirmatn-Regular.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/assets/fonts/Vazirmatn-Bold.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/public.css?v=s21.0">
    <!-- hhx-ad-until:<?= $adUntil ?> -->
    <!-- hhx-sponsor-until:<?= max(0, (int)($data['sponsor_until'] ?? 0)) ?> -->
    <?php foreach ($schemas as $schema):
        $schemaJson = str_replace(['همی‌هاب', 'همی هاب', 'همیهاب'], 'حامی‌هاب', (string)json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
    ?>
    <script type="application/ld+json"><?= $schemaJson ?></script>
    <?php endforeach; ?>
</head>
<body>
    <a class="hx-skip-link" href="#hx-content">پرش به محتوای اصلی</a>
    <header class="hx-header">
        <div class="hx-container hx-header-inner">
            <a href="/" class="hx-brand">
                <img src="/assets/img/favicon.png" alt="HamiHubX" class="hx-brand-logo" width="40" height="40">
                <span>
                    حامی‌هاب گیلان
                    <span class="hx-brand-en" dir="ltr">HamiHub<span class="hx-brand-x">X</span></span>
                </span>
            </a>
            <nav class="hx-nav" aria-label="منوی اصلی">
                <a href="/">صفحه اصلی</a>
                <a href="/city/rasht/cafe-restaurant/"><?= self::icon('award', 'hx-icon hx-icon-sm') ?> کافه‌ها و رستوران‌های رشت</a>
                <a href="/city/rasht/"><?= self::icon('location', 'hx-icon hx-icon-sm') ?> شهر رشت</a>
                <a href="/city/bandar-anzali/">بندر انزلی</a>
                <a href="/city/lahijan/">لاهیجان</a>
            </nav>
            <div class="hx-header-actions">
                <a href="/advertise" class="hx-nav-cta" data-hx-ad-modal>
                    <?= self::icon('announce', 'hx-icon hx-icon-sm') ?>
                    <span>درخواست تبلیغات</span>
                </a>
            </div>
        </div>
    </header>

    <?= $ad['html'] ?>
    <div id="hx-content">
    <?= $body ?>
    </div>
    <?= $footerAd['html'] ?>

    <footer class="hx-footer">
        <div class="hx-container hx-footer-grid">
            <div class="hx-footer-col hx-footer-about">
                <div style="display:flex;align-items:center;gap:0.65rem;margin-bottom:0.65rem;">
                    <img src="/assets/img/favicon.png" alt="HamiHubX" width="36" height="36" style="border-radius:9px;">
                    <strong style="color:#fff;font-size:1.02rem;">حامی‌هاب گیلان <span dir="ltr" style="font-weight:800;">HamiHubX.ir</span></strong>
                </div>
                <p>فهرست کسب‌وکارهای استان گیلان؛ معرفی کافه‌ها، رستوران‌ها، مراکز خدماتی و اقامتی بر اساس اطلاعات گردآوری‌شده و بازبینی تحریریه.</p>
            </div>
            <nav class="hx-footer-col" aria-label="دسترسی سریع">
                <h3>دسترسی سریع</h3>
                <a href="/">صفحه اصلی</a>
                <a href="/city/rasht/">بهترین‌های رشت</a>
                <a href="/city/bandar-anzali/">بهترین‌های بندر انزلی</a>
                <a href="/city/lahijan/">بهترین‌های لاهیجان</a>
                <a href="/advertise">درخواست تبلیغات</a>
            </nav>
            <div class="hx-footer-col">
                <h3>داده و استناد</h3>
                <p>بخشی از داده‌ها از <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">مشارکت‌کنندگان OpenStreetMap</a> تحت مجوز ODbL است.</p>
                <p>امتیازها از منبع اعلام‌شده هر کسب‌وکار ثبت می‌شود و میانگین منابع مختلف با هم جمع نمی‌شود.</p>
            </div>
        </div>
        <?php if ($advertisingReady): ?>
        <div class="hx-container" id="advertise">
            <button type="button" class="hx-ad-cta-card" data-hx-ad-modal>
                <span class="hx-ad-cta-top">
                    <span class="hx-ad-cta-badge"><?= self::icon('announce', 'hx-icon hx-icon-sm') ?><span>جایگاه ویژه · بنر · معرفی اختصاصی</span></span>
                    <span class="hx-ad-cta-open">باز کردن فرم <?= self::icon('arrow-left', 'hx-icon hx-icon-sm') ?></span>
                </span>
                <strong class="hx-ad-cta-title">کسب‌وکار خود را در برترین‌های گیلان دیده کنید</strong>
                <span class="hx-ad-cta-hint">فرم درخواست در یک پنجرهٔ سریع باز می‌شود؛ نام، شماره تماس و پیام شما فقط برای پیگیری درخواست در پنل مدیر نگهداری می‌شود.</span>
            </button>
            <noscript><p class="hx-ad-cta-noscript"><a class="hx-btn hx-btn-outline" href="/advertise">رفتن به فرم درخواست تبلیغات</a></p></noscript>
        </div>
        <?php endif; ?>
    </footer>
    <?php if ($advertisingReady): ?>
    <div class="hx-modal" id="hx-ad-modal" hidden>
        <div class="hx-modal-overlay" data-hx-ad-close></div>
        <div class="hx-modal-panel" role="dialog" aria-modal="true" aria-labelledby="hx-ad-modal-title">
            <button type="button" class="hx-modal-close" data-hx-ad-close aria-label="بستن پنجره">&times;</button>
            <h2 id="hx-ad-modal-title">درخواست تبلیغات در حامی‌هاب</h2>
            <p class="hx-modal-sub">نام، شماره تماس و پیام شما فقط برای پیگیری درخواست در پنل مدیر نگهداری می‌شود.</p>
            <form class="hx-ad-form" data-hx-public-form="advertising" action="/api/v1/public/advertising-inquiry" method="post">
                <?= self::adFormInner(null) ?>
            </form>
            <div class="hx-ad-done" data-hx-ad-done hidden>
                <?= self::icon('tick-circle', 'hx-icon') ?>
                <strong>درخواست شما ثبت شد</strong>
                <p>تیم حامی‌هاب برای پیگیری با شما تماس می‌گیرد؛ این پنجره خودکار بسته می‌شود.</p>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <button type="button" class="hx-back-top" data-hx-back-top aria-label="بازگشت به بالای صفحه" hidden>
        <?= self::icon('arrow-up', 'hx-icon') ?>
        <span class="hx-visually-hidden">بازگشت به بالا</span>
    </button>
    <script src="/assets/js/public.js?v=s21.0" defer></script>
</body>
</html>
        <?php
        return (string)ob_get_clean();
    }

    private static function render404(string $message): void
    {
        if (!headers_sent()) {
            header('X-Robots-Tag: noindex, follow');
        }
        $body = '<main class="hx-container" style="padding:3rem 1rem;">'
            . '<div class="hx-card" style="text-align:center;padding:2.75rem 1.5rem;max-width:640px;margin:0 auto;">'
            . '<div style="font-size:2.6rem;font-weight:800;line-height:1;background:linear-gradient(135deg,#09132e 0%,#0047cc 100%);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:.75rem;" dir="ltr">۴۰۴</div>'
            . '<h1 style="margin:0 0 .5rem;">صفحه مورد نظر یافت نشد</h1>'
            . '<p class="hx-card-desc">' . self::e($message) . '</p>'
            . '<div class="hx-card-actions" style="justify-content:center;margin-top:1.25rem;">'
            . '<a class="hx-btn hx-btn-primary" href="/">بازگشت به صفحه اصلی</a>'
            . '<a class="hx-btn hx-btn-outline" href="/city/rasht/">بهترین‌های رشت</a>'
            . '<a class="hx-btn hx-btn-outline" href="/city/lahijan/">بهترین‌های لاهیجان</a>'
            . '</div></div></main>';
        $html = self::renderLayout([
            'title'       => 'صفحه یافت نشد (404) | حامی‌هاب گیلان',
            'description' => $message,
            'noindex'     => true,
            'body'        => $body,
        ]);
        Response::html($html, 404);
    }

    /**
     * پاسخ استاندارد HTTP 410 (Gone) برای کسب‌وکارهای منتشرشده‌ای که به‌صورت دائمی حذف شده‌اند
     * به موتورهای جستجو اعلام می‌کند نشانی را تمیز از ایندکس خارج کنند و کاربر را به دسته مرتبط هدایت می‌کند.
     *
     * @param array<string, mixed> $rule
     */
    private static function render410(array $rule): void
    {
        if (!headers_sent()) {
            header('X-Robots-Tag: noindex, follow');
        }
        $entityTitle = trim((string)($rule['entity_title'] ?? ''));
        $fallbackPath = trim((string)($rule['fallback_path'] ?? '/')) ?: '/';
        $fallbackLabel = trim((string)($rule['fallback_label'] ?? '')) ?: 'مشاهده سایر مجموعه‌ها در حامی‌هاب';
        $heading = $entityTitle !== ''
            ? 'این مجموعه («' . $entityTitle . '») دیگر در حامی‌هاب فعال نیست'
            : 'این صفحه به‌صورت دائمی از فهرست حامی‌هاب حذف شده است';
        $desc = 'اطلاعات این کسب‌وکار از فهرست فعال حامی‌هاب گیلان خارج شده است. می‌توانید مجموعه‌های مشابه و فعال همین دسته را مشاهده کنید.';

        $html = self::renderLayout([
            'title'       => 'مجموعه حذف شده است (410) | حامی‌هاب گیلان',
            'description' => $desc,
            'noindex'     => true,
            'body'        => '<main class="hx-container" style="padding:3rem 1rem;"><div class="hx-card" style="text-align:center;padding:2.5rem;"><h1>' . self::e($heading) . '</h1><p class="hx-card-desc">' . self::e($desc) . '</p><div style="margin-top:1.25rem;display:flex;gap:0.75rem;justify-content:center;flex-wrap:wrap;"><a class="hx-btn hx-btn-primary" href="' . self::e($fallbackPath) . '">' . self::e($fallbackLabel) . '</a><a class="hx-btn hx-btn-outline" href="/">صفحه اصلی حامی‌هاب</a></div></div></main>',
        ]);
        Response::html($html, 410);
    }

    /** Start is inclusive; end is exclusive, matching the cache transition. */
    private static function isActiveSponsored(array $item, string $now): bool
    {
        if (empty($item['is_sponsored'])) {
            return false;
        }
        if (!empty($item['sponsor_start']) && $item['sponsor_start'] > $now) {
            return false;
        }
        if (!empty($item['sponsor_end']) && $item['sponsor_end'] <= $now) {
            return false;
        }
        return true;
    }

    private static function buildItemListElements(array $businesses, string $baseUrl): array
    {
        $elements = [];
        foreach ($businesses as $idx => $b) {
            $elements[] = [
                '@type'    => 'ListItem',
                'position' => $idx + 1,
                'name'     => (string)$b['name'],
                'url'      => $baseUrl . BusinessUrlService::path((string)$b['city_slug'], (string)$b['slug']),
            ];
        }
        return $elements;
    }

    private static function buildBreadcrumbSchema(array $crumbs): array
    {
        $elements = [];
        foreach ($crumbs as $idx => $c) {
            $elements[] = [
                '@type'    => 'ListItem',
                'position' => $idx + 1,
                'name'     => (string)$c['name'],
                'item'     => (string)$c['url'],
            ];
        }
        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }

    private static function extractMarkers(array $businesses): array
    {
        $markers = [];
        foreach ($businesses as $idx => $b) {
            if (!empty($b['lat']) && !empty($b['lng'])) {
                $markers[] = [
                    'rank'     => $idx + 1,
                    'id'       => (int)$b['id'],
                    'name'     => (string)$b['name'],
                    'lat'      => (float)$b['lat'],
                    'lng'      => (float)$b['lng'],
                    'district' => (string)($b['neighborhood_name_fa'] ?: ($b['city_name_fa'] ?? '')),
                    'url'      => BusinessUrlService::path((string)$b['city_slug'], (string)$b['slug']),
                ];
            }
        }
        return $markers;
    }

    /**
     * S17: رندر دکمه‌های مسیریابی با منطق واحد در همهٔ صفحات.
     *   hero    : فقط دکمهٔ اصلی گوگل (با destination_place_id اگر Place ID موجود بود)
     *   sidebar : مجموعهٔ کامل — گوگل، جستجوی نام، نشان، بلد، OpenStreetMap
     *   compact : فقط دکمهٔ اصلی گوگل با مختصات (کارت‌های لیستی و مقاله)
     * همهٔ لینک‌ها با data-hx-track="map" به بیکن آمار S9 وصل هستند.
     */
    private static function mapActionLinks(array $biz, ?string $placeId, string $sourcePage, string $variant): string
    {
        if (empty($biz['lat']) || empty($biz['lng'])) return '';
        $lat = (float)$biz['lat'];
        $lng = (float)$biz['lng'];
        $bid = (int)$biz['id'];
        $attr = static function (string $provider) use ($bid, $sourcePage): string {
            return 'target="_blank" rel="noopener" data-hx-track="map" data-business-id="' . $bid
                . '" data-source-page="' . self::e($sourcePage) . '" data-provider="' . $provider . '"';
        };
        $btn = static function (string $href, string $provider, string $label, string $cls, string $icon, callable $attr): string {
            return '<a class="' . $cls . '" ' . $attr($provider) . ' href="' . self::e($href) . '">'
                . self::icon($icon, 'hx-icon hx-icon-sm') . '<span>' . self::e($label) . '</span></a>';
        };
        $out = [];
        if ($variant === 'compact') {
            $out[] = $btn(MapLinks::googleDirections($lat, $lng, null), 'google', 'مسیریابی', 'hx-btn hx-btn-outline', 'routing', $attr);
            return implode('', $out);
        }
        // دکمهٔ اصلی: با Place ID گوگل اگر موجود بود، وگرنه با مختصات (جستجوی متنی هرگز دکمهٔ اصلی نیست)
        $out[] = $btn(MapLinks::googleDirections($lat, $lng, $placeId), 'google', 'مسیریابی در Google Maps', 'hx-btn hx-btn-secondary', 'routing', $attr);
        if ($variant === 'sidebar') {
            $out[] = $btn(MapLinks::googleSearch((string)$biz['name'], (string)($biz['city_name_fa'] ?? '')), 'google_search', 'جستجوی نام در Google Maps', 'hx-btn hx-btn-outline', 'map', $attr);
            $out[] = $btn(MapLinks::neshan($lat, $lng), 'neshan', 'مسیریابی با نشان', 'hx-btn hx-btn-outline', 'routing', $attr);
            $out[] = $btn(MapLinks::balad($lat, $lng), 'balad', 'مسیریابی با بلد', 'hx-btn hx-btn-outline', 'routing', $attr);
            $out[] = $btn(MapLinks::osm($lat, $lng), 'osm', 'نمایش در OpenStreetMap', 'hx-btn hx-btn-outline', 'map', $attr);
        }
        return implode('', $out);
    }

    private static function baseUrl(): string
    {
        $configured = rtrim((string)Config::get('app.url', ''), '/');
        if ($configured !== '' && !str_contains($configured, 'localhost')) {
            return $configured;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'hamihubx.ir';
        return $scheme . '://' . $host;
    }

    private static function e(mixed $val): string
    {
        return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Convert Latin digits to Persian digits for user-facing numeric labels.
     * Used only for display; stored data and URLs stay Latin.
     */
    private static function faDigits(string $value): string
    {
        return strtr($value, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    /**
     * تبدیل تاریخ میلادی (Y-m-d[ H:i:s]) به نمایش شمسی «۱۴۰۴/۰۸/۱۶» با ارقام فارسی — فقط برای نمایش.
     */
    private static function faJalali(string $gregorian): string
    {
        $datePart = substr($gregorian, 0, 10);
        if (preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $datePart, $m) !== 1) return self::e('');
        [$gy, $gm, $gd] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = ($gm > 2) ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $jm = ($days < 186) ? 1 + intdiv($days, 31) : 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));
        return self::faDigits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
    }
}
