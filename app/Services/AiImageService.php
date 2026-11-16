<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * S30 — تولید تصویر hero مقاله‌ها با ورکر مستقل Cloudflare (flux-1-schnell)
 *
 * کانفیگ در ردیف settings با کلید ai_image_v1 نگهداری می‌شود و سکرت ورکر
 * با AiProviderVault (AES-256-GCM، کلید اصلی خارج از public_html) رمزنگاری می‌شود.
 * پاسخ ورکر (base64) در یک فایل موقت نوشته و از طریق MediaService::handleUpload
 * به یک ردیف استاندارد media (سه واریانت WebP) تبدیل می‌شود.
 */
final class AiImageService
{
    private const KEY = 'ai_image_v1';
    private const MODEL_DEFAULT = 'flux-1-schnell';
    private const CURL_CONNECT_TIMEOUT = 8;
    private const CURL_TIMEOUT = 90;
    private const RESPONSE_MAX_BYTES = 8 * 1024 * 1024;

    /** لاحق سبک ثابت — تصویر شاخص سردبیری، بدون متن و واترمارک */
    private const STYLE_SUFFIX = ', photorealistic editorial photograph, warm natural light, high quality, no text, no watermark, no people looking at camera';

    private AiProviderVault $vault;

    public function __construct(string $basePath)
    {
        $this->vault = new AiProviderVault($basePath);
    }

    /** وضعیت کانفیگ برای پنل (سکرت هرگز برگردانده نمی‌شود) */
    public function status(): array
    {
        $state = $this->decode();
        return [
            'configured' => $state !== null && is_array($state['secret'] ?? null),
            'enabled'    => (bool) ($state['enabled'] ?? false),
            'endpoint'   => (string) ($state['endpoint'] ?? ''),
            'model'      => (string) ($state['model'] ?? self::MODEL_DEFAULT),
            'version'    => (int) ($state['version'] ?? 0),
        ];
    }

