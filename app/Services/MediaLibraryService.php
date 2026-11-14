<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * سرویس اختصاصی و مستقل مدیریت کتابخانه رسانه و فایل‌های فیزیکی WebP روی هاست DirectAdmin.
 *
 * طراحی‌شده به‌صورت کلاس مستقل (OPcache-fresh) و مقاوم در برابر محدودیت‌های open_basedir،
 * پوشه‌های غیرقابل‌خواندن و تفاوت نسخه‌های مایگریشن دیتابیس روی هاست اشتراکی.
 */
final class MediaLibraryService
{
    private const MAX_SCAN_FILES = 25000;

    /**
     * راهنمای استاندارد ابعاد پیشنهادی برای تمام بخش‌های آپلود تصویر در پنل مدیریت
     *
     * @return array<string, array<string, string>>
     */
    public static function dimensionGuides(): array
    {
        return [
            'business_cover' => [
                'slot' => 'business_cover',
                'title' => 'تصویر شاخص (کاور) کسب‌وکار',
                'recommended_px' => '1200 × 800',
                'aspect_ratio' => '3:2 افقی (یا 16:9 معادل 1200×675)',
                'min_px' => '800 × 530',
                'fit_mode' => 'برش هوشمند از مرکز در کارت‌ها (object-fit: cover)',
                'note' => 'سوژه اصلی یا تابلو را در مرکز کادر قرار دهید. هر فرمتی آپلود شود، به‌صورت خودکار و ۱۰۰٪ به ۳ نسخه WebP کم‌حجم (480، 800 و 1200 پیکسل) تبدیل می‌شود و هیچ فایل JPEG یا PNG روی هاست ذخیره نخواهد شد.',
            ],
            'article_cover' => [
                'slot' => 'article_cover',
                'title' => 'تصویر شاخص مقاله و پیش‌نمایش شبکه‌های اجتماعی',
                'recommended_px' => '1200 × 630',
                'aspect_ratio' => '1.91:1 یا 16:9 افقی (1200×675)',
                'min_px' => '960 × 500',
                'fit_mode' => 'پوشش کامل هدر مقاله و کارت OpenGraph (object-fit: cover)',
                'note' => 'ابعاد 1200×630 بهترین نمایش را هم در بالای مقاله و هم هنگام اشتراک‌گذاری لینک در شبکه‌های اجتماعی ایجاد می‌کند.',
            ],
            'advertising_banner' => [
                'slot' => 'advertising_banner',
                'title' => 'بنر تبلیغاتی جایگاه‌های عمومی سایت',
                'recommended_px' => '1200 × 250 (تکی) یا 800 × 250 (دوتایی کنار هم)',
                'aspect_ratio' => 'افقی 5:1 تا 3:1 (تکی تمام‌عرض یا جفت بنر دسکتاپ)',
                'min_px' => '600 × 200',
                'fit_mode' => 'نمایش کامل بدون برش (object-fit: contain)',
                'note' => 'در هر جایگاه حداکثر ۲ بنر فعال کنار هم در دسکتاپ نمایش داده می‌شود. بنرها برش نمی‌خورند؛ JPG/PNG/WebP به WebP بهینه تبدیل و GIFهای متحرک با حفظ کامل انیمیشن ذخیره می‌شوند (پیشنهاد: حداکثر ۸ مگابایت).',
            ],
        ];
    }

