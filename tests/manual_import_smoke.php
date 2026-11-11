<?php
declare(strict_types=1);

// Run only with an explicitly designated disposable database. Fixtures roll back.
$configFile = getenv('HHX_TEST_CONFIG') ?: '';
if (getenv('HHX_ALLOW_TEST_FIXTURES') !== '1' || !$configFile || !is_file($configFile)) {
    throw new RuntimeException('Use HHX_ALLOW_TEST_FIXTURES=1 and HHX_TEST_CONFIG pointing to a test-only config.');
}
$config = require $configFile;
$database = is_array($config) ? (string)($config['db']['database'] ?? '') : '';
if (!preg_match('/^hhx_test_[a-z0-9_]+$/', $database)) throw new RuntimeException('Refusing non-test database.');
if (PHP_SAPI !== 'cli' && !defined('HHX_TEMPORARY_TEST_HARNESS')) throw new RuntimeException('CLI only.');
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Core/Config.php';
\App\Core\Config::load($configFile);
require BASE_PATH . '/app/Core/Bootstrap.php';
\App\Core\Bootstrap::init(BASE_PATH);

use App\Core\DB;
use App\Services\ManualImportService;
use App\Services\PipelineService;

function verifyImport(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo "PASS: {$label}\n";
}
$pdo = DB::connection();
$pdo->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(5));
    $input = ['name'=>'Test importer ' . $suffix,'source'=>'fixture','source_id'=>'import-' . $suffix,
        'city'=>'لاهیجان','category'=>'کافه','address'=>'original', 'lat'=>37.202, 'lng'=>50.012,
        'phones'=>['+989123456789'], 'rating'=>4.7, 'reviews_count'=>23,
        'fetched_at'=>gmdate('Y-m-d\TH:i:s\Z', time()-7200), 'reviews'=>[['id'=>'review-1','text'=>'A previous review']], 'reviews_complete'=>false];
    $opts = ManualImportService::options(['city_id'=>2,'category_id'=>1,'source_categories'=>['کافه'],'min_rating'=>4,'min_reviews'=>10]);
    $entry = static fn(array $item, string $decision, array $candidateIds=[]): array => ['index'=>0,'item'=>$item,'decision'=>$decision,'candidate_ids'=>$candidateIds];
    $countBefore = (int)DB::fetchOne('SELECT COUNT(*) AS n FROM businesses')['n'];
    $preview = ManualImportService::preview([$input], $opts);
    verifyImport($preview[0]['status']==='new' && (int)DB::fetchOne('SELECT COUNT(*) AS n FROM businesses')['n']===$countBefore, 'preview does not write');
    $duplicates = ManualImportService::preview([$input,$input], $opts);
    verifyImport($duplicates[0]['status']==='invalid' && $duplicates[1]['status']==='invalid', 'duplicate source IDs in one file are rejected');
    $wrongCity=$input;$wrongCity['city']='رشت';
    verifyImport(ManualImportService::preview([$wrongCity],$opts)[0]['status']==='filtered', 'city filter');
    $low=$input;$low['rating']=3.2;
    verifyImport(ManualImportService::preview([$low],$opts)[0]['status']==='filtered', 'minimum rating filter');
    $saved=ManualImportService::apply([$entry($input,'new')],$opts)[0];
    verifyImport($saved['status']==='created', 'new source inserted');
    $id=(int)$saved['business_id'];
    verifyImport(DB::fetchOne('SELECT status FROM businesses WHERE id=:id',['id'=>$id])['status']==='pending_review', 'new business is not published');
    verifyImport(ManualImportService::preview([$input],$opts)[0]['status']==='stale', 'identical/older observation is not reapplied');
    $update=$input;$update['fetched_at']=gmdate('Y-m-d\TH:i:s\Z',time()-3600);$update['rating']=4.2;$update['reviews_count']=30;$update['address']='different address';$update['reviews']=[];
    verifyImport(ManualImportService::preview([$update],$opts)[0]['status']==='update', 'same source ID resolves to existing business');
    $saved=ManualImportService::apply([$entry($update,'update')],$opts)[0];
    verifyImport($saved['status']==='updated' && (int)$saved['business_id']===$id, 'same source updated without duplicate');
    $biz=DB::fetchOne('SELECT address FROM businesses WHERE id=:id',['id'=>$id]);
    verifyImport($biz['address']==='original' && (bool)DB::fetchOne("SELECT id FROM change_log WHERE business_id=:id AND field='address' AND status='pending'",['id'=>$id]), 'editorial address preserved and change queued');
    $source=DB::fetchOne('SELECT * FROM business_sources WHERE provider=:p AND source_id=:sid',['p'=>'fixture','sid'=>$input['source_id']]);
    $history=DB::fetchOne('SELECT raw FROM business_source_history WHERE business_source_id=:id ORDER BY id DESC LIMIT 1',['id'=>(int)$source['id']]);
    verifyImport((float)$source['rating']===4.2 && (int)$source['reviews_count']===30 && str_contains($history['raw'],'A previous review'), 'rating refreshed and previous raw review preserved');
    $ratingDate=DB::fetchOne('SELECT observed_at FROM business_source_rating_dates WHERE business_source_id=:id',['id'=>(int)$source['id']])['observed_at'];
    $partial=$update;$partial['fetched_at']=gmdate('Y-m-d\TH:i:s\Z',time()-1800);$partial['rating']=null;$partial['reviews_count']=null;
    $optsNoFilter=$opts;$optsNoFilter['min_rating']=0;$optsNoFilter['min_reviews']=0;
    verifyImport(ManualImportService::apply([$entry($partial,'update')],$optsNoFilter)[0]['status']==='updated', 'partial observation accepted');
    $source=DB::fetchOne('SELECT * FROM business_sources WHERE id=:id',['id'=>(int)$source['id']]);
    verifyImport((float)$source['rating']===4.2 && (int)$source['reviews_count']===30 && DB::fetchOne('SELECT observed_at FROM business_source_rating_dates WHERE business_source_id=:id',['id'=>(int)$source['id']])['observed_at']===$ratingDate, 'missing aggregates preserve the pair and its original date');
    $cross=$input;$cross['source']='other_fixture';$cross['source_id']='cross-' . $suffix;
    $r=ManualImportService::preview([$cross],$opts)[0];
    verifyImport($r['status']==='review' && in_array($id,array_column($r['candidates'],'id'),true), 'cross-source candidate requires explicit review');
    verifyImport(ManualImportService::apply([$entry($cross,'skip')],$opts)[0]['status']==='skipped', 'unconfirmed candidate is not linked');
    $linked=ManualImportService::apply([$entry($cross,'link:' . $id)],$opts)[0];
    verifyImport($linked['status']==='updated' && (int)$linked['business_id']===$id, 'explicitly confirmed candidate is linked');
    DB::query('UPDATE businesses SET category_id=2 WHERE id=:id',['id'=>$id]);
    $otherCategory=$input;$otherCategory['source']='third_fixture';$otherCategory['source_id']='third-' . $suffix;
    $crossCategory=ManualImportService::preview([$otherCategory],$opts)[0];
    verifyImport($crossCategory['status']==='review' && in_array($id,array_column($crossCategory['candidates'],'id'),true), 'similar business in another category is shown for review');
    $linkedCross=ManualImportService::apply([$entry($otherCategory,'link:' . $id)],$opts)[0];
    verifyImport($linkedCross['status']==='updated' && (int)DB::fetchOne('SELECT category_id FROM businesses WHERE id=:id',['id'=>$id])['category_id']===2, 'cross-category link preserves existing category');
    DB::query('UPDATE businesses SET category_id=1 WHERE id=:id',['id'=>$id]);
    $sameName=$input;$sameName['source_id']='far-' . $suffix;$sameName['lat']=37.25;$sameName['lng']=50.05;
    verifyImport(ManualImportService::preview([$sameName],$opts)[0]['status']==='review', 'same name at a distant location is not silently merged');
    verifyImport(ManualImportService::apply([$entry($sameName,'new',[])],$opts)[0]['status']==='skipped', 'changed candidate set cannot be silently inserted');
    $sharedPhone=$input;$sharedPhone['name']='Different name ' . $suffix;$sharedPhone['source_id']='phone-' . $suffix;$sharedPhone['lat']=null;$sharedPhone['lng']=null;
    $phonePreview=ManualImportService::preview([$sharedPhone],$opts)[0];
    verifyImport($phonePreview['status']==='review' && in_array($id,array_column($phonePreview['candidates'],'id'),true), 'shared valid phone is offered for manual review even without coordinates');
    $unknown=$input;$unknown['name']='Unknown aggregate ' . $suffix;$unknown['source_id']='unknown-' . $suffix;$unknown['lat']=37.23;$unknown['lng']=50.03;$unknown['phones']=[];$unknown['rating']=null;$unknown['reviews_count']=null;
    $newUnknown=ManualImportService::apply([$entry($unknown,'new')],$optsNoFilter)[0];
    $unknownSource=DB::fetchOne('SELECT rating,reviews_count FROM business_sources WHERE provider=:p AND source_id=:sid',['p'=>'fixture','sid'=>$unknown['source_id']]);
    verifyImport($newUnknown['status']==='created' && $unknownSource['rating']===null && $unknownSource['reviews_count']===null, 'unknown rating and count remain unknown');
    echo "All manual import smoke checks passed; rolling fixtures back.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
