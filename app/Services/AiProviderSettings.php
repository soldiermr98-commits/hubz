<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Admin-managed overrides use the existing settings table; no schema migration is needed. */
final class AiProviderSettings
{
    private const PREFIX = 'ai_provider_v1_';
    private AiProviderVault $vault;

    public function __construct(private readonly array $baseConfig, string $basePath)
    {
        $this->vault = new AiProviderVault($basePath);
    }

    /** Effective runtime configuration. A broken override fails closed, never falls back to a stale key. */
    public function effectiveConfig(): array
    {
        $config = $this->baseConfig;
        $config['providers'] = [];
        foreach (AiArticleService::catalog() as $id=>$definition) {
            try {
                $config['providers'][$id] = $this->effectiveProvider($id, $this->readState($id));
            } catch (RuntimeException $e) {
                $config['providers'][$id] = ['enabled'=>false, 'free_tier_confirmed'=>false, 'api_key'=>'', 'model'=>$definition['default_model'], 'base_url'=>''];
            }
        }
        return $config;
    }

    /** Only non-secret status, never a key, ciphertext, or partial key. */
    public function managementStatus(): array
    {
        $out = [];
        foreach (AiArticleService::catalog() as $id=>$definition) {
            $state = $this->readState($id);
            $legacy = $this->legacy($id);
            $source = $state !== null ? 'panel' : (($legacy['api_key'] ?? '') !== '' ? 'file' : 'none');
            $configured = $state !== null ? is_array($state['secret'] ?? null) : (($legacy['api_key'] ?? '') !== '');
            $error = '';
            try {
                $effective = $this->effectiveProvider($id, $state);
            } catch (RuntimeException $e) {
                $error = 'کلید ذخیره‌شده قابل خواندن نیست؛ پشتیبان کلید خصوصی سرور را بررسی کنید.';
                $effective = ['enabled'=>false, 'free_tier_confirmed'=>false, 'api_key'=>'', 'model'=>$definition['default_model'], 'base_url'=>''];
            }
            $test = new AiArticleService(['providers'=>[$id=>$effective]]);
            $statuses = array_column($test->status(), null, 'id');
            $out[] = [
                'id'=>$id, 'label'=>$definition['label'],
                'model'=>(string)($effective['model'] ?: $definition['default_model']),
                'default_model'=>$definition['default_model'], 'suggested_models'=>$definition['suggested_models'],
                'available'=>(bool)$statuses[$id]['available'],
                'enabled'=>(bool)$effective['enabled'],
                'free_tier_confirmed'=>(bool)$effective['free_tier_confirmed'],
                'key_configured'=>$configured, 'source'=>$source,
                'version'=>(int)($state['version'] ?? 0), 'error'=>$error,
                'base_url'=>(string)($effective['base_url'] ?? ''),
            ];
        }
        return $out;
    }

