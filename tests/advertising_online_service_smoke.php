<?php
declare(strict_types=1);

// Synthetic offline integration test only. Never use a production database or provider.
$configFile = getenv('HHX_TEST_CONFIG') ?: '';
if (PHP_SAPI !== 'cli' || getenv('HHX_ALLOW_TEST_FIXTURES') !== '1' || !$configFile || !is_file($configFile) || getenv('HHX_PAYMENT_VAULT_KEY') !== 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=') throw new RuntimeException('Explicit local fixture and test-only vault key required.');
$config = require $configFile; $db=$config['db'] ?? [];
if (!preg_match('/^hhx_test_[a-z0-9_]+$/', (string)($db['database'] ?? '')) || !in_array((string)($db['host'] ?? ''), ['localhost','127.0.0.1'], true)) throw new RuntimeException('Refusing nonlocal/non-test database.');
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Core/Config.php'; \App\Core\Config::load($configFile);
require BASE_PATH . '/app/Core/Bootstrap.php'; \App\Core\Bootstrap::init(BASE_PATH);

use App\Core\DB;
use App\Core\Migrator;
use App\Services\AdvertisingFinanceService as Finance;
use App\Services\AdvertisingOnlinePaymentService as Online;
use App\Services\PaymentGatewayClient as Client;
use App\Services\PaymentGatewaySettings as Settings;

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException('FAIL: '.$message); }
function mustReject(callable $f,string $message):void { try {$f();} catch (Throwable $e) {return;} throw new RuntimeException('FAIL: '.$message); }
function fixtureKey():string {return bin2hex(random_bytes(32));}
function contract():int {return Finance::createContract(['request_key'=>fixtureKey(),'title'=>'قرارداد تست','client_name'=>'مشتری تست','client_phone'=>'','amount_toman'=>'100000','notes'=>''],1)['id'];}
function version(int $id):int {return (int)DB::fetchOne('SELECT version FROM advertising_contracts WHERE id=:id',['id'=>$id])['version'];}
function row(int $id):array {return DB::fetchOne('SELECT * FROM advertising_online_payments WHERE id=:id',['id'=>$id]);}
function settingVersion(Settings $settings,string $provider):int { foreach($settings->statuses() as $s) if($s['id']===$provider)return (int)$s['version']; throw new RuntimeException('provider missing'); }
function fixtureLink(Online $service,int $contract,string $provider,int $amount=20000):array {
    $token=fixtureKey();$payload=['contract_id'=>$contract,'amount_toman'=>(string)$amount,'provider'=>$provider,'hours'=>24,'version'=>version($contract),'request_key'=>fixtureKey(),'link_token'=>$token,'confirmed'=>true];
    return [$token,$payload,$service->createLink($payload,1)];
}
Migrator::runFeatureUpdate(); Migrator::runAdvertisingUpdate();
check(Finance::schemaReady(),'fixture schema');
$settings=new Settings(BASE_PATH);
$settings->save('zibal','sandbox','zibal',settingVersion($settings,'zibal'),false,1);
$settings->save('aqayepardakht','sandbox','sandbox',settingVersion($settings,'aqayepardakht'),false,1);
$settings->save('idpay','sandbox','synthetic-idpay-key',settingVersion($settings,'idpay'),false,1);
$statuses=json_encode($settings->statuses(),JSON_UNESCAPED_UNICODE);
check(!str_contains($statuses,'synthetic-idpay-key') && !str_contains($statuses,'secret_sealed'),'settings never return secret');
mustReject(static fn()=> $settings->save('zibal','live','live-fake',settingVersion($settings,'zibal'),false,1),'live setting requires explicit consent');
mustReject(static fn()=> $settings->save('idpay','sandbox','changed-fake-key',0,false,1),'stale settings version must fail');