    /**
     * فهرست کامل کتابخانه رسانه با اطلاعات مسیر دقیق روی هاست، حجم دیسک و محل‌های استفاده در دیتابیس
     *
     * نسخهٔ S9: شمارش مصرف‌ها با یک کوئری گروهی GROUP BY انجام می‌شود و جزئیات استفادهٔ هر تصویر
     * فقط برای همان صفحهٔ فعلی از دیتابیس خوانده می‌شود؛ دیگر کل جدول businesses، business_photos،
     * article_revisions (با payload_json حجیم) و advertising_banners در حافظه بارگذاری نمی‌شود.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function listLibrary(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(12, (int) ($filters['per_page'] ?? 24)));
        $usageFilter = trim((string) ($filters['usage'] ?? 'all'));
        $search = trim((string) ($filters['q'] ?? ''));

        if (!self::tableExists('media')) {
            throw new RuntimeException('جدول media هنوز در دیتابیس ایجاد نشده است.');
        }

        $rows = DB::fetchAll(
            'SELECT id, file_path_480, file_path_800, file_path_1200, mime_type, width, height, size_bytes, alt_text, created_at
             FROM media
             ORDER BY id DESC'
        );

        // S9: شمارش مصرف با GROUP BY (یک ردیف به‌ازای هر رسانهٔ استفاده‌شده) — سبک در حافظه.
        $usageCounts = self::usageCountsByMediaId();
        // S9: برای جستجوی متنی روی «عناوین محل استفاده»، تطبیق در SQL انجام می‌شود نه با بارگذاری همهٔ جدول‌ها.
        $titleMatchedIds = $search !== '' ? self::usageTitleMatchIds($search) : [];

        $matchedIds = [];
        $usedCount = 0;
        $unusedCount = 0;
        $missingFilesCount = 0;
        $nonWebpDbCount = 0;
        $knownRelativePaths = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $variants = self::inspectMediaVariants($row);
            foreach ($variants as $v) {
                if ($v['web_path'] !== '') {
                    $knownRelativePaths[$v['web_path']] = true;
                }
            }

            $allPresent = true;
            $allWebp = strtolower((string) ($row['mime_type'] ?? '')) === 'image/webp';
            foreach ($variants as $v) {
                if (!$v['exists']) {
                    $allPresent = false;
                }
                if (!$v['is_webp']) {
                    $allWebp = false;
                }
            }

            $usageInfo = $usageCounts[$id] ?? null;
            $usageCount = $usageInfo === null ? 0 : (int) $usageInfo['usage_count'];
            $isUsed = $usageCount > 0;
            if ($isUsed) {
                $usedCount++;
            } else {
                $unusedCount++;
            }
            if (!$allPresent) {
                $missingFilesCount++;
            }
            if (!$allWebp) {
                $nonWebpDbCount++;
            }

            $hasBannerLock = $usageInfo !== null && (int) $usageInfo['banner_count'] > 0;

            if ($usageFilter === 'used' && !$isUsed) {
                continue;
            }
            if ($usageFilter === 'unused' && $isUsed) {
                continue;
            }
            if ($usageFilter === 'missing_files' && $allPresent) {
                continue;
            }
            if ($usageFilter === 'non_webp' && $allWebp) {
                continue;
            }

            if ($search !== '') {
                $haystack = implode(' ', [
                    (string) $id,
                    (string) ($row['alt_text'] ?? ''),
                    (string) ($row['file_path_1200'] ?? ''),
                ]);
                $matched = function_exists('mb_stripos')
                    ? mb_stripos($haystack, $search, 0, 'UTF-8') !== false
                    : stripos($haystack, $search) !== false;
                if (!$matched && !isset($titleMatchedIds[$id])) {
                    continue;
                }
            }

            $matchedIds[] = $id;
        }

        $totalFiltered = count($matchedIds);
        $offset = ($page - 1) * $perPage;
        $sliceIds = array_slice($matchedIds, $offset, $perPage);

        // جزئیات محل‌های استفاده فقط برای همین صفحه (حداکثر ۱۰۰ مورد) خوانده می‌شود.
        $usageDetails = self::collectUsageMap($sliceIds);
        $pagedItems = [];
        if ($sliceIds !== []) {
            $sliceRows = self::rowsByIds($sliceIds);
            foreach ($sliceRows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $usages = $usageDetails[$id] ?? [];
                $variants = self::inspectMediaVariants($row);

                $totalBytes = 0;
                $allPresent = true;
                $allWebp = strtolower((string) ($row['mime_type'] ?? '')) === 'image/webp';
                foreach ($variants as $v) {
                    $totalBytes += $v['bytes'];
                    if (!$v['exists']) {
                        $allPresent = false;
                    }
                    if (!$v['is_webp']) {
                        $allWebp = false;
                    }
                }

                $usageInfo = $usageCounts[$id] ?? null;
                $usageCount = $usageInfo === null ? 0 : (int) $usageInfo['usage_count'];
                $hasBannerLock = false;
                foreach ($usages as $u) {
                    if (($u['type'] ?? '') === 'advertising_banner') {
                        $hasBannerLock = true;
                        break;
                    }
                }

                $pagedItems[] = [
                    'id' => $id,
                    'file_path_1200' => (string) ($row['file_path_1200'] ?? ''),
                    'file_path_800' => (string) ($row['file_path_800'] ?? ''),
                    'file_path_480' => (string) ($row['file_path_480'] ?? ''),
                    'url_1200' => (string) ($row['file_path_1200'] ?? ''),
                    'url_800' => (string) ($row['file_path_800'] ?? ''),
                    'url_480' => (string) ($row['file_path_480'] ?? ''),
                    'host_path_1200' => $variants['1200']['host_path'],
                    'mime_type' => (string) ($row['mime_type'] ?? 'image/webp'),
                    'width' => (int) ($row['width'] ?? 0),
                    'height' => (int) ($row['height'] ?? 0),
                    'alt_text' => (string) ($row['alt_text'] ?? ''),
                    'uploaded_by_name' => '',
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'variants' => $variants,
                    'total_bytes' => $totalBytes,
                    'all_files_present' => $allPresent,
                    'all_webp' => $allWebp,
                    'usages' => $usages,
                    'usage_count' => $usageCount,
                    'can_force_detach' => !$hasBannerLock,
                ];
            }
        }

        $diskStats = self::scanUploadsDirectory($knownRelativePaths);

        return [
            'items' => $pagedItems,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $totalFiltered,
                'total_pages' => max(1, (int) ceil($totalFiltered / $perPage)),
            ],
            'summary' => [
                'total_db_records' => count($rows),
                'used_db_records' => $usedCount,
                'unused_db_records' => $unusedCount,
                'missing_files_records' => $missingFilesCount,
                'non_webp_db_records' => $nonWebpDbCount,
                'uploads_host_root' => 'public_html/uploads',
                'disk_webp_files' => $diskStats['webp_files'],
                'disk_non_webp_files' => $diskStats['non_webp_files'],
                'disk_orphan_files' => $diskStats['orphan_files'],
                'disk_total_bytes' => $diskStats['total_bytes'],
            ],
            'dimension_guides' => self::dimensionGuides(),
        ];
    }

    /**
     * S9: ردیف‌های رسانهٔ یک صفحهٔ مشخص با کوئری IN — جایگزین نگه‌داشتن همهٔ ردیف‌ها در حافظه.
     *
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    private static function rowsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::fetchAll(
            "SELECT id, file_path_480, file_path_800, file_path_1200, mime_type, width, height, size_bytes, alt_text, created_at
             FROM media WHERE id IN ({$placeholders}) ORDER BY id DESC",
            $ids
        );
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $ordered = [];
        foreach (array_reverse($ids) as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }
        return $ordered;
    }

    /**
     * S9: شمارش مصرف هر رسانه با یک کوئری UNION ALL + GROUP BY روی SQL.
     * هیچ ردیف کامل جدولی در PHP بارگذاری نمی‌شود؛ خروجی فقط شناسه → تعداد مصرف و تعداد بنر است.
     *
     * @return array<int, array{usage_count:int, banner_count:int}>
     */
    private static function usageCountsByMediaId(): array
    {
        $union = [
            "SELECT cover_media_id AS media_id, 0 AS is_banner FROM businesses WHERE cover_media_id IS NOT NULL",
            "SELECT bp.media_id AS media_id, 0 AS is_banner FROM business_photos bp",
            "SELECT ae.cover_media_id AS media_id, 0 AS is_banner FROM article_extras ae WHERE ae.cover_media_id IS NOT NULL",
            "SELECT ab.media_id AS media_id, 1 AS is_banner FROM advertising_banners ab WHERE ab.media_id IS NOT NULL",
        ];
        // پیش‌نویس/بازبینی مقاله: cover_media_id داخل payload_json است؛ با توابع JSON و شرط اعتبار JSON.
        $union[] = "SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(ar.payload_json, '$.cover_media_id')) AS UNSIGNED) AS media_id, 0 AS is_banner
                    FROM article_revisions ar
                    INNER JOIN articles a ON a.id = ar.article_id
                    WHERE ar.status IN ('draft','review')
                      AND ar.payload_json LIKE '%cover_media_id%'
                      AND JSON_VALID(ar.payload_json)
                      AND CAST(JSON_UNQUOTE(JSON_EXTRACT(ar.payload_json, '$.cover_media_id')) AS UNSIGNED) > 0
                      AND NOT EXISTS (
                          SELECT 1 FROM article_extras ae2
                          WHERE ae2.article_id = ar.article_id
                            AND ae2.cover_media_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(ar.payload_json, '$.cover_media_id')) AS UNSIGNED)
                      )";
        $sql = "SELECT u.media_id, COUNT(*) AS usage_count, SUM(u.is_banner) AS banner_count
                FROM (" . implode(" UNION ALL ", $union) . ") u
                WHERE u.media_id IS NOT NULL AND u.media_id > 0
                GROUP BY u.media_id";
        $map = [];
        try {
            foreach (DB::fetchAll($sql) as $row) {
                $mid = (int) ($row['media_id'] ?? 0);
                if ($mid > 0) {
                    $map[$mid] = ['usage_count' => (int) $row['usage_count'], 'banner_count' => (int) $row['banner_count']];
                }
            }
        } catch (Throwable) {
            // اگر نسخهٔ دیتابیس توابع JSON را پشتیبانی نکرد، فقط بخش پیش‌نویس‌ها محاسبه نمی‌شود؛ بقیه آمار درست می‌ماند.
        }
        return $map;
    }

    /**
     * S9: شناسه‌های رسانه‌ای که «عنوان» محل استفاده‌شان با جستجوی مدیر مطابقت دارد — محاسبه در SQL.
     *
     * @return array<int, bool>
     */
    private static function usageTitleMatchIds(string $search): array
    {
        $like = '%' . $search . '%';
        $params = [];
        for ($i = 0; $i < 8; $i++) {
            $params[] = $like;
        }
        $sql = "SELECT cover_media_id AS mid FROM businesses
                 WHERE cover_media_id IS NOT NULL AND (name LIKE ? OR CAST(id AS CHAR) LIKE ?)
                UNION
                SELECT bp.media_id AS mid FROM business_photos bp
                  INNER JOIN businesses b ON b.id = bp.business_id
                 WHERE b.name LIKE ? OR CAST(b.id AS CHAR) LIKE ?
                UNION
                SELECT ae.cover_media_id AS mid FROM article_extras ae
                  INNER JOIN articles a ON a.id = ae.article_id
                 WHERE ae.cover_media_id IS NOT NULL AND (a.title LIKE ? OR CAST(a.id AS CHAR) LIKE ?)
                UNION
                SELECT ab.media_id AS mid FROM advertising_banners ab
                 WHERE ab.media_id IS NOT NULL AND (ab.title LIKE ? OR CAST(ab.id AS CHAR) LIKE ?)";
        $ids = [];
        try {
            foreach (DB::fetchAll($sql, $params) as $row) {
                $mid = (int) ($row['mid'] ?? 0);
                if ($mid > 0) {
                    $ids[$mid] = true;
                }
            }
        } catch (Throwable) {
        }
        // جستجو در عنوان پیش‌نویس/بازبینی مقاله (payload_json) فقط اگر جدول موجود است.
        if (self::tableExists('article_revisions') && self::tableExists('articles')) {
            try {
                $rows = DB::fetchAll(
                    "SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(ar.payload_json, '$.cover_media_id')) AS UNSIGNED) AS mid
                     FROM article_revisions ar
                     INNER JOIN articles a ON a.id = ar.article_id
                     WHERE ar.status IN ('draft','review')
                       AND ar.payload_json LIKE '%cover_media_id%'
                       AND JSON_VALID(ar.payload_json)
                       AND (a.title LIKE :q1 OR CAST(ar.article_id AS CHAR) LIKE :q2)",
                    ['q1' => $like, 'q2' => $like]
                );
                foreach ($rows as $row) {
                    $mid = (int) ($row['mid'] ?? 0);
                    if ($mid > 0) {
                        $ids[$mid] = true;
                    }
                }
            } catch (Throwable) {
            }
        }
        return $ids;
    }

    /**
     * ویرایش متن جایگزین سئو (alt_text) برای یک تصویر در جدول media
     */
    public static function updateAltText(int $mediaId, string $altText): bool
    {
        if ($mediaId <= 0) {
            throw new InvalidArgumentException('شناسه رسانه نامعتبر است.');
        }
        $exists = DB::fetchOne('SELECT id FROM media WHERE id = :id LIMIT 1', ['id' => $mediaId]);
        if (!$exists) {
            return false;
        }
        $trimmed = trim($altText);
        $cleanAlt = function_exists('mb_substr') ? mb_substr($trimmed, 0, 220, 'UTF-8') : substr($trimmed, 0, 220);
        DB::query(
            'UPDATE media SET alt_text = :alt WHERE id = :id',
            ['alt' => $cleanAlt, 'id' => $mediaId]
        );
        return true;
    }

    /**
     * حذف رسانه‌ها با گارد مصرف؛ فلگ force برای «حذف کامل اجباری» تصاویر متصل به بنر است:
     * بنرهای متصل غیرفعال و بی‌تصویر می‌شوند و سپس رکورد + فایل‌ها کامل حذف می‌گردند.
     * (S23 رفع باگ: قبلاً این پارامتر در امضا نبود و آرگومان سوم اندپوینت‌ها بی‌صدا نادیده می‌شد.)
     *
     * @param int[] $mediaIds
     * @return array<string, mixed>
     */
    public static function deleteMediaItems(array $mediaIds, bool $detachUsages = false, bool $force = false): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn($v): int => (int) $v, $mediaIds),
            static fn(int $id): bool => $id > 0
        )));
        if (!$ids) {
            throw new InvalidArgumentException('هیچ تصویری برای حذف انتخاب نشده است.');
        }
        if (count($ids) > 200) {
            throw new InvalidArgumentException('در هر نوبت حداکثر ۲۰۰ تصویر قابل حذف است.');
        }

        $placeholders = [];
        $params = [];
        foreach ($ids as $idx => $id) {
            $k = 'm' . $idx;
            $placeholders[] = ':' . $k;
            $params[$k] = $id;
        }

        $rows = DB::fetchAll(
            'SELECT id, file_path_480, file_path_800, file_path_1200 FROM media WHERE id IN (' . implode(',', $placeholders) . ')',
            $params
        );
        if (!$rows) {
            return [
                'deleted_ids' => [],
                'deleted_count' => 0,
                'deleted_files_count' => 0,
                'freed_bytes' => 0,
                'blocked' => [],
            ];
        }

        $foundIds = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        $usageMap = self::collectUsageMap($foundIds);

        $blocked = [];
        $deletableRows = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $usages = $usageMap[$id] ?? [];

            if ($usages) {
                $bannerUsages = array_values(array_filter(
                    $usages,
                    static fn(array $u): bool => ($u['type'] ?? '') === 'advertising_banner'
                ));
                if ($bannerUsages && !$force) {
                    $blocked[] = [
                        'id' => $id,
                        'reason' => 'این تصویر به بنر تبلیغاتی متصل است؛ ابتدا تصویر بنر را تغییر دهید یا بنر را حذف کنید.',
                        'usages' => $usages,
                    ];
                    continue;
                }
                if ($bannerUsages && $force && self::tableExists('advertising_banners')) {
                    // جداسازی اجباری: بنرهای متصل غیرفعال و بی‌تصویر می‌شوند تا تصویر آزاد شود
                    DB::query('UPDATE advertising_banners SET media_id = NULL, active = 0 WHERE media_id = :id', ['id' => $id]);
                }
                if (!$detachUsages && !$force) {
                    $blocked[] = [
                        'id' => $id,
                        'reason' => 'این تصویر در کسب‌وکار یا مقاله استفاده شده است.',
                        'usages' => $usages,
                    ];
                    continue;
                }
            }

            $deletableRows[] = $row;
        }

        if (!$deletableRows) {
            return [
                'deleted_ids' => [],
                'deleted_count' => 0,
                'deleted_files_count' => 0,
                'freed_bytes' => 0,
                'blocked' => $blocked,
            ];
        }

        $pdo = DB::connection();
        $inOuterTx = $pdo->inTransaction();
        if (!$inOuterTx) {
            $pdo->beginTransaction();
        }

        $hasBusinesses = self::tableExists('businesses');
        $hasBusinessPhotos = self::tableExists('business_photos');
        $hasArticleExtras = self::tableExists('article_extras');
        $deletedIds = [];
        $filesToUnlink = [];

        try {
            foreach ($deletableRows as $row) {
                $id = (int) $row['id'];
                if ($detachUsages) {
                    if ($hasBusinesses) {
                        DB::query('UPDATE businesses SET cover_media_id = NULL WHERE cover_media_id = :id', ['id' => $id]);
                    }
                    if ($hasBusinessPhotos) {
                        DB::query('DELETE FROM business_photos WHERE media_id = :id', ['id' => $id]);
                    }
                    if ($hasArticleExtras) {
                        DB::query('UPDATE article_extras SET cover_media_id = NULL WHERE cover_media_id = :id', ['id' => $id]);
                    }
                }

                DB::query('DELETE FROM media WHERE id = :id', ['id' => $id]);
                $deletedIds[] = $id;

                foreach (['file_path_1200', 'file_path_800', 'file_path_480'] as $col) {
                    $webPath = trim((string) ($row[$col] ?? ''));
                    if ($webPath !== '') {
                        $filesToUnlink[$webPath] = true;
                    }
                }
            }

            if (!$inOuterTx) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if (!$inOuterTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $deletedFilesCount = 0;
        $freedBytes = 0;
        foreach (array_keys($filesToUnlink) as $webPath) {
            $res = self::safeUnlinkUploadFile($webPath);
            if ($res['deleted']) {
                $deletedFilesCount++;
                $freedBytes += $res['bytes'];
            }
        }

        return [
            'deleted_ids' => $deletedIds,
            'deleted_count' => count($deletedIds),
            'deleted_files_count' => $deletedFilesCount,
            'freed_bytes' => $freedBytes,
            'blocked' => $blocked,
        ];
    }

    /**
     * قفل اختصاصی کتابخانهٔ رسانه (فایل‌محور با flock) — هماهنگی آپلود و پاک‌سازی orphan
     * تا فایل‌های تازه‌نوشته‌شدهٔ آپلود، پیش از ثبت در دیتابیس، قربانی پاک‌سازی هم‌زمان نشوند.
     *
     * @return resource|null
     */
    public static function acquireLibraryLock(bool $blocking = true, int $timeoutMs = 10000)
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $dir = $base . '/storage/private';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null; // fail-open: نبود مسیر قفل نباید آپلود را از کار بیندازد
        }
        $fp = @fopen($dir . '/media_library.lock', 'c');
        if (!$fp) {
            return null;
        }
        if ($blocking) {
            $deadline = microtime(true) + max(0, $timeoutMs) / 1000;
            while (true) {
                if (@flock($fp, LOCK_EX | LOCK_NB)) {
                    return $fp;
                }
                if (microtime(true) >= $deadline) {
                    @fclose($fp);
                    return null; // fail-open با لاگ — جریان آپلود مهم‌تر از اطمینان کامل قفل است
                }
                usleep(50000);
            }
        }
        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            @fclose($fp);
            return null;
        }
        return $fp;
    }

    /** @param resource|null $fp */
    public static function releaseLibraryLock($fp): void
    {
        if ($fp) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    /**
     * حذف فایل‌های orphan (بدون رکورد دیتابیس) باقی‌مانده در پوشه uploads
     *
     * اگر آپلود دیگری در جریان است (قفل مشغول)، پاک‌سازی با پرش امن انجام نمی‌شود
     * تا فایل‌های تازه‌نوشته‌شده که هنوز رکورد دیتابیس ندارند حذف نشوند.
     *
     * @return array{deleted_files: int, freed_bytes: int, skipped?: bool}
     */
    public static function purgeOrphanUploadFiles(): array
    {
        $lock = self::acquireLibraryLock(false);
        if ($lock === null) {
            error_log('HX media purge skipped: upload in progress (library lock busy)');
            return ['deleted_files' => 0, 'freed_bytes' => 0, 'skipped' => true];
        }
        try {
            return self::purgeOrphanUploadFilesLocked();
        } finally {
            self::releaseLibraryLock($lock);
        }
    }

    /**
     * بدنهٔ اصلی پاک‌سازی — فقط با قفل گرفته‌شده صدا زده شود.
     *
     * @return array{deleted_files: int, freed_bytes: int}
     */
    private static function purgeOrphanUploadFilesLocked(): array
    {
        $rows = DB::fetchAll('SELECT file_path_1200, file_path_800, file_path_480 FROM media');
        $known = [];
        foreach ($rows as $row) {
            foreach (['file_path_1200', 'file_path_800', 'file_path_480'] as $col) {
                $p = trim((string) ($row[$col] ?? ''));
                if ($p !== '') {
                    $known[$p] = true;
                }
            }
        }

        $uploadsDir = self::uploadsRootFullPath();
        if ($uploadsDir === null || !@is_dir($uploadsDir)) {
            return ['deleted_files' => 0, 'freed_bytes' => 0];
        }

        $deleted = 0;
        $freed = 0;
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($uploadsDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            $seen = 0;
            foreach ($it as $fileInfo) {
                if (++$seen > self::MAX_SCAN_FILES) {
                    break;
                }
                if (!$fileInfo->isFile() || $fileInfo->isLink()) {
                    continue;
                }
                $name = $fileInfo->getFilename();
                if (in_array(strtolower($name), ['.htaccess', 'index.html', 'index.php', '.gitkeep'], true)) {
                    continue;
                }
                $full = $fileInfo->getPathname();
                $relFromUploads = ltrim(str_replace('\\', '/', substr($full, strlen($uploadsDir))), '/');
                $webPath = '/uploads/' . $relFromUploads;

                if (!isset($known[$webPath])) {
                    $res = self::safeUnlinkUploadFile($webPath);
                    if ($res['deleted']) {
                        $deleted++;
                        $freed += $res['bytes'];
                    }
                }
            }
        } catch (Throwable) {
        }

        return ['deleted_files' => $deleted, 'freed_bytes' => $freed];
    }

    /**
     * بررسی محل‌های مصرف شناسه‌های media در تمام جداول مرتبط با گارد کامل روی هر جدول
     *
     * @param int[] $mediaIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function collectUsageMap(array $mediaIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn($v): int => (int) $v, $mediaIds),
            static fn(int $id): bool => $id > 0
        )));
        if (!$ids) {
            return [];
        }

        $map = [];
        // S9: همهٔ پرس‌وجوهای زیر فقط برای همین شناسه‌ها اجرا می‌شوند؛ دیگر کل جدول‌ها بارگذاری نمی‌شوند.
        $ph = implode(',', array_fill(0, count($ids), '?'));

        if (self::tableExists('businesses')) {
            try {
                $bizCovers = DB::fetchAll(
                    "SELECT id, name, status, cover_media_id FROM businesses WHERE cover_media_id IS NOT NULL AND cover_media_id IN ({$ph})",
                    $ids
                );
                foreach ($bizCovers as $row) {
                    $mid = (int) ($row['cover_media_id'] ?? 0);
                    if ($mid > 0) {
                        $map[$mid][] = [
                            'type' => 'business_cover',
                            'label' => 'کاور کسب‌وکار',
                            'entity_id' => (int) $row['id'],
                            'title' => (string) ($row['name'] ?? ''),
                            'status' => (string) ($row['status'] ?? ''),
                        ];
                    }
                }
            } catch (Throwable) {
            }
        }

        if (self::tableExists('business_photos') && self::tableExists('businesses')) {
            try {
                $bizPhotos = DB::fetchAll(
                    "SELECT bp.media_id, b.id AS business_id, b.name, b.status
                     FROM business_photos bp
                     INNER JOIN businesses b ON b.id = bp.business_id
                     WHERE bp.media_id IN ({$ph})",
                    $ids
                );
                foreach ($bizPhotos as $row) {
                    $mid = (int) ($row['media_id'] ?? 0);
                    if ($mid > 0) {
                        $map[$mid][] = [
                            'type' => 'business_photo',
                            'label' => 'گالری کسب‌وکار',
                            'entity_id' => (int) $row['business_id'],
                            'title' => (string) ($row['name'] ?? ''),
                            'status' => (string) ($row['status'] ?? ''),
                        ];
                    }
                }
            } catch (Throwable) {
            }
        }

        if (self::tableExists('article_extras') && self::tableExists('articles')) {
            try {
                $artCovers = DB::fetchAll(
                    "SELECT ae.cover_media_id, a.id AS article_id, a.title, a.status
                     FROM article_extras ae
                     INNER JOIN articles a ON a.id = ae.article_id
                     WHERE ae.cover_media_id IS NOT NULL AND ae.cover_media_id IN ({$ph})",
                    $ids
                );
                foreach ($artCovers as $row) {
                    $mid = (int) ($row['cover_media_id'] ?? 0);
                    if ($mid > 0) {
                        $map[$mid][] = [
                            'type' => 'article_cover',
                            'label' => 'تصویر شاخص مقاله',
                            'entity_id' => (int) $row['article_id'],
                            'title' => (string) ($row['title'] ?? ''),
                            'status' => (string) ($row['status'] ?? ''),
                        ];
                    }
                }
            } catch (Throwable) {
            }
        }

        if (self::tableExists('article_revisions') && self::tableExists('articles')) {
            try {
                $revRows = DB::fetchAll(
                    "SELECT ar.article_id, ar.payload_json, a.title
                     FROM article_revisions ar
                     INNER JOIN articles a ON a.id = ar.article_id
                     WHERE ar.status IN ('draft', 'review')
                       AND ar.payload_json LIKE '%cover_media_id%'
                       AND JSON_VALID(ar.payload_json)
                       AND CAST(JSON_UNQUOTE(JSON_EXTRACT(ar.payload_json, '$.cover_media_id')) AS UNSIGNED) IN ({$ph})",
                    $ids
                );
                foreach ($revRows as $row) {
                    $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
                    $mid = is_array($payload) ? (int) ($payload['cover_media_id'] ?? 0) : 0;
                    if ($mid > 0) {
                        $alreadyListed = false;
                        foreach ($map[$mid] ?? [] as $existing) {
                            if ($existing['type'] === 'article_cover' && $existing['entity_id'] === (int) $row['article_id']) {
                                $alreadyListed = true;
                                break;
                            }
                        }
                        if (!$alreadyListed) {
                            $map[$mid][] = [
                                'type' => 'article_revision',
                                'label' => 'پیش‌نویس/بازبینی مقاله',
                                'entity_id' => (int) $row['article_id'],
                                'title' => (string) ($row['title'] ?? ''),
                                'status' => 'draft',
                            ];
                        }
                    }
                }
            } catch (Throwable) {
            }
        }

        if (self::tableExists('advertising_banners')) {
            try {
                $banners = DB::fetchAll(
                    "SELECT id, title, placement, active, media_id FROM advertising_banners WHERE media_id IS NOT NULL AND media_id IN ({$ph})",
                    $ids
                );
                foreach ($banners as $row) {
                    $mid = (int) ($row['media_id'] ?? 0);
                    if ($mid > 0) {
                        $map[$mid][] = [
                            'type' => 'advertising_banner',
                            'label' => 'بنر تبلیغاتی (' . (string) ($row['placement'] ?? '') . ')',
                            'entity_id' => (int) $row['id'],
                            'title' => (string) ($row['title'] ?? ''),
                            'status' => ((int) ($row['active'] ?? 0) === 1) ? 'active' : 'inactive',
                        ];
                    }
                }
            } catch (Throwable) {
            }
        }

        $filtered = [];
        foreach ($ids as $id) {
            $filtered[$id] = $map[$id] ?? [];
        }
        return $filtered;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, array{web_path: string, host_path: string, exists: bool, bytes: int, is_webp: bool}>
     */
    private static function inspectMediaVariants(array $row): array
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $result = [];
        foreach (['1200' => 'file_path_1200', '800' => 'file_path_800', '480' => 'file_path_480'] as $size => $col) {
            $webPath = trim((string) ($row[$col] ?? ''));
            $cleanRel = ltrim($webPath, '/');
            $hostPath = $cleanRel !== '' ? 'public_html/' . $cleanRel : '';
            $fullPath = $cleanRel !== '' ? $base . '/public_html/' . $cleanRel : '';
            $exists = $fullPath !== '' && @is_file($fullPath);
            $bytes = $exists ? (int) (@filesize($fullPath) ?: 0) : 0;
            $isWebp = $webPath !== '' && str_ends_with(strtolower($webPath), '.webp');

            $result[$size] = [
                'web_path' => $webPath,
                'host_path' => $hostPath,
                'exists' => $exists,
                'bytes' => $bytes,
                'is_webp' => $isWebp,
            ];
        }
        return $result;
    }

    /**
     * @param array<string, bool> $knownRelativePaths
     * @return array{webp_files: int, non_webp_files: int, orphan_files: int, total_bytes: int}
     */
    private static function scanUploadsDirectory(array $knownRelativePaths): array
    {
        $uploadsDir = self::uploadsRootFullPath();
        if ($uploadsDir === null || !@is_dir($uploadsDir)) {
            return ['webp_files' => 0, 'non_webp_files' => 0, 'orphan_files' => 0, 'total_bytes' => 0];
        }

        $webpFiles = 0;
        $nonWebpFiles = 0;
        $orphanFiles = 0;
        $totalBytes = 0;

        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($uploadsDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            $seen = 0;
            foreach ($it as $fileInfo) {
                if (++$seen > self::MAX_SCAN_FILES) {
                    break;
                }
                if (!$fileInfo->isFile() || $fileInfo->isLink()) {
                    continue;
                }
                $name = $fileInfo->getFilename();
                if (in_array(strtolower($name), ['.htaccess', 'index.html', 'index.php', '.gitkeep'], true)) {
                    continue;
                }
                $bytes = (int) @$fileInfo->getSize();
                $totalBytes += $bytes;
                if (str_ends_with(strtolower($name), '.webp')) {
                    $webpFiles++;
                } else {
                    $nonWebpFiles++;
                }

                $full = $fileInfo->getPathname();
                $relFromUploads = ltrim(str_replace('\\', '/', substr($full, strlen($uploadsDir))), '/');
                $webPath = '/uploads/' . $relFromUploads;
                if (!isset($knownRelativePaths[$webPath])) {
                    $orphanFiles++;
                }
            }
        } catch (Throwable) {
        }

        return [
            'webp_files' => $webpFiles,
            'non_webp_files' => $nonWebpFiles,
            'orphan_files' => $orphanFiles,
            'total_bytes' => $totalBytes,
        ];
    }

    private static function uploadsRootFullPath(): ?string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $path = $base . '/public_html/uploads';
        if (!@is_dir($path)) {
            return null;
        }
        $real = @realpath($path);
        return is_string($real) && $real !== '' ? $real : $path;
    }

    /**
     * @return array{deleted: bool, bytes: int}
     */
    private static function safeUnlinkUploadFile(string $webPath): array
    {
        $clean = trim($webPath);
        if (
            $clean === ''
            || !str_starts_with($clean, '/uploads/')
            || str_contains($clean, '..')
            || str_contains($clean, '\\')
            || str_contains($clean, "\0")
        ) {
            return ['deleted' => false, 'bytes' => 0];
        }

        $uploadsRoot = self::uploadsRootFullPath();
        if ($uploadsRoot === null) {
            return ['deleted' => false, 'bytes' => 0];
        }
        $realUploadsRoot = @realpath($uploadsRoot);
        if (!is_string($realUploadsRoot) || $realUploadsRoot === '') {
            return ['deleted' => false, 'bytes' => 0];
        }

        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $candidate = $base . '/public_html/' . ltrim($clean, '/');
        if (!@is_file($candidate) || @is_link($candidate)) {
            return ['deleted' => false, 'bytes' => 0];
        }

        $realFile = @realpath($candidate);
        if (!is_string($realFile) || !str_starts_with($realFile, $realUploadsRoot . DIRECTORY_SEPARATOR)) {
            return ['deleted' => false, 'bytes' => 0];
        }

        $baseName = strtolower(basename($realFile));
        if (in_array($baseName, ['.htaccess', 'index.html', 'index.php', '.gitkeep'], true)) {
            return ['deleted' => false, 'bytes' => 0];
        }

        $bytes = (int) (@filesize($realFile) ?: 0);
        $deleted = @unlink($realFile);

        if ($deleted) {
            $parentDir = dirname($realFile);
            if ($parentDir !== $realUploadsRoot && str_starts_with($parentDir, $realUploadsRoot . DIRECTORY_SEPARATOR)) {
                @rmdir($parentDir);
            }
        }

        return ['deleted' => $deleted, 'bytes' => $deleted ? $bytes : 0];
    }

    private static function tableExists(string $table): bool
    {
        if (!preg_match('/^[a-z0-9_]+$/i', $table)) {
            return false;
        }
        try {
            return DB::fetchOne("SHOW TABLES LIKE '{$table}'") !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
