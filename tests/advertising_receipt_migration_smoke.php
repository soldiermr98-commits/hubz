<?php
declare(strict_types=1);
// Exercises a legacy duplicate and additive backfill only in a disposable local DB.
$configFile=getenv('HHX_TEST_CONFIG') ?: '';
if(PHP_SAPI!=='cli'||getenv('HHX_ALLOW_TEST_FIXTURES')!=='1'||!$configFile||!is_file($configFile))throw new RuntimeException('Explicit fixture guard required.');
$config=require $configFile;$db=$config['db']??[];
if(!preg_match('/^hhx_test_[a-z0-9_]+$/',(string)($db['database']??''))||!in_array((string)($db['host']??''),['localhost','127.0.0.1'],true))throw new RuntimeException('Refusing nonlocal/non-test DB.');
define('BASE_PATH',dirname(__DIR__));require BASE_PATH.'/app/Core/Config.php';\App\Core\Config::load($configFile);require BASE_PATH.'/app/Core/Bootstrap.php';\App\Core\Bootstrap::init(BASE_PATH);
use App\Core\DB;use App\Core\Migrator;use App\Services\AdvertisingFinanceService as Finance;
function checkMigration(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);}
function fixtureKeyMigration():string{return bin2hex(random_bytes(32));}
Migrator::runFeatureUpdate();
// This specific test must start before 008/009/010, in a freshly seeded fixture DB.
foreach(['008_advertising_reporting_contracts.sql','009_advertising_finance.sql','010_advertising_receipt_uniqueness.sql'] as $name)checkMigration(!DB::fetchOne('SELECT migration FROM schema_migrations WHERE migration=:m',['m'=>$name]),'fresh fixture only');
foreach(['008_advertising_reporting_contracts.sql','009_advertising_finance.sql'] as $name){
    DB::connection()->exec((string)file_get_contents(BASE_PATH.'/database/migrations/'.$name));
    DB::query('INSERT INTO schema_migrations (migration) VALUES (:m)',['m'=>$name]);
}
$create=static fn():int=>Finance::createContract(['request_key'=>fixtureKeyMigration(),'title'=>'legacy fixture','client_name'=>'synthetic','amount_toman'=>'100000'],1)['id'];
$a=$create();$b=$create();$reference='fixture-'.fixtureKeyMigration().'-123';$alternate=strtoupper(substr($reference,0,-3)).'۱۲۳';
foreach([[$a,$reference],[$b,$alternate]] as [$contract,$ref])DB::query("INSERT INTO advertising_manual_payments (contract_id,amount_toman,method,reference,request_key,status,recorded_by) VALUES (:c,10000,'cash',:r,:k,'recorded',1)",['c'=>$contract,'r'=>$ref,'k'=>fixtureKeyMigration()]);
$failed=false;try{Migrator::runAdvertisingUpdate();}catch(RuntimeException $e){$failed=true;checkMigration(!str_contains($e->getMessage(),$reference),'no receipt in error');}
checkMigration($failed,'legacy live duplicate blocks upgrade');
checkMigration(!DB::fetchOne('SELECT migration FROM schema_migrations WHERE migration=:m',['m'=>'010_advertising_receipt_uniqueness.sql'])&&!Finance::schemaReady(),'incomplete migration is not ready');
$sql=(string)file_get_contents(BASE_PATH.'/database/migrations/010_advertising_receipt_uniqueness.sql');
checkMigration((bool)preg_match('/CREATE TABLE IF NOT EXISTS advertising_receipt_unique\s*\(.*?\) ENGINE=.*?;/s',$sql,$ddl),'test DDL');
DB::connection()->exec($ddl[0]);checkMigration(!Finance::schemaReady(),'table without completed backfill is not ready');
// Fixture-only accounting decision: void one duplicate, retaining its history and reference.
DB::query("UPDATE advertising_manual_payments SET status='voided',voided_by=1,voided_at=NOW(),void_reason='synthetic review' WHERE contract_id=:c",['c'=>$b]);
$result=Migrator::runAdvertisingUpdate();checkMigration($result['total_applied']===1&&Finance::schemaReady(),'backfill after duplicate is voided');
$r=DB::fetchOne("SELECT COUNT(*) AS n FROM advertising_receipt_unique WHERE namespace='live' AND reference=:r",['r'=>$reference]);checkMigration((int)$r['n']===1,'equivalent old references backfill one permanent reservation');
$again=Migrator::runAdvertisingUpdate();checkMigration($again['total_applied']===0,'migration repeatable');
$third=$create();$blocked=false;try{Finance::recordManual(['contract_id'=>$third,'amount_toman'=>'1000','method'=>'cash','reference'=>$alternate,'notes'=>'','request_key'=>fixtureKeyMigration(),'confirmed'=>true],1);}catch(Throwable $e){$blocked=true;}
checkMigration($blocked,'pre-migration receipt cannot be reused');
echo "PASS: existing active duplicate blocks migration, retained voided history backfills a permanent collation-aware claim, and migration is repeatable.\n";