// All responses below come from this closure; it cannot make network requests.
$createCount=0;$verifyCount=0;$requests=[];$cases=[];$scenario='success';
$sender=static function(array $spec) use (&$createCount,&$verifyCount,&$requests,&$cases,&$scenario):array {
    $requests[]=$spec;
    if (str_ends_with($spec['url'],'/request') || str_ends_with($spec['url'],'/create') || $spec['url']==='https://api.idpay.ir/v1.1/payment') {
        $createCount++;
        if($scenario==='create_timeout')throw new RuntimeException('synthetic timeout');
        $provider=str_contains($spec['url'],'zibal')?'zibal':(str_contains($spec['url'],'aqayepardakht')?'aqayepardakht':'idpay');
        $order=$spec['body']['orderId'] ?? $spec['body']['invoice_id'] ?? $spec['body']['order_id'];
        $raw=$spec['body']['amount'];
        if($provider==='zibal') {$id=(string)random_int(1000000000000,9999999999999);$reply=['http'=>200,'body'=>['result'=>100,'trackId'=>(int)$id]];}
        elseif($provider==='aqayepardakht') {$id='t'.bin2hex(random_bytes(10));$reply=['http'=>200,'body'=>['status'=>'success','transid'=>$id]];}
        else {$id=bin2hex(random_bytes(16));$reply=['http'=>201,'body'=>['id'=>$id,'link'=>Client::startUrl('idpay','sandbox',$id)]];}
        $cases[$id]=['provider'=>$provider,'order'=>$order,'amount'=>$raw,'reference'=>'r'.bin2hex(random_bytes(10))];return $reply;
    }
    $verifyCount++;
    $id=(string)($spec['body']['trackId'] ?? $spec['body']['transid'] ?? $spec['body']['id']);
    $info=$cases[$id] ?? null;if(!$info)throw new RuntimeException('unknown synthetic id');
    if($scenario==='verify_timeout')throw new RuntimeException('synthetic timeout');
    if($scenario==='already')return ['http'=>200,'body'=>['result'=>201]];
    if($info['provider']==='zibal')return ['http'=>200,'body'=>['result'=>100,'status'=>1,'amount'=>$info['amount']+($scenario==='mismatch'?10:0),'orderId'=>$info['order'],'refNumber'=>$info['reference']]];
    if($info['provider']==='aqayepardakht')return ['http'=>200,'body'=>['status'=>'success','code'=>'1']];
    return ['http'=>200,'body'=>['status'=>100,'id'=>$id,'order_id'=>$info['order'],'amount'=>$info['amount'],'track_id'=>$info['reference']]];
};
$service=new Online($settings,new Client($sender),false); // local fake sender, exercise dormant live accounting

// Sandbox snapshot remains immutable even if the provider is disabled afterward.
$c=contract();[$token,$payload,$created]=fixtureLink($service,$c,'zibal');$id=(int)$created['id'];
$replay=$service->createLink($payload,1);check($replay['replayed']&&$replay['id']===$id,'link request replay');
$bad=$payload;$bad['amount_toman']='20001';mustReject(static fn()=> $service->createLink($bad,1),'changed payload cannot reuse key');
$p=row($id);check($p['link_hash']===hash('sha256',$token)&&$p['link_hash']!==$token&&!str_contains($p['secret_sealed'],'zibal'),'token hash and encrypted snapshot');
$settings->save('zibal','disabled','',settingVersion($settings,'zibal'),false,1);
$url=$service->checkout($token);$p=row($id);$pid=$p['provider_id'];
check(str_starts_with($url,'https://gateway.zibal.ir/start/') && $p['state']==='pending','session created once');
check(end($requests)['body']['merchant']==='zibal','attempt uses original encrypted credential snapshot');
$before=$createCount;check($service->checkout($token)===$url&&$createCount===$before,'pending checkout resumes same session');
$before=$verifyCount;check($service->callback($token,['trackId'=>'wrong','success'=>'1'])==='unconfirmed'&&$verifyCount===$before,'forged callback cannot trigger verify');
check($service->callback($token,['trackId'=>$pid,'success'=>'1'])==='sandbox_verified','sandbox callback verified');
check(Finance::paidAmount($c)===0,'sandbox never credits real balance');
$before=$verifyCount;check($service->callback($token,['trackId'=>$pid,'success'=>'1'])==='sandbox_verified'&&$verifyCount===$before,'callback replay is idempotent');

