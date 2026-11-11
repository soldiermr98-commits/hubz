<?php
declare(strict_types=1);

/**
 * فایل تنظیمات نمونه ورکر هوش مصنوعی راه دور (ai-worker/config.sample.php - مرحله S5)
 * این پوشه می‌تواند روی کامپیوتر شخصی مدیر اجرا شود تا بار پردازش LLM روی هاست اشتراکی نیفتد.
 */

return [
    // آدرس پایه سایت حامی‌هاب روی هاست DirectAdmin
    'server_base_url' => 'https://hamihubx.ir',

    // توکن Bearer مشترک با پنل مدیریت (قابل مشاهده و چرخش در پنل ادمین)
    'bearer_token'    => '',

    // تایم‌اوت‌های اتصال به سرور هاست اشتراکی
    'connect_timeout' => 5,
    'request_timeout' => 15,

    // تنظیمات موتور هوش مصنوعی (در صورت خالی بودن api_key از موتور قطعی بدون توهم داخلی استفاده می‌شود)
    'llm' => [
        'enabled'  => false,
        'endpoint' => 'https://api.openai.com/v1/chat/completions', // یا آدرس Ollama محلی: http://127.0.0.1:11434/v1/chat/completions
        'api_key'  => '',
        'model'    => 'gpt-4o-mini',
        'timeout'  => 45,
    ],
];
