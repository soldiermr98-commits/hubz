#!/usr/bin/env python3
"""Build the OPcache-proof DirectAdmin update for the WebP Media Library tab.

Ensures a strictly newer ZIP entry timestamp than all prior releases so Zend
OPcache invalidates cached scripts immediately, while shipping a brand-new
physical endpoint (`public_html/api/media-library.php`) + service class
(`app/Services/MediaLibraryService.php`) + content-hashed `admin.js`.
"""
from __future__ import annotations

import argparse
from pathlib import Path, PurePosixPath
import tempfile
import zipfile

from build_api_article_sync_update import (
    DOC_PREFIXES,
    FORBIDDEN_PREFIXES,
    safe_zip_entries,
    sha,
)
from build_business_delete_media_webp_update import collect_repo_runtime_files
from build_media_tab_500_hotfix import (
    DEPLOYED_ARCHIVE_CHAIN as PRE_HOTFIX_CHAIN,
    verify_tree,
)
from build_update_candidate import check_existing, publish_new_or_same

ROOT = Path(__file__).resolve().parent.parent
RELEASES = ROOT / 'release-files'
DEFAULT_OUTPUT = RELEASES / 'media-library-opcache-fix'
ZIP_NAME = 'hamihub-update-media-library-opcache-fix.zip'
GUIDE_NAME = 'RAHNAMA-FA.md'
SHA_NAME = 'SHA256SUMS.txt'
GUIDE_SOURCE = DEFAULT_OUTPUT / GUIDE_NAME

# Strictly newer than (2026, 11, 9, 0, 0, 0) used in prior archives so Zend OPcache
# st_mtime comparison always detects updated files upon unzip.
ARCHIVE_TIMESTAMP = (2026, 11, 10, 15, 0, 0)

POST_HOTFIX_CHAIN = (
    *PRE_HOTFIX_CHAIN,
    'release-files/media-tab-500-hotfix/hamihub-hotfix-media-tab-500.zip',
)

PACKAGE_FILES = (
    'app/Controllers/MediaController.php',
    'app/Core/Request.php',
    'app/Core/Response.php',
    'app/Services/MediaLibraryService.php',
    'app/Services/MediaService.php',
    'public_html/admin/assets/admin.css',
    'public_html/admin/assets/admin.js',
    'public_html/admin/index.html',
    'public_html/api/index.php',
    'public_html/api/media-library.php',
)


def make_timestamped_archive(files: dict[str, bytes], date_time: tuple[int, int, int, int, int, int]) -> bytes:
    with tempfile.TemporaryFile() as temp:
        with zipfile.ZipFile(temp, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
            for name, content in sorted(files.items()):
                info = zipfile.ZipInfo(name, date_time)
                info.create_system = 3
                info.external_attr = 0o100644 << 16
                info.compress_type = zipfile.ZIP_DEFLATED
                zf.writestr(info, content, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
        temp.seek(0)
        return temp.read()


def reconstruct_chain(stage: Path, chain: tuple[str, ...]) -> None:
    for rel_zip in chain:
        zip_path = ROOT / rel_zip
        if not zip_path.is_file():
            raise FileNotFoundError(f'Missing baseline release archive: {rel_zip}')
        for name, content in safe_zip_entries(zip_path).items():
            if '/' not in name and name.startswith(DOC_PREFIXES):
                continue
            dest = stage / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(content)


def safe_output(path: Path) -> Path:
    path = path.expanduser().absolute()
    if any(part.is_symlink() for part in (path, *path.parents)):
        raise ValueError('Refusing to write through a symlinked output directory.')
    resolved = path.resolve()
    releases, allowed = RELEASES.resolve(), DEFAULT_OUTPUT.resolve()
    if resolved == ROOT or resolved == releases:
        raise ValueError('Refusing to write at the repository or release root.')
    if releases in resolved.parents and resolved != allowed and allowed not in resolved.parents:
        raise ValueError('Refusing to write inside another published release.')
    return resolved


def build(output: Path) -> Path:
    output = safe_output(output)
    if not GUIDE_SOURCE.is_file():
        raise FileNotFoundError(f'Missing guide file: {GUIDE_SOURCE}')
    guide_bytes = GUIDE_SOURCE.read_bytes()

    payload_files: dict[str, bytes] = {}
    for rel in PACKAGE_FILES:
        if rel.startswith('/') or '\\' in rel or '..' in PurePosixPath(rel).parts:
            raise ValueError(f'Unsafe runtime path: {rel}')
        if rel.startswith(FORBIDDEN_PREFIXES):
            raise ValueError(f'Forbidden path in update package: {rel}')
        payload_files[rel] = (ROOT / rel).read_bytes()

    # Verify clean overlay onto BOTH pre-hotfix and post-hotfix DirectAdmin states
    for chain in (PRE_HOTFIX_CHAIN, POST_HOTFIX_CHAIN):
        with tempfile.TemporaryDirectory(prefix='hhx-media-opcache-verify-') as temp_dir:
            stage = Path(temp_dir)
            reconstruct_chain(stage, chain)
            for rel, content in payload_files.items():
                dest = stage / rel
                dest.parent.mkdir(parents=True, exist_ok=True)
                dest.write_bytes(content)
            stats = verify_tree(stage, check_matches_repo=True)
            assert collect_repo_runtime_files()

    manifest_bytes = ''.join(f'{sha(data)}  {path}\n' for path, data in sorted(payload_files.items())).encode()
    zip_entries = {
        'INSTALL-FA.md': guide_bytes,
        'MANIFEST-SHA256.txt': manifest_bytes,
        **payload_files,
    }
    zip_bytes = make_timestamped_archive(zip_entries, ARCHIVE_TIMESTAMP)
    products = {
        ZIP_NAME: zip_bytes,
        GUIDE_NAME: guide_bytes,
    }
    products[SHA_NAME] = ''.join(f'{sha(data)}  {name}\n' for name, data in products.items()).encode()

    output.mkdir(parents=True, exist_ok=True)
    for name, data in products.items():
        check_existing(output / name, data)
    for name, data in products.items():
        publish_new_or_same(output / name, data)

    archive_path = output / ZIP_NAME
    print(
        f'{archive_path}\n'
        f'SHA-256 {sha(zip_bytes)}\n'
        f'ZIP timestamp: {ARCHIVE_TIMESTAMP}\n'
        f'Verified: {stats["api_routes"]} API routes, {stats["public_routes"]} public routes, '
        f'{stats["static_calls"]} static calls, {stats["instance_calls"]} core instance calls, '
        f'{stats["runtime_files"]} runtime files matched repo'
    )
    return archive_path


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        '--output',
        type=Path,
        default=DEFAULT_OUTPUT,
        help='output directory (default: release-files/media-library-opcache-fix)',
    )
    args = parser.parse_args()
    build(args.output)
