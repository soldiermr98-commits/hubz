#!/usr/bin/env python3
"""
بسته‌ساز S24 — بستهٔ جامع ارتقای حامی‌هاب (لانچ فنی: فاز ۳ بدون درگاه + فاز ۴)

قواعد بسته:
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
OUT_DIR = os.path.join(ROOT, "release-files", "s24-technical-launch")
OUT_ZIP = os.path.join(OUT_DIR, "hamihub-full-upgrade-to-s24.zip")

INCLUDE_DIRS = ["app", "public_html", "database", "cron", "bin"]
PUBLIC_HTML_SKIP_TOP = {"cache", "uploads"}
ROOT_INCLUDE_FILES = ["scripts/build_s24_master_rollup.py"]

INSTALL_FA = """# راهنمای ارتقای کامل hamihubx.ir تا S24 — لانچ فنی

**بسته:** `hamihub-full-upgrade-to-s24.zip` · **نسخهٔ دارایی‌ها:** `s21.0` (بدون تغییر؛ فایل‌های JS/CSS عمومی عوض نشده‌اند) · **مایگریشن جدید:** `022_system_backups.sql`

## این بسته چیست؟

بستهٔ **جامع و یکپارچهٔ ارتقا تا S24 (گام دوم نقشهٔ راه)** است: کل درخت استقرار نسخهٔ S23 به‌علاوهٔ **فعال‌سازی صف AI بدون توهم، تکمیل دو استاب کد، بکاپ روزانهٔ خودکار دیتابیس و پایش سلامت سیستم**. اگر سایت شما روی S23 است فقط این موارد اثر می‌گیرند؛ اگر روی S22 یا قدیمی‌تر است، کل درخت با آخرین نسخه یکجا به‌روز می‌شود (آپلود S23 لازم نیست).

**این بسته یک مایگریشن دیتابیس جدید دارد** (`022_system_backups.sql`) — جدول `system_backups` و دو تنظیم بکاپ؛ هیچ دادهٔ موجود تغییر نمی‌کند.

**درگاه پرداخت آنلاین در این نسخه نیست و عمداً حذف شده است** (تصمیم صریح مدیر): فروش با کارت‌به‌کارت و ثبت فیش پیش می‌رود و فاز ۳ درگاه live ندارد.

## موارد این بسته (S24)

1. **فعال‌سازی صف AI بدون توهم:** چهار رووت `/api/v1/ai/tasks/…` که از S5 غیرفعال بودند اکنون واقعی‌اند؛ پنل جدید در تنظیمات: آمار صف، ثبت دسته‌ای، اجرای فوری ۱۰ تسک با گاردریل ضد توهم، فهرست تسک‌ها با دلیل رد شدن. خروجی هر تسک پیش از ثبت اعتبارسنجی می‌شود؛ متن‌ها فقط از فیلدهای راستی‌آزمایی‌شدهٔ دیتابیس ساخته می‌شوند.
2. **تکمیل `BusinessSeoService::resetSchemaCache`:** ریست کش بررسی جدول سئو که قبلاً بی‌اثر بود؛ بعد از اجرای مایگریشن ۰۱۲ در همان درخواست، پاسخ کهنه نمی‌ماند.
3. **تکمیل `PipelineService::recalculateTiersForCityCategory`:** امتیازدهی بیزی صادقانه `(v/(v+m))·R + (m/(v+m))·C` فقط از امتیازهای واقعاً مشاهده‌شده؛ کسب‌وکار بدون ستاره هرگز از دادهٔ غیب رتبه نمی‌گیرد و tier او بدون شواهد واقعی تغییر نمی‌کند.
4. **بکاپ روزانهٔ خودکار دیتابیس:** خروجی SQL فشرده در `storage/backups` با نگهداری ۱۴ روز، بدون mysqldump و سازگار با هاست اشتراکی؛ با کرون خودکار است و دکمهٔ «بکاپ دستی» و فهرست بکاپ‌ها در پنل دارد. جدول‌های لاگ خام فقط ساختارشان بکاپ می‌شود تا فایل قابل‌مدیریت بماند.
5. **پایش سلامت سیستم:** کارت «سلامت سیستم و بکاپ» در تنظیمات با ۷ بررسی (دیتابیس، کرون، صف AI، بکاپ، دسترسی نوشتن، مایگریشن‌ها، فایل‌های عمومی) — ریشه‌یابی سریع در روز لانچ و بعد از آن.
6. **تست دود لایو:** `php bin/smoke-live.php https://hamihubx.ir` — بررسی ۶ مسیر اصلی سایت با خروجی JSON.
7. **اصلاح مدل پیش‌فرض NVIDIA:** شناسهٔ منقضی `nvidia/nemotron-3.5-lightning-30b-a3b` به شناسهٔ معتبر `nvidia/nemotron-3.5-lightning` اصلاح شد (راستی‌آزمایی زندهٔ کاتالوگ). `openrouter/free` و `gemini-3.5-flash-lite` تأیید شدند و بدون تغییر مانده‌اند.

## خط قرمزهای امنیتی بسته

