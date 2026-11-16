<?php
declare(strict_types=1);

// S30 smoke: photo backfill candidates, Apify photo mapping, and the provider
// photo attach pipeline (WebP conversion, dedupe, auto-cover) — DB fixtures in a
// disposable database only. The HTTP shell (auth/multipart) is exercised by the
// panel/collector-remote E2E checklist; this test calls the service directly.
// This test writes WebP files under public_html/uploads and deletes them in finally.
$configFile = getenv('HHX_TEST_CONFIG') ?: '';
if (getenv('HHX_ALLOW_TEST_FIXTURES') !== '1' || !$configFile || !is_file($configFile)) {
    fwrite(STDERR, "Use HHX_ALLOW_TEST_FIXTURES=1 and HHX_TEST_CONFIG=/path/to/test-only-config.php\n"); exit(2);
}
$config = require $configFile;
$database = is_array($config) ? (string)($config['db']['database'] ?? '') : '';
if (!preg_match('/^hhx_test_[a-z0-9_]+$/', $database)) {
    fwrite(STDERR, "Refusing database: a disposable hhx_test_* database is required.\n"); exit(2);
}
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Core/Config.php';
\App\Core\Config::load($configFile);
require BASE_PATH . '/app/Core/Bootstrap.php';
\App\Core\Bootstrap::init(BASE_PATH);

use App\Core\DB;
use App\Services\ApifyImportService;
use App\Services\MediaUploadException;
use App\Services\PhotoBackfillService;

function check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: {$label}\n";
}

/** فیکسچر PNG کوچک واقعی برای عبور از فحش MIME و تبدیل WebP */
function makeTestPng(): string {
    if (!function_exists('imagecreatetruecolor')) {
        fwrite(STDERR, "GD extension required for this smoke test.\n"); exit(2);
    }
    $img = imagecreatetruecolor(640, 400);
    $bg = imagecolorallocate($img, 190, 210, 230);
    imagefill($img, 0, 0, $bg);
    $tmp = tempnam(sys_get_temp_dir(), 'hhxpng_') . '.png';
    imagepng($img, $tmp);
    imagedestroy($img);
    return $tmp;
}

