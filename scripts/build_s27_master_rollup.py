#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
بسته‌ساز S27 — فاز ۶ سئو تکنیکال (صفحات فرود ارگانیک + ضد Thin Content + اسکیمای دقیق)

قواعد بسته (منطبق با بسته‌سازهای قبلی):
- شامل: app/، public_html/ (بدون cache و uploads)، database/، cron/، bin/ و همین اسکریپت
- بدون: config/ (کانفیگ هاست)، storage/، collector-remote/، ai-worker/، tests/،
  src/ و فایل‌های محیط توسعهٔ پنل، release-files/، docs/، public/ (دارایی‌های dev)
- هر فایل داخل ZIP با SHA-256 در MANIFEST-SHA256.txt ثبت می‌شود
- ساختار ZIP همان درخت استقرار است: فایل‌ها از ریشهٔ ZIP به همان مسیرهای پروژه می‌نشینند
- بدون مایگریشن دیتابیس — فاز ۶ صرفاً کد است؛ ستون‌های tier/internal_score از فاز ۳ موجودند
"""
import hashlib
import os
import zipfile
from datetime import datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
STAMP = datetime.now().strftime("%Y%m%d%H%M%S")
OUT_DIR = os.path.join(ROOT, "release-files", "s27-seo-phase6")
OUT_ZIP = os.path.join(OUT_DIR, "hamihub-full-upgrade-to-s27.zip")

INCLUDE_DIRS = ["app", "public_html", "database", "cron", "bin"]
PUBLIC_HTML_SKIP_TOP = {"cache", "uploads"}
ROOT_INCLUDE_FILES = ["scripts/build_s27_master_rollup.py"]

INSTALL_FA = """# راهنمای ارتقای کامل hamihubx.ir تا S27 — فاز ۶: سئو تکنیکال و صفحات فرود ارگانیک

**بسته:** `hamihub-full-upgrade-to-s27.zip` · **مایگریشن جدید:** ندارد · **تغییر پنل ادمین:** ندارد (بیلد قبلی کافی است)

## این بسته چیست؟

پیاده‌سازی کامل فاز ۶ سئو با ۵ محور توافق‌شده. هیچ جدول یا ستون جدیدی ساخته نمی‌شود؛
همه‌ی رتبه‌بندی‌ها از داده‌های واقعی موجود (tier و internal_score فاز ۳، امتیاز و نظرات
منابع، دیدگاه‌های تاییدشده) استفاده می‌کنند.

## محور ۱ — صفحات فرود شهر×دسته

1. **Title رقابتی با تعداد واقعی:** «بهترین [دسته] در [شهر] | لیست N مرکز برتر بر اساس
   امتیاز کاربران» — N از شمارش واقعی کسب‌وکارهای منتشرشده همان ترکیب می‌آید. برای
   صفحه‌های ۲ به بعد، « | صفحه N» انتهای عنوان اضافه می‌شود.
2. **Meta Description غنی:** رتبه‌بندی N دسته + میانگین امتیاز واقعی (وقتی موجود).
3. **H1 داینامیک:** «بهترین [دسته] در [شهر]؛ لیست N مرکز برتر».
4. **مرتب‌سازی پیش‌فرض بیزی:** جایگاه ویژه (پرداختی) → Tier A → Tier B → Tier C →
   امتیاز بیزی → امتیاز منبع → تعداد نظر. مدل بیزی همان فاز ۳ است (m=20، مرکز =
   میانگین واقعی همان شهر×دسته).
5. **بلوک آمار محلی:** تعداد مراکز، تعداد دارای امتیاز، میانگین امتیاز از ۵، مجموع
   نظرهای واقعی — همه سمت سرور از دیتابیس.
6. **۳ پرسش متداول داینامیک:** «بهترین‌ها کدام‌ها هستند؟» (با نام واقعی ۳ مرکز برتر)،
   «چند مجموعه ثبت شده؟»، «میانگین امتیاز چقدر است؟» — همراه اسکیمای FAQPage.
7. **لینک‌سازی داخلی:** باکس «راهنماهای مرتبط» (مقالات همان شهر×دسته) و بخش
   «سایر دسته‌های برتر در [شهر]» با تعداد هر دسته.

## محور ۲ — محافظت از رکوردها (ضد Thin Content)

- **گاردریل ایندکس کسب‌وکار:** رکوردی که «نه توضیحات دارد، نه نظر/امتیاز واقعی دارد،
  و نه تلفن و نه نشانی» تا زمان تکمیل داده `noindex, follow` می‌گیرد تا رتبه کل
  دامنه را پایین نکشد. هر داده‌ای که اضافه شود، صفحه خودکار به حالت ایندکس برمی‌گردد.
