<?php
declare(strict_types=1);
// Worker used only by the guarded concurrency smoke test; all gateway responses are fake.
$configFile=getenv('HHX_TEST_CONFIG') ?: '';
if (PHP_SAPI !== 'cli' || getenv('HHX_ALLOW_TEST_FIXTURES')!=='1' || !$configFile || !is_file($configFile) || getenv('HHX_PAYMENT_VAULT_KEY')!=='AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=') throw new RuntimeException('Fixture guards required.');
$config=require $configFile;$db=$config['db']??[];
if(!preg_match('/^hhx_test_[a-z0-9_]+$/',(string)($db['database']??''))||!in_array((string)($db['host']??''),['localhost','127.0.0.1'],true))throw new RuntimeException('Refusing nonlocal/non-test DB.');
define('BASE_PATH',dirname(__DIR__));require BASE_PATH.'/app/Core/Config.php';\App\Core\Config::load($configFile);require BASE_PATH.'/app/Core/Bootstrap.php';\App\Core\Bootstrap::init(BASE_PATH);
use App\Core\DB;
use App\Services\AdvertisingFinanceService as Finance;
use App\Services\AdvertisingOnlinePaymentService as Online;
use App\Services\PaymentGatewayClient as Gateway;
use App\Services\PaymentGatewaySettings as Settings;
$input=json_decode(stream_get_contents(STDIN),true);if(!is_array($input))throw new RuntimeException('invalid fixture input');
$key=static fn():string=>bin2hex(random_bytes(32));
$create=static fn():int=>Finance::createContract(['request_key'=>$key(),'title'=>'concurrency fixture','client_name'=>'fixture','client_phone'=>'','amount_toman'=>'100000','notes'=>''],1)['id'];
$settings=new Settings(BASE_PATH);
try {
    $action=$input['action']??'';
    if($action==='setup'){
        foreach($settings->statuses() as $s)if($s['id']==='zibal')$version=(int)$s['version'];
        $settings->save('zibal','live','fake-concurrency-merchant',$version??0,true,1);
        $one=$create();$two=$create();$three=$create();$four=$create();$five=$create();$six=$create();$seven=$create();$eight=$create();$token=$key();
        $client=new Gateway(static fn(array $spec):array=>['http'=>200,'body'=>['result'=>100,'trackId'=>random_int(1000000000000,9999999999999)]]);
        $service=new Online($settings,$client,false);
        $p=$service->createLink(['contract_id'=>$four,'amount_toman'=>'30000','provider'=>'zibal','hours'=>24,'version'=>1,'request_key'=>$key(),'link_token'=>$token,'confirmed'=>true],1);
        $service->checkout($token);
        $r=DB::fetchOne('SELECT provider_id,order_id FROM advertising_online_payments WHERE id=:id',['id'=>$p['id']]);
        $token2=$key();$p2=$service->createLink(['contract_id'=>$seven,'amount_toman'=>'30000','provider'=>'zibal','hours'=>24,'version'=>1,'request_key'=>$key(),'link_token'=>$token2,'confirmed'=>true],1);$service->checkout($token2);
        $r2=DB::fetchOne('SELECT provider_id,order_id FROM advertising_online_payments WHERE id=:id',['id'=>$p2['id']]);
        echo json_encode(['one'=>$one,'two'=>$two,'three'=>$three,'four'=>$four,'five'=>$five,'six'=>$six,'seven'=>$seven,'eight'=>$eight,'token'=>$token,'payment_id'=>$p['id'],'provider_id'=>$r['provider_id'],'order_id'=>$r['order_id'],'token2'=>$token2,'payment_id2'=>$p2['id'],'provider_id2'=>$r2['provider_id'],'order_id2'=>$r2['order_id']]);exit;
    }
    if(isset($input['at']))while(microtime(true)<(float)$input['at'])usleep(1000);
    if($action==='manual'){
        $result=Finance::recordManual(['contract_id'=>$input['contract'],'amount_toman'=>$input['amount'],'method'=>'bank_transfer','reference'=>$input['reference'],'notes'=>'','request_key'=>$input['key'],'confirmed'=>true],1);
        echo json_encode(['ok'=>true,'result'=>$result]);exit;
    }
    if($action==='link'){
        $service=new Online($settings,new Gateway(static function():array {throw new RuntimeException('Unexpected network');}),false);
        $result=$service->createLink(['contract_id'=>$input['contract'],'amount_toman'=>'60000','provider'=>'zibal','hours'=>24,'version'=>1,'request_key'=>$input['key'],'link_token'=>$input['token'],'confirmed'=>true],1);
        echo json_encode(['ok'=>true,'result'=>$result]);exit;
    }
    if($action==='callback'){
        $provider=(string)$input['provider_id'];$order=(string)$input['order_id'];
        $client=new Gateway(static function(array $spec) use($order):array {usleep(500000);return ['http'=>200,'body'=>['result'=>100,'status'=>1,'amount'=>300000,'orderId'=>$order,'refNumber'=>'ref-'.$order]];});
        $service=new Online($settings,$client,false);$state=$service->callback($input['token'],['trackId'=>$provider,'success'=>'1']);
        echo json_encode(['ok'=>true,'state'=>$state]);exit;
    }
    if($action==='inspect'){
        $contracts=[];foreach($input['contracts'] as $id)$contracts[(string)$id]=['paid'=>Finance::paidAmount((int)$id),'open'=>Finance::hasUnresolved((int)$id)];
        $p=DB::fetchOne('SELECT state FROM advertising_online_payments WHERE id=:id',['id'=>$input['payment_id']]);
        $audit=DB::fetchOne("SELECT COUNT(*) AS n FROM advertising_online_audit WHERE payment_id=:id AND reason='verification_started'",['id'=>$input['payment_id']]);
        $p2=isset($input['payment_id2'])?DB::fetchOne('SELECT state FROM advertising_online_payments WHERE id=:id',['id'=>$input['payment_id2']]):null;
        echo json_encode(['contracts'=>$contracts,'payment_state'=>$p['state'],'payment_state2'=>$p2['state']??null,'verify_started'=>(int)$audit['n']]);exit;
    }
    throw new RuntimeException('Unknown test action.');
} catch(Throwable $e) {
    if(DB::connection()->inTransaction())DB::connection()->rollBack();
    echo json_encode(['ok'=>false,'type'=>get_class($e),'code'=>$e->getCode()]);
}
