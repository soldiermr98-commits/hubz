<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use Throwable;

final class AdvertisingReportingService
{
    public static function schemaReady(): bool
    {
        try {
            foreach (['advertising_banner_daily','advertising_contracts','advertising_manual_payments'] as $table) {
                if (DB::fetchOne("SHOW TABLES LIKE '{$table}'") === null) return false;
            }
            return true;
        } catch (Throwable $e) { return false; }
    }

    /** Aggregate counters only: no IP, device identifiers, or per-visitor event records. */
    public static function countBanner(int $id, string $event): void
    {
        if ($id < 1 || !in_array($event, ['view','click'], true)) return;
        DB::query("INSERT INTO advertising_banner_daily (banner_id,day,views,clicks)
            SELECT id,CURDATE(),:views,:clicks FROM advertising_banners
            WHERE id=:id AND active=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>NOW())
            ON DUPLICATE KEY UPDATE views=views+VALUES(views),clicks=clicks+VALUES(clicks)",
            ['views'=>$event === 'view' ? 1 : 0, 'clicks'=>$event === 'click' ? 1 : 0, 'id'=>$id]);
    }
}
