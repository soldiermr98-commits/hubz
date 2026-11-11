<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\DB;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/** Manual, source-agnostic JSON imports. Preview never writes; ambiguous links need an explicit decision. */
final class ManualImportService
{
    public const MAX_RECORDS = 500;
    public const BATCH_SIZE = 25;

    public static function options(array $input): array
    {
        $cityId = (int)($input['city_id'] ?? 0);
        $categoryId = (int)($input['category_id'] ?? 0);
        $city = DB::fetchOne('SELECT id,name_fa FROM cities WHERE id=:id AND is_active=1', ['id'=>$cityId]);
        $category = DB::fetchOne('SELECT id FROM categories WHERE id=:id AND is_active=1', ['id'=>$categoryId]);
        $selected = $input['source_categories'] ?? null;
        if (!$city || !$category) throw new InvalidArgumentException('شهر و دسته فعال را انتخاب کنید.');
        if (!is_array($selected) || !$selected || count($selected) > 100) throw new InvalidArgumentException('حداقل یک دسته از دسته‌های فایل را انتخاب کنید.');
        foreach ($selected as $item) {
            if (!is_string($item) || mb_strlen($item, 'UTF-8') > 200) throw new InvalidArgumentException('دسته فایل معتبر نیست.');
        }
        $minRating = $input['min_rating'] ?? 0;
        $minReviews = $input['min_reviews'] ?? 0;
        if (!is_numeric($minRating) || (float)$minRating < 0 || (float)$minRating > 5
            || !is_numeric($minReviews) || (float)$minReviews < 0 || (float)$minReviews > 4294967295
            || floor((float)$minReviews) !== (float)$minReviews) {
            throw new InvalidArgumentException('حداقل امتیاز یا تعداد نظر معتبر نیست.');
        }
        return ['city_id'=>$cityId, 'category_id'=>$categoryId, 'city_name'=>(string)$city['name_fa'],
            'source_categories'=>array_values(array_unique($selected)), 'min_rating'=>(float)$minRating, 'min_reviews'=>(int)$minReviews];
    }

    public static function preview(array $items, array $options): array
    {
        if (!$items || count($items) > self::MAX_RECORDS || !array_is_list($items)) {
            throw new InvalidArgumentException('فایل باید یک آرایه JSON شامل ۱ تا ۵۰۰ رکورد باشد.');
        }
        $seen = [];
        foreach ($items as $item) foreach (self::identityKeys($item) as $key) $seen[$key] = ($seen[$key] ?? 0) + 1;
        $rows = [];
        foreach ($items as $index=>$item) {
            $duplicate = false;
            foreach (self::identityKeys($item) as $key) if (($seen[$key] ?? 0) > 1) $duplicate = true;
            $rows[] = $duplicate
                ? self::row($index, is_array($item) ? $item : [], 'invalid', 'شناسه یا Place ID در همین فایل تکرار شده است.')
                : self::inspect($index, $item, $options);
        }
        return $rows;
    }

    private static function identityKeys(mixed $item): array
    {
        if (!is_array($item) || !is_string($item['source'] ?? null) || !is_string($item['source_id'] ?? null)) return [];
        $provider = trim($item['source']); $id = trim($item['source_id']);
        $keys = $id !== '' ? [$provider . "\0id\0" . $id] : [];
        if ($provider === 'google_maps') foreach (ApifyImportService::placeIds($item) as $pid) $keys[] = $provider . "\0place\0" . $pid;
        return $keys;
    }

