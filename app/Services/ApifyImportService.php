<?php
declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/** Convert supported Google Maps Actor output without inventing values or timestamps. */
final class ApifyImportService
{
    public static function prepare(array $items, ?string $runStartedAt = null, string $timeOrigin = 'manual_run_started_at'): array
    {
        if (!$items || !array_is_list($items) || count($items) > ManualImportService::MAX_RECORDS) {
            throw new InvalidArgumentException('فایل باید آرایه‌ای شامل ۱ تا ۵۰۰ رکورد باشد.');
        }
        $out = [];
        $stats = ['apify'=>0, 'already_standard'=>0, 'missing_time'=>0, 'missing_identity'=>0, 'missing_coordinates'=>0];
        foreach ($items as $item) {
            if (!is_array($item) || array_is_list($item)) { $out[] = $item; continue; }
            if (isset($item['source'], $item['source_id']) && is_string($item['source']) && is_string($item['source_id'])) {
                $out[] = $item;
                $stats['already_standard']++;
                continue;
            }
            // Do not guess a provider for an arbitrary JSON object.
            if (!array_key_exists('title', $item) || (!array_key_exists('url', $item) && !array_key_exists('placeId', $item) && !array_key_exists('fid', $item))) {
                $out[] = $item;
                continue;
            }
            $stats['apify']++;
            $ids = self::placeIds($item);
            $placeId = count($ids) === 1 ? $ids[0] : '';
            $fid = is_string($item['fid'] ?? null) && preg_match('/^0x[0-9a-f]+:0x[0-9a-f]+$/i', trim($item['fid']))
                ? strtolower(trim($item['fid'])) : '';
            // A digest avoids MySQL's case-insensitive collation conflating distinct Place IDs.
            $sourceId = $fid !== '' ? $fid : ($placeId !== '' ? 'gpid_' . hash('sha256', $placeId) : '');
            $category = is_string($item['categoryName'] ?? null) ? $item['categoryName'] : '';
            if ($category === '' && is_array($item['categories'] ?? null) && is_string($item['categories'][0] ?? null)) $category = $item['categories'][0];
            $address = is_string($item['address'] ?? null) ? $item['address'] : (is_string($item['street'] ?? null) ? $item['street'] : null);
            $location = is_array($item['location'] ?? null) ? $item['location'] : [];
            $lat = $location['lat'] ?? null; $lng = $location['lng'] ?? null;
            // An unverified placeholder is not a coordinate.
            if (($item['locationUnverified'] ?? false) === true) $lat = $lng = null;
            $time = is_string($item['scrapedAt'] ?? null) && trim($item['scrapedAt']) !== '' ? trim($item['scrapedAt']) : trim((string)$runStartedAt);
            $origin = !empty($item['scrapedAt']) ? 'scrapedAt' : ($time !== '' ? $timeOrigin : 'missing');
            $phone = is_string($item['phone'] ?? null) ? trim($item['phone']) : '';
            $normalized = [
                'source'=>'google_maps', 'source_id'=>$sourceId, 'google_place_id'=>$placeId,
                'source_url'=>is_string($item['url'] ?? null) ? $item['url'] : '',
                'name'=>is_string($item['title'] ?? null) ? $item['title'] : '',
                'city'=>is_string($item['city'] ?? null) ? $item['city'] : '', 'category'=>$category,
                'address'=>$address, 'lat'=>$lat, 'lng'=>$lng, 'phones'=>$phone !== '' ? [$phone] : [],
                'website'=>is_string($item['website'] ?? null) ? $item['website'] : null,
                'rating'=>$item['totalScore'] ?? null, 'reviews_count'=>$item['reviewsCount'] ?? null,
                'fetched_at'=>$time, 'observation_time_origin'=>$origin,
                'reviews'=>is_array($item['reviews'] ?? null) ? $item['reviews'] : [],
                'reviews_complete'=>false,
                // Retain the actual Actor output, including fields not mapped for publication.
                'apify_raw'=>$item,
            ];
            if (is_bool($item['permanentlyClosed'] ?? null)) $normalized['permanently_closed'] = $item['permanentlyClosed'];
            if ($time === '') $stats['missing_time']++;
            if ($sourceId === '' || count($ids) > 1) $stats['missing_identity']++;
            if ($lat === null || $lng === null) $stats['missing_coordinates']++;
            $out[] = $normalized;
        }
        return ['items'=>$out, 'stats'=>$stats];
    }

    /** Collect only identity-bearing fields, never IDs mentioned in review text. */
    public static function placeIds(array $item): array
    {
        $ids = [];
        $collect = static function (array $row) use (&$ids): void {
            foreach (['google_place_id','placeId','place_id'] as $key) {
                $v = $row[$key] ?? null;
                if (is_string($v) && preg_match('/^[A-Za-z0-9_-]{10,190}$/D', trim($v))) $ids[trim($v)] = true;
            }
            foreach (['source_url','url'] as $key) {
                $url = $row[$key] ?? null;
                if (!is_string($url) || strlen($url) > 8192) continue;
                $parts = parse_url($url);
                $host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
                if (!preg_match('/(^|\.)google\.(?:com|[a-z]{2,3}(?:\.[a-z]{2})?)$/D', $host)) continue;
                $query = []; parse_str((string)($parts['query'] ?? ''), $query);
                $q = $query['query_place_id'] ?? $query['place_id'] ?? null;
                if (is_string($q) && preg_match('/^[A-Za-z0-9_-]{10,190}$/D', $q)) $ids[$q] = true;
                $path = rawurldecode((string)($parts['path'] ?? ''));
                if (preg_match('/!19s([A-Za-z0-9_-]{10,190})(?:[!\/]|$)/', $path, $m)) $ids[$m[1]] = true;
            }
        };
        $collect($item);
        if (is_array($item['apify_raw'] ?? null)) $collect($item['apify_raw']);
        return array_keys($ids);
    }
}
