#!/usr/bin/env python3
"""Regression & overlay test for hamihub-hotfix-media-tab-500.zip."""
from __future__ import annotations

from pathlib import Path
import sys
import tempfile

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'scripts'))
import build_media_tab_500_hotfix as builder  # noqa: E402

if not __debug__:
    raise RuntimeError('Run without Python optimization; assertions are required.')


def main() -> None:
    published_zip = builder.DEFAULT_OUTPUT / builder.ZIP_NAME
    assert published_zip.is_file(), f'Missing hotfix ZIP: {published_zip}'

    with tempfile.TemporaryDirectory(prefix='hhx-media-hotfix-smoke-') as tmp:
        host = Path(tmp) / 'host'
        host.mkdir()
        builder.reconstruct_pre_hotfix_host(host)

        # 1. Prove the pre-hotfix host fails on MediaController.php:29 ($request->query)
        try:
            builder.verify_core_instance_calls(host)
            raise AssertionError('Expected RouteVerificationError on pre-hotfix MediaController.php')
        except builder.RouteVerificationError as exc:
            assert 'app/Controllers/MediaController.php:29: call to undefined method App\\Core\\Request::query()' in str(exc), exc

        # 2. Apply the 3-file hotfix ZIP and verify full runtime health
        zip_entries = builder.safe_zip_entries(published_zip)
        assert set(zip_entries) == {'INSTALL-FA.md', 'MANIFEST-SHA256.txt', *builder.HOTFIX_FILES}
        for name, content in zip_entries.items():
            if '/' not in name and name.startswith(builder.DOC_PREFIXES):
                continue
            dest = host / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(content)

        stats = builder.verify_tree(host, check_matches_repo=False)
        assert stats['api_routes'] == 89, stats
        assert stats['public_routes'] == 8, stats
        assert stats['instance_calls'] > 60, stats
        assert stats['types'] >= 65, stats

    print(
        f'PASS: pre-hotfix Request::query() bug reproduced; 3-file hotfix verified '
        f'({stats["api_routes"]} API routes, {stats["static_calls"]} static calls, '
        f'{stats["instance_calls"]} core instance calls, {stats["runtime_files"]} runtime files).'
    )


if __name__ == '__main__':
    main()
