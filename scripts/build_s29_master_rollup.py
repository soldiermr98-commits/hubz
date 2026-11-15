#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
بسته‌ساز S29 — دکمهٔ «فکت از کسب‌وکارهای منتشرشده» در دستیار مقاله

قواعد بسته (منطبق با بسته‌سازهای قبلی):
- شامل: app/، public_html/ (بدون cache و uploads)، database/، cron/، bin/ و همین اسکریپت
- بدون: config/ (کانفیگ هاست)، storage/، collector-remote/، ai-worker/، tests/،
  src/ و فایل‌های محیط توسعهٔ پنل، release-files/، docs/، public/ (دارایی‌های dev)
- هر فایل داخل ZIP با SHA-256 در MANIFEST-SHA256.txt ثبت می‌شود
- ساختار ZIP همان درخت استقرار است؛ بدون مایگریشن دیتابیس
"""
import hashlib
import os
import zipfile
from datetime import datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
STAMP = datetime.now().strftime("%Y%m%d%H%M%S")
OUT_DIR = os.path.join(ROOT, "release-files", "s29-ai-facts-from-db")
OUT_ZIP = os.path.join(OUT_DIR, "hamihub-full-upgrade-to-s29.zip")

INCLUDE_DIRS = ["app", "public_html", "database", "cron", "bin"]
PUBLIC_HTML_SKIP_TOP = {"cache", "uploads"}
ROOT_INCLUDE_FILES = ["scripts/build_s29_master_rollup.py"]

INSTALL_FA = """# راهنمای S29 — دکمهٔ «فکت از کسب‌وکارهای منتشرشده» در دستیار مقاله

**بسته:** `hamihub-full-upgrade-to-s29.zip` · **مایگریشن جدید:** ندارد · **تغییر پنل ادمین:** دارد (بیلد جدید admin.js)

## این بسته چه مشکلی را حل می‌کند؟

دو تولید آزمایشی مقاله با سرویس هوش مصنوعی نشان داد گلوگاه کیفیت، پر کردن دستی فیلد
«اطلاعات و منابع بررسی‌شده» است. گاردریل ضد توهم درست کار می‌کند — وقتی فکت واقعی نباشد
مدل به‌جای ساختن کافه یا رستوران خیالی، صادقانه می‌گوید اطلاعات ندارد و بخش کسب‌وکارها را
حذف می‌کند — اما نتیجه، مقاله‌ای لاغر با جای‌نگهدار [نام] می‌شود و پر کردن فکت‌ها باید هر بار
دستی از پنل کپی شود.

این بسته حلقه را می‌بندد: در پنل ادمین، ویرایشگر مقاله ← «نوشتن با هوش مصنوعی» ← بلوک جدید
**«فکت از کسب‌وکارهای منتشرشده (دیتابیس)»**:

1. باز شدن بلوک کاملاً دستی است؛ تا خودتان بازش نکنید هیچ درخواستی به سرور نمی‌رود.
2. منوی ترکیب شهر×دسته فقط ترکیب‌های دارای حداقل یک مرکز منتشرشده را با تعدادشان نشان
   می‌دهد، به‌همراه گزینهٔ «همهٔ دسته‌های شهر» برای مقاله‌های سفر و راهنمای عمومی.
3. دکمهٔ «دریافت فکت‌ها و افزودن به اطلاعات بالا» ردیف‌های آمادهٔ فکت را به انتهای کادر
   اضافه می‌کند:

   ```
   مراکز واقعی منتشرشده در حامی‌هاب — رشت — کافه (تنها منبع مجاز برای نام، نشانی و تلفن):
   ۱. نام | کافه | محله، نشانی | تلفن: ... | ویژگی ثبت‌شده
   ```

4. متن قبلی کادر هرگز حذف نمی‌شود؛ فقط افزوده می‌شود و پیش از ارسال قابل ویرایش است.
5. هیچ فراخوانی سرویس هوش مصنوعی در این قدم نیست و سهمیهٔ ۵/۳۰ را مصرف نمی‌کند.

