<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use Throwable;

/**
 * یکپارچه‌سازی خودکار و ۱ به ۱ بین جدول دسته‌بندی‌های اصلی سایت (categories)
 * و بسته‌های جمع‌آوری OSM/نشان (category_packs + category_pack_items)
 * تا نام دسته‌ها در «جمع‌آوری و شهرها»، «جمع‌آوری و ورود»، «بازبینی» و سایت عمومی ۱۰۰٪ یکسان باشد.
 */
final class CategorySyncService
{
    /**
     * تنظیمات کامل تگ‌های OSM، نشان و کلیدواژه‌ها برای ۵ دسته‌بندی استاندارد حامی‌هاب
     */
    public const CANONICAL_PACK_PRESETS = [
        'cafe-restaurant' => [
            'default_name_fa' => 'کافه و رستوران',
            'queries' => [
                'osm' => ['amenity=cafe', 'amenity=restaurant', 'amenity=fast_food', 'amenity=ice_cream'],
                'neshan' => ['کافه و رستوران', 'کافه', 'رستوران'],
                'gosom' => ['کافه و رستوران', 'کافه', 'رستوران'],
            ],
            'keywords' => ['کافه و رستوران', 'کافه', 'رستوران'],
        ],
        'beauty-salon' => [
            'default_name_fa' => 'سالن زیبایی',
            'queries' => [
                'osm' => ['shop=beauty', 'shop=hairdresser', 'shop=cosmetics'],
                'neshan' => ['سالن زیبایی', 'آرایشگاه'],
                'gosom' => ['سالن زیبایی', 'آرایشگاه زنانه'],
            ],
            'keywords' => ['سالن زیبایی', 'آرایشگاه زنانه'],
        ],
        'clinic-dental' => [
            'default_name_fa' => 'کلینیک و دندانپزشکی',
            'queries' => [
                'osm' => ['amenity=dentist', 'amenity=clinic', 'amenity=doctors', 'healthcare=dentist', 'healthcare=clinic'],
                'neshan' => ['کلینیک و دندانپزشکی', 'دندانپزشکی', 'کلینیک'],
                'gosom' => ['کلینیک دندانپزشکی', 'دندانپزشک', 'کلینیک زیبایی'],
            ],
            'keywords' => ['دندانپزشکی', 'کلینیک'],
        ],
        'villa-ecolodge' => [
            'default_name_fa' => 'ویلا و اقامتگاه',
            'queries' => [
                'osm' => ['tourism=hotel', 'tourism=guest_house', 'tourism=chalet', 'tourism=apartment', 'tourism=motel', 'tourism=hostel'],
                'neshan' => ['ویلا و اقامتگاه', 'اقامتگاه بومگردی', 'هتل', 'ویلا'],
                'gosom' => ['اقامتگاه بومگردی', 'هتل', 'اجاره ویلا'],
            ],
            'keywords' => ['اقامتگاه بومگردی', 'ویلا', 'هتل'],
        ],
        'tourism-attraction' => [
            'default_name_fa' => 'جاذبه گردشگری',
            'queries' => [
                'osm' => ['tourism=attraction', 'tourism=museum', 'tourism=viewpoint', 'historic=monument', 'historic=castle', 'historic=archaeological_site', 'natural=waterfall', 'leisure=park'],
                'neshan' => ['جاذبه گردشگری', 'جاذبه دیدنی', 'موزه'],
                'gosom' => ['جاذبه دیدنی', 'پارک جنگلی', 'موزه'],
            ],
            'keywords' => ['جاذبه گردشگری', 'جاذبه دیدنی', 'موزه'],
        ],
    ];

    /**
     * نام‌های قدیمی و متناقض در seedهای اولیه (002 و 003) که باید با نام دسته اصلی همگام شوند.
     */
    private const LEGACY_PACK_NAMES = [
        'بسته کافه‌ها',
        'بسته کافهها',
        'بسته کافه و رستوران‌های محبوب',
        'بسته کافه و رستورانهای محبوب',
        'بسته سالن‌های زیبایی و مراقبت پوست',
        'بسته سالنهای زیبایی و مراقبت پوست',
        'بسته کلینیک زیبایی و دندانپزشکی',
        'بسته ویلا، هتل و اقامتگاه بومگردی',
        'بسته جاذبه‌های طبیعی و تاریخی',
        'بسته جاذبههای طبیعی و تاریخی',
    ];

    /**
     * مجموعه‌های تگ OSM پیش‌فرض قدیمی که در صورت دست‌نخورده بودن به مجموعه کامل ارتقا می‌یابند.
     */
    private const LEGACY_OSM_TAG_SETS = [
        'cafe-restaurant' => [
            ['amenity=cafe'],
            ['amenity=cafe', 'amenity=restaurant'],
        ],
        'beauty-salon' => [
            ['shop=beauty', 'shop=hairdresser'],
        ],
        'clinic-dental' => [
            ['amenity=dentist', 'amenity=clinic'],
        ],
        'villa-ecolodge' => [
            ['tourism=hotel', 'tourism=guest_house'],
        ],
        'tourism-attraction' => [
            ['tourism=attraction', 'historic=monument'],
        ],
    ];

