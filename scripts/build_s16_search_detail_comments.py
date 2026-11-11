#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
بسته‌ساز مرحله S16 — جستجوی سالم، ریدیزاین صفحه جزئیات، سیستم دیدگاه‌ها.
خروجی: ZIP افزایشی + SHA256SUMS + RAHNAMA-FA.md در release-files/s16-search-detail-comments/
بررسی‌های بسته: مسیر امن، موجودی فایل، توازن براکت PHP، نبود ارجاع بیرونی، هم‌خوانی نسخه s16.0،
تاریخ‌مندی timestamp برای ریست OPcache و ساخته‌شدن مایگریشن 020.
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
OUTPUT = ROOT / 'release-files/s16-search-detail-comments'
ZIP_NAME = 'hamihub-update-s16-search-detail-comments.zip'

# timestamp یکتا برای ریست OPcache روی هاست (جدیدتر از همه بسته‌های قبلی)
BUILD_TS = datetime.now(timezone.utc).strftime('%Y%m%d%H%M%S')
ZIP_MTIME = (2026, 11, 11, 12, 0, 0)

FILES = (
    'public_html/index.php',
    'public_html/api/index.php',
    'app/Controllers/PublicSiteController.php',
    'app/Services/CommentService.php',
    'app/Services/AdminMailService.php',
    'app/Core/Cache.php',
    'database/migrations/020_business_comments.sql',
    'public_html/assets/css/public.css',
    'public_html/assets/js/public.js',
    'public_html/admin/assets/admin.js',
    'public_html/admin/assets/admin.css',
    'public_html/admin/index.html',
    'tests/s16_public_ui_smoke.mjs',
    'scripts/build_s16_search_detail_comments.py',
)

FORBIDDEN_PREFIXES = ('/', '\\', '..')
PHP_OPEN_TAG_REQUIRED = ('public_html/index.php', 'public_html/api/index.php',
                         'app/Controllers/PublicSiteController.php', 'app/Services/CommentService.php',
                         'app/Services/AdminMailService.php', 'app/Core/Cache.php')


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

    # هم‌خوانی نسخه‌ها
    psc = payloads['app/Controllers/PublicSiteController.php'].decode('utf-8')
    cache = payloads['app/Core/Cache.php'].decode('utf-8')
    css = payloads['public_html/assets/css/public.css'].decode('utf-8')
    js = payloads['public_html/assets/js/public.js'].decode('utf-8')
    index = payloads['public_html/index.php'].decode('utf-8')
    api = payloads['public_html/api/index.php'].decode('utf-8')
    admin = payloads['public_html/admin/assets/admin.js'].decode('utf-8', errors='ignore')
    mig = payloads['database/migrations/020_business_comments.sql'].decode('utf-8')

    checks = [
        ('controller references s16.0 css', 'public.css?v=s16.0' in psc),
        ('controller references s16.0 js', 'public.js?v=s16.0' in psc),
        ('cache accepts s16.0', 'public.css?v=s16.0' in cache),
        ('css deployed rule intact', '.hx-grid [hidden]' in css),
        ('css has s16 detail gallery', 'hx-detail-gallery' in css),
        ('css has comment page', 'hx-comment-card' in css),
        ('js has comment endpoint', '/api/v1/public/comments' in js),
        ('index has /search route', "PublicSiteController::class, 'searchPage'" in index),
        ('index has /businesses route', "'businessesCatalog'" in index),
        ('index has comment route', "'commentPage'" in index),
        ('index has error shield', 'HXErrorShield' in index),
        ('api has comments routes', "'/api/v1/public/comments'" in api and "'/api/v1/comments'" in api),
        ('admin has comments view', 'دیدگاه' in admin and 'comments/moderate' in admin),
        ('migration 020 pending default', "DEFAULT 'pending'" in mig),
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
                   f's16-search-detail-comments\nbuilt:{BUILD_TS}\n')
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
