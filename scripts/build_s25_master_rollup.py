#!/usr/bin/env python3
"""
بسته‌ساز S25 — بستهٔ جامع ارتقای حامی‌هاب (مدیریت حذف رکوردهای صف جمع‌آوری)

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
OUT_DIR = os.path.join(ROOT, "release-files", "s25-collector-queue-management")
OUT_ZIP = os.path.join(OUT_DIR, "hamihub-full-upgrade-to-s25.zip")

INCLUDE_DIRS = ["app", "public_html", "database", "cron", "bin"]
PUBLIC_HTML_SKIP_TOP = {"cache", "uploads"}
ROOT_INCLUDE_FILES = ["scripts/build_s25_master_rollup.py"]

INSTALL_FA = """# راهنمای ارتقای کامل hamihubx.ir تا S25 — مدیریت حذف رکوردهای صف جمع‌آوری

**بسته:** `hamihub-full-upgrade-to-s25.zip` · **نسخهٔ دارایی‌ها:** پنل ادمین با URLهای هش‌دار جدید (بیلد مجدد) · **مایگریشن جدید:** `023_collector_jobs_purge.sql`

## این بسته چیست؟

بستهٔ **جامع و یکپارچهٔ ارتقا تا S25** است: کل درخت استقرار نسخهٔ S24 به‌علاوهٔ **امکان حذف رکوردهای صف جمع‌آوری از پنل (تکی و پاک‌سازی کامل) و مایگریشن پاک‌سازی سوابق موجود**. اگر سایت شما روی S24 است فقط این موارد اثر می‌گیرند؛ اگر روی S23 یا قدیمی‌تر است، کل درخت با آخرین نسخه یکجا به‌روز می‌شود (آپلود S24 لازم نیست).

طبق درخواست صریح مدیر: **سوابق صف Jobهای جمع‌آوری (۲۵ کار قبلی شامل خطاهای HTTP 485 نشان و تایم‌اوت‌های Overpass) باید کلاً از دیتابیس پاک شود.**

**مرز مهم و تضمین‌شده:** حذف فقط روی جدول `collector_jobs` (سابقهٔ خودِ صف) اعمال می‌شود. کسب‌وکارهایی که از این کارها قبلاً ثبت/تأیید شده‌اند — مثلاً ۱۱۷ کافه‌و‌رستوران رشت یا ۶۴ سالن زیبایی رشت — **کاملاً دست‌نخورده می‌مانند.** وضعیت منابع (OSM/نشان) هم تغییر نمی‌کند.

## موارد این بسته (S25)

1. **حذف تکی رکورد:** در تب «شهرها و جمع‌آوری»، ستون جدید «حذف رکورد» به جدول صف اضافه شده است؛ دکمهٔ حذف هر ردیف با پیام تأیید شناسه و شهر/دسته، رکورد را برای همیشه از دیتابیس پاک می‌کند.
2. **پاک‌سازی کامل صف:** دکمهٔ سرخ «پاک‌سازی همهٔ رکوردها از دیتابیس» کنار «اجرای کار بعدی روی هاست» — همهٔ رکوردهای `collector_jobs` حذف و شناسه‌ها از ۱ دوباره شروع می‌شوند (AUTO_INCREMENT ریست می‌شود). پیام نتیجه، تعداد واقعی حذف‌شده را نشان می‌دهد.
3. **مایگریشن `023_collector_jobs_purge.sql`:** پاک‌سازی یک‌بارهٔ ۲۵ رکورد موجود، بدون نیاز به phpMyAdmin — با اجرای «مایگریشن‌ها» از پنل انجام می‌شود و در سابقه ثبت می‌گردد (اجرای دوباره چیزی را پاک نمی‌کند).
4. **امنیت:** هر دو عملیات با CSRF و کنترل نقش محافظت می‌شوند؛ حذف تکی مثل بقیهٔ صف (admin/editor) و پاک‌سازی کامل فقط ادمین.
5. **رفع اشکال تایپ پنل به‌ارث‌رسیده از S24:** اینترفیس تکراری `AiTaskRow` یکپارچه شد؛ پنل ادمین با بیلد تمیز و URLهای هش‌دار جدید مستقر شده است.

## خط قرمزهای امنیتی بسته

- هیچ فایلی از `config/` داخل بسته نیست — کانفیگ هاست (`config.local.php`) دست‌نخورده می‌ماند.
- پوشه‌های `collector-remote/`، `ai-worker/`، `storage/`، `tests/`، `docs/` و دارایی‌های dev (`src/`، `public/`، فایل‌های Vite) داخل بسته نیستند.
- `public_html/cache` و `public_html/uploads` جایگزین نمی‌شوند.
- هیچ دادهٔ محصولی (کسب‌وکار، مقاله، رسانه، سفارش تبلیغات) در هیچ اندپوینت یا مایگریشن این بسته حذف نمی‌شود.
- اثرانگشت SHA-256 تک‌تک فایل‌ها در `MANIFEST-SHA256.txt` داخل ZIP و `SHA256SUMS.txt` کنار بسته است.

