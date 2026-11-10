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
                'note' => 'در هر جایگاه حداکثر ۲ بنر فعال کنار هم در دسکتاپ نمایش داده می‌شود. بنرها برش نمی‌خورند؛ تصاویر ثابت به WebP بهینه و GIFهای متحرک با حفظ کامل انیمیشن و بهینه‌سازی خودکار ذخیره می‌شوند.',
            ],
        ];
    }

    /**
     * فهرست کامل کتابخانه رسانه با اطلاعات مسیر دقیق روی هاست، حجم دیسک و محل‌های استفاده در دیتابیس
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

        $allIds = array_map(static fn(array $r): int => (int) ($r['id'] ?? 0), $rows);
        $usageMap = self::collectUsageMap($allIds);

        $enriched = [];
        $unusedCount = 0;
        $usedCount = 0;
        $missingFilesCount = 0;
        $nonWebpDbCount = 0;
        $knownRelativePaths = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $usages = $usageMap[$id] ?? [];
            $variants = self::inspectMediaVariants($row);

            foreach ($variants as $v) {
                if ($v['web_path'] !== '') {
                    $knownRelativePaths[$v['web_path']] = true;
                }
            }

            $totalBytes = 0;
            $allPresent = true;
            $mimeLower = strtolower((string) ($row['mime_type'] ?? ''));
            $allWebp = $mimeLower === 'image/webp';
            $allGif = $mimeLower === 'image/gif';
            foreach ($variants as $v) {
                $totalBytes += $v['bytes'];
                if (!$v['exists']) {
                    $allPresent = false;
                }
                if (!$v['is_webp']) {
                    $allWebp = false;
                }
                if (!str_ends_with(strtolower($v['web_path']), '.gif')) {
                    $allGif = false;
                }
            }
            // GIFهای متحرک با حفظ انیمیشن فرمت بهینه و مجاز هستند؛ غیر WebP بودن آن‌ها خطا نیست.
            $formatOk = $allWebp || $allGif;

            $isUsed = count($usages) > 0;
            if ($isUsed) {
                $usedCount++;
            } else {
                $unusedCount++;
            }
            if (!$allPresent) {
                $missingFilesCount++;
            }
            if (!$formatOk) {
                $nonWebpDbCount++;
            }

            $hasBannerLock = false;
            foreach ($usages as $u) {
                if (($u['type'] ?? '') === 'advertising_banner') {
                    $hasBannerLock = true;
                    break;
                }
            }

            $item = [
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
                'format_ok' => $formatOk,
                'is_animated_gif' => $allGif,
                'usages' => $usages,
                'usage_count' => count($usages),
                'can_force_detach' => !$hasBannerLock,
            ];

            if ($usageFilter === 'used' && !$isUsed) {
                continue;
            }
            if ($usageFilter === 'unused' && $isUsed) {
                continue;
            }
            if ($usageFilter === 'missing_files' && $allPresent) {
                continue;
            }
            if ($usageFilter === 'non_webp' && $formatOk) {
                continue;
            }

            if ($search !== '') {
                $haystack = implode(' ', [
                    (string) $id,
                    $item['alt_text'],
                    $item['file_path_1200'],
                    $item['host_path_1200'],
                    implode(' ', array_map(static fn(array $u): string => (string) ($u['title'] ?? ''), $usages)),
                ]);
                $matched = function_exists('mb_stripos')
                    ? mb_stripos($haystack, $search, 0, 'UTF-8') !== false
                    : stripos($haystack, $search) !== false;
                if (!$matched) {
                    continue;
                }
            }

            $enriched[] = $item;
        }

        $totalFiltered = count($enriched);
        $offset = ($page - 1) * $perPage;
        $pagedItems = array_slice($enriched, $offset, $perPage);

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
     * حذف امن یک یا چند تصویر از دیتابیس و حذف فیزیکی فایل‌های WebP آن‌ها از هاست.
     * با force=true تصاویر متصل به بنر تبلیغاتی هم جداسازی (media_id بنر خالی و بنر غیرفعال) و کامل حذف می‌شوند.
     *
     * @param array<int, mixed> $mediaIds
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
                if ($bannerUsages) {
                    if (!$force) {
                        $blocked[] = [
                            'id' => $id,
                            'reason' => 'این تصویر به بنر تبلیغاتی متصل است؛ ابتدا تصویر بنر را تغییر دهید یا بنر را حذف کنید (یا از حذف کامل اجباری استفاده کنید).',
                            'usages' => $usages,
                        ];
                        continue;
                    }
                }
                if (!$detachUsages) {
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
                'detached_banners' => 0,
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
        $hasBanners = self::tableExists('advertising_banners');
        $deletedIds = [];
        $filesToUnlink = [];
        $detachedBanners = 0;

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
                    if ($force && $hasBanners) {
                        $detachedBanners += DB::query(
                            'UPDATE advertising_banners SET media_id = NULL, active = 0, updated_at = NOW() WHERE media_id = :id',
                            ['id' => $id]
                        )->rowCount();
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
            'detached_banners' => $detachedBanners,
            'blocked' => $blocked,
        ];
    }

    /**
     * حذف فایل‌های orphan (بدون رکورد دیتابیس) باقی‌مانده در پوشه uploads
     *
     * @return array{deleted_files: int, freed_bytes: int}
     */
    public static function purgeOrphanUploadFiles(): array
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

        if (self::tableExists('businesses')) {
            try {
                $bizCovers = DB::fetchAll(
                    'SELECT id, name, status, cover_media_id FROM businesses WHERE cover_media_id IS NOT NULL'
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
                    'SELECT bp.media_id, b.id AS business_id, b.name, b.status
                     FROM business_photos bp
                     INNER JOIN businesses b ON b.id = bp.business_id'
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
                    'SELECT ae.cover_media_id, a.id AS article_id, a.title, a.status
                     FROM article_extras ae
                     INNER JOIN articles a ON a.id = ae.article_id
                     WHERE ae.cover_media_id IS NOT NULL'
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
                       AND ar.payload_json LIKE '%cover_media_id%'"
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
                    'SELECT id, title, placement, active, media_id FROM advertising_banners WHERE media_id IS NOT NULL'
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
                $lowerName = strtolower($name);
                if (str_ends_with($lowerName, '.webp') || str_ends_with($lowerName, '.gif')) {
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
