<?php
declare(strict_types=1);

// Read-only check of synthetic encrypted settings, guarded to local test DBs.
$configFile=getenv('HHX_TEST_CONFIG') ?: '';
if (PHP_SAPI!=='cli' || getenv('HHX_ALLOW_TEST_FIXTURES')!=='1' || !is_file($configFile)) throw new RuntimeException('Fixture guard missing.');
$config=require $configFile;
$db=is_array($config)?($config['db']??[]):[];
if (!preg_match('/^hhx_test_[a-z0-9_]+$/',(string)($db['database']??'')) || !in_array((string)($db['host']??''),['localhost','127.0.0.1'],true)) throw new RuntimeException('Refusing non-test database.');
define('BASE_PATH',dirname(__DIR__));
require BASE_PATH.'/app/Core/Config.php'; \App\Core\Config::load($configFile);
require BASE_PATH.'/app/Core/Bootstrap.php'; \App\Core\Bootstrap::init(BASE_PATH);
foreach (['gemini','nvidia'] as $id) {
    $row=\App\Core\DB::fetchOne('SELECT v FROM settings WHERE k=:k',['k'=>'ai_provider_v1_'.$id]);
    if ($id==='nvidia' && $row===null) continue; // first inspection occurs before NVIDIA is saved
    $value=(string)($row['v']??'');
    foreach (['synthetic-http-key','synthetic-replacement-key','synthetic-nvidia-key','synthetic-nvidia-replacement'] as $key) {
        if ($value==='' || str_contains($value,$key)) throw new RuntimeException('Key is missing or stored as plaintext.');
    }
    $state=json_decode($value,true);
    if (!is_array($state) || !is_array($state['secret']??null) || empty($state['secret']['nonce']) || empty($state['secret']['ciphertext']) || empty($state['secret']['tag'])) throw new RuntimeException('Encrypted record is incomplete.');
}
echo "PASS: real test DB contains encrypted provider settings, not synthetic plaintext keys.\n";
