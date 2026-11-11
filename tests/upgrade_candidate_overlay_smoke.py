#!/usr/bin/env python3
"""Offline overlay test: historical install archives -> .5 -> staging candidate.

Only creates files in a new temporary directory. --stage can preserve the
synthetic installation for additional guarded local database/HTTP tests.
"""
from __future__ import annotations

import argparse
import hashlib
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parent.parent
BASE = '35476933f98ebd2d5d442ae4a8eb54dc7e32e5b0'
TARGET = '543b0d6eeed8e51b1986268a43bee12cb564cc67'
TARGET_RC2 = '17be5bb6d0f5d75c7d2aee159ac00b573f101ef9'
ARCHIVES = (
    'release-files/v2026.11.06/hamihub-full-install-v2026.11.06.zip',
    'release-files/v2026.11.06.1/hamihub-update-admin-user-v2026.11.06.1.zip',
    'release-files/v2026.11.07.1/hamihub-update-admin-public-v2026.11.07.1.zip',
    'release-files/v2026.11.07.2/hamihub-update-admin-public-v2026.11.07.2.zip',
    'release-files/v2026.11.07.3/hamihub-update-ai-admin-v2026.11.07.3.zip',
    'release-files/v2026.11.07.5/hamihub-update-ai-models-v2026.11.07.5.zip',
)
CANDIDATE = ROOT / 'release-files/after-v2026.11.07.5-rc1/hamihub-update-after-v2026.11.07.5-rc1.zip'
CANDIDATE_RC2 = ROOT / 'release-files/after-v2026.11.07.5-rc2/hamihub-update-after-v2026.11.07.5-rc2.zip'
RUNTIME = ('app/', 'database/', 'public_html/')
DOCS = {'UPGRADE-RC-FA.md', 'UPGRADE-RC-SOURCE.txt', 'UPGRADE-RC-MANIFEST.txt', 'UPGRADE-RC-BASELINE-SHA256.txt'}
if not __debug__:
    raise RuntimeError('Run this smoke test without Python optimization; assertions are required.')

SENTINELS = {
    'config/config.local.php': b'<?php /* synthetic local config: never replace */ return [];\n',
    'storage/private/ai-provider-master.key': b'synthetic-ai-master-key-preservation-test\n',
    'storage/private/advertising-payment-master.key': b'synthetic-payment-key-preservation-test\n',
    'storage/installed.lock': b'synthetic-installed-lock\n',
    'public_html/uploads/synthetic-existing-file.txt': b'synthetic-upload-preservation-test\n',
    'app/synthetic-custom-local-file.txt': b'synthetic-local-file-preservation-test\n',
}


def git(*args: str) -> bytes:
    return subprocess.check_output(['git', *args], cwd=ROOT)


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def safe_entries(z: zipfile.ZipFile) -> dict[str, bytes]:
    result: dict[str, bytes] = {}
    for info in z.infolist():
        name = info.filename
        parts = PurePosixPath(name).parts
        mode = info.external_attr >> 16
        if name.startswith('/') or '\\' in name or '..' in parts or stat.S_ISLNK(mode):
            raise AssertionError(f'Unsafe archive entry: {name}')
        if info.is_dir():
            continue
        if name in result:
            raise AssertionError(f'Duplicate archive entry: {name}')
        result[name] = z.read(info)
    return result


def extract(archive: Path, destination: Path) -> dict[str, bytes]:
    with zipfile.ZipFile(archive) as z:
        entries = safe_entries(z)
    for name, data in entries.items():
        target = destination / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
    return entries


def tracked_runtime(revision: str) -> list[str]:
    paths = git('ls-tree', '-r', '-z', '--name-only', revision, '--', 'app', 'database', 'public_html').decode().split('\0')
    return sorted(path for path in paths if path)


def compare_tree(stage: Path, revision: str) -> int:
    paths = tracked_runtime(revision)
    for path in paths:
        actual = (stage / path).read_bytes()
        expected = git('show', f'{revision}:{path}')
        if actual != expected:
            raise AssertionError(f'File does not match {revision[:7]}: {path}')
    return len(paths)


def files_snapshot(stage: Path) -> dict[str, str]:
    return {p.relative_to(stage).as_posix(): sha(p.read_bytes()) for p in stage.rglob('*') if p.is_file()}


