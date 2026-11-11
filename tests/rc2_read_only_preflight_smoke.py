#!/usr/bin/env python3
"""Exercise the RC2 preflight only against a fresh synthetic installation."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import selectors
import shutil
import socket
import subprocess
import tempfile
from urllib.error import HTTPError
from urllib.request import urlopen
import zipfile

ROOT = Path(__file__).resolve().parent.parent
SCRIPT = ROOT / 'scripts/rc2_read_only_preflight.php'
RELEASE = ROOT / 'release-files/after-v2026.11.07.5-rc2'
MANIFEST = RELEASE / 'BASELINE-SHA256.txt'
ARCHIVE = RELEASE / 'hamihub-update-after-v2026.11.07.5-rc2.zip'
BASELINE_SHA = '5b9eb0e0e44c6eed48ebe29fcf404c4c921fd4d354b9accbb813a8bfd6984330'
ARCHIVES = (
    'release-files/v2026.11.06/hamihub-full-install-v2026.11.06.zip',
    'release-files/v2026.11.06.1/hamihub-update-admin-user-v2026.11.06.1.zip',
    'release-files/v2026.11.07.1/hamihub-update-admin-public-v2026.11.07.1.zip',
    'release-files/v2026.11.07.2/hamihub-update-admin-public-v2026.11.07.2.zip',
    'release-files/v2026.11.07.3/hamihub-update-ai-admin-v2026.11.07.3.zip',
    'release-files/v2026.11.07.5/hamihub-update-ai-models-v2026.11.07.5.zip',
)
CANARY = 'synthetic-secret-never-print-or-load-78abf315'
if not __debug__:
    raise RuntimeError('Run without Python optimization; assertions are required.')


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def make_baseline(stage: Path) -> dict[str, bytes]:
    expected = dict(line.split('  ', 1)[::-1] for line in MANIFEST.read_text().splitlines())
    assert len(expected) == 80 and digest(MANIFEST.read_bytes()) == BASELINE_SHA
    files: dict[str, bytes] = {}
    # Only extract names from the pinned manifest; never trust arbitrary ZIP paths.
    for relative_archive in ARCHIVES:
        with zipfile.ZipFile(ROOT / relative_archive) as source:
            for info in source.infolist():
                if not info.is_dir() and info.filename in expected:
                    files[info.filename] = source.read(info)
    assert set(files) == set(expected), 'Synthetic .5 baseline is incomplete'
    for name, content in files.items():
        assert digest(content) == expected[name], f'Bad synthetic baseline file: {name}'
        target = stage / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(content)
    config = stage / 'config/config.local.php'
    config.parent.mkdir(parents=True)
    config.write_text("<?php file_put_contents(__DIR__.'/poison-was-read', 'yes'); "
                      f"throw new RuntimeException('{CANARY}');\n")
    private = stage / 'storage/private/synthetic.key'
    private.parent.mkdir(parents=True)
    private.write_text(CANARY)
    custom = stage / 'app/custom-local-extra.php'
    custom.write_text('<?php // synthetic custom file outside the baseline\n')
    return files


def snapshot(stage: Path) -> dict[str, str]:
    return {path.relative_to(stage).as_posix(): digest(path.read_bytes())
            for path in stage.rglob('*') if path.is_file() and not path.is_symlink()}


def invoke(php: str, stage: Path, manifest: Path = MANIFEST, archive: Path = ARCHIVE,
           script: Path = SCRIPT) -> tuple[int, dict]:
    result = subprocess.run([php, str(script), str(stage), str(manifest), str(archive)],
                            cwd=ROOT, capture_output=True, text=True, timeout=30)
    assert CANARY not in result.stdout + result.stderr
    assert str(stage) not in result.stdout + result.stderr, 'Absolute site path leaked'
    assert not result.stderr, result.stderr
    report = json.loads(result.stdout)
    return result.returncode, report


def expect_issue(report: dict, path: str, kind: str) -> None:
    assert report['decision'] == 'STOP'
    assert {'path': path, 'issue': kind} in report['baseline']['issues'], report


def web_guard(php: str) -> None:
    # A short-lived loopback-only server tests that accidentally serving the
    # script cannot execute a preflight or expose a diagnostic report.
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    server = subprocess.Popen([php, '-S', f'127.0.0.1:{port}', '-t', str(SCRIPT.parent)],
                              cwd=ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE,
                              text=True)
    try:
        assert server.stderr is not None
        with selectors.DefaultSelector() as selector:
            selector.register(server.stderr, selectors.EVENT_READ)
            if not selector.select(timeout=8):
                raise AssertionError('PHP fixture server failed to start')
        line = server.stderr.readline()
        assert 'Development Server' in line and server.poll() is None, line
        try:
            urlopen(f'http://127.0.0.1:{port}/{SCRIPT.name}', timeout=5)
            raise AssertionError('Web access to CLI-only preflight must not succeed')
        except HTTPError as response:
            assert response.code == 404 and response.read() == b''
            assert 'no-store' in response.headers.get('Cache-Control', '')
    finally:
        server.terminate()
        try:
            server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait(timeout=5)
        if server.stderr:
            server.stderr.close()


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php', help='PHP CLI executable')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='hhx-rc2-preflight-') as directory:
        temp = Path(directory)
        stage = temp / 'site'
        stage.mkdir()
        files = make_baseline(stage)
        before = snapshot(stage)

        code, report = invoke(args.php, stage)
        assert code == 0 and report['decision'] == 'MANUAL_REVIEW_REQUIRED'
        assert report['archive_verified'] and report['baseline_manifest_verified']
        version_id = int(subprocess.check_output([args.php, '-r', 'echo PHP_VERSION_ID;'], text=True))
        assert report['cli_environment']['php_at_least_8_1'] is (version_id >= 80100)
        assert report['baseline'] == {'expected': 80, 'matched': 80, 'issues': []}
        baseline_only = subprocess.run([args.php, str(SCRIPT), '--baseline-only', str(stage), str(MANIFEST)],
                                       cwd=ROOT, capture_output=True, text=True, timeout=30)
        assert baseline_only.returncode == 0 and not baseline_only.stderr
        assert CANARY not in baseline_only.stdout and str(stage) not in baseline_only.stdout
        partial = json.loads(baseline_only.stdout)
        assert partial['decision'] == 'MANUAL_REVIEW_REQUIRED' and partial['archive_verified'] is None
        assert partial['baseline_manifest_verified'] and partial['baseline'] == report['baseline']
        assert 'RC2 archive integrity (baseline-only mode)' in partial['not_checked']
        assert 'web PHP version and extensions' in report['not_checked']
        assert 'database schema, migrations and existing receipts' in report['not_checked']
        assert 'extra or customized files outside the baseline list' in report['not_checked']
        code2, report2 = invoke(args.php, stage)
        assert code2 == 0 and report2 == report and snapshot(stage) == before
        assert not (stage / 'config/poison-was-read').exists()
        for disabled, expected in [('json_encode', 'CLI JSON extension'), ('hash_file', 'CLI hash functions'),
                                   ('hash_init', 'CLI hash functions')]:
            missing = subprocess.run([args.php, '-d', f'disable_functions={disabled}', str(SCRIPT),
                                      str(stage), str(MANIFEST), str(ARCHIVE)],
                                     cwd=ROOT, capture_output=True, text=True, timeout=15)
            assert missing.returncode == 69 and missing.stdout == '' and expected in missing.stderr
            assert str(stage) not in missing.stderr and CANARY not in missing.stderr
        assert snapshot(stage) == before

        target_name = 'app/Core/Config.php'
        target = stage / target_name
        target.write_bytes(files[target_name] + b'\n// synthetic local change\n')
        code, report = invoke(args.php, stage)
        assert code == 2 and report['baseline']['matched'] == 79
        expect_issue(report, target_name, 'different')
        target.write_bytes(files[target_name])

        install_name = 'public_html/install.php'
        install = stage / install_name
        install.unlink()
        code, report = invoke(args.php, stage)
        assert code == 2
        expect_issue(report, install_name, 'missing_or_unavailable')
        install.write_bytes(files[install_name])

        target.unlink()
        target.symlink_to(stage / 'config/config.local.php')
        code, report = invoke(args.php, stage)
        assert code == 2
        expect_issue(report, target_name, 'symlink_not_followed')
        target.unlink()
        target.write_bytes(files[target_name])

        controllers = stage / 'app/Controllers'
        renamed = stage / 'app/Controllers-synthetic-original'
        controllers.rename(renamed)
        controllers.symlink_to(renamed, target_is_directory=True)
        code, report = invoke(args.php, stage)
        assert code == 2
        expect_issue(report, 'app/Controllers/AdvertisingController.php', 'symlink_not_followed')
        controllers.unlink()
        renamed.rename(controllers)

        special_name = 'public_html/robots.txt'
        special = stage / special_name
        special.unlink()
        os.mkfifo(special)
        code, report = invoke(args.php, stage)
        assert code == 2
        expect_issue(report, special_name, 'not_a_regular_file')
        special.unlink()
        special.write_bytes(files[special_name])
        with special.open('wb') as output:
            output.truncate(2097153)  # Sparse; just over the 2 MiB per-file budget.
        code, report = invoke(args.php, stage)
        assert code == 2
        expect_issue(report, special_name, 'unreadable_or_too_large')
        special.write_bytes(files[special_name])

        # Nine changed 2 MiB files exceed the 16 MiB total read budget.
        # The real pinned 80-file baseline is under 7 MiB.
        inflated = list(files)[:9]
        for name in inflated:
            (stage / name).write_bytes(b'x' * 2097152)
        code, report = invoke(args.php, stage)
        assert code == 2
        assert any(item['issue'] == 'audit_read_budget_exceeded' for item in report['baseline']['issues'])
        for name in inflated:
            (stage / name).write_bytes(files[name])
        assert snapshot(stage) == before

        if hasattr(os, 'geteuid') and os.geteuid() != 0:
            special.chmod(0)
            try:
                code, report = invoke(args.php, stage)
                assert code == 2
                expect_issue(report, special_name, 'unreadable_or_outside_root')
            finally:
                special.chmod(0o644)

        wrong_manifest = temp / 'wrong-manifest/BASELINE-SHA256.txt'
        wrong_manifest.parent.mkdir()
        wrong_manifest.write_bytes(b'bad synthetic manifest\n')
        code, report = invoke(args.php, stage, wrong_manifest)
        assert code == 2 and report['errors'] == ['baseline_manifest_digest_mismatch']
        assert report['baseline'] is None and not report['archive_verified']
        failed_partial = subprocess.run([args.php, str(SCRIPT), '--baseline-only', str(stage), str(wrong_manifest)],
                                        cwd=ROOT, capture_output=True, text=True, timeout=30)
        bad_report = json.loads(failed_partial.stdout)
        assert failed_partial.returncode == 2 and not failed_partial.stderr
        assert bad_report['errors'] == ['baseline_manifest_digest_mismatch']
        assert bad_report['baseline'] is None and bad_report['archive_verified'] is None
        broken_zip = temp / 'wrong-archive' / ARCHIVE.name
        broken_zip.parent.mkdir()
        changed = bytearray(ARCHIVE.read_bytes())
        changed[0] ^= 1
        broken_zip.write_bytes(changed)
        code, report = invoke(args.php, stage, archive=broken_zip)
        assert code == 2 and report['errors'] == ['rc2_archive_digest_mismatch']
        assert report['baseline'] is None and report['baseline_manifest_verified']
        broken_zip.write_bytes(changed[:-1])
        code, report = invoke(args.php, stage, archive=broken_zip)
        assert code == 2 and report['errors'] == ['rc2_archive_unavailable_or_wrong_size']
        code, report = invoke(args.php, temp / 'not-a-site')
        assert code == 2 and report['errors'] == ['site_root_unavailable']
        assert report['baseline'] is None and not report['archive_verified']
        linked_stage = temp / 'linked-site'
        linked_stage.symlink_to(stage, target_is_directory=True)
        code, report = invoke(args.php, linked_stage)
        assert code == 2 and report['errors'] == ['site_root_unavailable']

        artifact_link = temp / 'linked/BASELINE-SHA256.txt'
        artifact_link.parent.mkdir()
        artifact_link.symlink_to(MANIFEST)
        code, report = invoke(args.php, stage, artifact_link)
        assert code == 2 and report['errors'] == ['baseline_manifest_unavailable']
        archive_link = temp / 'linked' / ARCHIVE.name
        archive_link.symlink_to(ARCHIVE)
        code, report = invoke(args.php, stage, archive=archive_link)
        assert code == 2 and report['errors'] == ['rc2_archive_unavailable_or_wrong_size']
        inside = stage / 'synthetic-artifacts/BASELINE-SHA256.txt'
        inside.parent.mkdir()
        shutil.copyfile(MANIFEST, inside)
        code, report = invoke(args.php, stage, inside)
        assert code == 2 and report['errors'] == ['baseline_manifest_unavailable']
        shutil.rmtree(inside.parent)

        # Exercise path validation even if a future maintainer changes the pin:
        # only this temporary test copy is re-pinned to a malicious manifest.
        poison = MANIFEST.read_text().replace('  app/.htaccess', '  app/../../config/config.local.php', 1).encode()
        traversal = temp / 'traversal/BASELINE-SHA256.txt'
        traversal.parent.mkdir()
        traversal.write_bytes(poison)
        test_script = temp / SCRIPT.name
        source = SCRIPT.read_text()
        assert source.count(BASELINE_SHA) == 1
        test_script.write_text(source.replace(BASELINE_SHA, digest(poison)))
        code, report = invoke(args.php, stage, traversal, script=test_script)
        assert code == 2 and report['errors'] == ['baseline_manifest_invalid']
        assert report['baseline'] is None

        assert not (stage / 'config/poison-was-read').exists()
        assert snapshot(stage) == before, 'The preflight changed synthetic installation content'
        web_guard(args.php)
    print('PASS: RC2 preflight is partial and read-only; exact baseline, mismatches, '
          'missing files, symlinks, unsafe inputs, tampering, config isolation and CLI-only guard.')


if __name__ == '__main__':
    main()
