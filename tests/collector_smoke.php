<?php
declare(strict_types=1);

// This test writes fixtures ONLY in a disposable database and rolls them back.
// It refuses the production config even if the caller accidentally points to it.
$configFile = getenv('HHX_TEST_CONFIG') ?: '';
if (getenv('HHX_ALLOW_TEST_FIXTURES') !== '1' || !$configFile || !is_file($configFile)) {
    fwrite(STDERR, "Use HHX_ALLOW_TEST_FIXTURES=1 and HHX_TEST_CONFIG=/path/to/test-only-config.php\n"); exit(2);
}
$config = require $configFile;
$database = is_array($config) ? (string)($config['db']['database'] ?? '') : '';
if (!preg_match('/^hhx_test_[a-z0-9_]+$/', $database)) {
    fwrite(STDERR, "Refusing database: a disposable hhx_test_* database is required.\n"); exit(2);
}
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Core/Config.php';
\App\Core\Config::load($configFile);
require BASE_PATH . '/app/Core/Bootstrap.php';
\App\Core\Bootstrap::init(BASE_PATH);
require BASE_PATH . '/collector-remote/Providers/ProviderInterface.php';
require BASE_PATH . '/collector-remote/Providers/OsmProvider.php';

use App\Core\DB;
use App\Services\PipelineService;
use CollectorRemote\Providers\OsmProvider;

function check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: {$label}\n";
}
$pdo = DB::connection();
$pdo->beginTransaction();
try {
    check((bool)DB::fetchOne('SELECT id FROM cities WHERE id=1') && (bool)DB::fetchOne('SELECT id FROM categories WHERE id=1'), 'test database has migrations and seed');
    $suffix = bin2hex(random_bytes(6));
    $name = 'Isolated Fixture ' . $suffix;
    $items = OsmProvider::mapElements([
        ['type'=>'node','id'=>71001,'lat'=>37.28,'lon'=>49.58,'tags'=>['name'=>$name,'phone'=>'123','addr:full'=>'Fixture A']],
        ['type'=>'node','id'=>71002,'lat'=>37.33,'lon'=>49.63,'tags'=>['name'=>$name,'addr:full'=>'Fixture B']],
        ['type'=>'node','id'=>71003,'tags'=>['amenity'=>'cafe']],
    ]);
    check(count($items) === 2 && $items[0]['rating'] === null && str_starts_with($items[0]['source_url'], 'https://www.openstreetmap.org/'), 'OSM mapping uses supplied facts only');
    // Ensure globally unique source IDs when multiple smoke tests use the same DB.
    $items[0]['source_id'] .= '-' . $suffix; $items[1]['source_id'] .= '-' . $suffix;
    $one = PipelineService::processIncomingRecord(1, 1, 'osm', $items[0]);
    $two = PipelineService::processIncomingRecord(1, 1, 'osm', $items[1]);
    check($one['business_id'] !== $two['business_id'], 'far apart same-name businesses are distinct');
    $id = (int)$one['business_id'];
    check(DB::fetchOne('SELECT status FROM businesses WHERE id=:id', ['id'=>$id])['status'] === 'pending_review', 'new record requires manual approval');
    check(!DB::fetchOne('SELECT id FROM business_contacts WHERE business_id=:id', ['id'=>$id]), 'invalid phone not stored');
    $items[0]['address'] = 'Fixture changed';
    $again = PipelineService::processIncomingRecord(1, 1, 'osm', $items[0]);
    check($again['business_id'] === $id && !$again['is_new'], 'repeat source is deduplicated');
    check(DB::fetchOne('SELECT address FROM businesses WHERE id=:id', ['id'=>$id])['address'] === 'Fixture A', 'incoming change is not applied automatically');
    check((bool)DB::fetchOne("SELECT id FROM review_queue WHERE target_id=:id AND kind='change' AND status='pending'", ['id'=>$id]), 'incoming change queued for manual review');
    echo "All smoke checks passed; rolling fixtures back.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
