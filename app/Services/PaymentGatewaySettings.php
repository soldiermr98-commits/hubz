<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use DomainException;
use InvalidArgumentException;
use Throwable;

/** Payment gateway settings are separate from AI credentials and disabled by default. */
final class PaymentGatewaySettings
{
    public const PROVIDERS = ['zibal'=>'زیبال', 'aqayepardakht'=>'آقای پرداخت', 'idpay'=>'آیدی‌پی'];
    private PaymentVault $vault;
    public function __construct(string $basePath) { $this->vault = new PaymentVault($basePath); }

    private static function validKey(string $key): bool
    {
        return $key !== '' && strlen($key) <= 512 && !preg_match('/[^\x21-\x7e]/', $key);
    }

    /** For these providers sandbox is identified by the credential itself. */
    public static function validateModeCredential(string $provider, string $mode, string $secret): void
    {
        if (!self::validKey($secret)) throw new InvalidArgumentException('کلید درگاه معتبر نیست.');
        if (($provider === 'zibal' && (($mode === 'sandbox' && $secret !== 'zibal') || ($mode === 'live' && $secret === 'zibal')))
            || ($provider === 'aqayepardakht' && (($mode === 'sandbox' && $secret !== 'sandbox') || ($mode === 'live' && $secret === 'sandbox')))) {
            throw new InvalidArgumentException('کلید آزمایشی و حالت واقعی/آزمایشی با هم سازگار نیستند.');
        }
    }

    private static function sealed(?string $json): ?array
    {
        if ($json === null || $json === '') return null;
        $sealed = json_decode($json, true);
        if (!is_array($sealed) || !is_string($sealed['nonce'] ?? null) || !is_string($sealed['ciphertext'] ?? null) || !is_string($sealed['tag'] ?? null)) {
            throw new DomainException('اطلاعات رمزگذاری‌شده درگاه قابل خواندن نیست.', 409);
        }
        return $sealed;
    }

    private static function hasEncryptedSecrets(): bool
    {
        $settings = DB::fetchOne('SELECT provider FROM advertising_gateway_settings WHERE secret_sealed IS NOT NULL LIMIT 1');
        $attempts = DB::fetchOne('SELECT id FROM advertising_online_payments LIMIT 1');
        return $settings !== null || $attempts !== null;
    }

    public function statuses(): array
    {
        $rows = DB::fetchAll('SELECT provider,mode,secret_sealed,version FROM advertising_gateway_settings');
        $byId = array_column($rows, null, 'provider'); $out = [];
        foreach (self::PROVIDERS as $id=>$label) {
            $row = $byId[$id] ?? null; $error = '';
            $configured = $row !== null && !empty($row['secret_sealed']);
            if ($configured) {
                try { $this->vault->decrypt($id, self::sealed((string)$row['secret_sealed']) ?? []); }
                catch (Throwable $e) { $error = 'کلید ذخیره‌شده قابل خواندن نیست؛ پشتیبان کلید خصوصی سرور را بررسی کنید.'; }
            }
            $out[] = ['id'=>$id, 'label'=>$label, 'mode'=>(string)($row['mode'] ?? 'disabled'),
                'key_configured'=>$configured, 'version'=>(int)($row['version'] ?? 0), 'error'=>$error];
        }
        return $out;
    }

    /** Returns only the encrypted immutable snapshot; never returns credentials to the UI. */
    public function snapshot(string $provider): array
    {
        if (!isset(self::PROVIDERS[$provider])) throw new InvalidArgumentException('درگاه معتبر نیست.');
        $row = DB::fetchOne('SELECT mode,secret_sealed,version FROM advertising_gateway_settings WHERE provider=:id FOR UPDATE', ['id'=>$provider]);
        if (!$row || !in_array($row['mode'], ['sandbox','live'], true)) throw new DomainException('این درگاه فعال نیست.', 409);
        $sealed = self::sealed((string)($row['secret_sealed'] ?? ''));
        if (!$sealed) throw new DomainException('کلید درگاه آماده نیست.', 409);
        $secret = $this->vault->decrypt($provider, $sealed);
        self::validateModeCredential($provider, (string)$row['mode'], $secret);
        return ['mode'=>(string)$row['mode'], 'secret_sealed'=>(string)$row['secret_sealed'], 'version'=>(int)$row['version']];
    }

    public function decryptSnapshot(string $provider, string $sealedJson, string $mode): string
    {
        if (!isset(self::PROVIDERS[$provider]) || !in_array($mode, ['sandbox','live'], true)) throw new DomainException('درگاه تراکنش معتبر نیست.', 409);
        $secret = $this->vault->decrypt($provider, self::sealed($sealedJson) ?? []);
        self::validateModeCredential($provider, $mode, $secret);
        return $secret;
    }

    public function save(string $provider, string $mode, string $newKey, int $version, bool $confirmLive, int $userId, bool $remove = false): void
    {
        if (!isset(self::PROVIDERS[$provider]) || !in_array($mode, ['disabled','sandbox','live'], true) || $version < 0) throw new InvalidArgumentException('تنظیمات درگاه معتبر نیست.');
        if ($mode === 'live' && !$confirmLive) throw new InvalidArgumentException('پیش از فعال‌سازی واقعی، هزینه و شرایط درگاه را تأیید کنید.');
        $newKey = trim($newKey);
        if ($newKey !== '' && !self::validKey($newKey)) throw new InvalidArgumentException('فرمت کلید درگاه معتبر نیست.');
        $pdo = DB::connection(); $pdo->beginTransaction();
        try {
            DB::query("INSERT IGNORE INTO advertising_gateway_settings (provider,mode,version) VALUES (:id,'disabled',0)", ['id'=>$provider]);
            $row = DB::fetchOne('SELECT * FROM advertising_gateway_settings WHERE provider=:id FOR UPDATE', ['id'=>$provider]);
            if (!$row || (int)$row['version'] !== $version) throw new DomainException('تنظیمات هم‌زمان تغییر کرده‌اند؛ صفحه را تازه کنید.', 409);
            $sealed = self::sealed($row['secret_sealed']);
            if ($remove) { $sealed = null; $mode = 'disabled'; }
            elseif ($newKey !== '') $sealed = $this->vault->encrypt($provider, $newKey, !self::hasEncryptedSecrets());
            if ($mode !== 'disabled') {
                if (!$sealed) throw new InvalidArgumentException('برای فعال‌سازی، کلید درگاه را وارد کنید.');
                $key = $this->vault->decrypt($provider, $sealed);
                self::validateModeCredential($provider, $mode, $key);
            }
            $json = $sealed === null ? null : json_encode($sealed, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            DB::query('UPDATE advertising_gateway_settings SET mode=:mode,secret_sealed=:secret,version=version+1,updated_by=:user,updated_at=NOW() WHERE provider=:id',
                ['mode'=>$mode,'secret'=>$json,'user'=>$userId,'id'=>$provider]);
            DB::query('INSERT INTO advertising_gateway_settings_log (provider,old_mode,new_mode,key_action,version,changed_by) VALUES (:id,:old,:new,:action,:version,:user)',
                ['id'=>$provider,'old'=>$row['mode'],'new'=>$mode,'action'=>$remove ? 'removed' : ($newKey !== '' ? 'replaced' : 'unchanged'),'version'=>$version+1,'user'=>$userId]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
