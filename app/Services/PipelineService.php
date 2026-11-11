<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use InvalidArgumentException;
use Throwable;

/** Collection is evidence, not verification. Publication and changes require a human decision. */
final class PipelineService
{
    public static function distanceMeters(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2): ?float
    {
        if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) return null;
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;
        return round(12742000 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a))), 2);
    }

    public static function canonicalNameKey(string $name): string
    {
        $name = SlugService::toEnglishDigits(SlugService::normalizePersianText($name));
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($name, 'UTF-8'));
    }

    /**
     * کلید هسته نام کسب‌وکار بدون پیشوندهای عمومی صنفی (کافه، رستوران، سالن زیبایی، هتل و...)
     * برای تشخیص تکراری بین OSM («کافه بهار») و Google Maps/CSV («کافه و رستوران بهار»).
     */
    public static function coreBusinessNameKey(string $name): string
    {
        $clean = mb_strtolower(SlugService::toEnglishDigits(SlugService::normalizePersianText($name)), 'UTF-8');
        $clean = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $clean);
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));
        $prefixes = [
            'کافه و رستوران', 'کافه رستوران', 'رستوران سنتی', 'کافه قنادی', 'کافه بستنی',
            'فست فود', 'کافه', 'رستوران', 'کبابی',
            'سالن زیبایی و مراقبت پوست', 'سالن زیبایی', 'آرایشگاه زنانه', 'آرایشگاه مردانه', 'آرایشگاه', 'مرکز زیبایی',
            'کلینیک زیبایی و دندانپزشکی', 'کلینیک دندانپزشکی', 'کلینیک زیبایی', 'مطب دندانپزشکی', 'دندانپزشکی', 'کلینیک',
            'اقامتگاه بومگردی', 'اقامتگاه بوم گردی', 'مجتمع اقامتی', 'اقامتگاه', 'هتل آپارتمان', 'هتل', 'ویلا', 'کلبه',
            'جاذبه گردشگری', 'پارک جنگلی', 'موزه',
            'cafe restaurant', 'cafe', 'restaurant', 'beauty salon', 'dental clinic', 'clinic', 'ecolodge', 'hotel', 'villa',
        ];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($clean, $prefix . ' ')) {
                $rest = trim(mb_substr($clean, mb_strlen($prefix, 'UTF-8'), null, 'UTF-8'));
                $compactRest = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $rest);
                if (mb_strlen($compactRest, 'UTF-8') >= 3) {
                    return $compactRest;
                }
            }
        }
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $clean);
    }

    /**
     * استخراج متن ساده هسته نام (با فاصله) برای جستجوی LIKE ایمن در شهر
     */
    public static function coreSearchPhrase(string $name): string
    {
        $clean = mb_strtolower(SlugService::toEnglishDigits(SlugService::normalizePersianText($name)), 'UTF-8');
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $clean)));
        foreach ([
            'کافه و رستوران', 'کافه رستوران', 'رستوران سنتی', 'فست فود', 'کافه', 'رستوران',
            'سالن زیبایی', 'آرایشگاه زنانه', 'آرایشگاه', 'کلینیک دندانپزشکی', 'کلینیک زیبایی',
            'مطب دندانپزشکی', 'دندانپزشکی', 'کلینیک', 'اقامتگاه بومگردی', 'اقامتگاه', 'هتل', 'ویلا',
        ] as $prefix) {
            if (str_starts_with($clean, $prefix . ' ')) {
                $rest = trim(mb_substr($clean, mb_strlen($prefix, 'UTF-8'), null, 'UTF-8'));
                if (mb_strlen($rest, 'UTF-8') >= 3) {
                    return $rest;
                }
            }
        }
        return $clean;
    }

    /**
     * Input source IDs must be stable; a name alone is not a reliable identity.
     * Known sources are updated in place. Cross-source matches require the same name
     * and either close coordinates or a shared, valid phone when coordinates are absent.
     */
    public static function processIncomingRecord(
        int $cityId, int $defaultCategoryId, string $provider, array $rawItem,
        string $cityPublishMode = 'manual', float $minScoreThreshold = 0.0, array $options = []
    ): array {
        $name = mb_substr(SlugService::normalizePersianText((string) ($rawItem['name'] ?? '')), 0, 200);
        $key = self::canonicalNameKey($name);
        $sourceId = trim((string) ($rawItem['source_id'] ?? ''));
        if ($cityId <= 0 || $defaultCategoryId <= 0 || $key === '' || $sourceId === '' || strlen($sourceId) > 190
            || !preg_match('/^[a-z0-9_-]{1,50}$/', $provider)) {
            throw new InvalidArgumentException('رکورد فاقد نام، شهر، دسته یا شناسه معتبر منبع است.');
        }
        $address = SlugService::normalizePersianText((string) ($rawItem['address'] ?? ''));
        $hours = SlugService::normalizePersianText((string) ($rawItem['hours'] ?? ''));
        $lat = isset($rawItem['lat']) && is_numeric($rawItem['lat']) ? (float) $rawItem['lat'] : null;
        $lng = isset($rawItem['lng']) && is_numeric($rawItem['lng']) ? (float) $rawItem['lng'] : null;
        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) $lat = $lng = null;
        $phones = [];
        foreach ((array) ($rawItem['phones'] ?? []) as $phone) {
            $normalized = SlugService::normalizePhone((string) $phone);
            if ($normalized !== '' && !in_array($normalized, $phones, true)) $phones[] = $normalized;
        }
        $closed = !empty($rawItem['permanently_closed']) || str_contains($hours, 'دائماً بسته');
        $rating = isset($rawItem['rating']) && is_numeric($rawItem['rating']) ? (float) $rawItem['rating'] : null;
        if ($rating !== null && ($rating < 1 || $rating > 5)) $rating = null;
        $reviews = isset($rawItem['reviews_count']) && is_numeric($rawItem['reviews_count'])
            ? max(0, (int) $rawItem['reviews_count']) : null;
        $hasRatingPair = $rating !== null && $reviews !== null;
        $score = $rating !== null && $reviews !== null && $reviews > 0 ? $rating : 0.0;
        $manualImport = !empty($options['manual_import']);
        $targetId = (int) ($options['target_business_id'] ?? 0);
        $sourceTime = $manualImport ? (string) ($options['source_time'] ?? '') : null;
        if ($manualImport && $sourceTime === '') throw new InvalidArgumentException('تاریخ دریافت منبع معتبر نیست.');
        $raw = json_encode($rawItem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($raw === false) throw new InvalidArgumentException('داده منبع قابل ذخیره نیست.');

        $pdo = DB::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $source = DB::fetchOne(
                "SELECT s.* FROM business_sources s WHERE s.provider = :p AND s.source_id = :sid LIMIT 1 FOR UPDATE",
                ['p' => $provider, 'sid' => $sourceId]
            );
            $existing = $source ? DB::fetchOne('SELECT * FROM businesses WHERE id=:id FOR UPDATE', ['id'=>(int)$source['business_id']]) : null;
            if ($source && !$existing) throw new InvalidArgumentException('رکورد منبع به کسب‌وکاری ناموجود متصل است.');
            if ($existing !== null && (int) $existing['city_id'] !== $cityId) {
                throw new InvalidArgumentException('این شناسه منبع قبلاً برای شهر دیگری ثبت شده است.');
            }
            if ($manualImport && $source) {
                if ($targetId > 0 && $targetId !== (int)$existing['id']) throw new InvalidArgumentException('شناسه منبع به کسب‌وکار دیگری متصل است.');
                if ($sourceTime <= (string)$source['fetched_at']) throw new InvalidArgumentException('این داده از نسخه ثبت‌شده جدیدتر نیست.');
            }
            if ($source) {
                // Never combine one half of an aggregate with a missing half from a later observation.
                if (!$hasRatingPair) {
                    $rating = $source['rating'] !== null ? (float)$source['rating'] : null;
                    $reviews = $source['reviews_count'] !== null ? (int)$source['reviews_count'] : null;
                }
                if ($source['rating'] !== null && (int)($source['reviews_count'] ?? 0) > 0) {
                    DB::query('INSERT IGNORE INTO business_source_rating_dates (business_source_id,observed_at) VALUES (:id,:observed)',
                        ['id'=>(int)$source['id'],'observed'=>$source['fetched_at']]);
                }
                // Preserve prior raw observations, including any previously collected review text.
                DB::query('INSERT INTO business_source_history (business_source_id,raw,rating,reviews_count,fetched_at,archived_at) VALUES (:id,:raw,:rating,:reviews,:fetched,NOW())',
                    ['id'=>(int)$source['id'],'raw'=>$source['raw'],'rating'=>$source['rating'],'reviews'=>$source['reviews_count'],'fetched'=>$source['fetched_at']]);
            }
            if ($manualImport && !$existing && $targetId > 0) {
                $existing = DB::fetchOne('SELECT * FROM businesses WHERE id=:id AND city_id=:city LIMIT 1 FOR UPDATE',
                    ['id'=>$targetId,'city'=>$cityId]);
                if (!$existing) throw new InvalidArgumentException('کسب‌وکار انتخاب‌شده در این شهر یافت نشد.');
            }
            $borderlineDuplicates = [];
            if (!$manualImport && $existing === null) {
                $coreKey = self::coreBusinessNameKey($name);
                $corePhrase = self::coreSearchPhrase($name);
                $candWhere = ['b.normalized_name = :name'];
                $candParams = ['city' => $cityId, 'cat_pref' => $defaultCategoryId, 'name' => $key];
                if ($lat !== null && $lng !== null) {
                    $candWhere[] = '(b.lat BETWEEN :minlat AND :maxlat AND b.lng BETWEEN :minlng AND :maxlng)';
                    $candParams += [
                        'minlat' => $lat - 0.0020,
                        'maxlat' => $lat + 0.0020,
                        'minlng' => $lng - 0.0025,
                        'maxlng' => $lng + 0.0025,
                    ];
                }
                $candidates = DB::fetchAll(
                    'SELECT b.*, cat.name_fa AS category_name
                     FROM businesses b
                     LEFT JOIN categories cat ON cat.id = b.category_id
                     WHERE b.city_id = :city AND (' . implode(' OR ', $candWhere) . ')
                     ORDER BY (b.category_id = :cat_pref) DESC, b.id ASC
                     LIMIT 80',
                    $candParams
                );
                if (mb_strlen($coreKey, 'UTF-8') >= 3) {
                    $escapedCoreKey = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $coreKey);
                    $coreRows = DB::fetchAll(
                        'SELECT b.*, cat.name_fa AS category_name
                         FROM businesses b
                         LEFT JOIN categories cat ON cat.id = b.category_id
                         WHERE b.city_id = :city AND b.normalized_name LIKE :ck
                         ORDER BY b.id ASC LIMIT 50',
                        ['city' => $cityId, 'ck' => '%' . $escapedCoreKey]
                    );
                    $seenIds = array_column($candidates, 'id');
                    foreach ($coreRows as $cr) {
                        if (!in_array($cr['id'], $seenIds, false) && self::coreBusinessNameKey((string) ($cr['name'] ?? '')) === $coreKey) {
                            $candidates[] = $cr;
                            $seenIds[] = $cr['id'];
                        }
                    }
                }
                $phoneMatchedIds = [];
                if ($phones) {
                    $pKeys = [];
                    $pParams = ['city_p' => $cityId];
                    foreach ($phones as $pi => $pv) {
                        $pKeys[] = ':ph' . $pi;
                        $pParams['ph' . $pi] = $pv;
                    }
                    $phoneCands = DB::fetchAll(
                        "SELECT DISTINCT b.*, cat.name_fa AS category_name
                         FROM businesses b
                         INNER JOIN business_contacts bc ON bc.business_id = b.id AND bc.type IN ('phone','mobile')
                         LEFT JOIN categories cat ON cat.id = b.category_id
                         WHERE b.city_id = :city_p AND bc.value IN (" . implode(',', $pKeys) . ")
                         ORDER BY b.id ASC LIMIT 40",
                        $pParams
                    );
                    $seenCandIds = array_column($candidates, 'id');
                    foreach ($phoneCands as $pc) {
                        $phoneMatchedIds[(int) $pc['id']] = true;
                        if (!in_array($pc['id'], $seenCandIds, false)) {
                            $candidates[] = $pc;
                        }
                    }
                }

                foreach ($candidates as $candidate) {
                    $candId = (int) $candidate['id'];
                    $distance = self::distanceMeters(
                        $lat,
                        $lng,
                        $candidate['lat'] !== null ? (float) $candidate['lat'] : null,
                        $candidate['lng'] !== null ? (float) $candidate['lng'] : null
                    );
                    $samePhone = isset($phoneMatchedIds[$candId]);
                    if (!$samePhone && $phones) {
                        $known = DB::fetchAll("SELECT value FROM business_contacts WHERE business_id = :id AND type IN ('phone','mobile')", ['id' => $candId]);
                        if (array_intersect($phones, array_column($known, 'value'))) {
                            $samePhone = true;
                        }
                    }
                    $sameExact = ((string) ($candidate['normalized_name'] ?? '') === $key);
                    $candCore = self::coreBusinessNameKey((string) ($candidate['name'] ?? ''));
                    $sameCore = ($coreKey !== '' && mb_strlen($coreKey, 'UTF-8') >= 3 && $candCore === $coreKey);

                    // 1. High-confidence cross-category / cross-source match -> attach source directly without creating duplicate
                    if ($sameExact && (($distance !== null && $distance <= 80) || ($distance === null && $samePhone))) {
                        $existing = $candidate;
                        break;
                    }
                    if ($sameCore && (($distance !== null && $distance <= 50) || $samePhone)) {
                        $existing = $candidate;
                        break;
                    }

                    // 2. Borderline duplicate -> surface in Review Queue ("تکراری‌های مرزی") for 1-click human decision
                    $closeCoreSubstring = false;
                    if ($distance !== null && $distance <= 35 && mb_strlen($coreKey, 'UTF-8') >= 4 && mb_strlen($candCore, 'UTF-8') >= 4) {
                        $closeCoreSubstring = str_contains($coreKey, $candCore) || str_contains($candCore, $coreKey);
                    }
                    if (
                        $samePhone
                        || ($sameCore && ($distance === null || $distance <= 150))
                        || $closeCoreSubstring
                    ) {
                        $borderlineDuplicates[] = [
                            'id' => $candId,
                            'name' => (string) $candidate['name'],
                            'category_id' => (int) $candidate['category_id'],
                            'category_name' => (string) ($candidate['category_name'] ?? ''),
                            'distance' => $distance,
                            'same_phone' => $samePhone,
                            'status' => (string) $candidate['status'],
                        ];
                    }
                }
            }
            if ($existing !== null) {
                $id = (int) $existing['id'];
                if ($source) {
                    DB::query("UPDATE business_sources SET raw=:raw,rating=COALESCE(:rating,rating),reviews_count=COALESCE(:reviews,reviews_count),fetched_at=COALESCE(:fetched,NOW()) WHERE id=:source_id AND business_id=:id",
                        ['id'=>$id,'source_id'=>(int)$source['id'],'raw'=>$raw,'rating'=>$rating,'reviews'=>$reviews,'fetched'=>$sourceTime]);
                } else {
                    // A concurrent import of the same source must fail, not attach its data to another business.
                    DB::query("INSERT INTO business_sources (business_id,provider,source_id,raw,rating,reviews_count,fetched_at) VALUES (:id,:p,:sid,:raw,:rating,:reviews,COALESCE(:fetched,NOW()))",
                        ['id'=>$id,'p'=>$provider,'sid'=>$sourceId,'raw'=>$raw,'rating'=>$rating,'reviews'=>$reviews,'fetched'=>$sourceTime]);
                }
                if ($hasRatingPair && $reviews > 0) self::recordRatingDate($provider, $sourceId, $sourceTime);
                DB::query("UPDATE businesses SET last_collected_at = NOW() WHERE id = :id", ['id' => $id]);
                foreach (['name' => $name, 'address' => $address, 'hours' => $hours] as $field => $value) {
                    if ($value !== '' && $value !== (string) ($existing[$field] ?? '')) {
                        self::enqueueChange($id, $field, (string) ($existing[$field] ?? ''), $value, $provider);
                    }
                }
                // S17: قفل مختصات دستی — اگر ادمین نقطه را دستی تأیید کرده ('manual')،
                // هیچ ایمپورتی حق پیشنهاد یا تغییر مختصات ندارد؛ فقط ثبت منبع/امتیاز ادامه می‌یابد.
                $coordsLocked = (($existing['coords_source'] ?? null) === 'manual');
                if ($lat !== null && $lng !== null && !$coordsLocked && ($existing['lat'] === null || $existing['lng'] === null)) {
                    self::enqueueChange($id, 'coordinates', ($existing['lat'] ?? '') . ',' . ($existing['lng'] ?? ''), $lat . ',' . $lng, $provider);
                } elseif ($lat !== null && $lng !== null && !$coordsLocked && $existing['lat'] !== null && $existing['lng'] !== null) {
                    $distance = self::distanceMeters($lat, $lng, (float) $existing['lat'], (float) $existing['lng']);
                    if ($distance !== null && $distance > 30) {
                        self::enqueueChange($id, 'coordinates', $existing['lat'] . ',' . $existing['lng'], $lat . ',' . $lng, $provider);
                    }
                }
                if ($phones) {
                    $old = DB::fetchOne("SELECT value FROM business_contacts WHERE business_id=:id AND type IN ('phone','mobile') ORDER BY is_primary DESC,id ASC LIMIT 1", ['id'=>$id]);
                    $oldPhone = (string) ($old['value'] ?? '');
                    if ($phones[0] !== $oldPhone) self::enqueueChange($id, 'phone', $oldPhone, $phones[0], $provider);
                }
                if ($closed && $existing['status'] === 'published') {
                    self::enqueueChange($id, 'status', 'published', 'hidden', $provider);
                }
                if ($ownsTransaction) $pdo->commit();
                return ['outcome'=>'duplicate','reason_code'=>'existing_source_or_nearby_match','business_id'=>$id,
                    'is_new'=>false,'in_review_queue'=>true,'score'=>(float) $existing['internal_score']];
            }

            $reason = $closed ? 'permanently_closed' : (!$phones ? 'missing_valid_phone' : 'manual_review');
            $slug = SlugService::ensureUniqueSlug('businesses', SlugService::suggestSlug($name, 'biz'));
            // S17: منشأ مختصات ثبت‌شدهٔ ایمپورت (provenance) از همان ابتدا ذخیره می‌شود
            $importCoordsSource = ($lat !== null && $lng !== null) ? MapLinks::sanitizeProvider($provider) : null;
            DB::query("INSERT INTO businesses (name, normalized_name, slug, category_id, city_id, address, lat, lng, coords_source, hours,
                status, tier, internal_score, confidence, publish_reason, last_collected_at, created_at, updated_at)
                VALUES (:name,:norm,:slug,:cat,:city,:addr,:lat,:lng,:coords_source,:hours,'pending_review','C',:score,'low',:reason,NOW(),NOW(),NOW())",
                ['name'=>$name,'norm'=>$key,'slug'=>$slug,'cat'=>$defaultCategoryId,'city'=>$cityId,
                 'addr'=>$address !== '' ? $address : null,'lat'=>$lat,'lng'=>$lng,'coords_source'=>$importCoordsSource,'hours'=>$hours !== '' ? $hours : null,
                 'score'=>$score,'reason'=>$reason]);
            $id = DB::lastInsertId();
            DB::query("INSERT INTO business_sources (business_id,provider,source_id,raw,rating,reviews_count,fetched_at) VALUES (:id,:p,:sid,:raw,:rating,:reviews,COALESCE(:fetched,NOW()))",
                ['id'=>$id,'p'=>$provider,'sid'=>$sourceId,'raw'=>$raw,'rating'=>$rating,'reviews'=>$reviews,'fetched'=>$sourceTime]);
            if ($hasRatingPair && $reviews > 0) self::recordRatingDate($provider, $sourceId, $sourceTime);
            foreach ($phones as $index => $phone) {
                DB::query("INSERT INTO business_contacts (business_id,type,value,is_primary,source,confidence,last_verified_at) VALUES (:id,:type,:value,:primary,:source,'low',NULL)",
                    ['id'=>$id,'type'=>str_starts_with($phone, '+989') ? 'mobile' : 'phone','value'=>$phone,'primary'=>$index === 0 ? 1 : 0,'source'=>$provider]);
            }
            self::enqueueReviewIfNotExists('business', $id, $reason, [
                'business_id'=>$id, 'business_name'=>$name, 'address'=>$address, 'phones'=>$phones,
                'raw_phones'=>(array) ($rawItem['phones'] ?? []), 'provider'=>$provider, 'source_id'=>$sourceId,
                'source_url'=>(string) ($rawItem['source_url'] ?? ''), 'permanently_closed'=>$closed,
                'duplicate_candidates'=>$borderlineDuplicates,
            ]);
            if ($borderlineDuplicates) {
                self::enqueueReviewIfNotExists('duplicate', $id, 'potential_duplicate', [
                    'business_id'=>$id,
                    'business_name'=>$name,
                    'provider'=>$provider,
                    'source_id'=>$sourceId,
                    'primary_candidate_id'=>(int)$borderlineDuplicates[0]['id'],
                    'candidates'=>array_slice($borderlineDuplicates, 0, 5),
                ]);
            }
            if ($ownsTransaction) $pdo->commit();
            return ['outcome'=>'pending_review','reason_code'=>$reason,'business_id'=>$id,'is_new'=>true,'in_review_queue'=>true,'score'=>$score];
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function recordRatingDate(string $provider, string $sourceId, ?string $sourceTime): void
    {
        DB::query("INSERT INTO business_source_rating_dates (business_source_id,observed_at)
            SELECT id,COALESCE(:observed,NOW()) FROM business_sources WHERE provider=:p AND source_id=:sid
            ON DUPLICATE KEY UPDATE observed_at=VALUES(observed_at)", ['observed'=>$sourceTime,'p'=>$provider,'sid'=>$sourceId]);
    }

    private static function enqueueChange(int $id, string $field, string $old, string $new, string $provider): void
    {
        $pending = DB::fetchOne("SELECT id FROM change_log WHERE business_id=:bid AND field=:field AND new_value=:value AND status='pending' LIMIT 1",
            ['bid'=>$id, 'field'=>$field, 'value'=>$new]);
        if ($pending) return;
        DB::query("INSERT INTO change_log (business_id,field,old_value,new_value,source,status,created_at) VALUES (:bid,:field,:old,:new,:source,'pending',NOW())",
            ['bid'=>$id,'field'=>$field,'old'=>$old,'new'=>$new,'source'=>$provider]);
        $changeId = DB::lastInsertId();
        DB::query("INSERT INTO review_queue (kind,target_id,reason_code,payload_json,status,created_at) VALUES ('change',:id,:reason,:payload,'pending',NOW())",
            ['id'=>$id,'reason'=>$field . '_changed','payload'=>json_encode(['business_id'=>$id,'change_id'=>$changeId,
                'field'=>$field,'old_value'=>$old,'new_value'=>$new,'source'=>$provider], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    public static function enqueueReviewIfNotExists(string $kind, int $targetId, string $reasonCode, array $payload): void
    {
        if (DB::fetchOne("SELECT id FROM review_queue WHERE kind=:kind AND target_id=:id AND status='pending' LIMIT 1", ['kind'=>$kind,'id'=>$targetId])) return;
        DB::query("INSERT INTO review_queue (kind,target_id,reason_code,payload_json,status,created_at) VALUES (:kind,:id,:reason,:payload,'pending',NOW())",
            ['kind'=>$kind,'id'=>$targetId,'reason'=>$reasonCode,'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    // Kept for older callers; no fabricated ranking or tier is derived from missing ratings.
    public static function recalculateTiersForCityCategory(int $cityId, int $categoryId): void {}
}
