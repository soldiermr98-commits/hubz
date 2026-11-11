#!/usr/bin/env python3
"""Build the OPcache-safe DirectAdmin update for 2-up ad grids, new ad slots, and clean header/footer."""
from __future__ import annotations

import argparse
from pathlib import Path, PurePosixPath
import tempfile

from build_api_article_sync_update import (
    DOC_PREFIXES,
    FORBIDDEN_PREFIXES,
    sha,
)
from build_business_delete_media_webp_update import collect_repo_runtime_files
from build_media_library_opcache_fix import (
    POST_HOTFIX_CHAIN,
    PRE_HOTFIX_CHAIN,
    make_timestamped_archive,
    reconstruct_chain,
)
from build_media_tab_500_hotfix import verify_tree
from build_update_candidate import check_existing, publish_new_or_same

ROOT = Path(__file__).resolve().parent.parent
RELEASES = ROOT / 'release-files'
DEFAULT_OUTPUT = RELEASES / 'ad-slots-and-header-footer-update'
ZIP_NAME = 'hamihub-update-ad-slots-and-header-footer.zip'
GUIDE_NAME = 'RAHNAMA-FA.md'
SHA_NAME = 'SHA256SUMS.txt'
GUIDE_SOURCE = DEFAULT_OUTPUT / GUIDE_NAME

# Strictly newer than (2026, 11, 10, 15, 0, 0) used in media-library-opcache-fix
# so Zend OPcache st_mtime validation invalidates all updated PHP scripts immediately.
ARCHIVE_TIMESTAMP = (2026, 11, 10, 18, 0, 0)

POST_OPCACHE_FIX_CHAIN = (
    *POST_HOTFIX_CHAIN,
    'release-files/media-library-opcache-fix/hamihub-update-media-library-opcache-fix.zip',
)

PACKAGE_FILES = (
    'app/Controllers/AdvertisingController.php',
    'app/Controllers/BusinessController.php',
    'app/Controllers/MediaController.php',
    'app/Controllers/PublicSiteController.php',
    'app/Core/Cache.php',
    'app/Core/Request.php',
    'app/Core/Response.php',
    'app/Services/AdvertisingService.php',
    'app/Services/BusinessDataReviewService.php',
    'app/Services/MediaLibraryService.php',
    'app/Services/MediaService.php',
    'public_html/admin/assets/admin.css',
    'public_html/admin/assets/admin.js',
    'public_html/admin/index.html',
    'public_html/api/index.php',
    'public_html/api/media-library.php',
    'public_html/assets/css/public.css',
)


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

    # Verify clean overlay onto pre-hotfix, post-hotfix, AND post-opcache-fix DirectAdmin hosts
    for chain in (PRE_HOTFIX_CHAIN, POST_HOTFIX_CHAIN, POST_OPCACHE_FIX_CHAIN):
        with tempfile.TemporaryDirectory(prefix='hhx-ad-slots-verify-') as temp_dir:
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
        f'{stats["runtime_files"]} runtime files'
    )
    return archive_path


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=DEFAULT_OUTPUT)
    args = parser.parse_args()
    build(args.output)


if __name__ == '__main__':
    main()
