<?php
declare(strict_types=1);

namespace CollectorRemote\Providers;

use RuntimeException;

/**
 * پرووایدر CsvProvider برای ایمپورت فایل‌های CSV محلی روی کامپیوتر شما
 * یا تولید داده‌های استاندارد رشت/گیلان از فایل نمونه collector-remote/imports/rasht_cafes.csv
 */
final class CsvProvider implements ProviderInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function name(): string
    {
        return 'csv';
    }

    public function search(array $job): array
    {
        $importDir = (string) ($this->config['import_dir'] ?? (__DIR__ . '/../imports'));
        $citySlug = (string) ($job['city_slug'] ?? 'rasht');
        $csvFile = $importDir . '/' . $citySlug . '_places.csv';

        if (!is_file($csvFile)) {
            throw new RuntimeException("فایل CSV در مسیر {$csvFile} یافت نشد.");
        }

        $handle = fopen($csvFile, 'rb');
        if ($handle === false) {
            throw new RuntimeException("امکان باز کردن فایل CSV وجود ندارد: {$csvFile}");
        }

        $headers = fgetcsv($handle);
        if (!is_array($headers)) {
            fclose($handle);
            return [];
        }

        $headers = array_map(static fn($h): string => strtolower(trim((string) $h)), $headers);
        $items = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = [];
            foreach ($headers as $idx => $key) {
                $data[$key] = isset($row[$idx]) ? trim((string) $row[$idx]) : '';
            }

            $name = $data['name'] ?? '';
            if ($name === '') {
                continue;
            }

            $phones = [];
            if (!empty($data['phone'])) {
                $phones = array_map('trim', explode('|', $data['phone']));
            }

            $items[] = [
                'name' => $name,
                'category' => $data['category'] ?? 'کافه و رستوران',
                'address' => $data['address'] ?? '',
                'lat' => ($data['lat'] ?? '') !== '' ? (float) $data['lat'] : null,
                'lng' => ($data['lng'] ?? '') !== '' ? (float) $data['lng'] : null,
                'phones' => $phones,
                'hours' => $data['hours'] ?? '',
                'rating' => ($data['rating'] ?? '') !== '' ? (float) $data['rating'] : null,
                'reviews_count' => (int) ($data['reviews_count'] ?? 0),
                'photo_urls' => [],
                'source' => 'csv',
                'source_id' => !empty($data['source_id']) ? $data['source_id'] : ('csv_' . md5($citySlug . ':' . $name . ':' . ($data['address'] ?? ''))),
            ];
        }

        fclose($handle);
        return $items;
    }

    public function details(string $sourceId): ?array
    {
        return null;
    }
}
