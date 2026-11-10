<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Request;
use PDO;
use Throwable;

/**
 * S9 — ماژول جامع آمار بازدید و کلیک.
 *
 * سطح اول (analytics_daily): تجمیع ماندگار روزانه به تفکیک کسب‌وکار/مقاله/شهر/دسته/بنر.
 * سطح دوم (analytics_events): لاگ جزئیات با IP، منبع، UTM، دستگاه و مرورگر که فقط
 * تا «بازه نگهداری» انتخابی مدیر حفظ می‌شود و در کرون به‌صورت خودکار پاک‌سازی می‌گردد.
 *
 * ربات‌ها و کراولرها فیلتر می‌شوند؛ هیچ خطایی به سایت عمومی بازنمی‌گردد و شکست
 * ثبت آمار هرگز نباید جلوی رندر صفحه را بگیرد. هیچ کلید یا رمزی اینجا وجود ندارد.
 */
final class AnalyticsService
{
    /** ستون تجمیع هر نوع رویداد — فقط از این فهرست سفید استفاده می‌شود. */
    private const EVENT_COLUMNS = [
        'view' => 'views',
        'call' => 'clicks_call',
        'map' => 'clicks_map',
        'website' => 'clicks_website',
        'instagram' => 'clicks_instagram',
        'whatsapp' => 'clicks_whatsapp',
        'banner' => 'clicks_banner',
        'search' => 'searches',
    ];

    private const TARGET_TYPES = ['business', 'article', 'city', 'category', 'banner'];

    /** فیلتر ربات‌ها/کراولرها و کلاینت‌های غیر مرورگر (نکته: اپ‌های پیام‌رسان انسانی هستند و فیلتر نمی‌شوند). */
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|mediapartners|facebookexternalhit|facebookcatalog|telegrambot|twitterbot|linkedinbot|discordbot|embedly|quora|monit|uptime|pingdom|gtmetrix|lighthouse|pagespeed|headless|phantomjs|python|curl|wget|httpclient|okhttp|java\/|go-http|scrapy|yandex|baidu|sogou|ia_archiver|semrush|ahrefs|mj12bot|dotbot|petalbot|bytespider|amazonbot|gptbot|claudebot|anthropic|perplexity|applebot|googlebot|bingbot|duckduck/i';

    // ---------------------------------------------------------------- ثبت رویداد

