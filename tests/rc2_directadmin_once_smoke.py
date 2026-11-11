#!/usr/bin/env python3
"""Exercise the optional DirectAdmin wrapper on synthetic local files only."""
from __future__ import annotations

import argparse
import concurrent.futures
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import zipfile

from rc2_read_only_preflight_smoke import CANARY, MANIFEST, SCRIPT, make_baseline, snapshot

ROOT = Path(__file__).resolve().parent.parent
RUNNER = ROOT / 'scripts/rc2_directadmin_once.sh'
if not __debug__:
    raise RuntimeError('Run without Python optimization; assertions are required.')


def setup_kit(kit: Path) -> None:
    kit.mkdir(mode=0o700, parents=True)
    kit.chmod(0o700)
    for source, name in ((SCRIPT, 'rc2_read_only_preflight.php'),
                         (RUNNER, 'run-once.sh'),
                         (MANIFEST, 'BASELINE-SHA256.txt')):
        shutil.copyfile(source, kit / name)


def run(kit: Path, root: Path, env: dict[str, str]) -> subprocess.CompletedProcess[str]:
    return subprocess.run(['/bin/sh', str(kit / 'run-once.sh'), str(root)], cwd='/tmp',
                          env=env, text=True, capture_output=True, timeout=35)


def result(kit: Path) -> tuple[int, dict]:
    once = kit / 'run-once'
    return int((once / 'exit-code.txt').read_text()), json.loads((once / 'report.txt').read_text())


