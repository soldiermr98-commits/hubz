#!/usr/bin/env python3
"""Build the post-RC2 worker/cache overlay from pinned Git commits.

The archive is incremental, not a full installation. Never reads live config,
credentials, uploads or cache; never overwrites a different published artifact.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import subprocess
import tempfile
import zipfile

from build_update_candidate import check_existing, publish_new_or_same

ROOT = Path(__file__).resolve().parent.parent
BASE = '17be5bb6d0f5d75c7d2aee159ac00b573f101ef9'  # RC2 target, PR #19
TARGET = 'b34c09a3b35d9f55286a4357f1b56d700c37d6ce'  # PR #26 merge
RELEASES = ROOT / 'release-files'
DEFAULT_OUTPUT = RELEASES / 'after-rc2-worker-cache'
ZIP_NAME = 'hamihub-update-after-rc2-worker-cache.zip'
BASELINE_NAME = 'BASELINE-RC2-SHA256.txt'
TARGET_NAME = 'TARGET-SHA256.txt'
GUIDE_NAME = 'RAHNAMA-FA.md'
CHECKER_NAME = 'CHECK-FILES-OFFLINE.html'
SHA_NAME = 'SHA256SUMS.txt'
GUIDE_SOURCE = ROOT / 'docs/WORKER_CACHE_PACKAGE_GUIDE_FA.md'
CHECKER_TEMPLATE = ROOT / 'scripts/worker_filecheck_offline.template.html'

# Fail closed if another runtime change is ever added to either pinned commit.
STATUSES = {
    'app/Controllers/AuthController.php': 'M',
    'app/Controllers/BusinessController.php': 'M',
    'app/Controllers/CategoryController.php': 'M',
    'app/Controllers/CollectorController.php': 'M',
    'app/Controllers/PublicSiteController.php': 'M',
    'app/Controllers/ReviewQueueController.php': 'M',
    'app/Core/Cache.php': 'M',
    'app/Core/WorkerLock.php': 'A',
    'app/Services/CollectorRunner.php': 'M',
    'app/Services/MaintenanceService.php': 'M',
    'app/Services/ManualImportService.php': 'M',
    'app/Services/SponsorshipStatus.php': 'M',
    'cron/worker.php': 'M',
    'public_html/index.php': 'M',
}
RUNTIME_ROOTS = ('app/', 'cron/', 'collector-remote/', 'database/', 'public_html/', 'config/', 'storage/')
META_NAMES = (
    'UPGRADE-WORKER-CACHE-FA.md',
    'UPGRADE-WORKER-CACHE-BASELINE-SHA256.txt',
    'UPGRADE-WORKER-CACHE-MANIFEST-SHA256.txt',
    'UPGRADE-WORKER-CACHE-SOURCE.txt',
)


def git(*args: str) -> bytes:
    return subprocess.check_output(['git', *args], cwd=ROOT)


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def validate_source() -> None:
    for revision in (BASE, TARGET):
        if git('rev-parse', '--verify', f'{revision}^{{commit}}').decode().strip() != revision:
            raise ValueError(f'Unexpected source commit: {revision}')
    subprocess.check_call(['git', 'merge-base', '--is-ancestor', BASE, TARGET], cwd=ROOT)
    parts = git('diff', '--no-renames', '--name-status', '-z', BASE, TARGET).decode().split('\0')
    changes = {parts[i + 1]: parts[i] for i in range(0, len(parts) - 1, 2)
               if parts[i + 1].startswith(RUNTIME_ROOTS)}
    if changes != STATUSES:
        raise ValueError(f'Runtime change set differs from reviewed allowlist: {changes}')
    if len({PurePosixPath(path).name for path in changes}) != len(changes):
        raise ValueError('Offline checker requires unique basenames.')
    for path, status in changes.items():
        if path.startswith('/') or '\\' in path or '..' in PurePosixPath(path).parts:
            raise ValueError(f'Unsafe archive path: {path}')
        for revision in ((TARGET,) if status == 'A' else (BASE, TARGET)):
            tree = git('ls-tree', revision, '--', path).decode().strip()
            if not tree.startswith('100644 blob '):
                raise ValueError(f'Not an ordinary tracked file: {revision} {path}')
        if status == 'A' and git('ls-tree', BASE, '--', path):
            raise ValueError(f'New file already exists in the baseline: {path}')


def manifest(data: dict[str, bytes]) -> bytes:
    return ''.join(f'{sha(value)}  {path}\n' for path, value in sorted(data.items())).encode()


def archive(files: dict[str, bytes]) -> bytes:
    with tempfile.TemporaryFile() as temp:
        with zipfile.ZipFile(temp, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
            for name, content in sorted(files.items()):
                info = zipfile.ZipInfo(name, (2024, 1, 1, 0, 0, 0))
                info.create_system = 3
                info.external_attr = 0o100644 << 16
                info.compress_type = zipfile.ZIP_DEFLATED
                z.writestr(info, content, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
        temp.seek(0)
        return temp.read()


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
    validate_source()
    target = {path: git('show', f'{TARGET}:{path}') for path in sorted(STATUSES)}
    baseline = {path: git('show', f'{BASE}:{path}') for path, state in sorted(STATUSES.items()) if state == 'M'}
    baseline_data, target_data = manifest(baseline), manifest(target)
    guide = GUIDE_SOURCE.read_bytes()
    source = (f'Incremental post-RC2 worker/cache update; not a full installation.\n'
              f'Expected RC2 baseline: {BASE} (PR #19)\n'
              f'Target code:           {TARGET} (PR #26 merge)\n'
              '13 modified files must match the baseline manifest; app/Core/WorkerLock.php is new.\n'
              '14 runtime PHP files; no migrations, private config, storage, uploads or cache.\n'
              'Extraction does not schedule cron or prove live-host compatibility.\n').encode()
    contents = {
        META_NAMES[0]: guide,
        META_NAMES[1]: baseline_data,
        META_NAMES[2]: target_data,
        META_NAMES[3]: source,
        **target,
    }
    archive_data = archive(contents)
    payload = {
        'baseline': {path: sha(data) for path, data in baseline.items()},
        'target': {path: sha(data) for path, data in target.items()},
        'zipName': ZIP_NAME,
        'zipSha256': sha(archive_data),
    }
    template = CHECKER_TEMPLATE.read_text(encoding='utf-8')
    if template.count('__PAYLOAD_JSON__') != 1:
        raise ValueError('Offline checker placeholder is missing or ambiguous.')
    checker = template.replace('__PAYLOAD_JSON__', json.dumps(payload, sort_keys=True, separators=(',', ':'))).encode()
    products = {
        ZIP_NAME: archive_data,
        BASELINE_NAME: baseline_data,
        TARGET_NAME: target_data,
        GUIDE_NAME: guide,
        CHECKER_NAME: checker,
    }
    products[SHA_NAME] = ''.join(f'{sha(data)}  {name}\n' for name, data in products.items()).encode()
    output.mkdir(parents=True, exist_ok=True)
    # Inspect all conflicts before publishing anything; publish each complete
    # file without following symlinks or replacing a different existing file.
    for name, data in products.items():
        check_existing(output / name, data)
    for name, data in products.items():
        publish_new_or_same(output / name, data)
    result = output / ZIP_NAME
    print(f'{result}\nSHA-256 {sha(archive_data)}\n{len(target)} runtime files + {len(META_NAMES)} information files')
    return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=DEFAULT_OUTPUT, help='new output directory (default: post-RC2 release directory)')
    args = parser.parse_args()
    build(args.output)