    private static bool $syncedInRequest = false;

    public static function resetRequestCache(): void
    {
        self::$syncedInRequest = false;
    }

    /**
     * همگام‌سازی خودکار و بدون ریسک بسته‌های جمع‌آوری با دسته‌بندی‌های اصلی سایت
     */
    public static function ensureUnified(bool $force = false): void
    {
        if (self::$syncedInRequest && !$force) {
            return;
        }
        try {
            if (
                DB::fetchOne("SHOW TABLES LIKE 'categories'") === null
                || DB::fetchOne("SHOW TABLES LIKE 'category_packs'") === null
                || DB::fetchOne("SHOW TABLES LIKE 'category_pack_items'") === null
            ) {
                return;
            }

            $categories = DB::fetchAll('SELECT id, name_fa, slug, is_active FROM categories ORDER BY sort ASC, id ASC');
            if (!$categories) {
                self::$syncedInRequest = true;
                return;
            }

            foreach ($categories as $cat) {
                self::syncSingleCategoryPack($cat);
            }
            self::$syncedInRequest = true;
        } catch (Throwable $e) {
            // Never block read endpoints if DB user lacks write privileges in a read-only check.
        }
    }

    public static function syncSingleCategoryPack(array $cat): int
    {
        $catId = (int) ($cat['id'] ?? 0);
        if ($catId <= 0) {
            return 0;
        }
        $catName = trim((string) ($cat['name_fa'] ?? ''));
        $catSlug = strtolower(trim((string) ($cat['slug'] ?? '')));
        $catActive = (int) ($cat['is_active'] ?? 1) === 1 ? 1 : 0;
        $preset = self::CANONICAL_PACK_PRESETS[$catSlug] ?? null;

        // 1. Find existing pack linked to this category (prefer matching pack_id = category_id for seed 1..5)
        $linked = DB::fetchOne(
            'SELECT p.id, p.name, p.queries_json, p.is_active
             FROM category_pack_items cpi
             INNER JOIN category_packs p ON p.id = cpi.pack_id
             WHERE cpi.category_id = :cid
             ORDER BY (p.id = :cid_match) DESC, p.id ASC
             LIMIT 1',
            ['cid' => $catId, 'cid_match' => $catId]
        );

        if (!$linked && $catId <= 5) {
            $seedPack = DB::fetchOne('SELECT id, name, queries_json, is_active FROM category_packs WHERE id = :id', ['id' => $catId]);
            if ($seedPack) {
                $otherOwner = DB::fetchOne('SELECT category_id FROM category_pack_items WHERE pack_id = :pid AND category_id <> :cid LIMIT 1', ['pid' => $catId, 'cid' => $catId]);
                if (!$otherOwner) {
                    $linked = $seedPack;
                }
            }
        }

        $defaultQueries = $preset['queries'] ?? [
            'osm' => ['amenity=cafe'],
            'neshan' => [$catName],
        ];
        $defaultKeywords = $preset['keywords'] ?? [$catName];

        if (!$linked) {
            $queriesJson = json_encode($defaultQueries, JSON_UNESCAPED_UNICODE);
            DB::query(
                'INSERT INTO category_packs (name, queries_json, is_active, created_at) VALUES (:name, :q, :active, NOW())',
                ['name' => $catName, 'q' => $queriesJson, 'active' => $catActive]
            );
            $packId = DB::lastInsertId();
            DB::query(
                'INSERT INTO category_pack_items (pack_id, category_id, queries_json) VALUES (:pid, :cid, :q)',
                ['pid' => $packId, 'cid' => $catId, 'q' => json_encode(['keywords' => $defaultKeywords], JSON_UNESCAPED_UNICODE)]
            );
            return $packId;
        }

        $packId = (int) $linked['id'];
        $currentName = trim((string) ($linked['name'] ?? ''));
        $decodedQueries = json_decode((string) ($linked['queries_json'] ?? '{}'), true);
        if (!is_array($decodedQueries)) {
            $decodedQueries = [];
        }

        $shouldUnifyName = $currentName === ''
            || in_array($currentName, self::LEGACY_PACK_NAMES, true)
            || $currentName === 'بسته ' . $catName;

        $nextName = $shouldUnifyName ? $catName : $currentName;
        $nextQueries = $decodedQueries;
        $queriesChanged = false;

        if ($preset !== null) {
            $currentOsm = array_values(array_map('strval', is_array($decodedQueries['osm'] ?? null) ? $decodedQueries['osm'] : []));
            $presetOsm = $preset['queries']['osm'];
            $legacySets = self::LEGACY_OSM_TAG_SETS[$catSlug] ?? [];
            $isLegacyOsm = empty($currentOsm);
            foreach ($legacySets as $legacySet) {
                if ($currentOsm === $legacySet) {
                    $isLegacyOsm = true;
                    break;
                }
            }
            if (!$isLegacyOsm && $shouldUnifyName && count($currentOsm) < count($presetOsm) && !array_diff($currentOsm, $presetOsm)) {
                $isLegacyOsm = true;
            }
            if ($isLegacyOsm && $currentOsm !== $presetOsm) {
                $nextQueries['osm'] = $presetOsm;
                $nextQueries['neshan'] = $preset['queries']['neshan'];
                $nextQueries['gosom'] = $preset['queries']['gosom'];
                $queriesChanged = true;
            }
        }

        if ($nextName !== $currentName || $queriesChanged) {
            DB::query(
                'UPDATE category_packs SET name = :name, queries_json = :q WHERE id = :id',
                [
                    'name' => $nextName,
                    'q' => json_encode($nextQueries, JSON_UNESCAPED_UNICODE),
                    'id' => $packId,
                ]
            );
        }

        // Ensure clean 1-to-1 link in category_pack_items (remove accidental category_id=1 duplicates on packs > 1)
        $existingLink = DB::fetchOne(
            'SELECT id, queries_json FROM category_pack_items WHERE pack_id = :pid AND category_id = :cid LIMIT 1',
            ['pid' => $packId, 'cid' => $catId]
        );
        if (!$existingLink) {
            DB::query(
                'INSERT INTO category_pack_items (pack_id, category_id, queries_json) VALUES (:pid, :cid, :q)',
                ['pid' => $packId, 'cid' => $catId, 'q' => json_encode(['keywords' => $defaultKeywords], JSON_UNESCAPED_UNICODE)]
            );
        } elseif ($catSlug === 'cafe-restaurant') {
            $itemQ = json_decode((string) ($existingLink['queries_json'] ?? '{}'), true);
            if (is_array($itemQ) && ($itemQ['keywords'] ?? null) === ['کافه']) {
                DB::query(
                    'UPDATE category_pack_items SET queries_json = :q WHERE id = :id',
                    ['q' => json_encode(['keywords' => $defaultKeywords], JSON_UNESCAPED_UNICODE), 'id' => (int) $existingLink['id']]
                );
            }
        }
        DB::query(
            'DELETE FROM category_pack_items WHERE pack_id = :pid AND category_id <> :cid',
            ['pid' => $packId, 'cid' => $catId]
        );

        return $packId;
    }

