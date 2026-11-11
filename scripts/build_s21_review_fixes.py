#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
بسته‌ساز مرحله S21 — رفع گزارش‌های بازبینی:
۱) مودال درخواست تبلیغات: حذف اسکرول افقی RTL (تکنیک clip برای honeypot) + اینپوت‌های هم‌اندازه
۲) آمار: سازگاری ONLY_FULL_GROUP_BY + داشبورد مقاوم + اندپوینت «بررسی دیتابیس» + نمایش جزئیات خطا
۳) دیدگاه‌ها: خروج از صندوق بازبینی (فقط تب دیدگاه‌ها) + مایگریشن ۰۲۱ برای پاکسازی ردیف‌های قدیمی
۴) اعلان ایمیل: نمایش آخرین نتیجهٔ اعلان خودکار در پنل
خروجی: ZIP افزایشی + SHA256SUMS + RAHNAMA-FA.md در release-files/s21-review-fixes/
"""
from __future__ import annotations

import hashlib
import io
import sys
import zipfile
from datetime import datetime, timezone
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parent.parent
OUTPUT = ROOT / 'release-files/s21-review-fixes'
ZIP_NAME = 'hamihub-update-s21-review-fixes.zip'

BUILD_TS = datetime.now(timezone.utc).strftime('%Y%m%d%H%M%S')
ZIP_MTIME = (2026, 11, 12, 10, 0, 0)

FILES = (
    'app/Controllers/AnalyticsController.php',
    'app/Controllers/PublicSiteController.php',
    'app/Core/Cache.php',
    'app/Services/AdminMailService.php',
    'app/Services/AnalyticsService.php',
    'app/Services/CommentService.php',
    'database/migrations/021_comments_out_of_review_queue.sql',
    'public_html/api/index.php',
    'public_html/assets/js/public.js',
    'public_html/assets/css/public.css',
    'public_html/admin/assets/admin.js',
    'public_html/admin/assets/admin.css',
    'public_html/admin/index.html',
    'src/services/adminApi.ts',
    'src/components/StatsView.tsx',
    'src/components/AdminMailPanel.tsx',
    'tests/s21_review_fixes_smoke.mjs',
    'tests/s20_inline_comments_hard_delete_smoke.mjs',
    'tests/s19_ad_modal_smoke.mjs',
    'tests/s17_map_navigation_smoke.mjs',
    'scripts/build_s21_review_fixes.py',
)

FORBIDDEN_PREFIXES = ('/', '\\', '..')
PHP_OPEN_TAG_REQUIRED = (
    'app/Controllers/AnalyticsController.php',
    'app/Controllers/PublicSiteController.php',
    'app/Core/Cache.php',
    'app/Services/AdminMailService.php',
    'app/Services/AnalyticsService.php',
    'app/Services/CommentService.php',
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
        if rel.endswith(('.css', '.js')) and rel.startswith('public_html/assets/'):
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
    cache = payloads['app/Core/Cache.php'].decode('utf-8')
    ansvc = payloads['app/Services/AnalyticsService.php'].decode('utf-8')
    anctl = payloads['app/Controllers/AnalyticsController.php'].decode('utf-8')
    csvc = payloads['app/Services/CommentService.php'].decode('utf-8')
    mails = payloads['app/Services/AdminMailService.php'].decode('utf-8')
    api = payloads['public_html/api/index.php'].decode('utf-8')
    js = payloads['public_html/assets/js/public.js'].decode('utf-8')
    css = payloads['public_html/assets/css/public.css'].decode('utf-8')
    adminjs = payloads['public_html/admin/assets/admin.js'].decode('utf-8', errors='replace')
    statsview = payloads['src/components/StatsView.tsx'].decode('utf-8')
    mailpanel = payloads['src/components/AdminMailPanel.tsx'].decode('utf-8')
    adminapi = payloads['src/services/adminApi.ts'].decode('utf-8')
    mig = payloads['database/migrations/021_comments_out_of_review_queue.sql'].decode('utf-8')

    checks = [
        # نسخه‌ها
        ('controller references s21.0 css', 'public.css?v=s21.0' in psc),
        ('controller references s21.0 js', 'public.js?v=s21.0' in psc),
        ('no stale s20.0 in controller', 's20.0' not in psc),
        ('cache accepts s21.0', 'public.css?v=s21.0' in cache),
        ('public.js loads leaflet s21', 'leaflet.js?v=s21' in js and 'leaflet.css?v=s21' in js),
        # مودال: اسکرول افقی و اندازهٔ اینپوت
        ('no offscreen honeypot', 'left: -10000px' not in css and 'left:-10000px' not in css),
        ('honeypot clip rect', 'clip: rect(0, 0, 0, 0)' in css),
        ('honeypot clip-path', 'clip-path: inset(50%)' in css),
        ('modal panel overflow-x hidden', 'overflow-y: auto; overflow-x: hidden' in css),
        ('modal panel overscroll contain', 'overscroll-behavior: contain' in css),
        ('ad grid minmax', 'grid-template-columns: repeat(2, minmax(0, 1fr))' in css),
        ('ad input selector type-agnostic', '.hx-ad-form input:not([type="checkbox"])' in css),
        ('name input explicit type', '<input type="text" name="name"' in psc),
        # آمار
        ('topTargets group-by safe', 'GROUP BY d.target_id, {$titleColumn}' in ansvc),
        ('joinFor returns group column', "'b.name']" in ansvc),
        ('dashboard missing-table guard', "'analytics_daily'" in ansvc and 'DB::tableExists' in ansvc),
        ('diagnose service method', 'public static function diagnose(' in ansvc),
        ('diagnose reports sql_mode', 'SELECT @@sql_mode AS m' in ansvc),
        ('dashboard warnings', 'warnings' in ansvc),
        ('no static-this bug', '$this->zeroTotals' not in ansvc),
        ('analytics diagnose endpoint', 'public static function diagnose(' in anctl),
        ('analytics detail field', "'detail' => 'خطای سرور: '" in anctl),
        ('api route diagnose', "'/api/v1/analytics/diagnose', [AnalyticsController::class, 'diagnose']" in api),
        ('adminApi fetch diagnose', 'fetchAnalyticsDiagnose' in adminapi),
        ('adminApi error detail passthrough', 'err.detail = data.detail' in adminapi),
        ('stats view db check panel', 'DbCheckPanel' in statsview),
        ('stats view run migrations', 'runWebMigrations' in statsview),
        ('admin bundle has db check', 'بررسی دیتابیس' in adminjs),
        # دیدگاه‌ها: خروج از صندوق بازبینی
        ('comment submit no review_queue', 'INSERT INTO review_queue' not in csvc),
        ('comment moderate no review_queue', 'UPDATE review_queue' not in csvc),
        ('comment hard delete kept', 'DELETE FROM business_comments WHERE id = :id' in csvc),
        ('comment table insert kept', 'INSERT INTO business_comments' in csvc),
        ('migration 021 purges comments', "DELETE FROM `review_queue` WHERE `kind` = 'comment';" in mig),
        ('js sending text updated', 'در حال ارسال به صندوق بازبینی' not in js),
        ('js per-form sending text', 'در حال ارسال دیدگاه…' in js and 'در حال ارسال درخواست…' in js),
        # اعلان ایمیل
        ('notify state key', 'NOTIFY_STATE_KEY' in mails),
        ('notify skipped recorded', "rememberNotify($event, 'skipped'" in mails),
        ('notify result recorded', "$sent ? 'sent' : 'failed'" in mails),
        ('status exposes notify_state', "'notify_state'=>self::notifyState()" in mails),
        ('mail panel shows last notify', 'آخرین اعلان خودکار' in mailpanel),
        ('admin bundle has last notify', 'آخرین اعلان خودکار' in adminjs),
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
                   f's21-review-fixes\nbuilt:{BUILD_TS}\n')
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
