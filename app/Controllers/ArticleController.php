<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\ArticleService;
use App\Services\ArticleText;
use App\Services\SlugService;

/**
 * کنترلر مدیریت مقالات لیستی («بهترین X در شهر Y») و جایگاه‌های اسپانسرشیپ (مرحله S4)
 * منطبق با جداول articles, article_items, article_faqs در 001_initial_schema.sql
 */
final class ArticleController
{
    /** The list includes the live version and (when present) a private working copy. */
    public static function index(Request $req, array $params = []): void
    {
        Auth::requireRole(['admin', 'editor']);
        $ids = DB::fetchAll('SELECT id FROM articles ORDER BY id DESC');
        $articles = [];
        foreach ($ids as $row) {
            $article = ArticleService::load((int)$row['id']);
            if ($article) $articles[] = $article;
        }
        $tagsReady = ArticleService::tagsSchemaReady();
        Response::json(['ok'=>true, 'schema_ready'=>ArticleService::schemaReady(),
            'article_tags_ready'=>$tagsReady, 'tag_catalog'=>$tagsReady ? ArticleService::tagCatalog() : [],
            'rich_editor_available'=>ArticleText::richAvailable(), 'articles'=>$articles]);
    }

    /** Save without exposing drafts or replacing the published version prematurely. */
    public static function storeOrUpdate(Request $req, array $params = []): void
    {
        Csrf::verifyOrFail($req);
        $user = Auth::requireRole(['admin', 'editor']);
        try {
            $result = ArticleService::save($req->json(), $user);
            Cache::flushAll();
            $message = $result['working_status'] === 'review' ? 'تغییرات برای بررسی مدیر ارسال شد؛ نسخه منتشرشده بدون تغییر باقی ماند.'
                : ($result['working_status'] === 'draft' ? 'پیش‌نویس تغییرات ذخیره شد؛ نسخه منتشرشده بدون تغییر باقی ماند.'
                : ($result['status'] === 'published' ? 'مقاله منتشر شد.' : ($result['status'] === 'review' ? 'مقاله برای بررسی مدیر ارسال شد.' : 'پیش‌نویس ذخیره شد.')));
            Response::json(array_merge(['ok'=>true,'message'=>$message], $result, ['article'=>ArticleService::load((int)$result['article_id'])]));
        } catch (\InvalidArgumentException $e) {
            Response::json(['ok'=>false,'error'=>$e->getMessage()], 422);
        } catch (\PDOException $e) {
            error_log('Article database save failed: ' . $e->getMessage());
            Response::json(['ok'=>false,'error'=>'ذخیره مقاله ناموفق بود؛ اطلاعات قبلی تغییر نکرده است. گزارش سرور را بررسی کنید.'], 500);
        } catch (\RuntimeException $e) {
            Response::json(['ok'=>false,'error'=>$e->getMessage()], 409);
        } catch (\Throwable $e) {
            error_log('Article save failed: ' . $e->getMessage());
            Response::json(['ok'=>false,'error'=>'ذخیره مقاله ناموفق بود؛ اطلاعات قبلی تغییر نکرده است. گزارش سرور را بررسی کنید.'], 500);
        }
    }

    /**
     * POST /api/v1/articles/auto-build
     * تولید خودکار مقاله «بهترین {دسته} در {شهر}» از کسب‌وکارهای برتر آن شهر و دسته
     */
    public static function autoBuild(Request $req, array $params = []): void
    {
        Csrf::verifyOrFail($req);
        Auth::requireRole(['admin', 'editor']);
        if (!ArticleService::schemaReady()) Response::json(['ok'=>false,'error'=>'ابتدا به‌روزرسانی دیتابیس را طبق راهنمای نصب انجام دهید.'],409);
        $cityId = (int) $req->input('city_id', 0);
        $categoryId = (int) $req->input('category_id', 0);
        $city = DB::fetchOne("SELECT id, name_fa, slug FROM cities WHERE id = :id", ['id' => $cityId]);
        $category = DB::fetchOne("SELECT id, name_fa, slug FROM categories WHERE id = :id", ['id' => $categoryId]);
        if (!$city || !$category) {
            Response::json(['ok' => false, 'error' => 'شهر و دسته معتبر را انتخاب کنید.'], 422);
        }
        $businesses = DB::fetchAll(
            "SELECT id FROM businesses WHERE city_id = :cid AND category_id = :cat AND status = 'published' ORDER BY name ASC, id ASC LIMIT 50",
            ['cid' => $cityId, 'cat' => $categoryId]
        );
        if (!$businesses) {
            Response::json(['ok' => false, 'error' => 'برای این شهر و دسته کسب‌وکار تأییدشده‌ای وجود ندارد.'], 422);
        }
        $slug = SlugService::ensureUniqueSlug('articles', 'list-' . $category['slug'] . '-' . $city['slug']);
        $title = $category['name_fa'] . ' در ' . $city['name_fa'];
        $pdo = DB::connection();
        $pdo->beginTransaction();
        try {
            DB::query(
                "INSERT INTO articles (title, slug, category_id, city_id, year_label, status, ai_generated, created_at, updated_at)
                 VALUES (:title, :slug, :cat, :city, '', 'draft', 0, NOW(), NOW())",
                ['title' => $title, 'slug' => $slug, 'cat' => $categoryId, 'city' => $cityId]
            );
            $articleId = DB::lastInsertId();
            foreach ($businesses as $index => $biz) {
                DB::query("INSERT INTO article_items (article_id, business_id, position, is_sponsored) VALUES (:aid, :bid, :pos, 0)",
                    ['aid' => $articleId, 'bid' => (int) $biz['id'], 'pos' => $index + 1]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        Response::json(['ok' => true, 'article_id' => $articleId, 'slug' => $slug,
            'items_added' => count($businesses), 'message' => 'پیش‌نویس ساخته شد؛ قبل از انتشار آن را بررسی کنید.']);
    }
}
