#!/usr/bin/env python3
"""Build an additive update on the published unified-categories baseline."""
from pathlib import Path, PurePosixPath
import tempfile

from build_api_article_sync_update import FORBIDDEN_PREFIXES, sha
from build_media_library_opcache_fix import make_timestamped_archive, reconstruct_chain
from build_media_tab_500_hotfix import verify_tree
from build_unified_categories_dedupe_update import POST_AD_SLOTS_CHAIN
from build_update_candidate import check_existing, publish_new_or_same
import build_business_delete_media_webp_update as verifier

ROOT = Path(__file__).resolve().parent.parent
OUTPUT = ROOT / 'release-files/staff-mail-persian-ui'
GUIDE = OUTPUT / 'RAHNAMA-FA.md'
ZIP = 'hamihub-update-staff-mail-persian-ui.zip'
BASELINE = 'release-files/unified-categories-and-dedupe/hamihub-update-unified-categories-and-dedupe.zip'
BASE_SHA = '7644dd090561334dd113a1bc61aa93cdf2daf3c81c5d74e5ae93b665dd9e94a2'
TIMESTAMP = (2026, 11, 10, 23, 0, 0)  # Newer than prior update; reset OPcache on install.
FILES = (
    'app/Controllers/AdminMailController.php', 'app/Controllers/AdvertisingController.php',
    'app/Controllers/PublicSiteController.php', 'app/Controllers/StaffController.php',
    'app/Core/Auth.php', 'app/Core/StaffAccess.php', 'app/Services/AdminMailService.php',
    'app/ThirdParty/PHPMailer/Exception.php', 'app/ThirdParty/PHPMailer/LICENSE',
    'app/ThirdParty/PHPMailer/PHPMailer.php', 'app/ThirdParty/PHPMailer/README.txt',
    'app/ThirdParty/PHPMailer/SMTP.php', 'public_html/admin/assets/admin.css',
    'public_html/admin/assets/admin.js', 'public_html/admin/index.html',
    'public_html/api/index.php', 'public_html/assets/css/public.css',
)


def build() -> None:
    if sha((ROOT / BASELINE).read_bytes()) != BASE_SHA:
        raise ValueError('The published baseline ZIP differs from the expected checksum.')
    payload = {}
    for rel in FILES:
        path = PurePosixPath(rel)
        if path.is_absolute() or '..' in path.parts or rel.startswith(FORBIDDEN_PREFIXES):
            raise ValueError(f'Unsafe path: {rel}')
        source = ROOT / rel
        if source.is_symlink() or not source.is_file():
            raise ValueError(f'Missing or symlinked file: {rel}')
        payload[rel] = source.read_bytes()
    with tempfile.TemporaryDirectory(prefix='hhx-staff-mail-verify-') as temp:
        stage = Path(temp)
        reconstruct_chain(stage, (*POST_AD_SLOTS_CHAIN, BASELINE))
        for rel, data in payload.items():
            dest = stage / rel
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(data)
        # Historical verifier defaults remain unchanged for older release tests.
        verifier.EXPECTED_API_ROUTE_COUNT = 94
        stats = verify_tree(stage, check_matches_repo=True)
        assert stats['api_routes'] == 94 and stats['public_routes'] == 8
    guide = GUIDE.read_bytes()
    manifest = ''.join(f'{sha(data)}  {name}\n' for name, data in sorted(payload.items())).encode()
    archive = make_timestamped_archive({'INSTALL-FA.md':guide, 'MANIFEST-SHA256.txt':manifest, **payload}, TIMESTAMP)
    sums = f'{sha(archive)}  {ZIP}\n{sha(guide)}  RAHNAMA-FA.md\n'.encode()
    products = {ZIP:archive, 'RAHNAMA-FA.md':guide, 'SHA256SUMS.txt':sums}
    for name, data in products.items(): check_existing(OUTPUT / name, data)
    for name, data in products.items(): publish_new_or_same(OUTPUT / name, data)
    print(f'{OUTPUT / ZIP}\nSHA-256 {sha(archive)}\nVerified {stats}')

if __name__ == '__main__':
    build()
