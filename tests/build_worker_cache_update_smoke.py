#!/usr/bin/env python3
"""Synthetic content, protection and RC2 -> worker/cache overlay checks."""
from __future__ import annotations

import hashlib
import importlib.util
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'scripts'))
import build_worker_cache_update as builder  # noqa: E402

if not __debug__:
    raise RuntimeError('Run without Python optimization; assertions are required.')

spec = importlib.util.spec_from_file_location('previous_overlay', ROOT / 'tests/upgrade_candidate_overlay_smoke.py')
assert spec is not None and spec.loader is not None
previous = importlib.util.module_from_spec(spec)
spec.loader.exec_module(previous)


def run(*args: str, failure: str | None = None) -> subprocess.CompletedProcess[str]:
    result = subprocess.run([sys.executable, str(ROOT / 'scripts/build_worker_cache_update.py'), *args], cwd=ROOT, text=True, capture_output=True)
    if failure is None:
        assert result.returncode == 0, result.stderr
    else:
        assert result.returncode != 0 and failure in result.stderr, (result.stdout, result.stderr)
    return result


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def parse_manifest(data: bytes) -> dict[str, str]:
    out = {}
    for line in data.decode().splitlines():
        digest, path = line.split('  ', 1)
        assert re.fullmatch('[0-9a-f]{64}', digest) and path not in out
        out[path] = digest
    return out


def snapshot(path: Path) -> dict[str, tuple[bytes, int]]:
    return {f.name: (f.read_bytes(), f.stat().st_mtime_ns) for f in path.iterdir() if f.is_file()}


def old_releases_snapshot() -> dict[str, str]:
    roots = [ROOT / 'release-files/after-v2026.11.07.5-rc1', ROOT / 'release-files/after-v2026.11.07.5-rc2', ROOT / 'release-files/rc2-directadmin-filecheck']
    return {str(p.relative_to(ROOT)): sha(p.read_bytes()) for root in roots for p in root.rglob('*') if p.is_file()}


def archive_entries(path: Path) -> dict[str, bytes]:
    with zipfile.ZipFile(path) as z:
        assert z.testzip() is None
        return previous.safe_entries(z)


