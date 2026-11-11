<?php
declare(strict_types=1);

/**
 * فایل تنظیمات برنامه collector-remote (که روی کامپیوتر شخصی شما اجرا می‌شود، نه روی سرور هاست)
 * تمام تایم‌اوت‌ها دقیقاً طبق اصلاحیه ۲ پرامپت تنظیم شده‌اند و قابل ویرایش هستند.
 */

return [
    'server' => [
        // آدرس سایت شما روی هاست DirectAdmin
        'base_url' => 'https://hamihubx.ir',
        // توکن Bearer که در پنل مدیریت (شهرها و جمع‌آوری) نمایش داده می‌شود
        'bearer_token' => '',
        // تایم‌اوت ارتباط با سرور هاست (اصلاحیه ۲: CONNECTTIMEOUT=5 و TIMEOUT=12)
        'connect_timeout' => 5,
        'timeout' => 12,
        // حداکثر آیتم در هر بسته ارسالی به /api/v1/collector/ingest
        'batch_size' => 200,
    ],

    'timeouts' => [
        // تایم‌اوت Overpass API (اصلاحیه ۲: TIMEOUT=90)
        'overpass_timeout' => 90,
        // تایم‌اوت‌های مدل Async ابزار gosom/google-maps-scraper روی کامپیوتر شما
        'gosom_request_timeout' => 12,
        'gosom_poll_interval_sec' => 5,
        'gosom_max_wait_sec' => 180,
        // تایم‌اوت API هوش مصنوعی (اصلاحیه ۲: TIMEOUT=120 - برای مرحله S5)
        'ai_timeout' => 120,
    ],

    'providers' => [
        'gosom' => [
            'enabled' => true,
            // آدرس سرویس محلی gosom/google-maps-scraper روی کامپیوتر شما
            'base_url' => 'http://127.0.0.1:8080',
        ],
        'osm' => [
            'enabled' => true,
            'overpass_endpoint' => 'https://overpass-api.de/api/interpreter',
            'nominatim_endpoint' => 'https://nominatim.openstreetmap.org/search',
            'user_agent' => 'HamiHubX-Gilan-Directory/1.0 (https://hamihubx.ir)',
        ],
        'neshan' => [
            'enabled' => true,
            'api_key' => '', // کلید رسمی API نشان (در صورت داشتن کلید وارد کنید)
            'search_url' => 'https://api.neshan.org/v1/search',
        ],
        'csv' => [
            'enabled' => true,
            // مسیر پوشه فایل‌های CSV روی کامپیوتر شما
            'import_dir' => __DIR__ . '/imports',
        ],
        'wikidata' => [
            'enabled' => false,
            'endpoint' => 'https://query.wikidata.org/sparql',
        ],
        'google_places' => [
            'enabled' => false,
            'api_key' => '',
        ],
        'balad' => [
            'enabled' => false,
        ],
    ],
];