- **گاردریل ترکیب خالی:** صفحه شهر×دسته بدون هیچ کسب‌وکار منتشرشده هم noindex است.
- **چیپ مقایسه امتیاز:** در صفحه هر کسب‌وکار، امتیازش با میانگین واقعی همان دسته در
  همان شهر مقایسه می‌شود (بالاتر/پایین‌تر/هم‌سطح).
- **صف AI خودکار:** کسب‌وکارهای Tier A و B که توضیحات ندارند، هنگام بازدید واقعی
  (cache-miss) به‌صورت خودکار در صف ai_tasks فاز ۳ ثبت می‌شوند تا متن معرفی برایشان
  تولید شود؛ تسک تکراری ثبت نمی‌شود و هیچ خطایی رندر را متوقف نمی‌کند.

## محور ۳ — اسکیمای JSON-LD دقیق

- صفحه کسب‌وکار: LocalBusiness + GeoCoordinates + BreadcrumbList + تصویر کاور +
  sameAs (وب‌سایت/اینستاگرام). **AggregateRating فقط وقتی تعداد نظر بزرگ‌تر از صفر
  است** — این اصلاح، خطای رایج کنسول جستجو (reviewCount=0) را حذف می‌کند.
- صفحه شهر×دسته: ItemList (فقط ۱۰ مورد اول) + FAQPage داینامیک + BreadcrumbList.

## محور ۴ — سایت‌مپ، Canonical و لینک‌سازی

- `/sitemap.xml` داینامیک: فقط ترکیب‌های شهر×دسته با حداقل یک کسب‌وکار منتشرشده،
  فقط کسب‌وکارهای ایندکس‌پذیر (منطبق با گاردریل)، افزودن `lastmod`، و حذف مسیرهای
  دارای قانون فعال 410/301 از migration 012.
- canonical همه صفحات قبلاً درست بود (با فیلتر و صفحه‌بندی روی مسیر تمیز می‌ماند)؛
  همین رفتار حفظ شد.
- لینک‌سازی دوطرفه: صفحه کسب‌وکار → سایر دسته‌های شهر؛ صفحه شهر×دسته → مقالات
  مرتبط (AiArticleService/ArticleService) و دسته‌های هم‌شهر.

## محور ۵ — پرفورمنس و کش

- کش دیسک ۵ دقیقه‌ای صفحات عمومی دست‌نخورده باقی است؛ نسخه CSS به `s27.0` رفت تا
  کش مرورگر و کش صفحات قدیمی خودکار باطل شوند (گارد داخل Cache::getPage هم به‌روز شد).
- ابعاد صریح تصاویر گالری (width/height) برای حذف پرش چیدمان (CLS) در Core Web
  Vitals موبایل؛ فونت‌ها از قبل preload با font-display:swap هستند.
- نقشه‌ها از قبل فقط با کلیک کاربر بارگذاری می‌شوند (بدون JS سنگین اولیه).

## بستن حلقهٔ Tier (جدید در S27)

تا امروز Tierها فقط با فراخوانی دستی محاسبه می‌شدند. در S27:
- **پس از هر جاب جمع‌آوری موفق،** Tier همان شهر×دسته بلافاصله بازمحاسبه می‌شود.
- **پس از هر تایید/رد در صندوق بازبینی** نیز ترکیب مربوطه بازمحاسبه می‌شود.
- **یک‌بار در لحظه استقرار:** بعد از آپلود فایل‌ها، روی هاست دستور زیر را اجرا کنید
  تا همه Tierها یک‌جا تازه شوند (اگر ترمینال ندارید، با اولین جاب کرون یا اولین
  تایید بازبینی هم به‌تدریج پر می‌شود؛ این مرحله اختیاری اما توصیه‌شده است):

```
php ~/bin/recalc-tiers.php
```

(مسیر دقیق php روی هاست را از پشتیبانی بپرسید؛ معمولاً `/usr/local/bin/php` است.)

## راستی‌آزمایی انجام‌شده

- تجزیه کامل هر ۸۵ فایل PHP پروژه با پارسر PHP 8 بدون هیچ خطای نگارشی.
- بازبینی دستی و جفت‌سازی هر کوئری جدید با ساختار جدول‌های 001/013/020.
- کلاس‌ها/آیکن‌های جدید فقط از استایل‌ها و آیکن‌های موجود + بلاک‌های افزوده‌شده در
  انتهای public.css استفاده می‌کنند.

## خط قرمزهای امنیتی بسته

