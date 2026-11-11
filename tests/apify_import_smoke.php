<?php
declare(strict_types=1);

// Disposable DB only. The test creates/changes fixtures and must never run on production.
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
use App\Services\ApifyAutomationService;
use App\Services\ApifyImportService;
use App\Services\ManualImportService;

function verifyApify(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo "PASS: {$label}\n";
}
function apifyEntry(array $item, string $decision, array $ids=[]): array { return ['index'=>0,'item'=>$item,'decision'=>$decision,'candidate_ids'=>$ids]; }

$suffix = bin2hex(random_bytes(4));
$pid = 'ChIJFixtureLegacy_' . $suffix;
$fid = '0xabcdef' . $suffix . ':0x987654321';
$earlier = gmdate('Y-m-d\TH:i:s\Z', time()-7200);
$later = gmdate('Y-m-d\TH:i:s\Z', time()-3600);
$opts = ManualImportService::options(['city_id'=>2,'category_id'=>3,'source_categories'=>['دندانپزشک','درمانگاه دندان‌پزشکی','متخصص ارتودنسی'],'min_rating'=>0,'min_reviews'=>0]);
$legacy = ['source'=>'google_maps','source_id'=>$fid,'source_url'=>'https://www.google.com/maps/place/test/data=!1s'.$fid.'!19s'.$pid.'?hl=fa',
    'name'=>'Test legacy '.$suffix,'city'=>'لاهیجان','category'=>'دندانپزشک','fetched_at'=>$earlier,
    'address'=>'Old address','phones'=>[],'rating'=>4.0,'reviews_count'=>2,'reviews'=>[['text'=>'old-review-test']]];
