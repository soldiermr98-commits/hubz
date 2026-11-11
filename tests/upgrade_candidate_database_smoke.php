<?php
declare(strict_types=1);

// Two phases, on a synthetic .5-like tree BEFORE and AFTER overlaying the RC.
// Run setup_article_url_fixture.php first against an EMPTY disposable test DB.
// This script commits synthetic changes; never run it with real data.
$configFile = getenv('HHX_TEST_CONFIG') ?: '';
$phase = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || getenv('HHX_ALLOW_TEST_FIXTURES') !== '1' || !$configFile || !is_file($configFile) || !in_array($phase, ['baseline', 'upgraded'], true)) {
    throw new RuntimeException('Explicit test-fixture guard, local config and baseline/upgraded phase are required.');
}
$config = require $configFile;
$db = is_array($config) ? ($config['db'] ?? []) : [];
if (!preg_match('/^hhx_test_[a-z0-9_]+$/', (string)($db['database'] ?? '')) || !in_array((string)($db['host'] ?? ''), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Refusing non-local or non-test database.');
}
if (isset($config['advertising_payments']['experimental_sandbox']) && $config['advertising_payments']['experimental_sandbox'] === true) {
    throw new RuntimeException('This test requires the default-off sandbox configuration.');
}
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Core/Config.php';
\App\Core\Config::load($configFile);
require BASE_PATH . '/app/Core/Bootstrap.php';
\App\Core\Bootstrap::init(BASE_PATH);

use App\Core\DB;
use App\Core\Migrator;
use App\Core\PaymentSandbox;
use App\Services\AdvertisingFinanceService;

function upgradeCheck(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
}
function hasMigration(string $name): bool {
    return DB::fetchOne('SELECT migration FROM schema_migrations WHERE migration=:name', ['name'=>$name]) !== null;
}
function preservedState(): void {
    $article = DB::fetchOne("SELECT title,status,intro FROM articles WHERE slug='fixture-live'");
    $business = DB::fetchOne("SELECT status FROM businesses WHERE slug='fixture-cafe'");
    $auto = DB::fetchOne('SELECT enabled,task_id,next_run_at FROM apify_automation_settings WHERE id=1');
    $setting = DB::fetchOne("SELECT v FROM settings WHERE k='synthetic.upgrade.fixture'");
    upgradeCheck($article && $article['title'] === 'نسخه زنده آزمایشی' && $article['status'] === 'published' && $article['intro'] === 'متن زنده اصلی', 'published article retained');
    upgradeCheck($business && $business['status'] === 'published', 'published business retained');
    upgradeCheck($auto && (int)$auto['enabled'] === 1 && $auto['task_id'] === 'fixture-only-never-run' && $auto['next_run_at'] === '2035-01-01 00:00:00', 'prior automation settings retained');
    upgradeCheck($setting && $setting['v'] === 'synthetic-retained-value', 'prior setting retained');
    upgradeCheck(!hasMigration('003_manual_collection_defaults.sql'), '003 must remain unrecorded and unexecuted');
}

if ($phase === 'baseline') {
    upgradeCheck(!hasMigration('006_article_editor_city_urls.sql') && !hasMigration('007_ai_advertising_quality.sql'), 'fresh fixture before targeted updates');
    $article = Migrator::runArticleUpdate();
    $feature = Migrator::runFeatureUpdate();
    upgradeCheck($article['total_applied'] === 1 && $feature['total_applied'] === 1, 'old .5 targeted migrations');
    DB::query("INSERT INTO settings (k,v) VALUES ('synthetic.upgrade.fixture','synthetic-retained-value')");
    preservedState();
    echo "PASS: .5-like baseline prepared with targeted 006/007, synthetic published content and 003 deliberately absent.\n";
    exit;
}

upgradeCheck(hasMigration('006_article_editor_city_urls.sql') && hasMigration('007_ai_advertising_quality.sql'), 'expected old baseline');
foreach (['008_advertising_reporting_contracts.sql', '009_advertising_finance.sql', '010_advertising_receipt_uniqueness.sql'] as $name) {
    upgradeCheck(!hasMigration($name), 'fresh candidate migration ' . $name);
}
preservedState();
$blocked = false;
try { Migrator::runIncremental(); }
catch (RuntimeException $e) { $blocked = str_contains($e->getMessage(), '003'); }
upgradeCheck($blocked, 'general updater refuses unsafe missing 003');
preservedState();
$result = Migrator::runAdvertisingUpdate();
upgradeCheck($result['applied'] === ['008_advertising_reporting_contracts.sql', '009_advertising_finance.sql', '010_advertising_receipt_uniqueness.sql'], 'only targeted 008-010 applied in order');
upgradeCheck(Migrator::runAdvertisingUpdate()['total_applied'] === 0, 'targeted update is repeatable');
upgradeCheck(AdvertisingFinanceService::schemaReady(), 'finance schema ready');
upgradeCheck(!PaymentSandbox::enabled(), 'missing/false sandbox flag stays off');
preservedState();
echo "PASS: overlay targeted 008-010, repeat update, default-off sandbox and retained published content/settings; unsafe 003 was blocked.\n";
