<?php
declare(strict_types=1);

namespace App\Core;

/**
 * بارگذار خودکار کلاس‌های فضای نام App\ بدون نیاز به Composer روی سرور هاست اشتراکی
 */
final class Bootstrap
{
    public static function init(string $basePath): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', rtrim($basePath, '/\\'));
        }

        spl_autoload_register(static function (string $class): void {
            $prefix = 'App\\';
            $baseDir = BASE_PATH . '/app/';

            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relativeClass = substr($class, $len);
            $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

            if (is_file($file)) {
                require_once $file;
                return;
            }

            // فال‌بک هوشمند برای هاست اشتراکی (در صورتی که فایل در پوشه هم‌سطح آپلود شده باشد)
            $shortName = basename(str_replace('\\', '/', $relativeClass)) . '.php';
            $fallbacks = [
                $baseDir . 'Services/' . $shortName,
                $baseDir . 'Controllers/' . $shortName,
                $baseDir . 'Core/' . $shortName,
                $baseDir . $shortName,
            ];
            foreach ($fallbacks as $candidate) {
                if (is_file($candidate)) {
                    require_once $candidate;
                    return;
                }
            }
        });

        $configFile = BASE_PATH . '/config/config.php';
        if (!is_file($configFile)) {
            $configFile = BASE_PATH . '/config/config.sample.php';
        }

        Config::load($configFile);
    }
}
