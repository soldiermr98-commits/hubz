#!/usr/bin/env python3
"""Synthetic checks for the non-installing DirectAdmin filecheck kit."""
from __future__ import annotations

import hashlib
import io
from pathlib import Path
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parent.parent
BUILDER = ROOT / 'scripts/build_rc2_directadmin_filecheck.py'
PUBLISHED = ROOT / 'release-files/rc2-directadmin-filecheck'
NAME = 'hamihub-rc2-directadmin-filecheck.zip'


def build(output: Path) -> subprocess.CompletedProcess[str]:
    return subprocess.run(['python3', str(BUILDER), '--output', str(output)], cwd=ROOT,
                          capture_output=True, text=True, timeout=30)


def main() -> None:
    if not __debug__:
        raise RuntimeError('Run without Python optimization; assertions are required.')
    with tempfile.TemporaryDirectory(prefix='hhx-da-kit-') as work:
        temp = Path(work)
        output = temp / 'output'
        first = build(output)
        assert first.returncode == 0, first.stderr
        data = (output / NAME).read_bytes()
        checksum = (output / 'SHA256SUMS.txt').read_text()
        assert checksum == f'{hashlib.sha256(data).hexdigest()}  {NAME}\n'
        assert data == (PUBLISHED / NAME).read_bytes()
        assert checksum == (PUBLISHED / 'SHA256SUMS.txt').read_text()
        expected = {
            'RAHNAMA-FA.md': ROOT / 'docs/DIRECTADMIN_RC2_FILECHECK_FA.md',
            'run-once.sh': ROOT / 'scripts/rc2_directadmin_once.sh',
            'rc2_read_only_preflight.php': ROOT / 'scripts/rc2_read_only_preflight.php',
            'BASELINE-SHA256.txt': ROOT / 'release-files/after-v2026.11.07.5-rc2/BASELINE-SHA256.txt',
        }
        with zipfile.ZipFile(io.BytesIO(data)) as archive:
            assert archive.namelist() == sorted(expected)
            assert archive.testzip() is None
            for name, path in expected.items():
                assert archive.read(name) == path.read_bytes()
                assert archive.getinfo(name).compress_type == zipfile.ZIP_STORED
                assert not name.endswith('.zip')  # The candidate cannot be extracted by mistake.
        assert build(output).returncode == 0
        (output / NAME).write_bytes(b'different')
        before = (output / NAME).read_bytes(), (output / 'SHA256SUMS.txt').read_bytes()
        assert build(output).returncode != 0
        assert before == ((output / NAME).read_bytes(), (output / 'SHA256SUMS.txt').read_bytes())
        symlink_out = temp / 'symlink-output'
        symlink_out.mkdir()
        (symlink_out / NAME).symlink_to(temp / 'do-not-create')
        assert build(symlink_out).returncode != 0
        assert not (temp / 'do-not-create').exists()
        protected = ROOT / 'release-files/after-v2026.11.07.5-rc2/subdir-forbidden'
        assert build(protected).returncode != 0 and not protected.exists()
        print('PASS: reproducible private kit, fixed inputs, checksums, no nested update, symlinks and protected releases.')


if __name__ == '__main__':
    main()
