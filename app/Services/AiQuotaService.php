<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\DB;
use RuntimeException;
use Throwable;

final class AiQuotaService
{
    /** Serialize generation and connection tests across all users and app nodes. */
    public static function reserve(int $userId, string $provider): bool
    {
        $perUser = max(1, min(100, (int)Config::get('ai.hourly_user_limit', 5)));
        $perSite = max(1, min(500, (int)Config::get('ai.daily_site_limit', 30)));
        $pdo = DB::connection();
        $pdo->beginTransaction();
        try {
            $lock = DB::fetchOne('SELECT id FROM ai_article_quota_lock WHERE id=1 FOR UPDATE');
            if ($lock === null) throw new RuntimeException('محدودیت درخواست آماده نیست.');
            $hour = (int)(DB::fetchOne('SELECT COUNT(*) AS n FROM ai_article_requests WHERE user_id=:id AND requested_at >= (NOW() - INTERVAL 1 HOUR)', ['id'=>$userId])['n'] ?? 0);
            $day = (int)(DB::fetchOne('SELECT COUNT(*) AS n FROM ai_article_requests WHERE requested_at >= (NOW() - INTERVAL 24 HOUR)')['n'] ?? 0);
            if ($hour >= $perUser || $day >= $perSite) { $pdo->rollBack(); return false; }
            DB::query('INSERT INTO ai_article_requests (user_id,provider,requested_at) VALUES (:user,:provider,NOW())', ['user'=>$userId, 'provider'=>$provider]);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function schemaReady(): bool
    {
        return DB::fetchOne("SHOW TABLES LIKE 'ai_article_requests'") !== null
            && DB::fetchOne("SHOW TABLES LIKE 'ai_article_quota_lock'") !== null;
    }
}
