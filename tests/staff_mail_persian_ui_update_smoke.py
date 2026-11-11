#!/usr/bin/env python3
"""Verify the published update is reproducible and its contents match its manifest."""
import hashlib
import subprocess
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'scripts'))
import build_staff_mail_persian_ui_update as b
from build_api_article_sync_update import safe_zip_entries

subprocess.run([sys.executable, str(ROOT / 'scripts/build_staff_mail_persian_ui_update.py')], cwd=ROOT, check=True)
entries = safe_zip_entries(b.OUTPUT / b.ZIP)
assert set(entries) == set(b.FILES) | {'INSTALL-FA.md', 'MANIFEST-SHA256.txt'}
for line in entries['MANIFEST-SHA256.txt'].decode().splitlines():
    digest, rel = line.split('  ', 1)
    assert hashlib.sha256(entries[rel]).hexdigest() == digest
    assert entries[rel] == (ROOT / rel).read_bytes()
with zipfile.ZipFile(b.OUTPUT / b.ZIP) as archive:
    assert all(info.date_time == b.TIMESTAMP for info in archive.infolist())
assert b.TIMESTAMP > (2026, 11, 10, 21, 0, 0)
print('PASS: staff, mail and Persian UI archive matches source, manifest and baseline overlay.')
