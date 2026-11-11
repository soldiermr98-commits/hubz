<?php
declare(strict_types=1);

// DESTRUCTIVE FIXTURE: creates tables and commits synthetic records. It must
// only run against a new, empty, disposable LOCAL hhx_test_* database. It
// never contacts a provider, a production database, or a scheduled cron.
$configFile = getenv('HHX_TEST_CONFIG') ?: '';
if (PHP_SAPI !== 'cli' || getenv('HHX_ALLOW_TEST_FIXTURES') !== '1' || !$configFile || !is_file($configFile)) {
    throw new RuntimeException('Explicit HHX_ALLOW_TEST_FIXTURES=1 and HHX_TEST_CONFIG are required.');
}
$config = require $configFile;
$db = is_array($config) ? ($config['db'] ?? []) : [];
if (!preg_match('/^hhx_test_[a-z0-9_]+$/D', (string)($db['database'] ?? ''))
    || !in_array((string)($db['host'] ?? ''), ['localhost','127.0.0.1'], true)) {
    throw new RuntimeException('Refusing non-local or non-test database.');
}
// Even if a test config contains provider keys, the fixture copy cannot use them.
$config['providers'] = [];
$mode = $argv[1] ?? 'parent';
$root = $mode === 'parent' ? sys_get_temp_dir() . '/hhx-worker-test-' . bin2hex(random_bytes(8)) : (string)($argv[2] ?? '');
if (!preg_match('~^' . preg_quote(sys_get_temp_dir(), '~') . '/hhx-worker-test-[a-f0-9]{16}$~D', $root)) throw new RuntimeException('Invalid fixture root.');
$repo = dirname(__DIR__);
if ($mode === 'parent') {
    if (!mkdir($root, 0700)) throw new RuntimeException('Cannot create fixture root.');
    mkdir($root . '/config', 0700); mkdir($root . '/cron', 0700);
    foreach (['app','database','collector-remote'] as $dir) {
        if (!symlink($repo . '/' . $dir, $root . '/' . $dir)) throw new RuntimeException('Cannot create fixture link');
    }
    copy($repo . '/cron/worker.php', $root . '/cron/worker.php');
    file_put_contents($root . '/config/config.php', '<?php return ' . var_export($config, true) . ';');
    chmod($root . '/config/config.php', 0600);
}
define('BASE_PATH', $root);
require $repo . '/app/Core/Bootstrap.php';
\App\Core\Bootstrap::init($root);
require_once $repo . '/collector-remote/Providers/ProviderInterface.php';

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Request;
use App\Core\WorkerLock;
use App\Services\CollectorRunner;
use App\Services\MaintenanceService;
use CollectorRemote\Providers\ProviderInterface;

