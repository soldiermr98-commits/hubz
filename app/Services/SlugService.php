<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\DB;

/**
 * سرویس پیشنهاد خودکار اسلاگ لاتین (Finglish / ASCII) از نام فارسی،
 * نرمال‌سازی حروف و ارقام فارسی، و اعتبارسنجی اسلاگ‌های رزروشده
 */
final class SlugService
{
    /**
     * دیکشنری واژگان پرتکرار شهرها، دسته‌ها و کسب‌وکارهای استان گیلان برای ساخت اسلاگ خوانا
     */
    private const DICTIONARY = [
        'رشت' => 'rasht',
        'بندر انزلی' => 'bandar-anzali',
        'انزلی' => 'anzali',
        'لاهیجان' => 'lahijan',
        'لنگرود' => 'langarud',
        'تالش' => 'talesh',
        'آستارا' => 'astara',
        'فومن' => 'fuman',
        'ماسوله' => 'masouleh',
        'ماسال' => 'masal',
        'رودبار' => 'rudbar',
        'صومعه‌سرا' => 'someh-sara',
        'صومعه سرا' => 'someh-sara',
        'آستانه اشرفیه' => 'astaneh-ashrafiyeh',
        'رودسر' => 'rudsar',
        'سیاهکل' => 'siahkal',
        'دیلمان' => 'deylaman',
        'شفت' => 'shaft',
        'خمام' => 'khomam',
        'رضوانشهر' => 'rezvanshahr',
        'کیاشهر' => 'kiashahr',
        'چابکسر' => 'chaboksar',
        'کلاچای' => 'kelachay',
        'گلسار' => 'golsar',
        'منظریه' => 'manzarieh',
        'معلم' => 'moallem',
        'سبزه میدان' => 'sabzeh-meydan',
        'شهرداری' => 'shahrdari',
        'کافه' => 'cafe',
        'رستوران' => 'restaurant',
        'کبابی' => 'kababi',
        'فست فود' => 'fastfood',
        'نانوایی' => 'bakery',
        'شیرینی' => 'pastry',
        'سالن زیبایی' => 'beauty-salon',
        'آرایشگاه' => 'barber',
        'کلینیک' => 'clinic',
        'دندانپزشکی' => 'dental',
        'پزشک' => 'doctor',
        'داروخانه' => 'pharmacy',
        'ویلا' => 'villa',
        'اقامتگاه' => 'ecolodge',
        'بومگردی' => 'ecolodge',
        'هتل' => 'hotel',
        'جاذبه' => 'attraction',
        'گردشگری' => 'tourism',
        'طبیعت' => 'nature',
        'ساحل' => 'beach',
        'جنگل' => 'forest',
        'بازار' => 'bazaar',
        'بهترین' => 'best',
        'گیلان' => 'gilan',
    ];

    /**
     * نگاشت حرف‌به‌حرف فارسی به لاتین (برای کلماتی که در دیکشنری نیستند)
     */
    private const CHAR_MAP = [
        'آ' => 'a', 'ا' => 'a', 'أ' => 'a', 'إ' => 'e',
        'ب' => 'b', 'پ' => 'p', 'ت' => 't', 'ث' => 's',
        'ج' => 'j', 'چ' => 'ch', 'ح' => 'h', 'خ' => 'kh',
        'د' => 'd', 'ذ' => 'z', 'ر' => 'r', 'ز' => 'z',
        'ژ' => 'zh', 'س' => 's', 'ش' => 'sh', 'ص' => 's',
        'ض' => 'z', 'ط' => 't', 'ظ' => 'z', 'ع' => 'a',
        'غ' => 'gh', 'ف' => 'f', 'ق' => 'gh', 'ک' => 'k',
        'ك' => 'k', 'گ' => 'g', 'ل' => 'l', 'م' => 'm',
        'ن' => 'n', 'و' => 'v', 'ه' => 'h', 'ة' => 'h',
        'ی' => 'y', 'ي' => 'y', 'ئ' => 'y',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '‌' => '-', // نیم‌فاصله
    ];

    /**
     * نرمال‌سازی متن فارسی (تبدیل ي/ك عربی به فارسی و ارقام فارسی/عربی به انگلیسی در صورت نیاز)
     */
    public static function normalizePersianText(string $text): string
    {
        $search = ['ي', 'ك', 'ة', 'ؤ', 'إ', 'أ'];
        $replace = ['ی', 'ک', 'ه', 'و', 'ا', 'ا'];
        $clean = str_replace($search, $replace, trim($text));
        $clean = preg_replace('/\s+/u', ' ', $clean);
        return is_string($clean) ? $clean : '';
    }

