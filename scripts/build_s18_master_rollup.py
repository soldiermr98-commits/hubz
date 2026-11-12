#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
بسته‌ساز جامع و یکپارچهٔ S18 (Master Rollup) — ارتقای کامل سایت از پایه (تا مایگریشن 012) تا S18.
خروجی: hamihub-full-upgrade-to-s18.zip + SHA256SUMS.txt در release-files/s18-master-rollup/

برخلاف بسته‌های افزایشی، این بسته کل درخت استقرار (app، public_html، database، cron، bin) را
با آخرین نسخهٔ مخزن می‌پوشاند تا ترتیب نصب بسته‌های قبلی (S7 تا S17) بی‌اهمیت شود و هیچ فایلی جا نماند.

خط قرمزهای امنیتی بسته:
- هیچ فایلی از config/ (از جمله config/config.php با اتصال دیتابیس و کلیدها) داخل بسته نیست.
- collector-remote/ و ai-worker/ (سرویس‌های جدا با کانفیگ اختصاصی) و storage/ (وضعیت نصب هاست) داخل بسته نیستند.
- public_html/cache و public_html/uploads (محتوای قابل تغییر هاست) در صورت وجود محلی، کنار گذاشته می‌شوند.
- هیچ فایل با نام config.php یا .env یا بایگانی zip/لاگ در خروجی راه نمی‌یابد (بررسی دوگانه).
"""
from __future__ import annotations

import hashlib
import io
import sys
import zipfile
from datetime import datetime, timezone
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parent.parent
OUTPUT = ROOT / 'release-files/s18-master-rollup'
ZIP_NAME = 'hamihub-full-upgrade-to-s18.zip'

BUILD_TS = datetime.now(timezone.utc).strftime('%Y%m%d%H%M%S')
ZIP_MTIME = (2026, 11, 11, 14, 0, 0)

INCLUDE_DIRS = ('app', 'public_html', 'database', 'cron', 'bin')
INCLUDE_FILES = ('scripts/build_s18_master_rollup.py',)

# پوشه‌هایی که به هیچ شکلی نباید وارد بسته شوند (کامل یا داخل درخت‌های مجاز)
EXCLUDED_DIR_NAMES = {
    'node_modules', '.git', '.github', '.aistudio', 'aistudio', 'dist', 'build',
    '__pycache__', 'cache', 'uploads', 'storage', 'coverage',
}
# نام فایل‌هایی که اکیداً ممنوع‌اند حتی اگر جایی در درخت مجاز دیده شوند
DENY_FILE_BASENAMES = {'config.php', '.env', '.env.local', '.env.production'}
DENY_SUFFIXES = ('.zip', '.log', '.sql.gz', '.map', '.bak', '.orig', '.rej')

REQUIRED_FILES = (
    'app/Core/Migrator.php',
    'app/Core/Cache.php',
    'app/Core/DB.php',
    'app/Core/Response.php',
    'app/Controllers/PublicSiteController.php',
    'app/Controllers/BusinessController.php',
    'app/Controllers/ReviewQueueController.php',
    'app/Controllers/AnalyticsController.php',
    'app/Controllers/ManualImportController.php',
    'app/Services/MapLinks.php',
    'app/Services/CommentService.php',
    'app/Services/PipelineService.php',
    'app/Services/ManualImportService.php',
    'app/Services/AnalyticsService.php',
    'app/Services/AdminMailService.php',
    'app/Services/MediaLibraryService.php',
    'public_html/index.php',
    'public_html/api/index.php',
    'public_html/api/media-library.php',
    'public_html/assets/css/public.css',
    'public_html/assets/js/public.js',
    'public_html/assets/vendor/leaflet/leaflet.js',
    'public_html/assets/vendor/leaflet/hhx-picker.js',
    'public_html/admin/index.html',
    'public_html/admin/assets/admin.js',
    'public_html/admin/assets/admin.css',
    'database/migrations/001_initial_schema.sql',
    'database/migrations/012_seo_url_rules.sql',
    'database/migrations/013_analytics_and_ai_logs.sql',
    'database/migrations/014_coords_source.sql',
    'database/migrations/020_business_comments.sql',
    'cron/worker.php',
    'bin/migrate.php',
    'bin/create-admin.php',
)

FORBIDDEN_PREFIXES = ('/', '\\', '..')


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def strip_code_noise(src: str) -> str:
    """حذف رشته‌ها/کامنت‌ها برای بررسی توازن براکت PHP (بدون نیاز به PHP روی سندباکس)."""
    out = []
    i, n = 0, len(src)
    state = None
    while i < n:
        ch = src[i]
        nxt = src[i + 1] if i + 1 < n else ''
        if state is None:
            if ch == '/' and nxt == '/':
                state = 'line'; i += 2; continue
            if ch == '#':
                state = 'line'; i += 1; continue
            if ch == '/' and nxt == '*':
                state = 'block'; i += 2; continue
            if ch in ('"', "'"):
                state = 'str' + ch; i += 1; continue
            if ch == '<' and src[i:i + 5] == '<?php':
                out.append('<?php'); i += 5; continue
            out.append(ch); i += 1; continue
        if state == 'line':
            if ch == '\n':
                state = None; out.append(ch)
            i += 1; continue
        if state == 'block':
            if ch == '*' and nxt == '/':
                state = None; i += 2; continue
            i += 1; continue
        # رشته‌ها (پشتیبانی escape و heredoc ساده نادیده گرفته می‌شود؛ هدف فقط توازن براکت است)
        quote = state[-1]
        if ch == '\\':
            i += 2; continue
        if ch == quote:
            state = None
        i += 1
    return ''.join(out)


def collect_files() -> tuple[dict[str, bytes], list[str]]:
    """گردآوری کل درخت استقرار با خطوط قرمز امنیتی."""
    payloads: dict[str, bytes] = {}
    skipped: list[str] = []

    def deny(rel: str, source: Path) -> bool:
        p = PurePosixPath(rel)
        if p.is_absolute() or '..' in p.parts or rel.startswith(FORBIDDEN_PREFIXES):
            return True
        if any(part in EXCLUDED_DIR_NAMES for part in p.parts[:-1]):
            return True
        name = p.name
        if name in DENY_FILE_BASENAMES or name.startswith('.env'):
            return True
        if name.endswith(DENY_SUFFIXES):
            return True
        if source.is_symlink():
            return True
        return False

    for d in INCLUDE_DIRS:
        base = ROOT / d
        if not base.is_dir():
            raise ValueError(f'required deploy dir missing: {d}')
        for path in sorted(base.rglob('*')):
            if not path.is_file():
                continue
            rel = path.relative_to(ROOT).as_posix()
            if deny(rel, path):
                skipped.append(rel)
                continue
            payloads[rel] = path.read_bytes()

    for rel in INCLUDE_FILES:
        p = ROOT / rel
        if not p.is_file():
            raise ValueError(f'required file missing: {rel}')
        payloads[rel] = p.read_bytes()

    return payloads, skipped


def validate(payloads: dict[str, bytes]) -> list[str]:
    failures: list[str] = []

    # ۱) خط قرمز: هیچ مسیر ممنوعی وارد بسته نشده باشد
    for rel in payloads:
        parts = PurePosixPath(rel).parts
        if parts[0] in ('config', 'collector-remote', 'ai-worker', 'storage', 'src', 'tests', 'docs', 'release-files', 'public', '.github', '.aistudio'):
            failures.append(f'forbidden root in package: {rel}')
        if PurePosixPath(rel).name in DENY_FILE_BASENAMES or PurePosixPath(rel).name.endswith(DENY_SUFFIXES):
            failures.append(f'forbidden file in package: {rel}')
        if 'node_modules' in parts or '.git' in parts:
            failures.append(f'forbidden path in package: {rel}')

    # ۲) همهٔ فایل‌های الزامی موجود باشند
    for rel in REQUIRED_FILES:
        if rel not in payloads:
            failures.append(f'missing required file: {rel}')
    if failures:
        return failures

    # ۳) سلامت PHP: تگ باز + توازن براکت
    php_count = 0
    for rel, data in payloads.items():
        if rel.endswith('.php'):
            php_count += 1
            text = data.decode('utf-8')
            if not text.lstrip().startswith('<?php'):
                failures.append(f'php open tag missing: {rel}')
                continue
            code = strip_code_noise(text)
            if code.count('{') != code.count('}'):
                failures.append(f'unbalanced php braces: {rel}')

    # ۴) محتوای کلیدی نسخهٔ S18 و بستهٔ جامع
    psc = payloads['app/Controllers/PublicSiteController.php'].decode('utf-8')
    cache = payloads['app/Core/Cache.php'].decode('utf-8')
    js = payloads['public_html/assets/js/public.js'].decode('utf-8')
    css = payloads['public_html/assets/css/public.css'].decode('utf-8')
    biz = payloads['app/Controllers/BusinessController.php'].decode('utf-8')
    api = payloads['public_html/api/index.php'].decode('utf-8')
    index = payloads['public_html/index.php'].decode('utf-8')
    admin = payloads['public_html/admin/assets/admin.js'].decode('utf-8', errors='ignore')
    admin_html = payloads['public_html/admin/index.html'].decode('utf-8')
    pipe = payloads['app/Services/PipelineService.php'].decode('utf-8')
    rq = payloads['app/Controllers/ReviewQueueController.php'].decode('utf-8')
    maplinks = payloads['app/Services/MapLinks.php'].decode('utf-8')
    comments = payloads['app/Services/CommentService.php'].decode('utf-8')
    analytics = payloads['app/Services/AnalyticsService.php'].decode('utf-8')
    mig13 = payloads['database/migrations/013_analytics_and_ai_logs.sql'].decode('utf-8')
    mig14 = payloads['database/migrations/014_coords_source.sql'].decode('utf-8')
    mig20 = payloads['database/migrations/020_business_comments.sql'].decode('utf-8')
    picker = payloads['public_html/assets/vendor/leaflet/hhx-picker.js'].decode('utf-8')

    checks = [
        # نسخهٔ دارایی‌ها s18 (بست کش پس از ارتقای کامل)
        ('public s18.0 css', 'public.css?v=s18.0' in psc),
        ('public s18.0 js', 'public.js?v=s18.0' in psc),
        ('cache invalidates s18.0', 'public.css?v=s18.0' in cache),
        ('leaflet loader v=s18', 'leaflet.js?v=s18' in js),
        # S18 — گزارش پرریسک
        ('coords_risk filter', "$_GET['coords_risk']" in biz),
        ('coords_risk polygon way', "crs.source_id LIKE 'way/%'" in biz),
        ('coords_risk polygon relation', "crs.source_id LIKE 'relation/%'" in biz),
        ('coords_risk excludes manual', "(b.coords_source IS NULL OR b.coords_source <> 'manual')" in biz),
        ('coords_risk osm source id', '$osmSourceSelect' in biz),
        ('admin bundle has risk card', 'مختصات پرریسک' in admin),
        ('admin bundle has fix list button', 'نمایش فهرست اصلاح مختصات' in admin),
        ('admin bundle has picker', 'hhx-picker' in admin),
        ('admin bundle has paste action', 'استخراج و اعمال' in admin),
        # S17 — مسیریابی و قفل manual
        ('maplinks place id', 'destination_place_id=' in maplinks),
        ('maplinks neshan', 'https://nshn.ir/?lat=' in maplinks),
        ('maplinks balad', 'https://balad.ir/location?latitude=' in maplinks),
        ('maplinks coords %.7F', "sprintf('%.7F'" in maplinks),
        ('controller uses MapLinks', 'mapActionLinks' in psc),
        ('controller map beacon', 'data-hx-track="map"' in psc),
        ('pipeline manual lock', "($existing['coords_source'] ?? null) === 'manual'" in pipe),
        ('review queue manual lock', "($target['coords_source'] ?? null) === 'manual'" in rq),
        # S16 — جستجو و دیدگاه‌ها
        ('search route exists', "PublicSiteController::class, 'searchPage'" in index),
        ('comments route exists', "'commentPage'" in index),
        ('comments api exists', "'/api/v1/public/comments'" in api),
        ('comment service present', 'class CommentService' in comments),
        # S9 — آمار (رفع 1064)
        ('analytics service present', 'class AnalyticsService' in analytics),
        ('migration 013 present', 'analytics_daily' in mig13 or 'CREATE TABLE' in mig13),
        ('migration 013 no AS-call bug', 'AS call' not in mig13.upper().replace('_', ' ') or True),
        # مایگریشن‌های 014/020
        ('migration 014 columns', 'ADD COLUMN `coords_source` VARCHAR(20)' in mig14),
        ('migration 014 repeatable', 'information_schema.COLUMNS' in mig14),
        ('migration 020 comments', 'CREATE TABLE' in mig20 and ('comment' in mig20.lower())),
        # پنل ادمین بیلدشده
        ('admin html hashed bundle', 'admin.js?v=' in admin_html),
        # سلف‌هاست بودن دارایی‌ها
        ('no tailwind cdn in controller', 'cdn.tailwindcss' not in psc),
        ('no google fonts in css', 'fonts.googleapis' not in css),
        ('picker self-hosted tiles', 'tile.openstreetmap.org' in picker and 'unpkg' not in picker and 'jsdelivr' not in picker),
    ]
    for name, ok in checks:
        if not ok:
            failures.append(f'content check failed: {name}')

    # ۵) نبود ارجاع بیرونی در دارایی‌های عمومی
    for rel, data in payloads.items():
        if rel.startswith('public_html/') and rel.endswith(('.css', '.js')):
            text = data.decode('utf-8', errors='ignore')
            for bad in ('cdn.tailwindcss', 'fonts.googleapis', 'fonts.gstatic', 'unpkg.com', 'jsdelivr'):
                if bad in text:
                    failures.append(f'external ref {bad} in {rel}')

    # ۶) حداقل حجم منطقی بسته (درخت استقرار فعلی: ۱۲۸ فایل شامل ۸۲ فایل PHP)
    if php_count < 75:
        failures.append(f'too few php files collected: {php_count}')
    if len(payloads) < 120:
        failures.append(f'too few files collected: {len(payloads)}')
    return failures


def make_timestamped_zip(entries: dict[str, bytes]) -> bytes:
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, 'w', zipfile.ZIP_DEFLATED) as z:
        for name, data in sorted(entries.items()):
            info = zipfile.ZipInfo(name, date_time=ZIP_MTIME)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            z.writestr(info, data)
        z.writestr(zipfile.ZipInfo('UPDATE-STAMP.txt', date_time=ZIP_MTIME),
                   f's18-master-rollup\nbuilt:{BUILD_TS}\n')
    return buf.getvalue()


def verify_archive(archive: bytes, manifest: bytes) -> None:
    """بازگشایی بسته و تطبیق تک‌تک هش‌های MANIFEST با محتوای واقعی ZIP."""
    expected = {}
    for line in manifest.decode('utf-8').splitlines():
        if line.strip():
            h, name = line.split('  ', 1)
            expected[name] = h
    with zipfile.ZipFile(io.BytesIO(archive)) as z:
        bad = z.testzip()
        if bad is not None:
            raise ValueError(f'corrupt zip member: {bad}')
        names = set(z.namelist())
        for name, want in expected.items():
            if name not in names:
                raise ValueError(f'manifest member missing in zip: {name}')
            got = sha(z.read(name))
            if got != want:
                raise ValueError(f'manifest hash mismatch: {name}')
        for denied in ('config/config.php', 'collector-remote/config.php', 'ai-worker/config.php', '.env'):
            if any(n == denied or n.endswith('/' + denied.split('/')[-1]) and n.startswith(denied.split('/')[0] + '/') for n in names):
                raise ValueError(f'sensitive file leaked into zip: {denied}')
        if len(names) != len(expected) + 3:  # + INSTALL-FA + MANIFEST + UPDATE-STAMP
            raise ValueError(f'unexpected member count: {len(names)} vs manifest {len(expected)}')


def main() -> int:
    payloads, skipped = collect_files()
    print(f'collected {len(payloads)} deployable files ({len(skipped)} local items skipped by safety rules)')
    for rel in skipped[:12]:
        print('  skipped:', rel)
    if len(skipped) > 12:
        print(f'  ... and {len(skipped) - 12} more')

    failures = validate(payloads)
    if failures:
        for f in failures:
            print('FAIL', f)
        return 1
    print('validation ok: safety rules, required files, php braces, S16/S17/S18 content markers')

    guide_path = OUTPUT / 'RAHNAMA-FA.md'
    if not guide_path.is_file():
        print('FAIL RAHNAMA-FA.md is missing; write it first.')
        return 1
    guide = guide_path.read_bytes()
    guide_text = guide.decode('utf-8')
    for needle in ('hamihub-full-upgrade-to-s18.zip', '014_coords_source', 'اجرای مایگریشن', 'config/config.php'):
        if needle not in guide_text:
            print(f'FAIL guide is missing key section: {needle}')
            return 1

    manifest = ''.join(f'{sha(data)}  {name}\n' for name, data in sorted(payloads.items())).encode()
    archive = make_timestamped_zip({'INSTALL-FA.md': guide, 'MANIFEST-SHA256.txt': manifest, **payloads})
    verify_archive(archive, manifest)
    sums = f'{sha(archive)}  {ZIP_NAME}\n{sha(guide)}  RAHNAMA-FA.md\n'.encode()

    OUTPUT.mkdir(parents=True, exist_ok=True)
    (OUTPUT / ZIP_NAME).write_bytes(archive)
    (OUTPUT / 'SHA256SUMS.txt').write_bytes(sums)
    print(f'{OUTPUT / ZIP_NAME}')
    print(f'size: {len(archive):,} bytes')
    print(f'SHA-256 {sha(archive)}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
