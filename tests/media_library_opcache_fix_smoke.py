#!/usr/bin/env python3
"""Smoke & OPcache timestamp regression test for hamihub-update-media-library-opcache-fix.zip."""
from __future__ import annotations

from pathlib import Path
import sys
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'scripts'))
import build_media_library_opcache_fix as builder  # noqa: E402

if not __debug__:
    raise RuntimeError('Run without Python optimization; assertions are required.')


def main() -> None:
    published_zip = builder.DEFAULT_OUTPUT / builder.ZIP_NAME
    assert published_zip.is_file(), f'Missing update ZIP: {published_zip}'

    prev_zip = ROOT / 'release-files/business-delete-and-webp-media/hamihub-update-business-delete-and-webp-media.zip'
    with zipfile.ZipFile(prev_zip) as pzf:
        prev_mtime = pzf.getinfo('app/Controllers/MediaController.php').date_time

    with zipfile.ZipFile(published_zip) as zf:
        for info in zf.infolist():
            assert info.date_time > prev_mtime, (
                f'OPcache hazard: {info.filename} timestamp {info.date_time} is not newer than {prev_mtime}'
            )

    zip_entries = builder.safe_zip_entries(published_zip)
    assert set(zip_entries) == {'INSTALL-FA.md', 'MANIFEST-SHA256.txt', *builder.PACKAGE_FILES}
    assert 'public_html/api/media-library.php' in zip_entries
    assert 'app/Services/MediaLibraryService.php' in zip_entries

    for label, chain in (('pre-hotfix', builder.PRE_HOTFIX_CHAIN), ('post-hotfix', builder.POST_HOTFIX_CHAIN)):
        with tempfile.TemporaryDirectory(prefix=f'hhx-opcache-fix-{label}-') as tmp:
            host = Path(tmp) / 'host'
            host.mkdir()
            builder.reconstruct_chain(host, chain)
            for name, content in zip_entries.items():
                if '/' not in name and name.startswith(builder.DOC_PREFIXES):
                    continue
                dest = host / name
                dest.parent.mkdir(parents=True, exist_ok=True)
                dest.write_bytes(content)

            stats = builder.verify_tree(host, check_matches_repo=False)
            assert stats['api_routes'] == 89, stats
            assert stats['public_routes'] == 8, stats
            assert stats['types'] >= 65, stats

    print(
        f'PASS: OPcache-safe media update verified across pre/post-hotfix hosts '
        f'(ZIP mtime {builder.ARCHIVE_TIMESTAMP} > {prev_mtime}, {stats["types"]} verified classes).'
    )


if __name__ == '__main__':
    main()