    /**
     * تبدیل ارقام فارسی و عربی به انگلیسی
     */
    public static function toEnglishDigits(string $input): string
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        return str_replace($persian, $english, $input);
    }

    /**
     * نرمال‌سازی شماره تلفن (موبایل به +989... و تلفن ثابت گیلان به +9813...)
     */
    public static function normalizePhone(string $rawPhone): string
    {
        $digits = preg_replace('/[^0-9+]/', '', self::toEnglishDigits($rawPhone));
        if (!is_string($digits) || $digits === '') {
            return '';
        }

        if (str_starts_with($digits, '0098')) {
            $digits = '+98' . substr($digits, 4);
        } elseif (str_starts_with($digits, '98') && strlen($digits) === 12) {
            $digits = '+' . $digits;
        } elseif (str_starts_with($digits, '09') && strlen($digits) === 11) {
            $digits = '+98' . substr($digits, 1);
        } elseif (str_starts_with($digits, '9') && strlen($digits) === 10) {
            $digits = '+98' . $digits;
        } elseif (str_starts_with($digits, '013') && strlen($digits) === 11) {
            $digits = '+98' . substr($digits, 1);
        } elseif (strlen($digits) === 8 && !str_starts_with($digits, '0')) {
            // شماره ۸ رقمی ثابت بدون پیش‌شماره در استان گیلان (013)
            $digits = '+9813' . $digits;
        }

        return preg_match('/^\+98(?:9[0-9]{9}|[1-8][0-9]{9})$/', $digits) ? $digits : '';
    }

    /**
     * تولید اسلاگ لاتین استاندارد از متن فارسی یا انگلیسی
     */
    public static function suggestSlug(string $title, string $fallbackPrefix = 'item'): string
    {
        $normalized = self::normalizePersianText($title);
        $lower = mb_strtolower($normalized, 'UTF-8');

        // جایگزینی عبارات دیکشنری از طولانی‌ترین به کوتاه‌ترین
        $dict = self::DICTIONARY;
        uksort($dict, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        foreach ($dict as $fa => $en) {
            $lower = str_replace($fa, ' ' . $en . ' ', $lower);
        }

        $converted = strtr($lower, self::CHAR_MAP);
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($converted));
        $slug = trim((string) $slug, '-');
        $slug = preg_replace('/-+/', '-', $slug);

        if (!is_string($slug) || $slug === '') {
            $slug = $fallbackPrefix . '-' . random_int(100, 999);
        }

        if (Config::isReservedSlug($slug)) {
            $slug .= '-gilan';
        }

        return $slug;
    }

    /**
     * اعتبارسنجی کامل اسلاگ (فرمت ASCII و عدم حضور در لیست اسلاگ‌های رزروشده)
     *
     * @return array{ok: bool, error?: string}
     */
    public static function validateSlug(string $slug): array
    {
        $clean = strtolower(trim($slug));
        if ($clean === '') {
            return [
                'ok' => false,
                'error' => 'اسلاگ نمی‌تواند خالی باشد. روی دکمه «پیشنهاد خودکار اسلاگ» کلیک کنید یا یک عبارت انگلیسی بنویسید.',
            ];
        }

        if (!Config::isValidSlugFormat($clean)) {
            return [
                'ok' => false,
                'error' => 'فرمت اسلاگ معتبر نیست. فقط از حروف کوچک انگلیسی (a-z)، اعداد (0-9) و خط تیره (-) استفاده کنید (مثلاً: cafe-lamiz-rasht).',
            ];
        }

        if (Config::isReservedSlug($clean)) {
            return [
                'ok' => false,
                'error' => "اسلاگ «{$clean}» جزو مسیرهای رزروشده سیستم است. لطفاً یک پسوند مانند «{$clean}-gilan» به آن اضافه کنید.",
            ];
        }

        return ['ok' => true];
    }

    /**
     * تضمین یکتا بودن اسلاگ در یک جدول مشخص (در صورت تکراری بودن، عدد -2، -3 اضافه می‌کند)
     */
    public static function ensureUniqueSlug(string $table, string $baseSlug, ?int $ignoreId = null): string
    {
        $allowedTables = ['cities', 'neighborhoods', 'categories', 'businesses', 'articles'];
        if (!in_array($table, $allowedTables, true)) {
            return $baseSlug;
        }

        // Slugs are stored in VARCHAR(200), and old public addresses must not
        // be reassigned to a different item after a rename.
        $maxLength = match ($table) { 'cities' => 90, 'neighborhoods', 'categories' => 110, default => 190 };
        $baseSlug = rtrim(substr($baseSlug, 0, $maxLength), '-');
        $candidate = $baseSlug;
        $counter = 2;
        $hasBusinessAliases = $table === 'businesses' && BusinessUrlService::schemaReady();
        $hasArticleAliases = $table === 'articles' && DB::fetchOne("SHOW TABLES LIKE 'article_url_aliases'") !== null;

        while (true) {
            $sql = "SELECT id FROM `{$table}` WHERE slug = :slug";
            $params = ['slug' => $candidate];

            if ($ignoreId !== null && $ignoreId > 0) {
                $sql .= " AND id != :ignore_id";
                $params['ignore_id'] = $ignoreId;
            }
            $sql .= " LIMIT 1";

            $existing = DB::fetchOne($sql, $params);
            $aliasOwner = null;
            if ($hasBusinessAliases) {
                $aliasOwner = DB::fetchOne('SELECT business_id AS id FROM business_url_aliases WHERE path=:path', ['path' => BusinessUrlService::legacyPath($candidate)]);
            } elseif ($hasArticleAliases) {
                $aliasOwner = DB::fetchOne('SELECT article_id AS id FROM article_url_aliases WHERE slug=:slug', ['slug' => $candidate]);
            }
            if ($existing === null && ($aliasOwner === null || (int)$aliasOwner['id'] === (int)$ignoreId)) {
                return $candidate;
            }

            $candidate = $baseSlug . '-' . $counter;
            $counter++;
        }
    }
}
