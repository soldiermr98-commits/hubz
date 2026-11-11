<?php
declare(strict_types=1);

// Disposable DB only. Never run fixture tests on a real host or production database.
$configFile=getenv('HHX_TEST_CONFIG') ?: '';
if (getenv('HHX_ALLOW_TEST_FIXTURES')!=='1' || !$configFile || !is_file($configFile)) throw new RuntimeException('A test-only config and HHX_ALLOW_TEST_FIXTURES=1 are required.');
$config=require $configFile;
if (!is_array($config) || !preg_match('/^hhx_test_[a-z0-9_]+$/', (string)($config['db']['database'] ?? ''))) throw new RuntimeException('Refusing non-test database.');
if (PHP_SAPI!=='cli' && !defined('HHX_TEMPORARY_TEST_HARNESS')) throw new RuntimeException('CLI only.');
define('BASE_PATH',dirname(__DIR__));
require BASE_PATH . '/app/Core/Config.php';
\App\Core\Config::load($configFile);
require BASE_PATH . '/app/Core/Bootstrap.php';
\App\Core\Bootstrap::init(BASE_PATH);

use App\Core\DB;
use App\Services\ApifyAutomationService;
use App\Services\ApifyImportService;
use App\Services\ManualImportService;

function verifyDirect(bool $ok,string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo "PASS: {$label}\n";
}
function mustReject(callable $fn): bool {
    try { $fn(); return false; } catch (InvalidArgumentException|RuntimeException $e) { return true; }
}
// Tables are cleared only after the explicit hhx_test_* guard above.
DB::query('DELETE FROM apify_import_runs');
DB::query('UPDATE apify_automation_settings SET enabled=0,task_id=\'\',city_id=NULL,category_id=NULL,next_run_at=NULL,last_error=NULL WHERE id=1');
DB::query("INSERT INTO categories (id,name_fa,slug,sort,is_active) VALUES (9999,'دسته آزمایشی','fixture-direct-category',999,1) ON DUPLICATE KEY UPDATE is_active=1");
$events=[]; $mode='normal'; $sequence=0;
$time=gmdate('Y-m-d\TH:i:s\Z',time()-60);
$raw=[
    'title'=>'Synthetic dental office','city'=>'لاهیجان','categoryName'=>'دندانپزشک','categories'=>['دندانپزشک'],
    'placeId'=>'ChIJFixtureDirectPlace123','url'=>'https://www.google.com/maps/search/?query_place_id=ChIJFixtureDirectPlace123',
    'totalScore'=>4.2,'reviewsCount'=>4,'location'=>['lat'=>37.2,'lng'=>50.0],
];
$transport=static function(string $method,string $path,?string $body,int $limit) use (&$events,&$mode,&$sequence,$raw,$time): string {
    $events[]=['method'=>$method,'path'=>$path,'body'=>$body,'limit'=>$limit];
    if ($method==='POST' && str_starts_with($path,'/v2/actors/')) {
        if ($mode==='unknown') throw new RuntimeException('Simulated lost response after POST');
        $sequence++;
        return json_encode(['data'=>['id'=>'DirectRemoteRun'.$sequence,'status'=>'RUNNING','startedAt'=>$time]]);
    }
    if ($method==='GET' && str_starts_with($path,'/v2/actor-runs?')) {
        if ($mode==='none') return json_encode(['data'=>['items'=>[]]]);
        return json_encode(['data'=>['items'=>[
            ['id'=>'UnrelatedRun123','status'=>'SUCCEEDED','defaultDatasetId'=>'UnrelatedDataset123','startedAt'=>$time],
            ['id'=>$mode==='oversize'?'OversizeMapRun123':'OlderMapRun123','status'=>'SUCCEEDED','defaultDatasetId'=>'CompatibleDataset123','startedAt'=>$time,'finishedAt'=>$time],
        ]]]);
    }
    if ($method==='GET' && preg_match('#^/v2/actor-runs/(DirectRemoteRun\d+)$#',$path,$match)) {
        return json_encode(['data'=>['id'=>$match[1],'status'=>'SUCCEEDED','defaultDatasetId'=>'DirectDataset123','startedAt'=>$time,'finishedAt'=>$time]]);
    }
    if ($method==='GET' && str_starts_with($path,'/v2/datasets/')) {
        if (str_contains($path,'UnrelatedDataset123')) return json_encode(array_fill(0,str_contains($path,'limit=20')?20:501,['title'=>'Other type','id'=>'not-a-map-identity']));
        if ($mode==='fivehundred') {
            $many=[];
            for($i=0;$i<500;$i++) {
                $item=$raw; $pid='ChIJFixtureFive' . str_pad((string)$i,8,'0',STR_PAD_LEFT);
                $item['title']='Synthetic place ' . $i; $item['placeId']=$pid;
                $item['url']='https://www.google.com/maps/search/?query_place_id=' . $pid;
                $many[]=$item;
            }
            return json_encode($many,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
        if ($mode==='oversize_bytes') return json_encode([array_merge($raw,['fixture_blob'=>str_repeat('x',4*1024*1024)])]);
        if ($mode==='empty') return '[]';
        if ($mode==='oversize') return json_encode(array_fill(0,str_contains($path,'limit=20')?20:501,$raw),JSON_UNESCAPED_UNICODE);
        if ($mode==='fid_only') { $fid=$raw;unset($fid['placeId'],$fid['url']);$fid['fid']='0x1234:0x5678';return json_encode([$fid],JSON_UNESCAPED_UNICODE); }
        return json_encode([$raw],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    throw new RuntimeException('Unexpected test request: ' . $method . ' ' . $path);
};
$service=new ApifyAutomationService('synthetic-token',$transport);
$input=['city_id'=>2,'category_id'=>3,'max_places'=>20,'max_charge_usd'=>0.29,'confirmed'=>true,'expected_search_term'=>'دندانپزشکی'];
$status=$service->status();
verifyDirect((int)$status['schedule']['enabled']===0 && $status['search_terms']['3']==='دندانپزشکی', 'disabled by default and actual first keyword exposed');
verifyDirect($status['search_terms']['9999']==='دسته آزمایشی', 'missing pack falls back to category name');
verifyDirect(mustReject(fn()=>$service->startDirect(array_merge($input,['confirmed'=>false]))) && !count($events), 'missing consent cannot cause a request');
verifyDirect(mustReject(fn()=>$service->startDirect(array_merge($input,['max_places'=>501]))) && mustReject(fn()=>$service->startDirect(array_merge($input,['max_charge_usd'=>0]))) && mustReject(fn()=>$service->startDirect(array_merge($input,['city_id'=>99999]))) && !count($events), 'invalid cap, count, or city cannot cause a paid request');
verifyDirect(mustReject(fn()=>$service->startDirect(array_merge($input,['expected_search_term'=>'قدیمی']))) && !count($events), 'changed search term requires a fresh confirmation');
$before=(int)DB::fetchOne('SELECT COUNT(*) AS n FROM businesses')['n'];
$start=$service->startDirect($input);
verifyDirect($start['status']==='started', 'direct collection starts with explicit consent');
$posts=array_values(array_filter($events,static fn($e)=>$e['method']==='POST'));
$payload=json_decode((string)$posts[0]['body'],true);
verifyDirect(count($posts)===1 && str_starts_with($posts[0]['path'],'/v2/actors/AabCualFIriz3X6Fs/runs?') && str_contains($posts[0]['path'],'maxTotalChargeUsd=0.29') && str_contains($posts[0]['path'],'maxItems=20') && str_contains($posts[0]['path'],'restartOnError=false'), 'direct Actor, item limit, and approved cost cap are sent once');
verifyDirect($payload['searchStringsArray']===['دندانپزشکی'] && $payload['locationQueries']===['لاهیجان, Iran'] && $payload['languages']===['fa'] && $payload['maxCrawledPlacesPerSearch']===20, 'exactly one category keyword, city, language, and manual limit');
verifyDirect($payload['geoStrictMatch']===true && $payload['keepUnverifiedLocations']===true && $payload['skipPlacesNotMatchingSearch']===true && $payload['maxReviewsPerPlace']===0 && $payload['maxPhotosPerPlace']===0 && $payload['extractPlaceDetails']===false && $payload['extractContactsFromWebsite']===false, 'boundary controls and paid extras are explicit');
verifyDirect(!str_contains($posts[0]['path'],'synthetic-token') && !str_contains($posts[0]['path'],'task'), 'no token or Task ID in start URL');
verifyDirect(mustReject(fn()=>$service->startDirect($input)) && count(array_filter($events,static fn($e)=>$e['method']==='POST'))===1, 'a concurrent or repeat start is blocked while a run is pending');
$service->refreshRun((int)$start['run_id']);
$staged=$service->runData((int)$start['run_id']);
verifyDirect(count($staged['items'])===1 && (int)$staged['city_id']===2 && (int)$staged['category_id']===3 && $staged['started_at_iso']===$time, 'polling stages result with selected destination and actual start time');
verifyDirect((int)DB::fetchOne('SELECT COUNT(*) AS n FROM businesses')['n']===$before, 'collection and staging do not create or publish businesses');
$mode='fivehundred';$start500=$service->startDirect(array_merge($input,['max_places'=>500]));$service->refreshRun((int)$start500['run_id']);
$staged500=$service->runData((int)$start500['run_id']);
$prepared=ApifyImportService::prepare($staged500['items'],$staged500['started_at_iso'])['items'];
$options=ManualImportService::options(['city_id'=>2,'category_id'=>3,'source_categories'=>['دندانپزشک'],'min_rating'=>0,'min_reviews'=>0]);
$preview=ManualImportService::preview($prepared,$options);
verifyDirect(count($staged500['items'])===500 && count($preview)===500 && count(array_filter($preview,static fn($row)=>$row['status']==='new'))===500, '500 synthetic places can be staged and previewed without publishing');
$mode='oversize_bytes';$oversizeRun=$service->startDirect($input);$service->refreshRun((int)$oversizeRun['run_id']);
verifyDirect(DB::fetchOne('SELECT status FROM apify_import_runs WHERE id=:id',['id'=>(int)$oversizeRun['run_id']])['status']==='too_large', 'oversize direct output is marked too large without truncation');
$mode='empty';$emptyRun=$service->startDirect($input);$service->refreshRun((int)$emptyRun['run_id']);
verifyDirect(count($service->runData((int)$emptyRun['run_id'])['items'])===0, 'empty direct output is staged without deleting anything');
$mode='normal';

$postCount=count(array_filter($events,static fn($e)=>$e['method']==='POST'));
$latest=$service->importLatest();
verifyDirect($service->runData($latest)['remote_run_id']==='OlderMapRun123', 'latest search samples and skips a large unrelated output instead of importing it');
verifyDirect($service->importLatest()===$latest, 'receiving latest compatible output is idempotent');
verifyDirect(count(array_filter($events,static fn($e)=>$e['method']==='POST'))===$postCount, 'receiving latest only performs GET requests to Apify');
$mode='none';verifyDirect(mustReject(fn()=>$service->importLatest()), 'no matching recent result returns an explicit error');
$mode='oversize';verifyDirect(mustReject(fn()=>$service->importLatest()), 'more than 500 existing results are not silently truncated');
$mode='fid_only';verifyDirect($service->importLatest()===$latest, 'Google Maps output with only a valid FID is compatible');
$mode='normal';

$settings=['enabled'=>true,'direct'=>true,'confirmed'=>true,'city_id'=>2,'category_id'=>3,'max_charge_usd'=>1.25,'expected_search_term'=>'دندانپزشکی'];
verifyDirect(mustReject(fn()=>$service->saveSchedule(array_merge($settings,['confirmed'=>false]))) && mustReject(fn()=>$service->saveSchedule(array_merge($settings,['expected_search_term'=>'old']))) && count(array_filter($events,static fn($e)=>$e['method']==='POST'))===$postCount, 'stale schedule confirmation does not enable or start a run');
$service->saveSchedule($settings);
$schedule=$service->status()['schedule'];
$minutes=(int)DB::fetchOne('SELECT TIMESTAMPDIFF(MINUTE,NOW(),next_run_at) AS n FROM apify_automation_settings WHERE id=1')['n'];
verifyDirect((int)$schedule['enabled']===1 && $schedule['mode']==='direct' && $minutes>=20159 && $minutes<=20160, 'new direct schedule waits 14 days without an immediate charge');
verifyDirect($service->tick()['status']==='waiting' && count(array_filter($events,static fn($e)=>$e['method']==='POST'))===$postCount, 'worker before due date does not start an Actor');
DB::query('UPDATE apify_automation_settings SET next_run_at=DATE_ADD(NOW(),INTERVAL 3 DAY) WHERE id=1');
$service->saveSchedule($settings);
$minutes=(int)DB::fetchOne('SELECT TIMESTAMPDIFF(MINUTE,NOW(),next_run_at) AS n FROM apify_automation_settings WHERE id=1')['n'];
verifyDirect($minutes>=4319 && $minutes<=4320, 'editing an active direct schedule preserves its next due date');
DB::query('UPDATE apify_automation_settings SET next_run_at=NOW() WHERE id=1');
$scheduled=$service->tick();
$posts=array_values(array_filter($events,static fn($e)=>$e['method']==='POST'));$last=end($posts);$body=json_decode((string)$last['body'],true);
verifyDirect($scheduled['status']==='started' && str_starts_with($last['path'],'/v2/actors/AabCualFIriz3X6Fs/runs?') && str_contains($last['path'],'maxTotalChargeUsd=1.25') && str_contains($last['path'],'maxItems=500') && $body['maxCrawledPlacesPerSearch']===500, 'due schedule uses the direct Actor and bounded 500-item request');
verifyDirect($service->tick()['status']==='checked' && count($service->runData((int)$scheduled['run_id'])['items'])===1, 'worker retrieves and stages the scheduled output');
verifyDirect($service->tick()['status']==='waiting', 'repeated worker ticks do not duplicate the scheduled start');

DB::query('UPDATE apify_automation_settings SET next_run_at=NOW() WHERE id=1');
$manual=$service->startDirect($input);
$minutes=(int)DB::fetchOne('SELECT TIMESTAMPDIFF(MINUTE,NOW(),next_run_at) AS n FROM apify_automation_settings WHERE id=1')['n'];
verifyDirect($manual['status']==='started' && $minutes>=20159 && $minutes<=20160, 'manual start pushes a matching imminent schedule back 14 days');
$service->refreshRun((int)$manual['run_id']);

$mode='unknown';$unknown=$service->startDirect($input);
$postsBefore=count(array_filter($events,static fn($e)=>$e['method']==='POST'));
verifyDirect($unknown['status']==='start_unknown' && (int)$service->status()['schedule']['enabled']===0, 'lost POST response stops the schedule safely');
verifyDirect(mustReject(fn()=>$service->startDirect($input)) && $service->tick()['status']==='start_unknown' && count(array_filter($events,static fn($e)=>$e['method']==='POST'))===$postsBefore, 'uncertain start cannot be retried automatically or manually');
$service->resolveUnknown((int)$unknown['run_id']);
verifyDirect((int)$service->status()['schedule']['enabled']===0, 'resolving uncertainty never re-enables a schedule');
$mode='normal';
DB::query("INSERT INTO apify_import_runs (task_id,status,updated_at) VALUES ('__hami_direct__','starting',NOW()-INTERVAL 4 MINUTE)");
$stale=DB::lastInsertId();$service->refreshRun($stale);
verifyDirect(DB::fetchOne('SELECT status FROM apify_import_runs WHERE id=:id',['id'=>$stale])['status']==='start_unknown', 'panel polling recovers an abandoned start without requiring cron');
$service->resolveUnknown($stale);

DB::query("UPDATE apify_automation_settings SET enabled=1,task_id='LegacyTaskABC',city_id=2,category_id=3,max_charge_usd=2.00,next_run_at=DATE_ADD(NOW(),INTERVAL 1 DAY) WHERE id=1");
$legacy=$service->status()['schedule'];
verifyDirect($legacy['mode']==='legacy' && (int)$legacy['enabled']===1 && $legacy['task_id']==='LegacyTaskABC', 'an existing enabled legacy schedule remains enabled and unchanged');
$service->saveSchedule($settings);
$changed=$service->status()['schedule'];
verifyDirect($changed['mode']==='direct' && (int)$changed['enabled']===1 && (int)DB::fetchOne('SELECT TIMESTAMPDIFF(MINUTE,NOW(),next_run_at) AS n FROM apify_automation_settings WHERE id=1')['n']>=20159, 'explicit replacement of legacy schedule starts a new 14-day wait');

$socket=(string)($config['db']['unix_socket'] ?? '');
$dsn=$socket!=='' ? 'mysql:unix_socket='.$socket.';dbname='.$config['db']['database'] : 'mysql:host='.$config['db']['host'].';port='.$config['db']['port'].';dbname='.$config['db']['database'];
$other=new PDO($dsn,(string)$config['db']['username'],(string)$config['db']['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$lock='hhx_apify_' . substr(hash('sha256',(string)$config['db']['database']),0,40);
$stmt=$other->prepare('SELECT GET_LOCK(?,0)');$stmt->execute([$lock]);
verifyDirect((int)$stmt->fetchColumn()===1 && mustReject(fn()=>$service->startDirect($input)) && $service->tick()['status']==='busy', 'database lock prevents simultaneous admin and cron starts');
$stmt=$other->prepare('SELECT RELEASE_LOCK(?)');$stmt->execute([$lock]);
verifyDirect((int)DB::fetchOne('SELECT COUNT(*) AS n FROM businesses')['n']===$before, 'all direct and latest tests leave business publication untouched');
echo "All direct Apify smoke checks passed on the disposable database.\n";
