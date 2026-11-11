<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class AiProviderVaultException extends RuntimeException {}

/**
 * Encrypts provider keys stored in the existing settings table. The master key
 * stays outside public_html, or can be supplied through the server environment.
 * A database-only backup does not contain enough information to decrypt keys.
 */
final class AiProviderVault
{
    private const CIPHER = 'aes-256-gcm';
    private const AAD_PREFIX = 'hamihub.ai.provider.v1:';

    public function __construct(private readonly string $basePath) {}

    /** @return array{nonce:string,ciphertext:string,tag:string} */
    public function encrypt(string $provider, string $secret, bool $mayCreateMaster): array
    {
        return self::seal($provider, $secret, $this->masterKey($mayCreateMaster));
    }

    public function decrypt(string $provider, array $sealed): string
    {
        return self::open($provider, $sealed, $this->masterKey(false));
    }

    /** These pure functions also allow synthetic cryptographic tests. */
    public static function seal(string $provider, string $secret, string $master): array
    {
        if (!function_exists('openssl_encrypt') || strlen($master) !== 32) {
            throw new AiProviderVaultException('امکان رمزگذاری کلید روی هاست آماده نیست.');
        }
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($secret, self::CIPHER, $master, OPENSSL_RAW_DATA, $nonce, $tag, self::AAD_PREFIX . $provider, 16);
        if (!is_string($ciphertext) || strlen($tag) !== 16) throw new AiProviderVaultException('رمزگذاری کلید انجام نشد.');
        return ['nonce'=>base64_encode($nonce), 'ciphertext'=>base64_encode($ciphertext), 'tag'=>base64_encode($tag)];
    }

    public static function open(string $provider, array $sealed, string $master): string
    {
        if (!function_exists('openssl_decrypt') || strlen($master) !== 32) throw new AiProviderVaultException('امکان خواندن کلید خصوصی آماده نیست.');
        $nonce = isset($sealed['nonce']) && is_string($sealed['nonce']) ? base64_decode($sealed['nonce'], true) : false;
        $ciphertext = isset($sealed['ciphertext']) && is_string($sealed['ciphertext']) ? base64_decode($sealed['ciphertext'], true) : false;
        $tag = isset($sealed['tag']) && is_string($sealed['tag']) ? base64_decode($sealed['tag'], true) : false;
        if (!is_string($nonce) || strlen($nonce) !== 12 || !is_string($ciphertext) || $ciphertext === '' || !is_string($tag) || strlen($tag) !== 16) {
            throw new AiProviderVaultException('کلید ذخیره‌شده قابل خواندن نیست؛ نسخه پشتیبان را بررسی کنید.');
        }
        $secret = openssl_decrypt($ciphertext, self::CIPHER, $master, OPENSSL_RAW_DATA, $nonce, $tag, self::AAD_PREFIX . $provider);
        if (!is_string($secret) || $secret === '') throw new AiProviderVaultException('کلید ذخیره‌شده قابل خواندن نیست؛ نسخه پشتیبان را بررسی کنید.');
        return $secret;
    }

    private function masterKey(bool $mayCreate): string
    {
        // Optional stable 32-byte base64 environment secret for multiple app nodes.
        $env = getenv('HHX_AI_VAULT_KEY');
        if (is_string($env) && $env !== '') {
            $key = base64_decode($env, true);
            if (!is_string($key) || strlen($key) !== 32) throw new AiProviderVaultException('کلید رمزگذاری سرور معتبر نیست.');
            return $key;
        }

        $storage = realpath($this->basePath . '/storage');
        $webroot = realpath($this->basePath . '/public_html');
        if ($storage === false || $webroot === false || !is_dir($storage) || self::inside($storage, $webroot)) {
            throw new AiProviderVaultException('پوشه خصوصی storage خارج از ریشه وب آماده نیست.');
        }
        // Also fail closed for a misconfigured server serving the project root.
        $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($documentRoot !== false && self::inside($storage, $documentRoot)) {
            throw new AiProviderVaultException('پوشه storage نباید در ریشه وب قابل دسترس باشد.');
        }
        $dir = $storage . '/private';
        if (is_link($dir)) throw new AiProviderVaultException('مسیر ذخیره خصوصی معتبر نیست.');
        if (!is_dir($dir)) {
            $old = umask(0077);
            try { if (!@mkdir($dir, 0700) && !is_dir($dir)) throw new AiProviderVaultException('پوشه خصوصی روی هاست ساخته نشد.'); }
            finally { umask($old); }
        }
        self::privatePermissions($dir, 0700);
        $lockPath = $dir . '/ai-provider.lock';
        $keyPath = $dir . '/ai-provider-master.key';
        if (is_link($lockPath) || is_link($keyPath)) throw new AiProviderVaultException('مسیر کلید خصوصی معتبر نیست.');
        $old = umask(0077);
        try { $lock = @fopen($lockPath, 'c+b'); } finally { umask($old); }
        if ($lock === false) throw new AiProviderVaultException('دسترسی نوشتن به پوشه خصوصی وجود ندارد.');
        try {
            self::privatePermissions($lockPath, 0600);
            if (!flock($lock, LOCK_EX)) throw new AiProviderVaultException('قفل ذخیره کلید آماده نیست.');
            if (!is_file($keyPath)) {
                if (!$mayCreate) throw new AiProviderVaultException('کلید خصوصی سرور پیدا نشد؛ پشتیبان فایل‌ها را بازیابی کنید.');
                $old = umask(0077);
                try { $file = @fopen($keyPath, 'x+b'); } finally { umask($old); }
                if ($file === false) throw new AiProviderVaultException('ساخت کلید خصوصی سرور انجام نشد.');
                $ok = false;
                try {
                    $key = random_bytes(32);
                    $ok = fwrite($file, $key) === 32 && fflush($file);
                } finally { fclose($file); }
                if (!$ok) { @unlink($keyPath); throw new AiProviderVaultException('ذخیره کلید خصوصی سرور انجام نشد.'); }
            }
            self::privatePermissions($keyPath, 0600);
            $key = @file_get_contents($keyPath);
            if (!is_string($key) || strlen($key) !== 32) throw new AiProviderVaultException('کلید خصوصی سرور قابل خواندن نیست؛ پشتیبان را بررسی کنید.');
            return $key;
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function inside(string $path, string $parent): bool
    {
        $parent = rtrim($parent, '/');
        return $path === $parent || str_starts_with($path, $parent . '/');
    }

    private static function privatePermissions(string $path, int $mode): void
    {
        if (!@chmod($path, $mode)) throw new AiProviderVaultException('سطح دسترسی امن برای ذخیره کلید فراهم نیست.');
        clearstatcache(true, $path);
        $permissions = @fileperms($path);
        if ($permissions === false || ($permissions & 0077) !== 0) throw new AiProviderVaultException('سطح دسترسی امن برای ذخیره کلید فراهم نیست.');
    }
}
