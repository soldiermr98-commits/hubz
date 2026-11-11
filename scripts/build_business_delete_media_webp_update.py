#!/usr/bin/env python3
"""Build the 12-file DirectAdmin update package for SEO-safe business deletion & WebP media management.

Reconstructs the exact current DirectAdmin host baseline (after PR #34's
api-and-article-tags-sync update), overlays the 12 updated/new files, and verifies:
  1. All 89 routes in public_html/api/index.php resolve to existing PSR-4
     controller files with public static handler methods.
  2. All 8 routes in public_html/index.php resolve to existing PSR-4
     controller files with public static handler methods.
  3. All App\\ class imports, cross-class static method calls, and Migrator SQL
     file references (including 012_seo_url_rules.sql) across app/, public_html/,
     and cron/ are valid.
  4. Strict 100% WebP output enforcement in MediaService.php (no imagejpeg,
     imagepng, imagegif, imagebmp, imageavif, or move_uploaded_file calls).
  5. Admin SPA index.html asset cache-busting hashes match admin.js & admin.css.
  6. All tracked/working runtime files across app/, database/, public_html/, and
     cron/ match the repository runtime tree byte-for-byte after applying the overlay.
"""
from __future__ import annotations

import argparse
from pathlib import Path, PurePosixPath
import re
import tempfile

from build_api_article_sync_update import (
    DOC_PREFIXES,
    DEPLOYED_ARCHIVE_CHAIN as PREVIOUS_CHAIN,
    FORBIDDEN_PREFIXES,
    RUNTIME_TREES,
    RouteVerificationError,
    clean_php,
    index_php_types,
    make_archive,
    parse_use_aliases,
    safe_zip_entries,
    sha,
    strip_php_comments,
    verify_routes_file,
)
from build_update_candidate import check_existing, publish_new_or_same

ROOT = Path(__file__).resolve().parent.parent
RELEASES = ROOT / 'release-files'
DEFAULT_OUTPUT = RELEASES / 'business-delete-and-webp-media'
ZIP_NAME = 'hamihub-update-business-delete-and-webp-media.zip'
GUIDE_NAME = 'RAHNAMA-FA.md'
SHA_NAME = 'SHA256SUMS.txt'
GUIDE_SOURCE = DEFAULT_OUTPUT / GUIDE_NAME

EXPECTED_API_ROUTE_COUNT = 89
EXPECTED_PUBLIC_ROUTE_COUNT = 8

DEPLOYED_ARCHIVE_CHAIN = (
    *PREVIOUS_CHAIN,
    'release-files/api-and-article-tags-sync/hamihub-update-api-and-article-tags-sync.zip',
)

UPDATE_FILES = (
    'app/Controllers/AuthController.php',
    'app/Controllers/BusinessController.php',
    'app/Controllers/MediaController.php',
    'app/Controllers/PublicSiteController.php',
    'app/Core/Migrator.php',
    'app/Services/BusinessSeoService.php',
    'app/Services/MediaService.php',
    'database/migrations/012_seo_url_rules.sql',
    'public_html/admin/assets/admin.css',
    'public_html/admin/assets/admin.js',
    'public_html/admin/index.html',
    'public_html/api/index.php',
)

FORBIDDEN_IMAGE_WRITERS_RE = re.compile(
    r'\b(?:imagejpeg|imagepng|imagegif|imagebmp|imageavif|imagewbmp|move_uploaded_file)\s*\('
)


def reconstruct_current_host_baseline(stage: Path) -> None:
    """Extract the cumulative archive chain currently installed on the DirectAdmin host."""
    for rel_zip in DEPLOYED_ARCHIVE_CHAIN:
        zip_path = ROOT / rel_zip
        if not zip_path.is_file():
            raise FileNotFoundError(f'Missing baseline release archive: {rel_zip}')
        for name, content in safe_zip_entries(zip_path).items():
            if '/' not in name and name.startswith(DOC_PREFIXES):
                continue
            dest = stage / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(content)


def collect_repo_runtime_files() -> dict[str, bytes]:
    """Collect all runtime files under app/, database/, public_html/, and cron/ in the repo."""
    files: dict[str, bytes] = {}
    for tree in RUNTIME_TREES:
        base = ROOT / tree
        if not base.is_dir():
            continue
        for f in sorted(base.rglob('*')):
            if not f.is_file():
                continue
            rel = f.relative_to(ROOT).as_posix()
            if rel.startswith(FORBIDDEN_PREFIXES):
                continue
            files[rel] = f.read_bytes()
    return files