## محدودهٔ داده (خط قرمز حریم خصوصی)

فقط همان اطلاعات عمومی‌ای که در صفحات عمومی سایت هم نمایش داده می‌شود وارد فکت می‌شود:
نام، دسته، محله/نشانی، تلفن عمومی (phone/mobile)، و بولت یا توضیح کوتاه ثبت‌شده.
هیچ دادهٔ داخلی، پیش‌نویس، ایمیل یا حساب‌های اجتماعی وارد فکت نمی‌شود. مراکز به ترتیب
Tier و امتیاز داخلی (بهترین‌ها اول) و حداکثر ۲۰ مورد انتخاب می‌شوند؛ اگر سقف طول فیلد
(۸۰۰۰ نویسه) پر شود، فقط تا جایی که جا باشد اضافه می‌شود و پنل هشدار می‌دهد.

## فایل‌های تغییریافته در این بسته

- `app/Controllers/AiArticleController.php` — دو متد جدید فقط‌خواندنی (factCombos و facts)
- `public_html/api/index.php` — دو مسیر GET جدید با گارد نقش ادمین/ویرایشگر
- `public_html/admin/index.html` و `public_html/admin/assets/admin.js` — بیلد جدید پنل

## پیش‌نیاز

از دیتابیس **و** فایل‌ها پشتیبان کامل بگیرید (phpMyAdmin → Export و دانلود فایل‌ها).
قانون ثابت: اول پشتیبان، بعد ارتقا. (این بسته به دیتابیس دست نمی‌زند؛ پشتیبان فقط برای
آرامش خیال است.)

## گام ۱ — آپلود ZIP

محتوای ZIP را در ریشهٔ پروژه روی هاست (کنار `public_html` و `app`) استخراج کنید؛ ساختار ZIP
همان درخت استقرار است. این بستهٔ **جامع کامل** است و همهٔ فایل‌های S22 تا S28 را هم دارد؛
اگر هر فایلی از بسته‌های قبلی جا مانده باشد، همین آپلود جبران می‌کند. هیچ فایلی از `config/`
داخل بسته نیست و `public_html/cache` و `public_html/uploads` جایگزین نمی‌شوند.

## گام ۲ — پاک‌سازی کش مرورگر (مهم)

پنل ادمین فایل جاوااسکریپت جدید دارد. بعد از آپلود، پنل را با **Ctrl+F5** باز کنید
(یا کش مرورگر را پاک کنید). اگر گزینهٔ «فکت از کسب‌وکارهای منتشرشده» را در دستیار ندیدید،
یعنی مرورگر نسخهٔ قدیمی admin.js را از کش خوانده است.

## گام ۳ — راستی‌آزمایی (۵ دقیقه)

1. پنل ادمین ← مقاله‌ها ← ویرایش یک مقاله ← «نوشتن با هوش مصنوعی» را باز کنید.
2. روی «فکت از کسب‌وکارهای منتشرشده (دیتابیس) (نمایش)» کلیک کنید — فهرست شهرها و دسته‌ها
   با تعداد مراکز منتشرشده باید ظاهر شود.
3. یک ترکیب (مثلاً رشت / کافه) را انتخاب و «دریافت فکت‌ها و افزودن به اطلاعات بالا» را بزنید —
   ردیف‌های فکت باید به انتهای کادر «اطلاعات و منابع بررسی‌شده» اضافه شوند و پیام سبز
   «N مرکز به انتهای فکت‌ها اضافه شد.» دیده شود.
4. مقالهٔ کافه‌های رشت را با همین فکت‌ها تولید کنید — دیگر نباید جای‌نگهدار [نام] یا
   جملهٔ «اطلاعات مشخصی ارائه نشده است» ببینید.
