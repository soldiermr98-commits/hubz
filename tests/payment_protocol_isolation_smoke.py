#!/usr/bin/env python3
"""Verify that the synthetic gateway protocol test never loads local config."""
from __future__ import annotations

import argparse
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parent.parent
FILES = (
    'tests/payment_gateway_protocol_smoke.php',
    'app/Services/PaymentVault.php',
    'app/Services/PaymentGatewaySettings.php',
    'app/Services/PaymentGatewayClient.php',
    # Present so an accidental Bootstrap call reaches the poisoned config.
    'app/Core/Bootstrap.php',
    'app/Core/Config.php',
)
if not __debug__:
    raise RuntimeError('Run without Python optimization; assertions are required.')


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php', help='PHP CLI executable')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='hhx-protocol-isolation-') as directory:
        root = Path(directory)
        for name in FILES:
            target = root / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / name, target)
        config = root / 'config'
        config.mkdir()
        poison = ("<?php file_put_contents(__DIR__ . '/config-was-read', 'synthetic'); "
                  "throw new RuntimeException('Test must not read application configuration');\n")
        (config / 'config.php').write_text(poison, encoding='utf-8')
        (config / 'config.local.php').write_text(poison, encoding='utf-8')
        result = subprocess.run([args.php, str(root / FILES[0])], cwd=root, text=True,
                                capture_output=True, timeout=30)
        assert not (config / 'config-was-read').exists(), 'Gateway protocol test loaded local config'
        assert result.returncode == 0 and 'PASS:' in result.stdout, (result.stdout, result.stderr)
    print('PASS: synthetic gateway protocol test does not load application config.')


if __name__ == '__main__':
    main()
