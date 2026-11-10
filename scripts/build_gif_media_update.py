#!/usr/bin/env python3
"""Build the incremental 'GIF media + complete delete' update package.

Packages only the runtime files that changed between the pinned base commit
(the main HEAD this update targets) and the current HEAD, following the same
release-files layout as the existing hamihub update packages:
  - hamihub-update-gif-media-complete-delete.zip
  - RAHNAMA-FA.md        (Persian installation guide)
  - SHA256SUMS.txt

No config, storage, uploads, logs or user data are included.
"""
from __future__ import annotations

import hashlib
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
BASE = "45a20e1"  # original main HEAD this update targets (before GIF work)
OUTPUT = ROOT / "release-files/gif-media-and-complete-delete"
ZIP_NAME = "hamihub-update-gif-media-complete-delete.zip"
GUIDE = OUTPUT / "RAHNAMA-FA.md"


def git(*args: str) -> bytes:
    return subprocess.check_output(["git", *args], cwd=ROOT)


def changed_runtime_files(base: str, head: str) -> dict[str, bytes]:
    out = git("diff", "--no-renames", "--name-only", "-z", base, head).decode().split("\0")
    entries: dict[str, bytes] = {}
    for path in out:
        if not path:
            continue
        if path.startswith("src/") or path in ("package.json", "package-lock.json", "DEVELOPMENT.md", "README.md", "CHANGELOG.md"):
            # Panel source files are already compiled into public_html/admin/assets.
            continue
        allowed = (
            path.startswith("app/")
            or path.startswith("public_html/api/")
            or path.startswith("public_html/admin/assets/")
            or path == "public_html/admin/index.html"
        )
        if not allowed:
            raise ValueError(f"Unexpected file in update set: {path} (add explicit review)")
        if path.startswith("public_html/uploads/") or path.startswith("public_html/cache/"):
            raise ValueError(f"Data directory must never be packaged: {path}")
        tree = git("ls-tree", head, "--", path).decode().strip()
        if not tree.startswith(("100644 blob ", "100755 blob ")):
            raise ValueError(f"Not a regular tracked file: {path}")
        entries[path] = git("show", f"{head}:{path}")
    if not entries:
        raise ValueError("No runtime changes detected.")
    return dict(sorted(entries.items()))


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def main() -> int:
    head = git("rev-parse", "--short", "HEAD").decode().strip()
    changes = changed_runtime_files(BASE, head)
    guide_bytes = GUIDE.read_bytes()

    source = (
        "hamihub incremental update: animated GIF banners + complete media deletion\n"
        f"Base commit: {BASE}\n"
        f"Target commit: {head}\n"
        f"Runtime files: {len(changes)}\n"
        "No config, keys, storage, uploads, cache, logs, or user data are included.\n"
        "SQL migrations: none required. Panel assets are pre-built and included.\n"
    ).encode()
    manifest = "".join(f"{digest(data)}  {path}\n" for path, data in changes.items()).encode()

    files: dict[str, bytes] = {
        "UPGRADE-SOURCE.txt": source,
        "UPGRADE-MANIFEST.txt": manifest,
        **changes,
    }

    OUTPUT.mkdir(parents=True, exist_ok=True)
    archive = OUTPUT / ZIP_NAME
    with tempfile.TemporaryFile() as temp:
        with zipfile.ZipFile(temp, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
            for path, data in sorted(files.items()):
                info = zipfile.ZipInfo(path, (2026, 11, 10, 0, 0, 0))
                info.create_system = 3
                info.external_attr = 0o100644 << 16
                info.compress_type = zipfile.ZIP_DEFLATED
                z.writestr(info, data, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
        temp.seek(0)
        archive_data = temp.read()

    archive.write_bytes(archive_data)
    checksums = (
        f"{digest(archive_data)}  {ZIP_NAME}\n"
        f"{digest(guide_bytes)}  RAHNAMA-FA.md\n"
    )
    (OUTPUT / "SHA256SUMS.txt").write_bytes(checksums.encode())

    print(f"Archive: {archive}")
    print(f"SHA-256: {digest(archive_data)}")
    print(f"Runtime files: {len(changes)}")
    for path in changes:
        print(f"  - {path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
