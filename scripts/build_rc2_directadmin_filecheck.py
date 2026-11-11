#!/usr/bin/env python3
"""Build a private, non-installing DirectAdmin baseline-only RC2 filecheck kit."""
from __future__ import annotations

import argparse
import hashlib
import io
from pathlib import Path
import zipfile

from build_update_candidate import check_existing, publish_new_or_same

ROOT = Path(__file__).resolve().parent.parent
RC2 = ROOT / 'release-files/after-v2026.11.07.5-rc2'
RC1 = ROOT / 'release-files/after-v2026.11.07.5-rc1'
DEFAULT = ROOT / 'release-files/rc2-directadmin-filecheck'
NAME = 'hamihub-rc2-directadmin-filecheck.zip'
PINNED = {
    'rc2_read_only_preflight.php': '568b2ca17af161da43cc6d9e28505a1f0717f7f67af8d2332ae859478afb0ecd',
    'BASELINE-SHA256.txt': '5b9eb0e0e44c6eed48ebe29fcf404c4c921fd4d354b9accbb813a8bfd6984330',
}


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def regular(path: Path) -> bytes:
    if path.is_symlink() or not path.is_file():
        raise ValueError(f'Required input is not a regular file: {path.name}')
    return path.read_bytes()


def make_bytes() -> bytes:
    files = {
        'RAHNAMA-FA.md': regular(ROOT / 'docs/DIRECTADMIN_RC2_FILECHECK_FA.md'),
        'run-once.sh': regular(ROOT / 'scripts/rc2_directadmin_once.sh'),
        'rc2_read_only_preflight.php': regular(ROOT / 'scripts/rc2_read_only_preflight.php'),
        'BASELINE-SHA256.txt': regular(RC2 / 'BASELINE-SHA256.txt'),
    }
    for name, expected in PINNED.items():
        if digest(files[name]) != expected:
            raise ValueError(f'Unexpected input hash: {name}')
    if PINNED['rc2_read_only_preflight.php'].encode() not in files['run-once.sh']:
        raise ValueError('Wrapper does not pin the supplied preflight script')
    result = io.BytesIO()
    # The kit is tiny. Store files without deflate so zlib versions do not
    # change published ZIP bytes; file order, metadata and timestamps are fixed.
    with zipfile.ZipFile(result, 'w', compression=zipfile.ZIP_STORED) as archive:
        for name, data in sorted(files.items()):
            info = zipfile.ZipInfo(name, (2020, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_STORED
            archive.writestr(info, data, compress_type=zipfile.ZIP_STORED)
    return result.getvalue()


def build(output: Path) -> Path:
    destination = output.resolve()
    for protected in (RC1.resolve(), RC2.resolve()):
        if destination == protected or protected in destination.parents:
            raise ValueError('Refusing to write inside an existing release candidate')
    data = make_bytes()
    output.mkdir(parents=True, exist_ok=True)
    archive = output / NAME
    checksums = f'{digest(data)}  {NAME}\n'.encode()
    targets = ((archive, data), (output / 'SHA256SUMS.txt', checksums))
    for path, expected in targets:
        check_existing(path, expected)
    for path, expected in targets:
        publish_new_or_same(path, expected)
    print(f'{archive}\nSHA-256 {digest(data)}')
    return archive


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=DEFAULT)
    build(parser.parse_args().output)