- هیچ فایلی از `config/` داخل بسته نیست — کانفیگ هاست (`config.local.php`) دست‌نخورده می‌ماند.
- پوشه‌های `collector-remote/`، `ai-worker/`، `storage/`، `tests/`، `docs/` و دارایی‌های dev (`src/`، `public/`، فایل‌های Vite) داخل بسته نیستند.
- `public_html/cache` و `public_html/uploads` جایگزین نمی‌شوند.
- کلیدهای هوش مصنوعی و توکن‌های سرور هیچ‌وقت داخل بسته یا مایگریشن نیستند.
- اثرانگشت SHA-256 تک‌تک فایل‌ها در `MANIFEST-SHA256.txt` داخل ZIP و `SHA256SUMS.txt` کنار بسته است.

## پیش‌نیاز

- از دیتابیس **و** فایل‌ها پشتیبان کامل بگیرید (phpMyAdmin → Export، و دانلود `public_html`). مایگریشن ۰۲۲ فقط جدول و تنظیم جدید اضافه می‌کند، اما قانون ثابت است: اول پشتیبان.

## مراحل نصب

1. **پشتیبان:** مطابق پیش‌نیاز بالا.
2. **آپلود:** محتوای ZIP را با همان ساختار پوشه‌ها روی هاست جایگزین کنید (حالت Overwrite/Replace):
   - `app/…` → پوشهٔ `app` پروژه (هم‌تراز `public_html`)
   - `public_html/…` → داخل `public_html`
   - `database/` و `cron/` و `bin/…` → همان مسیرهای پروژه
   - فایل `scripts/build_s24_master_rollup.py` اختیاری است و فقط برای هم‌خوانی مخزن است.
   - **دقت:** `config/config.local.php`، پوشهٔ `storage/` و `public_html/uploads/` دست‌نخورده می‌مانند. فایل‌های مخفی (`.htaccess`) هم باید جایگزین شوند.
3. **مایگریشن:** پنل مدیریت → تنظیمات → «اجرای مایگریشن‌ها»؛ پیام موفقیت مایگریشن `022` را ببینید.
4. **کرون:** اگر کرون `cron/worker.php` از قبل فعال است، هیچ چیز عوض نکنید — بکاپ خودکار از همان کرون اجرا می‌شود (بیش از یک‌بار در ۲۰ ساعت). اگر فعال نیست، طبق راهنمای تب «تنظیمات» پنل فعالش کنید.

## بررسی پس از نصب (چک‌لیست لانچ)

1. **سلامت:** پنل → تنظیمات → کارت «سلامت سیستم و بکاپ» — هر ۷ چک باید سبک باشند (کرون تا اولین اجرا هشدار می‌دهد؛ طبیعی است).
2. **بکاپ:** دکمهٔ «بکاپ دستی الان» را بزنید؛ فایل `.sql.gz` در `storage/backups` ساخته می‌شود و در فهرست پنل ظاهر می‌شود. فایل را دانلود و باز کنید تا از سالم بودنش مطمئن شوید.
3. **تست دود:** `php bin/smoke-live.php https://hamihubx.ir` → `"ok": true`.
4. **صف AI (اختیاری):** در تنظیمات → «صف کارهای هوش مصنوعی بدون توهم»: ثبت دسته‌ای → اجرای فوری → آمار «انجام شد». توجه: هر تسک درخواست واقعی به سرویس انتخابی است و سهمیه می‌برد.
5. **آمار:** بازدید و کلیک تماس/مسیریابی همچنان در تب آمار ثبت می‌شود (فعالیت فاز ۲ بدون تغییر).
6. **لاگ خطا:** در ۲۴ ساعت اول error_log نباید خطای PHP جدید داشته باشد.

## بعد از لانچ

چک‌لیست کامل «روز لانچ» در مخزن: `docs/LAUNCH_CHECKLIST_FA.md`. گام بعدی نقشهٔ راه: فاز ۶ سئو (صفحات فرود شهر×دسته، اسکیما و مقالات متصل به دادهٔ واقعی).
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
        stamp_content = f"s24-master-rollup\nbuilt:{STAMP}\n"
        zf.writestr("UPDATE-STAMP.txt", stamp_content)
        zf.writestr("INSTALL-FA.md", INSTALL_FA)
        manifest_lines = []
        for rel, digest in entries:
            manifest_lines.append(f"{digest}  {rel}")
        manifest_lines = [x for x in manifest_lines if x]
        zf.writestr("MANIFEST-SHA256.txt", "\n".join(manifest_lines) + "\n")

    sums_path = os.path.join(OUT_DIR, "SHA256SUMS.txt")
    with open(sums_path, "w", encoding="utf-8") as f:
        f.write(f"{sha256_of(OUT_ZIP)}  hamihub-full-upgrade-to-s24.zip\n")

    print(f"بستهٔ S24 ساخته شد: {OUT_ZIP}")
    print(f"تعداد فایل‌های استقرار: {len(entries)} (بدون متادیتا)")
    print(f"SHA-256 ZIP: {sha256_of(OUT_ZIP)}")
    print(f"PHPهای بررسی‌شدهٔ ساختاری: {len(php_files)} — {'همگی سالم' if not problems else 'مشکل!'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
