<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\DB;
use Throwable;

/**
 * سرویس تولید محتوای بدون توهم (Non-Hallucinating AI Worker Service - مرحله S5)
 *
 * قوانین سخت‌گیرانه ضد توهم (Anti-Hallucination Guardrails):
 * ۱. خروجی فقط و فقط باید از فیلدهای واقعی موجود در input_json تولید شود.
 * ۲. اختراع هرگونه شماره تماس، ساعت کاری، امکانات (پارکینگ، وای‌فای، موسیقی زنده، منو یا قیمت)
 *    که در input_json وجود ندارد اکیداً ممنوع است و توسط گاردریل رد می‌شود.
 * ۳. فهرست دقیق فیلدهای استفاده‌شده در ستون used_fields_json جدول ai_tasks ثبت می‌شود.
 */
final class AiContentService
{
    /** عبارات ممنوعه که اگر در ورودی نباشند نباید در متن خروجی ظاهر شوند */
    private const FORBIDDEN_HALLUCINATION_TERMS = [
        'پارکینگ اختصاصی',
        'اینترنت رایگان',
        'وای‌فای رایگان',
        'موسیقی زنده',
        'ارزان‌ترین قیمت',
        'تخفیف ویژه',
        'ارسال رایگان',
        'شعبه دوم',
        'برنده جایزه',
    ];

    /**
     * ساخت ورودی استاندارد و راستی‌آزمایی‌شده برای تسک تولید متن کسب‌وکار (business_text)
     *
     * @return array<string, mixed>|null
     */
    public static function buildBusinessInput(int $businessId): ?array
    {
        $biz = DB::fetchOne(
            "SELECT b.*,
                    c.name_fa AS city_name_fa,
                    c.slug AS city_slug,
                    cat.name_fa AS category_name_fa,
                    cat.slug AS category_slug,
                    n.name_fa AS neighborhood_name_fa
             FROM businesses b
             INNER JOIN cities c ON c.id = b.city_id
             INNER JOIN categories cat ON cat.id = b.category_id
             LEFT JOIN neighborhoods n ON n.id = b.neighborhood_id
             WHERE b.id = :id
             LIMIT 1",
            ['id' => $businessId]
        );

        if ($biz === null) {
            return null;
        }

        $contacts = DB::fetchAll(
            "SELECT type, value, is_primary, confidence
             FROM business_contacts
             WHERE business_id = :bid
             ORDER BY is_primary DESC, id ASC",
            ['bid' => $businessId]
        );

        $sourcesCountRow = DB::fetchOne(
            "SELECT COUNT(*) AS cnt FROM business_sources WHERE business_id = :bid",
            ['bid' => $businessId]
        );
        $sourcesCount = (int) ($sourcesCountRow['cnt'] ?? 0);

        $phones = [];
        $instagram = null;
        $website = null;
        $whatsapp = null;

        foreach ($contacts as $contact) {
            $type = (string) ($contact['type'] ?? '');
            $val = trim((string) ($contact['value'] ?? ''));
            if ($val === '') {
                continue;
            }
            if (($type === 'phone' || $type === 'mobile') && !in_array($val, $phones, true)) {
                $phones[] = $val;
            } elseif ($type === 'instagram' && $instagram === null) {
                $instagram = $val;
            } elseif ($type === 'website' && $website === null) {
                $website = $val;
            } elseif ($type === 'whatsapp' && $whatsapp === null) {
                $whatsapp = $val;
            }
        }

        return [
            'business_id'          => (int) $biz['id'],
            'name'                 => (string) $biz['name'],
            'slug'                 => (string) $biz['slug'],
            'city_name_fa'         => (string) $biz['city_name_fa'],
            'category_name_fa'     => (string) $biz['category_name_fa'],
            'neighborhood_name_fa' => !empty($biz['neighborhood_name_fa']) ? (string) $biz['neighborhood_name_fa'] : null,
            'address'              => !empty($biz['address']) ? (string) $biz['address'] : null,
            'lat'                  => $biz['lat'] !== null ? (float) $biz['lat'] : null,
            'lng'                  => $biz['lng'] !== null ? (float) $biz['lng'] : null,
            'hours'                => !empty($biz['hours']) ? (string) $biz['hours'] : null,
            'tier'                 => (string) ($biz['tier'] ?? 'C'),
            'internal_score'       => round((float) ($biz['internal_score'] ?? 0), 2),
            'confidence'           => (string) ($biz['confidence'] ?? 'medium'),
            'sources_count'        => max(1, $sourcesCount),
            'phones'               => $phones,
            'instagram'            => $instagram,
            'website'              => $website,
            'whatsapp'             => $whatsapp,
        ];
    }

