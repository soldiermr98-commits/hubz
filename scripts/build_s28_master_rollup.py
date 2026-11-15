#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
بسته‌ساز S28 — بهداشت لاگ کرون و اصلاح استقرار worker

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
OUT_DIR = os.path.join(ROOT, "release-files", "s28-cron-log-hygiene")
OUT_ZIP = os.path.join(OUT_DIR, "hamihub-full-upgrade-to-s28.zip")

INCLUDE_DIRS = ["app", "public_html", "database", "cron", "bin"]
PUBLIC_HTML_SKIP_TOP = {"cache", "uploads"}
ROOT_INCLUDE_FILES = ["scripts/build_s28_master_rollup.py"]

INSTALL_FA = """# راهنمای S28 — بهداشت لاگ کرون و اصلاح استقرار worker

**بسته:** `hamihub-full-upgrade-to-s28.zip` · **مایگریشن جدید:** ندارد · **تغییر پنل ادمین:** ندارد (بیلد قبلی کافی است)

## این بسته چه مشکلی را حل می‌کند؟

در لاگ‌های هاست هر دقیقه یک خط اضافه می‌شد: «`worker.php executed.`» در `storage/cron.log`
و «`اجرای موفق cron/worker.php (S0 Heartbeat)`» در `storage/logs/worker.log`.

ریشه‌یابی: این دو متن دقیقاً از **نسخهٔ اولیهٔ S0** فایل `cron/worker.php` می‌آیند — اسکریپتی
که فقط ضربان می‌نویسد و **هیچ سرویس واقعی را اجرا نمی‌کند**. یعنی جاب کرونِ فعلی یا هنوز
نسخهٔ S0 را اجرا می‌کند یا یک جاب دوم قدیمی هم‌زمان ضربان می‌نویسد. در هر دو حالت، این
خدمات از مسیر کرون اجرا نمی‌شدند و فقط دکمه‌های دستی پنل کار می‌کردند:

- پردازش خودکار صف جمع‌آوری (CollectorRunner)
- بکاپ روزانهٔ خودکار دیتابیس (S24)
- تیک خودکار اتوماسیون Apify
- نگهداری دوره‌ای (آزادسازی جاب‌های قفل‌شده، پاک‌سازی تلاش‌های ورود، انتقال اسپانسر)
- هوک بازمحاسبهٔ Tier پس از جاب‌های جمع‌آوری (S27)

این بسته هر سه چیز را یک‌جا حل می‌کند:

1. **جایگزینی `cron/worker.php` با نسخهٔ کامل و بی‌صدا** — از این به بعد فقط وقتی اتفاق واقعی
   بیفتد (اجرای جاب جمع‌آوری، گرفتن بکاپ، خطا و...) یک خط JSON در `cron.log` می‌نشیند؛
   در حالت عادی هیچ خروجی‌ای ندارد.
2. **چرخش خودکار لاگ** — `storage/cron.log` و `storage/logs/worker.log` بزرگ‌تر از ۱ مگابایت
   نمی‌شوند (نسخهٔ قبلی به `.old` می‌رود؛ سقف قطعی ۲ مگابایت برای هر مسیر).
3. **فایل ضربان ثابت‌حجم** `storage/cron-last-run.txt` — مهر زمانی هر اجرا در همان چند بایت
   بازنویسی می‌شود؛ برای اطمینان از زنده‌بودن کرون فقط کافی است مهرش تازه باشد.

## پیش‌نیاز

از دیتابیس **و** فایل‌ها پشتیبان کامل بگیرید (phpMyAdmin → Export و دانلود فایل‌ها).
قانون ثابت: اول پشتیبان، بعد ارتقا. (این بسته به دیتابیس دست نمی‌زند؛ پشتیبان فقط برای
آرامش خیال است.)

## گام ۱ — آپلود ZIP

محتوای ZIP را در ریشهٔ پروژه روی هاست (کنار `public_html` و `app`) استخراج کنید؛ ساختار ZIP
همان درخت استقرار است. این بستهٔ **جامع کامل** است و همهٔ فایل‌های S22 تا S27 را هم دارد؛
اگر هر فایلی از بسته‌های قبلی جا مانده باشد (که دقیقاً علت این ماجراست)، همین آپلود همه را
جبران می‌کند. فایل حیاتی این بسته: **`cron/worker.php`** — حتماً جایگزین شود.

## گام ۲ — پاک‌سازی کرون‌جاب‌ها در DirectAdmin (مهم‌ترین گام)

پنل DirectAdmin → **Cron Jobs** → فهرست کامل جاب‌ها را ببینید. قاعدهٔ نهایی:

- **دقیقاً یک** جابِ worker.php داشته باشید، با همین دستور:

```
/usr/local/bin/php /home/liliflor/domains/hamihubx.ir/cron/worker.php >> /home/liliflor/domains/hamihubx.ir/storage/cron.log 2>&1
```

  زمان‌بندی: هر ۱ دقیقه (`* * * * *`) — همان قبلی. حالا بی‌صدا است و لازم نیست تغییرش دهید.
- **هر جاب دیگری که به worker.php اشاره می‌کند حذف شود** — به‌خصوص جاب‌هایی که به
  `public_html/cron/worker.php` اشاره می‌کنند (میراث نسخه‌های اولیه).
- جاب روزانهٔ `bin/recalc-tiers.php` (اگر ساخته‌اید) بماند — مستقل و سالم است.

## گام ۳ — حذف پسماندهای قدیمی در فایل‌منیجر

- اگر پوشهٔ `public_html/cron` وجود دارد (به‌خصوص `worker.php` داخلش) → **حذف کنید**؛
  دیگر لازم نیست و نباید نسخهٔ قدیمی جایی برای اجرا بماند.
- اگر پوشهٔ `public_html/storage` هم از قدیم مانده → حذف کنید.

## گام ۴ — خالی‌کردن لاگ‌های فعلی (اختیاری ولی توصیه‌شده)

در فایل‌منیجر DirectAdmin فایل‌های `storage/cron.log` و `storage/logs/worker.log` را باز کنید،
همهٔ محتوا را پاک و ذخیره کنید (یا کل فایل را حذف کنید — اجرای بعدی کرون مسیر را خودش
دوباره می‌سازد). اگر کاری نکنید هم مشکلی نیست؛ از این به بعد سقف ۲ مگابایت دارند و به `.old`
چرخش می‌کنند.

## گام ۵ — راستی‌آزمایی (۵ دقیقه بعد از آپلود)

1. فایل `storage/cron-last-run.txt` را باز کنید — مهر زمانی باید کمتر از دو دقیقه قبل باشد.
   (این از این به بعد نشانهٔ «کرون زنده است» است.)
2. چند دقیقه صبر کنید و `storage/cron.log` را دوباره باز کنید — دیگر نباید خطوط
   «`worker.php executed.`» اضافه شود. فایل از این به بعد فقط هنگام رویداد واقعی خط می‌گیرد
   (مثلاً وقتی جاب جمع‌آوری اجرا شد یا بکاپ روزانه گرفته شد؛ یک خط JSON با مهر زمانی).
3. در پنل ادمین، بخش جمع‌آوری را باز کنید — از این به بعد پردازش خودکار صف واقعاً توسط
   کرون انجام می‌شود؛ و بکاپ روزانه هم سر موعدش در `system_backups` ثبت می‌شود.
4. اگر جاب `recalc-tiers` را گذاشته‌اید، فردا صبح در `cron.log` خط
   «پایان: N ترکیب موفق، ۰ ناموفق.» دیده می‌شود.

## اگر بعد از آپلود باز هم خطوط «worker.php executed.» اضافه شد

یعنی یک کرون‌جاب دوم قدیمی هنوز به نسخهٔ قدیمی اشاره می‌کند. فهرست کرون‌جاب‌های DirectAdmin
را دوباره چک کنید و جز همان یک جابِ گام ۲، بقیه را حذف کنید؛ و مطمئن شوید پوشهٔ
`public_html/cron` حذف شده است.

## اگر ساعت خطوط لاگ با ساعت واقعی فرق داشت

اگر مهر زمانی خطوط تازه با ساعت ایران نمی‌خواند، ساعت سرور هاست شما جابه‌جاست؛ به
پشتیبانی هاست گزارش دهید (روی زمان‌بندی کرون و بکاپ اثر می‌گذارد).

## خط قرمزهای امنیتی بسته

- هیچ فایلی از `config/` داخل بسته نیست — کانفیگ هاست دست‌نخورده می‌ماند.
- `public_html/cache` و `public_html/uploads` جایگزین نمی‌شوند.
- بدون مایگریشن؛ هیچ داده‌ای تغییر نمی‌کند.
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
        zf.writestr("RAHNAMA-S28-FA.md", INSTALL_FA)
        zf.writestr("MANIFEST-SHA256.txt", "\n".join(manifest_lines) + "\n")

    with open(os.path.join(OUT_DIR, "SHA256SUMS.txt"), "w", encoding="utf-8") as f:
        f.write("\n".join(manifest_lines) + "\n")

    zip_sha = sha256_of(OUT_ZIP)
    size = os.path.getsize(OUT_ZIP)
    with open(os.path.join(OUT_DIR, "PACKAGE-SHA256.txt"), "w", encoding="utf-8") as f:
        f.write(f"{zip_sha}  hamihub-full-upgrade-to-s28.zip\\n")

    print("S28 package built successfully")
    print(f"files: {len(files)}")
    print(f"zip: {OUT_ZIP}")
    print(f"zip_size_bytes: {size}")
    print(f"zip_sha256: {zip_sha}")
    with zipfile.ZipFile(OUT_ZIP) as zf:
        names = set(zf.namelist())
    required = [
        "cron/worker.php",
        "bin/recalc-tiers.php",
        "app/Services/MaintenanceService.php",
        "app/Services/CollectorRunner.php",
        "app/Services/BackupService.php",
        "app/Core/WorkerLock.php",
        "public_html/index.php",
        "RAHNAMA-S28-FA.md",
        "MANIFEST-SHA256.txt",
    ]
    missing = [r for r in required if r not in names]
    if missing:
        raise SystemExit("MISSING in zip: " + ", ".join(missing))
    print("required_files: OK")


if __name__ == "__main__":
    main()