// Other supported providers follow the same server-verified, sandbox-only accounting.
foreach (['aqayepardakht','idpay'] as $provider) {
    $c2=contract();[$t,,$result]=fixtureLink($service,$c2,$provider);$service->checkout($t);$p=row((int)$result['id']);$pid=$p['provider_id'];
    $cb=$provider==='aqayepardakht'?['transid'=>$pid,'invoice_id'=>$p['order_id'],'status'=>'1']:['id'=>$pid,'order_id'=>$p['order_id'],'status'=>'10'];
    $before=$verifyCount;$forged=$cb;$forged['status']='0';check($service->callback($t,$forged)==='unconfirmed'&&$verifyCount===$before,'invalid '.$provider.' callback');
    check($service->callback($t,$cb)==='sandbox_verified'&&Finance::paidAmount($c2)===0,'sandbox '.$provider);
}

// Live mode here only means a fake fixture; the sender is still entirely local.
$settings->save('zibal','live','synthetic-live-merchant',settingVersion($settings,'zibal'),true,1);
$c3=contract();[$t,,$result]=fixtureLink($service,$c3,'zibal',30000);$service->checkout($t);$p=row((int)$result['id']);$pid=$p['provider_id'];$ref=$cases[$pid]['reference'];
check($service->callback($t,['trackId'=>$pid,'success'=>'1'])==='verified'&&Finance::paidAmount($c3)===30000,'fake live server verification credits once');
check($service->callback($t,['trackId'=>$pid,'success'=>'1'])==='verified'&&Finance::paidAmount($c3)===30000,'fake live callback replay');
$c4=contract();mustReject(static fn()=>Finance::recordManual(['contract_id'=>$c4,'amount_toman'=>'1000','method'=>'bank_transfer','reference'=>$ref,'notes'=>'','request_key'=>fixtureKey(),'confirmed'=>true],1),'online reference cannot be reused as manual');

// Experimental HTTP handlers construct the service in sandbox-only mode.
// Even a pre-existing live setting/link must be rejected before transport or mutation.
$limited=new Online($settings,new Client($sender));$liveFixture=contract();[$liveToken,$livePayload,$liveLink]=fixtureLink($service,$liveFixture,'zibal');$before=$createCount+$verifyCount;
mustReject(static fn()=>$limited->createLink($livePayload,1),'sandbox cannot replay live link');
mustReject(static fn()=>$limited->checkout($liveToken),'sandbox cannot start existing live link');
mustReject(static fn()=>$limited->callback($liveToken,['trackId'=>'1','success'=>'1']),'sandbox cannot process live callback');
mustReject(static fn()=>Online::resolve(['payment_id'=>(int)$liveLink['id'],'contract_id'=>$liveFixture,'resolution'=>'cancel_link','reason'=>'دلیل آزمایشی برای لغو','confirmed'=>true],1),'sandbox default cannot resolve live attempt');
$liveNew=contract();mustReject(static fn()=>fixtureLink($limited,$liveNew,'zibal'),'sandbox cannot create with live snapshot');
check($createCount+$verifyCount===$before && row((int)$liveLink['id'])['state']==='link_ready','blocked live calls make no provider requests or state changes');

// Mismatch and timeout never auto-retry and block further mutations until explicit investigation.
$c5=contract();[$t,,$result]=fixtureLink($service,$c5,'zibal');$service->checkout($t);$p=row((int)$result['id']);$pid=$p['provider_id'];$scenario='mismatch';
check($service->callback($t,['trackId'=>$pid,'success'=>'1'])==='uncertain' && Finance::paidAmount($c5)===0,'amount mismatch unresolved');
mustReject(static fn()=>Finance::recordManual(['contract_id'=>$c5,'amount_toman'=>'1000','method'=>'cash','reference'=>'fixture-blocked-'.fixtureKey(),'notes'=>'','request_key'=>fixtureKey(),'confirmed'=>true],1),'unresolved blocks manual');
mustReject(static fn()=>Online::resolve(['payment_id'=>(int)$result['id'],'contract_id'=>$c5,'resolution'=>'not_paid','reason'=>'بررسی مستقل درگاه انجام شد','confirmed'=>true],1,false),'reconciliation requires explicit attestations');
Online::resolve(['payment_id'=>(int)$result['id'],'contract_id'=>$c5,'resolution'=>'not_paid','reason'=>'بررسی مستقل درگاه انجام شد','confirmed'=>true,'checked_provider'=>true,'no_future_charge_confirmed'=>true],1,false);
check(row((int)$result['id'])['state']==='cancelled','audited not-paid assertion');