    /** Save one provider, preserving a blank key and rejecting stale administrator edits. */
    public function save(string $id, string $model, string $newKey, bool $enabled, bool $confirmed, int $version, bool $remove = false, string $baseUrl = ''): void
    {
        $catalog = AiArticleService::catalog();
        if (!isset($catalog[$id])) throw new InvalidArgumentException('سرویس انتخاب‌شده معتبر نیست.');
        $model = trim($model);
        if ($model === '') $model = $catalog[$id]['default_model'];
        if (!AiArticleService::validModel($id, $model)) throw new InvalidArgumentException('نام مدل معتبر نیست؛ OpenRouter فقط مدل رایگان را می‌پذیرد.');
        $newKey = trim($newKey);
        if ($newKey !== '' && !self::validKey($newKey)) throw new InvalidArgumentException('فرمت کلید معتبر نیست؛ فاصله و خط جدید را حذف کنید.');
        // S9: نشانی جایگزین (پراکسی/Cloudflare Worker) اختیاری است؛ فقط https کامل بدون پارامتر.
        $baseUrl = trim($baseUrl);
        if ($baseUrl !== '' && !AiArticleService::validBaseUrl($baseUrl)) throw new InvalidArgumentException('نشانی جایگزین معتبر نیست؛ آدرس https کامل و بدون پارامتر ورودی وارد کنید.');
        if ($version < 0) throw new InvalidArgumentException('نسخه تنظیمات معتبر نیست.');
        if (!$remove && $enabled && !$confirmed) throw new InvalidArgumentException('پیش از فعال‌سازی، سهمیه و احتمال هزینه حساب را بررسی و تأیید کنید.');

        $pdo = DB::connection();
        $pdo->beginTransaction();
        try {
            $key = self::PREFIX . $id;
            DB::query('INSERT IGNORE INTO settings (k,v) VALUES (:k, :v)', ['k'=>$key, 'v'=>'']);
            $row = DB::fetchOne('SELECT v FROM settings WHERE k=:k FOR UPDATE', ['k'=>$key]);
            if ($row === null) throw new RuntimeException('ذخیره تنظیمات انجام نشد.');
            $state = self::decode((string)($row['v'] ?? ''));
            if ((int)($state['version'] ?? 0) !== $version) throw new RuntimeException('تنظیمات هم‌زمان تغییر کرده‌اند؛ صفحه را تازه و دوباره تلاش کنید.', 409);

            $secret = null;
            if (!$remove) {
                if ($newKey !== '') {
                    $secret = $this->vault->encrypt($id, $newKey, !$this->hasEncryptedSecrets());
                } elseif ($state !== null && is_array($state['secret'] ?? null)) {
                    $secret = $state['secret'];
                    // Never activate an unreadable key as if it worked.
                    if ($enabled && !self::validKey($this->vault->decrypt($id, $secret))) throw new RuntimeException('کلید ذخیره‌شده معتبر نیست.');
                } elseif ($state === null) {
                    $oldKey = $this->legacy($id)['api_key'] ?? '';
                    if (is_string($oldKey) && $oldKey !== '') {
                        if (!self::validKey($oldKey)) throw new RuntimeException('کلید قبلی معتبر نیست؛ کلید جدید وارد کنید.');
                        $secret = $this->vault->encrypt($id, $oldKey, !$this->hasEncryptedSecrets());
                    }
                }
                if ($enabled && $secret === null) throw new InvalidArgumentException('برای فعال‌سازی، کلید API را وارد کنید.');
            }
            $next = [
                'version'=>$version + 1, 'model'=>$model,
                'enabled'=>$remove ? false : $enabled,
                'free_tier_confirmed'=>$remove ? false : $confirmed,
                'secret'=>$secret,
                'base_url'=>$baseUrl !== '' ? $baseUrl : null,
            ];
            $json = json_encode($next, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            DB::query('UPDATE settings SET v=:v,updated_at=NOW() WHERE k=:k', ['v'=>$json, 'k'=>$key]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function readState(string $id): ?array
    {
        $row = DB::fetchOne('SELECT v FROM settings WHERE k=:k', ['k'=>self::PREFIX . $id]);
        return $row === null ? null : self::decode((string)($row['v'] ?? ''));
    }

    private static function decode(string $value): ?array
    {
        if ($value === '') return null;
        $data = json_decode($value, true);
        if (!is_array($data) || !isset($data['version']) || !is_int($data['version']) || $data['version'] < 1
            || !is_string($data['model'] ?? null) || !is_bool($data['enabled'] ?? null) || !is_bool($data['free_tier_confirmed'] ?? null)
            || !array_key_exists('secret', $data) || (!is_array($data['secret']) && $data['secret'] !== null)) {
            throw new RuntimeException('تنظیمات سرویس قابل خواندن نیست؛ پشتیبان را بررسی کنید.');
        }
        return $data;
    }

    private function effectiveProvider(string $id, ?array $state): array
    {
        $catalog = AiArticleService::catalog();
        if ($state === null) return $this->legacy($id);
        $secret = $state['secret'] === null ? '' : $this->vault->decrypt($id, $state['secret']);
        $baseUrl = is_string($state['base_url'] ?? null) ? trim((string)$state['base_url']) : '';
        return [
            'enabled'=>$state['enabled'], 'free_tier_confirmed'=>$state['free_tier_confirmed'],
            'api_key'=>$secret, 'model'=>$state['model'] ?: $catalog[$id]['default_model'],
            'base_url'=>$baseUrl !== '' && AiArticleService::validBaseUrl($baseUrl) ? $baseUrl : '',
        ];
    }

    private function legacy(string $id): array
    {
        $definition = AiArticleService::catalog()[$id];
        $providers = $this->baseConfig['providers'] ?? [];
        $old = is_array($providers) && is_array($providers[$id] ?? null) ? $providers[$id] : [];
        return [
            'enabled'=>($old['enabled'] ?? false) === true,
            'free_tier_confirmed'=>($old['free_tier_confirmed'] ?? false) === true,
            'api_key'=>is_string($old['api_key'] ?? null) ? trim($old['api_key']) : '',
            'model'=>is_string($old['model'] ?? null) && trim($old['model']) !== '' ? trim($old['model']) : $definition['default_model'],
            'base_url'=>is_string($old['base_url'] ?? null) ? trim((string)$old['base_url']) : '',
        ];
    }

    private function hasEncryptedSecrets(): bool
    {
        foreach (array_keys(AiArticleService::catalog()) as $id) {
            $state = $this->readState($id);
            if ($state !== null && is_array($state['secret'] ?? null)) return true;
        }
        return false;
    }

    private static function validKey(string $key): bool
    {
        return $key !== '' && strlen($key) <= 512 && !preg_match('/[\x00-\x20\x7f]/', $key);
    }
}