def verify_static_calls_migrations_and_webp(
    stage: Path,
    types: dict[str, dict[str, object]] | None = None,
) -> int:
    if types is None:
        types = index_php_types(stage)

    checked_calls = 0
    for sub in ('app', 'public_html', 'cron'):
        base_dir = stage / sub
        if not base_dir.is_dir():
            continue
        for php_file in sorted(base_dir.rglob('*.php')):
            rel = php_file.relative_to(stage).as_posix()
            if rel in ('public_html/check.php', 'public_html/install.php'):
                continue
            raw = php_file.read_text(encoding='utf-8')
            cleaned = clean_php(raw)
            ns_match = re.search(r'\bnamespace\s+(App(?:\\[A-Za-z0-9_]+)*)\s*;', cleaned)
            cur_ns = ns_match.group(1) if ns_match else ''
            aliases = parse_use_aliases(cleaned)
            for alias, fqcn in aliases.items():
                if fqcn not in types:
                    raise RouteVerificationError(f'{rel}: imported missing type {fqcn} (as {alias})')
            for cm in re.finditer(r'(?<!\\)\b([A-Z][A-Za-z0-9_]*)::([a-zA-Z_][A-Za-z0-9_]*)\s*\(', cleaned):
                cls_ref, meth = cm.group(1), cm.group(2)
                target = aliases.get(cls_ref) or (
                    f'{cur_ns}\\{cls_ref}' if cur_ns and f'{cur_ns}\\{cls_ref}' in types else None
                )
                if target:
                    pub_static = types[target]['public_static']
                    assert isinstance(pub_static, set)
                    if meth not in pub_static:
                        raise RouteVerificationError(
                            f'{rel}: static call to missing/non-public {target}::{meth}()'
                        )
                    checked_calls += 1

    migrator_path = stage / 'app/Core/Migrator.php'
    if not migrator_path.is_file():
        raise RouteVerificationError('Missing app/Core/Migrator.php')
    migrator_uncommented = strip_php_comments(migrator_path.read_text(encoding='utf-8'))
    sql_refs = set(re.findall(r'\x27([0-9]{3}_[a-z0-9_]+\.sql)\x27', migrator_uncommented))
    for required_sql in ('011_article_topic_tags.sql', '012_seo_url_rules.sql'):
        if required_sql not in sql_refs:
            raise RouteVerificationError(f'Migrator.php does not reference {required_sql}')
    for sql_name in sorted(sql_refs):
        sql_path = stage / 'database/migrations' / sql_name
        if not sql_path.is_file() or sql_path.read_text(encoding='utf-8').strip() == '':
            raise RouteVerificationError(
                f'Migrator.php references missing or empty SQL file: database/migrations/{sql_name}'
            )

    # Verify strict 100% WebP-only policy in MediaService.php
    media_svc = stage / 'app/Services/MediaService.php'
    if not media_svc.is_file():
        raise RouteVerificationError('Missing app/Services/MediaService.php')
    media_cleaned = clean_php(media_svc.read_text(encoding='utf-8'))
    forbidden_match = FORBIDDEN_IMAGE_WRITERS_RE.search(media_cleaned)
    if forbidden_match:
        raise RouteVerificationError(
            f'MediaService.php contains non-WebP writer call: {forbidden_match.group(0)}'
        )
    if 'imagewebp(' not in media_cleaned:
        raise RouteVerificationError('MediaService.php must write images via imagewebp()')

    # Verify admin index.html cache-busting hashes match admin.js and admin.css
    admin_html = (stage / 'public_html/admin/index.html').read_text(encoding='utf-8')
    for asset_name in ('admin.js', 'admin.css'):
        asset_bytes = (stage / 'public_html/admin/assets' / asset_name).read_bytes()
        expected_tag = f'/admin/assets/{asset_name}?v={sha(asset_bytes)[:12]}'
        if expected_tag not in admin_html:
            raise RouteVerificationError(
                f'public_html/admin/index.html missing content-hashed reference {expected_tag}'
            )

    return checked_calls


def verify_tree(stage: Path, *, check_matches_repo: bool = True) -> dict[str, int]:
    types = index_php_types(stage)
    api_routes = verify_routes_file(
        stage,
        'public_html/api/index.php',
        expected_count=EXPECTED_API_ROUTE_COUNT,
        types=types,
    )
    public_routes = verify_routes_file(
        stage,
        'public_html/index.php',
        expected_count=EXPECTED_PUBLIC_ROUTE_COUNT,
        types=types,
    )
    static_calls = verify_static_calls_migrations_and_webp(stage, types=types)

    runtime_count = 0
    if check_matches_repo:
        repo_runtime = collect_repo_runtime_files()
        for rel, expected_bytes in sorted(repo_runtime.items()):
            staged_file = stage / rel
            if not staged_file.is_file():
                raise RouteVerificationError(f'Missing runtime file after overlay: {rel}')
            if staged_file.read_bytes() != expected_bytes:
                raise RouteVerificationError(f'Runtime file diverges from repo after overlay: {rel}')
        runtime_count = len(repo_runtime)

    return {
        'types': len(types),
        'api_routes': len(api_routes),
        'public_routes': len(public_routes),
        'static_calls': static_calls,
        'runtime_files': runtime_count,
    }


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
    for rel in UPDATE_FILES:
        if rel.startswith('/') or '\\' in rel or '..' in PurePosixPath(rel).parts:
            raise ValueError(f'Unsafe runtime path: {rel}')
        if rel.startswith(FORBIDDEN_PREFIXES):
            raise ValueError(f'Forbidden path in update package: {rel}')
        src_path = ROOT / rel
        if not src_path.is_file():
            raise FileNotFoundError(f'Missing payload file: {rel}')
        payload_files[rel] = src_path.read_bytes()

    with tempfile.TemporaryDirectory(prefix='hhx-biz-media-verify-') as temp_dir:
        stage = Path(temp_dir)
        reconstruct_current_host_baseline(stage)
        for rel, content in payload_files.items():
            dest = stage / rel
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(content)
        stats = verify_tree(stage, check_matches_repo=True)

    manifest_bytes = ''.join(f'{sha(data)}  {path}\n' for path, data in sorted(payload_files.items())).encode()
    zip_entries = {
        'INSTALL-FA.md': guide_bytes,
        'MANIFEST-SHA256.txt': manifest_bytes,
        **payload_files,
    }
    zip_bytes = make_archive(zip_entries)
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
        f'Pre-build verified: {stats["api_routes"]} API routes, {stats["public_routes"]} public routes, '
        f'{stats["static_calls"]} static calls, {stats["runtime_files"]} runtime files matched repo'
    )
    return archive_path


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        '--output',
        type=Path,
        default=DEFAULT_OUTPUT,
        help='output directory (default: release-files/business-delete-and-webp-media)',
    )
    args = parser.parse_args()
    build(args.output)
