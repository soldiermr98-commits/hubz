#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
بسته‌ساز مرحله S19 — مودال ریسپانسیو درخواست تبلیغات + صفحه اختصاصی /advertise
+ سوال امنیتی فرم تبلیغات + رفع تله «تست ایمیل موفق ولی اعلان‌ها ارسال نمی‌شود».
خروجی: ZIP افزایشی + SHA256SUMS + RAHNAMA-FA.md در release-files/s19-ad-modal-email/
بررسی‌ها: مسیر امن، موجودی فایل، توازن براکت PHP، نبود ارجاع بیرونی، هم‌خوانی نسخه s19.0،
روت‌های /advertise و ad-question، سوال امنیتی در submitInquiry، مودال در لایوت،
فچ سوال در public.js و هشدار کلید فعال‌سازی در تست ایمیل.
"""
from __future__ import annotations

import hashlib
import io
import sys
import zipfile
from datetime import datetime, timezone
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parent.parent
OUTPUT = ROOT / 'release-files/s19-ad-modal-email'
ZIP_NAME = 'hamihub-update-s19-ad-modal-email.zip'

BUILD_TS = datetime.now(timezone.utc).strftime('%Y%m%d%H%M%S')
ZIP_MTIME = (2026, 11, 12, 10, 0, 0)

FILES = (
    'app/Controllers/PublicSiteController.php',
    'app/Controllers/AdvertisingController.php',
    'app/Controllers/AdminMailController.php',
    'app/Services/AdminMailService.php',
    'app/Core/Cache.php',
    'public_html/index.php',
    'public_html/api/index.php',
    'public_html/assets/js/public.js',
    'public_html/assets/css/public.css',
    'tests/s19_ad_modal_smoke.mjs',
    'tests/s18_coords_risk_smoke.mjs',
    'scripts/build_s19_ad_modal_email.py',
)

FORBIDDEN_PREFIXES = ('/', '\\', '..')
PHP_OPEN_TAG_REQUIRED = (
    'app/Controllers/PublicSiteController.php',
    'app/Controllers/AdvertisingController.php',
    'app/Controllers/AdminMailController.php',
    'app/Services/AdminMailService.php',
    'app/Core/Cache.php',
    'public_html/index.php',
    'public_html/api/index.php',
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
        if rel.endswith(('.css', '.js')):
            for bad in ('cdn.tailwindcss', 'fonts.googleapis', 'fonts.gstatic', 'unpkg.com', 'jsdelivr'):
                if bad in text:
                    stats['checks_failed'].append(f'{rel}: external ref {bad}')
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

    psc = payloads['app/Controllers/PublicSiteController.php'].decode('utf-8')
    adv = payloads['app/Controllers/AdvertisingController.php'].decode('utf-8')
    mailc = payloads['app/Controllers/AdminMailController.php'].decode('utf-8')
    mails = payloads['app/Services/AdminMailService.php'].decode('utf-8')
    cache = payloads['app/Core/Cache.php'].decode('utf-8')
    index = payloads['public_html/index.php'].decode('utf-8')
    api = payloads['public_html/api/index.php'].decode('utf-8')
    js = payloads['public_html/assets/js/public.js'].decode('utf-8')
    css = payloads['public_html/assets/css/public.css'].decode('utf-8')

    checks = [
        # نسخه‌ها
        ('controller references s19.0 css', 'public.css?v=s19.0' in psc),
        ('controller references s19.0 js', 'public.js?v=s19.0' in psc),
        ('no stale s18.0 in controller', 's18.0' not in psc),
        ('cache accepts s19.0', 'public.css?v=s19.0' in cache),
        ('public.js loads leaflet s19', 'leaflet.js?v=s19' in js and 'leaflet.css?v=s19' in js),
        # صفحه اختصاصی + API سوال
        ('advertisePage exists', 'public static function advertisePage(' in psc),
        ('adQuestion endpoint exists', 'public static function adQuestion(' in psc),
        ('adFormInner shared renderer', 'private static function adFormInner(' in psc),
        ('advertise gated on schemaReady', 'AdvertisingService::schemaReady()' in psc.split('advertisePage')[1][:400]),
        ('advertise uses fresh question', 'CommentService::generateQuestion(0)' in psc),
        ('noindex not set on advertise', 'noindex' not in psc.split('advertisePage')[1][:2200]),
        # مودال + CTA
        ('modal markup in layout', 'id="hx-ad-modal"' in psc),
        ('footer cta card', 'hx-ad-cta-card' in psc),
        ('cta data attr', 'data-hx-ad-modal' in psc),
        ('cta links to /advertise', 'href="/advertise" data-hx-ad-modal' in psc),
        ('modal noscript fallback', 'رفتن به فرم درخواست تبلیغات' in psc),
        ('old details removed', '<details class="hx-ad-contact"' not in psc),
        # سوال امنیتی در ثبت درخواست
        ('submitInquiry verifies question', '\\App\\Services\\CommentService::verifyQuestion(' in adv),
        ('submitInquiry clear error', 'پاسخ سوال امنیتی درست نیست یا منقضی شده است' in adv),
        # روت‌ها
        ('index has /advertise route', "PublicSiteController::class, 'advertisePage'" in index),
        ('advertise route before catch-all', index.index("'/advertise'") < index.index("'/{city}/{slug}'")),
        ('api has ad-question route', "PublicSiteController::class, 'adQuestion'" in api),
        # فرانت
        ('js manages modal', "getElementById('hx-ad-modal')" in js),
        ('js fetches question', "fetch('/api/v1/public/ad-question'" in js),
        ('js escape close', "ev.key === 'Escape'" in js),
        ('js success event', 'hx:ad-success' in js),
        ('js refresh-question event', 'hx:ad-refresh-question' in js),
        ('css modal panel', '.hx-modal-panel' in css),
        ('css footer cta card', '.hx-ad-cta-card' in css),
        ('css advertise hero', '.hx-advert-hero' in css),
        ('css mobile sheet', '.hx-modal { padding: 0; align-items: flex-end; }' in css),
        ('css reduced motion', 'prefers-reduced-motion: reduce' in css),
        # ایمیل
        ('mail test warns disabled toggle', 'ارسال اعلان‌ها فعال باشد' in mailc),
        ('notify logs disabled reason', 'notifications disabled in settings' in mails),
        # ادمین بدون تغییر دست‌نخورده (بدون رفرنس CDN)
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
        z.writestr(zipfile.ZipInfo('UPDATE-STAMP.txt', date_time=ZIP_MTIME),
                   f's19-ad-modal-email\nbuilt:{BUILD_TS}\n')
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
