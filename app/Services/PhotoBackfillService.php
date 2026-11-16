<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use InvalidArgumentException;
use Throwable;

/**
 * S30 — پس‌گیری عکس کسب‌وکارها از منابع قبلاً جمع‌آوری‌شده (بدون دانلود روی هاست)
 *
 * هاست فقط «کاندیدها» را از JSON خام منابع (business_sources.raw) استخراج می‌کند:
 *  - آیتم‌های آپی‌فای: photos (نگاشت جدید) / apify_raw.imageUrls / images[].url / imageUrl
 *  - گوسوم (google-maps-scraper محلی): photo_urls
 * دانلود واقعی عکس و پوش بهینه‌شده توسط collector-remote روی PC مدیر انجام می‌شود
 * (POST /api/v1/collector/photo) — دقیقاً مطابق سیاست S2: «هیچ دانلود مستقیمی توسط هاست».
 */
final class PhotoBackfillService
{
    private const MAX_SCAN = 150;
    private const MAX_PHOTOS_PER_BUSINESS = 5;

    /**
     * کسب‌وکارهای منتشرشدهٔ بی‌عکس + URL عکس‌های یافت‌شده در منابعشان
     *
     * @return array{total_without_photo: int, scanned: int, candidates: array<int, array<string, mixed>>}
     */
    public static function candidates(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $rows = DB::fetchAll(
            "SELECT b.id, b.name, b.slug, c.slug AS city_slug, c.name_fa AS city_name_fa
             FROM businesses b
             INNER JOIN cities c ON c.id = b.city_id
             LEFT JOIN business_photos bp ON bp.business_id = b.id AND bp.status = 'approved'
             WHERE b.status = 'published' AND b.cover_media_id IS NULL AND bp.id IS NULL
             ORDER BY b.internal_score DESC, b.id DESC
             LIMIT " . self::MAX_SCAN
        );

        $totalWithoutPhoto = (int) (DB::fetchOne(
            "SELECT COUNT(*) AS n
             FROM businesses b
             LEFT JOIN business_photos bp ON bp.business_id = b.id AND bp.status = 'approved'
             WHERE b.status = 'published' AND b.cover_media_id IS NULL AND bp.id IS NULL"
        )['n'] ?? 0);

        $candidates = [];
        $scanned = 0;
        foreach ($rows as $row) {
            if (count($candidates) >= $limit) break;
            $scanned++;
            $photos = self::photosForBusiness((int) $row['id']);
            if ($photos === []) continue;
            $candidates[] = [
                'business_id'  => (int) $row['id'],
                'name'         => (string) $row['name'],
                'city_name_fa' => (string) $row['city_name_fa'],
                'attribution'  => 'Google Maps',
                'photos'       => $photos,
            ];
        }

        return [
            'total_without_photo' => $totalWithoutPhoto,
            'scanned'             => $scanned,
            'candidates'          => $candidates,
        ];
    }

    /**
     * استخراج URL عکس از JSON خام منابع یک کسب‌وکار (بدون ساخت URL خیالی؛ فقط فیلدهای شناخته‌شده)
     *
     * @return string[]
     */
    private static function photosForBusiness(int $businessId): array
    {
        $sources = DB::fetchAll(
            "SELECT source, raw FROM business_sources
             WHERE business_id = :bid
             ORDER BY fetched_at DESC, id DESC
             LIMIT 4",
            ['bid' => $businessId]
        );

        $urls = [];
        foreach ($sources as $src) {
            $raw = json_decode((string) ($src['raw'] ?? ''), true);
            if (!is_array($raw)) continue;

            // ۱) نگاشت جدید آپی‌فای (ApifyImportService::prepare → photos)
            $push = static function (array $list) use (&$urls): void {
                foreach ($list as $u) {
                    if (is_string($u) && preg_match('#^https://[^\s"\'<>]+$#iD', trim($u)) && strlen($u) <= 2048) {
                        $urls[trim($u)] = true;
                    }
                }
            };
            if (is_array($raw['photos'] ?? null)) $push($raw['photos']);

            // ۲) گوسوم (photo_urls)
            if (is_array($raw['photo_urls'] ?? null)) $push($raw['photo_urls']);

            // ۳) خروجی خام آپی‌فای داخل apify_raw
            if (is_array($raw['apify_raw'] ?? null)) {
                foreach (ApifyImportService::photoUrls($raw['apify_raw']) as $u) {
                    $urls[$u] = true;
                }
            }

            if (count($urls) >= self::MAX_PHOTOS_PER_BUSINESS) break;
        }

        return array_slice(array_keys($urls), 0, self::MAX_PHOTOS_PER_BUSINESS);
    }

