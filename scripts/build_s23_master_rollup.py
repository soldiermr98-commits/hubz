#!/usr/bin/env python3
"""
بسته‌ساز S23 — بستهٔ جامع ارتقای حامی‌هاب (all-in-one + رفع باگ‌های زیرساخت)

قواعد بسته:
- شامل: app/، public_html/ (بدون cache و uploads)، database/، cron/، bin/ و همین اسکریپت
- بدون: config/ (کانفیگ هاست)، storage/، collector-remote/، ai-worker/، tests/،
  src/ و فایل‌های محیط توسعهٔ پنل، release-files/، docs/، public/ (دارایی‌های dev)
- هر فایل داخل ZIP با SHA-256 در MANIFEST-SHA256.txt ثبت می‌شود
- ساختار ZIP همان درخت استقرار است: فایل‌ها از ریشهٔ ZIP به همان مسیرهای پروژه می‌نشینند
"""
import hashlib
import io
import os
import sys
import zipfile
from datetime import datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
STAMP = datetime.now().strftime("%Y%m%d%H%M%S")
OUT_DIR = os.path.join(ROOT, "release-files", "s23-infrastructure-hardening")
OUT_ZIP = os.path.join(OUT_DIR, "hamihub-full-upgrade-to-s23.zip")

INCLUDE_DIRS = ["app", "public_html", "database", "cron", "bin"]
PUBLIC_HTML_SKIP_TOP = {"cache", "uploads"}
ROOT_INCLUDE_FILES = ["scripts/build_s23_master_rollup.py"]

