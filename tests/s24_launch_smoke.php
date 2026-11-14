<?php
declare(strict_types=1);

// S24 launch smoke — pure in-memory doubles; no network, no production DB, no files outside a temp fixture.
// Covers: AI queue route activation, NVIDIA default model fix, BusinessSeoService schema-cache reset,
// PipelineService Bayesian tier recalculation, BackupService dump generation and HealthService checks.

namespace App\Core {
    final class FakeConnection {
        public function quote($value): string { return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$value) . "'"; }
    }
    final class Config {
        public static function get(string $key, $default = null) { return $key === 'db.database' ? 'hhx_smoke_test' : $default; }
    }
    final class DB {
        public static array $canned = [];   // [needle => result] — first matching needle wins
        public static array $recorded = []; // non-select queries
        private static ?FakeConnection $connection = null;
        private static function result(string $sql) {
            foreach (self::$canned as $needle => $result) {
                if (str_contains($sql, $needle)) return $result;
            }
            throw new \RuntimeException('Unexpected query in test: ' . substr($sql, 0, 120));
        }
        public static function connection(): FakeConnection { return self::$connection ??= new FakeConnection(); }
        public static function fetchOne(string $sql, array $params = []): ?array {
            $r = self::result($sql);
            return $r === null ? null : (array)$r;
        }
        public static function fetchAll(string $sql, array $params = []): array {
            $r = self::result($sql);
            return is_array($r) ? array_values($r) : [];
        }
        public static function query(string $sql, array $params = []): void {
            self::$recorded[] = ['sql' => $sql, 'params' => $params];
        }
    }
}

namespace {
    use App\Core\DB;
    use App\Services\AiArticleService;
    use App\Services\BackupService;
    use App\Services\BusinessSeoService;
    use App\Services\HealthService;
    use App\Services\PipelineService;

    require __DIR__ . '/../app/Services/ArticleText.php';
    require __DIR__ . '/../app/Services/AiArticleService.php';
    require __DIR__ . '/../app/Services/PipelineService.php';
    require __DIR__ . '/../app/Services/BusinessSeoService.php';
    require __DIR__ . '/../app/Core/WorkerLock.php';
    require __DIR__ . '/../app/Services/BackupService.php';
    require __DIR__ . '/../app/Services/HealthService.php';