    /**
     * ثبت یک رویداد آماری (بازدید/کلیک/جستجو). همیشه بی‌صدا شکست می‌خورد.
     *
     * @param array<string, mixed> $payload — کلیدهای مجاز: business_id, article_id, city_id,
     *   category_id, banner_id, target_title, path, referrer, search_query, ip, user_agent,
     *   site_host, utm{source,medium,campaign,term}
     * @return array{status:string, target_type?:string, target_id?:int}
     */
    public static function record(string $eventType, array $payload): array
    {
        try {
            if (!isset(self::EVENT_COLUMNS[$eventType])) return ['status' => 'invalid_event'];
            $ua = self::truncate(self::cleanText((string) ($payload['user_agent'] ?? '')), 300);
            if (self::isBot($ua)) return ['status' => 'skipped_bot'];
            $ip = self::truncate((string) ($payload['ip'] ?? ''), 45);
            if ($ip === '') return ['status' => 'skipped_no_ip'];

            $path = self::truncate(self::cleanText((string) ($payload['path'] ?? '')), 190);
            foreach (['/admin', '/api', '/install.php', '/check.php', '/cache/'] as $blocked) {
                if ($blocked !== '' && str_starts_with($path, $blocked)) return ['status' => 'skipped_path'];
            }

            $target = self::resolveTarget($payload);
            $day = date('Y-m-d');
            $visitorHash = self::visitorHash($ip, $ua, $day);
            $referrer = self::truncate(self::cleanText((string) ($payload['referrer'] ?? '')), 500);
            $ref = self::classifyReferrer($referrer, self::cleanHost((string) ($payload['site_host'] ?? '')));
            $device = self::parseDevice($ua);
            $browser = self::parseBrowser($ua);
            $query = self::truncate(self::cleanText((string) ($payload['search_query'] ?? '')), 190);
            $utm = self::utmFields(is_array($payload['utm'] ?? null) ? $payload['utm'] : []);
            $column = self::EVENT_COLUMNS[$eventType];

            // سطح اول — تجمیع ماندگار روزانه
            if ($target['type'] !== 'none') {
                DB::query(
                    "INSERT INTO analytics_daily (day, target_type, target_id, {$column})
                     VALUES (:d, :t, :i, 1)
                     ON DUPLICATE KEY UPDATE {$column} = {$column} + 1",
                    ['d' => $day, 't' => $target['type'], 'i' => $target['id']]
                );
                if ($eventType === 'view') {
                    $inserted = DB::query(
                        'INSERT IGNORE INTO analytics_visitor_days (day, visitor_hash, target_type, target_id)
                         VALUES (:d, :v, :t, :i)',
                        ['d' => $day, 'v' => $visitorHash, 't' => $target['type'], 'i' => $target['id']]
                    )->rowCount();
                    if ($inserted === 1) {
                        DB::query(
                            'UPDATE analytics_daily SET unique_visitors = unique_visitors + 1
                             WHERE day = :d AND target_type = :t AND target_id = :i',
                            ['d' => $day, 't' => $target['type'], 'i' => $target['id']]
                        );
                    }
                }
            }

            // سطح دوم — لاگ جزئیات (تا بازه نگهداری انتخابی مدیر)
            DB::query(
                'INSERT INTO analytics_events
                 (occurred_at, event_type, target_type, target_id, target_title, path, visitor_hash, ip_address,
                  referrer, ref_source, ref_host, utm_source, utm_medium, utm_campaign, utm_term, device, browser,
                  search_query, user_agent)
                 VALUES (NOW(), :et, :tt, :ti, :title, :path, :vh, :ip, :ref, :rsrc, :rhost,
                         :us, :um, :uc, :ut, :dev, :br, :q, :ua)',
                [
                    'et' => $eventType, 'tt' => $target['type'], 'ti' => $target['id'],
                    'title' => self::truncate($target['title'], 190), 'path' => $path,
                    'vh' => $visitorHash, 'ip' => $ip, 'ref' => $referrer,
                    'rsrc' => $ref['source'], 'rhost' => self::truncate($ref['host'], 120),
                    'us' => $utm['utm_source'], 'um' => $utm['utm_medium'],
                    'uc' => $utm['utm_campaign'], 'ut' => $utm['utm_term'],
                    'dev' => $device, 'br' => $browser, 'q' => $query, 'ua' => $ua,
                ]
            );
            return ['status' => 'ok', 'target_type' => $target['type'], 'target_id' => $target['id']];
        } catch (Throwable $e) {
            error_log('Analytics record failed: ' . get_class($e));
            return ['status' => 'error'];
        }
    }

    /** ثبت جستجوی داخلی سایت به‌صورت سمت سرور (صفحات جستجو کش نمی‌شوند). */
    public static function recordSearch(array $payload): array
    {
        $payload['search_query'] = $payload['query'] ?? '';
        return self::record('search', $payload);
    }

    /** اطلاعات محیطی درخواست برای ثبت آمار؛ فقط IP، UA و هاست سایت. */
    public static function requestContext(Request $req): array
    {
        return [
            'ip' => $req->ip(),
            'user_agent' => (string) ($req->header('User-Agent', '') ?? ''),
            'site_host' => (string) ($req->header('Host', '') ?? ''),
        ];
    }

    // ---------------------------------------------------------------- داشبورد پنل

    /**
     * خروجی داشبورد آمار پنل مدیریت: سری روزانه، جمع کل و برترین‌ها.
     *
     * @return array<string, mixed>
     */
    public static function dashboard(array $filters = []): array
    {
        $range = self::rangeFromFilters($filters);
        $from = $range['from'];
        $to = $range['to'];
        $params = ['from' => $from, 'to' => $to];

        $series = DB::fetchAll(
            'SELECT day,
                    SUM(views) AS views, SUM(unique_visitors) AS uniques,
                    SUM(clicks_call) AS call, SUM(clicks_map) AS map,
                    SUM(clicks_website) AS website, SUM(clicks_instagram) AS instagram,
                    SUM(clicks_whatsapp) AS whatsapp, SUM(clicks_banner) AS banner,
                    SUM(searches) AS searches
             FROM analytics_daily
             WHERE day BETWEEN :from AND :to
             GROUP BY day ORDER BY day',
            $params
        );

        $totals = ['views' => 0, 'uniques' => 0, 'call' => 0, 'map' => 0, 'website' => 0, 'instagram' => 0, 'whatsapp' => 0, 'banner' => 0, 'searches' => 0];
        foreach ($series as $row) {
            foreach ($totals as $k => $v) {
                $totals[$k] = $v + (int) $row[$k];
            }
        }

        $typeFilter = is_string($filters['target_type'] ?? null) ? $filters['target_type'] : '';
        $types = in_array($typeFilter, self::TARGET_TYPES, true) ? [$typeFilter] : self::TARGET_TYPES;
        $tops = [];
        foreach ($types as $type) {
            $tops[$type] = self::topTargets($type, $from, $to, (int) ($filters['target_id'] ?? 0), 25);
        }

        return [
            'from' => $from, 'to' => $to,
            'series' => $series, 'totals' => $totals, 'tops' => $tops,
            'available_types' => self::TARGET_TYPES,
        ];
    }

