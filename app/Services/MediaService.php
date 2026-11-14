<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use GdImage;
use PDO;
use RuntimeException;
use Throwable;

final class MediaUploadException extends RuntimeException {}

/**
 * Validate and re-encode all uploads strictly as optimized WebP images (480w, 800w, 1200w),
 * never storing any JPEG/PNG/GIF/BMP/AVIF original on disk, and manage the Media Library.
 *
 * استثنای تبلیغاتی: GIFهای «متحرک» برای حفظ انیمیشن دست‌نخورده ذخیره می‌شوند (بدون تبدیل به WebP
 * که فقط فریم اول را نگه می‌دارد)؛ GIFهای تک‌فریمی مانند قبل به WebP بهینه تبدیل می‌شوند.
 *
 * S23 (تک‌تک‌سازی): این کلاس فقط «موتور آپلود» است — مدیریت کتابخانهٔ رسانه
 * (فهرست، ویرایش Alt، حذف، پاک‌سازی orphan، نقشهٔ مصرف) منبع واحدش MediaLibraryService است
 * و متدهای این کلاس فقط نمایندهٔ همان‌اند تا دو کپی ~۷۰۰ خطی موازی حذف شود.
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
    /** سقف ایمنی برای شمارش فریم‌های GIF هنگام اسکن ساختار بلوکی فایل */
    private const MAX_GIF_FRAMES_SCAN = 1001;

    /**
     * راهنمای استاندارد ابعاد پیشنهادی برای تمام بخش‌های آپلود تصویر در پنل مدیریت
     *
     * @return array<string, array<string, string>>
     */
    public static function dimensionGuides(): array
    {
        return MediaLibraryService::dimensionGuides();
    }

    /**
     * @param array<string, mixed> $uploadedFile
     * @return array<string, mixed>
     */
    public static function handleUpload(array $uploadedFile, string $altText = ''): array
    {
        if (!isset($uploadedFile['error']) || is_array($uploadedFile['error'])) {
            throw new MediaUploadException('پارامتر آپلود نامعتبر است.');
        }
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            throw new MediaUploadException('آپلود فایل کامل نشد؛ حجم یا محدودیت سرور را بررسی کنید.');
        }
        $tmpPath = (string) $uploadedFile['tmp_name'];
        $actualSize = (int) ($uploadedFile['size'] ?? 0);
        if ($tmpPath === '' || !is_file($tmpPath) || $actualSize <= 0 || $actualSize !== (int) (@filesize($tmpPath) ?: 0)) {
            throw new MediaUploadException('فایل آپلودشده یافت نشد یا حجمش نامعتبر است.');
        }
        if ($actualSize > self::MAX_UPLOAD_BYTES) {
            throw new MediaUploadException('حجم فایل بیش از حد مجاز است (حداکثر ۸ مگابایت).');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = (string) $finfo->file($tmpPath);
        if (!in_array($realMime, self::ALLOWED_MIMES, true)) {
            throw new MediaUploadException('فرمت تصویر مجاز نیست. فقط تصاویر واقعی با فرمت JPG، PNG، WebP، GIF، BMP یا AVIF قابل قبول هستند؛ به‌جز GIF متحرک که با حفظ انیمیشن ذخیره می‌شود، بقیه به WebP بهینه تبدیل می‌شوند.');
        }
        if (!extension_loaded('gd')) {
            throw new MediaUploadException('افزونه GD روی سرور فعال نیست. لطفاً وضعیت آن را بررسی کنید.');
        }
        $isAnimatedGif = $realMime === 'image/gif' && self::animatedGifFrameCount($tmpPath) > 1;
        // Never fall back to JPEG or PNG: every saved file on disk must be real WebP.
        // Animated GIFs are the only exception: converting them to WebP with GD would
        // silently drop every frame except the first, so they are stored as valid GIF.
        if (
            !$isAnimatedGif
            && (
                !function_exists('imagewebp')
                || !function_exists('imagecreatefromwebp')
                || !function_exists('imagetypes')
                || !defined('IMG_WEBP')
                || (imagetypes() & IMG_WEBP) === 0
            )
        ) {
            throw new MediaUploadException('پشتیبانی WebP در افزونه GD فعال نیست؛ برای جلوگیری از ذخیره فرمت غیر WebP عملیات متوقف شد.');
        }
        $info = @getimagesize($tmpPath);
        if (!is_array($info) || (int) $info[0] <= 0 || (int) $info[1] <= 0) {
            throw new MediaUploadException('تصویر ارسالی آسیب‌دیده است یا هدر آن با نوع فایل هم‌خوانی ندارد.');
        }

        if ($realMime === 'image/gif' && $isAnimatedGif) {
            $gifWidth = (int) $info[0];
            $gifHeight = (int) $info[1];
            if ($gifWidth < 100 || $gifHeight < 100) {
                throw new MediaUploadException('ابعاد تصویر بسیار کوچک است (حداقل ۱۰۰×۱۰۰ پیکسل الزامی است).');
            }
            if ($gifWidth > self::MAX_DIMENSION || $gifHeight > self::MAX_DIMENSION || $gifWidth * $gifHeight > self::MAX_PIXELS) {
                throw new MediaUploadException('ابعاد تصویر بسیار بزرگ است؛ هر ضلع حداکثر ۱۰٬۰۰۰ و مساحت حداکثر ۲۰ میلیون پیکسل است.');
            }
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

        // S23 رفع race آپلود/پاک‌سازی: تا وقتی این آپلود در جریان است، پاک‌سازی orphan
        // فایل‌های تازه‌نوشته‌شده (که هنوز رکورد دیتابیس ندارند) را حذف نمی‌کند.
        $libraryLock = MediaLibraryService::acquireLibraryLock(true);
        if ($libraryLock === null) {
            error_log('HX media upload proceeded without library lock (timeout) — purge may skip this window');
        }

        $token = bin2hex(random_bytes(8));
        $generatedPaths = [];
        $variantSizes = [];
        $ownedFiles = [];
        $finalWidth800 = 0;
        $finalHeight800 = 0;
        $finalSizeBytes = 0;
        $storedMime = 'image/webp';
        $pdo = null;
        $transactionOwned = false;
        $commitAttempted = false;

        try {
            if ($isAnimatedGif) {
                // حفظ کامل انیمیشن: فایل GIF اعتبارسنجی‌شده بدون تغییر بیت‌به‌بیت ذخیره می‌شود
                // (GD قابلیت رمزگذاری مجدد GIF چندفریمی ندارد و تبدیل به WebP انیمیشن را نابود می‌کند).
                $relPath = "/uploads/{$subDir}/gif_{$token}.gif";
                $absPath = $base . '/public_html' . $relPath;
                $file = @fopen($absPath, 'xb');
                if ($file === false) {
                    throw new MediaUploadException('امکان ذخیره تصویر وجود ندارد. فضای دیسک و دسترسی پوشه را بررسی کنید.');
                }
                $ownedFiles[] = $absPath;
                $source = @fopen($tmpPath, 'rb');
                if (!is_resource($source)) {
                    throw new MediaUploadException('خواندن فایل آپلودشده ممکن نشد؛ دوباره تلاش کنید.');
                }
                $copiedBytes = @stream_copy_to_stream($source, $file);
                @fclose($source);
                $flushed = @fflush($file);
                $closed = @fclose($file);
                if ($copiedBytes === false || (int) $copiedBytes !== $actualSize || !$flushed || !$closed) {
                    throw new MediaUploadException('ذخیره فایل GIF متحرک کامل نشد. فضای دیسک و دسترسی پوشه را بررسی کنید.');
                }
                clearstatcache(true, $absPath);
                $gifSize = @filesize($absPath);
                $gifInfo = @getimagesize($absPath);
                if (
                    !is_int($gifSize)
                    || $gifSize <= 0
                    || !is_array($gifInfo)
                    || ($gifInfo['mime'] ?? '') !== 'image/gif'
                    || (int) $gifInfo[0] !== $origWidth
                    || (int) $gifInfo[1] !== $origHeight
                ) {
                    throw new MediaUploadException('فایل GIF ذخیره‌شده معتبر نیست؛ آپلود متوقف شد.');
                }
                $verifiedFrame = function_exists('imagecreatefromgif') ? @imagecreatefromgif($absPath) : false;
                if (!$verifiedFrame instanceof GdImage) {
                    throw new MediaUploadException('فایل GIF ذخیره‌شده قابل رمزگشایی نیست؛ آپلود متوقف شد.');
                }
                unset($verifiedFrame);
                @chmod($absPath, 0644);
                // هر سه ستون به همان فایل GIF اشاره می‌کنند تا بنر عمومی سایت (file_path_1200)
                // و پیش‌نمایش‌های پنل، همه نسخه متحرک را نشان دهند.
                foreach ([480, 800, 1200] as $variantW) {
                    $generatedPaths[$variantW] = $relPath;
                    $variantSizes[$variantW] = 0;
                }
                $variantSizes[800] = $gifSize;
                $finalWidth800 = $origWidth;
                $finalHeight800 = $origHeight;
                $finalSizeBytes = $gifSize;
                $storedMime = 'image/gif';
            } else {
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
            }

            $pdo = DB::connection();
            if ($pdo->inTransaction() || !$pdo->beginTransaction()) {
                throw new MediaUploadException('ثبت تصویر در پایگاه داده آماده نیست.');
            }
            $transactionOwned = true;
            DB::query(
                "INSERT INTO media (file_path_480, file_path_800, file_path_1200, mime_type, width, height, size_bytes, alt_text, created_at)
                 VALUES (:p480, :p800, :p1200, :mime, :w, :h, :sz, :alt, NOW())",
                [
                    'p480' => $generatedPaths[480],
                    'p800' => $generatedPaths[800],
                    'p1200' => $generatedPaths[1200],
                    'mime' => $storedMime,
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
            MediaLibraryService::releaseLibraryLock($libraryLock);
        }

        return [
            'id' => $mediaId,
            'file_path_480' => $generatedPaths[480],
            'file_path_800' => $generatedPaths[800],
            'file_path_1200' => $generatedPaths[1200],
            'url_480' => $generatedPaths[480],
            'url_800' => $generatedPaths[800],
            'url_1200' => $generatedPaths[1200],
            'mime_type' => $storedMime,
            'is_animated_gif' => $isAnimatedGif,
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
     * شمارش فریم‌های یک فایل GIF با پیمایش استاندارد بلوک‌های فرمت GIF87a/GIF89a.
     * بیش از یک فریم یعنی GIF متحرک؛ خروجی ۰ یعنی فایل قابل خواندن نبود یا ساختار نامعتبر بود.
     */
    private static function animatedGifFrameCount(string $path): int
    {
        $fp = @fopen($path, 'rb');
        if (!is_resource($fp)) {
            return 0;
        }
        $header = (string) @fread($fp, 6);
        if (!preg_match('/\AGIF8[79]a\z/', $header)) {
            @fclose($fp);
            return 0;
        }
        $screen = (string) @fread($fp, 7);
        if (strlen($screen) < 7) {
            @fclose($fp);
            return 0;
        }
        $flags = ord($screen[4]);
        if (($flags & 0x80) !== 0) {
            $paletteBytes = 3 * (2 ** (($flags & 0x07) + 1));
            if (@fseek($fp, $paletteBytes, SEEK_CUR) !== 0) {
                @fclose($fp);
                return 0;
            }
        }

        $frames = 0;
        while (!feof($fp) && $frames <= self::MAX_GIF_FRAMES_SCAN) {
            $block = @fread($fp, 1);
            if ($block === false || $block === '') {
                break;
            }
            $byte = ord($block);
            if ($byte === 0x3B) {
                break; // Trailer: پایان فایل
            }
            if ($byte === 0x21) {
                // Extension block: پرش از روی زیربلوک‌ها
                $label = @fread($fp, 1);
                if ($label === false || $label === '') {
                    break;
                }
                if (!self::skipGifSubBlocks($fp)) {
                    break;
                }
                continue;
            }
            if ($byte === 0x2C) {
                $frames++;
                $descriptor = (string) @fread($fp, 9);
                if (strlen($descriptor) < 9) {
                    break;
                }
                $dFlags = ord($descriptor[8]);
                if (($dFlags & 0x80) !== 0) {
                    $localPaletteBytes = 3 * (2 ** (($dFlags & 0x07) + 1));
                    if (@fseek($fp, $localPaletteBytes, SEEK_CUR) !== 0) {
                        break;
                    }
                }
                if (@fread($fp, 1) === false) {
                    break;
                }
                if (!self::skipGifSubBlocks($fp)) {
                    break;
                }
                continue;
            }
            // بایت ناشناخته: ساختار فایل نامعتبر است
            break;
        }
        @fclose($fp);
        return $frames;
    }

    /** پرش از روی زنجیره زیربلوک‌های GIF تا بلوک خالی (طول صفر). */
    private static function skipGifSubBlocks($fp): bool
    {
        while (!feof($fp)) {
            $lenData = @fread($fp, 1);
            if ($lenData === false || $lenData === '') {
                return false;
            }
            $len = ord($lenData);
            if ($len === 0) {
                return true;
            }
            if (@fseek($fp, $len, SEEK_CUR) !== 0) {
                return false;
            }
        }
        return false;
    }

    /**
     * S23: نمایندهٔ MediaLibraryService — منبع واحد مدیریت کتابخانهٔ رسانه.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function listLibrary(array $filters = []): array
    {
        return MediaLibraryService::listLibrary($filters);
    }

    /** S23: نمایندهٔ MediaLibraryService */
    public static function updateAltText(int $mediaId, string $altText): bool
    {
        return MediaLibraryService::updateAltText($mediaId, $altText);
    }

    /**
     * S23: نمایندهٔ MediaLibraryService (فلگ force همان‌جا پشتیبانی می‌شود؛ اینجا پیش‌فرض بسته است).
     *
     * @param int[] $mediaIds
     * @return array<string, mixed>
     */
    public static function deleteMediaItems(array $mediaIds, bool $detachUsages = false): array
    {
        return MediaLibraryService::deleteMediaItems($mediaIds, $detachUsages, false);
    }

    /** S23: نمایندهٔ MediaLibraryService — با قفل کتابخانه و پرش امن در هم‌زمانی با آپلود */
    public static function purgeOrphanUploadFiles(): array
    {
        return MediaLibraryService::purgeOrphanUploadFiles();
    }

    /**
     * S23: نمایندهٔ MediaLibraryService
     *
     * @param int[] $mediaIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function collectUsageMap(array $mediaIds): array
    {
        return MediaLibraryService::collectUsageMap($mediaIds);
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
