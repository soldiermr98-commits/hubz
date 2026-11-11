#!/usr/bin/env python3
"""Build the staging-only post-.5 overlay from pinned, committed Git objects.

No working-tree application files, configuration, storage, uploads, or secrets
are included. This is an incremental candidate, never a full installation.
"""
from __future__ import annotations

import argparse
import hashlib
import os
from pathlib import Path
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parent.parent
BASE = '35476933f98ebd2d5d442ae4a8eb54dc7e32e5b0'  # PR #12, .5 code base
TARGET = '543b0d6eeed8e51b1986268a43bee12cb564cc67'  # PR #17 merge; RC1 stays immutable
TARGET_RC2 = '17be5bb6d0f5d75c7d2aee159ac00b573f101ef9'  # PR #19 merge, including media fixes
DEFAULT_OUTPUT = ROOT / 'release-files/after-v2026.11.07.5-rc1'
DEFAULT_OUTPUT_RC2 = ROOT / 'release-files/after-v2026.11.07.5-rc2'
ZIP_NAME = 'hamihub-update-after-v2026.11.07.5-rc1.zip'
ZIP_NAME_RC2 = 'hamihub-update-after-v2026.11.07.5-rc2.zip'
BASELINE_NAME = 'BASELINE-SHA256.txt'
GUIDE = DEFAULT_OUTPUT / 'RAHNAMA-FA.md'
GUIDE_RC2 = DEFAULT_OUTPUT_RC2 / 'RAHNAMA-FA.md'
RUNTIME_PREFIXES = ('app/', 'database/', 'public_html/')
FORBIDDEN_PREFIXES = ('public_html/uploads/', 'public_html/cache/')
META = ('UPGRADE-RC-FA.md', 'UPGRADE-RC-SOURCE.txt', 'UPGRADE-RC-MANIFEST.txt', 'UPGRADE-RC-BASELINE-SHA256.txt')


def git(*args: str) -> bytes:
    return subprocess.check_output(['git', *args], cwd=ROOT)


def runtime_changes(target: str = TARGET) -> dict[str, bytes]:
    for revision in (BASE, target):
        if git('rev-parse', '--verify', f'{revision}^{{commit}}').decode().strip() != revision:
            raise ValueError(f'Unexpected commit for {revision}')
    output = git('diff', '--no-renames', '--name-status', '-z', BASE, target)
    fields = output.decode('utf-8').split('\0')
    entries: dict[str, bytes] = {}
    for i in range(0, len(fields) - 1, 2):
        status, path = fields[i:i + 2]
        if not status:
            continue
        if path.startswith(('cron/', 'collector-remote/')) or (path.startswith('config/') and path != 'config/config.sample.php'):
            raise ValueError(f'Runtime change needs explicit packaging review: {path}')
        if not path.startswith(RUNTIME_PREFIXES):
            continue
        if status not in ('A', 'M') or path.startswith(FORBIDDEN_PREFIXES):
            raise ValueError(f'Unexpected runtime change (deletion, rename, or data): {status} {path}')
        if path.startswith('/') or '..' in Path(path).parts or '\\' in path:
            raise ValueError(f'Unsafe path: {path}')
        tree = git('ls-tree', target, '--', path).decode().strip()
        if not tree.startswith(('100644 blob ', '100755 blob ')):
            raise ValueError(f'Not a regular tracked file: {path}')
        entries[path] = git('show', f'{target}:{path}')
    if not entries or not all(f'database/migrations/{n}' in entries for n in (
        '008_advertising_reporting_contracts.sql',
        '009_advertising_finance.sql',
        '010_advertising_receipt_uniqueness.sql',
    )):
        raise ValueError('The expected runtime changes/migrations are missing.')
    return dict(sorted(entries.items()))


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def baseline_manifest() -> bytes:
    paths = git('ls-tree', '-r', '-z', '--name-only', BASE, '--', 'app', 'database', 'public_html').decode().split('\0')
    entries = sorted(p for p in paths if p.startswith(RUNTIME_PREFIXES) and not p.startswith(FORBIDDEN_PREFIXES))
    if not entries or any(p.startswith('/') or '..' in Path(p).parts or '\\' in p for p in entries):
        raise ValueError('Unexpected baseline runtime paths')
    return ''.join(f'{digest(git("show", f"{BASE}:{path}"))}  {path}\n' for path in entries).encode()


