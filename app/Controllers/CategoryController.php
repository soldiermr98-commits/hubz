<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\CategorySyncService;
use App\Services\SlugService;

/**
 * کنترلر مدیریت دسته‌بندی‌ها و بسته‌های دسته (Category Packs) در مرحله S1
 */
final class CategoryController
{
    /**
     * فهرست تمام دسته‌ها و بسته‌های دسته آماده (Category Packs)
     * GET /api/v1/categories
     */
    public static function index(Request $request): void
    {
        Auth::requireRole(['admin', 'editor']);
        CategorySyncService::ensureUnified();

        $categories = DB::fetchAll(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM businesses b WHERE b.category_id = c.id) AS businesses_count
             FROM categories c
             ORDER BY c.sort ASC, c.id ASC"
        );

        $packs = CategorySyncService::fetchUnifiedPacks();
        $packItems = DB::fetchAll("SELECT * FROM category_pack_items ORDER BY pack_id ASC, id ASC");

        Response::json([
            'ok' => true,
            'categories' => $categories,
            'packs' => $packs,
            'pack_items' => $packItems,
        ]);
    }

    /**
     * ایجاد یا ویرایش دسته‌بندی کسب‌وکار با اعتبارسنجی اسلاگ‌های رزروشده
     * POST /api/v1/categories
     */
    public static function storeOrUpdateCategory(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin', 'editor']);

        $id = (int) $request->input('id', 0);
        $nameFa = SlugService::normalizePersianText((string) $request->input('name_fa', ''));
        $parentId = $request->input('parent_id') ? (int) $request->input('parent_id') : null;
        $sort = (int) $request->input('sort', 10);
        $isActive = (int) $request->input('is_active', 1) === 1 ? 1 : 0;

        if ($nameFa === '') {
            Response::json([
                'ok' => false,
                'error' => 'لطفاً نام فارسی دسته‌بندی را وارد کنید (مثلاً: کافه و رستوران).',
                'field' => 'name_fa',
            ], 422);
        }

        $rawSlug = trim((string) $request->input('slug', ''));
        if ($rawSlug === '') {
            $rawSlug = SlugService::suggestSlug($nameFa, 'cat');
        }

        $slugCheck = SlugService::validateSlug($rawSlug);
        if (!$slugCheck['ok']) {
            Response::json([
                'ok' => false,
                'error' => $slugCheck['error'],
                'field' => 'slug',
            ], 422);
        }

        $uniqueSlug = SlugService::ensureUniqueSlug('categories', strtolower($rawSlug), $id > 0 ? $id : null);
        $previousCategory = $id > 0 ? DB::fetchOne('SELECT name_fa FROM categories WHERE id = :id', ['id' => $id]) : null;

        if ($id > 0) {
            DB::query(
                "UPDATE categories
                 SET parent_id = :pid, name_fa = :name_fa, slug = :slug, sort = :sort, is_active = :active
                 WHERE id = :id",
                [
                    'pid' => $parentId,
                    'name_fa' => $nameFa,
                    'slug' => $uniqueSlug,
                    'sort' => $sort,
                    'active' => $isActive,
                    'id' => $id,
                ]
            );
            $catId = $id;
            $msg = "دسته‌بندی «{$nameFa}» با موفقیت به‌روزرسانی شد.";
        } else {
            DB::query(
                "INSERT INTO categories (parent_id, name_fa, slug, sort, is_active, created_at)
                 VALUES (:pid, :name_fa, :slug, :sort, :active, NOW())",
                [
                    'pid' => $parentId,
                    'name_fa' => $nameFa,
                    'slug' => $uniqueSlug,
                    'sort' => $sort,
                    'active' => $isActive,
                ]
            );
            $catId = DB::lastInsertId();
            $msg = "دسته‌بندی جدید «{$nameFa}» با اسلاگ «{$uniqueSlug}» ساخته شد.";
        }

        $category = DB::fetchOne("SELECT * FROM categories WHERE id = :id", ['id' => $catId]);
        if ($category) {
            $packId = CategorySyncService::syncSingleCategoryPack($category);
            if ($packId > 0 && $previousCategory && (string) ($previousCategory['name_fa'] ?? '') !== $nameFa) {
                DB::query(
                    'UPDATE category_packs SET name = :new_name WHERE id = :pid AND name = :old_name',
                    ['new_name' => $nameFa, 'pid' => $packId, 'old_name' => (string) $previousCategory['name_fa']]
                );
            }
            CategorySyncService::resetRequestCache();
        }
        Cache::flushAll();

        Response::json([
            'ok' => true,
            'message' => $msg,
            'category' => $category,
        ]);
    }

    /**
     * ایجاد یا ویرایش بسته دسته (Category Pack) برای جستجوی خودکار در پرووایدرها
     * POST /api/v1/category-packs
     */
    public static function storeOrUpdatePack(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin', 'editor']);

        $id = (int) $request->input('id', 0);
        $name = SlugService::normalizePersianText((string) $request->input('name', ''));
        $rawCategoryId = (int) $request->input('category_id', 0);
        if ($rawCategoryId <= 0 && $id > 0) {
            $existingLink = DB::fetchOne('SELECT category_id FROM category_pack_items WHERE pack_id = :pid ORDER BY id ASC LIMIT 1', ['pid' => $id]);
            if ($existingLink) {
                $rawCategoryId = (int) $existingLink['category_id'];
            }
        }
        $categoryId = $rawCategoryId > 0 ? $rawCategoryId : 1;
        $targetCategory = DB::fetchOne('SELECT id, name_fa FROM categories WHERE id = :id', ['id' => $categoryId]);
        if (!$targetCategory) {
            Response::json([
                'ok' => false,
                'error' => 'دسته‌بندی متصل انتخاب‌شده معتبر نیست.',
                'field' => 'category_id',
            ], 422);
        }

        if ($name === '') {
            $name = (string) $targetCategory['name_fa'];
        }

        $queriesInput = $request->input('queries_json', '');
        $isActive = (int) $request->input('is_active', 1) === 1 ? 1 : 0;

        if (is_array($queriesInput)) {
            $decoded = $queriesInput;
            $queriesJson = json_encode($queriesInput, JSON_UNESCAPED_UNICODE);
        } else {
            $decoded = json_decode((string) $queriesInput, true);
            if (!is_array($decoded)) {
                Response::json([
                    'ok' => false,
                    'error' => 'ساختار عبارات جستجو (JSON) معتبر نیست. لطفاً عبارات هر پرووایدر را بررسی کنید.',
                    'field' => 'queries_json',
                ], 422);
            }
            $queriesJson = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }

        if ($id > 0) {
            DB::query(
                "UPDATE category_packs SET name = :name, queries_json = :q, is_active = :active WHERE id = :id",
                ['name' => $name, 'q' => $queriesJson, 'active' => $isActive, 'id' => $id]
            );
            $packId = $id;
            $msg = "تنظیمات جمع‌آوری دسته «{$name}» با موفقیت ویرایش شد.";
        } else {
            DB::query(
                "INSERT INTO category_packs (name, queries_json, is_active, created_at)
                 VALUES (:name, :q, :active, NOW())",
                ['name' => $name, 'q' => $queriesJson, 'active' => $isActive]
            );
            $packId = DB::lastInsertId();
            $msg = "بسته جمع‌آوری جدید «{$name}» با موفقیت ثبت شد.";
        }

        $existingItem = DB::fetchOne(
            'SELECT id, queries_json FROM category_pack_items WHERE pack_id = :pid AND category_id = :cid LIMIT 1',
            ['pid' => $packId, 'cid' => $categoryId]
        );
        $itemKeywords = is_array($decoded['keywords'] ?? null)
            ? $decoded['keywords']
            : (is_array($decoded['neshan'] ?? null) && $decoded['neshan'] ? $decoded['neshan'] : [(string) $targetCategory['name_fa']]);
        $itemJson = $existingItem && !isset($decoded['keywords'])
            ? (string) $existingItem['queries_json']
            : (string) json_encode(['keywords' => array_values($itemKeywords)], JSON_UNESCAPED_UNICODE);

        DB::query(
            "INSERT INTO category_pack_items (pack_id, category_id, queries_json)
             VALUES (:pid, :cid, :q)
             ON DUPLICATE KEY UPDATE queries_json = VALUES(queries_json)",
            ['pid' => $packId, 'cid' => $categoryId, 'q' => $itemJson]
        );
        DB::query(
            'DELETE FROM category_pack_items WHERE pack_id = :pid AND category_id <> :cid',
            ['pid' => $packId, 'cid' => $categoryId]
        );
        CategorySyncService::resetRequestCache();

        $pack = DB::fetchOne(
            "SELECT p.*, c.id AS category_id, c.name_fa AS category_name_fa, c.slug AS category_slug
             FROM category_packs p
             LEFT JOIN category_pack_items cpi ON cpi.pack_id = p.id
             LEFT JOIN categories c ON c.id = cpi.category_id
             WHERE p.id = :id
             LIMIT 1",
            ['id' => $packId]
        );

        Response::json([
            'ok' => true,
            'message' => $msg,
            'pack' => $pack,
        ]);
    }
}