$scenario='success';$c6=contract();[$t,,$result]=fixtureLink($service,$c6,'zibal');$service->checkout($t);$p=row((int)$result['id']);$scenario='already';
check($service->callback($t,['trackId'=>$p['provider_id'],'success'=>'1'])==='uncertain','previously verified result needs review');
Online::resolve(['payment_id'=>(int)$result['id'],'contract_id'=>$c6,'resolution'=>'paid','reason'=>'بررسی مستقل شماره پیگیری','reference'=>'audit'.bin2hex(random_bytes(8)),'confirmed'=>true,'checked_provider'=>true,'no_future_charge_confirmed'=>true],1,false);
check(row((int)$result['id'])['state']==='reconciled'&&Finance::paidAmount($c6)===20000,'human assertion distinguished from gateway verify');
mustReject(static fn()=>Online::resolve(['payment_id'=>(int)$result['id'],'contract_id'=>$c6,'resolution'=>'paid','reason'=>'بررسی مستقل شماره پیگیری','reference'=>'another-ref','confirmed'=>true,'checked_provider'=>true,'no_future_charge_confirmed'=>true],1,false),'cannot reconcile twice');

$scenario='success';$c7=contract();[$t,,$result]=fixtureLink($service,$c7,'zibal');$scenario='create_timeout';
mustReject(static fn()=>$service->checkout($t),'ambiguous creation');$before=$createCount;
mustReject(static fn()=>$service->checkout($t),'no new session after timeout');check($createCount===$before&&row((int)$result['id'])['state']==='uncertain','no automatic retry after timeout');
Online::resolve(['payment_id'=>(int)$result['id'],'contract_id'=>$c7,'resolution'=>'not_paid','reason'=>'بررسی درگاه و عدم پرداخت','confirmed'=>true,'checked_provider'=>true,'no_future_charge_confirmed'=>true],1,false);
$manual=Finance::recordManual(['contract_id'=>$c7,'amount_toman'=>'1000','method'=>'cash','reference'=>'manual'.fixtureKey(),'notes'=>'','request_key'=>fixtureKey(),'confirmed'=>true],1);
check($manual['status']==='recorded','manual allowed only after reconciliation');

$scenario='success';$c9=contract();[$t,,$result]=fixtureLink($service,$c9,'zibal');$service->checkout($t);$p=row((int)$result['id']);$scenario='verify_timeout';
check($service->callback($t,['trackId'=>$p['provider_id'],'success'=>'1'])==='uncertain' && Finance::paidAmount($c9)===0,'verification timeout remains unresolved');
$before=$verifyCount;check($service->callback($t,['trackId'=>$p['provider_id'],'success'=>'1'])==='unconfirmed'&&$verifyCount===$before,'ambiguous verification never retried automatically');
Online::resolve(['payment_id'=>(int)$result['id'],'contract_id'=>$c9,'resolution'=>'not_paid','reason'=>'بررسی درگاه و عدم پرداخت','confirmed'=>true,'checked_provider'=>true,'no_future_charge_confirmed'=>true],1,false);

// A never-started expired capability is the only attempt auto-cancelled.
$scenario='success';$c8=contract();[$t,,$result]=fixtureLink($service,$c8,'zibal');DB::query('UPDATE advertising_online_payments SET expires_at=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id=:id',['id'=>$result['id']]);
[$t2,,$new]=fixtureLink($service,$c8,'zibal');check(row((int)$result['id'])['state']==='cancelled'&&row((int)$new['id'])['state']==='link_ready','expired unused link audited before new one');
$audit=DB::fetchOne("SELECT id FROM advertising_online_audit WHERE payment_id=:id AND reason='link_expired'",['id'=>$result['id']]);check($audit!==null,'expiry audit');
Online::resolve(['payment_id'=>(int)$new['id'],'contract_id'=>$c8,'resolution'=>'cancel_link','reason'=>'لغو لینک استفاده نشده','confirmed'=>true],1,false);
check(row((int)$new['id'])['state']==='cancelled','explicit cancel of unused link');

echo "PASS: synthetic encrypted snapshots, mocked gateways, callback forgery/replay, live-vs-sandbox accounting, mismatch/timeout/reconciliation, receipt uniqueness and expiry. No network.\n";