$oldSaved = ManualImportService::apply([apifyEntry($legacy,'new')],$opts)[0];
verifyApify($oldSaved['status']==='created', 'legacy FID source seeded as pending');
$oldId = (int)$oldSaved['business_id'];
$raw = [
    'title'=>'Test legacy '.$suffix, 'totalScore'=>4.3,'reviewsCount'=>6,'street'=>'6243+324','city'=>'لاهيجان',
    'state'=>'استان گیلان','countryCode'=>'IR','website'=>'https://example.com/','phone'=>'+98 13 0000 0000',
    'categories'=>['دندانپزشک'],'categoryName'=>'دندانپزشک',
    'url'=>'https://www.google.com/maps/search/?api=1&query=test&query_place_id='.$pid,
];
$without = ApifyImportService::prepare([$raw]);
verifyApify($without['stats']['missing_time']===1 && $without['items'][0]['fetched_at']==='', 'missing collection time is not invented');
verifyApify(ManualImportService::preview($without['items'],$opts)[0]['status']==='invalid', 'missing time cannot be imported');
$prepared = ApifyImportService::prepare([$raw],$later);
$item = $prepared['items'][0];
verifyApify($item['source']==='google_maps' && $item['google_place_id']===$pid && str_starts_with($item['source_id'],'gpid_'), 'Place ID extracted from sample URL into case-safe key');
verifyApify($item['address']==='6243+324' && $item['lat']===null && $item['lng']===null && $item['rating']===4.3 && $item['reviews_count']===6, 'sample fields map without inventing coordinates');
verifyApify($item['reviews']===[] && $item['website']==='https://example.com/' && $item['apify_raw']===$raw, 'raw website and missing review text preserved honestly');
$preview = ManualImportService::preview([$item],$opts)[0];
verifyApify($preview['status']==='update' && $preview['source_id']===$fid && (int)$preview['business_id']===$oldId, 'exact Place ID resolves legacy FID instead of duplicate');
$saved=ManualImportService::apply([apifyEntry($item,'update')],$opts)[0];
verifyApify($saved['status']==='updated' && (int)$saved['business_id']===$oldId, 'legacy source updates in place');
$source=DB::fetchOne('SELECT id,raw,rating,reviews_count FROM business_sources WHERE provider=:p AND source_id=:sid',['p'=>'google_maps','sid'=>$fid]);
$history=DB::fetchOne('SELECT raw FROM business_source_history WHERE business_source_id=:id ORDER BY id DESC LIMIT 1',['id'=>(int)$source['id']]);
verifyApify((float)$source['rating']===4.3 && (int)$source['reviews_count']===6 && str_contains((string)$history['raw'],'old-review-test'), 'real rating updates and old review retained in history');
verifyApify(ManualImportService::preview([$item],$opts)[0]['status']==='stale', 'same source time cannot be reapplied');
$dup=$raw;$dup['fid']='0x1234:0x5678';
$dups=ManualImportService::preview(ApifyImportService::prepare([$raw,$dup],$later)['items'],$opts);
verifyApify($dups[0]['status']==='invalid' && $dups[1]['status']==='invalid', 'duplicate Place IDs are detected even with different FID fields');
$wrong=$raw;$wrong['fid']='0xaaaa:0xbbbb';$wrong['placeId']='ChIJAnotherConflictingPlace';
verifyApify(ManualImportService::preview(ApifyImportService::prepare([$wrong],$later)['items'],$opts)[0]['status']==='conflict', 'contradictory IDs are not merged');
$unknown=$raw;$unknown['title']='Unknown rating '.$suffix;$unknown['url']='https://www.google.com/maps/search/?query_place_id=ChIJUnknown'.$suffix;$unknown['phone']='';$unknown['totalScore']=null;$unknown['reviewsCount']=null;
$unknownItem=ApifyImportService::prepare([$unknown],$later)['items'][0];
$newUnknown=ManualImportService::apply([apifyEntry($unknownItem,'new')],$opts)[0];
$unknownSource=DB::fetchOne('SELECT rating,reviews_count FROM business_sources WHERE provider=:p AND source_id=:sid',['p'=>'google_maps','sid'=>$unknownItem['source_id']]);
verifyApify($newUnknown['status']==='created' && $unknownSource['rating']===null && $unknownSource['reviews_count']===null, 'unknown score and count remain null');
verifyApify(DB::fetchOne('SELECT status FROM businesses WHERE id=:id',['id'=>(int)$newUnknown['business_id']])['status']==='pending_review', 'new raw place is not published');
$withFid=$unknown;$withFid['fid']='0x123456:0x987654';
$nextItem=ApifyImportService::prepare([$withFid],gmdate('Y-m-d\TH:i:s\Z',time()-1200))['items'][0];
$nextRow=ManualImportService::preview([$nextItem],$opts)[0];
verifyApify($nextRow['status']==='update' && $nextRow['source_id']===$unknownItem['source_id'], 'later FID output resolves a previously saved Place ID key');
$caseA=$unknown;$caseB=$unknown;$caseA['url']='https://www.google.com/maps/search/?query_place_id=ChIJCaseABC'.$suffix;$caseB['url']='https://www.google.com/maps/search/?query_place_id=ChIJcaseABC'.$suffix;
$cases=ApifyImportService::prepare([$caseA,$caseB],$later)['items'];
verifyApify($cases[0]['source_id']!==$cases[1]['source_id'], 'Place IDs differing only by letter case remain distinct');
$badKey=$unknownItem;$badKey['source_id']='gpid_' . str_repeat('0',64);
verifyApify(ManualImportService::preview([$badKey],$opts)[0]['status']==='conflict', 'tampered Place ID hash is blocked');
$one=$raw;$one['title']='Shared phone one '.$suffix;$one['url']='https://www.google.com/maps/search/?query_place_id=ChIJPhoneOne'.$suffix;$one['phone']='+98 911 ' . str_pad((string)(hexdec($suffix) % 10000000),7,'0',STR_PAD_LEFT);
$two=$one;$two['title']='Shared phone two '.$suffix;$two['url']='https://www.google.com/maps/search/?query_place_id=ChIJPhoneTwo'.$suffix;
$first=ApifyImportService::prepare([$one],$later)['items'][0];$second=ApifyImportService::prepare([$two],$later)['items'][0];
$firstSaved=ManualImportService::apply([apifyEntry($first,'new')],$opts)[0];
$secondRow=ManualImportService::preview([$second],$opts)[0];
verifyApify($firstSaved['status']==='created' && $secondRow['status']==='review' && in_array((int)$firstSaved['business_id'],array_column($secondRow['candidates'],'id'),true), 'shared phone is a review clue, not an automatic merge');

