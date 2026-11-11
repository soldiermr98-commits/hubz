<?php
declare(strict_types=1);

namespace CollectorRemote\Providers;

/**
 * رابط استاندارد تمام پرووایدرهای جمع‌آوری (بخش ۹ و اصلاحیه ۶)
 * خروجی هر آیتم در search() و details() باید دارای این ساختار استاندارد باشد:
 * [
 *   'name' => string,
 *   'category' => string,
 *   'address' => string,
 *   'lat' => ?float,
 *   'lng' => ?float,
 *   'phones' => string[],
 *   'hours' => string,
 *   'rating' => ?float,
 *   'reviews_count' => int,
 *   'photo_urls' => string[],
 *   'source' => string,
 *   'source_id' => string,
 * ]
 */
interface ProviderInterface
{
    /**
     * نام یکتای پرووایدر (مثلاً osm, gosom, neshan, csv)
     */
    public function name(): string;

    /**
     * جستجوی کسب‌وکارها بر اساس پارامترهای Job
     *
     * @param array<string, mixed> $job
     * @return array<int, array<string, mixed>>
     */
    public function search(array $job): array;

    /**
     * دریافت جزئیات تکمیلی یک مکان در صورت نیاز
     *
     * @param string $sourceId
     * @return array<string, mixed>|null
     */
    public function details(string $sourceId): ?array;
}
