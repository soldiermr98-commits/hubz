#!/usr/bin/env python3
"""
بسته‌ساز S26 — رفع اشکال آمار بازدید روی MariaDB (کلمهٔ رزروشدهٔ call)

قواعد بسته (منطبق با بسته‌سازهای قبلی):
- شامل: app/، public_html/ (بدون cache و uploads)، database/، cron/، bin/ و همین اسکریپت
- بدون: config/ (کانفیگ هاست)، storage/، collector-remote/، ai-worker/، tests/،
  src/ و فایل‌های محیط توسعهٔ پنل، release-files/، docs/، public/ (دارایی‌های dev)
- هر فایل داخل ZIP با SHA-256 در MANIFEST-SHA256.txt ثبت می‌شود
- ساختار ZIP همان درخت استقرار است: فایل‌ها از ریشهٔ ZIP به همان مسیرهای پروژه می‌نشینند
"""
import hashlib
import os
import zipfile
from datetime import datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
STAMP = datetime.now().strftime("%Y%m%d%H%M%S")
OUT_DIR = os.path.join(ROOT, "release-files", "s26-mariadb-analytics-hotfix")
OUT_ZIP = os.path.join(OUT_DIR, "hamihub-full-upgrade-to-s26.zip")

INCLUDE_DIRS = ["app", "public_html", "database", "cron", "bin"]
PUBLIC_HTML_SKIP_TOP = {"cache", "uploads"}
ROOT_INCLUDE_FILES = ["scripts/build_s26_master_rollup.py"]

INSTALL_FA = """# راهنمای ارتقای کامل hamihubx.ir تا S26 — رفع اشکال آمار بازدید روی MariaDB

**بسته:** `hamihub-full-upgrade-to-s26.zip` · **مایگریشن جدید:** ندارد · **تغییر پنل ادمین:** ندارد (بیلد S25 کافی است)

## این بسته چیست؟

رفع اشکال فوری صفحهٔ «آمار بازدیدها» پنل مدیریت. روی هاست لایو (MariaDB) همهٔ بخش‌های
داشبورد آمار با این خطا شکست می‌خوردند:

```
SQLSTATE[42000]: Syntax error or access violation: 1064
... near 'call, SUM(clicks_map) AS map, ...
```

**علت ریشه‌ای:** در دو کوئریِ خواندن آمار (روند روزانه و برترین‌ها) از نام مستعار
`AS call` استفاده شده بود؛ «CALL» در MariaDB/MySQL کلمهٔ رزروشده است و کوئری روی
سرور واقعی می‌شکند. همین کوئری‌ها در محیط توسعه بدون MariaDB واقعی از نظر نگارشی
«قابل اجرا» بودند و به همین دلیل تا امروز خود را نشان ندادند.

## کارهای انجام‌شده (S26)

1. **`app/Services/AnalyticsService.php`** — تنها فایل تغییر یافته:
   - کوئری روند روزانه و کوئری برترین‌ها: همهٔ نام‌های مستعار داخل بک‌تیک قرار گرفتند.
   - مرتب‌سازی برترین‌ها صریحاً روی عبارت تجمیعی انجام می‌شود تا ابهام «مستعار یا ستون
     هم‌نام» در MariaDB هم پیش نیاید.
   - کلیدهای JSON خروجی (call، map و…) بدون تغییر مانده‌اند؛ پنل ادمین نیازی به بیلد
     مجدد ندارد.
2. **بازبینی کامل کوئری‌های آمار** با فهرست کلمات رزروشده — تنها همین دو کوئری مستعار
   رزروشده داشتند؛ کوئری تشخیصی «بررسی دیتابیس» و ثبت رویدادها از ابتدا سالم بودند.

## راستی‌آزمایی (روی MariaDB واقعی، نه شبیه‌سازی)

- یک **MariaDB 11.8.8 واقعی** به‌صورت ایزوله اجرا شد و مایگریشن ۰۱۳ واقعی روی آن اعمال شد:
  - کوئری قدیمی **دقیقاً همان خطای لایو** را بازتولید کرد (ERROR 1064 near 'call').
  - کوئری‌های اصلاح‌شده و کل سرویس آمار واقعی (داشبورد، ثبت call/view/search، لاگ
    جزئیات، «بررسی دیتابیس»، خروجی اکسل، پاک‌سازی نگهداری) در ۲۸ بررسی سبز شدند.
  - حالت سخت `ONLY_FULL_GROUP_BY` هم جداگانه آزمایش شد.

## خط قرمزهای امنیتی بسته

- هیچ فایلی از `config/` داخل بسته نیست — کانفیگ هاست (`config.local.php`) دست‌نخورده می‌ماند.
- پوشه‌های `collector-remote/`، `ai-worker/`، `storage/`، `tests/`، `docs/` و دارایی‌های dev داخل بسته نیستند.
- `public_html/cache` و `public_html/uploads` جایگزین نمی‌شوند.
- هیچ مایگریشن جدیدی وجود ندارد؛ هیچ داده یا جدولی تغییر نمی‌کند.
- اثرانگشت SHA-256 تک‌تک فایل‌ها در `MANIFEST-SHA256.txt` داخل ZIP و `SHA256SUMS.txt` کنار بسته است.

## پیش‌نیاز

- از دیتابیس **و** فایل‌ها پشتیبان کامل بگیرید (phpMyAdmin → Export، و دانلود `public_html`).
  قانون ثابت: اول پشتیبان، بعد ارتقا.

## مراحل نصب

1. **پشتیبان:** مطابق پیش‌نیاز بالا.
2. **آپلود:** محتوای ZIP را در ریشهٔ پروژه روی هاست (کنار `public_html` و `app`) استخراج
   کنید؛ ساختار ZIP همان درخت استقرار است و فایل‌های هم‌مسیر جایگزین می‌شوند. تنها
   فایل تغییر یافته نسبت به S25 `app/Services/AnalyticsService.php` است — اگر ترجیح
   می‌دهید دستی آپلود کنید، فقط همین فایل را جایگزین نمایید.
3. **دسترسی‌ها:** نیازی به تغییر دسترسی نیست؛ همان ۶۴۴/۷۵۵ قبلی بماند.
4. **کش OPcache:** اگر OPcache فعال است، از پنل «ریست OPcache» را اجرا کنید یا چند
   دقیقه صبر کنید تا خودش به‌روز شود؛ سپس یک‌بار صفحهٔ آمار را باز کنید.
5. **تأیید نهایی:**
   - صفحهٔ «آمار بازدیدها» پنل را باز کنید — پیام‌های هشدار «خواندن … ناموفق بود» نباید
     وجود داشته باشد و روند روزانه/برترین‌ها باید با اعداد واقعی پر شود.
   - برای اطمینان کامل، دکمهٔ «بررسی دیتابیس» در همان صفحه را اجرا کنید؛ باید پیام
     «همهٔ بررسی‌ها موفق بود؛ ساختار دیتابیس برای آمار سالم است.» بدهد.

## اگر باز هم خطا دیدید

متن کامل خطای هر بخش در همان صفحه نمایش داده می‌شود (پیام‌ها بدون دادهٔ حساس کوتاه
شده‌اند). همان متن را برای پیگیری ارسال کنید؛ دکمهٔ «بررسی دیتابیس» هم جزئیات هر کوئری
را جداگانه نشان می‌دهد.

## وضعیت صف جمع‌آوری (خارج از محدودهٔ این بسته)

خطاهای قبلی جاب‌های نشان (HTTP 485) و Overpass (تایم‌اوت/۵۰۴) به این اشکال مربوط نیستند
و جداگانه پیگیری می‌شوند. حذف رکوردهای صف طبق بستهٔ S25 از تب «شهرها و جمع‌آوری» در
دسترس است.
"""


