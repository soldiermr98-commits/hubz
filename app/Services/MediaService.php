<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use GdImage;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class MediaUploadException extends RuntimeException {}

/**
 * Validate and re-encode all uploads strictly as optimized WebP images (480w, 800w, 1200w),
 * never storing any JPEG/PNG/GIF/BMP/AVIF original on disk, and manage the Media Library.
 *
 * Exception: animated GIF files keep their animation and are stored as optimized GIF
 * (resize + layer optimization via Imagick when available, validated original otherwise),
 * because GD cannot re-encode multi-frame GIFs and converting them to WebP would destroy
 * the animation.
 */
final class MediaService
{
    private const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;
    private const MAX_DIMENSION = 10000;
    private const MAX_PIXELS = 20000000;
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/bmp',
        'image/x-ms-bmp',
        'image/avif',
    ];
    private const TARGET_WIDTHS = [480, 800, 1200];

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
     * @param array<string, mixed> $uploadedFile
     * @return array<string, mixed>
     */
    public static function handleUpload(array $uploadedFile, string $altText = ''): array
    {
        if (!isset($uploadedFile['error']) || is_array($uploadedFile['error'])) {
            throw new MediaUploadException('ساختار فایل ارسالی نامعتبر است. لطفاً یک فایل تصویر انتخاب کنید.');
        }
        if ((int) $uploadedFile['error'] !== UPLOAD_ERR_OK) {
            throw new MediaUploadException('خطا در دریافت فایل آپلودشده. حجم فایل نباید بیشتر از ۸ مگابایت باشد.');
        }
        $tmpPath = (string) ($uploadedFile['tmp_name'] ?? '');
        $reportedSize = (int) ($uploadedFile['size'] ?? 0);
        if ($tmpPath === '' || !is_uploaded_file($tmpPath) || !is_file($tmpPath)) {
            throw new MediaUploadException('فایل موقت آپلود در سرور یافت نشد یا معتبر نیست.');
        }
        $actualSize = @filesize($tmpPath);
        if (
            $reportedSize <= 0
            || $reportedSize > self::MAX_UPLOAD_BYTES
            || !is_int($actualSize)
            || $actualSize <= 0
            || $actualSize > self::MAX_UPLOAD_BYTES
        ) {
            throw new MediaUploadException('حجم تصویر باید حداکثر ۸ مگابایت باشد. لطفاً تصویر سبک‌تری انتخاب کنید.');
        }
        if (!function_exists('mb_check_encoding') || !function_exists('mb_strlen')) {
            throw new MediaUploadException('افزونه mbstring روی سرور فعال نیست.');
        }
        $altText = trim($altText);
        if (
            !mb_check_encoding($altText, 'UTF-8')
            || mb_strlen($altText, 'UTF-8') > 255
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $altText)
        ) {
            throw new MediaUploadException('متن جایگزین تصویر باید معتبر و حداکثر ۲۵۵ نویسه باشد.');
        }
        if (!class_exists(\finfo::class)) {
            throw new MediaUploadException('افزونه Fileinfo روی سرور فعال نیست.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = (string) $finfo->file($tmpPath);
        if (!in_array($realMime, self::ALLOWED_MIMES, true)) {
            throw new MediaUploadException('فرمت تصویر مجاز نیست. فقط تصاویر واقعی با فرمت JPG، PNG، WebP، GIF، BMP یا AVIF قابل قبول هستند و همگی به WebP بهینه تبدیل می‌شوند.');
        }
        if (!extension_loaded('gd')) {
            throw new MediaUploadException('افزونه GD روی سرور فعال نیست. لطفاً وضعیت آن را بررسی کنید.');
        }
        // Never fall back to JPEG or PNG: every saved file on disk must be real WebP.
        if (
            !function_exists('imagewebp')
            || !function_exists('imagecreatefromwebp')
            || !function_exists('imagetypes')
            || !defined('IMG_WEBP')
            || (imagetypes() & IMG_WEBP) === 0
        ) {
            throw new MediaUploadException('پشتیبانی WebP در افزونه GD فعال نیست؛ برای جلوگیری از ذخیره فرمت غیر WebP عملیات متوقف شد.');
        }
        $info = @getimagesize($tmpPath);
        if (!is_array($info) || !isset($info[0], $info[1])) {
            throw new MediaUploadException('تصویر ارسالی آسیب‌دیده است و قابل پردازش نیست.');
        }
        $infoMime = (string) ($info['mime'] ?? '');
        $mimeCompatible = $infoMime === $realMime
            || ($realMime === 'image/x-ms-bmp' && $infoMime === 'image/bmp')
            || ($realMime === 'image/bmp' && $infoMime === 'image/x-ms-bmp');
        if (!$mimeCompatible) {
            throw new MediaUploadException('تصویر ارسالی آسیب‌دیده است یا هدر آن با نوع فایل هم‌خوانی ندارد.');
        }

        // Animated GIFs are supported natively: they keep their animation and are
        // stored as an optimized GIF file. This branch runs before the WebP-only
        // checks because re-encoding animation to static WebP would destroy it.
        if ($realMime === 'image/gif' && self::isAnimatedGif($tmpPath)) {
            $gifWidth = (int) $info[0];
            $gifHeight = (int) $info[1];
            if ($gifWidth < 100 || $gifHeight < 100) {
                throw new MediaUploadException('ابعاد تصویر بسیار کوچک است (حداقل ۱۰۰×۱۰۰ پیکسل الزامی است).');
            }
            if (
                $gifWidth > self::MAX_DIMENSION
                || $gifHeight > self::MAX_DIMENSION
                || $gifWidth * $gifHeight > self::MAX_PIXELS
            ) {
                throw new MediaUploadException('ابعاد تصویر بسیار بزرگ است؛ هر ضلع حداکثر ۱۰٬۰۰۰ و مساحت حداکثر ۲۰ میلیون پیکسل است.');
            }
            return self::storeAnimatedGif($tmpPath, $gifWidth, $gifHeight, $actualSize, $altText);
        }

        $origWidth = (int) $info[0];
        $origHeight = (int) $info[1];
        if ($origWidth < 100 || $origHeight < 100) {
            throw new MediaUploadException('ابعاد تصویر بسیار کوچک است (حداقل ۱۰۰×۱۰۰ پیکسل الزامی است).');
        }
        if (
            $origWidth > self::MAX_DIMENSION
            || $origHeight > self::MAX_DIMENSION
            || $origWidth * $origHeight > self::MAX_PIXELS
        ) {
            throw new MediaUploadException('ابعاد تصویر بسیار بزرگ است؛ هر ضلع حداکثر ۱۰٬۰۰۰ و مساحت حداکثر ۲۰ میلیون پیکسل است.');
        }

        $largestWidth = min($origWidth, max(self::TARGET_WIDTHS));
        $largestHeight = max(1, (int) round($origHeight * ($largestWidth / $origWidth)));
        $estimatedBytes = ($origWidth * $origHeight + $largestWidth * $largestHeight) * 6 + 16 * 1024 * 1024;
        $limit = self::memoryLimitBytes();
        if ($limit !== null && $estimatedBytes > max(0, $limit - memory_get_usage(true))) {
            throw new MediaUploadException('حافظه PHP برای پردازش این ابعاد کافی نیست؛ تصویر کوچک‌تری انتخاب کنید.');
        }

        $srcImage = match ($realMime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($tmpPath) : false,
            'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($tmpPath) : false,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false,
            'image/gif' => function_exists('imagecreatefromgif') ? @imagecreatefromgif($tmpPath) : false,
            'image/bmp', 'image/x-ms-bmp' => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($tmpPath) : false,
            'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($tmpPath) : false,
            default => false,
        };
        if (!$srcImage instanceof GdImage || imagesx($srcImage) !== $origWidth || imagesy($srcImage) !== $origHeight) {
            throw new MediaUploadException('تصویر ارسالی آسیب‌دیده است یا فرمت آن در GD سرور پشتیبانی نمی‌شود.');
        }

        if ($realMime === 'image/jpeg') {
            $srcImage = self::applyExifOrientation($srcImage, $tmpPath);
            $origWidth = imagesx($srcImage);
            $origHeight = imagesy($srcImage);
        }
        if (function_exists('imageistruecolor') && !imageistruecolor($srcImage) && function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($srcImage);
        }

        $subDir = date('Y/m');
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $uploadDir = $base . '/public_html/uploads/' . $subDir;
        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new MediaUploadException('امکان ساخت پوشه آپلود وجود ندارد. دسترسی پوشه را بررسی کنید.');
        }

        $token = bin2hex(random_bytes(8));
        $generatedPaths = [];
        $variantSizes = [];
        $ownedFiles = [];
        $finalWidth800 = 0;
        $finalHeight800 = 0;
        $finalSizeBytes = 0;
        $pdo = null;
        $transactionOwned = false;
        $commitAttempted = false;

        try {
            foreach (self::TARGET_WIDTHS as $targetW) {
                $newW = min($origWidth, $targetW);
                $newH = max(1, (int) round($origHeight * ($newW / $origWidth)));
                $canvas = @imagecreatetruecolor($newW, $newH);
                if (!$canvas instanceof GdImage) {
                    throw new MediaUploadException('پردازش اندازه تصویر ناموفق بود.');
                }
                if (!@imagealphablending($canvas, false) || !@imagesavealpha($canvas, true)) {
                    throw new MediaUploadException('پردازش شفافیت تصویر ناموفق بود.');
                }
                $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                imagefilledrectangle($canvas, 0, 0, $newW, $newH, $transparent);
                if (!@imagecopyresampled($canvas, $srcImage, 0, 0, 0, 0, $newW, $newH, $origWidth, $origHeight)) {
                    throw new MediaUploadException('پردازش اندازه تصویر ناموفق بود.');
                }

                $relPath = "/uploads/{$subDir}/img_{$token}_{$targetW}.webp";
                $absPath = $base . '/public_html' . $relPath;
                $file = @fopen($absPath, 'xb');
                if ($file === false) {
                    throw new MediaUploadException('امکان ذخیره تصویر وجود ندارد. فضای دیسک و دسترسی پوشه را بررسی کنید.');
                }
                $ownedFiles[] = $absPath;
                $quality = $targetW === 480 ? 80 : 82;
                $written = false;
                $flushed = false;
                $closed = false;
                try {
                    $written = @imagewebp($canvas, $file, $quality);
                    $flushed = @fflush($file);
                } finally {
                    $closed = @fclose($file);
                    unset($canvas);
                }
                if (!$written || !$flushed || !$closed) {
                    throw new MediaUploadException('ذخیره تصویر WebP کامل نشد. فضای دیسک و دسترسی پوشه را بررسی کنید.');
                }
                clearstatcache(true, $absPath);
                $outputSize = @filesize($absPath);
                $outputInfo = @getimagesize($absPath);
                if (
                    !is_int($outputSize)
                    || $outputSize <= 0
                    || !is_array($outputInfo)
                    || ($outputInfo['mime'] ?? '') !== 'image/webp'
                    || (int) $outputInfo[0] !== $newW
                    || (int) $outputInfo[1] !== $newH
                ) {
                    throw new MediaUploadException('فایل WebP تولیدشده معتبر نیست؛ ذخیره تصویر متوقف شد.');
                }
                $verified = @imagecreatefromwebp($absPath);
                if (!$verified instanceof GdImage || imagesx($verified) !== $newW || imagesy($verified) !== $newH) {
                    throw new MediaUploadException('فایل WebP تولیدشده معتبر نیست؛ ذخیره تصویر متوقف شد.');
                }
                unset($verified);
                @chmod($absPath, 0644);
                $generatedPaths[$targetW] = $relPath;
                $variantSizes[$targetW] = $outputSize;
                if ($targetW === 800) {
                    $finalWidth800 = $newW;
                    $finalHeight800 = $newH;
                    $finalSizeBytes = $outputSize;
                }
            }

            $pdo = DB::connection();
            if ($pdo->inTransaction() || !$pdo->beginTransaction()) {
                throw new MediaUploadException('ثبت تصویر در پایگاه داده آماده نیست.');
            }
            $transactionOwned = true;
            DB::query(
                "INSERT INTO media (file_path_480, file_path_800, file_path_1200, mime_type, width, height, size_bytes, alt_text, created_at)
                 VALUES (:p480, :p800, :p1200, 'image/webp', :w, :h, :sz, :alt, NOW())",
                [
                    'p480' => $generatedPaths[480],
                    'p800' => $generatedPaths[800],
                    'p1200' => $generatedPaths[1200],
                    'w' => $finalWidth800,
                    'h' => $finalHeight800,
                    'sz' => $finalSizeBytes,
                    'alt' => $altText,
                ]
            );
            $mediaId = (int) DB::lastInsertId();
            if ($mediaId <= 0) {
                throw new MediaUploadException('ثبت تصویر در پایگاه داده کامل نشد.');
            }
            $commitAttempted = true;
            if (!$pdo->commit()) {
                throw new MediaUploadException('نتیجه ثبت تصویر نامشخص است؛ پیش از تلاش دوباره وضعیت را بررسی کنید.');
            }
            $transactionOwned = false;
        } catch (Throwable $error) {
            $safeToRemove = !$commitAttempted;
            if ($transactionOwned && $pdo instanceof PDO) {
                try {
                    if ($pdo->inTransaction()) {
                        $rolledBack = $pdo->rollBack();
                        if (!$commitAttempted) {
                            $safeToRemove = $rolledBack;
                        }
                    }
                } catch (Throwable) {
                    $safeToRemove = false;
                }
            }
            $cleanupFailed = false;
            if ($safeToRemove) {
                foreach ($ownedFiles as $filePath) {
                    if (!@unlink($filePath) && is_file($filePath)) {
                        $cleanupFailed = true;
                    }
                }
            }
            if ($commitAttempted) {
                throw new MediaUploadException('نتیجه ثبت تصویر نامشخص است؛ پیش از تلاش دوباره وضعیت رسانه را بررسی کنید.', 0, $error);
            }
            if (!$safeToRemove) {
                throw new MediaUploadException('لغو ثبت تصویر نامشخص است؛ فایل‌ها حفظ شدند و باید پیش از تلاش دوباره بررسی شوند.', 0, $error);
            }
            if ($cleanupFailed) {
                throw new MediaUploadException('ذخیره تصویر انجام نشد و پاک‌سازی فایل ناقص بود؛ از مدیر هاست بخواهید وضعیت را بررسی کند.', 0, $error);
            }
            if ($error instanceof MediaUploadException) {
                throw $error;
            }
            throw new MediaUploadException('امکان ذخیره تصویر وجود ندارد؛ وضعیت سرور و پایگاه داده را بررسی کنید.', 0, $error);
        } finally {
            unset($srcImage);
        }

        return [
            'id' => $mediaId,
            'file_path_480' => $generatedPaths[480],
            'file_path_800' => $generatedPaths[800],
            'file_path_1200' => $generatedPaths[1200],
            'url_480' => $generatedPaths[480],
            'url_800' => $generatedPaths[800],
            'url_1200' => $generatedPaths[1200],
            'mime_type' => 'image/webp',
            'width' => $finalWidth800,
            'height' => $finalHeight800,
            'size_bytes' => $finalSizeBytes,
            'alt_text' => $altText,
            'source_mime_type' => $realMime,
            'original_bytes' => $actualSize,
            'webp_1200_bytes' => $variantSizes[1200] ?? $finalSizeBytes,
            'webp_total_bytes' => array_sum($variantSizes),
            'host_dir' => 'public_html/uploads/' . $subDir,
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

        $rows = DB::fetchAll('SELECT m.* FROM media m ORDER BY m.id DESC');

        $allIds = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        $usageMap = self::collectUsageMap($allIds);

        $enriched = [];
        $unusedCount = 0;
        $usedCount = 0;
        $missingFilesCount = 0;
        $nonWebpDbCount = 0;
        $knownRelativePaths = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $usages = $usageMap[$id] ?? [];
            $variants = self::inspectMediaVariants($row);

            foreach ($variants as $v) {
                if ($v['web_path'] !== '') {
                    $knownRelativePaths[$v['web_path']] = true;
                }
            }

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

            $isUsed = count($usages) > 0;
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

            $hasBannerLock = false;
            foreach ($usages as $u) {
                if ($u['type'] === 'advertising_banner') {
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
            if ($usageFilter === 'non_webp' && $allWebp) {
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
        $cleanAlt = mb_substr(trim($altText), 0, 220);
        DB::query(
            'UPDATE media SET alt_text = :alt WHERE id = :id',
            ['alt' => $cleanAlt, 'id' => $mediaId]
        );
        return true;
    }

    /**
     * تشخیص GIF متحرک با پارس باینری ساختار بلوک‌های GIF؛ بدون نیاز به Imagick
     */
    private static function isAnimatedGif(string $filePath): bool
    {
        $size = @filesize($filePath);
        if (!is_int($size) || $size < 16) {
            return false;
        }
        $data = @file_get_contents($filePath);
        if (!is_string($data) || strlen($data) < 14) {
            return false;
        }
        if (!str_starts_with($data, 'GIF87a') && !str_starts_with($data, 'GIF89a')) {
            return false;
        }
        $len = strlen($data);
        $pos = 6;
        if ($len < $pos + 7) {
            return false;
        }
        $screenFlags = ord($data[$pos + 4]);
        $pos += 7;
        if (($screenFlags & 0x80) !== 0) {
            $pos += 3 * (2 ** (($screenFlags & 0x07) + 1));
        }
        $frames = 0;
        while ($pos < $len) {
            $block = ord($data[$pos]);
            if ($block === 0x21) { // Extension Introducer
                if ($pos + 2 > $len) {
                    break;
                }
                $pos += 2;
                while ($pos < $len) {
                    $subLen = ord($data[$pos]);
                    $pos++;
                    if ($subLen === 0) {
                        break;
                    }
                    $pos += $subLen;
                }
            } elseif ($block === 0x2C) { // Image Descriptor = one frame
                $frames++;
                if ($frames > 1) {
                    return true;
                }
                if ($pos + 10 > $len) {
                    break;
                }
                $imgFlags = ord($data[$pos + 9]);
                $pos += 10;
                if (($imgFlags & 0x80) !== 0) {
                    $pos += 3 * (2 ** (($imgFlags & 0x07) + 1));
                }
                if ($pos >= $len) {
                    break;
                }
                $pos++; // LZW minimum code size
                while ($pos < $len) {
                    $subLen = ord($data[$pos]);
                    $pos++;
                    if ($subLen === 0) {
                        break;
                    }
                    $pos += $subLen;
                }
            } else { // 0x3B trailer or unknown byte: stop parsing safely
                break;
            }
        }
        return $frames > 1;
    }

    /**
     * ذخیره GIF متحرک با حفظ کامل انیمیشن؛ در صورت وجود Imagick باز اندازه‌گذاری و بهینه‌سازی لایه‌ها،
     * در غیر این صورت ذخیره امن فایل اصلی معتبرشده. مسیر یکسان در هر سه ستون ثبت می‌شود.
     */
    private static function storeAnimatedGif(string $tmpPath, int $origWidth, int $origHeight, int $actualSize, string $altText): array
    {
        $subDir = date('Y/m');
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $uploadDir = $base . '/public_html/uploads/' . $subDir;
        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new MediaUploadException('امکان ساخت پوشه آپلود وجود ندارد. دسترسی پوشه را بررسی کنید.');
        }

        $token = bin2hex(random_bytes(8));
        $relPath = "/uploads/{$subDir}/img_{$token}_anim.gif";
        $absPath = $base . '/public_html' . $relPath;
        $optimized = false;
        $finalWidth = $origWidth;
        $finalHeight = $origHeight;

        if (class_exists(\Imagick::class)) {
            try {
                $imagick = new \Imagick($tmpPath);
                $coalesced = $imagick->coalesceImages();
                if ($coalesced instanceof \Imagick && $coalesced->getNumberImages() > 1) {
                    $maxWidth = min(1200, max(self::TARGET_WIDTHS));
                    if ($origWidth > $maxWidth) {
                        $newW = $maxWidth;
                        $newH = max(1, (int) round($origHeight * ($maxWidth / $origWidth)));
                        foreach ($coalesced as $frame) {
                            $frame->resizeImage($newW, $newH, \Imagick::FILTER_TRIANGLE, 1, false);
                        }
                        $finalWidth = $newW;
                        $finalHeight = $newH;
                    }
                    $coalesced->optimizeImageLayers();
                    if ($coalesced->writeImages($absPath, true)) {
                        clearstatcache(true, $absPath);
                        $check = @getimagesize($absPath);
                        if (is_array($check) && ($check['mime'] ?? '') === 'image/gif' && self::isAnimatedGif($absPath)) {
                            $optimized = true;
                        }
                    }
                }
                if (isset($coalesced) && $coalesced instanceof \Imagick) {
                    $coalesced->destroy();
                }
                $imagick->destroy();
            } catch (Throwable) {
                $optimized = false;
            }
            if (!$optimized) {
                @unlink($absPath);
            }
        }

        if (!$optimized) {
            $in = @fopen($tmpPath, 'rb');
            $out = @fopen($absPath, 'xb');
            if (!$in || !$out) {
                if (is_resource($in)) @fclose($in);
                if (is_resource($out)) @fclose($out);
                throw new MediaUploadException('امکان ذخیره تصویر وجود ندارد. فضای دیسک و دسترسی پوشه را بررسی کنید.');
            }
            $copied = @stream_copy_to_stream($in, $out);
            @fclose($in);
            @fclose($out);
            if ($copied === false || $copied <= 0) {
                @unlink($absPath);
                throw new MediaUploadException('ذخیره تصویر GIF کامل نشد. فضای دیسک و دسترسی پوشه را بررسی کنید.');
            }
        }

        @chmod($absPath, 0644);
        clearstatcache(true, $absPath);
        $outInfo = @getimagesize($absPath);
        if (!is_array($outInfo) || ($outInfo['mime'] ?? '') !== 'image/gif' || !self::isAnimatedGif($absPath)) {
            @unlink($absPath);
            throw new MediaUploadException('فایل GIF ذخیره‌شده معتبر نیست یا انیمیشن آن حفظ نشده است؛ ذخیره تصویر متوقف شد.');
        }
        $outSize = (int) @filesize($absPath);
        if ($outSize <= 0) {
            @unlink($absPath);
            throw new MediaUploadException('فایل GIF ذخیره‌شده خالی است؛ ذخیره تصویر متوقف شد.');
        }
        $finalWidth = (int) $outInfo[0];
        $finalHeight = (int) $outInfo[1];

        $pdo = null;
        $transactionOwned = false;
        $commitAttempted = false;
        try {
            $pdo = DB::connection();
            if ($pdo->inTransaction() || !$pdo->beginTransaction()) {
                throw new MediaUploadException('ثبت تصویر در پایگاه داده آماده نیست.');
            }
            $transactionOwned = true;
            DB::query(
                "INSERT INTO media (file_path_480, file_path_800, file_path_1200, mime_type, width, height, size_bytes, alt_text, created_at)
                 VALUES (:p, :p, :p, 'image/gif', :w, :h, :sz, :alt, NOW())",
                [
                    'p' => $relPath,
                    'w' => $finalWidth,
                    'h' => $finalHeight,
                    'sz' => $outSize,
                    'alt' => $altText,
                ]
            );
            $mediaId = (int) DB::lastInsertId();
            if ($mediaId <= 0) {
                throw new MediaUploadException('ثبت تصویر در پایگاه داده کامل نشد.');
            }
            $commitAttempted = true;
            if (!$pdo->commit()) {
                throw new MediaUploadException('نتیجه ثبت تصویر نامشخص است؛ پیش از تلاش دوباره وضعیت را بررسی کنید.');
            }
            $transactionOwned = false;
        } catch (Throwable $error) {
            $safeToRemove = !$commitAttempted;
            if ($transactionOwned && $pdo instanceof PDO) {
                try {
                    if ($pdo->inTransaction()) {
                        $rolledBack = $pdo->rollBack();
                        if (!$commitAttempted) {
                            $safeToRemove = $rolledBack;
                        }
                    }
                } catch (Throwable) {
                    $safeToRemove = false;
                }
            }
            if ($safeToRemove) {
                @unlink($absPath);
            }
            if ($commitAttempted) {
                throw new MediaUploadException('نتیجه ثبت تصویر نامشخص است؛ پیش از تلاش دوباره وضعیت رسانه را بررسی کنید.', 0, $error);
            }
            if (!$safeToRemove) {
                throw new MediaUploadException('لغو ثبت تصویر نامشخص است؛ فایل حفظ شد و باید پیش از تلاش دوباره بررسی شود.', 0, $error);
            }
            if ($error instanceof MediaUploadException) {
                throw $error;
            }
            throw new MediaUploadException('امکان ذخیره تصویر وجود ندارد؛ وضعیت سرور و پایگاه داده را بررسی کنید.', 0, $error);
        }

        return [
            'id' => $mediaId,
            'file_path_480' => $relPath,
            'file_path_800' => $relPath,
            'file_path_1200' => $relPath,
            'url_480' => $relPath,
            'url_800' => $relPath,
            'url_1200' => $relPath,
            'mime_type' => 'image/gif',
            'width' => $finalWidth,
            'height' => $finalHeight,
            'size_bytes' => $outSize,
            'alt_text' => $altText,
            'source_mime_type' => 'image/gif',
            'original_bytes' => $actualSize,
            'webp_1200_bytes' => $outSize,
            'webp_total_bytes' => $outSize,
            'animated_gif' => true,
            'gif_optimized' => $optimized,
            'host_dir' => 'public_html/uploads/' . $subDir,
        ];
    }

    /**
     * حذف امن یک یا چند تصویر از دیتابیس و حذف فیزیکی فایل‌های WebP آن‌ها از هاست
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
            'SELECT * FROM media WHERE id IN (' . implode(',', $placeholders) . ')',
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
        $forceDetachBannerIds = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $usages = $usageMap[$id] ?? [];

            if ($usages) {
                $bannerUsages = array_values(array_filter(
                    $usages,
                    static fn(array $u): bool => $u['type'] === 'advertising_banner'
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
                    foreach ($bannerUsages as $bu) {
                        $forceDetachBannerIds[] = (int) ($bu['entity_id'] ?? 0);
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

        $hasArticleExtras = self::tableExists('article_extras');
        $hasBanners = self::tableExists('advertising_banners');
        $deletedIds = [];
        $filesToUnlink = [];
        $detachedBanners = 0;

        try {
            foreach ($deletableRows as $row) {
                $id = (int) $row['id'];
                if ($detachUsages) {
                    DB::query('UPDATE businesses SET cover_media_id = NULL WHERE cover_media_id = :id', ['id' => $id]);
                    DB::query('DELETE FROM business_photos WHERE media_id = :id', ['id' => $id]);
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
        if ($uploadsDir === null || !is_dir($uploadsDir)) {
            return ['deleted_files' => 0, 'freed_bytes' => 0];
        }

        $deleted = 0;
        $freed = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($uploadsDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $fileInfo) {
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

        return ['deleted_files' => $deleted, 'freed_bytes' => $freed];
    }

    /**
     * بررسی محل‌های مصرف شناسه‌های media در تمام جداول مرتبط
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

        if (self::tableExists('article_extras')) {
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

        if (self::tableExists('article_revisions')) {
            try {
                $revRows = DB::fetchAll(
                    "SELECT ar.article_id, ar.payload_json, a.title
                     FROM article_revisions ar
                     INNER JOIN articles a ON a.id = ar.article_id
                     WHERE ar.status IN ('draft', 'review')"
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

    private static function applyExifOrientation(GdImage $image, string $filePath): GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        try {
            $exif = @exif_read_data($filePath);
            $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
            $rotated = match ($orientation) {
                3 => @imagerotate($image, 180, 0),
                6 => @imagerotate($image, -90, 0),
                8 => @imagerotate($image, 90, 0),
                default => false,
            };
            if ($rotated instanceof GdImage) {
                return $rotated;
            }
        } catch (Throwable) {
        }
        return $image;
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
            $exists = $fullPath !== '' && is_file($fullPath);
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
        if ($uploadsDir === null || !is_dir($uploadsDir)) {
            return ['webp_files' => 0, 'non_webp_files' => 0, 'orphan_files' => 0, 'total_bytes' => 0];
        }

        $webpFiles = 0;
        $nonWebpFiles = 0;
        $orphanFiles = 0;
        $totalBytes = 0;

        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($uploadsDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $fileInfo) {
                if (!$fileInfo->isFile() || $fileInfo->isLink()) {
                    continue;
                }
                $name = $fileInfo->getFilename();
                if (in_array(strtolower($name), ['.htaccess', 'index.html', 'index.php', '.gitkeep'], true)) {
                    continue;
                }
                $bytes = (int) $fileInfo->getSize();
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
        if (!is_dir($path)) {
            return null;
        }
        $real = realpath($path);
        return $real !== false ? $real : null;
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

        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $candidate = $base . '/public_html/' . ltrim($clean, '/');
        if (!is_file($candidate) || is_link($candidate)) {
            return ['deleted' => false, 'bytes' => 0];
        }

        $realFile = realpath($candidate);
        if ($realFile === false || !str_starts_with($realFile, $uploadsRoot . DIRECTORY_SEPARATOR)) {
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
            if ($parentDir !== $uploadsRoot && str_starts_with($parentDir, $uploadsRoot . DIRECTORY_SEPARATOR)) {
                @rmdir($parentDir);
            }
        }

        return ['deleted' => $deleted, 'bytes' => $deleted ? $bytes : 0];
    }

    private static function tableExists(string $table): bool
    {
        try {
            return DB::fetchOne("SHOW TABLES LIKE '{$table}'") !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /** Parse conventional PHP memory_limit values; -1 means unlimited. */
    private static function memoryLimitBytes(): ?int
    {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '' || $value === '-1' || !preg_match('/\A(\d+)\s*([kmg]?)\z/i', $value, $match)) {
            return null;
        }
        $amount = (int) $match[1];
        $factor = match (strtolower($match[2])) {
            'k' => 1024,
            'm' => 1048576,
            'g' => 1073741824,
            default => 1,
        };
        if ($amount > intdiv(PHP_INT_MAX, $factor)) {
            return null;
        }
        return $amount * $factor;
    }
}
