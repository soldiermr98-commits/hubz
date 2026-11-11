<?php
declare(strict_types=1);

namespace App\Core;

/**
 * مسیریاب سبک و سریع برای صفحات عمومی و APIهای نسخه ۱ (/api/v1/*)
 */
final class Router
{
    /** @var array<int, array{method: string, pattern: string, regex: string, handler: callable}> */
    private array $routes = [];

    /**
     * ثبت مسیر جدید با پشتیبانی از پارامترهای پویا مانند /b/{slug}
     */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $normalizedPattern = '/' . trim($pattern, '/');
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn(array $m): string => '(?P<' . $m[1] . '>[a-z0-9\-]+)',
            $normalizedPattern
        );

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $normalizedPattern,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
        ];
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    /**
     * اجرای مسیر منطبق با درخواست فعلی
     */
    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path = $request->path();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $path, $matches)) {
                $params = [];
                foreach ($matches as $key => $val) {
                    if (is_string($key)) {
                        $params[$key] = $val;
                    }
                }

                ($route['handler'])($request, $params);
                return;
            }
        }

        if (str_starts_with($path, '/api/')) {
            Response::json([
                'ok' => false,
                'error' => 'مسیر درخواستی در API یافت نشد.',
                'path' => $path,
            ], 404);
        }

        Response::html(
            '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>۴۰۴ - صفحه پیدا نشد</title></head>' .
            '<body style="font-family:tahoma,sans-serif;text-align:center;padding:4rem;"><h1>۴۰۴ — صفحه مورد نظر در حامی‌هاب گیلان پیدا نشد</h1>' .
            '<p><a href="/">بازگشت به صفحه اصلی</a></p></body></html>',
            404
        );
    }
}