5. اگر شهری مرکز منتشرشده ندارد، در منو دیده نمی‌شود — این رفتار درست است.
6. بررسی امنیت: با یک مرورگر ناشناس (خارج از پنل) آدرس
   `/api/v1/ai/article/facts?city_id=1` باید پاسخ ۴۰۱/۴۰۳ بدهد، نه داده.

## اگر خطا دیدید

- «دریافت ترکیب‌های شهر و دسته ناموفق بود» → از پنل خارج و دوباره وارد شوید (نشست/CSRF)،
  بعد چند لحظه صبر و تلاش کنید.
- فهرست ترکیب‌ها خالی بود → هنوز هیچ مرکزی با وضعیت «منتشرشده» ندارید؛ اول کسب‌وکارها را
  از بخش مدیریت کسب‌وکارها منتشر کنید.
- خطای ۴۰۳ در همان پنل → کش مرورگر را پاک کنید (Ctrl+F5) یا ورود را تازه کنید.

## خط قرمزهای امنیتی بسته

- هیچ فایلی از `config/` داخل بسته نیست — کانفیگ هاست دست‌نخورده می‌ماند.
- بدون مایگریشن؛ هیچ جدول یا داده‌ای تغییر نمی‌کند.
- endpointهای جدید فقط‌خواندنی‌اند و با نقش ادمین/ویرایشگر گارد شده‌اند.
- کلیدها و تنظیمات سرویس‌ها در بسته و در خروجی‌های جدید هرگز بازنمی‌گردند.
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
            if d == "public_html" and rel_top in PUBLIC_HTML_SKIP_TOP:
                dirnames[:] = []
                continue
            for fn in filenames:
                full = os.path.join(dirpath, fn)
                rel = os.path.relpath(full, ROOT)
                files.append(rel)
    for extra in ROOT_INCLUDE_FILES:
        full = os.path.join(ROOT, extra)
        if os.path.isfile(full):
            files.append(extra)
    return sorted(set(files))


def main():
    os.makedirs(OUT_DIR, exist_ok=True)
    files = collect_files()
    manifest_lines = []
    with zipfile.ZipFile(OUT_ZIP, "w", zipfile.ZIP_DEFLATED) as zf:
        for rel in files:
            full = os.path.join(ROOT, rel)
            zf.write(full, rel)
            digest = sha256_of(full)
            manifest_lines.append(f"{digest}  {rel}")
        zf.writestr("RAHNAMA-S29-FA.md", INSTALL_FA)
        zf.writestr("MANIFEST-SHA256.txt", "\n".join(manifest_lines) + "\n")

    with open(os.path.join(OUT_DIR, "SHA256SUMS.txt"), "w", encoding="utf-8") as f:
        f.write("\n".join(manifest_lines) + "\n")

    zip_sha = sha256_of(OUT_ZIP)
    size = os.path.getsize(OUT_ZIP)
    with open(os.path.join(OUT_DIR, "PACKAGE-SHA256.txt"), "w", encoding="utf-8") as f:
        f.write(f"{zip_sha}  hamihub-full-upgrade-to-s29.zip\n")

    print("S29 package built successfully")
    print(f"files: {len(files)}")
    print(f"zip: {OUT_ZIP}")
    print(f"zip_size_bytes: {size}")
    print(f"zip_sha256: {zip_sha}")
    with zipfile.ZipFile(OUT_ZIP) as zf:
        names = set(zf.namelist())
    required = [
        "app/Controllers/AiArticleController.php",
        "public_html/api/index.php",
        "public_html/admin/index.html",
        "public_html/admin/assets/admin.js",
        "cron/worker.php",
        "bin/recalc-tiers.php",
        "RAHNAMA-S29-FA.md",
        "MANIFEST-SHA256.txt",
    ]
    missing = [r for r in required if r not in names]
    if missing:
        raise SystemExit("MISSING in zip: " + ", ".join(missing))
    print("required_files: OK")


if __name__ == "__main__":
    main()