    /** Resolve an exact Place ID against legacy FID-based sources. Ambiguous aliases never merge. */
    private static function sourceForIdentity(array $item, string $provider, string $sourceId): array
    {
        $ids = $provider === 'google_maps' ? ApifyImportService::placeIds($item) : [];
        if (count($ids) > 1) return ['conflict'=>true];
        if (!$ids) {
            $one = DB::fetchOne('SELECT s.business_id,s.source_id,s.fetched_at,b.name,b.city_id,b.category_id FROM business_sources s LEFT JOIN businesses b ON b.id=s.business_id WHERE s.provider=:p AND BINARY s.source_id=BINARY :sid LIMIT 1', ['p'=>$provider,'sid'=>$sourceId]);
            return ['source'=>$one];
        }
        $pid = $ids[0];
        // LOCATE is literal and case-sensitive; LIKE would treat underscores in Place IDs as wildcards.
        $found = DB::fetchAll('SELECT s.business_id,s.source_id,s.fetched_at,s.raw,b.name,b.city_id,b.category_id FROM business_sources s LEFT JOIN businesses b ON b.id=s.business_id WHERE s.provider=:p AND (BINARY s.source_id=BINARY :sid OR BINARY s.source_id=BINARY :pid OR LOCATE(BINARY :needle, BINARY s.raw)>0) ORDER BY s.id LIMIT 102',
            ['p'=>$provider,'sid'=>$sourceId,'pid'=>$pid,'needle'=>$pid]);
        if (count($found) > 100) return ['conflict'=>true];
        $matches = [];
        foreach ($found as $source) {
            $raw = json_decode((string)($source['raw'] ?? ''), true);
            $rawIds = is_array($raw) ? ApifyImportService::placeIds($raw) : [];
            if ((string)$source['source_id'] === $sourceId || (string)$source['source_id'] === $pid || in_array($pid, $rawIds, true)) {
                if (count($rawIds) > 1 || ((string)$source['source_id'] === $sourceId && $rawIds && !in_array($pid, $rawIds, true))) return ['conflict'=>true];
                $matches[(string)$source['source_id']] = $source;
            }
        }
        if (count($matches) > 1) return ['conflict'=>true];
        return ['source'=>$matches ? reset($matches) : null];
    }

    private static function row(int $index, array $item, string $status, string $message, array $extra = []): array
    {
        return array_merge(['index'=>$index, 'name'=>is_string($item['name'] ?? null) ? $item['name'] : '',
            'source_category'=>is_string($item['category'] ?? null) ? $item['category'] : '',
            'source'=>is_string($item['source'] ?? null) ? $item['source'] : '',
            'source_id'=>is_string($item['source_id'] ?? null) ? $item['source_id'] : '',
            'rating'=>is_numeric($item['rating'] ?? null) ? (float)$item['rating'] : null,
            'reviews_count'=>is_numeric($item['reviews_count'] ?? null) ? (int)$item['reviews_count'] : null,
            'status'=>$status, 'message'=>$message, 'candidates'=>[]], $extra);
    }