def check_existing(path: Path, expected: bytes) -> bool:
    """Do not replace an already-published candidate or follow a symlink."""
    if path.is_symlink():
        raise FileExistsError(f'Refusing to change existing candidate file: {path}')
    if path.exists():
        if not path.is_file() or path.read_bytes() != expected:
            raise FileExistsError(f'Refusing to change existing candidate file: {path}')
        return True
    return False


def publish_new_or_same(path: Path, data: bytes) -> None:
    if check_existing(path, data):
        return
    # Write completely in the destination directory, then publish atomically
    # with a hard link: unlike replace/rename this cannot clobber an existing
    # file. If the filesystem cannot do that, fail closed rather than expose a
    # partial artifact. A leftover temporary file is never the candidate name.
    fd, temp_name = tempfile.mkstemp(prefix=f'.{path.name}.', suffix='.tmp', dir=path.parent)
    temporary = Path(temp_name)
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
        os.chmod(temporary, 0o644)
        try:
            os.link(temporary, path)
        except FileExistsError:
            # A competing writer may have published identical content.
            if not check_existing(path, data):
                raise
    finally:
        temporary.unlink(missing_ok=True)


def build(output: Path, *, target: str = TARGET, zip_name: str = ZIP_NAME,
          guide_path: Path = GUIDE, target_label: str = 'PR #17') -> Path:
    changes = runtime_changes(target)
    guide = guide_path.read_bytes()
    baseline = baseline_manifest()
    source = (f'STAGING ONLY — incremental update, not a full installation\n'
              f'Baseline code: {BASE} (PR #12; .5 is itself incremental)\n'
              f'Target code:   {target} ({target_label})\n'
              f'Runtime files: {len(changes)}; SQL files are not executed on extraction.\n'
              'Baseline manifest lists expected old code files, not configuration or data.\n'
              'No config, storage, uploads, user data, or credentials are included.\n'
              'Online payment routes are default-off; no real payment is enabled.\n').encode()
    manifest = ''.join(f'{digest(data)}  {path}\n' for path, data in changes.items()).encode()
    files = {'UPGRADE-RC-FA.md': guide, 'UPGRADE-RC-SOURCE.txt': source,
             'UPGRADE-RC-MANIFEST.txt': manifest, 'UPGRADE-RC-BASELINE-SHA256.txt': baseline, **changes}
    output.mkdir(parents=True, exist_ok=True)
    archive = output / zip_name
    # Stable names, ordering, permissions and timestamps. Compression output may
    # still differ between Python/zlib versions, so never replace an existing ZIP.
    with tempfile.TemporaryFile() as temp:
        with zipfile.ZipFile(temp, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
            for path, data in sorted(files.items()):
                info = zipfile.ZipInfo(path, (2026, 11, 7, 0, 0, 0))
                info.create_system = 3
                info.external_attr = 0o100644 << 16
                info.compress_type = zipfile.ZIP_DEFLATED
                z.writestr(info, data, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
        temp.seek(0)
        archive_data = temp.read()
    archive_hash = digest(archive_data)
    checksums = (f'{archive_hash}  {zip_name}\n{digest(baseline)}  {BASELINE_NAME}\n').encode()
    outputs = ((archive, archive_data), (output / BASELINE_NAME, baseline), (output / 'SHA256SUMS.txt', checksums))
    # Validate all existing files before writing any, then create exclusively.
    for path, data in outputs:
        check_existing(path, data)
    for path, data in outputs:
        publish_new_or_same(path, data)
    print(f'{archive}\nSHA-256 {archive_hash}\n{len(changes)} runtime files + {len(META)} information files')
    return archive


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--candidate', choices=('rc1', 'rc2'), default='rc1', help='pinned candidate (default: rc1)')
    parser.add_argument('--output', type=Path, help='output directory (default: the selected candidate directory)')
    args = parser.parse_args()
    if args.candidate == 'rc2':
        selected_dir, other_dir = DEFAULT_OUTPUT_RC2, DEFAULT_OUTPUT
        options = dict(target=TARGET_RC2, zip_name=ZIP_NAME_RC2, guide_path=GUIDE_RC2, target_label='PR #19')
    else:
        selected_dir, other_dir = DEFAULT_OUTPUT, DEFAULT_OUTPUT_RC2
        options = dict(target=TARGET, zip_name=ZIP_NAME, guide_path=GUIDE, target_label='PR #17')
    output = (args.output or selected_dir).resolve()
    other_output = other_dir.resolve()
    if output == other_output or other_output in output.parents:
        parser.error('Refusing to write inside the other candidate directory.')
    build(output, **options)