    /** برترین‌های یک نوع هدف در بازه، با عنوان واقعی از جدول مربوطه. */
    private static function topTargets(string $type, string $from, string $to, int $onlyId, int $limit): array
    {
        [$join, $titleExpr] = self::joinFor($type);
        $sql = "SELECT d.target_id AS id, {$titleExpr} AS title,
                       SUM(d.views) AS views, SUM(d.unique_visitors) AS uniques,
                       SUM(d.clicks_call) AS call, SUM(d.clicks_map) AS map,
                       SUM(d.clicks_website) AS website, SUM(d.clicks_instagram) AS instagram,
                       SUM(d.clicks_whatsapp) AS whatsapp, SUM(d.clicks_banner) AS banner,
                       SUM(d.searches) AS searches
                FROM analytics_daily d {$join}
                WHERE d.day BETWEEN :from AND :to AND d.target_type = :type";
        $params = ['from' => $from, 'to' => $to, 'type' => $type];
        if ($onlyId > 0) {
            $sql .= ' AND d.target_id = :only';
            $params['only'] = $onlyId;
        }
        $sql .= " GROUP BY d.target_id ORDER BY views DESC, uniques DESC LIMIT {$limit}";
        return DB::fetchAll($sql, $params);
    }

    /** JOIN و عبارت عنوان هر نوع هدف؛ همهٔ JOINها روی PK جدول اصلی هستند. */
    private static function joinFor(string $type): array
    {
        return match ($type) {
            'business' => ['LEFT JOIN businesses b ON b.id = d.target_id', "COALESCE(b.name, CONCAT('#', d.target_id))"],
            'article' => ['LEFT JOIN articles a ON a.id = d.target_id', "COALESCE(a.title, CONCAT('#', d.target_id))"],
            'city' => ['LEFT JOIN cities c ON c.id = d.target_id', "COALESCE(c.name_fa, CONCAT('#', d.target_id))"],
            'category' => ['LEFT JOIN categories k ON k.id = d.target_id', "COALESCE(k.name_fa, CONCAT('#', d.target_id))"],
            'banner' => ['LEFT JOIN advertising_banners bt ON bt.id = d.target_id', "COALESCE(bt.title, CONCAT('#', d.target_id))"],
            default => ['', "CONCAT('#', d.target_id)"],
        };
    }

    // ---------------------------------------------------------------- لاگ جزئیات

