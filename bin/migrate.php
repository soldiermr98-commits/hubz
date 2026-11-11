<?php
declare(strict_types=1);

/**
 * اجراکننده افزایشی Migrationها
 * در صورت نبودن ترمینال روی هاست اشتراکی، همان کلاس App\Core\Migrator از طریق install.php یا پنل ادمین اجرا می‌شود.
 */

require_once dirname(__DIR__) . '/app/Core/Bootstrap.php';

use App\Core\Bootstrap;
use App\Core\Migrator;

Bootstrap::init(dirname(__DIR__));

$result = Migrator::runIncremental();
echo "=== نتیجه اجرای مایگریشن‌ها ===\n";
echo "تعداد اعمال‌شده جدید: " . $result['total_applied'] . "\n";
foreach ($result['applied'] as $file) {
    echo " + [اعمال شد]: {$file}\n";
}
foreach ($result['skipped'] as $file) {
    echo " - [قبلاً اجرا شده]: {$file}\n";
}
