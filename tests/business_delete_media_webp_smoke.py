#!/usr/bin/env python3
"""Automated verification for hamihub-update-business-delete-and-webp-media.zip."""
from __future__ import annotations

import hashlib
from pathlib import Path
import re
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'scripts'))
import build_business_delete_media_webp_update as builder  # noqa: E402

if not __debug__:
    raise RuntimeError('Run without Python optimization; assertions are required.')

SENTINELS = {
    'config/config.php': b'<?php /* live config sentinel: must never be overwritten */ return [];\n',
    'config/config.local.php': b'<?php /* local config sentinel */ return [];\n',
    'storage/private/ai-provider-master.key': b'sentinel-ai-master-key\n',
    'storage/private/advertising-payment-master.key': b'sentinel-payment-master-key\n',
    'storage/installed.lock': b'sentinel-installed-lock\n',
    'public_html/uploads/2026/11/img_existing_800.webp': b'sentinel-upload-bytes\n',
    'public_html/cache/existing-page.html': b'<!-- sentinel cached html -->\n',
}


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def parse_manifest(data: bytes) -> dict[str, str]:
    manifest: dict[str, str] = {}
    for line in data.decode('utf-8').splitlines():
        digest, path = line.split('  ', 1)
        assert re.fullmatch(r'[0-9a-f]{64}', digest) and path not in manifest, line
        manifest[path] = digest
    return manifest


def expect_verify_error(fn, substring: str) -> None:
    try:
        fn()
    except builder.RouteVerificationError as exc:
        assert substring in str(exc), f'Expected {substring!r} in {str(exc)!r}'
        return
    raise AssertionError(f'Expected RouteVerificationError containing {substring!r}')


def run_cli(*args: str, expected_failure: str | None = None) -> subprocess.CompletedProcess[str]:
    proc = subprocess.run(
        [sys.executable, str(ROOT / 'scripts/build_business_delete_media_webp_update.py'), *args],
        cwd=ROOT,
        text=True,
        capture_output=True,
    )
    if expected_failure is None:
        assert proc.returncode == 0, (proc.stdout, proc.stderr)
    else:
        assert proc.returncode != 0 and expected_failure in proc.stderr, (proc.stdout, proc.stderr)
    return proc


def main() -> None:
    published_dir = builder.DEFAULT_OUTPUT
    published_zip = published_dir / builder.ZIP_NAME
    assert published_zip.is_file(), f'Published archive missing: {published_zip}'

    with tempfile.TemporaryDirectory(prefix='hhx-biz-media-smoke-') as tmp:
        tmp_root = Path(tmp)
        host = tmp_root / 'host'
        host.mkdir()
        builder.reconstruct_current_host_baseline(host)

        for rel, content in SENTINELS.items():
            dest = host / rel
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(content)

        zip_entries = builder.safe_zip_entries(published_zip)
        for secret in SENTINELS.values():
            assert not any(secret in data for data in zip_entries.values()), 'Sentinel leaked into ZIP'

        assert set(zip_entries) == {'INSTALL-FA.md', 'MANIFEST-SHA256.txt', *builder.UPDATE_FILES}
        assert zip_entries['INSTALL-FA.md'] == (published_dir / builder.GUIDE_NAME).read_bytes()

        manifest = parse_manifest(zip_entries['MANIFEST-SHA256.txt'])
        assert set(manifest) == set(builder.UPDATE_FILES)
        for rel in builder.UPDATE_FILES:
            assert sha(zip_entries[rel]) == manifest[rel], f'Manifest SHA mismatch for {rel}'
            assert zip_entries[rel] == (ROOT / rel).read_bytes(), f'Archive differs from repo file {rel}'

        ext_sums = parse_manifest((published_dir / builder.SHA_NAME).read_bytes())
        assert set(ext_sums) == {builder.ZIP_NAME, builder.GUIDE_NAME}
        for name, digest in ext_sums.items():
            assert sha((published_dir / name).read_bytes()) == digest, f'SHA256SUMS mismatch for {name}'

        # Overlay onto simulated DirectAdmin host
        for name, content in zip_entries.items():
            if '/' not in name and name.startswith(builder.DOC_PREFIXES):
                continue
            dest = host / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(content)

        stats = builder.verify_tree(host, check_matches_repo=True)
        assert stats['api_routes'] == 89, stats
        assert stats['public_routes'] == 8, stats
        assert stats['runtime_files'] >= 104, stats

        for rel, content in SENTINELS.items():
            assert (host / rel).read_bytes() == content, f'Sentinel modified: {rel}'

        # Verify negative guard: non-WebP writer in MediaService.php is caught immediately
        media_svc = host / 'app/Services/MediaService.php'
        orig_media = media_svc.read_bytes()
        media_svc.write_text(
            orig_media.decode('utf-8') + '\n// test\nfunction _bad_test($im,$p){ imagejpeg($im,$p); }\n',
            encoding='utf-8',
        )
        expect_verify_error(
            lambda: builder.verify_tree(host, check_matches_repo=False),
            'non-WebP writer call: imagejpeg(',
        )
        media_svc.write_bytes(orig_media)

        # Verify deterministic rebuild
        rebuilt = tmp_root / 'rebuilt'
        run_cli('--output', str(rebuilt))
        assert builder.safe_zip_entries(rebuilt / builder.ZIP_NAME) == zip_entries
        assert (rebuilt / builder.SHA_NAME).read_bytes() == (published_dir / builder.SHA_NAME).read_bytes()

    print(
        f'PASS: 12-file DirectAdmin overlay verified ({stats["api_routes"]} API routes, '
        f'{stats["public_routes"]} public routes, {stats["static_calls"]} static calls, '
        f'{stats["runtime_files"]} runtime files matched repo); WebP-only & sentinel guards passed.'
    )


if __name__ == '__main__':
    main()