## پیش‌نیاز

- از دیتابیس **و** فایل‌ها پشتیبان کامل بگیرید (phpMyAdmin → Export، و دانلود `public_html`). قانون ثابت: اول پشتیبان، بعد ارتقا.

## مراحل نصب

1. **پشتیبان:** مطابق پیش‌نیاز بالا.
2. **آپلود:** محتوای ZIP را با همان ساختار پوشه‌ها روی هاست جایگزین کنید (حالت Overwrite/Replace):
   - `app/…` → پوشهٔ `app` پروژه (هم‌تراز `public_html`)
   - `public_html/…` → داخل `public_html`
   - `database/` و `cron/` و `bin/…` → همان مسیرهای پروژه
   - فایل `scripts/build_s25_master_rollup.py` اختیاری است و فقط برای هم‌خوانی مخزن است.
   - **دقت:** `config/config.local.php`، پوشهٔ `storage/` و `public_html/uploads/` دست‌نخورده می‌مانند. فایل‌های مخفی (`.htaccess`) هم باید جایگزین شوند.
3. **سخت‌افزار مرورگر:** بعد از آپلود، پنل ادمین را با یک بار Ctrl+F5 (بارگذاری مجدد کامل) باز کنید تا فایل JS/CSS جدید پنل حتماً جایگزین شود.
4. **مایگریشن (پاک‌سازی سوابق موجود):** پنل مدیریت → تب «شهرها و جمع‌آوری» → اجرای مایگریشن‌ها (یا تنظیمات → «اجرای مایگریشن‌ها»)؛ پیام موفقیت مایگریشن `023` را ببینید. همین لحظه ۲۵ رکورد قبلی برای همیشه از دیتابیس پاک می‌شوند و جدول صف خالی و از شناسهٔ ۱ آمادهٔ شروع است.

## بررسی پس از نصب

1. **پاک‌سازی سوابق:** جدول «لیست صف Jobهای جمع‌آوری» باید خالی باشد (پیام «هیچ رکوردی در صف جمع‌آوری وجود ندارد»).
2. **دکمه‌های جدید:** دکمهٔ «پاک‌سازی همهٔ رکوردها از دیتابیس» باید پیام «صف جمع‌آوری از قبل خالی است؛ چیزی برای پاک‌کردن نبود.» بدهد — یعنی اندپوینت پاک‌سازی کامل هم زنده است.
3. **سلامت داده‌ها:** تعداد کسب‌وکارهای منتشرشده در داشبورد پنل باید دقیقاً مثل قبل باشد (۱۱۷ کافه رشت، ۶۴ سالن زیبایی و…) — اگر عددی تغییر کرده باشد یعنی از ابتدا پشتیبان نگرفته‌اید؛ ادامه ندهید و گزارش کنید.
4. **جریان عادی جمع‌آوری:** یک کار آزمایشی (مثلاً رودسر · کافه و رستوران · osm) ثبت کنید؛ بعد از اجرا می‌توانید با دکمهٔ حذف همان ردیف، سابقه‌اش را پاک کنید تا جریان جدید تست شود.
5. **لاگ خطا:** در ۲۴ ساعت اول error_log نباید خطای PHP جدید داشته باشد.

## گام بعدی نقشهٔ راه

با پاک‌سازی صف و آماده‌بودن ابزار مدیریت آن، به گام بعدی (فاز ۶ سئو: صفحات فرود شهر×دسته، اسکیما و مقالات متصل به دادهٔ واقعی) می‌رویم.
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
        stamp_content = f"s25-collector-queue-management\nbuilt:{STAMP}\n"
        zf.writestr("UPDATE-STAMP.txt", stamp_content)
        zf.writestr("INSTALL-FA.md", INSTALL_FA)
        manifest_lines = []
        for rel, digest in entries:
            manifest_lines.append(f"{digest}  {rel}")
        manifest_lines = [x for x in manifest_lines if x]
        zf.writestr("MANIFEST-SHA256.txt", "\n".join(manifest_lines) + "\n")

    sums_path = os.path.join(OUT_DIR, "SHA256SUMS.txt")
    with open(sums_path, "w", encoding="utf-8") as f:
        f.write(f"{sha256_of(OUT_ZIP)}  hamihub-full-upgrade-to-s25.zip\n")

    print(f"بستهٔ S25 ساخته شد: {OUT_ZIP}")
    print(f"تعداد فایل‌های استقرار: {len(entries)} (بدون متادیتا)")
    print(f"SHA-256 ZIP: {sha256_of(OUT_ZIP)}")
    print(f"PHPهای بررسی‌شدهٔ ساختاری: {len(php_files)} — {'همگی سالم' if not problems else 'مشکل!'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
