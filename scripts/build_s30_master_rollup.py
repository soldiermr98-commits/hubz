#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
بسته‌ساز S30 — سیستم تصاویر ترکیبی (عکس واقعی از PC + تصویر شاخص تولیدی + og:image صفحه‌محور)

قواعد بسته (منطبق با بسته‌سازهای قبلی):
- شامل: app/، public_html/ (بدون cache و uploads)، database/، cron/، bin/، همین اسکریپت،
  deploy/cf-image-worker.js و docs/IMAGES_FA.md
- بدون: config/ (کانفیگ هاست)، storage/، collector-remote/ (روی PC مدیر اجرا می‌شود)،
  ai-worker/، tests/، src/ و فایل‌های محیط توسعهٔ پنل، release-files/
- هر فایل داخل ZIP با SHA-256 در MANIFEST-SHA256.txt ثبت می‌شود
- ساختار ZIP همان درخت استقرار است؛ بدون مایگریشن دیتابیس
"""
import hashlib
import os
import zipfile
from datetime import datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
STAMP = datetime.now().strftime("%Y%m%d%H%M%S")
OUT_DIR = os.path.join(ROOT, "release-files", "s30-images")
OUT_ZIP = os.path.join(OUT_DIR, "hamihub-full-upgrade-to-s30.zip")

INCLUDE_DIRS = ["app", "public_html", "database", "cron", "bin"]
PUBLIC_HTML_SKIP_TOP = {"cache", "uploads"}
ROOT_INCLUDE_FILES = [
    "scripts/build_s30_master_rollup.py",
    "deploy/cf-image-worker.js",
    "docs/IMAGES_FA.md",
]

INSTALL_FA = """# راهنمای S30 — سیستم تصاویر ترکیبی

**بسته:** `hamihub-full-upgrade-to-s30.zip` · **مایگریشن جدید:** ندارد · **تغییر پنل ادمین:** دارد (بیلد جدید admin.js)
**پیش‌نیاز نسخه:** هاست باید S29 باشد (پنل با دکمهٔ «فکت از کسب‌وکارهای منتشرشده»). این بستهٔ جامع است و S28 و S29 را هم شامل می‌شود.

## این بسته چه چیزی اضافه می‌کند؟

1. **عکس واقعی کسب‌وکارها (بدون دانلود روی هاست):** عکس‌های جمع‌آوری‌شدهٔ قبلی (آپی‌فای/گوسوم) از
   `business_sources.raw` استخراج می‌شوند؛ شما روی کامپیوتر خودتان دانلود و بهینه می‌کنید
   (`php collector-remote/run.php photos --limit=10`) و هاست فقط بایت پوش‌شده را فحش MIME و
   WebP سه‌سایز می‌کند. عکس تکراری با `source_url` رد می‌شود و اگر کسب‌وکار کاور نداشت،
   اولین عکس خودکار کاور می‌شود.
2. **تصویر شاخص مقاله با AI:** ورکر مستقل Cloudflare (جدای relay متن) با مدل flux-1-schnell؛
   در ادیتور مقاله دکمهٔ «تولید تصویر با AI» اضافه می‌شود و خروجی به‌عنوان کاور مقاله ثبت می‌شود.
3. **og:image صفحه‌محور:** صفحهٔ کسب‌وکار و مقاله کاور خودشان را به تلگرام/گوگل می‌دهند؛
   بدون کاور، همان لوگوی کارت قبلی.
4. **کپشن منبع عکس:** زیر عکس گالری «عکس: …» از ستون attribution.
5. **هاردنینگ امنیتی:** حذف توکن پیش‌فرض هاردکد ورکر، بستن نشتی پیام استثنا در ۵ اندپوینت،
   claiming اتمیک صف AI، گارد وضعیت در completeTask، و حذف امتیاز خیالی ۴.۰/۴.۱۰ از مولد
   «بدون توهم».

## پیش‌نیاز — همیشه اول پشتیبان

از دیتابیس **و** فایل‌ها پشتیبان کامل بگیرید (phpMyAdmin → Export و دانلود فایل‌ها).
این بسته به دیتابیس دست نمی‌زند؛ پشتیبان فقط برای آرامش خیال است.

## گام ۱ — آپلود ZIP