$events=[];$mode='normal';$taskId='TestTask'.$suffix;$existingRun='TestExistingRun'.$suffix;$retryRun='TestRetryRun'.$suffix;$largeRun='TestLargeRun'.$suffix;$remoteTime=gmdate('Y-m-d\TH:i:s\Z',time()-20);
$transport=static function(string $method,string $path,?string $body,int $limit) use (&$events,&$mode,$raw,$remoteTime,$taskId): string {
    $events[]=['method'=>$method,'path'=>$path,'body'=>$body];
    if ($method==='GET' && str_starts_with($path,'/v2/actor-tasks/')) {
        $input=['searchStringsArray'=>['dentist'],'locationQueries'=>['Lahijan, Iran'],'languages'=>['fa'],'maxCrawledPlacesPerSearch'=>500];
        if($mode==='bad_task')$input['searchStringsArray'][]='orthodontist';
        if(str_ends_with($path, '/input')) return json_encode($input);
        return json_encode(['data'=>['id'=>$taskId]]);
    }
    if ($method==='POST' && str_contains($path,'/runs?')) {
        if($mode==='unknown')throw new RuntimeException('simulated lost response');
        return json_encode(['data'=>['id'=>'TestScheduledRun'.substr($taskId,8),'status'=>'RUNNING','startedAt'=>$remoteTime]]);
    }
    if ($method==='GET' && preg_match('#^/v2/actor-runs/([A-Za-z0-9_-]+)$#',$path,$m)) {
        return json_encode(['data'=>['id'=>$m[1],'status'=>'SUCCEEDED','actorTaskId'=>$taskId,'defaultDatasetId'=>str_starts_with($m[1],'TestLargeRun')?'TestLargeDataset123':'TestDataset123','startedAt'=>$remoteTime,'finishedAt'=>$remoteTime]]);
    }
    if ($method==='GET' && str_contains($path,'/datasets/')) {
        if($mode==='dataset_fail')throw new RuntimeException('simulated temporary dataset failure');
        return json_encode(str_contains($path,'TestLargeDataset123')?array_fill(0,501,$raw):[$raw],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    throw new RuntimeException('unexpected mock request');
};
$auto=new ApifyAutomationService('fixture-token',$transport);
$businessCount=(int)DB::fetchOne('SELECT COUNT(*) AS n FROM businesses')['n'];
$existing=$auto->importExisting($existingRun);
$stored=$auto->runData($existing);
verifyApify(count($stored['items'])===1 && $stored['started_at_iso']===$remoteTime, 'existing run is fetched and real run start recorded');
verifyApify($auto->importExisting($existingRun)===$existing, 'same existing run is idempotent');
verifyApify((int)DB::fetchOne('SELECT COUNT(*) AS n FROM businesses')['n']===$businessCount && !array_filter($events,static fn($e)=>$e['method']==='POST'), 'fetching existing run neither starts a paid Actor nor imports businesses');
$mode='dataset_fail';
try { $auto->importExisting($retryRun); } catch (RuntimeException $e) {}
$pending=DB::fetchOne("SELECT id,status FROM apify_import_runs WHERE remote_run_id=:run",['run'=>$retryRun]);
verifyApify($pending && $pending['status']==='running', 'temporary dataset failure leaves an existing run retryable');
$mode='normal';$auto->refreshRun((int)$pending['id']);
verifyApify(count($auto->runData((int)$pending['id'])['items'])===1 && !array_filter($events,static fn($e)=>$e['method']==='POST'), 'manual refresh fetches the existing result without a paid start');
$large=$auto->importExisting($largeRun);
verifyApify(DB::fetchOne('SELECT status FROM apify_import_runs WHERE id=:id',['id'=>$large])['status']==='too_large', 'more than 500 items is not silently truncated');
$auto->saveSchedule(['enabled'=>true,'task_id'=>$taskId,'city_id'=>2,'category_id'=>3,'max_charge_usd'=>1.25,'source_categories'=>['دندانپزشک'],'min_rating'=>4.2,'min_reviews'=>3]);
DB::query('UPDATE apify_automation_settings SET next_run_at=NOW() WHERE id=1');
$start=$auto->tick();
verifyApify($start['status']==='started', 'due schedule starts one task asynchronously');
$setting=DB::fetchOne('SELECT TIMESTAMPDIFF(MINUTE,NOW(),next_run_at) AS minutes FROM apify_automation_settings WHERE id=1');
verifyApify((int)$setting['minutes']>=20159 && (int)$setting['minutes']<=20160, 'next run is 14 days after start');
$postEvents=array_values(array_filter($events,static fn($e)=>$e['method']==='POST'));
verifyApify(count($postEvents)===1 && str_contains($postEvents[0]['path'],'maxTotalChargeUsd=1.25') && str_contains($postEvents[0]['path'],'maxItems=500') && str_contains($postEvents[0]['path'],'restartOnError=false'), 'cost cap and item cap are sent without token in URL');
verifyApify($auto->tick()['status']==='checked', 'scheduled run is polled and staged');
$staged=$auto->runData((int)$start['run_id']);
verifyApify(count($staged['items'])===1 && (int)DB::fetchOne('SELECT COUNT(*) AS n FROM businesses')['n']===$businessCount, 'scheduled result stages without publishing or inserting');
verifyApify($staged['source_categories']===['دندانپزشک'] && (float)$staged['min_rating']===4.2 && (int)$staged['min_reviews']===3, 'scheduled run retains configured filter snapshot');
verifyApify($auto->tick()['status']==='waiting' && count(array_filter($events,static fn($e)=>$e['method']==='POST'))===1, 'cron repeats do not start duplicates');
DB::query('UPDATE apify_automation_settings SET next_run_at=NOW() WHERE id=1');$mode='unknown';
$uncertain=$auto->tick();
verifyApify($uncertain['status']==='start_unknown' && (int)DB::fetchOne('SELECT enabled FROM apify_automation_settings WHERE id=1')['enabled']===0, 'lost start response disables schedule');
$postCount=count(array_filter($events,static fn($e)=>$e['method']==='POST'));
verifyApify($auto->tick()['status']==='start_unknown' && count(array_filter($events,static fn($e)=>$e['method']==='POST'))===$postCount, 'uncertain paid start is not automatically retried');
$auto->resolveUnknown((int)$uncertain['run_id']);
verifyApify((int)DB::fetchOne('SELECT enabled FROM apify_automation_settings WHERE id=1')['enabled']===0, 'resolving uncertainty does not re-enable schedule');
$mode='bad_task';$auto->saveSchedule(['enabled'=>true,'task_id'=>$taskId,'city_id'=>2,'category_id'=>3,'max_charge_usd'=>1.25]);
DB::query('UPDATE apify_automation_settings SET next_run_at=NOW() WHERE id=1');
verifyApify($auto->tick()['status']==='preflight_error' && count(array_filter($events,static fn($e)=>$e['method']==='POST'))===$postCount, 'multi-query task is stopped before paid run');
DB::query("INSERT INTO apify_import_runs (task_id,status,updated_at) VALUES (:task,'starting',NOW() - INTERVAL 4 MINUTE)",['task'=>$taskId]);
$stale=(int)DB::connection()->lastInsertId();$auto->tick();
verifyApify(DB::fetchOne('SELECT status FROM apify_import_runs WHERE id=:id',['id'=>$stale])['status']==='start_unknown', 'abandoned starting state blocks retries using database time');
$auto->resolveUnknown($stale);
$auto->deleteReady($existing);
verifyApify((int)DB::fetchOne('SELECT COUNT(*) AS n FROM businesses')['n']===$businessCount, 'deleting staged result never deletes businesses');
echo "All Apify smoke checks passed on the disposable database.\n";
