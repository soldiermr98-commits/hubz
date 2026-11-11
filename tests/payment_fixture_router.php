<?php
declare(strict_types=1);

// Test-only cURL replacement: no provider network is contacted. The wrapped
// fixture router refuses anything except a local disposable hhx_test_* DB.
namespace App\Services {
    function curl_init(string $url): object|false {
        if (!in_array($url,['https://gateway.zibal.ir/v1/request','https://gateway.zibal.ir/v1/verify'],true)) return false;
        return (object)['url'=>$url,'options'=>[],'status'=>0];
    }
    function curl_setopt_array(object $curl,array $options):bool {$curl->options=$options;return true;}
    function curl_setopt(object $curl,int $option,mixed $value):bool {$curl->options[$option]=$value;return true;}
    function curl_exec(object $curl):bool {
        $body=json_decode((string)($curl->options[CURLOPT_POSTFIELDS]??''),true);
        if(!is_array($body)||($body['merchant']??'')!=='zibal')return false;
        if(str_ends_with($curl->url,'/request')){
            if(!is_int($body['amount']??null)||!is_string($body['orderId']??null)||!str_starts_with((string)($body['callbackUrl']??''),'https://fixture.invalid/'))return false;
            $response=['result'=>100,'trackId'=>random_int(1000000000000,9999999999999)];
        } else {
            $id=(string)($body['trackId']??'');
            $p=\App\Core\DB::fetchOne("SELECT order_id,amount_toman FROM advertising_online_payments WHERE provider='zibal' AND mode='sandbox' AND provider_id=:id",['id'=>$id]);
            if(!$p)return false;
            $response=['result'=>100,'status'=>1,'amount'=>(int)$p['amount_toman']*10,'orderId'=>$p['order_id'],'refNumber'=>'fixture-'.$p['order_id']];
        }
        $curl->status=200;$callback=$curl->options[CURLOPT_WRITEFUNCTION]??null;$chunk=json_encode($response);
        return is_callable($callback)&&is_string($chunk)&&$callback($curl,$chunk)===strlen($chunk);
    }
    function curl_getinfo(object $curl,int $option):int{return $curl->status;}
    function curl_close(object $curl):void {}
}
namespace {
    // Test-only untrusted peer simulation for the nonce-guarded fixture.
    $nonce=getenv('HHX_TEST_NONCE')?:'';$peer=(string)($_SERVER['HTTP_X_HHX_TEST_REMOTE_NONCE']??'');
    if(PHP_SAPI==='cli-server'&&getenv('HHX_ALLOW_TEST_FIXTURES')==='1'&&strlen($nonce)>=16&&$peer!==''&&hash_equals($nonce,$peer))$_SERVER['REMOTE_ADDR']='198.51.100.17';
    define('HHX_TEST_FAKE_PAYMENT_TRANSPORT',true);
    require __DIR__.'/article_fixture_router.php';
}
