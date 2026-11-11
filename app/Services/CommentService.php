<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\DB;
use Throwable;

/**
 * سرویس دیدگاه‌های کاربران روی کسب‌وکارها (مرحله S16)
 * — سوال امنیتی امضاشده HMAC بدون سشن (سازگار با کش کامل صفحات)
 * — دیدگاه‌ها پیش‌فرض «در انتظار تایید» می‌مانند و فقط با تایید تحریریه نمایش داده می‌شوند
 * — هیچ داده خصوصی (IP) در خروجی عمومی نمی‌آید؛ IP فقط برای مهار اسپم نگهداری می‌شود
 */
final class CommentService
{
    /** فاصله مجاز پاسخ به سوال امنیتی (ثانیه) */
    private const QUESTION_TTL = 3600;

    /**
     * ساخت سوال امنیتی ساده (جمع دو عدد یک‌رقمی) با امضای HMAC
     * @return array{a:int,b:int,ts:int,sig:string,label:string}
     */
    public static function generateQuestion(int $businessId): array
    {
        $a = random_int(2, 9);
        $b = random_int(2, 9);
        $ts = time();
        return [
            'a'     => $a,
            'b'     => $b,
            'ts'    => $ts,
            'sig'   => self::signature($a, $b, $ts),
            'label' => self::faWord($a) . ' + ' . self::faWord($b) . ' = ؟',
        ];
    }

