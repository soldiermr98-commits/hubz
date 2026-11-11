<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * A published article is never modified by an editor's draft. Its complete
 * working copy lives in article_revisions until an administrator approves it.
 */
final class ArticleService
{
    public static function schemaReady(): bool
    {
        static $ready = null;
        return $ready ??= DB::fetchOne("SHOW TABLES LIKE 'article_revisions'") !== null
            && DB::fetchOne("SHOW TABLES LIKE 'article_extras'") !== null
            && DB::fetchOne("SHOW TABLES LIKE 'article_url_aliases'") !== null;
    }

    public static function tagsSchemaReady(): bool
    {
        static $ready = null;
        return $ready ??= DB::fetchOne("SHOW TABLES LIKE 'article_tags'") !== null
            && DB::fetchOne("SHOW TABLES LIKE 'article_tag_links'") !== null;
    }

    public static function tagCatalog(): array
    {
        if (!self::tagsSchemaReady()) return [];
        return DB::fetchAll('SELECT id,name_fa,slug FROM article_tags ORDER BY name_fa,id');
    }

    public static function tagsForArticle(int $id): array
    {
        if (!self::tagsSchemaReady()) return [];
        return DB::fetchAll('SELECT t.id,t.name_fa,t.slug FROM article_tag_links l INNER JOIN article_tags t ON t.id=l.tag_id WHERE l.article_id=:id ORDER BY t.name_fa,t.id', ['id'=>$id]);
    }

    public static function requireSchema(): void
    {
        if (!self::schemaReady()) {
            throw new RuntimeException('برای استفاده از ویرایشگر جدید، پس از تهیه نسخه پشتیبان، به‌روزرسانی دیتابیس را طبق راهنمای نصب انجام دهید.');
        }
    }

    /** Shape used by both the API and the private preview. */
    public static function load(int $id): ?array
    {
        $article = DB::fetchOne(
            'SELECT a.*,c.name_fa AS city_name_fa,c.slug AS city_slug,cat.name_fa AS category_name_fa,cat.slug AS category_slug
             FROM articles a LEFT JOIN cities c ON c.id=a.city_id LEFT JOIN categories cat ON cat.id=a.category_id WHERE a.id=:id',
            ['id'=>$id]
        );
        if (!$article) return null;

        $items = DB::fetchAll(
            'SELECT ai.*,b.name AS business_name_fa,b.slug AS business_slug,b.internal_score AS weighted_score,b.tier
             FROM article_items ai LEFT JOIN businesses b ON b.id=ai.business_id
             WHERE ai.article_id=:id ORDER BY ai.position ASC,ai.id ASC', ['id'=>$id]
        );
        foreach ($items as &$item) {
            $highlights = json_decode((string)($item['highlights'] ?? ''), true);
            if (!is_array($highlights)) $highlights = [];
            $item['rank_num'] = (int)$item['position'];
            $item['badge_fa'] = (string)($highlights['badge'] ?? '');
            $item['verdict_fa'] = (string)($highlights['verdict'] ?? '');
            $item['pros'] = is_array($highlights['pros'] ?? null) ? $highlights['pros'] : [];
            $item['cons'] = is_array($highlights['cons'] ?? null) ? $highlights['cons'] : [];
            $item['custom_desc_html'] = (string)($item['custom_desc'] ?? '');
        }
        unset($item);
        $faqs = DB::fetchAll('SELECT id,article_id,question AS question_fa,answer AS answer_fa,sort AS sort_order FROM article_faqs WHERE article_id=:id ORDER BY sort,id', ['id'=>$id]);
        $tags = self::tagsForArticle($id);

        $article['title_fa'] = (string)$article['title'];
        $article['subtitle_fa'] = (string)($article['meta_description'] ?? '');
        $article['intro_html'] = (string)($article['intro'] ?? '');
        $article['methodology_html'] = (string)($article['tips_html'] ?? '');
        $article['items'] = $items;
        $article['faqs'] = $faqs;
        $article['tags'] = $tags;
        $article['cover_media_id'] = null;
        $article['cover_url'] = null;
        $article['revision'] = null;
        if (self::schemaReady()) {
            $cover = DB::fetchOne('SELECT x.cover_media_id,m.file_path_800 FROM article_extras x LEFT JOIN media m ON m.id=x.cover_media_id WHERE x.article_id=:id', ['id'=>$id]);
            $article['cover_media_id'] = isset($cover['cover_media_id']) ? (int)$cover['cover_media_id'] : null;
            $article['cover_url'] = $cover['file_path_800'] ?? null;
            $revision = DB::fetchOne('SELECT payload_json,base_hash,status,updated_by,updated_at FROM article_revisions WHERE article_id=:id', ['id'=>$id]);
            if ($revision) {
                $data = json_decode((string)$revision['payload_json'], true);
                if (!is_array($data)) throw new RuntimeException('نسخه در حال ویرایش قابل خواندن نیست؛ لطفاً از پشتیبان بازیابی کنید.');
                $media = !empty($data['cover_media_id']) ? DB::fetchOne('SELECT file_path_800 FROM media WHERE id=:id', ['id'=>(int)$data['cover_media_id']]) : null;
                $data['cover_url'] = $media['file_path_800'] ?? null;
                $article['revision'] = [
                    'status'=>(string)$revision['status'],
                    'updated_by'=>(int)$revision['updated_by'],
                    'updated_at'=>(string)$revision['updated_at'],
                    'base_conflict'=>!hash_equals((string)$revision['base_hash'], self::liveHash($id)),
                    'data'=>$data,
                ];
            }
        }
        $workingItems = $article['revision']['data']['items'] ?? [];
        $article['item_display'] = (object)self::itemDisplayInfo(array_merge($items, is_array($workingItems) ? $workingItems : []));
        $article['edit_token'] = isset($revision) && $revision
            ? hash('sha256', (string)$revision['status'] . "\n" . (string)$revision['payload_json'])
            : self::liveHash($id);
        return $article;
    }

