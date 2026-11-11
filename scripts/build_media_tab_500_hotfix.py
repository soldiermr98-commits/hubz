#!/usr/bin/env python3
"""Build the 3-file DirectAdmin hotfix for the Media tab HTTP 500 error.

Verifies static calls, routes, AND instance method calls on App\\Core\\Request
and App\\Core\\Router across the entire runtime tree.
"""
from __future__ import annotations

import argparse
from pathlib import Path, PurePosixPath
import re
import tempfile

from build_api_article_sync_update import (
    DOC_PREFIXES,
    FORBIDDEN_PREFIXES,
    RouteVerificationError,
    clean_php,
    make_archive,
    safe_zip_entries,
    sha,
)
from build_business_delete_media_webp_update import (
    DEPLOYED_ARCHIVE_CHAIN as PREV_CHAIN,
    collect_repo_runtime_files,
    verify_tree as verify_base_tree,
)
from build_update_candidate import check_existing, publish_new_or_same

ROOT = Path(__file__).resolve().parent.parent
RELEASES = ROOT / 'release-files'
DEFAULT_OUTPUT = RELEASES / 'media-tab-500-hotfix'
ZIP_NAME = 'hamihub-hotfix-media-tab-500.zip'
GUIDE_NAME = 'RAHNAMA-FA.md'
SHA_NAME = 'SHA256SUMS.txt'
GUIDE_SOURCE = DEFAULT_OUTPUT / GUIDE_NAME

DEPLOYED_ARCHIVE_CHAIN = (
    *PREV_CHAIN,
    'release-files/business-delete-and-webp-media/hamihub-update-business-delete-and-webp-media.zip',
)

HOTFIX_FILES = (
    'app/Controllers/MediaController.php',
    'app/Core/Request.php',
    'app/Services/MediaService.php',
)


def _match_brace(src: str, open_idx: int) -> int:
    depth = 0
    for idx in range(open_idx, len(src)):
        ch = src[idx]
        if ch == '{':
            depth += 1
        elif ch == '}':
            depth -= 1
            if depth == 0:
                return idx
    raise RouteVerificationError('Unbalanced braces in PHP source')


def index_public_instance_methods(stage: Path) -> dict[str, set[str]]:
    result: dict[str, set[str]] = {}
    for f in sorted((stage / 'app').rglob('*.php')):
        cleaned = clean_php(f.read_text(encoding='utf-8'))
        ns_m = re.search(r'\bnamespace\s+(App(?:\\[A-Za-z0-9_]+)*)\s*;', cleaned)
        if not ns_m:
            continue
        ns = ns_m.group(1)
        for cm in re.finditer(r'\bclass\s+([A-Za-z_][A-Za-z0-9_]*)[^{]*\{', cleaned):
            fqcn = f'{ns}\\{cm.group(1)}'
            body = cleaned[cm.end():_match_brace(cleaned, cm.end() - 1)]
            meths: set[str] = set()
            for mm in re.finditer(
                r'((?:(?:public|protected|private|static|final|abstract)\s+)*)function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(',
                body,
            ):
                mods = set(mm.group(1).split())
                if 'static' not in mods and ('public' in mods or not (mods & {'protected', 'private'})):
                    meths.add(mm.group(2))
            result[fqcn] = meths
    return result


def verify_core_instance_calls(stage: Path) -> int:
    inst = index_public_instance_methods(stage)
    req_methods = inst.get('App\\Core\\Request', set())
    router_methods = inst.get('App\\Core\\Router', set())
    checked = 0
    for sub in ('app', 'public_html', 'cron'):
        base_dir = stage / sub
        if not base_dir.is_dir():
            continue
        for php_file in sorted(base_dir.rglob('*.php')):
            rel = php_file.relative_to(stage).as_posix()
            cleaned = clean_php(php_file.read_text(encoding='utf-8'))
            for m in re.finditer(r'\$(req|request)->([A-Za-z_][A-Za-z0-9_]*)\s*\(', cleaned):
                meth = m.group(2)
                if meth not in req_methods:
                    line = cleaned[:m.start()].count('\n') + 1
                    raise RouteVerificationError(
                        f'{rel}:{line}: call to undefined method App\\Core\\Request::{meth}()'
                    )
                checked += 1
            for m in re.finditer(r'\$router->([A-Za-z_][A-Za-z0-9_]*)\s*\(', cleaned):
                meth = m.group(1)
                if meth not in router_methods:
                    line = cleaned[:m.start()].count('\n') + 1
                    raise RouteVerificationError(
                        f'{rel}:{line}: call to undefined method App\\Core\\Router::{meth}()'
                    )
                checked += 1
    return checked


def reconstruct_pre_hotfix_host(stage: Path) -> None:
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


def verify_tree(stage: Path, *, check_matches_repo: bool = True) -> dict[str, int]:
    stats = verify_base_tree(stage, check_matches_repo=check_matches_repo)
    stats['instance_calls'] = verify_core_instance_calls(stage)
    return stats


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
    for rel in HOTFIX_FILES:
        if rel.startswith('/') or '\\' in rel or '..' in PurePosixPath(rel).parts:
            raise ValueError(f'Unsafe runtime path: {rel}')
        if rel.startswith(FORBIDDEN_PREFIXES):
            raise ValueError(f'Forbidden path in hotfix package: {rel}')
        payload_files[rel] = (ROOT / rel).read_bytes()

    with tempfile.TemporaryDirectory(prefix='hhx-media-hotfix-verify-') as temp_dir:
        stage = Path(temp_dir)
        reconstruct_pre_hotfix_host(stage)
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
        help='output directory (default: release-files/media-tab-500-hotfix)',
    )
    args = parser.parse_args()
    build(args.output)
