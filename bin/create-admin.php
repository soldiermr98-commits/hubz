<?php
declare(strict_types=1);

/**
 * ابزار خط فرمان برای ساخت مدیر جدید یا اولین مدیر سیستم
 * نحوه استفاده:
 * php bin/create-admin.php admin StrongPass123! "مدیر ارشد همی‌هاب"
 */

require_once dirname(__DIR__) . '/app/Core/Bootstrap.php';

use App\Core\Auth;
use App\Core\Bootstrap;
use App\Core\DB;

Bootstrap::init(dirname(__DIR__));

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('این اسکریپت فقط از طریق خط فرمان (CLI) قابل اجراست.');
}

$username = strtolower(trim((string) ($argv[1] ?? 'admin')));
$password = (string) ($argv[2] ?? '');
$displayName = trim((string) ($argv[3] ?? 'مدیر ارشد سیستم'));
$role = in_array(($argv[4] ?? 'admin'), ['admin', 'editor'], true) ? $argv[4] : 'admin';

if ($username === '' || strlen($password) < 8) {
    echo "خطا: نام کاربری الزامی است و رمز عبور باید حداقل ۸ کاراکتر باشد.\n";
    echo "نمونه دستور:\n";
    echo "php bin/create-admin.php admin MySecurePass123 \"مدیر ارشد\"\n";
    exit(1);
}

$existing = DB::fetchOne("SELECT id FROM users WHERE username = :u LIMIT 1", ['u' => $username]);
$hash = Auth::hashPassword($password);

if ($existing !== null) {
    DB::query(
        "UPDATE users SET password_hash = :h, display_name = :d, role = :r, is_active = 1 WHERE id = :id",
        ['h' => $hash, 'd' => $displayName, 'r' => $role, 'id' => (int) $existing['id']]
    );
    echo "کاربر «{$username}» از قبل وجود داشت؛ رمز عبور و نقش او به «{$role}» به‌روزرسانی شد.\n";
    exit(0);
}

DB::query(
    "INSERT INTO users (username, display_name, password_hash, role, is_active, created_at)
     VALUES (:u, :d, :h, :r, 1, NOW())",
    [
        'u' => $username,
        'd' => $displayName,
        'h' => $hash,
        'r' => $role,
    ]
);

echo "مدیر جدید با نام کاربری «{$username}» و نقش «{$role}» با موفقیت ساخته شد.\n";