    /**
     * S30 — ذخیرهٔ بایت‌های پوش‌شده از PC و اتصال آن به کسب‌وکار (هستهٔ /collector/photo)
     *
     * - MediaService فحش MIME واقعی می‌کند و به WebP سه‌سایز تبدیل می‌کند (متد public آپلود همان است).
     * - dedupe با source_url (همان عکس قبلاً پوش شده → duplicate).
     * - کاور خودکار: کسب‌وکار بدون cover_media_id → همین تصویر کاور می‌شود.
     *
     * @param array<string, mixed> $uploadedFile آرایهٔ $_FILES مانند (error/tmp_name/size)
     * @return array{duplicate: bool, media?: array<string, mixed>, set_as_cover?: bool}
     * @throws InvalidArgumentException کسب‌وکار نامعتبر
     * @throws MediaUploadException فایل نامعتبر (پیام امن authored)
     */
    public static function attachUploadedPhoto(
        int $businessId,
        array $uploadedFile,
        string $sourceUrl,
        string $attribution,
        string $altText = ''
    ): array {
        $biz = DB::fetchOne(
            "SELECT id, name, cover_media_id FROM businesses WHERE id = :id AND status = 'published' LIMIT 1",
            ['id' => $businessId]
        );
        if ($biz === null) {
            throw new InvalidArgumentException('کسب‌وکار منتشرشدهٔ مقصود یافت نشد.');
        }

        // Dedupe: همین عکس قبلاً برای این کسب‌وکار پوش شده است
        if ($sourceUrl !== '') {
            $dupe = DB::fetchOne(
                'SELECT id FROM business_photos WHERE business_id = :bid AND source_url = :url LIMIT 1',
                ['bid' => $businessId, 'url' => $sourceUrl]
            );
            if ($dupe !== null) {
                return ['duplicate' => true];
            }
        }

        $media = MediaService::handleUpload(
            $uploadedFile,
            $altText !== '' ? $altText : (string) $biz['name']
        );

        $mediaId = (int) $media['id'];
        $setAsCover = (int) ($biz['cover_media_id'] ?? 0) === 0;

        $pdo = DB::connection();
        $pdo->beginTransaction();
        try {
            DB::query(
                "INSERT INTO business_photos (business_id, media_id, source, source_url, attribution, status, sort, created_at)
                 VALUES (:bid, :mid, 'provider', :surl, :attr, 'approved',
                         (SELECT s FROM (SELECT COALESCE(MAX(sort), 0) + 1 AS s FROM business_photos WHERE business_id = :bid2) t),
                         NOW())",
                [
                    'bid'  => $businessId,
                    'mid'  => $mediaId,
                    'surl' => $sourceUrl !== '' ? $sourceUrl : null,
                    'attr' => $attribution !== '' ? $attribution : null,
                    'bid2' => $businessId,
                ]
            );
            if ($setAsCover) {
                DB::query('UPDATE businesses SET cover_media_id = :mid WHERE id = :bid', ['mid' => $mediaId, 'bid' => $businessId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['duplicate' => false, 'media' => $media, 'set_as_cover' => $setAsCover];
    }
}
