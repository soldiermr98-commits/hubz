#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
بسته‌ساز مرحله S20 — فرم دیدگاه درون‌صفحه‌ای (بدون تب جدید) + اصلاح ویجت ستاره‌ها
+ حذف کامل رکوردها از دیتابیس (حذف تراکنشی مقاله + پاکسازی دیدگاه‌های کسب‌وکار حذف‌شده)
+ لینک واقعی /advertise در فوتر و ثبت آن در سایت‌مپ + یکدستی اینپوت‌های مودال تبلیغات.
خروجی: ZIP افزایشی + SHA256SUMS + RAHNAMA-FA.md در release-files/s20-inline-comments-hard-delete/
بررسی‌ها: مسیر امن، موجودی فایل، توازن براکت PHP، نبود ارجاع بیرونی، هم‌خوانی نسخه s20.0،
نداشتن «تب جدید»، فرم درون‌صفحه‌ای + comment-question، ستاره‌ها با data-star-value،
اندپوینت articles/delete + deleteArticle تراکنشی، پاکسازی business_comments، باندل ادمین نو.
"""
from __future__ import annotations

import hashlib
import io
import sys
import zipfile
from datetime import datetime, timezone
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parent.parent
OUTPUT = ROOT / 'release-files/s20-inline-comments-hard-delete'
ZIP_NAME = 'hamihub-update-s20-inline-comments-hard-delete.zip'

BUILD_TS = datetime.now(timezone.utc).strftime('%Y%m%d%H%M%S')
ZIP_MTIME = (2026, 11, 12, 10, 0, 0)

FILES = (
    'app/Controllers/PublicSiteController.php',
    'app/Controllers/BusinessController.php',
    'app/Controllers/ArticleController.php',
    'app/Services/ArticleService.php',
    'app/Core/Cache.php',
    'public_html/api/index.php',
    'public_html/assets/js/public.js',
    'public_html/assets/css/public.css',
    'public_html/admin/assets/admin.js',
    'public_html/admin/assets/admin.css',
    'public_html/admin/index.html',
    'src/services/adminApi.ts',
    'src/components/ArticlesManagerView.tsx',
    'tests/s20_inline_comments_hard_delete_smoke.mjs',
    'tests/s19_ad_modal_smoke.mjs',
    'tests/s17_map_navigation_smoke.mjs',
    'scripts/build_s20_inline_comments_hard_delete.py',
)

FORBIDDEN_PREFIXES = ('/', '\\', '..')
PHP_OPEN_TAG_REQUIRED = (
    'app/Controllers/PublicSiteController.php',
    'app/Controllers/BusinessController.php',
    'app/Controllers/ArticleController.php',
    'app/Services/ArticleService.php',
    'app/Core/Cache.php',
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
    biz = payloads['app/Controllers/BusinessController.php'].decode('utf-8')
    art = payloads['app/Controllers/ArticleController.php'].decode('utf-8')
    svc = payloads['app/Services/ArticleService.php'].decode('utf-8')
    cache = payloads['app/Core/Cache.php'].decode('utf-8')
    api = payloads['public_html/api/index.php'].decode('utf-8')
    js = payloads['public_html/assets/js/public.js'].decode('utf-8')
    css = payloads['public_html/assets/css/public.css'].decode('utf-8')
    adminjs = payloads['public_html/admin/assets/admin.js'].decode('utf-8', errors='replace')
    view = payloads['src/components/ArticlesManagerView.tsx'].decode('utf-8')

    checks = [
        # نسخه‌ها
        ('controller references s20.0 css', 'public.css?v=s20.0' in psc),
        ('controller references s20.0 js', 'public.js?v=s20.0' in psc),
        ('no stale s19.0 in controller', 's19.0' not in psc),
        ('cache accepts s20.0', 'public.css?v=s20.0' in cache),
        ('public.js loads leaflet s20', 'leaflet.js?v=s20' in js and 'leaflet.css?v=s20' in js),
        # فرم دیدگاه درون‌صفحه‌ای — بدون تب جدید
        ('no new-tab hero button', 'ثبت دیدگاه شما (تب جدید)' not in psc),
        ('no new-tab reviews button', 'ثبت دیدگاه (تب جدید)' not in psc),
        ('inline card rendered', 'id="hx-inline-comment"' in psc),
        ('inline form marker', 'data-hx-inline-comment-form' in psc),
        ('open-form buttons', 'data-hx-open-comment-form' in psc),
        ('commentQuestion endpoint', 'public static function commentQuestion(' in psc),
        ('noscript fallback to /comment/', 'رفتن به فرم ثبت دیدگاه' in psc),
        ('standalone page kept', 'public static function commentPage(' in psc),
        ('api route comment-question', "PublicSiteController::class, 'commentQuestion'" in api),
        ('js fetches comment question', "fetch('/api/v1/public/comment-question'" in js),
        ('js handles refresh-question', 'hx:comment-refresh-question' in js),
        ('css inline card', '.hx-inline-comment' in css),
        # ستاره‌ها
        ('star widget helper', 'private static function starWidget(' in psc),
        ('star explicit values', 'data-star-value=' in psc),
        ('js star init', 'initStarWidgets' in js),
        ('js is-on/is-hover', "'is-on'" in js and "'is-hover'" in js),
        ('css is-on/is-hover', '.hx-star.is-on .hx-icon' in css and '.hx-star.is-hover .hx-icon' in css),
        ('css star value feedback', '.hx-star-value' in css),
        # حذف کامل رکوردها
        ('article destroy endpoint', 'public static function destroy(' in art),
        ('article destroy admin only', "Auth::requireRole(['admin'])" in art),
        ('article destroy csrf', 'Csrf::verifyOrFail($req)' in art),
        ('article destroy cache flush', 'Cache::flushAll()' in art),
        ('article service hard delete', 'public static function deleteArticle(' in svc),
        ('article service deletes items', 'DELETE FROM article_items WHERE article_id=:id' in svc),
        ('article service deletes faqs', 'DELETE FROM article_faqs WHERE article_id=:id' in svc),
        ('article service deletes tag links', 'DELETE FROM article_tag_links WHERE article_id=:id' in svc),
        ('article service deletes revisions', 'DELETE FROM article_revisions WHERE article_id=:id' in svc),
        ('article service deletes extras', 'DELETE FROM article_extras WHERE article_id=:id' in svc),
        ('article service deletes aliases', 'DELETE FROM article_url_aliases WHERE article_id=:id' in svc),
        ('article service deletes review queue', "DELETE FROM review_queue WHERE kind='article' AND target_id=:id" in svc),
        ('article service deletes events', 'DELETE FROM events_daily WHERE article_id=:id' in svc),
        ('article service deletes article row', 'DELETE FROM articles WHERE id=:id' in svc),
        ('api route articles/delete', "'/api/v1/articles/delete', [ArticleController::class, 'destroy']" in api),
        ('business delete cleans comments', 'DELETE FROM business_comments WHERE business_id IN' in biz),
        ('business delete still hard', 'DELETE FROM businesses WHERE id IN' in biz),
        ('panel uses delete api', 'deleteArticleItem' in view),
        ('panel confirm is explicit', 'برای همیشه از دیتابیس حذف شود؟' in view),
        ('admin bundle contains delete api', '/api/v1/articles/delete' in adminjs),
        # فوتر/سایت‌مپ/مودال
        ('footer real link to /advertise', '<a href="/advertise">درخواست تبلیغات</a>' in psc),
        ('advertise in sitemap', "['loc' => $baseUrl . '/advertise', 'priority' => '0.6', 'changefreq' => 'monthly']" in psc),
        ('ad modal kept', 'id="hx-ad-modal"' in psc),
        ('ad modal cta kept', 'data-hx-ad-modal' in psc),
        ('ad inputs unified', 'box-sizing: border-box' in css and '.hx-ad-form textarea { resize: vertical; min-height: 6rem; }' in css),
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
                   f's20-inline-comments-hard-delete\nbuilt:{BUILD_TS}\n')
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