final class FixtureProvider implements ProviderInterface
{
    public function __construct(private string $mode) {}
    public function name(): string { return 'osm'; }
    public function details(string $sourceId): ?array { return null; }
    public function search(array $job): array {
        if ($this->mode === 'wait') {
            $id = (int)DB::fetchOne('SELECT CONNECTION_ID() AS id')['id'];
            echo 'READY:' . $id . "\n"; flush();
            if (trim((string)fgets(STDIN)) !== 'continue') throw new RuntimeException('Missing fixture signal');
        }
        if ($this->mode === 'throw') throw new RuntimeException('synthetic provider error', 403);
        if ($this->mode === 'item') return [['name'=>'Public Fixture', 'source_id'=>'fixture-source', 'address'=>'', 'phones'=>[]]];
        return []; // No external traffic or provider credentials.
    }
}
function fixtureAuth(): void {
    Auth::startSessionIfNeeded();
    $_SESSION['hhx_user'] = ['id'=>1, 'username'=>'fixture', 'display_name'=>'Fixture', 'role'=>'admin'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/fixture';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = Csrf::token();
}
if ($mode !== 'parent') {
    if ($mode === 'hold-collector') {
        try {
            $result = CollectorRunner::runNext(static fn()=>new FixtureProvider('wait'));
            echo json_encode(['result'=>$result], JSON_UNESCAPED_UNICODE) . "\n";
        } catch (Throwable $e) { echo "{\"uncertain\":true}\n"; }
    } elseif ($mode === 'maintenance') {
        echo json_encode(MaintenanceService::run(), JSON_UNESCAPED_UNICODE) . "\n";
    } elseif ($mode === 'review') {
        fixtureAuth();
        $_POST = ['ids'=>[(int)$argv[3], (int)$argv[4]], 'decision'=>'approved'];
        try { \App\Controllers\ReviewQueueController::decide(new Request()); }
        catch (Throwable $e) { echo "{\"fixture_error\":true}\n"; }
    } elseif ($mode === 'category') {
        fixtureAuth();
        $_POST = ['id'=>1, 'name_fa'=>'Fixture category changed', 'slug'=>'fixture-category', 'is_active'=>1];
        \App\Controllers\CategoryController::storeOrUpdateCategory(new Request());
    } elseif ($mode === 'connection-error') {
        $errors = 0;
        try { CollectorRunner::runNext(static fn()=>new FixtureProvider('empty')); } catch (Throwable $e) { $errors++; }
        try { MaintenanceService::run(); } catch (Throwable $e) { $errors++; }
        echo json_encode(['errors'=>$errors]) . "\n";
    } else throw new RuntimeException('Unknown fixture mode.');
    exit;
}

function checkWorker(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: {$label}\n";
}
function spawnWorker(array $args, ?array $env = null): array {
    $pipes = [];
    $proc = proc_open(array_merge([PHP_BINARY], $args), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null, $env);
    if (!is_resource($proc)) throw new RuntimeException('Cannot start fixture subprocess');
    stream_set_timeout($pipes[1], 15);
    return [$proc, $pipes];
}
function finishWorker($proc, array $pipes): string {
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($proc) !== 0) throw new RuntimeException('Fixture subprocess failed: ' . $err);
    return trim($out);
}
function readyWorker(array $pipes): int {
    $line = trim((string)fgets($pipes[1]));
    if (!preg_match('/^READY:(\d+)$/D', $line, $match)) throw new RuntimeException('Provider did not start: ' . $line);
    return (int)$match[1];
}
function endWorker($proc, array $pipes): array {
    fwrite($pipes[0], "continue\n");
    $result = json_decode(finishWorker($proc, $pipes), true);
    if (!is_array($result)) throw new RuntimeException('Invalid worker fixture result');
    return $result;
}
function removeWorkerTree(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        if ($item->isLink() || !$item->isDir()) @unlink($item->getPathname());
        else @rmdir($item->getPathname());
    }
    @rmdir($dir);
}
function queueJob(string $status = 'queued', int $attempts = 0, bool $old = false): int {
    DB::query("INSERT INTO collector_jobs (city_id,pack_id,provider,status,attempts,claimed_at) VALUES (1,1,'osm',:status,:attempts," . ($old ? 'DATE_SUB(NOW(), INTERVAL 16 MINUTE)' : 'NULL') . ")", ['status'=>$status,'attempts'=>$attempts]);
    return DB::lastInsertId();
}
function job(int $id): array { return DB::fetchOne('SELECT * FROM collector_jobs WHERE id=:id', ['id'=>$id]) ?? []; }