    /** Explain public eligibility without changing or publishing any business or article. */
    private static function itemDisplayInfo(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $id = is_array($item) ? (int)($item['business_id'] ?? 0) : 0;
            if ($id > 0) $ids[$id] = $id;
        }
        if (!$ids) return [];
        $rows = DB::fetchAll('SELECT b.id,b.status,b.city_id,b.category_id,c.id AS joined_city_id,cat.id AS joined_category_id
            FROM businesses b LEFT JOIN cities c ON c.id=b.city_id LEFT JOIN categories cat ON cat.id=b.category_id
            WHERE b.id IN (' . implode(',', array_values($ids)) . ')');
        $byId = [];
        foreach ($rows as $row) $byId[(int)$row['id']] = $row;
        $info = [];
        foreach ($ids as $id) {
            $row = $byId[$id] ?? null;
            $eligibility = !$row ? 'missing' : ((string)$row['status'] !== 'published' ? 'not_published'
                : (empty($row['joined_city_id']) || empty($row['joined_category_id']) ? 'missing_context' : 'eligible'));
            $info[(string)$id] = ['eligibility'=>$eligibility, 'business_status'=>$row['status'] ?? null,
                'city_id'=>isset($row['city_id']) ? (int)$row['city_id'] : null,
                'category_id'=>isset($row['category_id']) ? (int)$row['category_id'] : null];
        }
        return $info;
    }

