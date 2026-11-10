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
use App\Services\ArticleService;
use App\Services\ArticleText;
use App\Services\BusinessSeoService;
use App\Services\BusinessUrlService;
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

    /** Render the public home page. */
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

        $articles = DB::fetchAll(
            "SELECT a.id, a.slug, a.title, a.meta_description, a.year_label, a.published_at,
                    c.name_fa AS city_name_fa, c.slug AS city_slug,
                    cat.name_fa AS category_name_fa,
                    (SELECT COUNT(*) FROM article_items ai WHERE ai.article_id = a.id) AS items_count
             FROM articles a
             LEFT JOIN cities c ON c.id = a.city_id
             LEFT JOIN categories cat ON cat.id = a.category_id
             WHERE a.status = 'published'
             ORDER BY a.published_at DESC, a.id DESC
             LIMIT 8"
        );

        $businesses = self::fetchPublishedBusinesses("b.status = 'published'", [], 12);

        $totalPublished = 0;
        foreach ($cities as $c) { $totalPublished += (int)$c['biz_count']; }
        $publishedArticlesTotal = (int)(DB::fetchOne("SELECT COUNT(*) AS n FROM articles WHERE status = 'published'", [])['n'] ?? 0);

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

        $belowHeroAd = AdvertisingService::placement('home_below_hero', null, null);
        $midAd = AdvertisingService::placement('home_mid', null, null, null, false, 'hx-ad-wrap-inline');

        ob_start();
        ?>
        <section class="hx-hero">
            <div class="hx-container">
                <div class="hx-hero-glass-panel">
                    <span class="hx-hero-badge">
                        <?= self::icon('verify', 'hx-icon hx-icon-sm') ?>
                        <span>حامی‌هاب گیلان · فهرست کسب‌وکارهای محلی استان گیلان</span>
                    </span>
                    <h1>بهترین کافه‌ها، رستوران‌ها و خدمات استان گیلان</h1>
                    <p>فهرست کسب‌وکارها بر اساس اطلاعات گردآوری‌شده و بازبینی تحریریه ارائه می‌شود.</p>
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

        <?= $belowHeroAd['html'] ?>

        <main class="hx-container">
            <?php if (!empty($articles)): ?>
            <section class="hx-section">
                <div class="hx-section-head">
                    <div>
                        <h2 class="hx-section-title">
                            <?= self::icon('award') ?>
                            <span>راهنماها و مقالات لیستی منتخب (Top Lists)</span>
                        </h2>
                        <p class="hx-section-sub">راهنماها و مقالات منتشرشده توسط تحریریه</p>
                    </div>
                </div>
                <div class="hx-grid hx-grid-2">
                    <?php foreach ($articles as $art): ?>
                        <article class="hx-card">
                            <div class="hx-card-top">
                                <span class="hx-badge hx-badge-tier-a"><?= self::icon('verify', 'hx-icon hx-icon-sm') ?> <?= !empty($art['city_name_fa']) ? self::e($art['city_name_fa']) : 'مقاله تحریریه' ?><?= (int)$art['items_count'] > 0 ? ' · ' . (int)$art['items_count'] . ' مورد' : '' ?></span>
                                <?php if (!empty($art['category_name_fa'])): ?>
                                    <span class="hx-badge hx-badge-cat"><?= self::e($art['category_name_fa']) ?> · <?= self::e($art['year_label'] ?? '') ?></span>
                                <?php endif; ?>
                            </div>
                            <h3 class="hx-card-title">
                                <a href="/<?= self::e($art['slug']) ?>/"><?= self::e($art['title']) ?></a>
                            </h3>
                            <?php if (!empty($art['meta_description'])): ?>
                                <p class="hx-card-desc"><?= self::e($art['meta_description']) ?></p>
                            <?php endif; ?>
                            <div class="hx-card-actions">
                                <a class="hx-btn hx-btn-primary" href="/<?= self::e($art['slug']) ?>/">خواندن مقاله ←</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <?= $midAd['html'] ?>

            <section class="hx-section">
                <div class="hx-section-head">
                    <div>
                        <h2 class="hx-section-title">
                            <?= self::icon('star') ?>
                            <span>برترین کسب‌وکارهای منتشرشده در گیلان</span>
                        </h2>
                        <p class="hx-section-sub">فهرست کسب‌وکارهای منتشرشده</p>
                    </div>
                </div>
                <?php if (empty($businesses)): ?>
                    <div class="hx-card">
                        <p class="hx-card-desc">به‌زودی فهرست کسب‌وکارهای منتخب استان گیلان در این بخش نمایش داده می‌شود.</p>
                    </div>
                <?php else: ?>
                    <div class="hx-grid hx-grid-3">
                        <?php foreach ($businesses as $idx => $biz): ?>
                            <?= self::renderBusinessCard($biz, $idx + 1, 'home') ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="hx-section">
                <div class="hx-section-head">
                    <div>
                        <h2 class="hx-section-title">
                            <?= self::icon('map') ?>
                            <span>دسته‌بندی‌های تخصصی در شهرهای گیلان</span>
                        </h2>
                        <p class="hx-section-sub">انتخاب سریع بر اساس شهر و دسته‌بندی خدماتی</p>
                    </div>
                </div>
                <div class="hx-grid hx-grid-3">
                    <?php foreach ($cities as $city): ?>
                        <div class="hx-card">
                            <h3 class="hx-card-title">
                                <a href="/city/<?= self::e($city['slug']) ?>/">بهترین‌های <?= self::e($city['name_fa']) ?></a>
                            </h3>
                            <p class="hx-card-desc">دسترسی مستقیم به فهرست طبقه‌بندی‌شده کسب‌وکارهای تاییدشده در <?= self::e($city['name_fa']) ?>:</p>
                            <div style="display:flex;flex-wrap:wrap;gap:0.4rem;">
                                <?php foreach ($categories as $cat): ?>
                                    <a class="hx-btn hx-btn-outline" style="font-size:0.76rem;padding:0.3rem 0.6rem;" href="/city/<?= self::e($city['slug']) ?>/<?= self::e($cat['slug']) ?>/">
                                        <?= self::e($cat['name_fa']) ?> در <?= self::e($city['name_fa']) ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
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
            'extra_ad_until' => AdvertisingService::earliestBoundary((int)$belowHeroAd['until'], (int)$midAd['until']),
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

    /** GET /{city}/{slug} — only the actual city and published business resolve. */
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

        // اسکیمای LocalBusiness استاندارد و صادقانه بدون هیچ‌گونه aggregateRating ساختگی
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

        $breadcrumbSchema = self::buildBreadcrumbSchema([
            ['name' => 'حامی‌هاب گیلان', 'url' => $baseUrl . '/'],
            ['name' => (string)$biz['city_name_fa'], 'url' => $baseUrl . '/city/' . $biz['city_slug'] . '/'],
            ['name' => (string)$biz['category_name_fa'], 'url' => $baseUrl . '/city/' . $biz['city_slug'] . '/' . $biz['category_slug'] . '/'],
            ['name' => (string)$biz['name'], 'url' => $canonical],
        ]);

        $mapMarkers = self::extractMarkers([$biz]);
        $bullets = !empty($biz['bullets_json']) ? (json_decode((string)$biz['bullets_json'], true) ?: []) : [];
        $bizFaqs = !empty($biz['faqs_json']) ? (json_decode((string)$biz['faqs_json'], true) ?: []) : [];

        ob_start();
        ?>
        <main class="hx-container" data-hx-page-view data-business-id="<?= (int)$biz['id'] ?>">
            <nav class="hx-breadcrumb" aria-label="مسیر صفحه">
                <a href="/">حامی‌هاب گیلان</a>
                <span>/</span>
                <a href="/city/<?= self::e($biz['city_slug']) ?>/"><?= self::e($biz['city_name_fa']) ?></a>
                <span>/</span>
                <a href="/city/<?= self::e($biz['city_slug']) ?>/<?= self::e($biz['category_slug']) ?>/"><?= self::e($biz['category_name_fa']) ?></a>
                <span>/</span>
                <strong><?= self::e($biz['name']) ?></strong>
            </nav>

            <article class="hx-detail-hero">
                <div class="hx-card-top">
                    <div style="display:flex;gap:0.45rem;flex-wrap:wrap;align-items:center;">
                        <span class="hx-badge hx-badge-cat"><?= self::e($biz['category_name_fa']) ?> · <?= self::e($biz['city_name_fa']) ?></span>
                        <?php if (!empty($biz['promoted'])): ?><span class="hx-badge hx-badge-tier-a">جایگاه ویژه</span><?php endif; ?>
                    </div>
                </div>

                <h1 style="font-size:1.65rem;font-weight:900;margin:0.5rem 0 0.75rem;color:var(--hx-lapis-900);">
                    <?= self::e($biz['name']) ?>
                </h1>

                <?php if (!empty($biz['short_desc'])): ?>
                    <p style="font-size:1rem;color:var(--hx-slate-700);margin:0 0 1rem;line-height:1.85;">
                        <?= self::e($biz['short_desc']) ?>
                    </p>
                <?php endif; ?>

                <p style="font-weight:700;color:var(--hx-slate-700);margin:0 0 1rem;"><?= self::e(self::ratingLabel($biz)) ?></p>
                <div class="hx-detail-grid">
                    <div class="hx-info-box">
                        <strong><?= self::icon('location', 'hx-icon hx-icon-sm') ?> شهر و محله</strong>
                        <span><?= self::e($biz['city_name_fa']) ?><?= !empty($biz['neighborhood_name_fa']) ? '، ' . self::e($biz['neighborhood_name_fa']) : '' ?></span>
                    </div>
                    <div class="hx-info-box">
                        <strong><?= self::icon('map', 'hx-icon hx-icon-sm') ?> نشانی دقیق</strong>
                        <span><?= self::e($biz['address'] ?: 'نشانی ثبت نشده است') ?></span>
                    </div>
                    <?php if (!empty($biz['phone'])): ?>
                    <div class="hx-info-box">
                        <strong><?= self::icon('call', 'hx-icon hx-icon-sm') ?> شماره تماس ثبت‌شده</strong>
                        <span dir="ltr"><?= self::e($biz['phone']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($biz['hours'])): ?>
                    <div class="hx-info-box">
                        <strong><?= self::icon('clock', 'hx-icon hx-icon-sm') ?> ساعات فعالیت</strong>
                        <span><?= self::e($biz['hours']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="hx-card-actions" style="margin-top:1.25rem;">
                    <?php if (!empty($biz['phone'])): ?>
                        <a class="hx-btn hx-btn-primary" href="tel:<?= self::e($biz['phone']) ?>" data-hx-track="call" data-business-id="<?= (int)$biz['id'] ?>" data-source-page="business_detail">
                            <?= self::icon('call', 'hx-icon hx-icon-sm') ?>
                            <span>تماس مستقیم با <?= self::e($biz['name']) ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($biz['lat']) && !empty($biz['lng'])): ?>
                        <a class="hx-btn hx-btn-secondary" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&destination=<?= (float)$biz['lat'] ?>,<?= (float)$biz['lng'] ?>" data-hx-track="map" data-business-id="<?= (int)$biz['id'] ?>" data-source-page="business_detail">
                            <?= self::icon('routing', 'hx-icon hx-icon-sm') ?>
                            <span>مسیریابی در Google Maps</span>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($biz['website']) && preg_match('~^https?://~i', (string)$biz['website'])): ?>
                        <a class="hx-btn hx-btn-outline" target="_blank" rel="noopener nofollow" href="<?= self::e($biz['website']) ?>" data-hx-track="website" data-business-id="<?= (int)$biz['id'] ?>" data-source-page="business_detail">
                            <?= self::icon('global', 'hx-icon hx-icon-sm') ?>
                            <span>وب‌سایت</span>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($biz['instagram'])): ?>
                        <a class="hx-btn hx-btn-outline" target="_blank" rel="noopener nofollow" href="https://instagram.com/<?= self::e(ltrim((string)$biz['instagram'], '@')) ?>" data-hx-track="instagram" data-business-id="<?= (int)$biz['id'] ?>" data-source-page="business_detail">
                            <?= self::icon('instagram', 'hx-icon hx-icon-sm') ?>
                            <span>اینستاگرام (@<?= self::e(ltrim((string)$biz['instagram'], '@')) ?>)</span>
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!empty($bullets)): ?>
                    <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--hx-slate-200);">
                        <h2 style="font-size:1.05rem;font-weight:800;margin:0 0 0.65rem;">نکات برجسته و ویژگی‌ها</h2>
                        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
                            <?php foreach ($bullets as $bItem): ?>
                                <span class="hx-badge hx-badge-cat"><?= self::icon('tick-circle', 'hx-icon hx-icon-sm') ?> <?= self::e((string)$bItem) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($mediaItems)): ?>
                    <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--hx-slate-200);">
                        <h2 style="font-size:1.05rem;font-weight:800;margin:0 0 0.65rem;">تصاویر WebP بهینه‌شده</h2>
                        <div class="hx-grid hx-grid-3">
                            <?php foreach ($mediaItems as $m): ?>
                                <figure style="margin:0;border:1px solid var(--hx-slate-200);border-radius:10px;overflow:hidden;background:#f8fafc;">
                                    <img src="<?= self::e((string)($m['file_path_800'] ?: $m['file_path_480'])) ?>" alt="<?= self::e((string)($m['alt_text'] ?: $biz['name'])) ?>" loading="lazy" width="<?= (int)($m['width'] ?: 800) ?>" height="<?= (int)($m['height'] ?: 600) ?>" style="width:100%;height:190px;object-fit:cover;display:block;">
                                </figure>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($bizFaqs)): ?>
                    <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--hx-slate-200);">
                        <h2 style="font-size:1.05rem;font-weight:800;margin:0 0 0.75rem;display:flex;align-items:center;gap:0.4rem;">
                            <?= self::icon('message-question', 'hx-icon hx-icon-sm') ?>
                            <span>سؤالات متداول درباره <?= self::e($biz['name']) ?></span>
                        </h2>
                        <?php foreach ($bizFaqs as $bf):
                            if (!is_array($bf) || empty($bf['question']) || empty($bf['answer'])) continue;
                        ?>
                            <div class="hx-faq-item">
                                <p class="hx-faq-q"><?= self::icon('message-question', 'hx-icon hx-icon-sm') ?> <span><?= self::e((string)$bf['question']) ?></span></p>
                                <p class="hx-faq-a"><?= self::e((string)$bf['answer']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- بخش تعاملی مرحله S6: ادعای مالکیت کسب‌وکار (Claim) و گزارش خطای اطلاعات (Report) با استایل گلس‌مورفیسم -->
                <div class="hx-s6-feedback-bar" style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px dashed var(--hx-slate-200);">
                    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:0.85rem;background:linear-gradient(135deg,rgba(240,247,255,0.88),rgba(255,255,255,0.92));backdrop-filter:blur(12px);border:1px solid rgba(13,110,253,0.18);border-radius:14px;padding:1rem 1.15rem;">
                        <div>
                            <strong style="display:flex;align-items:center;gap:0.4rem;font-size:0.94rem;color:var(--hx-lapis-900);">
                                <?= self::icon('verify', 'hx-icon hx-icon-sm') ?>
                                <span>مالک «<?= self::e($biz['name']) ?>» هستید یا مغایرتی در اطلاعات مشاهده می‌کنید؟</span>
                            </strong>
                            <p style="margin:0.25rem 0 0;font-size:0.82rem;color:var(--hx-slate-600);">
                                درخواست شما مستقیماً در صندوق بازبینی تحریریه حامی‌هاب ثبت شده و پس از بررسی اعمال می‌گردد.
                            </p>
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:0.55rem;">
                            <button type="button" class="hx-btn hx-btn-primary" data-hx-toggle-form="claim-form-<?= (int)$biz['id'] ?>">
                                <?= self::icon('verify', 'hx-icon hx-icon-sm') ?>
                                <span>ثبت ادعای مالکیت کسب‌وکار</span>
                            </button>
                            <button type="button" class="hx-btn hx-btn-outline" data-hx-toggle-form="report-form-<?= (int)$biz['id'] ?>">
                                <?= self::icon('info-circle', 'hx-icon hx-icon-sm') ?>
                                <span>گزارش خطای اطلاعات / ساعت کاری</span>
                            </button>
                        </div>
                    </div>

                    <!-- فرم ادعای مالکیت (Claim) -->
                    <form id="claim-form-<?= (int)$biz['id'] ?>" data-hx-public-form="claim" style="display:none;margin-top:0.85rem;background:rgba(255,255,255,0.95);backdrop-filter:blur(14px);border:1px solid rgba(13,110,253,0.25);border-radius:14px;padding:1.15rem;box-shadow:0 10px 26px -10px rgba(4,8,20,0.08);">
                        <input type="hidden" name="business_id" value="<?= (int)$biz['id'] ?>">
                        <input type="text" name="website_hp" value="" tabindex="-1" autocomplete="off" style="display:none!important;">
                        <h3 style="margin:0 0 0.5rem;font-size:0.98rem;font-weight:800;color:var(--hx-lapis-900);display:flex;align-items:center;gap:0.4rem;">
                            <?= self::icon('verify', 'hx-icon hx-icon-sm') ?>
                            <span>فرم رسمی ادعای مالکیت «<?= self::e($biz['name']) ?>»</span>
                        </h3>
                        <p style="margin:0 0 0.85rem;font-size:0.82rem;color:var(--hx-slate-600);">
                            لطفاً نام و شماره تماس مستقیم خود را وارد کنید تا کارشناسان حامی‌هاب گیلان پس از احراز هویت، نشان تایید مالکیت را فعال نمایند.
                        </p>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:0.75rem;">
                            <div>
                                <label style="display:block;font-size:0.78rem;font-weight:700;margin-bottom:0.3rem;color:var(--hx-slate-700);">نام و نام خانوادگی مالک / مدیر *</label>
                                <input type="text" name="name" required maxlength="150" placeholder="مثلاً: علی گیلانی" style="width:100%;padding:0.55rem 0.75rem;border:1px solid var(--hx-slate-300);border-radius:9px;font-family:inherit;font-size:0.85rem;">
                            </div>
                            <div>
                                <label style="display:block;font-size:0.78rem;font-weight:700;margin-bottom:0.3rem;color:var(--hx-slate-700);">شماره موبایل یا تلفن ثابت جهت احراز *</label>
                                <input type="tel" name="phone" required maxlength="32" dir="ltr" placeholder="0911... / 013..." style="width:100%;padding:0.55rem 0.75rem;border:1px solid var(--hx-slate-300);border-radius:9px;font-family:inherit;font-size:0.85rem;">
                            </div>
                        </div>
                        <div style="margin-top:0.75rem;">
                            <label style="display:block;font-size:0.78rem;font-weight:700;margin-bottom:0.3rem;color:var(--hx-slate-700);">توضیحات تکمیلی (سمت، ساعت تماس یا اصلاحات مورد نیاز)</label>
                            <textarea name="note" rows="2" maxlength="800" placeholder="توضیحات یا شماره جدید مجموعه..." style="width:100%;padding:0.55rem 0.75rem;border:1px solid var(--hx-slate-300);border-radius:9px;font-family:inherit;font-size:0.85rem;"></textarea>
                        </div>
                        <div style="margin-top:0.75rem;display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;">
                            <button type="submit" class="hx-btn hx-btn-primary">ارسال درخواست مالکیت به صندوق بازبینی</button>
                            <span data-hx-form-msg style="font-size:0.82rem;font-weight:700;"></span>
                        </div>
                    </form>

                    <!-- فرم گزارش خطای کاربران (Report) -->
                    <form id="report-form-<?= (int)$biz['id'] ?>" data-hx-public-form="report" style="display:none;margin-top:0.85rem;background:rgba(255,255,255,0.95);backdrop-filter:blur(14px);border:1px solid rgba(13,110,253,0.25);border-radius:14px;padding:1.15rem;box-shadow:0 10px 26px -10px rgba(4,8,20,0.08);">
                        <input type="hidden" name="business_id" value="<?= (int)$biz['id'] ?>">
                        <input type="text" name="website_hp" value="" tabindex="-1" autocomplete="off" style="display:none!important;">
                        <h3 style="margin:0 0 0.5rem;font-size:0.98rem;font-weight:800;color:var(--hx-lapis-900);display:flex;align-items:center;gap:0.4rem;">
                            <?= self::icon('info-circle', 'hx-icon hx-icon-sm') ?>
                            <span>گزارش مغایرت اطلاعات «<?= self::e($biz['name']) ?>»</span>
                        </h3>
                        <p style="margin:0 0 0.75rem;font-size:0.82rem;color:var(--hx-slate-600);">
                            چنانچه شماره تلفن، آدرس، ساعت کاری تغییر کرده یا این واحد تعطیل شده است، مورد را بنویسید:
                        </p>
                        <div>
                            <textarea name="message" required rows="3" maxlength="1000" placeholder="مثلاً: شماره تلفن مجموعه تغییر کرده یا آدرس به خیابان گلسار منتقل شده است..." style="width:100%;padding:0.6rem 0.75rem;border:1px solid var(--hx-slate-300);border-radius:9px;font-family:inherit;font-size:0.85rem;"></textarea>
                        </div>
                        <div style="margin-top:0.75rem;display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;">
                            <button type="submit" class="hx-btn hx-btn-primary">ثبت گزارش خطا در صندوق بازبینی</button>
                            <span data-hx-form-msg style="font-size:0.82rem;font-weight:700;"></span>
                        </div>
                    </form>
                </div>
            </article>

            <?php if (!empty($mapMarkers)): ?>
                <?= self::renderLazyMapBox($mapMarkers, 'موقعیت مکانی ' . $biz['name'] . ' روی نقشه تعاملی') ?>
            <?php endif; ?>

            <?php if (!empty($related)): ?>
                <section class="hx-section">
                    <div class="hx-section-head">
                        <div>
                            <h2 class="hx-section-title">سایر گزینه‌های برتر <?= self::e($biz['category_name_fa']) ?> در <?= self::e($biz['city_name_fa']) ?></h2>
                        </div>
                    </div>
                    <div class="hx-grid hx-grid-3">
                        <?php foreach ($related as $idx => $relBiz): ?>
                            <?= self::renderBusinessCard($relBiz, $idx + 1, 'business_related') ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </main>
        <?php
        $bodyHtml = (string)ob_get_clean();

        $title = $biz['name'] . ' در ' . $biz['city_name_fa'] . ' | آدرس و تلفن | حامی‌هاب گیلان';
        $desc = !empty($biz['short_desc'])
            ? (string)$biz['short_desc']
            : ('اطلاعات کامل، آدرس، شماره تماس و مسیریابی ' . $biz['name'] . ' در ' . $biz['city_name_fa'] . ' بر اساس رتبه‌بندی حامی‌هاب گیلان.');

        $fullHtml = self::renderLayout([
            'title'       => $title,
            'description' => $desc,
            'canonical'   => $canonical,
            'og_type'     => 'place',
            'schemas'     => [$localBizSchema, $breadcrumbSchema],
            'ad_placement'=> 'business',
            'ad_city_id'  => (int)$biz['city_id'],
            'ad_category_id' => (int)$biz['category_id'],
            'body'        => $bodyHtml,
        ]);

        Cache::putPage(BusinessUrlService::path((string)$biz['city_slug'], (string)$biz['slug']), $fullHtml);
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
        <main class="hx-container">
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
        <main class="hx-container hx-catalog">
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
                                        <a class="hx-btn hx-btn-secondary" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&destination=<?= (float)$it['lat'] ?>,<?= (float)$it['lng'] ?>" data-hx-track="map" data-business-id="<?= (int)$it['business_id'] ?>" data-source-page="article_card">
                                            <?= self::icon('routing', 'hx-icon hx-icon-sm') ?>
                                            <span>مسیریابی</span>
                                        </a>
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
     * POST /api/v1/events/track — ثبت سبک کلیک‌های تماس، نقشه، اینستاگرام و وب‌سایت در جدول events_daily
     */
    public static function trackEvent(Request $req, array $params = []): void
    {
        $bizId = max(0, (int)$req->input('business_id', 0));
        $artId = max(0, (int)$req->input('article_id', 0));
        $type = (string)$req->input('event_type', '');
        if (!in_array($type, ['view','call','map','instagram','whatsapp','website'], true) || (!$bizId && !$artId)) {
            Response::json(['ok'=>false,'error'=>'رویداد نامعتبر است.'], 422);
        }
        try {
            if ($bizId && !DB::fetchOne("SELECT id FROM businesses WHERE id=:id AND status='published'", ['id'=>$bizId])) Response::json(['ok'=>false,'error'=>'مورد یافت نشد.'], 404);
            if ($artId && !DB::fetchOne("SELECT id FROM articles WHERE id=:id AND status='published'", ['id'=>$artId])) Response::json(['ok'=>false,'error'=>'مورد یافت نشد.'], 404);
            DB::query("INSERT INTO events_daily (day,business_id,article_id,type,cnt) VALUES (CURDATE(),:bid,:aid,:type,1) ON DUPLICATE KEY UPDATE cnt=cnt+1", ['bid'=>$bizId,'aid'=>$artId,'type'=>$type]);
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
        <article class="hx-card hx-business-card">
            <a class="hx-place-media" href="<?= self::e($slugUrl) ?>" tabindex="-1" aria-hidden="true">
                <?php if ($hasCover): ?>
                    <img src="<?= self::e($coverPath) ?>" alt="" loading="lazy" decoding="async" width="480" height="300">
                <?php else: ?>
                    <span class="hx-place-no-image"><?= self::icon('location', 'hx-icon hx-icon-lg') ?><span>تصویر ثبت نشده</span></span>
                <?php endif; ?>
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
                        <a class="hx-btn hx-btn-outline" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&amp;destination=<?= (float)$biz['lat'] ?>,<?= (float)$biz['lng'] ?>" data-hx-track="map" data-business-id="<?= (int)$biz['id'] ?>" data-source-page="<?= self::e($sourcePage) ?>"><?= self::icon('routing', 'hx-icon hx-icon-sm') ?> مسیریابی</a>
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
    <link rel="stylesheet" href="/assets/css/public.css?v=s11.0">
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
                <a href="#advertise">درخواست تبلیغات</a>
            </nav>
            <div class="hx-footer-col">
                <h3>داده و استناد</h3>
                <p>بخشی از داده‌ها از <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">مشارکت‌کنندگان OpenStreetMap</a> تحت مجوز ODbL است.</p>
                <p>امتیازها از منبع اعلام‌شده هر کسب‌وکار ثبت می‌شود و میانگین منابع مختلف با هم جمع نمی‌شود.</p>
            </div>
        </div>
        <?php if ($advertisingReady): ?>
        <div class="hx-container">
        <details class="hx-ad-contact" id="advertise">
            <summary>درخواست تبلیغات در حامی‌هاب</summary>
            <p>برای بررسی جایگاه و بنر، درخواست خود را بفرستید. نام، شماره تماس و پیام فقط برای پیگیری درخواست در پنل مدیر نگهداری می‌شود.</p>
            <form data-hx-public-form="advertising" action="/api/v1/public/advertising-inquiry" method="post">
                <label>نام <input name="name" required maxlength="150" autocomplete="name"></label>
                <label>شماره تماس <input name="phone" type="tel" required maxlength="32" autocomplete="tel" dir="ltr"></label>
                <label>پیام (اختیاری) <textarea name="message" maxlength="1000" rows="3"></textarea></label>
                <label class="hx-ad-consent"><input type="checkbox" name="consent" required> با ثبت اطلاعات بالا برای پیگیری درخواست تبلیغات موافقم.</label>
                <div class="hx-ad-honeypot" aria-hidden="true"><label>Website <input name="website_hp" tabindex="-1" autocomplete="off"></label></div>
                <button type="submit" class="hx-btn">ارسال درخواست تبلیغات</button>
                <p data-hx-form-msg role="status" aria-live="polite"></p>
                <noscript>برای ارسال امن فرم، جاوااسکریپت را فعال کنید.</noscript>
            </form>
        </details>
        </div>
        <?php endif; ?>
    </footer>
    <button type="button" class="hx-back-top" data-hx-back-top aria-label="بازگشت به بالای صفحه" hidden>
        <?= self::icon('arrow-up', 'hx-icon') ?>
        <span class="hx-visually-hidden">بازگشت به بالا</span>
    </button>
    <script src="/assets/js/public.js?v=s12.0" defer></script>
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
}