محتوای ZIP را در ریشهٔ پروژه روی هاست (کنار `public_html` و `app`) استخراج کنید.
هیچ فایلی از `config/` داخل بسته نیست و `public_html/cache` و `public_html/uploads`
جایگزین نمی‌شوند. فایل `deploy/cf-image-worker.js` و `docs/IMAGES_FA.md` هم داخل بسته
هستند (برای نصب ورکر تصویر).

## گام ۲ — پاک‌سازی کش مرورگر (مهم)

پنل ادمین فایل جاوااسکریپت جدید دارد. بعد از آپلود، پنل را با **Ctrl+F5** باز کنید.
اگر کارت «تصاویر کسب‌وکارها» در صف کولکتور و دکمهٔ «تولید تصویر با AI» در ادیتور مقاله
را ندیدید، مرورگر نسخهٔ قدیمی admin.js را از کش خوانده است.

## گام ۳ — راستی‌آزمایی سریع (۵ دقیقه، بدون وابستگی خارجی)

1. **پنل ← شهرها و جمع‌آوری:** در پایین بخش صف، کارت «تصاویر کسب‌وکارها (S30)» باید دیده
   شود با آمار «بی‌عکس: N». دکمهٔ «تولید/تعویض توکن کولکتور» را بزنید → توکن ۴۸ کاراکتری
   یک‌بار نمایش داده می‌شود → کپی کنید.
2. **پنل ← هوش مصنوعی:** پایین کارت سرویس‌ها، کارت «تصویر شاخص مقاله (S30)» باید باشد
   (فعلاً «غیرفعال» — تا ورکر را نصب نکرده‌اید طبیعی است).
3. **ادیتور مقاله:** ردیف «تصویر اصلی مقاله» باید دکمهٔ «تولید تصویر با AI» را داشته باشد.
4. **بررسی امنیت از مرورگر ناشناس (خارج از پنل):**
   - `GET /api/v1/collector/photos/backfill` باید **401** بدهد.
   - `GET /api/v1/ai/image/status` باید **401/403** بدهد.
   - `POST /api/v1/collector/photo` بدون توکن باید **401** بدهد.
5. **توکن قدیمی باطل شده:** اگر قبلاً برای ورکر هاست توکن در `collector-remote/config.php`
   داشتید، بعد از گام ۱ باید مقدار جدید را جای‌گذاری کنید (تعویض توکن عمداً بخشی از این
   بسته است — همان «سکرت حدس‌پذیر» معلق).

## گام ۴ — عکس واقعی روی کامپیوتر خودتان (بعد از آپلود هاست)

1. فایل‌های سمت PC را از ریپو (GitHub، کامیت S30) بردارید: `collector-remote/PhotoPusher.php`
   (جدید)، `collector-remote/run.php` و `collector-remote/config.sample.php` (به‌روز).
2. توکن کپی‌شده از پنل را در `collector-remote/config.php` (کلید `bearer_token`) بگذارید.
3. اجرا: `php collector-remote/run.php photos --limit=10`
   - خروجی: دریافت کاندیدها ← دانلود با `=w1600` ← پوش به هاست ← «کاور شد/گالری».
   - پیشرفت در `collector-remote/storage/photos-progress.json` ذخیره می‌شود؛ اجرای دوباره
     عکس‌های انجام‌شده را رد می‌کند.
4. بعد از پوش: کارت کسب‌وکار در سایت باید عکس داشته باشد و زیر عکسِ گالری صفحهٔ جزئیات
   کپشن «عکس: Google Maps» دیده شود.

## گام ۵ — نصب ورکر تصویر (اختیاری؛ برای hero مقاله‌ها)

طبق `docs/IMAGES_FA.md` داخل همین بسته:
1. محتوای `deploy/cf-image-worker.js` را در داشبورد کلادفلر (Workers & Pages → Create Worker)
   paste و Deploy کنید.
2. سه Secret بسازید: `IMAGE_WORKER_SECRET` (رشتهٔ تصادفی)، `CF_ACCOUNT_ID`، `CF_API_TOKEN`
   (پرمیشن Account → Workers AI → Edit).
3. پنل حامی‌هاب ← هوش مصنوعی ← کارت «تصویر شاخص مقاله»: نشانی `https://…/image` + سکرت ←
   تیک فعال‌سازی و تأیید مصرف ← ذخیره ← «تست با تصویر کوچک واقعی».