$pdo = DB::connection();
$pdo->beginTransaction();
$createdFiles = [];
try {
    check((bool)DB::fetchOne('SELECT id FROM cities WHERE id=1') && (bool)DB::fetchOne('SELECT id FROM categories WHERE id=1'), 'test database has migrations and seed');

    // ۱) نگاشت عکس آپی‌فای — فقط فیلدهای شناخته‌شده، فقط https
    $urls = ApifyImportService::photoUrls([
        'imageUrl' => 'not-a-url',
        'imageUrls' => ['https://lh3.googleusercontent.com/p/abc=s0', 'javascript:void(0)', ''],
        'images' => [['url' => 'https://lh3.googleusercontent.com/p/def=w800'], ['title' => 'no url here']],
    ]);
    check($urls === ['https://lh3.googleusercontent.com/p/abc=s0', 'https://lh3.googleusercontent.com/p/def=w800'], 'Apify photo mapping keeps only known https fields');

    $suffix = bin2hex(random_bytes(6));
    $suffix2 = bin2hex(random_bytes(6));

    // ۲) کسب‌وکار منتشرشدهٔ بی‌عکس با منبع دارای عکس (نگاشت جدید + gosom + apify_raw)
    DB::query(
        "INSERT INTO businesses (name, slug, status, city_id, category_id, tier, created_at, updated_at)
         VALUES (:n, :s, 'published', 1, 1, 'B', NOW(), NOW())",
        ['n' => 'Photoless Fixture ' . $suffix, 's' => 'photoless-fixture-' . $suffix]
    );
    $bizId = DB::lastInsertId();
    $raw = json_encode([
        'photos' => ["https://maps.example/photos/aaa{$suffix}"],
        'photo_urls' => ["https://maps.example/gosom/bbb{$suffix}"],
        'apify_raw' => ['imageUrls' => ["https://maps.example/apify/ccc{$suffix}", "https://maps.example/apify/ddd{$suffix}"]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    DB::query(
        "INSERT INTO business_sources (business_id, provider, source_id, raw, fetched_at, created_at)
         VALUES (:b, 'google_maps', :sid, :raw, NOW(), NOW())",
        ['b' => $bizId, 'sid' => 'gpid_fixture_' . $suffix, 'raw' => $raw]
    );

    // ۳) کسب‌وکاری که کاور دارد → نباید در کاندیدها باشد
    DB::query(
        "INSERT INTO businesses (name, slug, status, city_id, category_id, tier, created_at, updated_at)
         VALUES (:n, :s, 'published', 1, 1, 'A', NOW(), NOW())",
        ['n' => 'Covered Fixture ' . $suffix, 's' => 'covered-fixture-' . $suffix]
    );
    $coveredId = DB::lastInsertId();
    DB::query('UPDATE businesses SET cover_media_id = 1 WHERE id = :id', ['id' => $coveredId]);
    DB::query(
        "INSERT INTO business_sources (business_id, provider, source_id, raw, fetched_at, created_at)
         VALUES (:b, 'google_maps', :sid, :raw, NOW(), NOW())",
        ['b' => $coveredId, 'sid' => 'gpid_fixture_' . $suffix2, 'raw' => json_encode(['photos' => ["https://maps.example/x/{$suffix2}"]])]
    );

    $candidates = PhotoBackfillService::candidates(50);
    $found = null;
    foreach ($candidates['candidates'] as $c) {
        if ((int)$c['business_id'] === (int)$bizId) { $found = $c; break; }
    }
    check($found !== null, 'published business without photos appears as backfill candidate');
    check(is_array($found['photos']) && count($found['photos']) === 4, 'candidate collects photos from all source shapes (photos + photo_urls + apify_raw)');
    check($found['attribution'] === 'Google Maps', 'candidate carries default Google Maps attribution');
    $coveredFound = array_filter($candidates['candidates'], static fn($c) => (int)$c['business_id'] === (int)$coveredId);
    check($coveredFound === [], 'business with cover_media_id is excluded from backfill');

    // ۴) هستهٔ پوش عکس: WebP سه‌سایز + business_photos + کاور خودکار
    $png = makeTestPng();
    $sourceUrl = "https://maps.example/push/{$suffix}";
    $result = PhotoBackfillService::attachUploadedPhoto(
        (int)$bizId,
        ['error' => UPLOAD_ERR_OK, 'tmp_name' => $png, 'size' => (int)filesize($png)],
        $sourceUrl,
        'Google Maps',
        'عکس فیکسچر'
    );
    check(!$result['duplicate'] && (int)($result['media']['id'] ?? 0) > 0, 'attachUploadedPhoto stores media row');
    foreach (['480', '800', '1200'] as $w) {
        $rel = (string)($result['media']["file_path_{$w}"] ?? '');
        check(preg_match('~^/uploads/[a-zA-Z0-9_/-]+\.webp$~iD', $rel) === 1, "media has valid WebP path for width {$w}");
        $abs = BASE_PATH . '/public_html' . $rel;
        check(is_file($abs), "WebP file exists on disk for width {$w}");
        $createdFiles[] = $abs;
    }
    $photoRow = DB::fetchOne(
        "SELECT * FROM business_photos WHERE business_id = :b AND source_url = :u",
        ['b' => $bizId, 'u' => $sourceUrl]
    );
    check($photoRow !== null && $photoRow['source'] === 'provider' && $photoRow['status'] === 'approved', 'business_photos row stored as approved provider photo');
    check((string)$photoRow['attribution'] === 'Google Maps', 'attribution stored');
    $coverNow = (int)DB::fetchOne('SELECT cover_media_id FROM businesses WHERE id = :id', ['id' => $bizId])['cover_media_id'];
    check($coverNow === (int)$result['media']['id'], 'auto-cover set for business without cover');

    // ۵) dedupe: همان source_url دوباره → duplicate بدون ردیف جدید
    $again = PhotoBackfillService::attachUploadedPhoto(
        (int)$bizId,
        ['error' => UPLOAD_ERR_OK, 'tmp_name' => $png, 'size' => (int)filesize($png)],
        $sourceUrl,
        'Google Maps'
    );
    check($again['duplicate'] === true, 'same source_url push is reported as duplicate');

    // ۶) کسب‌وکار دارای کاور → set_as_cover=false و ردیف گالری
    $second = PhotoBackfillService::attachUploadedPhoto(
        (int)$coveredId,
        ['error' => UPLOAD_ERR_OK, 'tmp_name' => $png, 'size' => (int)filesize($png)],
        "https://maps.example/push2/{$suffix}",
        'Google Maps'
    );
    check(!$second['duplicate'] && $second['set_as_cover'] === false, 'existing cover is not overwritten');
    $createdFiles[] = BASE_PATH . '/public_html' . (string)$second['media']['file_path_800'];

    // ۷) فایل نامعتبر → MediaUploadException امن
    $bad = tempnam(sys_get_temp_dir(), 'hhxbad_');
    file_put_contents($bad, 'definitely not an image');
    try {
        PhotoBackfillService::attachUploadedPhoto(
            (int)$bizId,
            ['error' => UPLOAD_ERR_OK, 'tmp_name' => $bad, 'size' => 26],
            "https://maps.example/push3/{$suffix}",
            'Google Maps'
        );
        check(false, 'non-image bytes must be rejected');
    } catch (MediaUploadException) {
        check(true, 'non-image bytes rejected with safe MediaUploadException');
    }

    echo "All photo push smoke checks passed.\n";
} finally {
    foreach ($createdFiles as $f) {
        if (is_file($f)) @unlink($f);
    }
    if (isset($png) && is_file($png)) @unlink($png);
    if (isset($bad) && is_file($bad)) @unlink($bad);
    if ($pdo->inTransaction()) $pdo->rollBack();
}