try {
    $pdo = DB::connection();
    if (DB::fetchAll('SHOW TABLES')) throw new RuntimeException('The disposable database must be empty; nothing was changed.');
    foreach (['001_initial_schema.sql','002_s1_seed_gilan_packs.sql','004_json_import_promotions.sql'] as $file) {
        $pdo->exec((string)file_get_contents($repo . '/database/migrations/' . $file));
    }
    checkWorker(true, 'empty isolated database initialized with synthetic schema and seed');

    checkWorker(WorkerLock::acquire('cron'), 'cron lock acquired');
    checkWorker(!WorkerLock::acquire('cron'), 'same-connection re-entry is busy, not a second lock');
    [$cron, $cronPipes] = spawnWorker([$root . '/cron/worker.php']);
    $cronResult = json_decode(finishWorker($cron, $cronPipes), true);
    checkWorker(is_array($cronResult) && ($cronResult['status'] ?? '') === 'busy', 'a second whole cron entry exits busy without running services');
    WorkerLock::release('cron');

    checkWorker(WorkerLock::acquire('maintenance'), 'maintenance lock acquired');
    [$maint, $maintPipes] = spawnWorker([__FILE__, 'maintenance', $root]);
    $maintResult = json_decode(finishWorker($maint, $maintPipes), true);
    checkWorker(($maintResult['status'] ?? '') === 'busy', 'overlapping maintenance returns busy');
    WorkerLock::release('maintenance');

    $first = queueJob(); $second = queueJob();
    [$collector, $collectorPipes] = spawnWorker([__FILE__, 'hold-collector', $root]);
    readyWorker($collectorPipes);
    DB::query('UPDATE collector_jobs SET claimed_at=DATE_SUB(NOW(), INTERVAL 16 MINUTE) WHERE id=:id', ['id'=>$first]);
    $busy = CollectorRunner::runNext(static fn()=>throw new RuntimeException('must not call provider'));
    checkWorker(($busy['status'] ?? '') === 'busy' && empty($busy['ran']), 'manual/cron collector overlap skips the second run');
    $maintenance = MaintenanceService::run();
    checkWorker(!empty($maintenance['collector_recovery_skipped']) && ($maintenance['status'] ?? '') === 'ok', 'maintenance skips recovery while collector is active');
    checkWorker(job($first)['status'] === 'running' && job($second)['status'] === 'queued', 'a 16-minute active claim is not requeued and next job is untouched');
    $done = endWorker($collector, $collectorPipes);
    checkWorker(($done['result']['status'] ?? '') === 'done' && (int)job($first)['attempts'] === 1, 'long-running fake job finishes once');
    $next = CollectorRunner::runNext(static fn()=>new FixtureProvider('empty'));
    checkWorker(($next['status'] ?? '') === 'done' && (int)$next['job_id'] === $second, 'the next job runs only after the first releases its lock');

    $old = queueJob('claimed', 2, true); $exhausted = queueJob('running', 3, true);
    $maintenance = MaintenanceService::run();
    checkWorker(empty($maintenance['collector_recovery_skipped']) && (int)$maintenance['released_jobs'] >= 1, 'stale recovery runs after collector releases lock');
    checkWorker(job($old)['status'] === 'queued' && job($exhausted)['status'] === 'failed', 'old claims requeue or fail by attempt count');
    DB::query("UPDATE collector_jobs SET status='done' WHERE id=:id", ['id'=>$old]);

    $changed = queueJob();
    [$collector, $collectorPipes] = spawnWorker([__FILE__, 'hold-collector', $root]);
    readyWorker($collectorPipes);
    DB::query('UPDATE collector_jobs SET attempts=attempts+1 WHERE id=:id', ['id'=>$changed]);
    $outcome = endWorker($collector, $collectorPipes);
    checkWorker(!empty($outcome['uncertain']) && job($changed)['status'] === 'running' && (int)job($changed)['attempts'] === 2, 'stale attempt cannot finish or fail a newer claim');
    DB::query("UPDATE collector_jobs SET status='done' WHERE id=:id", ['id'=>$changed]);

    $lost = queueJob();
    [$collector, $collectorPipes] = spawnWorker([__FILE__, 'hold-collector', $root]);
    $connectionId = readyWorker($collectorPipes);
    DB::query('UPDATE collector_jobs SET claimed_at=DATE_SUB(NOW(), INTERVAL 16 MINUTE) WHERE id=:id', ['id'=>$lost]);
    $pdo->exec('KILL CONNECTION ' . $connectionId); // The fixture user owns both local sessions.
    $recovery = MaintenanceService::run();
    checkWorker(empty($recovery['collector_recovery_skipped']) && job($lost)['status'] === 'queued', 'database disconnect releases the advisory lock for stale recovery');
    $outcome = endWorker($collector, $collectorPipes);
    checkWorker(!empty($outcome['uncertain']) && job($lost)['status'] === 'queued', 'disconnected worker cannot finalize recovered work');
    DB::query("UPDATE collector_jobs SET status='done' WHERE id=:id", ['id'=>$lost]);

    $html = '<link href="/assets/css/public.css?v=s10.0"><!-- hhx-ad-until:0 --><!-- hhx-sponsor-until:0 -->';
    DB::query("INSERT INTO businesses (name,normalized_name,slug,category_id,city_id,status) VALUES ('Public Fixture','publicfixture','fixture-public',1,1,'published')");
    $publicBusiness = DB::lastInsertId();
    DB::query("INSERT INTO business_sources (business_id,provider,source_id) VALUES (:id,'osm','fixture-source')", ['id'=>$publicBusiness]);
    Cache::beginRender(); Cache::putPage('/collector', $html . 'old-collector');
    queueJob();
    $collected = CollectorRunner::runNext(static fn()=>new FixtureProvider('item'));
    checkWorker(($collected['status'] ?? '') === 'done' && (int)$collected['summary']['duplicates'] === 1, 'fake collector processes an existing public source');
    checkWorker(Cache::getPage('/collector') === null && !Cache::putPage('/collector', $html), 'collector commit invalidates the old cache and in-flight renders');

    $errorJob = queueJob();
    $failure = CollectorRunner::runNext(static fn()=>new FixtureProvider('throw'));
    checkWorker(($failure['status'] ?? '') === 'failed' && job($errorJob)['status'] === 'failed', 'fake provider error is recorded');
    checkWorker((bool)DB::fetchOne("SELECT provider FROM provider_state WHERE provider='osm' AND paused_until>NOW()"), '403 pauses the fake provider');
    checkWorker(WorkerLock::acquire('collector'), 'collector lock is released after provider error');
    WorkerLock::release('collector');
    DB::query("UPDATE provider_state SET enabled=0 WHERE provider='osm'");
    [$cron, $cronPipes] = spawnWorker([$root . '/cron/worker.php']);
    $cronResult = json_decode(finishWorker($cron, $cronPipes), true);
    checkWorker(!empty($cronResult['ok']) && ($cronResult['summary']['apify']['status'] ?? '') === 'disabled', 'isolated cron entry completes with providers disabled');

    Cache::beginRender(); Cache::putPage('/review', $html . 'old-review');
    DB::query("INSERT INTO businesses (name,normalized_name,slug,category_id,city_id,status) VALUES ('Fixture','fixture','fixture-review',1,1,'pending_review')");
    $business = DB::lastInsertId();
    DB::query("INSERT INTO review_queue (kind,target_id,reason_code) VALUES ('business',:id,'fixture')", ['id'=>$business]);
    $review1 = DB::lastInsertId();
    DB::query("INSERT INTO review_queue (kind,target_id,reason_code) VALUES ('claim',1,'fixture')");
    $review2 = DB::lastInsertId();
    $pdo->exec('DROP TABLE claims'); // Makes only the second synthetic decision fail.
    [$review, $reviewPipes] = spawnWorker([__FILE__, 'review', $root, (string)$review1, (string)$review2]);
    $result = json_decode(finishWorker($review, $reviewPipes), true);
    checkWorker(!empty($result['fixture_error']), 'second review decision fails after first committed');
    checkWorker(DB::fetchOne('SELECT status FROM businesses WHERE id=:id', ['id'=>$business])['status'] === 'published'
        && DB::fetchOne('SELECT status FROM review_queue WHERE id=:id', ['id'=>$review2])['status'] === 'pending', 'first decision commits and second rolls back');
    checkWorker(Cache::getPage('/review') === null && !Cache::putPage('/review', $html), 'committed first review invalidates cache despite later exception');

    Cache::beginRender(); Cache::putPage('/category', $html . 'old-category');
    [$category, $categoryPipes] = spawnWorker([__FILE__, 'category', $root]);
    $result = json_decode(finishWorker($category, $categoryPipes), true);
    checkWorker(!empty($result['ok']) && Cache::getPage('/category') === null && !Cache::putPage('/category', $html), 'category save invalidates old cache and in-flight renderers');

    // Separate subprocess with an unreachable local DB: connection errors must
    // not be reported as the normal lock-busy state.
    $badConfig = $config;
    $badConfig['db']['host'] = '127.0.0.1'; $badConfig['db']['port'] = 1;
    $badConfig['db']['unix_socket'] = $root . '/nonexistent.sock';
    $badConfig['db']['username'] = 'fixture_missing_user'; $badConfig['db']['password'] = bin2hex(random_bytes(16));
    $badFile = $root . '/config/bad.php';
    file_put_contents($badFile, '<?php return ' . var_export($badConfig, true) . ';');
    // Bootstrap uses the private config in the fixture root.
    copy($badFile, $root . '/config/config.php');
    [$bad, $badPipes] = spawnWorker([__FILE__, 'connection-error', $root]);
    $badResult = json_decode(finishWorker($bad, $badPipes), true);
    checkWorker(($badResult['errors'] ?? 0) === 2, 'database connection errors are not treated as busy');
    echo "All synthetic worker, recovery, review and category checks passed.\n";
} finally {
    removeWorkerTree($root);
}