- هیچ فایلی از `config/` داخل بسته نیست — کانفیگ هاست دست‌نخورده می‌ماند.
- `public_html/cache` و `public_html/uploads` جایگزین نمی‌شوند.
- **بدون مایگریشن دیتابیس؛ هیچ داده‌ای تغییر نمی‌کند.** تنها تغییر دیتابیس، بازنویسی
  مقادیر `tier` و `internal_score` توسط بازمحاسبهٔ بیزی است (همان منطق صادق فاز ۳).
- `noindex` فقط متادیتای صفحه است؛ هیچ رکوردی از ایندکس گوگل حذف یا اضافه نمی‌شود
  مگر با چرخه طبیعی گوگل.

## پیش‌نیاز

- از دیتابیس **و** فایل‌ها پشتیبان کامل بگیرید (phpMyAdmin → Export و دانلود فایل‌ها).
  قانون ثابت: اول پشتیبان، بعد ارتقا.

## مراحل نصب

1. **پشتیبان:** مطابق پیش‌نیاز بالا.
2. **آپلود:** محتوای ZIP را در ریشهٔ پروژه روی هاست (کنار `public_html` و `app`)
   استخراج کنید؛ ساختار ZIP همان درخت استقرار است. فایل‌های کلیدی تغییر یافته:
   `app/Controllers/PublicSiteController.php`، `app/Controllers/ReviewQueueController.php`،
   `app/Services/CollectorRunner.php`، `app/Core/Cache.php`،
   `public_html/assets/css/public.css`، `bin/recalc-tiers.php` (جدید).
3. **دسترسی‌ها:** نیازی به تغییر نیست؛ همان ۶۴۴/۷۵۵ قبلی بماند.
4. **اجرای بازمحاسبهٔ Tier (توصیه‌شده):** `php ~/bin/recalc-tiers.php`
5. **کش OPcache:** اگر فعال است، از پنل هاست یک‌بار ریست کنید.
6. **تأیید نهایی (چک‌لیست ۵ دقیقه‌ای):**
   - یک صفحه شهر×دسته باز کنید — عنوان باید «لیست N مرکز برتر...» باشد و بلوک آمار
     و پرسش‌های متداول در صفحه دیده شود.
   - سورس صفحه را باز کنید و دنبال `application/ld+json` بگردید؛ روی
     https://validator.schema.org تست کنید — نباید خطا بدهد.
   - `/sitemap.xml` را باز کنید — ترکیب‌های خالی و رکوردهای فقیر نباید داخلش باشند
     و `lastmod` باید دیده شود.
   - صفحه یک کسب‌وکارِ بدون اطلاعات تماس را باز کنید — در سورس باید
     `name="robots" content="noindex, follow"` دیده شود.
   - بعد از یک جاب کرون جمع‌آوری، ترتیب فهرست همان شهر×دسته باید طبق Tier جدید باشد.

## اگر سوالی پیش آمد

همهٔ تغییرات در کامیت «S27 phase 6 technical SEO» روی شاخه `main` ریپو ثبت است و
این راهنما + مانیفست SHA-256 داخل ZIP موجودند.
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
        zf.writestr("RAHNAMA-S27-FA.md", INSTALL_FA)
        zf.writestr("MANIFEST-SHA256.txt", "\n".join(manifest_lines) + "\n")

    with open(os.path.join(OUT_DIR, "SHA256SUMS.txt"), "w", encoding="utf-8") as f:
        f.write("\n".join(manifest_lines) + "\n")

    zip_sha = sha256_of(OUT_ZIP)
    size = os.path.getsize(OUT_ZIP)
    with open(os.path.join(OUT_DIR, "PACKAGE-SHA256.txt"), "w", encoding="utf-8") as f:
        f.write(f"{zip_sha}  hamihub-full-upgrade-to-s27.zip\n")

    print("S27 package built successfully")
    print(f"files: {len(files)}")
    print(f"zip: {OUT_ZIP}")
    print(f"zip_size_bytes: {size}")
    print(f"zip_sha256: {zip_sha}")
    # سالم‌بودن بسته را با شمارش فایل‌های کلیدی تضمین کن
    with zipfile.ZipFile(OUT_ZIP) as zf:
        names = set(zf.namelist())
    required = [
        "app/Controllers/PublicSiteController.php",
        "app/Controllers/ReviewQueueController.php",
        "app/Services/CollectorRunner.php",
        "app/Services/PipelineService.php",
        "app/Core/Cache.php",
        "public_html/assets/css/public.css",
        "public_html/index.php",
        "bin/recalc-tiers.php",
        "RAHNAMA-S27-FA.md",
        "MANIFEST-SHA256.txt",
    ]
    missing = [r for r in required if r not in names]
    if missing:
        raise SystemExit("MISSING in zip: " + ", ".join(missing))
    print("required_files: OK")


if __name__ == "__main__":
    main()
