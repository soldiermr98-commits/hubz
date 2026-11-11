<?php
declare(strict_types=1);

// Use only an explicitly designated disposable database with migrations 001–005 applied.
$configFile = getenv('HHX_TEST_CONFIG') ?: '';
if (getenv('HHX_ALLOW_TEST_FIXTURES') !== '1' || !$configFile || !is_file($configFile)) throw new RuntimeException('A test-only config and HHX_ALLOW_TEST_FIXTURES=1 are required.');
$config = require $configFile;
if (!is_array($config) || !preg_match('/^hhx_test_[a-z0-9_]+$/', (string)($config['db']['database'] ?? ''))) throw new RuntimeException('Refusing non-test database.');
if (PHP_SAPI !== 'cli' && !defined('HHX_TEMPORARY_TEST_HARNESS')) throw new RuntimeException('CLI only.');
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Core/Config.php';
\App\Core\Config::load($configFile);
require BASE_PATH . '/app/Core/Bootstrap.php';
\App\Core\Bootstrap::init(BASE_PATH);

use App\Core\DB;
use App\Controllers\PublicSiteController;

function verifyCatalog(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo "PASS: {$label}\n";
}
$pdo = DB::connection();
$pdo->beginTransaction();
try {
    $idSuffix = bin2hex(random_bytes(5));
    $create = static function (string $name, ?int $cover = null) use ($idSuffix): int {
        static $i=0; $i++;
        DB::query("INSERT INTO businesses(name,normalized_name,slug,city_id,category_id,address,lat,lng,cover_media_id,status) VALUES(:name,:normalized,:slug,1,4,:address,37.27,49.58,:cover,'published')", [
            'name'=>$name,'normalized'=>$name,'slug'=>'catalog-test-' . $idSuffix . '-' . $i,'address'=>'نشانی <آزمایشی>','cover'=>$cover,
        ]);
        return DB::lastInsertId();
    };
    DB::query("INSERT INTO media(file_path_480,file_path_800,file_path_1200,alt_text) VALUES('/uploads/2026/11/photo.webp','/uploads/2026/11/photo.webp','/uploads/2026/11/photo.webp','test')");
    $mediaId=DB::lastInsertId();
    $withCover=$create('اقامتگاه <ویژه>', $mediaId);
    $withPhoto=$create('تصویر تأییدشده');
    $rejected=$create('تصویر ردشده');
    DB::query("INSERT INTO business_photos(business_id,media_id,status) VALUES(:id,:media,'approved')", ['id'=>$withPhoto,'media'=>$mediaId]);
    DB::query("INSERT INTO business_photos(business_id,media_id,status) VALUES(:id,:media,'rejected')", ['id'=>$rejected,'media'=>$mediaId]);
    DB::query("INSERT INTO business_sources(business_id,provider,source_id,rating,reviews_count,fetched_at) VALUES(:id,'google_maps',:source,4.4,32,'2026-01-02 10:00:00')", ['id'=>$withCover,'source'=>'catalog-fixture-' . $idSuffix]);
    DB::query("INSERT INTO business_promotions(business_id,active) VALUES(:id,1)", ['id'=>$withCover]);
    $fetch = new ReflectionMethod(PublicSiteController::class, 'fetchPublishedBusinesses');
    $render = new ReflectionMethod(PublicSiteController::class, 'renderBusinessCard');
    $rows=$fetch->invoke(null, 'b.id IN (' . implode(',', [$withCover,$withPhoto,$rejected]) . ") AND b.status='published'", [], 10);
    $byId=[];foreach($rows as $row)$byId[(int)$row['id']]=$row;
    verifyCatalog(count($rows)===3 && $byId[$withCover]['cover_thumb_url']==='/uploads/2026/11/photo.webp' && $byId[$withPhoto]['cover_thumb_url']==='/uploads/2026/11/photo.webp', 'explicit cover and approved photo fallback are loaded');
    verifyCatalog($byId[$rejected]['cover_thumb_url']===null, 'rejected photo is not used as fallback');
    $html=$render->invoke(null,$byId[$withCover],1,'test');
    verifyCatalog(str_contains($html,'/uploads/2026/11/photo.webp') && str_contains($html,'4.4') && str_contains($html,'32 نظر'), 'card shows the actual photo and source rating and review count');
    verifyCatalog(str_contains($html,'اقامتگاه &lt;ویژه&gt;') && str_contains($html,'نشانی &lt;آزمایشی&gt;') && !str_contains($html,'اقامتگاه <ویژه>'), 'names and addresses are HTML escaped');
    verifyCatalog(str_contains($html,'جایگاه ویژه') && str_contains($html,'hx-visually-hidden'), 'promoted place is labeled for visual and assistive users');
    $empty=$render->invoke(null,$byId[$rejected],2,'test');
    verifyCatalog(str_contains($empty,'تصویر ثبت نشده') && str_contains($empty,'امتیاز ثبت نشده') && !str_contains($empty,'href="tel:'), 'missing photo, rating and phone are not invented');
    echo "All public catalogue smoke checks passed; rolling fixtures back.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