    /** بررسی صحت پاسخ سوال امنیتی (پذیرش ارقام فارسی و لاتین) */
    public static function verifyQuestion(string $a, string $b, string $ts, string $sig, string $answer): bool
    {
        if ($a === '' || $b === '' || $ts === '' || $sig === '' || $answer === '') return false;
        if (!ctype_digit($a) || !ctype_digit($b) || !ctype_digit($ts)) return false;
        $tsInt = (int)$ts;
        if ($tsInt <= 0 || abs(time() - $tsInt) > self::QUESTION_TTL) return false;
        if (!preg_match('~^[0-9\x{06F0}-\x{06F9}]{1,3}$~u', $answer)) return false;
        $answerLatin = strtr($answer, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        if ($answerLatin !== (string)((int)$a + (int)$b)) return false;
        return hash_equals(self::signature((int)$a, (int)$b, $tsInt), $sig);
    }

    private static function signature(int $a, int $b, int $ts): string
    {
        $key = (string)Config::get('app.check_key', '');
        if ($key === '') $key = (string)Config::get('app.install_token', 'hx-fallback-key');
        return hash_hmac('sha256', $a . '|' . $b . '|' . $ts, $key);
    }

    private static function faWord(int $n): string
    {
        $words = [0 => 'صفر', 1 => 'یک', 2 => 'دو', 3 => 'سه', 4 => 'چهار', 5 => 'پنج', 6 => 'شش', 7 => 'هفت', 8 => 'هشت', 9 => 'نه'];
        return $words[$n] ?? (string)$n;
    }

    /**
     * ثبت دیدگاه کاربر (وضعیت pending). خروجی آرایه‌ای با ok
     * S21: دیدگاه‌ها فقط در جدول business_comments ذخیره می‌شوند و در تب «دیدگاه‌ها»ی پنل
     * مدیریت می‌شوند؛ دیگر ردیفی در صندوق بازبینی (review_queue) ساخته نمی‌شود.
     *
     * @return array<string,mixed>
     */
    public static function submit(int $businessId, string $name, int $rating, string $body, string $ip): array
    {
        $biz = DB::fetchOne("SELECT id, slug, name FROM businesses WHERE id = :id AND status = 'published' LIMIT 1", ['id' => $businessId]);
        if (!$biz) {
            return ['ok' => false, 'error' => 'کسب‌وکار مورد نظر یافت نشد.', 'status' => 404];
        }
        // مهار اسپم تکرار: حداکثر ۳ دیدگاه در انتظار از یک IP برای یک کسب‌وکار در ۲۴ ساعت
        $pendingSame = (int)(DB::fetchOne(
            "SELECT COUNT(*) AS n FROM business_comments WHERE business_id = :bid AND ip_address = :ip AND status = 'pending' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            ['bid' => $businessId, 'ip' => substr($ip, 0, 64)]
        )['n'] ?? 0);
        if ($pendingSame >= 3) {
            return ['ok' => false, 'error' => 'دیدگاه قبلی شما در انتظار تایید است؛ پس از بررسی تحریریه می‌توانید دیدگاه جدیدی ثبت کنید.', 'status' => 429];
        }
        DB::query(
            "INSERT INTO business_comments (business_id, name, rating, body, status, ip_address, created_at)
             VALUES (:bid, :name, :rating, :body, 'pending', :ip, NOW())",
            [
                'bid'    => $businessId,
                'name'   => mb_substr($name, 0, 60),
                'rating' => max(1, min(5, $rating)),
                'body'   => mb_substr($body, 0, 1000),
                'ip'     => substr($ip, 0, 64),
            ]
        );
        return ['ok' => true, 'id' => DB::lastInsertId()];
    }

    /** دیدگاه‌های تاییدشده یک کسب‌وکار برای نمایش عمومی (بدون IP) */
    public static function publicList(int $businessId, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $rows = DB::fetchAll(
            "SELECT name, rating, body, created_at
             FROM business_comments
             WHERE business_id = :bid AND status = 'approved'
             ORDER BY created_at DESC, id DESC
             LIMIT {$limit}",
            ['bid' => $businessId]
        );
        return $rows;
    }

    /**
     * فهرست دیدگاه‌ها برای پنل مدیریت با فیلتر وضعیت و جستجو
     * @return array<string,mixed>
     */
    public static function adminList(string $status, string $q, int $page = 1): array
    {
        $status = in_array($status, ['pending', 'approved', 'rejected', 'all'], true) ? $status : 'pending';
        $perPage = 30;
        $where = [];
        $params = [];
        if ($status !== 'all') {
            $where[] = "c.status = :status";
            $params['status'] = $status;
        }
        $search = trim($q);
        if ($search !== '') {
            $search = mb_substr($search, 0, 120);
            $where[] = '(c.body LIKE :q1 OR c.name LIKE :q2 OR b.name LIKE :q3)';
            $like = '%' . addcslashes($search, '\\%_') . '%';
            $params['q1'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
        }
        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
        $offset = (max(1, $page) - 1) * $perPage;
        $rows = DB::fetchAll(
            "SELECT c.id, c.business_id, c.name, c.rating, c.body, c.status, c.created_at, c.reviewed_at,
                    b.name AS business_name, b.slug AS business_slug,
                    c2.name_fa AS city_name_fa, c2.slug AS city_slug
             FROM business_comments c
             INNER JOIN businesses b ON b.id = c.business_id
             INNER JOIN cities c2 ON c2.id = b.city_id
             {$whereSql}
             ORDER BY (c.status = 'pending') DESC, c.created_at DESC, c.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        $total = (int)(DB::fetchOne(
            "SELECT COUNT(*) AS n
             FROM business_comments c
             INNER JOIN businesses b ON b.id = c.business_id
             INNER JOIN cities c2 ON c2.id = b.city_id
             {$whereSql}",
            $params
        )['n'] ?? 0);
        $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach (DB::fetchAll("SELECT status, COUNT(*) AS n FROM business_comments GROUP BY status") as $row) {
            $k = (string)$row['status'];
            if (isset($counts[$k])) $counts[$k] = (int)$row['n'];
        }
        return [
            'items'      => $rows,
            'total'      => $total,
            'page'       => max(1, $page),
            'per_page'   => $perPage,
            'status'     => $status,
            'counts'     => $counts,
        ];
    }

    /**
     * تایید / رد / حذف دیدگاه در پنل مدیریت
     * S21: با حذف کامل ارتباط دیدگاه با صندوق بازبینی، این متد فقط business_comments را به‌روز می‌کند.
     *
     * @return array<string,mixed>
     */
    public static function moderate(int $id, string $action): array
    {
        $comment = DB::fetchOne("SELECT id, business_id, status FROM business_comments WHERE id = :id LIMIT 1", ['id' => $id]);
        if (!$comment) {
            return ['ok' => false, 'error' => 'دیدگاه یافت نشد.'];
        }
        if ($action === 'delete') {
            // S20/S21: حذف واقعی رکورد از دیتابیس؛ هیچ اثری از دیدگاه باقی نمی‌ماند.
            DB::query("DELETE FROM business_comments WHERE id = :id", ['id' => $id]);
            return ['ok' => true, 'message' => 'دیدگاه حذف شد.'];
        }
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        DB::query(
            "UPDATE business_comments SET status = :st, reviewed_at = NOW() WHERE id = :id",
            ['st' => $newStatus, 'id' => $id]
        );
        $msg = $newStatus === 'approved'
            ? 'دیدگاه تایید شد و در صفحه کسب‌وکار نمایش داده می‌شود.'
            : 'دیدگاه رد شد و دیگر نمایش داده نمی‌شود.';
        return ['ok' => true, 'message' => $msg, 'status' => $newStatus];
    }
}
