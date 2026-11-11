<?php
declare(strict_types=1);

namespace App\Core {
    final class DB {
        public static array $rows = [];
        public static function fetchOne(string $sql, array $params = []): ?array {
            return isset(self::$rows[$params['k']]) ? ['v'=>self::$rows[$params['k']]] : null;
        }
        public static function query(string $sql, array $params = []): object {
            $key = $params['k'];
            if (str_starts_with($sql, 'INSERT')) {
                if (isset(self::$rows[$key])) throw new \RuntimeException('duplicate');
                self::$rows[$key] = $params['v'];
                return new class { public function rowCount(): int { return 1; } };
            }
            $n = isset(self::$rows[$key]) && self::$rows[$key] === $params['old'] ? 1 : 0;
            if ($n) self::$rows[$key] = $params['v'];
            return new class($n) { public function __construct(private int $n) {} public function rowCount(): int { return $this->n; } };
        }
    }
}
namespace {
    define('BASE_PATH', dirname(__DIR__));
    require BASE_PATH . '/app/Services/AiProviderVault.php';
    require BASE_PATH . '/app/Services/AdminMailService.php';
    putenv('HHX_AI_VAULT_KEY=' . base64_encode(str_repeat('k', 32)));
    $data = ['version'=>0,'enabled'=>true,'method'=>'smtp','recipient'=>'admin@example.test','from'=>'site@example.test',
        'host'=>'smtp.example.test','port'=>587,'security'=>'tls','username'=>'fixture','password'=>'fixture-password'];
    \App\Services\AdminMailService::save($data);
    $status = \App\Services\AdminMailService::status();
    if (!$status['password_configured'] || $status['version'] !== 1 || str_contains(json_encode($status), 'fixture-password')) throw new \RuntimeException('status leaked or lost secret');
    $stored = \App\Core\DB::$rows['admin_mail_v1'];
    if (str_contains($stored, 'fixture-password')) throw new \RuntimeException('secret is plaintext');
    try { \App\Services\AdminMailService::save($data); throw new \RuntimeException('stale update accepted'); }
    catch (\RuntimeException $e) { if ($e->getCode() !== 409) throw $e; }
    $data['version']=1; $data['enabled']=false; $data['password']='';
    \App\Services\AdminMailService::save($data);
    if (!\App\Services\AdminMailService::status()['password_configured']) throw new \RuntimeException('blank password erased secret');
    if (\App\Services\AdminMailService::send('test','test')) throw new \RuntimeException('disabled mail sent');
    $data['version']=2; $data['clear_password']=true;
    \App\Services\AdminMailService::save($data);
    if (\App\Services\AdminMailService::status()['password_configured']) throw new \RuntimeException('secret not cleared');
    $data['version']=3; $data['enabled']=true;
    try { \App\Services\AdminMailService::save($data); throw new \RuntimeException('enabled incomplete SMTP accepted'); }
    catch (\InvalidArgumentException $e) {}
    echo "PASS: admin mail validation, encrypted secrets, redaction, concurrency and disabled delivery.\n";
}