def run(stage: Path, baseline_only: bool = False, *, target: str = TARGET, candidate: Path = CANDIDATE) -> None:
    for archive in ARCHIVES:
        extract(ROOT / archive, stage)
    count_before = compare_tree(stage, BASE)
    for path, content in SENTINELS.items():
        destination = stage / path
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(content)
    if baseline_only:
        test_dir = stage / 'tests'
        test_dir.mkdir(exist_ok=True)
        for name in ('setup_article_url_fixture.php', 'upgrade_candidate_database_smoke.php'):
            (test_dir / name).write_bytes((ROOT / 'tests' / name).read_bytes())
        print(f'PASS: prepared {count_before} matching .5-like baseline files and guarded PHP fixture scripts.')
        print('Replace the synthetic config placeholder with a local hhx_test_* config before running DB tests.')
        print(f'Synthetic baseline: {stage}')
        return
    before = files_snapshot(stage)

    with zipfile.ZipFile(candidate) as z:
        entries = safe_entries(z)
        assert z.testzip() is None, 'ZIP CRC validation failed'
    candidates = set(entries) - DOCS
    changed = set(git('diff', '--no-renames', '--name-only', '-z', BASE, target, '--', 'app', 'database', 'public_html').decode().split('\0')) - {''}
    assert candidates == changed, f'Unexpected runtime file set: {candidates ^ changed}'
    assert all(p.startswith(RUNTIME) and not p.startswith(('public_html/uploads/', 'public_html/cache/')) for p in candidates)
    assert DOCS <= set(entries) and len(entries) == len(changed) + len(DOCS)
    assert entries['UPGRADE-RC-FA.md'] == (candidate.parent / 'RAHNAMA-FA.md').read_bytes(), 'External and embedded guides differ'
    source = entries['UPGRADE-RC-SOURCE.txt'].decode()
    assert BASE in source and target in source and 'STAGING ONLY' in source, 'Candidate source identifiers do not match'
    if target == TARGET_RC2:
        # RC2 is a cumulative .5 overlay, not an update to RC1. Its only
        # runtime additions relative to RC1 are the two merged media files.
        with zipfile.ZipFile(CANDIDATE) as old:
            rc1_runtime = {name: data for name, data in safe_entries(old).items() if name not in DOCS}
        differences = {p for p in set(rc1_runtime) | candidates if rc1_runtime.get(p) != entries.get(p)}
        assert differences == {'app/Controllers/BusinessController.php', 'app/Services/MediaService.php'}, f'Unexpected RC2/RC1 differences: {differences}'
    for secret in SENTINELS.values():
        assert not any(secret in data for data in entries.values()), 'Synthetic private data leaked into the ZIP'
    def parse_manifest(data: bytes) -> dict[str, str]:
        manifest: dict[str, str] = {}
        for line in data.decode().splitlines():
            value, path = line.split('  ', 1)
            assert re.fullmatch(r'[0-9a-f]{64}', value) and path not in manifest
            manifest[path] = value
        return manifest

    manifest = parse_manifest(entries['UPGRADE-RC-MANIFEST.txt'])
    assert set(manifest) == candidates
    for path in candidates:
        assert sha(entries[path]) == manifest[path], f'Manifest mismatch: {path}'
        assert entries[path] == git('show', f'{target}:{path}'), f'Archive/source mismatch: {path}'
    baseline_data = (candidate.parent / 'BASELINE-SHA256.txt').read_bytes()
    assert baseline_data == entries['UPGRADE-RC-BASELINE-SHA256.txt']
    baseline = parse_manifest(baseline_data)
    assert set(baseline) == set(tracked_runtime(BASE))
    for path, value in baseline.items():
        assert value == sha((stage / path).read_bytes()), f'Baseline mismatch: {path}'
    checksum_lines = (candidate.parent / 'SHA256SUMS.txt').read_text().splitlines()
    assert checksum_lines == [f'{sha(candidate.read_bytes())}  {candidate.name}',
                              f'{sha(baseline_data)}  BASELINE-SHA256.txt'], 'External checksum mismatch'

    extract(candidate, stage)
    count_after = compare_tree(stage, target)
    after = files_snapshot(stage)
    for path, value in before.items():
        if path not in candidates:
            assert after.get(path) == value, f'Unrelated file removed or changed: {path}'
    for path, content in SENTINELS.items():
        assert (stage / path).read_bytes() == content, f'Protected file changed: {path}'
    html = (stage / 'public_html/admin/index.html').read_text()
    for name in ('admin.js', 'admin.css'):
        value = sha((stage / 'public_html/admin/assets' / name).read_bytes())[:12]
        assert f'/admin/assets/{name}?v={value}' in html, f'Admin asset hash mismatch: {name}'
    print(f'PASS: historical full install + updates -> .5 matched {count_before} PR #12 runtime files.')
    print(f'PASS: {len(candidates)} candidate files match pinned target/manifest; all {count_after} target runtime files match after overlay.')
    print('PASS: local config, private keys, lock, uploads, custom file and other unrelated files were preserved; admin asset URLs match.')
    print(f'Synthetic stage: {stage}')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--candidate', choices=('rc1', 'rc2'), default='rc1', help='pinned candidate (default: rc1)')
    parser.add_argument('--stage', type=Path, help='new, non-repository directory to retain for guarded local tests')
    parser.add_argument('--baseline-only', action='store_true', help='retain old code and PHP fixtures for the two-phase DB test; requires --stage')
    args = parser.parse_args()
    target, candidate = (TARGET_RC2, CANDIDATE_RC2) if args.candidate == 'rc2' else (TARGET, CANDIDATE)
    if args.baseline_only and not args.stage:
        parser.error('--baseline-only requires --stage')
    if args.stage:
        stage = args.stage.resolve()
        if stage.exists() or stage == ROOT or ROOT in stage.parents:
            raise SystemExit('--stage must be a new directory outside the repository')
        stage.mkdir(parents=True)
        run(stage, args.baseline_only, target=target, candidate=candidate)
    else:
        with tempfile.TemporaryDirectory(prefix='hhx-upgrade-') as directory:
            run(Path(directory), target=target, candidate=candidate)