4. در ادیتور مقاله: «تولید تصویر با AI» ← پرامپت (پیش‌پرشده از عنوان؛ انگلیسی بهتر جواب
   می‌دهد) ← تأیید مصرف ← تولید (~۶۰ ثانیه) ← ذخیرهٔ مقاله.
5. تصویر تولیدی «نمادین» است و جای عکس واقعی کسب‌وکار استفاده نمی‌شود.

## بررسی نهایی سئو

بعد از ست‌کردن کاور مقاله و انتشار:
`curl -s https://hamihubx.ir/<slug-article>/ | grep -i "og:image"`
باید کاور `/uploads/…` را نشان دهد، نه لوگوی کارت. صفحهٔ یک کسب‌وکار دارای عکس هم همینطور.

## اگر خطا دیدید

- «توکن Bearer کولکتور معتبر نیست» در اجرای PC → توکن پنل و config.php را دوباره تطبیق دهید.
- کارت تصاویر یا دکمهٔ AI دیده نمی‌شود → Ctrl+F5 (کش admin.js).
- خطای «پردازش و اتصال تصویر انجام نشد» → یک‌بار دیگر تلاش کنید؛ اگر ادامه داشت، حجم فایل
  (سقف ۸MB) و لاگ خطای هاست را بررسی کنید.
- 403 روی پنل بعد از آپلود → نشست را تازه کنید (خروج/ورود).

## خط قرمزهای امنیتی بسته

- هیچ فایلی از `config/` داخل بسته نیست — کانفیگ هاست دست‌نخورده می‌ماند.
- بدون مایگریشن؛ فقط جدول‌های موجود (`business_photos`، `media`، `settings`) مصرف می‌شوند.
- هاست هرگز عکس گوگل را دانلود نمی‌کند (سیاست S2 حفظ شد).
- توکن‌ها و سکرت‌ها هرگز در پاسخ‌های جدید بازنمی‌گردند (توکن کولکتور فقط یک‌بار بعد از ساخت).
- اندپوینت‌های جدید: پنلی‌ها با نقش + CSRF؛ راه‌دورها با Bearer fail-closed.
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
        zf.writestr("RAHNAMA-S30-FA.md", INSTALL_FA)
        zf.writestr("MANIFEST-SHA256.txt", "\n".join(manifest_lines) + "\n")

    with open(os.path.join(OUT_DIR, "SHA256SUMS.txt"), "w", encoding="utf-8") as f:
        f.write("\n".join(manifest_lines) + "\n")

    zip_sha = sha256_of(OUT_ZIP)
    size = os.path.getsize(OUT_ZIP)
    with open(os.path.join(OUT_DIR, "PACKAGE-SHA256.txt"), "w", encoding="utf-8") as f:
        f.write(f"{zip_sha}  hamihub-full-upgrade-to-s30.zip\n")

    print("S30 package built successfully")
    print(f"files: {len(files)}")
    print(f"zip: {OUT_ZIP}")
    print(f"zip_size_bytes: {size}")
    print(f"zip_sha256: {zip_sha}")
    with zipfile.ZipFile(OUT_ZIP) as zf:
        names = set(zf.namelist())
    required = [
        "app/Controllers/AiImageController.php",
        "app/Controllers/AiWorkerController.php",
        "app/Controllers/CollectorController.php",
        "app/Services/AiImageService.php",
        "app/Services/PhotoBackfillService.php",
        "app/Services/AiContentService.php",
        "app/Services/ApifyImportService.php",
        "public_html/api/index.php",
        "public_html/admin/index.html",
        "public_html/admin/assets/admin.js",
        "public_html/assets/css/public.css",
        "deploy/cf-image-worker.js",
        "docs/IMAGES_FA.md",
        "cron/worker.php",
        "RAHNAMA-S30-FA.md",
        "MANIFEST-SHA256.txt",
    ]
    missing = [r for r in required if r not in names]
    if missing:
        raise SystemExit("MISSING in zip: " + ", ".join(missing))
    forbidden = [n for n in names if n.startswith(("config/", "storage/", "collector-remote/", "ai-worker/", "tests/", "src/", "release-files/", "public_html/cache/", "public_html/uploads/"))]
    if forbidden:
        raise SystemExit("FORBIDDEN paths in zip: " + ", ".join(forbidden[:10]))
    print("required_files: OK")
    print("forbidden_paths: NONE")


if __name__ == "__main__":
    main()
