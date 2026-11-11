<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Non-blocking, connection-scoped locks shared by cron and panel requests.
 * All participants must use the same MySQL/MariaDB server and database.
 * These locks are released by the database when the connection closes.
 */
final class WorkerLock
{
    /** @var array<string, bool> Prevent recursive acquisition on the same connection. */
    private static array $held = [];

    private static function name(string $scope): string
    {
        if (!in_array($scope, ['cron', 'maintenance', 'collector'], true)) {
            throw new RuntimeException('Unknown worker lock scope.');
        }
        $database = (string) Config::get('db.database', '');
        if ($database === '') throw new RuntimeException('Database name is required for worker locks.');
        return 'hhx_' . $scope . '_' . substr(hash('sha256', $database), 0, 40);
    }

    /** Returns false only when another participant holds the lock. Errors fail closed. */
    public static function acquire(string $scope): bool
    {
        $name = self::name($scope);
        if (isset(self::$held[$name])) return false;
        $row = DB::fetchOne('SELECT GET_LOCK(:name, 0) AS acquired', ['name'=>$name]);
        $value = $row['acquired'] ?? null;
        if ($value === null || !in_array((string)$value, ['0', '1'], true)) {
            throw new RuntimeException('Worker lock could not be acquired.');
        }
        if ((int)$value === 0) return false;
        self::$held[$name] = true;
        return true;
    }

    /** Check ownership again after long provider calls and before processing each item. */
    public static function assertHeld(string $scope): void
    {
        $name = self::name($scope);
        if (!isset(self::$held[$name])) throw new RuntimeException('Worker lock is not held.');
        $row = DB::fetchOne('SELECT IS_USED_LOCK(:name) = CONNECTION_ID() AS held', ['name'=>$name]);
        if ((int)($row['held'] ?? 0) !== 1) throw new RuntimeException('Worker lock ownership was lost.');
    }

    public static function release(string $scope): void
    {
        $name = self::name($scope);
        if (!isset(self::$held[$name])) throw new RuntimeException('Worker lock is not held.');
        try {
            $row = DB::fetchOne('SELECT RELEASE_LOCK(:name) AS released', ['name'=>$name]);
            if ((int)($row['released'] ?? 0) !== 1) throw new RuntimeException('Worker lock release was not confirmed.');
        } finally {
            unset(self::$held[$name]);
        }
    }
}
