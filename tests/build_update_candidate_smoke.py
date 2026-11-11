#!/usr/bin/env python3
"""Offline checks for reproducible content and non-destructive candidate builds."""
from __future__ import annotations

import hashlib
import importlib.util
from pathlib import Path
import subprocess
import sys
import tempfile
from unittest.mock import patch
import zipfile

ROOT = Path(__file__).resolve().parent.parent
BUILDER = ROOT / 'scripts/build_update_candidate.py'
RELEASES = ROOT / 'release-files'
if not __debug__:
    raise RuntimeError('Run this smoke test without Python optimization; assertions are required.')


def run(*args: str, failure: str | None = None) -> None:
    result = subprocess.run([sys.executable, str(BUILDER), *args], cwd=ROOT, text=True, capture_output=True)
    if failure is None:
        assert result.returncode == 0, result.stderr
    else:
        assert result.returncode != 0 and failure in result.stderr, (result.stdout, result.stderr)


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def zip_contents(path: Path) -> dict[str, bytes]:
    with zipfile.ZipFile(path) as archive:
        names = archive.namelist()
        assert len(names) == len(set(names)), 'Duplicate archive entries'
        assert archive.testzip() is None, 'Invalid ZIP CRC'
        return {name: archive.read(name) for name in names}


def snapshot(directory: Path) -> dict[str, tuple[bytes, int]]:
    return {p.name: (p.read_bytes(), p.stat().st_mtime_ns) for p in directory.iterdir() if p.is_file()}


def main() -> None:
    with tempfile.TemporaryDirectory(prefix='hhx-candidate-build-') as temp:
        parent = Path(temp)
        for candidate in ('rc1', 'rc2'):
            filename = f'hamihub-update-after-v2026.11.07.5-{candidate}.zip'
            published = RELEASES / f'after-v2026.11.07.5-{candidate}'
            output = parent / candidate
            arguments = ('--candidate', candidate, '--output', str(output))
            run(*arguments)
            assert zip_contents(output / filename) == zip_contents(published / filename), 'Rebuilt content differs'
            assert (output / 'BASELINE-SHA256.txt').read_bytes() == (published / 'BASELINE-SHA256.txt').read_bytes()
            assert (output / 'SHA256SUMS.txt').read_text().splitlines() == [
                f'{sha(output / filename)}  {filename}',
                f'{sha(output / "BASELINE-SHA256.txt")}  BASELINE-SHA256.txt',
            ], 'Generated checksums must match the generated files'
            before = snapshot(output)
            run(*arguments)
            assert snapshot(output) == before, 'Rebuilding identical output must not rewrite files'

        rc2_zip = 'hamihub-update-after-v2026.11.07.5-rc2.zip'
        output = parent / 'parallel'
        command = [sys.executable, str(BUILDER), '--candidate', 'rc2', '--output', str(output)]
        writers = [subprocess.Popen(command, cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE) for _ in range(2)]
        for writer in writers:
            stdout, stderr = writer.communicate()
            assert writer.returncode == 0, (stdout, stderr)
        assert zip_contents(output / rc2_zip) == zip_contents(RELEASES / 'after-v2026.11.07.5-rc2' / rc2_zip)
        assert (output / 'SHA256SUMS.txt').read_text().splitlines() == [
            f'{sha(output / rc2_zip)}  {rc2_zip}',
            f'{sha(output / "BASELINE-SHA256.txt")}  BASELINE-SHA256.txt',
        ], 'Parallel writers must leave a consistent complete candidate'

        for name in (rc2_zip, 'BASELINE-SHA256.txt', 'SHA256SUMS.txt'):
            output = parent / ('conflict-' + name)
            output.mkdir()
            conflict = output / name
            conflict.write_bytes(b'synthetic-existing-file-do-not-overwrite\n')
            before = snapshot(output)
            run('--candidate', 'rc2', '--output', str(output), failure='Refusing to change existing candidate file')
            assert snapshot(output) == before, 'A conflict must be detected before writing any files'

        # Existing symlinks, even ones pointing at the correct file, are not overwritten or followed.
        output = parent / 'symlink'
        output.mkdir()
        link = output / 'BASELINE-SHA256.txt'
        link.symlink_to(RELEASES / 'after-v2026.11.07.5-rc1/BASELINE-SHA256.txt')
        run('--candidate', 'rc2', '--output', str(output), failure='Refusing to change existing candidate file')
        assert link.is_symlink() and not (output / rc2_zip).exists()

        # Neither the other candidate's directory nor its descendants are valid outputs.
        for selected, other in (('rc1', 'rc2'), ('rc2', 'rc1')):
            published = RELEASES / f'after-v2026.11.07.5-{other}'
            before = snapshot(published)
            for destination in (published, published / 'nested-output'):
                run('--candidate', selected, '--output', str(destination), failure='Refusing to write inside the other candidate directory')
            assert snapshot(published) == before and not (published / 'nested-output').exists()

        # Failure or a vanished competing writer must not expose a partial file
        # or leave an ordinary temporary file behind.
        spec = importlib.util.spec_from_file_location('candidate_builder_fixture', BUILDER)
        assert spec is not None and spec.loader is not None
        builder = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(builder)
        output = parent / 'publish-failure'
        output.mkdir()
        for error in (OSError('synthetic link failure'), FileExistsError('synthetic vanished writer')):
            with patch.object(builder.os, 'link', side_effect=error):
                try:
                    builder.publish_new_or_same(output / rc2_zip, b'synthetic complete bytes')
                except OSError:
                    pass
                else:
                    raise AssertionError('Failed atomic publication must not report success')
            assert not list(output.iterdir()), 'Failed publication must not leave a partial artifact'

        for competing_data in (b'identical', b'different'):
            output = parent / ('competing-' + competing_data.decode())
            output.mkdir()
            destination = output / rc2_zip

            def competing_writer(_source: Path, target: Path) -> None:
                target.write_bytes(competing_data)
                raise FileExistsError('synthetic competing writer')

            with patch.object(builder.os, 'link', side_effect=competing_writer):
                if competing_data == b'identical':
                    builder.publish_new_or_same(destination, b'identical')
                else:
                    try:
                        builder.publish_new_or_same(destination, b'identical')
                    except FileExistsError:
                        pass
                    else:
                        raise AssertionError('Different competing content must be refused')
            assert destination.read_bytes() == competing_data and list(output.iterdir()) == [destination]
    print('PASS: both candidate builds match pinned content; repeated, parallel, conflicting, symlink and failed publications are non-destructive.')


if __name__ == '__main__':
    main()