    /**
     * ذخیره کانفیگ (آدرس کامل اندپوینت ورکر مثل https://xxx.workers.dev/image)
     * سکرت خالی = حفظ سکرت فعلی. حذف با remove=true.
     */
    public function save(string $endpoint, string $newSecret, bool $enabled, bool $confirmed, int $version, bool $remove = false): void
    {
        $endpoint = rtrim(trim($endpoint), '/');
        $endpointValid = $endpoint === ''
            || (preg_match('#^https://[A-Za-z0-9._~:/-]+$#D', $endpoint) === 1 && !str_contains($endpoint, '?'));
        if (!$endpointValid) {
            throw new InvalidArgumentException('نشانی اندپوینت ورکر معتبر نیست؛ آدرس https کامل و بدون پارامتر ورودی وارد کنید.');
        }
        if ($version < 0) throw new InvalidArgumentException('نسخه تنظیمات معتبر نیست.');
        if (!$remove && $enabled && !$confirmed) {
            throw new InvalidArgumentException('پیش از فعال‌سازی، مصرف neuron و پلن رایگان Workers AI را بررسی و تأیید کنید.');
        }

        $pdo = DB::connection();
        $pdo->beginTransaction();
        try {
            DB::query('INSERT IGNORE INTO settings (k,v) VALUES (:k, :v)', ['k' => self::KEY, 'v' => '']);
            $row = DB::fetchOne('SELECT v FROM settings WHERE k = :k FOR UPDATE', ['k' => self::KEY]);
            if ($row === null) throw new RuntimeException('ذخیره تنظیمات انجام نشد.');
            $state = $this->decodeState((string) ($row['v'] ?? ''));
            if ((int) ($state['version'] ?? 0) !== $version) {
                throw new RuntimeException('تنظیمات هم‌زمان تغییر کرده‌اند؛ صفحه را تازه و دوباره تلاش کنید.', 409);
            }

            $secret = null;
            if (!$remove) {
                $newSecret = trim($newSecret);
                if ($newSecret !== '') {
                    $secret = $this->vault->encrypt('ai-image-worker', $newSecret, true);
                } elseif (is_array($state['secret'] ?? null)) {
                    $secret = $state['secret'];
                    if ($enabled && $this->vault->decrypt('ai-image-worker', $secret) === '') {
                        throw new RuntimeException('سکرت ذخیره‌شده معتبر نیست.');
                    }
                }
                if ($enabled && ($secret === null || $endpoint === '')) {
                    throw new InvalidArgumentException('برای فعال‌سازی، نشانی اندپوینت و سکرت ورکر لازم است.');
                }
            }

            $next = [
                'version' => $version + 1,
                'enabled' => $remove ? false : $enabled,
                'free_tier_confirmed' => $remove ? false : $confirmed,
                'endpoint' => $endpoint !== '' ? $endpoint : ($state['endpoint'] ?? ''),
                'model'    => self::MODEL_DEFAULT,
                'secret'   => $secret,
            ];
            DB::query(
                'UPDATE settings SET v = :v, updated_at = NOW() WHERE k = :k',
                ['v' => json_encode($next, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'k' => self::KEY]
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * تولید تصویر و ثبت آن به‌عنوان ردیف استاندارد media.
     * خروجی: آرایه media (شامل id و file_path_480/800/1200).
     *
     * @return array<string, mixed>
     */
    public function generate(string $prompt, int $width = 1024, int $height = 576, string $altText = ''): array
    {
        $state = $this->decode();
        if ($state === null || empty($state['enabled']) || !is_array($state['secret'] ?? null)) {
            throw new RuntimeException('سرویس تصویر فعال نیست؛ ابتدا اندپوینت و سکرت ورکر را در پنل ذخیره کنید.');
        }
        $endpoint = trim((string) ($state['endpoint'] ?? ''));
        if ($endpoint === '' || !preg_match('#^https://#', $endpoint)) {
            throw new RuntimeException('نشانی اندپوینت ورکر ثبت نشده است.');
        }

        $prompt = trim($prompt);
        if (mb_strlen($prompt) < 5) throw new InvalidArgumentException('توضیح تصویر خیلی کوتاه است.');
        if (mb_strlen($prompt) > 1200) throw new InvalidArgumentException('توضیح تصویر حداکثر ۱۲۰۰ کاراکتر است.');
        $width = max(256, min(1024, (int) (round($width / 16) * 16)));
        $height = max(256, min(1024, (int) (round($height / 16) * 16)));

        $secret = $this->vault->decrypt('ai-image-worker', $state['secret']);

        [$httpCode, $body] = $this->postJson($endpoint, $secret, [
            'prompt' => $prompt . self::STYLE_SUFFIX,
            'width'  => $width,
            'height' => $height,
            'steps'  => 4,
        ]);

        AiServiceLogger::log('ai-image-worker', self::MODEL_DEFAULT, $httpCode === 200 && !empty($body['ok']), $httpCode, (string) ($body['error'] ?? 'ok'));

        if ($httpCode === 401) {
            throw new RuntimeException('سکرت ورکر تصویر پذیرفته نشد (401)؛ مقدار آن را با Secret ورکر تطبیق دهید.');
        }
        if ($httpCode !== 200 || empty($body['ok']) || !is_string($body['image'] ?? null) || $body['image'] === '') {
            $detail = is_string($body['detail'] ?? null) ? $body['detail'] : '';
            throw new RuntimeException('تولید تصویر انجام نشد' . ($detail !== '' ? ' (' . $detail . ')' : '') . '؛ بعداً تلاش کنید.');
        }

        $image = base64_decode($body['image'], true);
        if ($image === false || strlen($image) < 1024) {
            throw new RuntimeException('تصویر برگشتی نامعتبر است.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'hhximg_');
        if ($tmp === false || file_put_contents($tmp, $image) !== strlen($image)) {
            if (is_string($tmp)) @unlink($tmp);
            throw new RuntimeException('نوشتن فایل موقت تصویر انجام نشد.');
        }

        try {
            // MediaService خودش فحش MIME می‌کند و به WebP سه‌سایز تبدیل می‌کند؛ اگر فرمت واقعی
            // قابل پردازش نباشد استثنای امن پرتاب می‌شود (بدون ذخیرهٔ فایل مشکوک).
            return MediaService::handleUpload(
                ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => strlen($image)],
                $altText
            );
        } finally {
            @unlink($tmp);
        }
    }

    /** @return array{0:int, 1:array<string,mixed>} */
    private function postJson(string $endpoint, string $secret, array $payload): array
    {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CURL_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::CURL_TIMEOUT,
            CURLOPT_MAXFILESIZE => self::RESPONSE_MAX_BYTES,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $secret,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [0, ['ok' => false, 'error' => 'curl: ' . $err]];
        }
        $body = json_decode((string) $raw, true);
        if (!is_array($body)) {
            return [$httpCode, ['ok' => false, 'error' => 'پاسخ غیر JSON از ورکر']];
        }
        return [$httpCode, $body];
    }

    private function decode(): ?array
    {
        $row = DB::fetchOne('SELECT v FROM settings WHERE k = :k LIMIT 1', ['k' => self::KEY]);
        if ($row === null) return null;
        return $this->decodeState((string) ($row['v'] ?? ''));
    }

    private function decodeState(string $json): ?array
    {
        if (trim($json) === '') return [];
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }
}
