<?php
declare(strict_types=1);

// Creates synthetic fixtures ONLY in an EMPTY, explicitly named local test DB.
// This script commits its fixtures; do not use a database containing real data.
$configFile = getenv('HHX_TEST_CONFIG') ?: '';
$password = getenv('HHX_TEST_PASSWORD') ?: '';
if (PHP_SAPI !== 'cli' || getenv('HHX_ALLOW_TEST_FIXTURES') !== '1' || !$configFile || !is_file($configFile) || strlen($password) < 12) {
    throw new RuntimeException('Set HHX_ALLOW_TEST_FIXTURES=1, HHX_TEST_CONFIG, and a test-only HHX_TEST_PASSWORD (12+ characters).');
}
$config = require $configFile;
$db = is_array($config) ? ($config['db'] ?? []) : [];
if (!preg_match('/^hhx_test_[a-z0-9_]+$/', (string)($db['database'] ?? '')) || !in_array((string)($db['host'] ?? ''), ['localhost','127.0.0.1'], true)) {
    throw new RuntimeException('Refusing non-local or non-test database.');
}
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Core/Config.php';
\App\Core\Config::load($configFile);
require BASE_PATH . '/app/Core/Bootstrap.php';
\App\Core\Bootstrap::init(BASE_PATH);

use App\Core\DB;
$pdo = DB::connection();
if (DB::fetchAll('SHOW TABLES')) throw new RuntimeException('The test database must be empty. No tables were changed.');
$pdo->exec("CREATE TABLE schema_migrations (migration VARCHAR(190) PRIMARY KEY,executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Simulate an existing site where the legacy 003 migration was never recorded.
foreach (['001_initial_schema.sql','002_s1_seed_gilan_packs.sql','004_json_import_promotions.sql','005_apify_automation.sql'] as $file) {
    $pdo->exec((string)file_get_contents(BASE_PATH . '/database/migrations/' . $file));
    DB::query('INSERT INTO schema_migrations (migration) VALUES (:name)', ['name'=>$file]);
}
$hash = password_hash($password, PASSWORD_BCRYPT);
foreach (['admin','editor'] as $role) {
    DB::query('INSERT INTO users (username,display_name,password_hash,role,is_active) VALUES (:u,:name,:hash,:role,1)',
        ['u'=>'fixture_' . $role,'name'=>'Fixture ' . $role,'hash'=>$hash,'role'=>$role]);
}
DB::query("INSERT INTO businesses (name,normalized_name,slug,category_id,city_id,address,status) VALUES
    ('کافه آزمایشی','کافه آزمایشی','fixture-cafe',1,1,'رشت','published'),
    ('کافه دوم','کافه دوم','fixture-other',1,2,'لاهیجان','published'),
    ('کسب‌وکار خصوصی','کسب‌وکار خصوصی','fixture-private',1,1,'رشت','draft')");
DB::query("INSERT INTO media (file_path_480,file_path_800,file_path_1200,alt_text) VALUES
    ('/uploads/fixture-approved.webp','/uploads/fixture-approved.webp','/uploads/fixture-approved.webp','approved'),
    ('/uploads/fixture-rejected.webp','/uploads/fixture-rejected.webp','/uploads/fixture-rejected.webp','rejected')");
DB::query("INSERT INTO business_photos (business_id,media_id,status) VALUES (1,1,'approved'),(1,2,'rejected')");
DB::query("INSERT INTO articles (title,slug,meta_title,meta_description,category_id,city_id,intro,tips_html,status,published_at) VALUES
    ('نسخه زنده آزمایشی','fixture-live','عنوان سئوی محفوظ','توضیح محفوظ',1,1,'متن زنده اصلی','روش محفوظ','published',NOW()),
    ('پیش‌نویس آزمایشی','fixture-draft','سئوی پیش‌نویس','توضیح پیش‌نویس',1,1,'متن پیش‌نویس خصوصی','بخش محفوظ','draft',NULL)");
DB::query("INSERT INTO article_items (article_id,business_id,position,custom_desc,highlights,is_sponsored,sponsor_label) VALUES
    (1,1,1,'توضیح محفوظ',:highlights,0,'تبلیغ'),(2,1,1,'توضیح پیش‌نویس',:draft_highlights,0,'تبلیغ'),
    (2,2,2,'اطلاعات قدیمی','legacy-invalid-json',0,NULL)",
    ['highlights'=>json_encode(['badge'=>'ویژه','verdict'=>'جمع‌بندی','pros'=>['مزیت محفوظ'],'cons'=>['عیب محفوظ'],'custom_legacy'=>'keep'], JSON_UNESCAPED_UNICODE),
     'draft_highlights'=>json_encode(['pros'=>['مزیت پیش‌نویس'],'cons'=>['نکته پیش‌نویس'],'custom_legacy'=>'keep'], JSON_UNESCAPED_UNICODE)]);
DB::query("INSERT INTO article_faqs (article_id,question,answer,sort) VALUES (1,'پرسش آزمایشی','پاسخ محفوظ',1)");
DB::query("UPDATE apify_automation_settings SET enabled=1,task_id='fixture-only-never-run',city_id=1,category_id=1,next_run_at='2035-01-01 00:00:00' WHERE id=1");
echo "Synthetic fixtures ready in {$db['database']}; migration 003 is intentionally unrecorded.\n";
