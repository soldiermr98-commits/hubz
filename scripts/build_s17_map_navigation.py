#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
بسته‌ساز مرحله S17 — نقشه و مسیریابی هوشمند + انتخاب مکان در پنل ادمین + قفل مختصات دستی.
خروجی: ZIP افزایشی + SHA256SUMS + RAHNAMA-FA.md در release-files/s17-map-navigation/
بررسی‌های بسته: مسیر امن، موجودی فایل، توازن براکت PHP، نبود ارجاع بیرونی، هم‌خوانی نسخه s17.0،
منطق MapLinks (destination_place_id / نشان / بلد)، قفل manual در هر سه سرویس،
تکرارپذیری مایگریشن 014، موتور picker سلف‌هاست و توکن‌های ادمین بیلدشده.
"""
from __future__ import annotations

import hashlib
import io
import re
import sys
import zipfile
from datetime import datetime, timezone
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parent.parent
OUTPUT = ROOT / 'release-files/s17-map-navigation'
ZIP_NAME = 'hamihub-update-s17-map-navigation.zip'

# timestamp یکتا برای ریست OPcache روی هاست (جدیدتر از همه بسته‌های قبلی)
BUILD_TS = datetime.now(timezone.utc).strftime('%Y%m%d%H%M%S')
ZIP_MTIME = (2026, 11, 11, 13, 0, 0)

FILES = (
    # فایل‌های هستهٔ S16 که S17 روی آن‌ها سوار می‌شود (نصب مجدد بی‌ضرر؛ بسته را از S16 رو به بالا یکپارچه نگه می‌دارد)
    'public_html/index.php',
    'public_html/api/index.php',
    'app/Core/Cache.php',
    'app/Services/CommentService.php',
    'app/Services/AdminMailService.php',
    'database/migrations/020_business_comments.sql',
    'public_html/assets/css/public.css',
    # فایل‌های اصلی S17
    'app/Controllers/PublicSiteController.php',
    'app/Controllers/BusinessController.php',
    'app/Controllers/ReviewQueueController.php',
    'app/Services/MapLinks.php',
    'app/Services/PipelineService.php',
    'database/migrations/014_coords_source.sql',
    'public_html/assets/js/public.js',
    'public_html/assets/vendor/leaflet/leaflet.js',
    'public_html/assets/vendor/leaflet/hhx-picker.js',
    'public_html/admin/assets/admin.js',
    'public_html/admin/assets/admin.css',
    'public_html/admin/index.html',
    'src/services/coordsParse.ts',
    'src/services/adminApi.ts',
    'src/components/BusinessesManagerView.tsx',
    'tests/s17_map_navigation_smoke.mjs',
    'tests/s17_coords_parse_smoke.tsx',
    'scripts/build_s17_map_navigation.py',
)

FORBIDDEN_PREFIXES = ('/', '\\', '..')
PHP_OPEN_TAG_REQUIRED = (
    'public_html/index.php', 'public_html/api/index.php',
    'app/Controllers/PublicSiteController.php', 'app/Controllers/BusinessController.php',
    'app/Controllers/ReviewQueueController.php', 'app/Services/MapLinks.php',
    'app/Services/PipelineService.php', 'app/Services/CommentService.php',
    'app/Services/AdminMailService.php', 'app/Core/Cache.php',
)


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def strip_code_noise(src: str) -> str:
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
            if ch == "'":
                state = "'"; out.append("'"); i += 1; continue
            if ch == '"':
                state = '"'; out.append('"'); i += 1; continue
            out.append(ch); i += 1
        elif state == 'line':
            if ch == '\n':
                state = None; out.append('\n')
            i += 1
        elif state == 'block':
            if ch == '*' and nxt == '/':
                state = None; i += 2; continue
            i += 1
        elif state in ("'", '"'):
            if ch == '\\':
                i += 2; continue
            if ch == state:
                state = None; out.append('"')
            i += 1
    return ''.join(out)


def validate() -> dict:
    stats = {'files': 0, 'php_balanced': 0, 'checks_failed': []}
    payloads = {}
    for rel in FILES:
        p = PurePosixPath(rel)
        if p.is_absolute() or '..' in p.parts or rel.startswith(FORBIDDEN_PREFIXES):
            raise ValueError(f'unsafe path: {rel}')
        source = ROOT / rel
        if source.is_symlink() or not source.is_file():
            raise ValueError(f'missing or symlinked file: {rel}')
        data = source.read_bytes()
        text = data.decode('utf-8')
        # نبود ارجاع بیرونی در دارایی‌های عمومی
        if rel.endswith(('.css', '.js')):
            for bad in ('cdn.tailwindcss', 'fonts.googleapis', 'fonts.gstatic', 'unpkg.com', 'jsdelivr'):
                if bad in text:
                    stats['checks_failed'].append(f'{rel}: external ref {bad}')
        # توازن براکت PHP
        if rel.endswith('.php'):
            code = strip_code_noise(text)
            if code.count('{') != code.count('}'):
                stats['checks_failed'].append(f'{rel}: unbalanced braces')
            else:
                stats['php_balanced'] += 1
        if rel in PHP_OPEN_TAG_REQUIRED and not text.startswith('<?php'):
            stats['checks_failed'].append(f'{rel}: missing php open tag')
        payloads[rel] = data
        stats['files'] += 1

    # هم‌خوانی نسخه‌ها و منطق S17
    psc = payloads['app/Controllers/PublicSiteController.php'].decode('utf-8')
    cache = payloads['app/Core/Cache.php'].decode('utf-8')
    css = payloads['public_html/assets/css/public.css'].decode('utf-8')
    js = payloads['public_html/assets/js/public.js'].decode('utf-8')
    leaflet = payloads['public_html/assets/vendor/leaflet/leaflet.js'].decode('utf-8')
    picker = payloads['public_html/assets/vendor/leaflet/hhx-picker.js'].decode('utf-8')
    maplinks = payloads['app/Services/MapLinks.php'].decode('utf-8')
    pipe = payloads['app/Services/PipelineService.php'].decode('utf-8')
    rq = payloads['app/Controllers/ReviewQueueController.php'].decode('utf-8')
    biz = payloads['app/Controllers/BusinessController.php'].decode('utf-8')
    mig14 = payloads['database/migrations/014_coords_source.sql'].decode('utf-8')
    index = payloads['public_html/index.php'].decode('utf-8')
    api = payloads['public_html/api/index.php'].decode('utf-8')
    admin = payloads['public_html/admin/assets/admin.js'].decode('utf-8', errors='ignore')

    checks = [
        # نسخهٔ دارایی‌ها
        ('controller references s17.0 css', 'public.css?v=s17.0' in psc),
        ('controller references s17.0 js', 'public.js?v=s17.0' in psc),
        ('cache accepts s17.0', 'public.css?v=s17.0' in cache),
        ('public.js loads leaflet v=s17', 'leaflet.js?v=s17' in js),
        # MapLinks: منطق دکمهٔ اصلی و پروایدرها
        ('maplinks has destination_place_id', 'destination_place_id=' in maplinks),
        ('maplinks has neshan url', 'https://nshn.ir/?lat=' in maplinks),
        ('maplinks has balad url', 'https://balad.ir/location?latitude=' in maplinks),
        ('maplinks has osm url', 'https://www.openstreetmap.org/?mlat=' in maplinks),
        ('maplinks coords %.7F', "sprintf('%.7F'" in maplinks),
        ('maplinks manual lock helper', "'manual'" in maplinks),
        # کنترلر عمومی: helper واحد + جستجوی متنی فقط کمکی + بیکن
        ('controller uses mapActionLinks', 'mapActionLinks' in psc),
        ('controller drops raw google dir link', 'https://www.google.com/maps/dir/?api=1&destination=' not in psc),
        ('controller hero variant', "'business_detail', 'hero'" in psc),
        ('controller sidebar variant', "'business_detail', 'sidebar'" in psc),
        ('controller has text-search secondary', 'جستجوی نام در Google Maps' in psc),
        ('controller has neshan button', 'مسیریابی با نشان' in psc),
        ('controller has balad button', 'مسیریابی با بلد' in psc),
        ('controller map tracking', 'data-hx-track="map"' in psc),
        ('controller place id lookup', 'MapLinks::googlePlaceId' in psc),
        ('controller imports MapLinks', 'use App\\Services\\MapLinks;' in psc),
        ('markers carry business id', "(int)$b['id']" in psc),
        # popup نقشه
        ('leaflet popup neshan', 'https://nshn.ir/?lat=' in leaflet),
        ('leaflet popup balad', 'https://balad.ir/location?latitude=' in leaflet),
        ('leaflet popup tracking', "a.setAttribute('data-hx-track', 'map')" in leaflet),
        # موتور picker ادمین سلف‌هاست
        ('picker is selfhosted', 'tile.openstreetmap.org' in picker and 'cdn.' not in picker),
        ('picker exposes API', 'window.HHXPicker' in picker and 'setPos' in picker),
        ('picker draggable pin', 'hhx-picker-pin' in picker),
        # قفل مختصات دستی
        ('pipeline manual lock', "($existing['coords_source'] ?? null) === 'manual'" in pipe),
        ('pipeline provenance on insert', 'coords_source' in pipe),
        ('review queue manual lock', "($target['coords_source'] ?? null) === 'manual'" in rq),
        ('review queue provenance', 'coords_source=COALESCE(:src,coords_source)' in rq),
        ('business controller manual on change', "$coordsSource = 'manual'" in biz),
        ('business controller coords changed guard', '$coordsChanged' in biz and '$coordsCleared' in biz),
        # مایگریشن 014
        ('migration 014 coords_source', 'ADD COLUMN `coords_source` VARCHAR(20)' in mig14),
        ('migration 014 verified_at', 'ADD COLUMN `coords_verified_at` DATETIME' in mig14),
        ('migration 014 repeatable', 'information_schema.COLUMNS' in mig14),
        # بقیهٔ فایل‌های S16 که یکپارچگی بسته را حفظ می‌کنند
        ('index has /search route', "PublicSiteController::class, 'searchPage'" in index),
        ('index has comment route', "'commentPage'" in index),
        ('api has comments routes', "'/api/v1/public/comments'" in api and "'/api/v1/comments'" in api),
        ('css deployed rule intact', '.hx-grid [hidden]' in css),
        # ادمین بیلدشده
        ('admin has picker reference', 'hhx-picker' in admin),
        ('admin has paste extract button', 'استخراج و اعمال' in admin),
        ('admin has coords source display', 'coords_source' in admin),
        ('no tailwind cdn in controller', 'cdn.tailwindcss' not in psc),
        ('no google fonts in css', 'fonts.googleapis' not in css),
    ]
    for name, ok in checks:
        if not ok:
            stats['checks_failed'].append(name)
    return stats


def make_timestamped_zip(entries: dict[str, bytes]) -> bytes:
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, 'w', zipfile.ZIP_DEFLATED) as z:
        for name, data in sorted(entries.items()):
            info = zipfile.ZipInfo(name, date_time=ZIP_MTIME)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            z.writestr(info, data)
        # فایل برچسب زمان ساخت برای تفکیک نسخه‌ها و ریست OPcache
        z.writestr(zipfile.ZipInfo('UPDATE-STAMP.txt', date_time=ZIP_MTIME),
                   f's17-map-navigation\nbuilt:{BUILD_TS}\n')
    return buf.getvalue()


def main() -> int:
    stats = validate()
    if stats['checks_failed']:
        for f in stats['checks_failed']:
            print('FAIL', f)
        return 1
    print(f"validation ok: {stats['files']} files, {stats['php_balanced']} php balanced")

    payload = {rel: (ROOT / rel).read_bytes() for rel in FILES}
    guide_path = OUTPUT / 'RAHNAMA-FA.md'
    if not guide_path.is_file():
        print('FAIL RAHNAMA-FA.md is missing; write it first.')
        return 1
    guide = guide_path.read_bytes()

    manifest = ''.join(f'{sha(data)}  {name}\n' for name, data in sorted(payload.items())).encode()
    archive = make_timestamped_zip({'INSTALL-FA.md': guide, 'MANIFEST-SHA256.txt': manifest, **payload})
    sums = f'{sha(archive)}  {ZIP_NAME}\n{sha(guide)}  RAHNAMA-FA.md\n'.encode()

    OUTPUT.mkdir(parents=True, exist_ok=True)
    (OUTPUT / ZIP_NAME).write_bytes(archive)
    (OUTPUT / 'SHA256SUMS.txt').write_bytes(sums)
    print(f'{OUTPUT / ZIP_NAME}')
    print(f'SHA-256 {sha(archive)}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
