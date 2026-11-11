<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\StaffAccess;
use Throwable;

/** Creates limited editors only. Never creates or modifies administrator accounts. */
final class StaffController
{
    private static function authorize(Request $request, bool $change): void
    {
        header('Cache-Control: private, no-store, max-age=0');
        Auth::requireRole(['admin']);
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        $secure = $https === 'on' || $https === '1';
        $trusted = Config::get('security.trusted_proxy_ips', []);
        if (!$secure && is_array($trusted) && in_array($request->ip(), $trusted, true)) $secure = strtolower((string)$request->header('X-Forwarded-Proto', '')) === 'https';
        if (!$secure && PHP_SAPI === 'cli-server' && getenv('HHX_ALLOW_TEST_FIXTURES') === '1'
            && in_array($request->ip(), ['127.0.0.1','::1'], true)
            && preg_match('/^hhx_test_[a-z0-9_]+$/', (string)Config::get('db.database', ''))) $secure = true;
        if (!$secure) Response::json(['ok'=>false,'error'=>'مدیریت کاربران فقط با HTTPS امکان‌پذیر است.'], 400);
        if ($change) Csrf::verifyOrFail($request);
    }

    public static function index(Request $request): void
    {
        self::authorize($request, false);
        $rows = DB::fetchAll("SELECT u.id,u.username,u.display_name,u.is_active,u.created_at,u.last_login_at,s.v AS permissions_json
            FROM users u LEFT JOIN settings s ON s.k=CONCAT('staff_permissions_',u.id)
            WHERE u.role='editor' AND LEFT(u.username, 10)=:prefix ORDER BY u.id DESC LIMIT 200", ['prefix'=>StaffAccess::PREFIX]);
        foreach ($rows as &$row) {
            if (!str_starts_with((string)$row['username'], StaffAccess::PREFIX)) { $row = null; continue; }
            $p = json_decode((string)($row['permissions_json'] ?? ''), true);
            $row['permissions'] = is_array($p) && array_is_list($p) ? array_values(array_intersect(StaffAccess::PERMISSIONS, array_filter($p, 'is_string'))) : [];
            unset($row['permissions_json']);
        }
        unset($row);
        Response::json(['ok'=>true,'users'=>array_values(array_filter($rows))]);
    }

    public static function save(Request $request): void
    {
        self::authorize($request, true);
        $d = $request->json();
        $id = $d['id'] ?? 0; $username = $d['username'] ?? ''; $name = $d['display_name'] ?? '';
        $password = $d['password'] ?? ''; $active = $d['is_active'] ?? null; $permissions = $d['permissions'] ?? null;
        if (!is_int($id) || $id < 0 || !is_string($username) || !is_string($name) || !is_string($password) || !is_bool($active)
            || !is_array($permissions) || !array_is_list($permissions) || count($permissions) > count(StaffAccess::PERMISSIONS)
            || count(array_filter($permissions, 'is_string')) !== count($permissions) || count(array_unique($permissions)) !== count($permissions)
            || array_diff($permissions, StaffAccess::PERMISSIONS) || trim($name) === '' || mb_strlen(trim($name)) > 120
            || ($id === 0 && !preg_match('/\A[a-z0-9][a-z0-9_]{2,39}\z/D', $username))
            || ($id === 0 && $password === '') || ($password !== '' && (strlen($password) < 12 || strlen($password) > 128))) {
            Response::json(['ok'=>false,'error'=>'نام، سطح دسترسی یا رمز معتبر نیست. رمز باید حداقل ۱۲ نویسه باشد.'], 422);
        }
        $pdo=DB::connection();
        try {
            $pdo->beginTransaction();
            if ($id === 0) {
                $full = StaffAccess::PREFIX . $username;
                if (DB::fetchOne('SELECT id FROM users WHERE username=:u LIMIT 1', ['u'=>$full])) { $pdo->rollBack(); Response::json(['ok'=>false,'error'=>'نام کاربری از قبل وجود دارد.'], 409); }
                DB::query("INSERT INTO users (username,display_name,password_hash,role,is_active) VALUES (:u,:n,:p,'editor',:a)",
                    ['u'=>$full,'n'=>trim($name),'p'=>Auth::hashPassword($password),'a'=>$active?1:0]);
                $id=DB::lastInsertId();
            } else {
                $row=DB::fetchOne('SELECT id,username,role FROM users WHERE id=:id FOR UPDATE', ['id'=>$id]);
                if (!$row || $row['role'] !== 'editor' || !str_starts_with((string)$row['username'], StaffAccess::PREFIX)) {
                    $pdo->rollBack(); Response::json(['ok'=>false,'error'=>'کاربر محدود پیدا نشد.'], 404);
                }
                DB::query('UPDATE users SET display_name=:n,is_active=:a WHERE id=:id', ['n'=>trim($name),'a'=>$active?1:0,'id'=>$id]);
                if ($password !== '') DB::query('UPDATE users SET password_hash=:p WHERE id=:id', ['p'=>Auth::hashPassword($password),'id'=>$id]);
            }
            DB::query('INSERT INTO settings (k,v) VALUES (:k,:v) ON DUPLICATE KEY UPDATE v=VALUES(v)',
                ['k'=>'staff_permissions_'.$id,'v'=>json_encode($permissions, JSON_THROW_ON_ERROR)]);
            $pdo->commit();
            Response::json(['ok'=>true,'id'=>$id,'message'=>'کاربر محدود ذخیره شد. تغییر دسترسی و غیرفعال‌سازی فوراً اعمال می‌شود.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::json(['ok'=>false,'error'=>'ذخیره انجام نشد؛ نام کاربری یا اتصال پایگاه داده را بررسی کنید.'], 409);
        }
    }
}
