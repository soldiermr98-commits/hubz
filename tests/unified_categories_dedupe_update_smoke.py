#!/usr/bin/env python3
"""Smoke & OPcache timestamp test for hamihub-update-unified-categories-and-dedupe.zip."""
from __future__ import annotations

from pathlib import Path
import sys
import tempfile
import zipfile
import io
import subprocess
import tarfile

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'scripts'))
import build_unified_categories_dedupe_update as builder  # noqa: E402
from build_api_article_sync_update import DOC_PREFIXES, FORBIDDEN_PREFIXES, RUNTIME_TREES, safe_zip_entries  # noqa: E402

if not __debug__:
    raise RuntimeError('Run without Python optimization; assertions are required.')


def main() -> None:
    published_zip = builder.DEFAULT_OUTPUT / builder.ZIP_NAME
    assert published_zip.is_file(), f'Missing update ZIP: {published_zip}'

    prev_zip = ROOT / 'release-files/ad-slots-and-header-footer-update/hamihub-update-ad-slots-and-header-footer.zip'
    with zipfile.ZipFile(prev_zip) as pzf:
        prev_mtime = pzf.getinfo('app/Services/MediaLibraryService.php').date_time

    with zipfile.ZipFile(published_zip) as zf:
        for info in zf.infolist():
            assert info.date_time > prev_mtime, (
                f'OPcache hazard: {info.filename} timestamp {info.date_time} is not newer than {prev_mtime}'
            )

    zip_entries = safe_zip_entries(published_zip)
    assert set(zip_entries) == {'INSTALL-FA.md', 'MANIFEST-SHA256.txt', *builder.PACKAGE_FILES}

    sync_src = zip_entries['app/Services/CategorySyncService.php'].decode('utf-8')
    for canonical in ('کافه و رستوران', 'سالن زیبایی', 'کلینیک و دندانپزشکی', 'ویلا و اقامتگاه', 'جاذبه گردشگری'):
        assert canonical in sync_src, f'Missing canonical category in CategorySyncService.php: {canonical}'
    for tag in ('amenity=restaurant', 'amenity=fast_food', 'tourism=chalet', 'healthcare=clinic', 'leisure=park'):
        assert tag in sync_src, f'Missing OSM tag in CategorySyncService.php: {tag}'

    osm_src = zip_entries['collector-remote/Providers/OsmProvider.php'].decode('utf-8')
    assert 'healthcare|leisure|natural' in osm_src, 'OsmProvider.php must support healthcare|leisure|natural tag keys'

    rq_src = zip_entries['app/Controllers/ReviewQueueController.php'].decode('utf-8')
    assert 'merge_into_id' in rq_src, 'ReviewQueueController.php must support 1-click duplicate merge'

    # This is a historical release: compare the overlay to its published commit,
    # not to the current working tree, which legitimately changes in later releases.
    archive = subprocess.check_output(['git', 'archive', 'dd1e2cebbe44009a94a9fb05ff1c5209608d9429'], cwd=ROOT)
    with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
        pinned = {member.name: tar.extractfile(member).read() for member in tar.getmembers() if member.isfile()}
    runtime_paths = [name for name in pinned if any(name.startswith(tree + '/') for tree in RUNTIME_TREES)
                     and not name.startswith(FORBIDDEN_PREFIXES)]

    for label, chain in (
        ('pre-hotfix', builder.PRE_HOTFIX_CHAIN),
        ('post-hotfix', builder.POST_HOTFIX_CHAIN),
        ('post-opcache-fix', builder.POST_OPCACHE_FIX_CHAIN),
        ('post-ad-slots', builder.POST_AD_SLOTS_CHAIN),
    ):
        with tempfile.TemporaryDirectory(prefix=f'hhx-unified-cats-{label}-') as tmp:
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
            for rel in runtime_paths:
                assert rel in pinned, f'Missing runtime file from published commit: {rel}'
                assert (host / rel).read_bytes() == pinned[rel], f'Runtime differs from published commit: {rel}'
            stats['runtime_files'] = len(runtime_paths)
            assert stats['api_routes'] == 89, stats
            assert stats['public_routes'] == 8, stats
            assert stats['runtime_files'] >= 107, stats

    print(
        f'PASS: unified categories & dedupe update ZIP verified across all 4 host baselines '
        f'(ZIP mtime {builder.ARCHIVE_TIMESTAMP} > {prev_mtime}, {stats["runtime_files"]} runtime files).'
    )


if __name__ == '__main__':
    main()