    function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException('FAIL: ' . $message); }

    $repo = dirname(__DIR__);

    // --- ۱. کاتالوگ سرویس‌ها: مدل پیش‌فرض NVIDIA اصلاح‌شده ---
    $catalog = AiArticleService::catalog();
    check($catalog['nvidia']['default_model'] === 'nvidia/nemotron-3.5-lightning', 'NVIDIA default model not corrected');
    check(array_column($catalog['nvidia']['suggested_models'], 'id') === ['nvidia/nemotron-3.5-lightning'], 'NVIDIA suggestion list not corrected');
    check($catalog['openrouter']['default_model'] === 'openrouter/free', 'OpenRouter default must stay openrouter/free (verified live)');
    check($catalog['gemini']['default_model'] === 'gemini-3.5-flash-lite', 'Gemini default must stay gemini-3.5-flash-lite (verified live)');
    check(AiArticleService::validModel('nvidia', 'nvidia/nemotron-3.5-lightning'), 'Corrected NVIDIA model rejected by validator');

    // --- ۲. رووت‌های صف AI دیگر به disabled وصل نیستند ---
    $apiIndex = file_get_contents($repo . '/public_html/api/index.php');
    check($apiIndex !== false && str_contains($apiIndex, "[AiWorkerController::class, 'enqueue']"), 'AI enqueue route not activated');
    check(str_contains($apiIndex, "[AiWorkerController::class, 'runBatch']"), 'AI run-batch route not activated');
    check(str_contains($apiIndex, "[AiWorkerController::class, 'pullNextTask']"), 'AI pull-next route not activated');
    check(str_contains($apiIndex, "[AiWorkerController::class, 'completeTask']"), 'AI complete route not activated');
    check(!str_contains($apiIndex, "[AiWorkerController::class, 'disabled']"), 'AI routes still wired to disabled handler');
    check(str_contains($apiIndex, "[SystemController::class, 'health']"), 'System health route missing');
    check(str_contains($apiIndex, "[SystemController::class, 'backupRun']"), 'System backup route missing');

    // --- ۳. مایگریشن ۰۲۲ ---
    $migration = file_get_contents($repo . '/database/migrations/022_system_backups.sql');
    check($migration !== false && str_contains($migration, 'CREATE TABLE IF NOT EXISTS `system_backups`'), 'migration 022 missing system_backups');
    check(str_contains($migration, 'backup_retention_days'), 'migration 022 missing retention setting');

    // --- ۴. BusinessSeoService::resetSchemaCache واقعاً کش را خالی می‌کند ---
    DB::$canned = [
        "SHOW TABLES LIKE 'seo_url_rules'" => null, // جدول هنوز ساخته نشده
    ];
    check(BusinessSeoService::schemaReady() === false, 'schemaReady should be false without table');
    DB::$canned = [
        "SHOW TABLES LIKE 'seo_url_rules'" => ['Tables_in_test' => 'seo_url_rules'],
    ];
    check(BusinessSeoService::schemaReady() === false, 'stale cache must persist until resetSchemaCache is called');
    BusinessSeoService::resetSchemaCache();
    check(BusinessSeoService::schemaReady() === true, 'resetSchemaCache must re-query the database');

    // --- ۵. PipelineService::recalculateTiersForCityCategory — بیزی صادقانه ---
    DB::$canned = [];
    DB::$recorded = [];
    DB::$canned['FROM businesses b
             WHERE b.city_id'] = [
        ['id' => '1', 'tier' => 'C', 'address' => 'خیابان امام', 'hours' => '', 'lat' => '37.2', 'lng' => '49.6'],
        ['id' => '2', 'tier' => 'C', 'address' => 'بلوار گلشهر', 'hours' => '۸ تا ۲۲', 'lat' => '37.3', 'lng' => '49.5'],
        ['id' => '3', 'tier' => 'B', 'address' => 'میدان شهرداری', 'hours' => '', 'lat' => '37.28', 'lng' => '49.58'],
    ];
    DB::$canned['SELECT bs.business_id,
                    SUM(bs.reviews_count)'] = [
        ['business_id' => '1', 'v' => '100', 'weighted' => '450.0'],  // R=4.5 با ۱۰۰ نظر → نزدیک 4.5
        ['business_id' => '2', 'v' => '4', 'weighted' => '20.0'],     // R=5.0 با ۴ نظر → به سمت میانگین کشیده می‌شود
    ];
    DB::$canned['SELECT AVG(bs.rating) AS mean_rating'] = ['mean_rating' => '4.0'];
    DB::$canned['SELECT DISTINCT business_id FROM business_contacts'] = [['business_id' => '1'], ['business_id' => '2']];
    PipelineService::recalculateTiersForCityCategory(1, 2);

    $scoreUpdates = [];
    $tierUpdates = [];
    foreach (DB::$recorded as $q) {
        if (str_contains($q['sql'], 'SET internal_score')) $scoreUpdates[(int)$q['params']['id']] = (float)$q['params']['score'];
        if (str_contains($q['sql'], 'SET tier')) $tierUpdates[(int)$q['params']['id']] = $q['params']['tier'];
    }
    check(count($scoreUpdates) === 3, 'all businesses must get an internal_score');
    // بیزی: id=1 با v=100 → 4.5 تقریباً حفظ می‌شود؛ id=2 با v=4 → از 5.0 به سمت 4.0 کشیده می‌شود
    check(abs($scoreUpdates[1] - 4.5) < 0.1, 'Bayesian score for high-volume business drifted too far');
    check($scoreUpdates[2] < 5.0 && $scoreUpdates[2] > 4.0, 'Bayesian score for low-volume business must shrink toward category mean');
    // id=3 بدون ستاره: نشانگر تکمیل فیلدها (فقط آدرس + مختصات = 2/4) — نه امتیاز ستاره‌ای
    check(abs($scoreUpdates[3] - 0.5) < 0.001, 'unrated business must get completion ratio 0.5, not a fabricated rating');
    // tier فقط بین دارایان امتیاز واقعی: id=1 در صدر → A، id=2 بعدی → B، id=3 tier قبلی خودش را نگه می‌دارد
    check(($tierUpdates[1] ?? '') === 'A', 'top rated business must become Tier A');
    check(($tierUpdates[2] ?? '') === 'B', 'second rated business must become Tier B');
    check(!array_key_exists(3, $tierUpdates), 'unrated business tier must never change without real ratings');

    // بدون هیچ امتیاز واقعی: هیچ tier ای تغییر نمی‌کند
    DB::$recorded = [];
    DB::$canned = [
        'FROM businesses b
             WHERE b.city_id' => [['id' => '9', 'tier' => 'C', 'address' => '', 'hours' => '', 'lat' => null, 'lng' => null]],
        'SELECT bs.business_id,
                    SUM(bs.reviews_count)' => [],
        'SELECT AVG(bs.rating) AS mean_rating' => null,
        'SELECT AVG(rating) AS mean_rating FROM business_sources' => null,
        'SELECT DISTINCT business_id FROM business_contacts' => [],
    ];
    PipelineService::recalculateTiersForCityCategory(1, 2);
    $tierChanges = array_filter(DB::$recorded, fn($q) => str_contains($q['sql'], 'SET tier'));
    check($tierChanges === [], 'tier must never be derived without real rating evidence');

    // ورودی نامعتبر
    $rejected = false;
    try { PipelineService::recalculateTiersForCityCategory(0, 2); } catch (InvalidArgumentException) { $rejected = true; }
    check($rejected, 'invalid city/category must be rejected');

    // --- ۶. BackupService — ساخت خروجی SQL فشرده با escaping درست ---
    $fixture = sys_get_temp_dir() . '/hhx-s24-' . bin2hex(random_bytes(6));
    mkdir($fixture . '/storage/backups', 0755, true);
    define('BASE_PATH', $fixture);

    DB::$recorded = [];
    DB::$canned = [
        "SELECT GET_LOCK" => ['acquired' => '1'],
        "SELECT RELEASE_LOCK" => ['released' => '1'],
        "SELECT v FROM settings WHERE k = 'backup_retention_days'" => ['v' => '14'],
        "SELECT v FROM settings WHERE k = 'backup_last_run'" => ['v' => ''],
        'SHOW TABLES' => [['T' => 'businesses'], ['T' => 'analytics_events']],
        'SHOW CREATE TABLE `businesses`' => ['Table' => 'businesses', 'Create Table' => 'CREATE TABLE `businesses` (id INT NOT NULL, name TEXT, PRIMARY KEY(id))'],
        'SHOW CREATE TABLE `analytics_events`' => ['Table' => 'analytics_events', 'Create Table' => 'CREATE TABLE `analytics_events` (id BIGINT, path VARCHAR(190))'],
        'SHOW COLUMNS FROM `businesses`' => [['Field' => 'id'], ['Field' => 'name']],
        'SHOW COLUMNS FROM `analytics_events`' => [['Field' => 'id'], ['Field' => 'path']],
        'SELECT `id`, `name` FROM `businesses`' => [
            ['id' => 1, 'name' => "کافه O'Brien\nخط دوم"], // escaping نام جلوی SQL injection در فایل بکاپ را می‌گیرد
            ['id' => 2, 'name' => null],
        ],
        'INSERT INTO system_backups' => [],
        "INSERT INTO settings (k, v, updated_at) VALUES ('backup_last_run'" => [],
        'DELETE FROM system_backups' => [],
    ];

    $result = BackupService::run('panel');
    check(($result['status'] ?? '') === 'ok', 'backup run failed: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
    $file = $fixture . '/storage/backups/' . $result['filename'];
    check(is_file($file), 'backup file not created');
    $dump = gzdecode((string)file_get_contents($file));
    check(str_contains($dump, 'CREATE TABLE `businesses`'), 'dump missing businesses schema');
    check(str_contains($dump, "INSERT INTO `businesses`"), 'dump missing businesses data');
    check(str_contains($dump, "O\\'Brien"), 'quotes not escaped in dump');
    check(str_contains($dump, 'خط دوم'), 'multibyte value lost in dump');
    check(str_contains($dump, 'VALUES (2, NULL)'), 'null values must be written as NULL');
    check(str_contains($dump, 'data skipped for analytics_events'), 'raw log table data must be skipped');
    check(!str_contains($dump, 'INSERT INTO `analytics_events`'), 'skipped table must not contain data inserts');
    $recordedInsert = array_values(array_filter(DB::$recorded, fn($q) => str_contains($q['sql'], 'INSERT INTO system_backups')))[0] ?? null;
    check($recordedInsert !== null && $recordedInsert['params']['st'] === 'ok', 'backup run not recorded in system_backups');

    // HealthService — روی همان fixture
    mkdir($fixture . '/public_html/api', 0755, true);
    file_put_contents($fixture . '/public_html/index.php', '<?php echo "حامی‌هاب";');
    file_put_contents($fixture . '/public_html/api/index.php', '<?php echo "api";');
    DB::$canned = [
        "SELECT COUNT(*) AS c, NOW()" => ['c' => '42', 'db_now' => '2026-02-14 10:00:00'],
        "SELECT v FROM settings WHERE k = 'sponsor_last_check'" => ['v' => date('Y-m-d H:i:s', time() - 600)],
        "SELECT SUM(status = 'queued') AS q" => ['q' => '2', 'cl' => '0', 'dn' => '5', 'f' => '1'],
        "SELECT v FROM settings WHERE k = 'backup_last_run'" => ['v' => date('Y-m-d H:i:s', time() - 900)],
        "SHOW TABLES LIKE 'business_comments'" => ['T' => 'business_comments'],
        "SHOW TABLES LIKE 'system_backups'" => ['T' => 'system_backups'],
    ];
    $health = HealthService::run();
    check($health['ok'] === true && $health['overall'] === 'ok', 'health must be ok on the healthy fixture: ' . json_encode($health, JSON_UNESCAPED_UNICODE));
    $byId = array_column($health['checks'], null, 'id');
    check(($byId['database']['status'] ?? '') === 'ok', 'database check must pass');
    check(($byId['backups']['status'] ?? '') === 'ok', 'backups check must pass after a run');
    check(($byId['storage']['status'] ?? '') === 'ok', 'storage check must pass');
    check(($byId['cron']['status'] ?? '') === 'ok', 'cron check must pass for a fresh run');

    // کرون ازکارافتاده → error
    DB::$canned["SELECT v FROM settings WHERE k = 'sponsor_last_check'"] = ['v' => date('Y-m-d H:i:s', time() - 90000)];
    $health2 = HealthService::run();
    check($health2['overall'] === 'error', 'stale cron must surface an error in overall health');

    // پاکسازی fixture
    foreach (glob($fixture . '/storage/backups/*') ?: [] as $f) @unlink($f);
    @rmdir($fixture . '/storage/backups'); @rmdir($fixture . '/storage');
    @unlink($fixture . '/public_html/api/index.php'); @rmdir($fixture . '/public_html/api');
    @unlink($fixture . '/public_html/index.php'); @rmdir($fixture . '/public_html'); @rmdir($fixture);

    echo "PASS: S24 launch smoke — AI queue activated, NVIDIA model fixed, schema-cache reset works, honest Bayesian tiering, backup dump with escaping, health checks.\n";
}