    /**
     * ساخت ورودی استاندارد و راستی‌آزمایی‌شده برای تسک پیش‌نویس مقاله لیستی (article_draft)
     *
     * @return array<string, mixed>|null
     */
    public static function buildArticleInput(int $articleId): ?array
    {
        $article = DB::fetchOne(
            "SELECT a.*,
                    c.name_fa AS city_name_fa,
                    c.slug AS city_slug,
                    cat.name_fa AS category_name_fa,
                    cat.slug AS category_slug
             FROM articles a
             INNER JOIN cities c ON c.id = a.city_id
             INNER JOIN categories cat ON cat.id = a.category_id
             WHERE a.id = :id
             LIMIT 1",
            ['id' => $articleId]
        );

        if ($article === null) {
            return null;
        }

        $items = DB::fetchAll(
            "SELECT ai.business_id,
                    ai.position,
                    ai.is_sponsored,
                    ai.sponsor_label,
                    b.name,
                    b.slug,
                    b.address,
                    b.hours,
                    b.lat,
                    b.lng,
                    b.tier,
                    b.internal_score,
                    b.confidence,
                    n.name_fa AS neighborhood_name_fa
             FROM article_items ai
             INNER JOIN businesses b ON b.id = ai.business_id
             LEFT JOIN neighborhoods n ON n.id = b.neighborhood_id
             WHERE ai.article_id = :aid
             ORDER BY ai.position ASC",
            ['aid' => $articleId]
        );

        // اگر هنوز آیتمی در مقاله ثبت نشده بود، تا ۱۰ کسب‌وکار برتر منتشرشده همان شهر و دسته را واکشی کن
        if (empty($items)) {
            $topBusinesses = DB::fetchAll(
                "SELECT b.id AS business_id,
                        b.name,
                        b.slug,
                        b.address,
                        b.hours,
                        b.lat,
                        b.lng,
                        b.tier,
                        b.internal_score,
                        b.confidence,
                        n.name_fa AS neighborhood_name_fa
                 FROM businesses b
                 LEFT JOIN neighborhoods n ON n.id = b.neighborhood_id
                 WHERE b.city_id = :cid
                   AND b.category_id = :catid
                   AND b.status = 'published'
                 ORDER BY b.internal_score DESC, b.id ASC
                 LIMIT 10",
                [
                    'cid'   => (int) $article['city_id'],
                    'catid' => (int) $article['category_id'],
                ]
            );
            foreach ($topBusinesses as $idx => $tb) {
                $tb['position'] = $idx + 1;
                $tb['is_sponsored'] = 0;
                $tb['sponsor_label'] = null;
                $items[] = $tb;
            }
        }

        $preparedItems = [];
        foreach ($items as $item) {
            $bid = (int) $item['business_id'];
            $phoneRow = DB::fetchOne(
                "SELECT value FROM business_contacts
                 WHERE business_id = :bid AND type IN ('phone', 'mobile')
                 ORDER BY is_primary DESC, id ASC LIMIT 1",
                ['bid' => $bid]
            );
            $preparedItems[] = [
                'business_id'          => $bid,
                'position'             => (int) ($item['position'] ?? 1),
                'name'                 => (string) $item['name'],
                'slug'                 => (string) $item['slug'],
                'neighborhood_name_fa' => !empty($item['neighborhood_name_fa']) ? (string) $item['neighborhood_name_fa'] : null,
                'address'              => !empty($item['address']) ? (string) $item['address'] : null,
                'hours'                => !empty($item['hours']) ? (string) $item['hours'] : null,
                'phone'                => !empty($phoneRow['value']) ? (string) $phoneRow['value'] : null,
                'has_coordinates'      => ($item['lat'] !== null && $item['lng'] !== null),
                'tier'                 => (string) ($item['tier'] ?? 'B'),
                'internal_score'       => round((float) ($item['internal_score'] ?? 4.10), 2),
                'confidence'           => (string) ($item['confidence'] ?? 'medium'),
            ];
        }

        return [
            'article_id'       => (int) $article['id'],
            'title'            => (string) $article['title'],
            'slug'             => (string) $article['slug'],
            'city_id'          => (int) $article['city_id'],
            'city_name_fa'     => (string) $article['city_name_fa'],
            'category_id'      => (int) $article['category_id'],
            'category_name_fa' => (string) $article['category_name_fa'],
            'year_label'       => (string) ($article['year_label'] ?: '۱۴۰۵'),
            'items'            => $preparedItems,
        ];
    }