    /**
     * واکشی فهرست یکپارچه بسته‌ها به همراه شناسه و نام دسته‌بندی اصلی متصل به هر بسته
     */
    public static function fetchUnifiedPacks(): array
    {
        self::ensureUnified();
        return DB::fetchAll(
            "SELECT p.*,
                    c.id AS category_id,
                    c.name_fa AS category_name_fa,
                    c.slug AS category_slug,
                    c.sort AS category_sort
             FROM category_packs p
             LEFT JOIN category_pack_items cpi ON cpi.pack_id = p.id
             LEFT JOIN categories c ON c.id = cpi.category_id
             ORDER BY COALESCE(c.sort, 999) ASC, p.id ASC"
        );
    }

    /**
     * تشخیص دقیق جفت (category, pack) چه کاربر category_id بفرستد و چه pack_id
     *
     * @return array{category_id:int,category_name_fa:string,pack_id:int,pack_name:string}|null
     */
    public static function resolveCategoryAndPack(int $categoryId, int $packId): ?array
    {
        self::ensureUnified();
        if ($categoryId > 0) {
            $cat = DB::fetchOne('SELECT id, name_fa, slug, is_active FROM categories WHERE id = :id AND is_active = 1', ['id' => $categoryId]);
            if (!$cat) {
                return null;
            }
            $resolvedPackId = self::syncSingleCategoryPack($cat);
            $pack = DB::fetchOne('SELECT id, name, is_active FROM category_packs WHERE id = :id AND is_active = 1', ['id' => $resolvedPackId]);
            if (!$pack) {
                return null;
            }
            return [
                'category_id' => (int) $cat['id'],
                'category_name_fa' => (string) $cat['name_fa'],
                'pack_id' => (int) $pack['id'],
                'pack_name' => (string) $pack['name'],
            ];
        }
        if ($packId > 0) {
            $row = DB::fetchOne(
                'SELECT p.id AS pack_id, p.name AS pack_name, c.id AS category_id, c.name_fa AS category_name_fa
                 FROM category_packs p
                 INNER JOIN category_pack_items cpi ON cpi.pack_id = p.id
                 INNER JOIN categories c ON c.id = cpi.category_id
                 WHERE p.id = :pid AND p.is_active = 1 AND c.is_active = 1
                 ORDER BY cpi.id ASC
                 LIMIT 1',
                ['pid' => $packId]
            );
            if (!$row) {
                return null;
            }
            return [
                'category_id' => (int) $row['category_id'],
                'category_name_fa' => (string) $row['category_name_fa'],
                'pack_id' => (int) $row['pack_id'],
                'pack_name' => (string) $row['pack_name'],
            ];
        }
        return null;
    }
}
