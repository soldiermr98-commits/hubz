<?php
declare(strict_types=1);

namespace App\Core;

/**
 * مدیریت درخواست‌های ورودی HTTP، هدرها، بدنه JSON و آدرس IP کاربر
 */
final class Request
{
    private array $jsonBody = [];
    private bool $jsonParsed = false;

    /**
     * متد درخواست (GET, POST, PUT, DELETE, ...)
     */
    public function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    /**
     * مسیر تمیز بدون Query String
     */
    public function path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }
        return '/' . trim($path, '/');
    }

    /**
     * دریافت بدنه خام درخواست
     */
    public function rawBody(): string
    {
        $raw = file_get_contents('php://input');
        return is_string($raw) ? $raw : '';
    }

    /**
     * دریافت داده‌های JSON ارسال‌شده در بدنه درخواست
     */
    public function json(): array
    {
        if ($this->jsonParsed) {
            return $this->jsonBody;
        }

        $this->jsonParsed = true;
        $raw = $this->rawBody();
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $this->jsonBody = $decoded;
        }

        return $this->jsonBody;
    }

    /**
     * دریافت پارامتر از Query String ($_GET)
     */
    public function query(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_GET)) {
            return $_GET[$key];
        }
        return $default;
    }

    /**
     * دریافت مقدار از JSON یا POST یا GET
     */
    public function input(string $key, mixed $default = null): mixed
    {
        $json = $this->json();
        if (array_key_exists($key, $json)) {
            return $json[$key];
        }
        if (array_key_exists($key, $_POST)) {
            return $_POST[$key];
        }
        if (array_key_exists($key, $_GET)) {
            return $_GET[$key];
        }
        return $default;
    }

    /**
     * دریافت هدر مشخص با نام (غیرحساس به بزرگی و کوچکی حروف)
     */
    public function header(string $name, ?string $default = null): ?string
    {
        $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$normalized])) {
            return trim((string) $_SERVER[$normalized]);
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $k => $v) {
                if (strcasecmp((string) $k, $name) === 0) {
                    return trim((string) $v);
                }
            }
        }

        return $default;
    }

    /**
     * استخراج توکن Bearer از هدر Authorization
     */
    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization');
        if ($auth === null) {
            return null;
        }
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * دریافت آدرس IP کلاینت
     */
    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