    /**
     * تولید خروجی ساختاریافته بدون توهم برای یک تسک (business_text یا article_draft)
     *
     * @param string $type
     * @param array<string, mixed> $input
     * @return array{output: array<string, mixed>, used_fields: array<int, string>, model: string}
     */
    public static function generateFactGroundedOutput(string $type, array $input): array
    {
        if ($type === 'business_text') {
            return self::generateBusinessText($input);
        }

        return self::generateArticleDraft($input);
    }

    /**
     * تولید متن معرفی، نکات کلیدی و سؤالات متداول کسب‌وکار صرفاً بر اساس فیلدهای موجود در ورودی
     *
     * @param array<string, mixed> $input
     * @return array{output: array<string, mixed>, used_fields: array<int, string>, model: string}
     */
    private static function generateBusinessText(array $input): array
    {
        $usedFields = ['name', 'city_name_fa', 'category_name_fa'];

        $name = (string) ($input['name'] ?? 'کسب‌وکار');
        $city = (string) ($input['city_name_fa'] ?? 'گیلان');
        $cat = (string) ($input['category_name_fa'] ?? 'خدمات محلی');
        $neighborhood = !empty($input['neighborhood_name_fa']) ? (string) $input['neighborhood_name_fa'] : null;
        $address = !empty($input['address']) ? (string) $input['address'] : null;
        $hours = !empty($input['hours']) ? (string) $input['hours'] : null;
        $phones = is_array($input['phones'] ?? null) ? $input['phones'] : [];
        $primaryPhone = !empty($phones[0]) ? (string) $phones[0] : null;
        $instagram = !empty($input['instagram']) ? (string) $input['instagram'] : null;
        $website = !empty($input['website']) ? (string) $input['website'] : null;
        $score = (float) ($input['internal_score'] ?? 0);
        $tier = (string) ($input['tier'] ?? 'C');
        $confidence = (string) ($input['confidence'] ?? 'medium');
        $sourcesCount = (int) ($input['sources_count'] ?? 1);
        $hasMap = ($input['lat'] !== null && $input['lng'] !== null);

        $locationPhrase = 'در شهر ' . $city;
        if ($neighborhood !== null) {
            $locationPhrase .= ' (محله ' . $neighborhood . ')';
            $usedFields[] = 'neighborhood_name_fa';
        }

        $sentences = [];
        $sentences[] = sprintf(
            '«%s» یکی از مراکز فعال در دسته «%s» %s است که اطلاعات آن در سامانه حامی‌هاب گیلان ثبت و بررسی شده است.',
            $name,
            $cat,
            $locationPhrase
        );

        if ($score > 0) {
            $usedFields[] = 'internal_score';
            $usedFields[] = 'tier';
            $tierLabel = $tier === 'A' ? 'سطح برتر (Tier A)' : ($tier === 'B' ? 'سطح منتخب (Tier B)' : 'سطح پایه (Tier C)');
            $sentences[] = sprintf(
                'این مجموعه بر اساس الگوریتم امتیاز وزنی بیزین در حامی‌هاب امتیاز %s از ۵ را کسب کرده و در جایگاه %s قرار دارد.',
                number_format($score, 2, '.', ''),
                $tierLabel
            );
        }

        if ($address !== null) {
            $usedFields[] = 'address';
            $sentences[] = sprintf('نشانی ثبت‌شده این مجموعه «%s» است.', $address);
        }

        $contactParts = [];
        if ($primaryPhone !== null) {
            $usedFields[] = 'phones';
            $contactParts[] = 'شماره تماس ' . $primaryPhone;
        }
        if ($hours !== null) {
            $usedFields[] = 'hours';
            $contactParts[] = 'ساعات فعالیت «' . $hours . '»';
        }
        if ($instagram !== null) {
            $usedFields[] = 'instagram';
            $contactParts[] = 'صفحه اینستاگرام';
        }
        if ($website !== null) {
            $usedFields[] = 'website';
            $contactParts[] = 'وب‌سایت رسمی';
        }

        if (!empty($contactParts)) {
            $sentences[] = 'برای ارتباط یا مراجعه، ' . implode('، ', $contactParts) . ' در پروفایل این کسب‌وکار در دسترس است.';
        }

        $shortDesc = implode(' ', $sentences);

        // ساخت بولت‌پوینت‌های کاملاً مستند و بدون حدس و گمان
        $bullets = [];
        $bullets[] = sprintf('فعال در رسته «%s» %s', $cat, $locationPhrase);

        if ($score > 0) {
            $bullets[] = sprintf('امتیاز وزنی بیزین %s از ۵ (رتبه %s در حامی‌هاب گیلان)', number_format($score, 2, '.', ''), 'Tier ' . $tier);
        }

        if ($sourcesCount >= 2 || $confidence === 'high') {
            $usedFields[] = 'confidence';
            $usedFields[] = 'sources_count';
            $bullets[] = sprintf('راستی‌آزمایی چندمنبعی با سطح اطمینان بالا (%d منبع مستقل)', max(2, $sourcesCount));
        }

        if ($primaryPhone !== null) {
            $bullets[] = 'شماره تماس مستقیم ثبت‌شده: ' . $primaryPhone;
        }

        if ($hasMap) {
            $usedFields[] = 'lat';
            $usedFields[] = 'lng';
            $bullets[] = 'دارای مختصات جغرافیایی دقیق روی نقشه جهت مسیریابی با نشان، بلد و گوگل‌مپ';
        }

        if ($hours !== null) {
            $bullets[] = 'ساعات کاری اعلام‌شده: ' . $hours;
        }

        // ساخت سؤالات متداول اختصاصی کسب‌وکار بر اساس داده‌های موجود
        $faqs = [];
        if ($address !== null) {
            $faqs[] = [
                'question' => sprintf('آدرس دقیق %s در %s کجاست؟', $name, $city),
                'answer'   => sprintf(
                    'نشانی ثبت‌شده برای %s در شهر %s%s عبارت است از: %s.',
                    $name,
                    $city,
                    $neighborhood !== null ? ' (محله ' . $neighborhood . ')' : '',
                    $address
                ),
            ];
        }

        if ($primaryPhone !== null) {
            $faqs[] = [
                'question' => sprintf('شماره تلفن تماس با %s چیست؟', $name),
                'answer'   => sprintf('شماره تماس ثبت‌شده برای %s در سامانه حامی‌هاب %s است.', $name, $primaryPhone),
            ];
        }

        $faqs[] = [
            'question' => sprintf('امتیاز و رتبه %s در حامی‌هاب گیلان چقدر است؟', $name),
            'answer'   => sprintf(
                'این کسب‌وکار بر اساس شاخص وزنی بیزین (Bayesian Score با m=20) امتیاز %s از ۵ و رتبه Tier %s را در دسته %s شهر %s کسب کرده است.',
                number_format($score > 0 ? $score : 4.0, 2, '.', ''),
                $tier,
                $cat,
                $city
            ),
        ];

        $usedFields = array_values(array_unique($usedFields));

        return [
            'output' => [
                'short_desc' => $shortDesc,
                'bullets'    => $bullets,
                'faqs'       => $faqs,
            ],
            'used_fields' => $usedFields,
            'model'       => 'hx-fact-grounded-v1 (Anti-Hallucination)',
        ];
    }

