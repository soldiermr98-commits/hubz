#!/usr/bin/env python3
"""Smoke & OPcache timestamp test for hamihub-update-ad-slots-and-header-footer.zip."""
from __future__ import annotations

from pathlib import Path
import sys
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'scripts'))
import build_ad_slots_header_footer_update as builder  # noqa: E402
from build_api_article_sync_update import DOC_PREFIXES, safe_zip_entries  # noqa: E402

if not __debug__:
    raise RuntimeError('Run without Python optimization; assertions are required.')


def main() -> None:
    published_zip = builder.DEFAULT_OUTPUT / builder.ZIP_NAME
    assert published_zip.is_file(), f'Missing update ZIP: {published_zip}'

    prev_zip = ROOT / 'release-files/media-library-opcache-fix/hamihub-update-media-library-opcache-fix.zip'
    with zipfile.ZipFile(prev_zip) as pzf:
        prev_mtime = pzf.getinfo('app/Services/MediaLibraryService.php').date_time

    with zipfile.ZipFile(published_zip) as zf:
        for info in zf.infolist():
            assert info.date_time > prev_mtime, (
                f'OPcache hazard: {info.filename} timestamp {info.date_time} is not newer than {prev_mtime}'
            )

    zip_entries = safe_zip_entries(published_zip)
    assert set(zip_entries) == {'INSTALL-FA.md', 'MANIFEST-SHA256.txt', *builder.PACKAGE_FILES}

    public_controller = zip_entries['app/Controllers/PublicSiteController.php'].decode('utf-8')
    for forbidden in ('href="/admin/"', 'hx-nav-admin', '>پنل مدیریت<', 'ورود مدیران', 'نقشه سایت (Sitemap)', 'Robots.txt'):
        assert forbidden not in public_controller, f'Forbidden public header/footer link remains: {forbidden}'
    for required in ('home_below_hero', 'home_mid', 'footer_above', 'public.css?v=s10.3'):
        assert required in public_controller, f'Missing expected marker in PublicSiteController.php: {required}'

    ad_service = zip_entries['app/Services/AdvertisingService.php'].decode('utf-8')
    for required in ('MAX_BANNERS_PER_SLOT = 2', 'hx-ad-grid-2', 'home_below_hero', 'home_mid', 'footer_above'):
        assert required in ad_service, f'Missing expected marker in AdvertisingService.php: {required}'

    for label, chain in (
        ('pre-hotfix', builder.PRE_HOTFIX_CHAIN),
        ('post-hotfix', builder.POST_HOTFIX_CHAIN),
        ('post-opcache-fix', builder.POST_OPCACHE_FIX_CHAIN),
    ):
        with tempfile.TemporaryDirectory(prefix=f'hhx-ad-slots-{label}-') as tmp:
            host = Path(tmp) / 'host'
            host.mkdir()
            builder.reconstruct_chain(host, chain)
            for name, content in zip_entries.items():
                if '/' not in name and name.startswith(DOC_PREFIXES):
                    continue
                dest = host / name
                dest.parent.mkdir(parents=True, exist_ok=True)
                dest.write_bytes(content)

            stats = builder.verify_tree(host, check_matches_repo=False)
            assert stats['api_routes'] == 89, stats
            assert stats['public_routes'] == 8, stats
            assert stats['types'] >= 65, stats

    print(
        f'PASS: ad-slots & header/footer update ZIP verified across all 3 host baselines '
        f'(ZIP mtime {builder.ARCHIVE_TIMESTAMP} > {prev_mtime}, {stats["types"]} verified classes).'
    )


if __name__ == '__main__':
    main()
