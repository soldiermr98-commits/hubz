<?php
declare(strict_types=1);

// Commits and cleans up only random synthetic rows in a disposable local test DB.
$configFile = getenv('HHX_TEST_CONFIG') ?: '';
if (PHP_SAPI !== 'cli' || getenv('HHX_ALLOW_TEST_FIXTURES') !== '1' || !$configFile || !is_file($configFile)) throw new RuntimeException('Test-only configuration required.');
$config = require $configFile;
$db = is_array($config) ? ($config['db'] ?? []) : [];
if (!preg_match('/^hhx_test_[a-z0-9_]+$/', (string)($db['database'] ?? '')) || !in_array((string)($db['host'] ?? ''), ['localhost','127.0.0.1'], true)) throw new RuntimeException('Refusing non-local or non-test database.');
define('BASE_PATH',dirname(__DIR__));
require BASE_PATH . '/app/Core/Config.php';
\App\Core\Config::load($configFile);
require BASE_PATH . '/app/Core/Bootstrap.php';
\App\Core\Bootstrap::init(BASE_PATH);

use App\Core\DB;
use App\Services\ArticleService;

function verifyConflict(bool $value, string $label): void {
    if (!$value) throw new RuntimeException('FAIL: ' . $label);
    echo "PASS: {$label}\n";
}
$admin = DB::fetchOne("SELECT id,role FROM users WHERE username='fixture_admin'");
$editor = DB::fetchOne("SELECT id,role FROM users WHERE username='fixture_editor'");
if (!$admin || !$editor || !ArticleService::schemaReady()) throw new RuntimeException('Run the fixture setup and migration 006 first.');
$id = 0;
try {
    $suffix = bin2hex(random_bytes(6));
    $saved = ArticleService::save(['title_fa'=>'Fixture conflict ' . $suffix,'intro_html'=>'اصل منتشرشده','status'=>'published'], $admin);
    $id = (int)$saved['article_id'];
    $article = ArticleService::load($id);
    $revision = ArticleService::save(['id'=>$id,'edit_token'=>$article['edit_token'],'intro_html'=>'نسخه در انتظار','status'=>'review'], $editor);
    verifyConflict($revision['status']==='published' && $revision['working_status']==='review', 'working revision is queued without taking down live article');
    // Simulate another writer changing the published base after the revision started.
    DB::query("UPDATE articles SET intro='نسخه زنده جدیدتر' WHERE id=:id", ['id'=>$id]);
    $pdo=DB::connection();$pdo->beginTransaction();
    $result=ArticleService::decideReview($id,'approved');
    $pdo->rollBack();
    verifyConflict($result==='conflict', 'review refuses a revision based on stale published content');
    $article = ArticleService::load($id);
    verifyConflict($article['intro_html']==='نسخه زنده جدیدتر' && $article['revision']['data']['intro_html']==='نسخه در انتظار' && $article['revision']['base_conflict'], 'both the newer live version and the working copy are retained');
    $blocked=false;
    try { ArticleService::save(['id'=>$id,'edit_token'=>$article['edit_token'],'status'=>'published'], $admin); }
    catch (RuntimeException $e) { $blocked=true; }
    verifyConflict($blocked, 'direct publication also refuses to overwrite the newer live version');
} finally {
    if (DB::connection()->inTransaction()) DB::connection()->rollBack();
    if ($id > 0) {
        foreach (['article_revisions'=>'article_id','article_extras'=>'article_id','article_items'=>'article_id','article_faqs'=>'article_id','article_url_aliases'=>'article_id','review_queue'=>'target_id','articles'=>'id'] as $table=>$column) {
            $where = $table === 'review_queue' ? " AND kind='article'" : '';
            DB::query("DELETE FROM `{$table}` WHERE `{$column}`=:id{$where}", ['id'=>$id]);
        }
    }
}
echo "All revision conflict checks passed; synthetic rows cleaned up.\n";
