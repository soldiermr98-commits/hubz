<?php
declare(strict_types=1);

// Pure protocol and encryption checks: synthetic values and an injected sender only.
// Load the few classes directly: Bootstrap would read the site's local config,
// which this offline test does not need and must not touch.
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only.');
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Services/PaymentVault.php';
require BASE_PATH . '/app/Services/PaymentGatewaySettings.php';
require BASE_PATH . '/app/Services/PaymentGatewayClient.php';

use App\Services\PaymentGatewayClient as Client;
use App\Services\PaymentVault;

function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL: ' . $label); }
function reject(callable $run, string $label): void { try { $run(); } catch (Throwable $e) { return; } throw new RuntimeException('FAIL: ' . $label); }
set_error_handler(static function(int $no, string $message): never { throw new ErrorException($message, 0, $no); });

$master = random_bytes(32); $sealed = PaymentVault::seal('zibal', 'synthetic-secret', $master);
check(PaymentVault::open('zibal', $sealed, $master) === 'synthetic-secret', 'vault round trip');
reject(static fn()=>PaymentVault::open('idpay', $sealed, $master), 'ciphertext bound to provider');
$bad=$sealed;$bad['tag']=base64_encode(random_bytes(16));
reject(static fn()=>PaymentVault::open('zibal', $bad, $master), 'tampered tag');
reject(static fn()=>PaymentVault::open('zibal', $sealed, random_bytes(32)), 'wrong master');

$order='adfixture123456';$callback='https://fixture.invalid/callback/abc';$amount=12500;
$z=Client::createSpec('zibal','sandbox','zibal',$amount,$order,$callback);
check($z['url']==='https://gateway.zibal.ir/v1/request' && $z['body']['amount']===$amount*10 && $z['body']['orderId']===$order, 'zibal rial and order');
$a=Client::createSpec('aqayepardakht','sandbox','sandbox',$amount,$order,$callback);
check($a['url']==='https://panel.aqayepardakht.ir/api/v2/create' && $a['body']['amount']===$amount && $a['body']['invoice_id']===$order && $a['body']['callback_method']==='GET', 'aqaye toman and invoice');
$i=Client::createSpec('idpay','sandbox','synthetic-key',$amount,$order,$callback);
check($i['url']==='https://api.idpay.ir/v1.1/payment' && $i['body']['amount']===$amount*10 && in_array('X-SANDBOX: 1',$i['headers'],true), 'idpay rial and sandbox');
reject(static fn()=>Client::createSpec('zarinpal','live','fake',$amount,$order,$callback),'unsupported provider');
reject(static fn()=>Client::createSpec('zibal','live','zibal',$amount,$order,$callback),'test merchant cannot be live');
reject(static fn()=>Client::createSpec('aqayepardakht','live','sandbox',$amount,$order,$callback),'sandbox pin cannot be live');
reject(static fn()=>Client::createSpec('zibal','sandbox','zibal',$amount,$order,'http://fixture.invalid/callback'),'callback must be HTTPS');
reject(static fn()=>Client::createSpec('aqayepardakht','sandbox','sandbox',999,$order,$callback),'minimum amount');

$zid='123456789';$aid='synthetic-trans-01';$iid='a123456789012345678901234567890b';
check(Client::parseCreated('zibal','sandbox',['http'=>200,'body'=>['result'=>100,'trackId'=>(int)$zid]])['provider_id']===$zid,'zibal create');
check(Client::parseCreated('aqayepardakht','sandbox',['http'=>200,'body'=>['status'=>'success','transid'=>$aid]])['provider_id']===$aid,'aqaye create');
$link=Client::startUrl('idpay','sandbox',$iid);
check(Client::parseCreated('idpay','sandbox',['http'=>201,'body'=>['id'=>$iid,'link'=>$link]])['provider_id']===$iid,'idpay create');
reject(static fn()=>Client::parseCreated('idpay','sandbox',['http'=>201,'body'=>['id'=>$iid,'link'=>'https://attacker.invalid/']]), 'idpay arbitrary redirect');
reject(static fn()=>Client::parseCreated('zibal','sandbox',['http'=>200,'body'=>['result'=>100,'trackId'=>'0/evil']]),'invalid provider ID');
reject(static fn()=>Client::parseCreated('zibal','sandbox',['http'=>200,'body'=>['result'=>[100],'trackId'=>[]]]),'malformed response without PHP warnings');

$zb=['result'=>100,'status'=>1,'amount'=>$amount*10,'orderId'=>$order,'refNumber'=>'ref-zibal-1'];
check(Client::parseVerified('zibal',$amount,$order,$zid,['http'=>200,'body'=>$zb])['verified'],'zibal verified');
foreach (['amount','orderId','status'] as $field) {
    $bad=$zb;$bad[$field]=$field==='amount' ? $amount : ($field==='status' ? 2 : 'other');
    check(!Client::parseVerified('zibal',$amount,$order,$zid,['http'=>200,'body'=>$bad])['verified'],'zibal mismatch '.$field);
}
check(Client::parseVerified('zibal',$amount,$order,$zid,['http'=>200,'body'=>['result'=>201]])['error']==='already_verified','previously verified is unresolved');
check(Client::parseVerified('aqayepardakht',$amount,$order,$aid,['http'=>200,'body'=>['status'=>'success','code'=>'1']])['verified'],'aqaye verify');
check(Client::parseVerified('aqayepardakht',$amount,$order,$aid,['http'=>200,'body'=>['status'=>'error','code'=>'2']])['error']==='already_verified','aqaye duplicate unresolved');
$ib=['status'=>100,'id'=>$iid,'order_id'=>$order,'amount'=>$amount*10,'track_id'=>'track-321'];
check(Client::parseVerified('idpay',$amount,$order,$iid,['http'=>200,'body'=>$ib])['verified'],'idpay verify');
foreach (['id','order_id','amount','track_id'] as $field) {
    $bad=$ib;$bad[$field]=$field==='amount'?$amount:'wrong';if($field==='track_id')$bad[$field]='';
    check(!Client::parseVerified('idpay',$amount,$order,$iid,['http'=>200,'body'=>$bad])['verified'],'idpay mismatch '.$field);
}
check(Client::parseVerified('idpay',$amount,$order,$iid,['http'=>200,'body'=>['status'=>101]])['error']==='already_verified','idpay duplicate unresolved');
foreach (['zibal','idpay','aqayepardakht'] as $provider) check(!Client::parseVerified($provider,$amount,$order,$zid,['http'=>200,'body'=>['result'=>[], 'status'=>[], 'code'=>[]]])['verified'],'malformed '.$provider);

$calls=[];$client=new Client(static function(array $spec) use (&$calls,$zid,$zb):array { $calls[]=$spec['url'];return str_ends_with($spec['url'],'/request') ? ['http'=>200,'body'=>['result'=>100,'trackId'=>(int)$zid]] : ['http'=>200,'body'=>$zb]; });
$created=$client->create('zibal','sandbox','zibal',$amount,$order,$callback);
$verified=$client->verify('zibal','sandbox','zibal',$amount,$order,$created['provider_id']);
check($verified['verified'] && count($calls)===2,'injectable fake transport');
echo "PASS: synthetic gateway protocols, toman/rial conversions, fixed redirects, strict verification, ambiguous duplicates and encrypted vault. No network calls.\n";
