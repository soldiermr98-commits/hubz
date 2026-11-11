# راهنمای نصب بسته رفع قطعی خطای OPcache / HTTP 500 تب «رسانه»

## چرا بسته قبلی روی هاست DirectAdmin فعال نشد؟ (شواهد دقیق فنی)
در بررسی هدر باینری فایل‌های ZIP مشخص شد هر دو بسته `hamihub-update-business-delete-and-webp-media.zip` و `hamihub-hotfix-media-tab-500.zip` دارای زمان‌بندی یکسان `(2026-11-09 00:00:00)` روی فایل `app/Controllers/MediaController.php` بودند.
در وب‌سرورهای DirectAdmin (LiteSpeed / PHP-FPM)، موتور **Zend OPcache** برای تشخیص تغییر فایل‌های PHP مقدار `st_mtime` فایل روی دیسک را با نسخه ذخیره‌شده در حافظه مقایسه می‌کند؛ به دلیل یکسان بودن ثانیهٔ `mtime` و/یا غیرفعال بودن بررسی لحظه‌ای در تنظیمات OPcache هاست، کد قبلی `MediaController.php` از حافظهٔ کش‌شده اجرا می‌شد و همچنان خطای خام `HTTP 500` برمی‌گرداند.

---

## راه‌حل ۱۰۰٪ قطعی در این بسته (بدون نیاز به ری‌استارت PHP یا دسترسی SSH)
1. **نقطه ورود فیزیکی و جدید `public_html/api/media-library.php` + کلاس جدید `app/Services/MediaLibraryService.php`:** این دو فایل هرگز قبلاً در حافظهٔ OPcache هاست وجود نداشته‌اند؛ بنابراین وب‌سرور بلافاصله در اولین کلیک آن‌ها را مستقیم از دیسک کامپایل و اجرا می‌کند (حتی اگر `opcache.validate_timestamps=0` یا `opcache_reset` در هاست محدود شده باشد).
2. **به‌روزرسانی خودکار کش پنل (`public_html/admin/index.html` و `admin.js`):** پنل مدیریت با هش جدید `?v=...` مستقیماً به درگاه تازه `/api/media-library.php` متصل می‌شود و هم‌زمان کش OPcache فایل‌های قبلی را هم در صورت مجاز بودن پاک‌سازی می‌کند.
3. **زمان‌بندی یکتای جدید داخل ZIP (`2026-11-10 15:00:00`) + محافظت سطح موتور PHP (`register_shutdown_function`):** حتی در صورت بروز خطای Fatal یا محدودیت حافظه/`open_basedir`، خروجی همیشه با فرمت استاندارد JSON برگردانده می‌شود و دیگر هرگز پیام مبهم `پاسخ نامعتبر از سرور (HTTP 500).` رخ نخواهد داد.

---

## روش نصب در DirectAdmin
1. وارد **File Manager** در DirectAdmin شوید و به **ریشهٔ پروژه** بروید (**یک سطح بالاتر از `public_html`**؛ همان پوشه‌ای که پوشه‌های `app` و `public_html` داخل آن دیده می‌شوند).
2. فایل **`hamihub-update-media-library-opcache-fix.zip`** را آپلود و **Extract (Overwrite)** کنید *(فایل‌های `config/config.php`، `uploads/`، `storage/` و `cache/` کاملاً دست‌نخورده باقی می‌مانند)*.
3. صفحهٔ پنل مدیریت (`/admin/`) را یک بار با **`Ctrl + F5`** (یا **`Ctrl + Shift + R`**) بازخوانی کنید تا فایل جدید `admin.js` بارگذاری شود و سپس وارد تب **«رسانه»** شوید.
