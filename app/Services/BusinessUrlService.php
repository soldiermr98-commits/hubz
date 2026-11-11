<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use RuntimeException;

/** Canonical city-first addresses and historical aliases for business pages. */
final class BusinessUrlService
{
    public static function schemaReady(): bool
    {
        static $ready = null;
        return $ready ??= DB::fetchOne("SHOW TABLES LIKE 'business_url_aliases'") !== null;
    }

    public static function path(string $citySlug, string $businessSlug): string
    {
        return '/' . rawurlencode($citySlug) . '/' . rawurlencode($businessSlug) . '/';
    }

    public static function legacyPath(string $businessSlug): string
    {
        return '/b/' . rawurlencode($businessSlug) . '/';
    }

    /** Call inside the same transaction as a slug/city change. */
    public static function remember(int $businessId, string $citySlug, string $businessSlug): void
    {
        if (!self::schemaReady()) {
            throw new RuntimeException('برای حفظ نشانی‌های قبلی ابتدا به‌روزرسانی دیتابیس را طبق راهنمای نصب انجام دهید.');
        }
        foreach ([self::legacyPath($businessSlug), self::path($citySlug, $businessSlug)] as $path) {
            $existing = DB::fetchOne('SELECT business_id FROM business_url_aliases WHERE path=:path FOR UPDATE', ['path' => $path]);
            if ($existing && (int)$existing['business_id'] !== $businessId) {
                throw new RuntimeException('این نشانی قبلاً برای کسب‌وکار دیگری ثبت شده است؛ تغییر نشانی انجام نشد.');
            }
            DB::query('INSERT IGNORE INTO business_url_aliases (path,business_id) VALUES (:path,:id)', ['path' => $path, 'id' => $businessId]);
        }
    }

    /** Preserve every city-first business URL if an administrator changes a city slug. */
    public static function rememberCity(int $cityId, string $citySlug): void
    {
        $businesses = DB::fetchAll('SELECT id,slug FROM businesses WHERE city_id=:city', ['city' => $cityId]);
        foreach ($businesses as $business) {
            self::remember((int)$business['id'], $citySlug, (string)$business['slug']);
        }
    }

    /** Resolve an alias only if its current target is published. */
    public static function resolveAlias(string $path): ?array
    {
        if (!self::schemaReady()) {
            return null;
        }
        return DB::fetchOne(
            "SELECT b.id,b.slug,c.slug AS city_slug FROM business_url_aliases a
             INNER JOIN businesses b ON b.id=a.business_id
             INNER JOIN cities c ON c.id=b.city_id
             WHERE a.path=:path AND b.status='published' LIMIT 1",
            ['path' => $path]
        );
    }
}