    /**
     * صفحه‌بندی لاگ جزئیات با فیلتر بازه، نوع رویداد، نوع هدف و جستجوی متنی.
     *
     * @return array<string, mixed>
     */
    public static function logs(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        [$where, $params] = self::logWhere($filters);
        $perPage = max(10, min(100, $perPage));
        $page = max(1, $page);
        $total = (int) (DB::fetchOne("SELECT COUNT(*) AS c FROM analytics_events {$where}", $params)['c'] ?? 0);
        $offset = ($page - 1) * $perPage;
        $rows = DB::fetchAll(
            "SELECT id, occurred_at, event_type, target_type, target_id, target_title, path, ip_address,
                    referrer, ref_source, ref_host, utm_source, utm_medium, utm_campaign, utm_term,
                    device, browser, search_query
             FROM analytics_events {$where}
             ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        return [
            'items' => $rows,
            'pagination' => [
                'page' => $page, 'per_page' => $perPage, 'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /** بازگرداندن PDOStatement لاگ‌ها برای استریم خروجی اکسل بدون مصرف حافظه. */
    public static function logStream(array $filters = [])
    {
        [$where, $params] = self::logWhere($filters);
        return DB::query(
            "SELECT occurred_at, event_type, target_type, target_id, target_title, path, ip_address,
                    ref_source, ref_host, referrer, utm_source, utm_medium, utm_campaign, utm_term,
                    device, browser, search_query
             FROM analytics_events {$where}
             ORDER BY id DESC LIMIT 200000",
            $params
        );
    }

    /** ردیف‌های گزارش تجمیعی خروجی اکسل (یک ردیف به‌ازای هر هدف). */
    public static function aggregateRows(array $filters = []): array
    {
        $range = self::rangeFromFilters($filters);
        $typeFilter = is_string($filters['target_type'] ?? null) ? $filters['target_type'] : '';
        $types = in_array($typeFilter, self::TARGET_TYPES, true) ? [$typeFilter] : self::TARGET_TYPES;
        $rows = [];
        foreach ($types as $type) {
            $rows = array_merge($rows, self::topTargets($type, $range['from'], $range['to'], (int) ($filters['target_id'] ?? 0), 20000));
        }
        return ['from' => $range['from'], 'to' => $range['to'], 'rows' => $rows];
    }

    /** ساخت شرط WHERE مشترک لاگ‌ها با فیلترهای اعتبارسنجی‌شده. */
    private static function logWhere(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];
        $range = self::rangeFromFilters($filters);
        $where[] = 'occurred_at >= :w_from AND occurred_at <= :w_to';
        $params['w_from'] = $range['from'] . ' 00:00:00';
        $params['w_to'] = $range['to'] . ' 23:59:59';
        if (isset(self::EVENT_COLUMNS[$filters['event_type'] ?? ''])) {
            $where[] = 'event_type = :w_et';
            $params['w_et'] = $filters['event_type'];
        }
        $tt = is_string($filters['target_type'] ?? null) ? $filters['target_type'] : '';
        if (in_array($tt, array_merge(self::TARGET_TYPES, ['none']), true)) {
            $where[] = 'target_type = :w_tt';
            $params['w_tt'] = $tt;
        }
        $tid = (int) ($filters['target_id'] ?? 0);
        if ($tid > 0) {
            $where[] = 'target_id = :w_tid';
            $params['w_tid'] = $tid;
        }
        $rs = is_string($filters['ref_source'] ?? null) ? $filters['ref_source'] : '';
        if (preg_match('/^(direct|search|instagram|telegram|social|internal|referral)$/', $rs) === 1) {
            $where[] = 'ref_source = :w_rs';
            $params['w_rs'] = $rs;
        }
        $q = self::cleanText(is_string($filters['q'] ?? null) ? $filters['q'] : '');
        if ($q !== '') {
            $where[] = '(target_title LIKE :w_q OR search_query LIKE :w_q OR ip_address LIKE :w_q OR ref_host LIKE :w_q)';
            $params['w_q'] = '%' . mb_substr($q, 0, 80) . '%';
        }
        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    // ---------------------------------------------------------------- نگهداری و تنظیمات

    /** بازه نگهداری لاگ جزئیات (روز) — پیش‌فرض ۷، مجاز ۳ تا ۳۰. */
    public static function rawRetentionDays(): int
    {
        return self::intSetting('analytics_raw_retention_days', 7, 3, 30);
    }

    public static function visitorRetentionDays(): int
    {
        return self::intSetting('analytics_visitor_retention_days', 2, 1, 7);
    }

    public static function aiRetentionDays(): int
    {
        return self::intSetting('ai_logs_retention_days', 30, 7, 180);
    }

    public static function setRawRetentionDays(int $days): void
    {
        $days = max(3, min(30, $days));
        DB::query(
            "INSERT INTO settings (k, v, updated_at) VALUES ('analytics_raw_retention_days', :v, NOW())
             ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = NOW()",
            ['v' => (string) $days]
        );
    }

    /** ذخیرهٔ آمار سطح اول همیشه ماندگار است؛ فقط لاگ جزئیات، دفتر یکتاها و لاگ AI پاک می‌شوند. */
    public static function prune(): array
    {
        $raw = max(1, self::rawRetentionDays());
        $vis = max(1, self::visitorRetentionDays());
        $ai = max(1, self::aiRetentionDays());
        $r1 = DB::query("DELETE FROM analytics_events WHERE occurred_at < DATE_SUB(NOW(), INTERVAL {$raw} DAY)")->rowCount();
        $r2 = DB::query("DELETE FROM analytics_visitor_days WHERE day < DATE_SUB(CURDATE(), INTERVAL {$vis} DAY)")->rowCount();
        $r3 = DB::query("DELETE FROM ai_service_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL {$ai} DAY)")->rowCount();
        DB::query(
            "INSERT INTO settings (k, v, updated_at) VALUES ('analytics_last_prune', :v, NOW())
             ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = NOW()",
            ['v' => date('Y-m-d H:i:s')]
        );
        return [
            'status' => 'ok',
            'raw_logs_deleted' => $r1,
            'visitor_rows_deleted' => $r2,
            'ai_logs_deleted' => $r3,
        ];
    }

    /** پاک‌سازی هر ساعت یک‌بار کافی است؛ کرون ۵ دقیقه‌ای بدون فشار اضافه اجرا می‌ماند. */
    public static function pruneDue(): bool
    {
        try {
            $last = DB::fetchOne("SELECT v FROM settings WHERE k = 'analytics_last_prune'");
            if ($last === null || !is_string($last['v'] ?? null) || $last['v'] === '') return true;
            $ts = strtotime((string) $last['v']);
            return $ts === false || (time() - $ts) >= 3600;
        } catch (Throwable) {
            return true;
        }
    }

    // ---------------------------------------------------------------- تشخیص‌ها (توابع خالص برای آزمون)

    /** شناسایی ربات/کراولر: UA خالی یا خیلی کوتاه یا تطبیق الگوی شناخته‌شده. */
    public static function isBot(string $userAgent): bool
    {
        if ($userAgent === '' || strlen($userAgent) < 20) return true;
        return preg_match(self::BOT_PATTERN, $userAgent) === 1;
    }

    public static function parseDevice(string $userAgent): string
    {
        if (preg_match('/(tablet|ipad|playbook|silk)|(?:android(?!.*mobile))/i', $userAgent) === 1) return 'tablet';
        if (preg_match('/mobi|iphone|ipod|android.*mobile|windows phone|iemobile|blackberry|opera mini|opera mobi/i', $userAgent) === 1) return 'mobile';
        return 'desktop';
    }

    public static function parseBrowser(string $userAgent): string
    {
        $checks = [
            ['Instagram', 'instagram'],
            ['Telegram', 'telegram'],
            ['WhatsApp', 'whatsapp'],
            ['Edge', 'edg(?:e|a|ios)?/'],
            ['Opera', 'opr/|opera'],
            ['Samsung', 'samsungbrowser'],
            ['UC', 'ucbrowser'],
            ['Firefox', 'fxios|firefox/'],
            ['Chrome', 'crios|chrome/'],
            ['Safari', 'safari'],
            ['Yandex', 'yabrowser'],
        ];
        foreach ($checks as [$name, $pattern]) {
            if (preg_match('@' . $pattern . '@i', $userAgent) === 1) return $name;
        }
        return 'Other';
    }

    /** @return array{source:string, host:string} */
    public static function classifyReferrer(string $referrer, string $siteHost = ''): array
    {
        $host = strtolower((string) (parse_url($referrer, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? '';
        if ($host === '' || !preg_match('/^[a-z0-9._-]+$/', $host)) return ['source' => 'direct', 'host' => ''];
        $site = strtolower(trim($siteHost));
        if ($site !== '' && !str_contains($site, '://')) $site = 'https://' . $site;
        $siteHostParsed = strtolower((string) (parse_url($site, PHP_URL_HOST) ?? ''));
        $siteHostParsed = preg_replace('/^www\./', '', $siteHostParsed) ?? '';
        if ($siteHostParsed !== '' && $host === $siteHostParsed) return ['source' => 'internal', 'host' => $host];
        if (preg_match('/(google\.|bing\.|duckduckgo|yandex|yahoo\.|ecosia|startpage)/', $host) === 1) return ['source' => 'search', 'host' => $host];
        if (preg_match('/(instagram|instagr\.am)/', $host) === 1) return ['source' => 'instagram', 'host' => $host];
        if (preg_match('/(^|\.)(t\.me|telegram\.)/', $host) === 1) return ['source' => 'telegram', 'host' => $host];
        if (preg_match('/(facebook|fb\.com|fb\.watch|twitter\.|(^|\.)x\.com|t\.co|linkedin|pinterest|threads\.net|aparat|youtube|youtu\.be)/', $host) === 1) return ['source' => 'social', 'host' => $host];
        return ['source' => 'referral', 'host' => $host];
    }

    // ---------------------------------------------------------------- کمک‌تابع‌های داخلی

    /** @return array{type:string, id:int, title:string} */
    private static function resolveTarget(array $payload): array
    {
        $candidates = [
            ['business', (int) ($payload['business_id'] ?? 0)],
            ['article', (int) ($payload['article_id'] ?? 0)],
            ['city', (int) ($payload['city_id'] ?? 0)],
            ['category', (int) ($payload['category_id'] ?? 0)],
            ['banner', (int) ($payload['banner_id'] ?? 0)],
        ];
        foreach ($candidates as [$type, $id]) {
            if ($id > 0) {
                $title = trim((string) ($payload['target_title'] ?? ''));
                if ($title === '') $title = self::lookupTitle($type, $id);
                return ['type' => $type, 'id' => $id, 'title' => $title];
            }
        }
        return ['type' => 'none', 'id' => 0, 'title' => ''];
    }

    private static function lookupTitle(string $type, int $id): string
    {
        try {
            $sql = match ($type) {
                'business' => 'SELECT name AS t FROM businesses WHERE id = :id',
                'article' => 'SELECT title AS t FROM articles WHERE id = :id',
                'city' => 'SELECT name_fa AS t FROM cities WHERE id = :id',
                'category' => 'SELECT name_fa AS t FROM categories WHERE id = :id',
                'banner' => 'SELECT title AS t FROM advertising_banners WHERE id = :id',
                default => null,
            };
            if ($sql === null) return '';
            $row = DB::fetchOne($sql, ['id' => $id]);
            return self::truncate((string) ($row['t'] ?? ''), 190);
        } catch (Throwable) {
            return '';
        }
    }

    private static function visitorHash(string $ip, string $ua, string $day): string
    {
        return md5(self::salt() . '|' . $ip . '|' . $ua . '|' . $day);
    }

    /** نمک ناشناس‌سازی پایدار برای هش روزانه بازدیدکننده؛ یک‌بار ساخته و در settings ذخیره می‌شود. */
    private static function salt(): string
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        try {
            $row = DB::fetchOne("SELECT v FROM settings WHERE k = 'analytics_salt'");
            if ($row !== null && is_string($row['v'] ?? null) && strlen((string) $row['v']) >= 16) {
                $cached = (string) $row['v'];
                return $cached;
            }
            $generated = bin2hex(random_bytes(16));
            DB::query(
                "INSERT INTO settings (k, v, updated_at) VALUES ('analytics_salt', :v, NOW())
                 ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = NOW()",
                ['v' => $generated]
            );
            $cached = $generated;
        } catch (Throwable) {
            $cached = 'hhx-analytics-fallback-salt';
        }
        return $cached;
    }

    /** @return array{utm_source:string, utm_medium:string, utm_campaign:string, utm_term:string} */
    private static function utmFields(array $utm): array
    {
        $out = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term'] as $key) {
            $value = self::cleanText(is_string($utm[$key] ?? null) ? $utm[$key] : '');
            $out[$key] = self::truncate($value, 100);
        }
        return $out;
    }

    /** @return array{from:string, to:string} */
    private static function rangeFromFilters(array $filters): array
    {
        $to = self::dateParam($filters['to'] ?? null) ?? date('Y-m-d');
        $from = self::dateParam($filters['from'] ?? null);
        if ($from === null) {
            $days = max(1, min(90, (int) ($filters['days'] ?? 7)));
            $from = date('Y-m-d', strtotime($to . ' -' . ($days - 1) . ' days'));
        }
        if ($from > $to) [$from, $to] = [$to, $from];
        return ['from' => $from, 'to' => $to];
    }

    private static function dateParam(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) return null;
        $ts = strtotime($value);
        return $ts === false ? null : date('Y-m-d', $ts);
    }

    private static function intSetting(string $key, int $default, int $min, int $max): int
    {
        try {
            $row = DB::fetchOne('SELECT v FROM settings WHERE k = :k', ['k' => $key]);
            $value = (int) ($row['v'] ?? $default);
            return max($min, min($max, $value > 0 ? $value : $default));
        } catch (Throwable) {
            return $default;
        }
    }

    private static function cleanText(string $value): string
    {
        return trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $value) ?? '');
    }

    private static function cleanHost(string $host): string
    {
        $host = strtolower(trim($host));
        return preg_match('/^[a-z0-9._-]{1,120}$/', $host) === 1 ? $host : '';
    }

    private static function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) return $value;
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $max, 'UTF-8')
            : substr($value, 0, $max);
    }
}
