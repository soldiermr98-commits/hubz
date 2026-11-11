<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * مدیریت تنظیمات پایه، منطقه زمانی و بارگذاری خودکار کلاس‌ها (PSR-4 سبک)
 */
final class Config
{
    private static array $items = [];
    private static bool $loaded = false;

    /**
     * بارگذاری فایل تنظیمات و تنظیم منطقه زمانی تهران
     */
    public static function load(string $configPath): void
    {
        if (self::$loaded) {
            return;
        }

        if (!is_file($configPath)) {
            throw new RuntimeException('فایل تنظیمات یافت نشد. config.sample.php را به config.local.php کپی و تنظیم کنید.');
        }

        $data = require $configPath;
        if (!is_array($data)) {
            throw new RuntimeException('ساختار فایل تنظیمات معتبر نیست.');
        }

        self::$items = $data;
        self::$loaded = true;

        $timezone = (string) self::get('app.timezone', 'Asia/Tehran');
        date_default_timezone_set($timezone);
    }

    /**
     * دریافت مقدار تنظیمات با کلید نقطه‌ای مانند db.host
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $current = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * بررسی اینکه آیا اسلاگ جزء اسلاگ‌های رزروشده سیستم است یا خیر
     */
    public static function isReservedSlug(string $slug): bool
    {
        $reserved = (array) self::get('reserved_slugs', []);
        return in_array(strtolower(trim($slug)), $reserved, true);
    }

    /**
     * اعتبارسنجی فرمت اسلاگ لاتین استاندارد
     */
    public static function isValidSlugFormat(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug);
    }
}