def main() -> None:
    published = Path(os.environ.get('HHX_TEST_CANDIDATE_DIR', str(builder.DEFAULT_OUTPUT))).resolve()
    archive = published / builder.ZIP_NAME
    assert archive.is_file(), 'Published ZIP must be present'
    old_snapshot = old_releases_snapshot()
    with tempfile.TemporaryDirectory(prefix='hhx-after-rc2-') as temp:
        root = Path(temp)
        rebuilt = root / 'rebuilt'
        run('--output', str(rebuilt))
        actual = archive_entries(archive)
        assert actual == archive_entries(rebuilt / builder.ZIP_NAME), 'Rebuilt ZIP contents differ'
        for name in (builder.BASELINE_NAME, builder.TARGET_NAME, builder.GUIDE_NAME):
            assert (rebuilt / name).read_bytes() == (published / name).read_bytes(), name
        generated_sums = parse_manifest((rebuilt / builder.SHA_NAME).read_bytes())
        for name, value in generated_sums.items():
            assert sha((rebuilt / name).read_bytes()) == value
        published_sums = parse_manifest((published / builder.SHA_NAME).read_bytes())
        assert set(published_sums) == {builder.ZIP_NAME,builder.BASELINE_NAME,builder.TARGET_NAME,builder.GUIDE_NAME,builder.CHECKER_NAME}
        for name, value in published_sums.items():
            assert sha((published / name).read_bytes()) == value, name
        before = snapshot(rebuilt)
        run('--output', str(rebuilt))
        assert snapshot(rebuilt) == before, 'Identical rebuild changed artifacts'
        parallel = root / 'parallel'
        command = [sys.executable, str(ROOT / 'scripts/build_worker_cache_update.py'), '--output', str(parallel)]
        writers = [subprocess.Popen(command, cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE) for _ in range(2)]
        for writer in writers:
            stdout, stderr = writer.communicate()
            assert writer.returncode == 0, (stdout, stderr)
        assert archive_entries(parallel / builder.ZIP_NAME) == actual
        for name, value in parse_manifest((parallel / builder.SHA_NAME).read_bytes()).items():
            assert sha((parallel / name).read_bytes()) == value

        # New and conflicting artifacts never overwrite existing files.
        for name in (*published_sums, builder.SHA_NAME):
            destination = root / ('conflict-' + name)
            destination.mkdir()
            (destination / name).write_bytes(b'existing protected data\n')
            old = snapshot(destination)
            run('--output', str(destination), failure='Refusing to change existing candidate file')
            assert snapshot(destination) == old
        link = root / 'output-symlink'
        link.symlink_to(rebuilt, target_is_directory=True)
        run('--output', str(link), failure='symlinked output directory')
        for protected in ('after-v2026.11.07.5-rc1', 'after-v2026.11.07.5-rc2', 'rc2-directadmin-filecheck'):
            run('--output', str(ROOT / 'release-files' / protected), failure='another published release')
        assert old_releases_snapshot() == old_snapshot, 'Previous release artifacts changed'

        # Recreate the historical installed .5 chain and apply the existing RC2.
        site = root / 'synthetic-site'
        site.mkdir()
        for historical in previous.ARCHIVES:
            previous.extract(ROOT / historical, site)
        previous.compare_tree(site, previous.BASE)
        assert (site / 'cron/worker.php').read_bytes() == builder.git('show', f'{builder.BASE}:cron/worker.php')
        previous.extract(previous.CANDIDATE_RC2, site)
        before_count = previous.compare_tree(site, builder.BASE)
        assert not (site / 'app/Core/WorkerLock.php').exists(), 'New file must not exist in pristine RC2'
        sentinels = {
            'config/config.php': b'<?php /* fixture config, do not replace */ return [];\n',
            'config/config.local.php': b'<?php /* fixture secret, do not replace */ return [];\n',
            'storage/private/ai-provider-master.key': b'synthetic-ai-private\n',
            'storage/private/advertising-payment-master.key': b'synthetic-finance-private\n',
            'storage/installed.lock': b'synthetic-install-lock\n',
            'public_html/uploads/example.webp': b'synthetic-upload\n',
            'public_html/cache/pages/_home/index.html': b'synthetic-existing-cache\n',
            'public_html/cache/pages/.hhx-generation': b'synthetic-generation\n',
            'app/custom-local-code.php': b'<?php /* unrelated custom code */\n',
        }
        for name, data in sentinels.items():
            path = site / name; path.parent.mkdir(parents=True, exist_ok=True); path.write_bytes(data)
        before_site = previous.files_snapshot(site)
        expected_paths = set(builder.STATUSES)
        assert set(actual) == expected_paths | set(builder.META_NAMES), 'Unexpected ZIP contents'
        assert all(name.endswith('.php') for name in expected_paths)
        assert not any(name.startswith(('config/', 'storage/', 'database/', 'public_html/uploads/', 'public_html/cache/')) for name in actual)
        assert not any(value in body for value in sentinels.values() for body in actual.values()), 'Synthetic private data in ZIP'
        assert actual[builder.META_NAMES[0]] == (published / builder.GUIDE_NAME).read_bytes()
        assert actual[builder.META_NAMES[1]] == (published / builder.BASELINE_NAME).read_bytes()
        assert actual[builder.META_NAMES[2]] == (published / builder.TARGET_NAME).read_bytes()
        source = actual[builder.META_NAMES[3]].decode()
        assert builder.BASE in source and builder.TARGET in source
        baseline = parse_manifest(actual[builder.META_NAMES[1]])
        target = parse_manifest(actual[builder.META_NAMES[2]])
        assert set(baseline) == {p for p, status in builder.STATUSES.items() if status == 'M'}
        assert set(target) == expected_paths
        for name, digest in baseline.items():
            assert digest == sha((site / name).read_bytes()), f'RC2 baseline mismatch: {name}'
        for name, digest in target.items():
            assert digest == sha(actual[name])
            assert actual[name] == builder.git('show', f'{builder.TARGET}:{name}'), f'Target differs: {name}'
        previous.extract(archive, site)
        after_count = previous.compare_tree(site, builder.TARGET)
        assert (site / 'cron/worker.php').read_bytes() == builder.git('show', f'{builder.TARGET}:cron/worker.php')
        after_site = previous.files_snapshot(site)
        for path, digest in before_site.items():
            if path not in expected_paths:
                assert after_site.get(path) == digest, f'Unrelated file changed or disappeared: {path}'
        for path, data in sentinels.items():
            assert (site / path).read_bytes() == data, path
        print(f'PASS: historical chain plus RC2 matched {before_count} runtime files; new overlay matched {after_count} target files and cron.')
        print('PASS: all 13 old and 14 new hashes, exact ZIP allowlist, CRC, protected files, reproducibility and old release integrity.')


if __name__ == '__main__':
    main()