    /** Hash content that a published revision is based on, to avoid overwriting newer live edits. */
    public static function liveHash(int $id): string
    {
        $article = DB::fetchOne('SELECT id,title,slug,meta_title,meta_description,category_id,city_id,intro,tips_html,year_label,status,ai_generated,published_at FROM articles WHERE id=:id', ['id'=>$id]);
        $items = DB::fetchAll('SELECT business_id,position,custom_desc,highlights,is_sponsored,sponsor_label,sponsor_start,sponsor_end FROM article_items WHERE article_id=:id ORDER BY position,id', ['id'=>$id]);
        $faqs = DB::fetchAll('SELECT question,answer,sort FROM article_faqs WHERE article_id=:id ORDER BY sort,id', ['id'=>$id]);
        $extra = self::schemaReady() ? DB::fetchOne('SELECT cover_media_id FROM article_extras WHERE article_id=:id', ['id'=>$id]) : null;
        $payload = [$article,$items,$faqs,$extra];
        $tags = self::tagsForArticle($id);
        // Keep hashes made before the taxonomy migration valid for untagged articles.
        if ($tags) $payload[] = $tags;
        return hash('sha256', (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function dataFromArticle(?array $article): array
    {
        if (!$article) return [];
        return [
            'title_fa'=>$article['title_fa'], 'slug'=>$article['slug'],
            'city_id'=>$article['city_id'], 'category_id'=>$article['category_id'],
            'meta_title'=>$article['meta_title'], 'meta_description'=>$article['meta_description'],
            'intro_html'=>$article['intro_html'], 'methodology_html'=>$article['methodology_html'],
            'cover_media_id'=>$article['cover_media_id'], 'items'=>$article['items'], 'faqs'=>$article['faqs'], 'tags'=>$article['tags'] ?? [],
        ];
    }

    /** Merge absent properties with the previous version instead of silently erasing them. */
    private static function normalize(array $input, array $previous, int $id): array
    {
        $value = static function (array $keys, mixed $default = null) use ($input, $previous): mixed {
            foreach ($keys as $key) if (array_key_exists($key, $input)) return $input[$key];
            foreach ($keys as $key) if (array_key_exists($key, $previous)) return $previous[$key];
            return $default;
        };
        $title = trim((string)$value(['title_fa','title'], ''));
        if ($title === '' || mb_strlen($title) > 255) throw new InvalidArgumentException('عنوان مقاله الزامی است و باید حداکثر ۲۵۵ نویسه باشد.');
        $slugInput = strtolower(trim((string)$value(['slug'], '')));
        if ($slugInput === '') $slugInput = SlugService::suggestSlug($title, 'article');
        if (strlen($slugInput) > 200) throw new InvalidArgumentException('نشانی مقاله بیش از حد طولانی است.');
        $check = SlugService::validateSlug($slugInput);
        if (!$check['ok']) throw new InvalidArgumentException((string)$check['error']);
        $slug = SlugService::ensureUniqueSlug('articles', $slugInput, $id > 0 ? $id : null);
        $cityId = (int)$value(['city_id'], 0) ?: null;
        $categoryId = (int)$value(['category_id'], 0) ?: null;
        if ($cityId && !DB::fetchOne('SELECT id FROM cities WHERE id=:id', ['id'=>$cityId])) throw new InvalidArgumentException('شهر انتخاب‌شده یافت نشد.');
        if ($categoryId && !DB::fetchOne('SELECT id FROM categories WHERE id=:id', ['id'=>$categoryId])) throw new InvalidArgumentException('دسته‌بندی انتخاب‌شده یافت نشد.');
        $metaTitle = (string)$value(['meta_title'], '');
        $metaDescription = (string)$value(['meta_description','subtitle_fa'], '');
        if (mb_strlen($metaTitle) > 255 || mb_strlen($metaDescription) > 320) throw new InvalidArgumentException('عنوان یا توضیح جست‌وجو بیش از حد طولانی است.');
        $intro = (string)$value(['intro_html','intro'], '');
        $tips = (string)$value(['methodology_html','tips_html'], '');
        if (strlen($intro) > 1000000 || strlen($tips) > 1000000) throw new InvalidArgumentException('متن مقاله بیش از حد طولانی است.');
        $intro = ArticleText::normalizeInput($intro, (string)($previous['intro_html'] ?? ''));
        if (strlen($intro) > 1000000) throw new InvalidArgumentException('متن مقاله بیش از حد طولانی است.');
        $cover = (int)$value(['cover_media_id'], 0) ?: null;
        if ($cover && !DB::fetchOne('SELECT id FROM media WHERE id=:id', ['id'=>$cover])) throw new InvalidArgumentException('تصویر مقاله یافت نشد.');

        $previousItems = [];
        foreach (($previous['items'] ?? []) as $item) $previousItems[(int)($item['business_id'] ?? 0)] = $item;
        $rawItems = $value(['items'], []);
        if (!is_array($rawItems) || count($rawItems) > 100) throw new InvalidArgumentException('فهرست کسب‌وکارها نامعتبر یا بیش از ۱۰۰ مورد است.');
        $items = []; $seen = [];
        foreach ($rawItems as $index=>$raw) {
            if (!is_array($raw)) throw new InvalidArgumentException('یکی از کسب‌وکارهای مقاله نامعتبر است.');
            $businessId = (int)($raw['business_id'] ?? 0);
            if ($businessId <= 0 || isset($seen[$businessId])) throw new InvalidArgumentException('کسب‌وکار تکراری یا نامعتبر در مقاله وجود دارد.');
            $seen[$businessId] = true;
            if (!isset($previousItems[$businessId]) && !DB::fetchOne('SELECT id FROM businesses WHERE id=:id', ['id'=>$businessId])) throw new InvalidArgumentException('یکی از کسب‌وکارهای انتخاب‌شده یافت نشد.');
            $prior = $previousItems[$businessId] ?? [];
            $merged = array_merge($prior, $raw);
            $originalHighlights = $prior['highlights'] ?? ($merged['highlights'] ?? null);
            $highlights = json_decode((string)($originalHighlights ?? ''), true);
            if (!is_array($highlights) && $originalHighlights !== null && $originalHighlights !== '') {
                // Do not silently replace legacy/unparseable data when another field is edited.
                foreach (['badge_fa','verdict_fa','pros','cons'] as $field) {
                    $default = in_array($field, ['pros','cons'], true) ? [] : '';
                    if (array_key_exists($field, $raw) && $raw[$field] !== ($prior[$field] ?? $default)) {
                        throw new RuntimeException('اطلاعات قدیمی یکی از گزینه‌ها قابل خواندن نیست؛ تغییر جزئیات آن بدون بررسی دستی ممکن نیست.');
                    }
                }
                $merged['highlights'] = (string)$originalHighlights;
            } else {
                if (!is_array($highlights)) $highlights = [];
                foreach (['badge_fa'=>'badge','verdict_fa'=>'verdict','pros'=>'pros','cons'=>'cons'] as $source=>$target) {
                    if (!array_key_exists($source, $merged)) continue;
                    // An old non-array value must survive the empty-array view exposed to the form.
                    if (in_array($source, ['pros','cons'], true) && isset($highlights[$target]) && !is_array($highlights[$target]) && $merged[$source] === []) continue;
                    $highlights[$target] = $merged[$source];
                }
                $merged['highlights'] = (string)json_encode($highlights, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $merged['business_id'] = $businessId;
            $merged['rank_num'] = (int)($merged['rank_num'] ?? ($index + 1));
            $merged['custom_desc_html'] = (string)($merged['custom_desc_html'] ?? $merged['custom_desc'] ?? '');
            $merged['is_sponsored'] = !empty($merged['is_sponsored']) ? 1 : 0;
            $merged['sponsor_label'] = array_key_exists('sponsor_label', $merged)
                ? ($merged['sponsor_label'] === null ? null : mb_substr((string)$merged['sponsor_label'], 0, 80))
                : 'تبلیغ';
            $merged['sponsor_start'] = !empty($merged['sponsor_start']) ? (string)$merged['sponsor_start'] : null;
            $merged['sponsor_end'] = !empty($merged['sponsor_end']) ? (string)$merged['sponsor_end'] : null;
            foreach (['sponsor_start','sponsor_end'] as $field) {
                if ($merged[$field] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/D', $merged[$field])) throw new InvalidArgumentException('تاریخ جایگاه ویژه معتبر نیست.');
                if ($merged[$field] !== null) $merged[$field] = str_replace('T', ' ', $merged[$field]);
            }
            $items[] = $merged;
        }
        $tags = [];
        if (self::tagsSchemaReady()) {
            $rawTags = $value(['tags'], []);
            if (!is_array($rawTags) || count($rawTags) > 20) throw new InvalidArgumentException('حداکثر ۲۰ برچسب موضوعی برای هر مقاله مجاز است.');
            $seenTags = [];
            foreach ($rawTags as $rawTag) {
                $name = trim((string)(is_array($rawTag) ? ($rawTag['name_fa'] ?? '') : $rawTag));
                if ($name === '') continue;
                if (mb_strlen($name) > 50 || preg_match('/[<>\\x00-\\x1F\\x7F]/u', $name)) throw new InvalidArgumentException('نام برچسب باید حداکثر ۵۰ نویسه و بدون نشانهٔ HTML باشد.');
                $key = mb_strtolower(SlugService::normalizePersianText($name), 'UTF-8');
                if (isset($seenTags[$key])) continue;
                $seenTags[$key] = true;
                $tags[] = $name;
            }
        } else {
            $tags = is_array($previous['tags'] ?? null) ? $previous['tags'] : [];
        }
        $rawFaqs = $value(['faqs'], []);
        if (!is_array($rawFaqs) || count($rawFaqs) > 100) throw new InvalidArgumentException('فهرست پرسش‌ها نامعتبر است.');
        $faqs = [];
        foreach ($rawFaqs as $index=>$faq) {
            if (!is_array($faq)) throw new InvalidArgumentException('یکی از پرسش‌ها نامعتبر است.');
            $q = (string)($faq['question_fa'] ?? $faq['question'] ?? '');
            $a = (string)($faq['answer_fa'] ?? $faq['answer'] ?? '');
            if (trim($q) === '' && trim($a) === '') continue;
            if (trim($q) === '' || trim($a) === '' || mb_strlen($q) > 500) throw new InvalidArgumentException('برای هر پرسش، سؤال و پاسخ کامل وارد کنید.');
            $faqs[] = ['question_fa'=>$q, 'answer_fa'=>$a, 'sort_order'=>(int)($faq['sort_order'] ?? $index + 1)];
        }
        return ['title_fa'=>$title,'slug'=>$slug,'city_id'=>$cityId,'category_id'=>$categoryId,
            'meta_title'=>$metaTitle,'meta_description'=>$metaDescription,'intro_html'=>$intro,'methodology_html'=>$tips,
            'cover_media_id'=>$cover,'items'=>$items,'faqs'=>$faqs,'tags'=>$tags];
    }

    /** Save a draft, submit for review, or explicitly publish as an admin. */
    public static function save(array $input, array $user): array
    {
        self::requireSchema();
        $id = (int)($input['id'] ?? 0);
        $status = (string)($input['status'] ?? 'draft');
        if (!in_array($status, ['draft','review','published'], true)) throw new InvalidArgumentException('وضعیت مقاله نامعتبر است.');
        if (($user['role'] ?? '') !== 'admin' && $status === 'published') $status = 'review';
        $pdo = DB::connection();
        $pdo->beginTransaction();
        try {
            if ($id > 0 && !DB::fetchOne('SELECT id FROM articles WHERE id=:id FOR UPDATE', ['id'=>$id])) throw new InvalidArgumentException('مقاله یافت نشد.');
            $article = $id > 0 ? self::load($id) : null;
            if ($article && isset($input['edit_token']) && !hash_equals((string)$article['edit_token'], (string)$input['edit_token'])) {
                throw new RuntimeException('این مقاله در جای دیگری تغییر کرده است. برای جلوگیری از حذف تغییرات، آن را دوباره باز کنید.');
            }
            $revisionRow = $id > 0 ? DB::fetchOne('SELECT * FROM article_revisions WHERE article_id=:id FOR UPDATE', ['id'=>$id]) : null;
            $previous = $revisionRow ? json_decode((string)$revisionRow['payload_json'], true) : self::dataFromArticle($article);
            if (!is_array($previous)) throw new RuntimeException('نسخه در حال ویرایش قابل خواندن نیست.');
            $data = self::normalize($input, $previous, $id);
            if (in_array($status, ['review','published'], true) && !ArticleText::hasVisibleText($data['intro_html']) && count($data['items']) === 0) {
                throw new InvalidArgumentException('برای ارسال یا انتشار، متن مقاله را بنویسید یا دست‌کم یک کسب‌وکار به فهرست اضافه کنید.');
            }

            if ($article && $article['status'] === 'published' && $status !== 'published') {
                $baseHash = $revisionRow ? (string)$revisionRow['base_hash'] : self::liveHash($id);
                DB::query("INSERT INTO article_revisions (article_id,payload_json,base_hash,status,updated_by,updated_at)
                    VALUES (:id,:payload,:base,:status,:user,NOW()) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json),status=VALUES(status),updated_by=VALUES(updated_by),updated_at=NOW()",
                    ['id'=>$id,'payload'=>json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),'base'=>$baseHash,'status'=>$status,'user'=>(int)$user['id']]);
                self::syncReview($id, $status, $data['title_fa'], (int)$user['id'], true);
                $workingStatus = $status;
                $publicStatus = 'published';
            } else {
                if ($revisionRow && $article && $article['status'] === 'published' && !hash_equals((string)$revisionRow['base_hash'], self::liveHash($id))) {
                    throw new RuntimeException('نسخه منتشرشده پس از شروع ویرایش تغییر کرده است؛ برای جلوگیری از حذف تغییرات، انتشار انجام نشد.');
                }
                if (!$article) {
                    DB::query("INSERT INTO articles (title,slug,category_id,city_id,year_label,status,ai_generated,created_at,updated_at)
                        VALUES (:title,:slug,:category,:city,'','draft',0,NOW(),NOW())",
                        ['title'=>$data['title_fa'],'slug'=>$data['slug'],'category'=>$data['category_id'],'city'=>$data['city_id']]);
                    $id = DB::lastInsertId();
                }
                self::apply($id, $data, $status);
                if ($revisionRow) DB::query('DELETE FROM article_revisions WHERE article_id=:id', ['id'=>$id]);
                self::syncReview($id, $status, $data['title_fa'], (int)$user['id'], false);
                $publicStatus = $status;
                $workingStatus = null;
            }
            $pdo->commit();
            return ['article_id'=>$id, 'slug'=>$data['slug'], 'status'=>$publicStatus, 'working_status'=>$workingStatus];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Upsert reusable topic labels and replace only this article's tag links. */
    private static function syncTags(int $articleId, array $names): void
    {
        if (!self::tagsSchemaReady()) return;
        DB::query('DELETE FROM article_tag_links WHERE article_id=:id', ['id'=>$articleId]);
        foreach ($names as $name) {
            $tag = DB::fetchOne('SELECT id FROM article_tags WHERE name_fa=:name LIMIT 1', ['name'=>$name]);
            if (!$tag) {
                $base = substr(SlugService::suggestSlug($name, 'topic'), 0, 120);
                for ($suffix=1; $suffix<=100 && !$tag; $suffix++) {
                    $tail = $suffix === 1 ? '' : '-' . $suffix;
                    $slug = substr($base, 0, 140 - strlen($tail)) . $tail;
                    if (DB::fetchOne('SELECT id FROM article_tags WHERE slug=:slug LIMIT 1', ['slug'=>$slug])) continue;
                    DB::query('INSERT IGNORE INTO article_tags (name_fa,slug) VALUES (:name,:slug)', ['name'=>$name,'slug'=>$slug]);
                    $tag = DB::fetchOne('SELECT id FROM article_tags WHERE name_fa=:name LIMIT 1', ['name'=>$name]);
                }
            }
            if (!$tag) throw new RuntimeException('ذخیره یکی از برچسب‌های موضوعی ممکن نشد؛ دوباره تلاش کنید.');
            DB::query('INSERT IGNORE INTO article_tag_links (article_id,tag_id) VALUES (:article,:tag)', ['article'=>$articleId,'tag'=>(int)$tag['id']]);
        }
    }

    /** Caller owns a transaction and has locked the article row. */
    private static function apply(int $id, array $data, string $status): void
    {
        $current = DB::fetchOne('SELECT slug,status,published_at FROM articles WHERE id=:id', ['id'=>$id]);
        if (!$current) throw new RuntimeException('مقاله یافت نشد.');
        $availableSlug = SlugService::ensureUniqueSlug('articles', (string)$data['slug'], $id);
        if ($availableSlug !== (string)$data['slug']) throw new RuntimeException('نشانی انتخاب‌شده اکنون برای مقاله دیگری استفاده می‌شود؛ لطفاً نشانی دیگری انتخاب کنید.');
        if ((string)$current['slug'] !== (string)$data['slug'] && ($current['status'] === 'published' || !empty($current['published_at']))) {
            $alias = DB::fetchOne('SELECT article_id FROM article_url_aliases WHERE slug=:slug FOR UPDATE', ['slug'=>$current['slug']]);
            if ($alias && (int)$alias['article_id'] !== $id) throw new RuntimeException('نشانی قبلی مقاله به مقاله دیگری تعلق دارد.');
            DB::query('INSERT IGNORE INTO article_url_aliases (slug,article_id) VALUES (:slug,:id)', ['slug'=>$current['slug'],'id'=>$id]);
        }
        DB::query("UPDATE articles SET title=:title,slug=:slug,city_id=:city,category_id=:category,
            meta_title=:meta_title,meta_description=:meta_desc,intro=:intro,tips_html=:tips,status=:status,
            published_at=CASE WHEN :status2='published' AND published_at IS NULL THEN NOW() ELSE published_at END,updated_at=NOW() WHERE id=:id",
            ['title'=>$data['title_fa'],'slug'=>$data['slug'],'city'=>$data['city_id'],'category'=>$data['category_id'],
             'meta_title'=>$data['meta_title'] !== '' ? $data['meta_title'] : null,
             'meta_desc'=>$data['meta_description'] !== '' ? $data['meta_description'] : null,
             'intro'=>$data['intro_html'] !== '' ? $data['intro_html'] : null,'tips'=>$data['methodology_html'] !== '' ? $data['methodology_html'] : null,
             'status'=>$status,'status2'=>$status,'id'=>$id]);
        DB::query('INSERT INTO article_extras (article_id,cover_media_id) VALUES (:id,:cover) ON DUPLICATE KEY UPDATE cover_media_id=VALUES(cover_media_id)', ['id'=>$id,'cover'=>$data['cover_media_id']]);
        DB::query('DELETE FROM article_items WHERE article_id=:id', ['id'=>$id]);
        foreach ($data['items'] as $item) {
            DB::query('INSERT INTO article_items (article_id,business_id,position,custom_desc,highlights,is_sponsored,sponsor_label,sponsor_start,sponsor_end)
                VALUES (:id,:business,:position,:description,:highlights,:sponsored,:label,:start,:end)',
                ['id'=>$id,'business'=>$item['business_id'],'position'=>$item['rank_num'],'description'=>$item['custom_desc_html'],
                 'highlights'=>$item['highlights'],'sponsored'=>$item['is_sponsored'],'label'=>$item['sponsor_label'],
                 'start'=>$item['sponsor_start'],'end'=>$item['sponsor_end']]);
        }
        DB::query('DELETE FROM article_faqs WHERE article_id=:id', ['id'=>$id]);
        foreach ($data['faqs'] as $faq) {
            DB::query('INSERT INTO article_faqs (article_id,question,answer,sort) VALUES (:id,:q,:a,:sort)',
                ['id'=>$id,'q'=>$faq['question_fa'],'a'=>$faq['answer_fa'],'sort'=>$faq['sort_order']]);
        }
        self::syncTags($id, is_array($data['tags'] ?? null) ? $data['tags'] : []);
    }

    private static function syncReview(int $id, string $status, string $title, int $userId, bool $revision): void
    {
        if ($status === 'review') {
            $payload = ['article_id'=>$id,'title'=>$title,'revision'=>$revision];
            $pending = DB::fetchOne("SELECT id FROM review_queue WHERE kind='article' AND target_id=:id AND status='pending' LIMIT 1 FOR UPDATE", ['id'=>$id]);
            if ($pending) {
                DB::query('UPDATE review_queue SET payload_json=:payload WHERE id=:id', ['payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),'id'=>(int)$pending['id']]);
            } else {
                PipelineService::enqueueReviewIfNotExists('article', $id, 'manual_review', $payload);
            }
        } else {
            DB::query("UPDATE review_queue SET status=:status,decided_by=:user,decided_at=NOW() WHERE kind='article' AND target_id=:id AND status='pending'",
                ['status'=>$status === 'published' ? 'approved' : 'rejected','user'=>$userId,'id'=>$id]);
        }
    }

    /** Called by the admin review queue inside its transaction. */
    public static function decideReview(int $id, string $decision): string
    {
        $article = DB::fetchOne('SELECT status FROM articles WHERE id=:id FOR UPDATE', ['id'=>$id]);
        if (!$article) return 'skipped';
        $revision = self::schemaReady() ? DB::fetchOne('SELECT * FROM article_revisions WHERE article_id=:id FOR UPDATE', ['id'=>$id]) : null;
        if ($revision && $article['status'] === 'published') {
            if ($revision['status'] !== 'review') return 'skipped';
            if ($decision === 'rejected') {
                DB::query("UPDATE article_revisions SET status='draft',updated_at=NOW() WHERE article_id=:id", ['id'=>$id]);
                return 'applied';
            }
            if (!hash_equals((string)$revision['base_hash'], self::liveHash($id))) return 'conflict';
            $data = json_decode((string)$revision['payload_json'], true);
            if (!is_array($data)) return 'conflict';
            if (SlugService::ensureUniqueSlug('articles', (string)$data['slug'], $id) !== (string)$data['slug']) return 'conflict';
            if (!ArticleText::hasVisibleText((string)$data['intro_html']) && empty($data['items'])) return 'conflict';
            self::apply($id, $data, 'published');
            DB::query('DELETE FROM article_revisions WHERE article_id=:id', ['id'=>$id]);
            return 'applied';
        }
        if ($article['status'] !== 'review') return 'skipped';
        if ($decision === 'approved') {
            DB::query("UPDATE articles SET status='published',published_at=COALESCE(published_at,NOW()),updated_at=NOW() WHERE id=:id", ['id'=>$id]);
        } else {
            DB::query("UPDATE articles SET status='draft',updated_at=NOW() WHERE id=:id", ['id'=>$id]);
        }
        return 'applied';
    }
}