def isolated_path(path: Path, include: tuple[str, ...]) -> str:
    path.mkdir()
    for command in include:
        exe = shutil.which(command)
        assert exe, f'{command} is needed for this test'
        (path / command).symlink_to(exe)
    return str(path)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--php', default=shutil.which('php'))
    args = parser.parse_args()
    assert args.php, 'PHP CLI is required (or pass --php)'
    php = str(Path(args.php).resolve())
    with tempfile.TemporaryDirectory(prefix='hhx-da-once-') as work:
        temp = Path(work)
        stage = temp / 'home/domains/example.test'
        stage.mkdir(parents=True)
        make_baseline(stage)
        initial = snapshot(stage)
        php_bin = temp / 'php-bin'
        php_bin.mkdir()
        (php_bin / 'php').symlink_to(php)
        clean_ini = temp / 'clean.ini'
        clean_ini.write_text('')
        env = dict(os.environ, PATH=str(php_bin) + os.pathsep + os.environ.get('PATH', ''),
                   PHPRC=str(clean_ini), PHP_INI_SCAN_DIR='')

        kit = temp / 'home/private-kit'
        setup_kit(kit)
        with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
            executions = list(pool.map(lambda _: run(kit, stage, env), range(6)))
        assert all(p.returncode == 0 and not p.stdout and not p.stderr for p in executions), executions
        code, report = result(kit)
        assert code == 0 and report['decision'] == 'MANUAL_REVIEW_REQUIRED'
        assert report['baseline']['matched'] == 80 and report['baseline']['issues'] == []
        assert report['archive_verified'] is None and report['baseline_manifest_verified']
        assert 'RC2 archive integrity (baseline-only mode)' in report['not_checked']
        raw = (kit / 'run-once/report.txt').read_text()
        assert str(stage) not in raw and CANARY not in raw
        assert not (kit / 'run-once/error.txt').read_text()
        assert (kit / 'run-once').stat().st_mode & 0o777 == 0o700
        assert (kit / 'run-once/report.txt').stat().st_mode & 0o777 == 0o600
        assert snapshot(stage) == initial
        assert not (stage / 'config/poison-was-read').exists()

        # Exercise the exact user-facing ZIP after private extraction, without
        # any executable permission requirement on the included shell script.
        extracted = temp / 'home/extracted-kit'
        extracted.mkdir(mode=0o700)
        with zipfile.ZipFile(ROOT / 'release-files/rc2-directadmin-filecheck/hamihub-rc2-directadmin-filecheck.zip') as archive:
            archive.extractall(extracted)
        assert run(extracted, stage, env).returncode == 0
        packaged_code, packaged_report = result(extracted)
        assert packaged_code == 0 and packaged_report['baseline'] == report['baseline']
        assert packaged_report['archive_verified'] is None and snapshot(stage) == initial

        original = (kit / 'run-once/report.txt').read_bytes()
        (stage / 'app/Core/Auth.php').write_text('changed after first scan')
        assert run(kit, stage, env).returncode == 0
        assert (kit / 'run-once/report.txt').read_bytes() == original, 'Cron ran twice'
        shutil.rmtree(stage)
        stage.mkdir()
        make_baseline(stage)
        assert snapshot(stage) == initial

        unsafe = temp / 'home/unsafe-kit'
        setup_kit(unsafe)
        unsafe.chmod(0o755)
        p = run(unsafe, stage, env)
        assert p.returncode == 73 and not (unsafe / 'run-once').exists()
        assert str(temp) not in p.stderr

        inside = stage / 'bad-kit'
        setup_kit(inside)
        p = run(inside, stage, env)
        assert p.returncode == 66 and not (inside / 'run-once').exists()
        shutil.rmtree(inside)
        assert snapshot(stage) == initial

        linked_site = temp / 'home/linked-site'
        linked_site.symlink_to(stage, target_is_directory=True)
        linked_kit = temp / 'home/linked-site-kit'
        setup_kit(linked_kit)
        p = subprocess.run(['/bin/sh', str(linked_kit / 'run-once.sh'), str(linked_site) + '/'],
                           cwd='/tmp', env=env, text=True, capture_output=True, timeout=30)
        assert p.returncode == 66 and not (linked_kit / 'run-once').exists()

        odd_site = temp / 'home/odd-site'
        odd_site.mkdir()
        (odd_site / 'app').symlink_to(stage / 'app', target_is_directory=True)
        (odd_site / 'database').mkdir()
        (odd_site / 'public_html').mkdir()
        odd_kit = temp / 'home/odd-kit'
        setup_kit(odd_kit)
        assert run(odd_kit, odd_site, env).returncode == 0
        code, report = result(odd_kit)
        assert code == 66 and report['errors'] == ['project_layout_unavailable_or_symlinked']
        assert snapshot(stage) == initial

        tampered = temp / 'home/tampered-script'
        setup_kit(tampered)
        (tampered / 'rc2_read_only_preflight.php').write_text('<?php echo "not trusted";')
        assert run(tampered, stage, env).returncode == 0
        code, report = result(tampered)
        assert code == 66 and report == {'decision': 'STOP', 'errors': ['preflight_script_digest_mismatch']}
        assert snapshot(stage) == initial

        broken_manifest = temp / 'home/tampered-manifest'
        setup_kit(broken_manifest)
        (broken_manifest / 'BASELINE-SHA256.txt').write_bytes(b'untrusted manifest')
        assert run(broken_manifest, stage, env).returncode == 0
        code, report = result(broken_manifest)
        assert code == 2 and report['errors'] == ['baseline_manifest_digest_mismatch']
        assert report['baseline'] is None and report['archive_verified'] is None
        assert snapshot(stage) == initial

        missing_php = temp / 'home/missing-php'
        setup_kit(missing_php)
        minimal = isolated_path(temp / 'minimal-bin', ('stat', 'id', 'dirname', 'mkdir', 'sha256sum', 'timeout', 'mv'))
        assert run(missing_php, stage, dict(env, PATH=minimal)).returncode == 0
        code, report = result(missing_php)
        assert code == 69 and report['errors'] == ['php_cli_unavailable']
        assert snapshot(stage) == initial

        fake_timeout = temp / 'home/timed-out'
        setup_kit(fake_timeout)
        tools = temp / 'timeout-bin'
        isolated_path(tools, ('stat', 'id', 'dirname', 'mkdir', 'sha256sum', 'mv'))
        (tools / 'php').symlink_to(php)
        (tools / 'timeout').write_text('#!/bin/sh\nexit 124\n')
        (tools / 'timeout').chmod(0o755)
        assert run(fake_timeout, stage, dict(env, PATH=str(tools))).returncode == 0
        assert (fake_timeout / 'run-once/exit-code.txt').read_text().strip() == '124'
        assert not (fake_timeout / 'run-once/report.txt').read_text()
        assert snapshot(stage) == initial

        empty_php = temp / 'home/empty-php'
        setup_kit(empty_php)
        tools = temp / 'empty-php-bin'
        isolated_path(tools, ('stat', 'id', 'dirname', 'mkdir', 'sha256sum', 'timeout', 'mv'))
        (tools / 'php').write_text('#!/bin/sh\nexit 0\n')
        (tools / 'php').chmod(0o755)
        assert run(empty_php, stage, dict(env, PATH=str(tools))).returncode == 0
        code, report = result(empty_php)
        assert code == 69 and report['errors'] == ['empty_preflight_output']
        assert snapshot(stage) == initial

        poison = temp / 'prepend.php'
        marker = temp / 'prepend-ran'
        poison.write_text('<?php file_put_contents(' + repr(str(marker)) + ', "yes"); ?>')
        ini = temp / 'php.ini'
        ini.write_text(f'auto_prepend_file="{poison}"\nauto_append_file="{poison}"\n')
        poison_env = dict(env, PHPRC=str(ini))
        probe = temp / 'noop.php'
        probe.write_text('<?php echo "ok";')
        control = subprocess.run([php, str(probe)], env=poison_env, capture_output=True, text=True, timeout=30)
        assert control.returncode == 0 and marker.exists(), 'Control: PHP did not load the poisoned ini'
        marker.unlink()
        poison_kit = temp / 'home/poison-ini'
        setup_kit(poison_kit)
        assert run(poison_kit, stage, poison_env).returncode == 0
        code, report = result(poison_kit)
        assert code == 0 and report['decision'] == 'MANUAL_REVIEW_REQUIRED'
        assert not marker.exists(), 'The runner allowed PHP ini auto-prepend/append hooks'
        assert snapshot(stage) == initial

        print('PASS: one-shot concurrency, permissions, no site writes, tampering, dependencies, timeout and PHP startup isolation.')


if __name__ == '__main__':
    main()