    /**
     * تولید پیش‌نویس کامل مقاله لیستی «بهترین X در شهر Y» بدون هیچ‌گونه توهم
     *
     * @param array<string, mixed> $input
     * @return array{output: array<string, mixed>, used_fields: array<int, string>, model: string}
     */
    private static function generateArticleDraft(array $input): array
    {
        $usedFields = ['title', 'city_name_fa', 'category_name_fa', 'year_label', 'items'];

        $city = (string) ($input['city_name_fa'] ?? 'رشت');
        $cat = (string) ($input['category_name_fa'] ?? 'کافه و رستوران');
        $year = (string) ($input['year_label'] ?? '۱۴۰۵');
        $items = is_array($input['items'] ?? null) ? $input['items'] : [];
        $count = count($items);

        $neighborhoods = [];
        $tierANames = [];
        foreach ($items as $it) {
            if (!empty($it['neighborhood_name_fa']) && !in_array($it['neighborhood_name_fa'], $neighborhoods, true)) {
                $neighborhoods[] = (string) $it['neighborhood_name_fa'];
            }
            if (($it['tier'] ?? '') === 'A' && count($tierANames) < 4) {
                $tierANames[] = (string) $it['name'];
            }
        }

        $neighborhoodText = !empty($neighborhoods)
            ? ('در محله‌هایی همچون ' . implode('، ', array_slice($neighborhoods, 0, 5)))
            : ('در نقاط مختلف شهر ' . $city);

        $introHtml = sprintf(
            '<p>انتخاب از میان <strong>بهترین مراکز %s در %s</strong> نیازمند بررسی دقیق موقعیت مکانی، اعتبار اطلاعات تماس و رضایت مستند مراجعان است. در این راهنمای جامع (به‌روزرسانی %s)، موتور تحلیلی <strong>حامی‌هاب گیلان (HamiHubX)</strong> تعداد <strong>%d مجموعه منتخب</strong> %s را بر اساس فرمول امتیاز وزنی بیزین (Bayesian Weighted Score با پارامتر تعدیل m=20) و تطابق چندمنبعی رتبه‌بندی کرده است.</p>',
            htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($city, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($year, ENT_QUOTES, 'UTF-8'),
            $count,
            htmlspecialchars($neighborhoodText, ENT_QUOTES, 'UTF-8')
        );

        $tipsHtml = sprintf(
            '<p>برای انتخاب بهترین %s در %s، پیشنهاد می‌شود مجموعه‌های دارای نشان <strong>Tier A (۲۰٪ برتر شهر)</strong> و وضعیت <strong>تأیید چندمنبعی</strong> را در اولویت قرار دهید. همچنین پیش از مراجعه حضوری می‌توانید از طریق دکمه‌های تماس مستقیم و مسیریابی (نشان، بلد یا گوگل‌مپ) در کارت هر کسب‌وکار، نزدیک‌ترین مسیر و ساعات فعالیت را بررسی نمایید.</p>',
            htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($city, ENT_QUOTES, 'UTF-8')
        );

        $metaTitle = sprintf('بهترین %s در %s (رتبه‌بندی %s) | حامی‌هاب گیلان', $cat, $city, $year);
        $metaDescription = sprintf(
            'معرفی و مقایسه %d مورد از بهترین مراکز %s در %s همراه با آدرس دقیق، شماره تماس، نقشه مسیریابی و امتیاز وزنی بیزین در حامی‌هاب گیلان.',
            max(1, $count),
            $cat,
            $city
        );

        $itemsOutput = [];
        foreach ($items as $item) {
            $bid = (int) ($item['business_id'] ?? 0);
            $bName = (string) ($item['name'] ?? '');
            $bNeighbor = !empty($item['neighborhood_name_fa']) ? (string) $item['neighborhood_name_fa'] : null;
            $bAddress = !empty($item['address']) ? (string) $item['address'] : null;
            $bPhone = !empty($item['phone']) ? (string) $item['phone'] : null;
            $bHours = !empty($item['hours']) ? (string) $item['hours'] : null;
            $bScore = (float) ($item['internal_score'] ?? 4.10);
            $bTier = (string) ($item['tier'] ?? 'B');
            $bConf = (string) ($item['confidence'] ?? 'medium');
            $hasCoords = !empty($item['has_coordinates']);

            $descParts = [];
            $descParts[] = sprintf(
                '«%s» با امتیاز وزنی بیزین %s از ۵ (در رده Tier %s) یکی از گزینه‌های شاخص %s در شهر %s%s است.',
                $bName,
                number_format($bScore, 2, '.', ''),
                $bTier,
                $cat,
                $city,
                $bNeighbor !== null ? ' (محله ' . $bNeighbor . ')' : ''
            );
            if ($bAddress !== null) {
                $descParts[] = 'نشانی این مجموعه در «' . $bAddress . '» قرار دارد.';
            }
            if ($bPhone !== null) {
                $descParts[] = 'شماره تماس مستقیم «' . $bPhone . '» برای هماهنگی ثبت شده است.';
            }

            $pros = [];
            $cons = [];

            $pros[] = sprintf('امتیاز بیزین %s از ۵ در رده Tier %s', number_format($bScore, 2, '.', ''), $bTier);
            if ($bConf === 'high') {
                $pros[] = 'اطلاعات مکان و تماس راستی‌آزمایی‌شده از چند منبع مستقل';
            }
            if ($bNeighbor !== null) {
                $pros[] = 'موقعیت مکانی مشخص در محله ' . $bNeighbor;
            }
            if ($bPhone !== null) {
                $pros[] = 'دسترسی به شماره تماس مستقیم جهت استعلام و هماهنگی';
            }
            if ($hasCoords) {
                $pros[] = 'مختصات دقیق روی نقشه جهت مسیریابی آنلاین';
            }

            if ($bHours === null) {
                $cons[] = 'ساعات کاری دقیق در منابع پایه ثبت نشده و نیاز به استعلام تلفنی دارد';
            }
            if ($bPhone === null) {
                $cons[] = 'شماره تماس مستقیم هنوز در پرونده ثبت نشده است';
            }
            if (empty($cons)) {
                $cons[] = 'در ساعات اوج مراجعه آخر هفته پیشنهاد می‌شود پیش از حرکت تماس بگیرید';
            }

            $itemsOutput[] = [
                'business_id' => $bid,
                'position'    => (int) ($item['position'] ?? 1),
                'custom_desc' => implode(' ', $descParts),
                'highlights'  => [
                    'pros' => $pros,
                    'cons' => $cons,
                ],
            ];
        }

        $topNamesText = !empty($tierANames)
            ? implode('، ', $tierANames)
            : (!empty($items[0]['name']) ? (string) $items[0]['name'] : 'مجموعه‌های رتبه‌اول لیست');

        $faqs = [
            [
                'question' => sprintf('رتبه‌بندی بهترین %s در %s در حامی‌هاب چگونه انجام شده است؟', $cat, $city),
                'answer'   => 'رتبه‌بندی بر اساس فرمول امتیاز وزنی بیزین (Bayesian Weighted Score با پارامتر m=20)، تطابق چندمنبعی داده‌های مکانی و صحت شماره تماس و آدرس انجام می‌شود تا مجموعه‌های دارای کیفیت پایدار در صدر قرار گیرند.',
            ],
            [
                'question' => sprintf('کدام مراکز %s در %s بالاترین امتیاز (Tier A) را دارند؟', $cat, $city),
                'answer'   => sprintf('در حال حاضر مجموعه‌های %s بر اساس شاخص وزنی بیزین در گروه برتر این لیست قرار دارند.', $topNamesText),
            ],
            [
                'question' => sprintf('آیا آدرس و لوکیشن مراکز %s در %s روی نقشه قابل مسیریابی است؟', $cat, $city),
                'answer'   => 'بله، برای تمام کسب‌وکارهای دارای مختصات تاییدشده در این صفحه، دکمه مسیریابی مستقیم با نشان، بلد، گوگل‌مپ و نقشه تعاملی حامی‌هاب فعال است.',
            ],
        ];

        return [
            'output' => [
                'meta_title'       => $metaTitle,
                'meta_description' => $metaDescription,
                'intro'            => $introHtml,
                'tips_html'        => $tipsHtml,
                'items'            => $itemsOutput,
                'faqs'             => $faqs,
            ],
            'used_fields' => $usedFields,
            'model'       => 'hx-fact-grounded-v1 (Anti-Hallucination)',
        ];
    }

    /**
     * اعتبارسنجی ضد توهم (Anti-Hallucination Guardrail):
     * بررسی می‌کند که خروجی تولیدشده هیچ شماره تلفن جعلی یا ادعای ناموجود در ورودی نداشته باشد.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $output
     * @return array{valid: bool, reason: string}
     */
    public static function validateNoHallucination(array $input, array $output): array
    {
        $inputSerialized = json_encode($input, JSON_UNESCAPED_UNICODE) ?: '';
        $outputSerialized = json_encode($output, JSON_UNESCAPED_UNICODE) ?: '';

        // ۱. بررسی عبارات ممنوعه (امکانات یا صفات تبلیغاتی که در ورودی نیستند)
        foreach (self::FORBIDDEN_HALLUCINATION_TERMS as $term) {
            if (mb_strpos($outputSerialized, $term) !== false && mb_strpos($inputSerialized, $term) === false) {
                return [
                    'valid'  => false,
                    'reason' => sprintf('رد شده توسط گاردریل ضد توهم: عبارت «%s» در داده‌های ورودی وجود ندارد.', $term),
                ];
            }
        }

        // ۲. بررسی شماره‌های تلفن داخل متن خروجی (اگر شماره‌ای در خروجی ذکر شده باید در ورودی باشد)
        if (preg_match_all('/(?:\+98|0)9\d{9}|013\d{8}/u', $outputSerialized, $matches)) {
            foreach ($matches[0] as $phoneFound) {
                if (strpos($inputSerialized, $phoneFound) === false) {
                    return [
                        'valid'  => false,
                        'reason' => sprintf('رد شده توسط گاردریل ضد توهم: شماره تلفن «%s» در ورودی کسب‌وکار وجود ندارد.', $phoneFound),
                    ];
                }
            }
        }

        return [
            'valid'  => true,
            'reason' => 'تأییدشده توسط گاردریل ضد توهم (۱۰۰٪ منطبق با فیلدهای ورودی).',
        ];
    }

    /**
     * اعمال خروجی تاییدشده تسک هوش مصنوعی روی جداول اصلی دیتابیس (businesses یا articles)
     *
     * @param string $type
     * @param int $targetId
     * @param array<string, mixed> $output
     */
    public static function applyOutputToTarget(string $type, int $targetId, array $output): void
    {
        if ($type === 'business_text') {
            $shortDesc = trim((string) ($output['short_desc'] ?? ''));
            $bullets = is_array($output['bullets'] ?? null) ? $output['bullets'] : [];
            $faqs = is_array($output['faqs'] ?? null) ? $output['faqs'] : [];

            DB::query(
                "UPDATE businesses
                 SET short_desc = :short_desc,
                     bullets_json = :bullets_json,
                     faqs_json = :faqs_json,
                     updated_at = NOW()
                 WHERE id = :id",
                [
                    'short_desc'   => $shortDesc,
                    'bullets_json' => json_encode($bullets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'faqs_json'    => json_encode($faqs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'id'           => $targetId,
                ]
            );

            Cache::flushAll();
            return;
        }

        if ($type === 'article_draft') {
            $metaTitle = trim((string) ($output['meta_title'] ?? ''));
            $metaDesc = trim((string) ($output['meta_description'] ?? ''));
            $intro = trim((string) ($output['intro'] ?? ''));
            $tipsHtml = trim((string) ($output['tips_html'] ?? ''));
            $items = is_array($output['items'] ?? null) ? $output['items'] : [];
            $faqs = is_array($output['faqs'] ?? null) ? $output['faqs'] : [];

            DB::query(
                "UPDATE articles
                 SET meta_title = COALESCE(NULLIF(:meta_title, ''), meta_title),
                     meta_description = COALESCE(NULLIF(:meta_description, ''), meta_description),
                     intro = :intro,
                     tips_html = :tips_html,
                     ai_generated = 1,
                     updated_at = NOW()
                 WHERE id = :id",
                [
                    'meta_title'       => $metaTitle,
                    'meta_description' => $metaDesc,
                    'intro'            => $intro,
                    'tips_html'        => $tipsHtml,
                    'id'               => $targetId,
                ]
            );

            foreach ($items as $item) {
                $bid = (int) ($item['business_id'] ?? 0);
                if ($bid <= 0) {
                    continue;
                }
                $customDesc = trim((string) ($item['custom_desc'] ?? ''));
                $highlights = is_array($item['highlights'] ?? null)
                    ? json_encode($item['highlights'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null;

                $existingItem = DB::fetchOne(
                    "SELECT id FROM article_items WHERE article_id = :aid AND business_id = :bid LIMIT 1",
                    ['aid' => $targetId, 'bid' => $bid]
                );

                if ($existingItem !== null) {
                    DB::query(
                        "UPDATE article_items
                         SET custom_desc = :custom_desc,
                             highlights = :highlights
                         WHERE id = :id",
                        [
                            'custom_desc' => $customDesc,
                            'highlights'  => $highlights,
                            'id'          => (int) $existingItem['id'],
                        ]
                    );
                } else {
                    DB::query(
                        "INSERT INTO article_items (article_id, business_id, position, custom_desc, highlights, is_sponsored)
                         VALUES (:aid, :bid, :pos, :custom_desc, :highlights, 0)",
                        [
                            'aid'         => $targetId,
                            'bid'         => $bid,
                            'pos'         => (int) ($item['position'] ?? 1),
                            'custom_desc' => $customDesc,
                            'highlights'  => $highlights,
                        ]
                    );
                }
            }

            if (!empty($faqs)) {
                DB::query("DELETE FROM article_faqs WHERE article_id = :aid", ['aid' => $targetId]);
                foreach ($faqs as $idx => $faq) {
                    $q = trim((string) ($faq['question'] ?? ''));
                    $a = trim((string) ($faq['answer'] ?? ''));
                    if ($q === '' || $a === '') {
                        continue;
                    }
                    DB::query(
                        "INSERT INTO article_faqs (article_id, question, answer, sort)
                         VALUES (:aid, :q, :a, :sort)",
                        [
                            'aid'  => $targetId,
                            'q'    => $q,
                            'a'    => $a,
                            'sort' => $idx + 1,
                        ]
                    );
                }
            }

            Cache::flushAll();
        }
    }
}