def sha256_of(path: str) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def collect_files():
    files = []
    for d in INCLUDE_DIRS:
        base = os.path.join(ROOT, d)
        for dirpath, dirnames, filenames in os.walk(base):
            rel_top = os.path.relpath(dirpath, base).split(os.sep)[0]
            if d == "public_html" and rel_top in PUBLIC_HTML_SKIP_TOP and dirpath != base:
                dirnames[:] = []
                continue
            dirnames[:] = [x for x in dirnames if x not in {".git", "node_modules"}]
            for fn in filenames:
                full = os.path.join(dirpath, fn)
                rel = os.path.relpath(full, ROOT)
                files.append(rel)
    for rel in ROOT_INCLUDE_FILES:
        if os.path.isfile(os.path.join(ROOT, rel)):
            files.append(rel)
    return sorted(files)


def main() -> int:
    os.makedirs(OUT_DIR, exist_ok=True)
    files = collect_files()

    # بررسی ساختاری همهٔ فایل‌های PHP داخل بسته (بازاستفاده از اعتبارسنج S23)
    _src = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "validate_s23_php_structure.py"), encoding="utf-8").read()
    _ns: dict = {}
    exec(_src.split("checks = {")[0], _ns)
    php_check = _ns["check"]
    problems = []
    php_files = [f for f in files if f.endswith(".php")]
    for f in php_files:
        problems += php_check(os.path.join(ROOT, f))
    if problems:
        for p in problems:
            print("✗", p)
        print("بسته‌سازی به دلیل مشکل ساختاری متوقف شد.")
        return 1

    if os.path.exists(OUT_ZIP):
        os.remove(OUT_ZIP)
    entries = []
    with zipfile.ZipFile(OUT_ZIP, "w", zipfile.ZIP_DEFLATED) as zf:
        for rel in files:
            full = os.path.join(ROOT, rel)
            zf.write(full, rel)
            entries.append((rel, sha256_of(full)))
        stamp_content = f"s26-mariadb-analytics-hotfix\nbuilt:{STAMP}\n"
        zf.writestr("UPDATE-STAMP.txt", stamp_content)
        zf.writestr("INSTALL-FA.md", INSTALL_FA)
        manifest_lines = []
        for rel, digest in entries:
            manifest_lines.append(f"{digest}  {rel}")
        manifest_lines = [x for x in manifest_lines if x]
        zf.writestr("MANIFEST-SHA256.txt", "\n".join(manifest_lines) + "\n")

    sums_path = os.path.join(OUT_DIR, "SHA256SUMS.txt")
    with open(sums_path, "w", encoding="utf-8") as f:
        f.write(f"{sha256_of(OUT_ZIP)}  hamihub-full-upgrade-to-s26.zip\n")

    print(f"بستهٔ S26 ساخته شد: {OUT_ZIP}")
    print(f"تعداد فایل‌های استقرار: {len(entries)} (بدون متادیتا)")
    print(f"SHA-256 ZIP: {sha256_of(OUT_ZIP)}")
    print(f"PHPهای بررسی‌شدهٔ ساختاری: {len(php_files)} — {'همگی سالم' if not problems else 'مشکل!'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
