<?php
declare(strict_types=1);

namespace App\Core;

/**
 * مدیریت احراز هویت، هش رمز عبور (Argon2id با جایگزین bcrypt)، نشست‌های امن،
 * محدودیت تلاش ورود (۵ بار در ۱۰ دقیقه برای IP+username) و کنترل دسترسی نقش‌ها (admin / editor)
 */
final class Auth
{
    /**
     * راه‌اندازی نشست با کوکی HttpOnly; Secure; SameSite=Lax
     */
    public static function startSessionIfNeeded(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $sessionName = (string) Config::get('security.session_name', 'hhx_session');
        $secure = (bool) Config::get('security.cookie_secure', false);
        $httpOnly = (bool) Config::get('security.cookie_httponly', true);
        $sameSite = (string) Config::get('security.cookie_samesite', 'Lax');

        session_name($sessionName);
        session_set_cookie_params([
            'lifetime' => 86400 * 7,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => $sameSite,
        ]);

        session_start();
    }

    /**
     * هش کردن رمز عبور با اولویت Argon2id و جایگزینی خودکار bcrypt در صورت نبودن Argon2id
     */
    public static function hashPassword(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID);
        }
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * بررسی صحت رمز عبور
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * بررسی محدودیت تعداد تلاش ناموفق ورود (حداکثر ۵ بار در ۱۰ دقیقه برای IP+username)
     */
    public static function isRateLimited(string $username, string $ip): bool
    {
        $maxAttempts = (int) Config::get('security.login_max_attempts', 5);
        $lockoutMinutes = (int) Config::get('security.login_lockout_minutes', 10);

        $row = DB::fetchOne(
            "SELECT COUNT(*) AS cnt
             FROM login_attempts
             WHERE username = :username
               AND ip_address = :ip
               AND is_successful = 0
               AND attempted_at >= (NOW() - INTERVAL :minutes MINUTE)",
            [
                'username' => strtolower(trim($username)),
                'ip' => $ip,
                'minutes' => $lockoutMinutes,
            ]
        );

        return ((int) ($row['cnt'] ?? 0)) >= $maxAttempts;
    }

    /**
     * ثبت نتیجه تلاش ورود در جدول login_attempts
     */
    public static function recordAttempt(string $username, string $ip, bool $success): void
    {
        DB::query(
            "INSERT INTO login_attempts (username, ip_address, is_successful, attempted_at)
             VALUES (:username, :ip, :success, NOW())",
            [
                'username' => strtolower(trim($username)),
                'ip' => $ip,
                'success' => $success ? 1 : 0,
            ]
        );
    }

    /**
     * تلاش برای ورود کاربر و ثبت نشست
     */
    public static function attemptLogin(string $username, string $password, string $ip): array
    {
        self::startSessionIfNeeded();
        $cleanUser = strtolower(trim($username));

        if (self::isRateLimited($cleanUser, $ip)) {
            return [
                'ok' => false,
                'status' => 429,
                'error' => 'تعداد تلاش‌های ناموفق شما بیش از حد مجاز (۵ بار در ۱۰ دقیقه) بوده است. لطفاً ۱۰ دقیقه صبر کنید و دوباره امتحان کنید.',
            ];
        }

        $user = DB::fetchOne(
            "SELECT id, username, display_name, password_hash, role, is_active
             FROM users
             WHERE username = :username
             LIMIT 1",
            ['username' => $cleanUser]
        );

        if ($user === null || (int) $user['is_active'] !== 1 || !self::verifyPassword($password, (string) $user['password_hash'])) {
            self::recordAttempt($cleanUser, $ip, false);
            return [
                'ok' => false,
                'status' => 401,
                'error' => 'نام کاربری یا رمز عبور اشتباه است. لطفاً کیبورد خود (فارسی/انگلیسی) را بررسی کرده و دوباره تلاش کنید.',
            ];
        }

        self::recordAttempt($cleanUser, $ip, true);
        session_regenerate_id(true);

        $_SESSION['hhx_user'] = [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'display_name' => (string) $user['display_name'],
            'role' => (string) $user['role'],
        ];

        if (str_starts_with((string)$user['username'], StaffAccess::PREFIX)) {
            $_SESSION['hhx_staff_password_fingerprint'] = hash('sha256', (string)$user['password_hash']);
        }
        DB::query("UPDATE users SET last_login_at = NOW() WHERE id = :id", ['id' => (int) $user['id']]);

        return [
            'ok' => true,
            'status' => 200,
            'user' => self::user(),
            'csrf_token' => Csrf::token(),
        ];
    }

    /**
     * دریافت کاربر واردشده فعلی
     */
    public static function user(): ?array
    {
        self::startSessionIfNeeded();
        $user = isset($_SESSION['hhx_user']) && is_array($_SESSION['hhx_user']) ? $_SESSION['hhx_user'] : null;
        if ($user === null || ($user['role'] ?? '') !== 'editor' || !str_starts_with((string)($user['username'] ?? ''), StaffAccess::PREFIX)) return $user;
        // Managed staff are revalidated on every request: changes, deactivation and
        // password resets revoke existing sessions immediately. Missing policy fails closed.
        $row = DB::fetchOne('SELECT id,username,display_name,role,is_active,password_hash FROM users WHERE id=:id LIMIT 1', ['id'=>(int)$user['id']]);
        if (!$row || (int)$row['is_active'] !== 1 || $row['role'] !== 'editor'
            || $row['username'] !== $user['username'] || !is_string($_SESSION['hhx_staff_password_fingerprint'] ?? null)
            || !hash_equals($_SESSION['hhx_staff_password_fingerprint'], hash('sha256', (string)$row['password_hash']))) {
            unset($_SESSION['hhx_user'], $_SESSION['hhx_staff_password_fingerprint']);
            return null;
        }
        $setting = DB::fetchOne('SELECT v FROM settings WHERE k=:k', ['k'=>'staff_permissions_' . (int)$row['id']]);
        $permissions = $setting ? json_decode((string)$setting['v'], true) : null;
        $user['permissions'] = is_array($permissions) && array_is_list($permissions)
            ? array_values(array_intersect(StaffAccess::PERMISSIONS, array_filter($permissions, 'is_string'))) : [];
        $user['display_name'] = (string)$row['display_name'];
        return $user;
    }

    /**
     * خروج کاربر و پاک‌سازی کامل نشست
     */
    public static function logout(): void
    {
        self::startSessionIfNeeded();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * الزام ورود کاربر و بررسی نقش مجاز (admin یا editor) در هر endpoint سمت سرور
     */
    public static function requireRole(array $allowedRoles = ['admin', 'editor']): array
    {
        $user = self::user();
        if ($user === null) {
            Response::json([
                'ok' => false,
                'error' => 'نشست شما منقضی شده است. لطفاً دوباره وارد پنل مدیریت شوید.',
                'code' => 'unauthenticated',
            ], 401);
        }

        if (!in_array($user['role'], $allowedRoles, true)) {
            Response::json([
                'ok' => false,
                'error' => 'شما دسترسی لازم برای انجام این عملیات را ندارید (نیازمند سطح دسترسی مدیر ارشد).',
                'code' => 'forbidden',
            ], 403);
        }

        if (isset($user['permissions'])) {
            $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            if (!is_string($path) || !StaffAccess::allowed($user['permissions'], $method, $path)) {
                Response::json(['ok'=>false,'error'=>'این بخش در دسترسی حساب شما نیست.','code'=>'forbidden'], 403);
            }
        }
        return $user;
    }

    /**
     * بررسی توکن Bearer برای ارتباط امن با collector-remote
     */
    public static function requireCollectorToken(Request $request): void
    {
        $provided = $request->bearerToken();
        $row = DB::fetchOne("SELECT v FROM settings WHERE k = 'collector_bearer_token' LIMIT 1");
        $expected = $row !== null && $row['v'] !== ''
            ? (string) $row['v']
            : (string) Config::get('security.collector_bearer_token', '');

        if ($provided === null || $expected === '' || !hash_equals($expected, $provided)) {
            Response::json([
                'ok' => false,
                'error' => 'توکن Bearer نامعتبر است. توکن تنظیم‌شده در collector-remote را با پنل تنظیمات تطبیق دهید.',
                'code' => 'invalid_bearer_token',
            ], 401);
        }
    }
}