    private static function inspect(int $index, mixed $item, array $options): array
    {
        if (!is_array($item) || array_is_list($item)) return self::row($index, [], 'invalid', 'رکورد باید یک شیء JSON باشد.');
        foreach (['name','source','source_id','city','category','fetched_at'] as $field) {
            if (!isset($item[$field]) || !is_string($item[$field])) return self::row($index, $item, 'invalid', "فیلد {$field} لازم است.");
        }
        $name = trim($item['name']); $provider = trim($item['source']); $sourceId = trim($item['source_id']);
        $sourceCategory = $item['category'];
        if (!in_array($sourceCategory, $options['source_categories'], true)) return self::row($index, $item, 'filtered', 'دسته فایل انتخاب نشده است.');
        if (PipelineService::canonicalNameKey($item['city']) !== PipelineService::canonicalNameKey($options['city_name'])) {
            return self::row($index, $item, 'filtered', 'شهر رکورد با شهر انتخاب‌شده یکسان نیست.');
        }
        if ($name === '' || mb_strlen($name, 'UTF-8') > 200 || PipelineService::canonicalNameKey($name) === ''
            || !preg_match('/^[a-z0-9_-]{1,50}$/', $provider) || $sourceId === '' || strlen($sourceId) > 190) {
            return self::row($index, $item, 'invalid', 'نام، منبع یا شناسه منبع معتبر نیست.');
        }
        foreach (['address','hours','source_url','website'] as $field) {
            if (isset($item[$field]) && !is_string($item[$field])) return self::row($index, $item, 'invalid', "فیلد {$field} معتبر نیست.");
        }
        if (isset($item['phones']) && (!is_array($item['phones']) || count($item['phones']) > 30
            || count(array_filter($item['phones'], 'is_string')) !== count($item['phones']))) {
            return self::row($index, $item, 'invalid', 'شماره‌ها باید آرایه‌ای از متن باشند.');
        }
        $rating = $item['rating'] ?? null; $count = $item['reviews_count'] ?? null;
        if (($rating !== null && (!is_numeric($rating) || (float)$rating < 1 || (float)$rating > 5))
            || ($count !== null && (!is_numeric($count) || (float)$count < 0 || (float)$count > 4294967295 || floor((float)$count) !== (float)$count))) {
            return self::row($index, $item, 'invalid', 'امتیاز باید بین ۱ تا ۵ و تعداد نظر یک عدد صحیح نامنفی باشد.');
        }
        if (($options['min_rating'] > 0 && ($rating === null || (float)$rating < $options['min_rating']))
            || ($options['min_reviews'] > 0 && ($count === null || (int)$count < $options['min_reviews']))) {
            return self::row($index, $item, 'filtered', 'کمتر از حداقل امتیاز یا تعداد نظر انتخاب‌شده است.');
        }
        $lat = $item['lat'] ?? null; $lng = $item['lng'] ?? null;
        if (($lat === null) !== ($lng === null) || ($lat !== null && (!is_numeric($lat) || !is_numeric($lng) || abs((float)$lat) > 90 || abs((float)$lng) > 180))) {
            return self::row($index, $item, 'invalid', 'مختصات باید هر دو معتبر باشند یا هر دو خالی باشند.');
        }
        try {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $item['fetched_at'])) throw new InvalidArgumentException();
            $date = new DateTimeImmutable($item['fetched_at']);
            $errors = DateTimeImmutable::getLastErrors();
            if (($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->getTimestamp() > time() + 86400) throw new InvalidArgumentException();
            $sourceTime = $date->setTimezone(new DateTimeZone('+03:30'))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return self::row($index, $item, 'invalid', 'fetched_at باید تاریخ ISO 8601 با منطقه زمانی باشد و در آینده نباشد.');
        }
        if ($provider === 'google_maps' && str_starts_with($sourceId, 'gpid_')) {
            $pids = ApifyImportService::placeIds($item);
            if (count($pids) !== 1 || $sourceId !== 'gpid_' . hash('sha256', $pids[0])) return self::row($index, $item, 'conflict', 'شناسه هش‌شده و Place ID رکورد با هم سازگار نیستند.');
        }
        $identity = self::sourceForIdentity($item, $provider, $sourceId);
        if (!empty($identity['conflict'])) return self::row($index, $item, 'conflict', 'شناسه‌های Google با چند منبع یا با هم تعارض دارند؛ ابتدا دستی بررسی کنید.');
        $source = $identity['source'] ?? null;
        if ($source) {
            // Keep the existing FID or ID; never change the source key merely because a Place ID was supplied.
            $item['source_id'] = (string)$source['source_id'];
            if (!$source['name'] || (int)$source['city_id'] !== $options['city_id']) {
                return self::row($index, $item, 'conflict', 'این شناسه در شهر دیگری ثبت شده است.');
            }
            if ($sourceTime <= (string)$source['fetched_at']) return self::row($index, $item, 'stale', 'داده ثبت‌شده هم‌زمان یا جدیدتر است.');
            return self::row($index, $item, 'update', 'به‌روزرسانی منبع متصل به ' . $source['name'], ['business_id'=>(int)$source['business_id'], 'source_time'=>$sourceTime]);
        }
        $coreKey = PipelineService::coreBusinessNameKey($name);
        $params = ['city'=>$options['city_id'], 'name'=>PipelineService::canonicalNameKey($name), 'name_exact'=>$name];
        $nearSql = '';
        if ($lat !== null) {
            $nearSql .= ' OR (b.lat BETWEEN :minlat AND :maxlat AND b.lng BETWEEN :minlng AND :maxlng)';
            $params += ['minlat'=>(float)$lat-0.0018,'maxlat'=>(float)$lat+0.0018,'minlng'=>(float)$lng-0.0022,'maxlng'=>(float)$lng+0.0022];
        }
        $found = DB::fetchAll("SELECT b.id,b.name,b.address,b.lat,b.lng,b.status,b.category_id,cat.name_fa AS category_name FROM businesses b
            LEFT JOIN categories cat ON cat.id=b.category_id
            WHERE b.city_id=:city AND (b.normalized_name=:name OR b.name=:name_exact {$nearSql}) ORDER BY b.id ASC LIMIT 101", $params);
        if (count($found) > 100) return self::row($index, $item, 'conflict', 'تعداد نام‌های مشابه زیاد است؛ ابتدا رکورد را دستی بررسی کنید.');
        if (mb_strlen($coreKey, 'UTF-8') >= 3 && $coreKey !== $params['name']) {
            $escapedCoreKey = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $coreKey);
            $coreRows = DB::fetchAll("SELECT b.id,b.name,b.address,b.lat,b.lng,b.status,b.category_id,cat.name_fa AS category_name FROM businesses b
                LEFT JOIN categories cat ON cat.id=b.category_id
                WHERE b.city_id=:city AND b.normalized_name LIKE :ck ORDER BY b.id ASC LIMIT 50",
                ['city'=>$options['city_id'], 'ck'=>'%' . $escapedCoreKey]);
            foreach ($coreRows as $cr) {
                if (PipelineService::coreBusinessNameKey((string)$cr['name']) === $coreKey) {
                    $found[] = $cr;
                }
            }
        }
        $matchedPhones = [];
        $phones = array_values(array_unique(array_filter(array_map([SlugService::class, 'normalizePhone'], $item['phones'] ?? []))));
        if ($phones) {
            $phoneParams = ['city_phone'=>$options['city_id']]; $phoneKeys = [];
            foreach ($phones as $i=>$phone) { $phoneKeys[] = ':phone' . $i; $phoneParams['phone' . $i] = $phone; }
            $phoneRows = DB::fetchAll('SELECT DISTINCT b.id,b.name,b.address,b.lat,b.lng,b.status,b.category_id,cat.name_fa AS category_name FROM businesses b
                INNER JOIN business_contacts bc ON bc.business_id=b.id AND bc.type IN (\'phone\',\'mobile\')
                LEFT JOIN categories cat ON cat.id=b.category_id WHERE b.city_id=:city_phone AND bc.value IN (' . implode(',', $phoneKeys) . ') ORDER BY b.id ASC LIMIT 101', $phoneParams);
            if (count($phoneRows) > 100) return self::row($index, $item, 'conflict', 'تعداد شماره‌های مشابه زیاد است؛ ابتدا رکورد را دستی بررسی کنید.');
            foreach ($phoneRows as $b) { $matchedPhones[(int)$b['id']] = true; $found[] = $b; }
        }
        $candidates = []; $seenCandidates = [];
        foreach ($found as $b) {
            if (isset($seenCandidates[(int)$b['id']])) continue;
            $seenCandidates[(int)$b['id']] = true;
            $distance = PipelineService::distanceMeters($lat === null ? null : (float)$lat, $lng === null ? null : (float)$lng,
                $b['lat'] === null ? null : (float)$b['lat'], $b['lng'] === null ? null : (float)$b['lng']);
            // Same name, same core name (without generic category prefix), a nearby place, or a shared valid phone are clues, never proof.
            $samePhone = isset($matchedPhones[(int)$b['id']]);
            $sameExactName = PipelineService::canonicalNameKey((string)$b['name']) === $params['name'];
            $sameCoreName = $coreKey !== '' && mb_strlen($coreKey, 'UTF-8') >= 3
                && PipelineService::coreBusinessNameKey((string)$b['name']) === $coreKey
                && ($distance === null || $distance <= 150);
            if (!$samePhone && !$sameExactName && !$sameCoreName && ($distance === null || $distance > 80)) continue;
            $candidates[] = ['id'=>(int)$b['id'], 'name'=>$b['name'], 'address'=>$b['address'], 'status'=>$b['status'], 'distance'=>$distance,
                'category_id'=>(int)$b['category_id'], 'category_name'=>$b['category_name'], 'same_phone'=>$samePhone];
        }
        if ($candidates) return self::row($index, $item, 'review', 'مکان مشابه پیدا شد؛ اتصال، ثبت جداگانه یا رد را انتخاب کنید.', ['candidates'=>$candidates, 'source_time'=>$sourceTime]);
        return self::row($index, $item, 'new', 'رکورد جدید؛ پس از ورود در انتظار بازبینی می‌ماند.', ['source_time'=>$sourceTime]);
    }

    public static function apply(array $entries, array $options): array
    {
        if (!$entries || count($entries) > self::BATCH_SIZE || !array_is_list($entries)) throw new InvalidArgumentException('هر نوبت حداکثر ۲۵ رکورد مجاز است.');
        $result = []; $seen = []; $pipelineAttempted = false;
        try {
            foreach ($entries as $entry) {
                if (!is_array($entry)) { $result[] = ['index'=>-1,'status'=>'skipped','message'=>'رکورد ارسالی معتبر نیست.']; continue; }
                $index = (int)($entry['index'] ?? -1);
                $item = $entry['item'] ?? null;
                $lockName = '';
                if (is_array($item) && ($item['source'] ?? null) === 'google_maps') {
                    $pids = ApifyImportService::placeIds($item);
                    if (count($pids) === 1) {
                        $lockName = 'hhx_google_' . substr(hash('sha256', $pids[0]), 0, 48);
                        $locked = DB::fetchOne('SELECT GET_LOCK(:name, 2) AS acquired', ['name'=>$lockName]);
                        if ((int)($locked['acquired'] ?? 0) !== 1) { $result[] = ['index'=>$index,'status'=>'skipped','message'=>'این شناسه هم‌اکنون در حال پردازش است؛ دوباره پیش‌نمایش بگیرید.']; continue; }
                    }
                }
                try {
                    $row = self::inspect($index, $item, $options);
                    $key = $row['source'] . "\0" . $row['source_id'];
                    if (isset($seen[$key])) { $result[] = ['index'=>$index,'status'=>'skipped','message'=>'شناسه تکراری در همین نوبت.']; continue; }
                    $seen[$key] = true;
                    $decision = (string)($entry['decision'] ?? ''); $target = 0;
                    if ($row['status'] === 'update' && $decision === 'update') {
                        $target = (int)$row['business_id'];
                    } elseif (in_array($row['status'], ['new','review'], true) && $decision === 'new') {
                        $expected = is_array($entry['candidate_ids'] ?? null) ? array_map('intval', $entry['candidate_ids']) : [-1];
                        $current = array_column($row['candidates'], 'id'); sort($expected); sort($current);
                        if ($expected !== $current) { $result[] = ['index'=>$index,'status'=>'skipped','message'=>'نتیجه تطبیق تغییر کرده است؛ دوباره پیش‌نمایش بگیرید.']; continue; }
                    } elseif ($row['status'] === 'review' && preg_match('/^link:(\d+)$/', $decision, $m) && in_array((int)$m[1], array_column($row['candidates'], 'id'), true)) {
                        $target = (int)$m[1];
                    } else {
                        $result[] = ['index'=>$index,'status'=>'skipped','message'=>$row['message'] ?: 'این مورد نیاز به بررسی دوباره دارد.']; continue;
                    }
                    try {
                        // inspect may have resolved a Place ID to an existing legacy FID.
                        $item['source_id'] = $row['source_id'];
                        $pipelineAttempted = true; // A commit can succeed even if its acknowledgement is lost.
                        $saved = PipelineService::processIncomingRecord($options['city_id'], $options['category_id'], trim($item['source']), $item, 'manual', 0.0,
                            ['manual_import'=>true,'target_business_id'=>$target,'source_time'=>$row['source_time']]);
                        $result[] = ['index'=>$index, 'status'=>$saved['is_new'] ? 'created' : 'updated', 'business_id'=>$saved['business_id'],
                            'message'=>$saved['is_new'] ? 'ثبت شد و در انتظار بازبینی است.' : 'منبع ثبت شد؛ تغییر مشخصات برای بازبینی ارسال شد.'];
                    } catch (InvalidArgumentException $e) {
                        $result[] = ['index'=>$index,'status'=>'skipped','message'=>$e->getMessage()];
                    } catch (Throwable $e) {
                        error_log('Manual import failed: ' . $e->getMessage());
                        $result[] = ['index'=>$index,'status'=>'error','message'=>'خطای ذخیره؛ این رکورد ذخیره نشد. دوباره پیش‌نمایش بگیرید.'];
                    }
                } finally {
                    if ($lockName !== '') DB::fetchOne('SELECT RELEASE_LOCK(:name) AS released', ['name'=>$lockName]);
                }
            }
        } finally {
            // Entries commit independently. Also invalidate if a later lock
            // release or an uncertain commit interrupts the batch.
            if ($pipelineAttempted) Cache::flushAll();
        }
        return $result;
    }
}
