<?php
declare(strict_types=1);

// Synthetic rows in an explicitly named, disposable LOCAL test database only.
$configFile = getenv('HHX_TEST_CONFIG') ?: '';
if (PHP_SAPI !== 'cli' || getenv('HHX_ALLOW_TEST_FIXTURES') !== '1' || !$configFile || !is_file($configFile)) {
    throw new RuntimeException('Test-only configuration required.');
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
use App\Services\ArticleService;

function verifyDisplay(bool $value, string $label): void {
    if (!$value) throw new RuntimeException('FAIL: ' . $label);
    echo "PASS: {$label}\n";
}
$admin = DB::fetchOne("SELECT id,role FROM users WHERE username='fixture_admin'");
$city = DB::fetchOne('SELECT id FROM cities ORDER BY id LIMIT 1');
$category = DB::fetchOne('SELECT id FROM categories ORDER BY id LIMIT 1');
if (!$admin || !$city || !$category || !ArticleService::schemaReady()) throw new RuntimeException('Run synthetic fixture setup and migration 006 first.');
$articleId = 0; $ids = [];
try {
    $suffix = bin2hex(random_bytes(6));
    foreach ([
        ['valid','published',(int)$city['id'],(int)$category['id']],
        ['private','draft',(int)$city['id'],(int)$category['id']],
        ['no-city','published',4294967294,(int)$category['id']],
        ['no-category','published',(int)$city['id'],4294967294],
    ] as [$name,$status,$cityId,$categoryId]) {
        DB::query('INSERT INTO businesses (name,normalized_name,slug,category_id,city_id,status) VALUES (:name,:normalized,:slug,:category,:city,:status)',
            ['name'=>'Synthetic ' . $name,'normalized'=>'synthetic ' . $name,'slug'=>'synthetic-' . $name . '-' . $suffix,
             'category'=>$categoryId,'city'=>$cityId,'status'=>$status]);
        $ids[$name] = DB::lastInsertId();
    }
    $oldIntro = "متن قدیمی\n\n## تیتر بدون تغییر\n<strong>متن قدیمی</strong>";
    $result = ArticleService::save(['title_fa'=>'Synthetic display ' . $suffix,'slug'=>'synthetic-display-' . $suffix,
        'intro_html'=>$oldIntro,'status'=>'published','items'=>array_map(static fn(int $id): array => ['business_id'=>$id], array_values($ids))], $admin);
    $articleId = (int)$result['article_id'];
    $legacyHashPayload = [
        DB::fetchOne('SELECT id,title,slug,meta_title,meta_description,category_id,city_id,intro,tips_html,year_label,status,ai_generated,published_at FROM articles WHERE id=:id', ['id'=>$articleId]),
        DB::fetchAll('SELECT business_id,position,custom_desc,highlights,is_sponsored,sponsor_label,sponsor_start,sponsor_end FROM article_items WHERE article_id=:id ORDER BY position,id', ['id'=>$articleId]),
        DB::fetchAll('SELECT question,answer,sort FROM article_faqs WHERE article_id=:id ORDER BY sort,id', ['id'=>$articleId]),
        DB::fetchOne('SELECT cover_media_id FROM article_extras WHERE article_id=:id', ['id'=>$articleId]),
    ];
    $expectedLegacyHash = hash('sha256',(string)json_encode($legacyHashPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    verifyDisplay(hash_equals($expectedLegacyHash, ArticleService::liveHash($articleId)), 'untagged articles keep the pre-taxonomy conflict hash');
    // Simulate a legacy dangling article_items reference without changing any other row.
    $missingId = (int)(DB::fetchOne('SELECT MAX(id) AS n FROM businesses')['n'] ?? 0) + 100000;
    DB::query('INSERT INTO article_items (article_id,business_id,position,is_sponsored) VALUES (:article,:business,5,1)',
        ['article'=>$articleId,'business'=>$missingId]);
    $article = ArticleService::load($articleId);
    $display = (array)$article['item_display'];
    verifyDisplay($display[$ids['valid']]['eligibility'] === 'eligible', 'published business with city/category is eligible');
    verifyDisplay($display[$ids['private']]['eligibility'] === 'not_published', 'private business is explicitly flagged');
    verifyDisplay($display[$ids['no-city']]['eligibility'] === 'missing_context' && $display[$ids['no-category']]['eligibility'] === 'missing_context', 'orphaned city/category prevent public display');
    verifyDisplay($display[$missingId]['eligibility'] === 'missing', 'dangling legacy item is flagged instead of hidden from admin');
    $changed = $article['items']; $changed[0]['is_sponsored'] = 1;
    ArticleService::save(['id'=>$articleId,'edit_token'=>$article['edit_token'],'items'=>$changed,'status'=>'draft'], $admin);
    $article = ArticleService::load($articleId);
    verifyDisplay($article['intro_html'] === $oldIntro && $article['revision']['data']['intro_html'] === $oldIntro, 'sponsor-only private draft preserves old text and live snapshot');
    verifyDisplay((DB::fetchOne('SELECT status FROM businesses WHERE id=:id', ['id'=>$ids['private']])['status'] ?? '') === 'draft', 'no unpublished business is automatically made public');
    verifyDisplay((int)(DB::fetchOne('SELECT is_sponsored FROM article_items WHERE article_id=:id AND business_id=:business', ['id'=>$articleId,'business'=>$ids['valid']])['is_sponsored'] ?? -1) === 0, 'a private sponsor draft does not change the public snapshot');
} finally {
    if (DB::connection()->inTransaction()) DB::connection()->rollBack();
    if ($articleId > 0) {
        foreach (['article_revisions'=>'article_id','article_extras'=>'article_id','article_items'=>'article_id','article_faqs'=>'article_id','article_url_aliases'=>'article_id','review_queue'=>'target_id','articles'=>'id'] as $table=>$column) {
            $where = $table === 'review_queue' ? " AND kind='article'" : '';
            DB::query("DELETE FROM `{$table}` WHERE `{$column}`=:id{$where}", ['id'=>$articleId]);
        }
    }
    foreach ($ids as $id) DB::query('DELETE FROM businesses WHERE id=:id', ['id'=>$id]);
}
echo "All item visibility checks passed; synthetic rows cleaned up.\n";
