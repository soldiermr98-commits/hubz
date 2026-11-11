<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use InvalidArgumentException;
use Throwable;

/**
 * SEO-safe URL lifecycle manager for deleted published businesses.
 *
 * Supports:
 * - HTTP 410 (Gone) + noindex with contextual city/category navigation for permanently
 *   removed published businesses (signals Googlebot to de-index cleanly without crawl errors).
 * - HTTP 301 permanent redirects to either the parent city+category hub (/city/{city}/{cat}/)
 *   or a canonical replacement published business (/{city}/{slug}/).
 * - Clean purge (slug & alias freed immediately) for drafts/unindexed test imports.
 *
 * Uses `seo_url_rules` when migration 012 is installed, and seamlessly falls back to
 * the `settings` table (`seo_deleted_business_rules`) beforehand so deletion works
 * immediately after deploying PHP files without requiring a prior DB migration.
 */
final class BusinessSeoService
{
    public const SETTINGS_FALLBACK_KEY = 'seo_deleted_business_rules';
    private const MAX_FALLBACK_RULES = 5000;

    public static function schemaReady(): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $ready = DB::fetchOne("SHOW TABLES LIKE 'seo_url_rules'") !== null;
            return $ready;
        } catch (Throwable) {
            return false;
        }
    }

    public static function resetSchemaCache(): void
    {
        // Used right after running migration 012 in the same request.
        try {
            $ref = new \ReflectionMethod(self::class, 'schemaReady');
            // Static local cache cannot be reset via reflection in all PHP builds;
            // migrateSettingsFallbackToTable() queries the table directly.
            unset($ref);
        } catch (Throwable) {
        }
    }

    public static function normalizePath(string $path): string
    {
        $parsed = parse_url($path, PHP_URL_PATH);
        $clean = is_string($parsed) ? trim($parsed) : '';
        if ($clean === '' || $clean === '/') {
            return '/';
        }
        return '/' . trim($clean, '/') . '/';
    }

    public static function validInternalPath(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= 320
            && str_starts_with($path, '/')
            && !str_starts_with($path, '//')
            && !preg_match('/[\x00-\x20\x7f\\\\]/', $path);
    }

    /**
     * Collect all public paths that belonged to a business (canonical city path,
     * legacy /b/{slug}/ path, and any historical business_url_aliases).
     *
     * @return string[]
     */
    public static function collectBusinessPaths(int $businessId, string $citySlug, string $businessSlug): array
    {
        $paths = [];
        if ($citySlug !== '' && $businessSlug !== '') {
            $paths[] = self::normalizePath(BusinessUrlService::path($citySlug, $businessSlug));
        }
        if ($businessSlug !== '') {
            $paths[] = self::normalizePath(BusinessUrlService::legacyPath($businessSlug));
        }
        if ($businessId > 0 && BusinessUrlService::schemaReady()) {
            $rows = DB::fetchAll(
                'SELECT path FROM business_url_aliases WHERE business_id = :id',
                ['id' => $businessId]
            );
            foreach ($rows as $row) {
                $aliasPath = self::normalizePath((string)($row['path'] ?? ''));
                if ($aliasPath !== '/') {
                    $paths[] = $aliasPath;
                }
            }
        }
        return array_values(array_unique(array_filter(
            $paths,
            static fn(string $p): bool => $p !== '/' && self::validInternalPath($p)
        )));
    }

    /**
     * Record 410 or 301 SEO rules for a published business being deleted.
     *
     * @param string[] $paths
     */
    public static function recordRules(
        array $paths,
        int $httpStatus,
        ?string $targetPath,
        ?int $targetBusinessId,
        ?string $fallbackPath,
        ?string $fallbackLabel,
        ?string $entityTitle
    ): int {
        if (!in_array($httpStatus, [301, 410], true)) {
            throw new InvalidArgumentException('کد وضعیت سئو نامعتبر است.');
        }
        $normalizedTarget = $targetPath !== null && $targetPath !== '' ? self::normalizePath($targetPath) : null;
        if ($normalizedTarget !== null && !self::validInternalPath($normalizedTarget)) {
            throw new InvalidArgumentException('نشانی مقصد ریدایرکت معتبر نیست.');
        }
        $normalizedFallback = $fallbackPath !== null && $fallbackPath !== '' ? self::normalizePath($fallbackPath) : null;
        if ($normalizedFallback !== null && !self::validInternalPath($normalizedFallback)) {
            $normalizedFallback = '/';
        }
        $label = $fallbackLabel !== null ? mb_substr(trim($fallbackLabel), 0, 200) : null;
        $title = $entityTitle !== null ? mb_substr(trim($entityTitle), 0, 200) : null;

        $validPaths = [];
        foreach ($paths as $rawPath) {
            $norm = self::normalizePath($rawPath);
            if ($norm === '/' || !self::validInternalPath($norm)) {
                continue;
            }
            if ($httpStatus === 301 && $normalizedTarget !== null && $norm === $normalizedTarget) {
                continue;
            }
            $validPaths[$norm] = true;
        }
        if (!$validPaths) {
            return 0;
        }

        if (self::tableExistsDirect()) {
            foreach (array_keys($validPaths) as $normPath) {
                DB::query(
                    'INSERT INTO seo_url_rules
                        (path, http_status, target_path, target_business_id, fallback_path, fallback_label, entity_title, created_at, updated_at)
                     VALUES
                        (:path, :status, :target_path, :target_biz, :fallback_path, :fallback_label, :title, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE
                        http_status = VALUES(http_status),
                        target_path = VALUES(target_path),
                        target_business_id = VALUES(target_business_id),
                        fallback_path = VALUES(fallback_path),
                        fallback_label = VALUES(fallback_label),
                        entity_title = VALUES(entity_title),
                        updated_at = NOW()',
                    [
                        'path' => $normPath,
                        'status' => $httpStatus,
                        'target_path' => $normalizedTarget,
                        'target_biz' => $targetBusinessId && $targetBusinessId > 0 ? $targetBusinessId : null,
                        'fallback_path' => $normalizedFallback,
                        'fallback_label' => $label !== '' ? $label : null,
                        'title' => $title !== '' ? $title : null,
                    ]
                );
            }
            return count($validPaths);
        }

        $map = self::loadFallbackMap(true);
        $now = date('Y-m-d H:i:s');
        foreach (array_keys($validPaths) as $normPath) {
            unset($map[$normPath]);
            $map[$normPath] = [
                'http_status' => $httpStatus,
                'target_path' => $normalizedTarget,
                'target_business_id' => $targetBusinessId && $targetBusinessId > 0 ? $targetBusinessId : null,
                'fallback_path' => $normalizedFallback,
                'fallback_label' => $label !== '' ? $label : null,
                'entity_title' => $title !== '' ? $title : null,
                'updated_at' => $now,
            ];
        }
        if (count($map) > self::MAX_FALLBACK_RULES) {
            $map = array_slice($map, -self::MAX_FALLBACK_RULES, null, true);
        }
        self::saveFallbackMap($map);
        return count($validPaths);
    }

    /**
     * Remove stale 410/301 rules when a business is published at these URLs again.
     */
    public static function clearForLiveBusiness(string $citySlug, string $businessSlug): void
    {
        $paths = array_values(array_filter([
            $citySlug !== '' && $businessSlug !== '' ? self::normalizePath(BusinessUrlService::path($citySlug, $businessSlug)) : '',
            $businessSlug !== '' ? self::normalizePath(BusinessUrlService::legacyPath($businessSlug)) : '',
        ]));
        if (!$paths) {
            return;
        }
        if (self::tableExistsDirect()) {
            foreach ($paths as $p) {
                DB::query('DELETE FROM seo_url_rules WHERE path = :path', ['path' => $p]);
            }
        }
        $map = self::loadFallbackMap(false);
        if (!$map) {
            return;
        }
        $changed = false;
        foreach ($paths as $p) {
            if (isset($map[$p])) {
                unset($map[$p]);
                $changed = true;
            }
        }
        if ($changed) {
            self::saveFallbackMap($map);
        }
    }

    /**
     * Resolve an SEO rule for an unfound business URL.
     *
     * @return null|array{http_status:int,redirect_to?:string,fallback_path:string,fallback_label:string,entity_title:string}
     */
    public static function resolveRule(string $requestedPath): ?array
    {
        $norm = self::normalizePath($requestedPath);
        if ($norm === '/' || !self::validInternalPath($norm)) {
            return null;
        }

        $rule = null;
        if (self::schemaReady()) {
            $rule = DB::fetchOne('SELECT * FROM seo_url_rules WHERE path = :path LIMIT 1', ['path' => $norm]);
        }
        if (!$rule) {
            $map = self::loadFallbackMap(false);
            if (isset($map[$norm]) && is_array($map[$norm])) {
                $rule = $map[$norm];
            }
        }
        if (!$rule) {
            return null;
        }

        $status = (int)($rule['http_status'] ?? 410);
        $fallbackPath = (string)($rule['fallback_path'] ?? '/');
        if (!self::validInternalPath($fallbackPath)) {
            $fallbackPath = '/';
        }
        $fallbackLabel = trim((string)($rule['fallback_label'] ?? '')) ?: 'صفحه اصلی حامی‌هاب گیلان';
        $entityTitle = trim((string)($rule['entity_title'] ?? ''));

        if ($status === 301) {
            $targetBizId = (int)($rule['target_business_id'] ?? 0);
            if ($targetBizId > 0) {
                $liveTarget = DB::fetchOne(
                    "SELECT b.slug, c.slug AS city_slug
                     FROM businesses b
                     INNER JOIN cities c ON c.id = b.city_id
                     WHERE b.id = :id AND b.status = 'published'
                     LIMIT 1",
                    ['id' => $targetBizId]
                );
                if ($liveTarget) {
                    $resolved = self::normalizePath(
                        BusinessUrlService::path((string)$liveTarget['city_slug'], (string)$liveTarget['slug'])
                    );
                    if ($resolved !== $norm && self::validInternalPath($resolved)) {
                        return [
                            'http_status' => 301,
                            'redirect_to' => $resolved,
                            'fallback_path' => $fallbackPath,
                            'fallback_label' => $fallbackLabel,
                            'entity_title' => $entityTitle,
                        ];
                    }
                }
            }

            $targetPath = (string)($rule['target_path'] ?? '');
            if ($targetPath !== '' && self::validInternalPath($targetPath) && self::normalizePath($targetPath) !== $norm) {
                return [
                    'http_status' => 301,
                    'redirect_to' => self::normalizePath($targetPath),
                    'fallback_path' => $fallbackPath,
                    'fallback_label' => $fallbackLabel,
                    'entity_title' => $entityTitle,
                ];
            }
            if ($fallbackPath !== '/' && $fallbackPath !== $norm) {
                return [
                    'http_status' => 301,
                    'redirect_to' => $fallbackPath,
                    'fallback_path' => $fallbackPath,
                    'fallback_label' => $fallbackLabel,
                    'entity_title' => $entityTitle,
                ];
            }
        }

        return [
            'http_status' => 410,
            'fallback_path' => $fallbackPath,
            'fallback_label' => $fallbackLabel,
            'entity_title' => $entityTitle,
        ];
    }

    /**
     * Copy any fallback rules stored in `settings` into `seo_url_rules` once migration 012 runs.
     */
    public static function migrateFallbackRulesToTable(): int
    {
        if (!self::tableExistsDirect()) {
            return 0;
        }
        $map = self::loadFallbackMap(false);
        if (!$map) {
            return 0;
        }
        $migrated = 0;
        foreach ($map as $path => $item) {
            if (!is_string($path) || !is_array($item) || !self::validInternalPath($path)) {
                continue;
            }
            $status = in_array((int)($item['http_status'] ?? 410), [301, 410], true) ? (int)$item['http_status'] : 410;
            DB::query(
                'INSERT IGNORE INTO seo_url_rules
                    (path, http_status, target_path, target_business_id, fallback_path, fallback_label, entity_title, created_at, updated_at)
                 VALUES
                    (:path, :status, :target_path, :target_biz, :fallback_path, :fallback_label, :title, NOW(), NOW())',
                [
                    'path' => $path,
                    'status' => $status,
                    'target_path' => isset($item['target_path']) && is_string($item['target_path']) ? $item['target_path'] : null,
                    'target_biz' => !empty($item['target_business_id']) ? (int)$item['target_business_id'] : null,
                    'fallback_path' => isset($item['fallback_path']) && is_string($item['fallback_path']) ? $item['fallback_path'] : null,
                    'fallback_label' => isset($item['fallback_label']) && is_string($item['fallback_label']) ? $item['fallback_label'] : null,
                    'title' => isset($item['entity_title']) && is_string($item['entity_title']) ? $item['entity_title'] : null,
                ]
            );
            $migrated++;
        }
        DB::query('DELETE FROM settings WHERE k = :k', ['k' => self::SETTINGS_FALLBACK_KEY]);
        return $migrated;
    }

    private static function tableExistsDirect(): bool
    {
        try {
            return DB::fetchOne("SHOW TABLES LIKE 'seo_url_rules'") !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function loadFallbackMap(bool $forUpdate): array
    {
        try {
            $sql = 'SELECT v FROM settings WHERE k = :k LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
            $row = DB::fetchOne($sql, ['k' => self::SETTINGS_FALLBACK_KEY]);
            if (!$row || !is_string($row['v'] ?? null) || trim($row['v']) === '') {
                return [];
            }
            $decoded = json_decode($row['v'], true);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, array<string, mixed>> $map
     */
    private static function saveFallbackMap(array $map): void
    {
        if (!$map) {
            DB::query('DELETE FROM settings WHERE k = :k', ['k' => self::SETTINGS_FALLBACK_KEY]);
            return;
        }
        $json = (string)json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        DB::query(
            'INSERT INTO settings (k, v, updated_at) VALUES (:k, :v, NOW())
             ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = NOW()',
            ['k' => self::SETTINGS_FALLBACK_KEY, 'v' => $json]
        );
    }
}