INSTALL_FA = """# راهنمای ارتقای کامل hamihubx.ir تا S23 — مقاوم‌سازی زیرساخت

**بسته:** `hamihub-full-upgrade-to-s23.zip` · **نسخهٔ دارایی‌ها:** `s21.0` (بدون تغییر) · **مایگریشن جدید:** ندارد

## این بسته چیست؟

بستهٔ **جامع و یکپارچهٔ ارتقا تا S23** است: کل درخت استقرار نسخهٔ S22 به‌علاوهٔ **۵ مقاوم‌سازی زیرساخت واقعی**. مثل بستهٔ جامع قبلی، کل درخت سایت (پوشه‌های `app`، `public_html`، `database`، `cron`، `bin`) را با آخرین نسخه جایگزین می‌کند؛ اگر سایت شما روی S22 باشد فقط مقاوم‌سازی‌ها اثر می‌گیرند؛ اگر قدیمی‌تر است همه‌چیز با هم به‌روز می‌شود.

**این بسته هیچ مایگریشن دیتابیس جدیدی ندارد** — ساختار دیتابیس دست‌نخورده می‌ماند.

## مقاوم‌سازی‌های این بسته (S23)

1. **تک‌تک‌سازی دو سرویس رسانه (حذف ~۵۹۰ خط تکرار):** کد مدیریت کتابخانهٔ رسانه (فهرست، ویرایش Alt، حذف، پاک‌سازی، نقشهٔ مصرف) که تا امروز دو کپی موازی و درهم در `MediaService` و `MediaLibraryService` داشت، اکنون منبع واحدش `MediaLibraryService` است؛ `MediaService` فقط «موتور آپلود» است و بقیهٔ متدهایش نمایندهٔ همان‌اند. آینده‌نگری: دیگر هیچ فیکسی فقط در یکی از دو کپی اعمال نمی‌شود.
2. **رفع race آپلود/پاک‌سازی رسانه:** «پاک‌سازی فایل‌های بدون رکورد» می‌توانست درست در پنجرهٔ بین نوشتن فایل روی هاست و ثبت ردیف دیتابیس اجرا شود و تصویر تازه‌آپلودشده را به‌عنوان orphan حذف کند. اکنون آپلود تا پایان ثبت دیتابیس قفل کتابخانه را نگه می‌دارد و پاک‌سازی در هم‌زمانی با آپلود با پرش امن (و ثبت در لاگ) انجام نمی‌شود.
3. **محدودیت نرخ واقعی روی اندپوینت آمار عمومی:** `/api/v1/events/track` (beacon بازدید و کلیک تماس/مسیریابی) که فقط سقف نشستی داشت، اکنون سقف فایل‌محور ۱۲۰ درخواست در دقیقه به‌ازای هر IP دارد (fail-open؛ رد شدن بی‌سروصدا است تا هیچ خطایی در کنسول مرورگر کاربر واقعی دیده نشود).
4. **درست‌سازی «حذف کامل اجباری» رسانهٔ متصل به بنر:** پارامتر `force` اندپوینت‌های رسانه از روز نخست بی‌صدا نادیده گرفته می‌شد (آرگومان سوم در امضای تابع وجود نداشت!). اکنون حذف اجباری، بنرهای متصل را غیرفعال و بی‌تصویر می‌کند و سپس رکورد و فایل‌ها کامل حذف می‌شوند — دقیقاً مطابق پیام‌هایی که رابط کاربری نشان می‌داد.
5. **بستن گپ دسترسی staff:** ویرایشگر (editor) می‌توانست وضعیت کسب‌وکار را مستقیماً «منتشرشده/مخفی/ردشده» بگذارد؛ اکنون همهٔ تصمیم‌های وضعیت‌گذاری برای ویرایشگر به «در انتظار بازبینی» هدایت می‌شود و انتشار فقط با نقش ادمین است.

## خط قرمزهای امنیتی بسته

- هیچ فایلی از `config/` (از جمله `config.php` و `config.local.php` هاست) داخل بسته نیست — کانفیگ هاست دست‌نخورده می‌ماند.
- پوشه‌های `collector-remote/`، `ai-worker/`، `storage/`، `tests/`، `docs/` و دارایی‌های dev (`src/`، `public/`، فایل‌های Vite) داخل بسته نیستند.
- `public_html/cache` و `public_html/uploads` (محتوای قابل تغییر هاست) جایگزین نمی‌شوند.
- اثرانگشت SHA-256 تک‌تک فایل‌ها در `MANIFEST-SHA256.txt` داخل ZIP و در `SHA256SUMS.txt` کنار بسته است.

## پیش‌نیاز

- از دیتابیس **و** فایل‌ها پشتیبان کامل بگیرید (phpMyAdmin → Export، و دانلود `public_html`).

## مراحل نصب

1. **پشتیبان:** مطابق پیش‌نیاز بالا.
2. **آپلود:** محتوای ZIP را با همان ساختار پوشه‌ها روی هاست جایگزین کنید (حالت Overwrite/Replace):
   - `app/…` → پوشهٔ `app` پروژه (هم‌تراز `public_html`)
   - `public_html/…` → داخل `public_html`
   - `database/` و `cron/` و `bin/…` → همان مسیرهای پروژه
   - فایل `scripts/build_s23_master_rollup.py` اختیاری است و فقط برای هم‌خوانی مخزن است.
   - **دقت:** `config/config.local.php`، پوشهٔ `storage/` و `public_html/uploads/` دست‌نخورده می‌مانند — بسته اصلاً شامل آن‌ها نیست. فایل‌های مخفی (`.htaccess`) هم باید جایگزین شوند.
3. **مایگریشن:** لازم نیست — این بسته هیچ تغییری در دیتابیس نمی‌دهد.
4. **کش:** نسخهٔ دارایی‌ها تغییر نکرده (فایل‌های JS/CSS عمومی تغییر نکرده‌اند)؛ اگر CDN دارید یک‌بار دستی خالی کنید.

## بررسی پس از نصب (چک‌لیست زنده)

1. **آپلود تصویر + پاک‌سازی هم‌زمان:** یک تصویر آپلود کنید و بلافاصله در تب رسانه «پاک‌سازی فایل‌های بدون رکورد» بزنید — تصویر جدید نباید حذف شود (قفل فعال است).
2. **سهمیهٔ بنر:** حذف یک بنر تبلیغاتی با گزینهٔ حذف تصویر مثل قبل باید رکورد و فایل را کامل پاک کند؛ و «حذف کامل اجباری» تصویر متصل به بنر از API حالا واقعاً بنر را غیرفعال و بی‌تصویر می‌کند.
3. **پنل ← کاربران:** اگر کاربر ویرایشگر دارید، ذخیرهٔ یک کسب‌وکار با وضعیت «منتشرشده» باید در «در انتظار بازبینی» بنشیند.
4. **سرعت سایت و آمار:** بازدید و کلیک تماس/مسیریابی همچنان در تب آمار ثبت می‌شود؛ فقط درخواست‌های انبوه غیرعادی (>۱۲۰ در دقیقه از یک IP) بی‌سروصدا مهار می‌شوند.
5. **لاگ خطا:** نباید خطای جدیدی در error_log هاست دیده شود (کلیدواژهٔ جدید: `HX media purge skipped` فقط در هم‌زمانی واقعی آپلود).
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

    # بررسی ساختاری همهٔ فایل‌های PHP داخل بسته (تابع check از اعتبارسنج، بدون اجرای ماژول)
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
        # فایل‌های متادیتا
        stamp_content = f"s23-master-rollup\nbuilt:{STAMP}\n"
        zf.writestr("UPDATE-STAMP.txt", stamp_content)
        zf.writestr("INSTALL-FA.md", INSTALL_FA)
        manifest_lines = []
        for rel, digest in entries:
            manifest_lines.append(f"{digest}  {rel}")
        manifest_lines = [x for x in manifest_lines if x]
        zf.writestr("MANIFEST-SHA256.txt", "\n".join(manifest_lines) + "\n")

    # SHA256SUMS بیرون بسته
    sums_path = os.path.join(OUT_DIR, "SHA256SUMS.txt")
    with open(sums_path, "w", encoding="utf-8") as f:
        f.write(f"{sha256_of(OUT_ZIP)}  hamihub-full-upgrade-to-s23.zip\n")

    print(f"بستهٔ S23 ساخته شد: {OUT_ZIP}")
    print(f"تعداد فایل‌های استقرار: {len(entries)} (بدون متادیتا)")
    print(f"SHA-256 ZIP: {sha256_of(OUT_ZIP)}")
    print(f"PHPهای بررسی‌شدهٔ ساختاری: {len(php_files)} — همگی سالم")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
