<?php
declare(strict_types=1);

/**
 * نصب‌کننده یک‌بارمصرف وب برای ساخت تمام جداول دیتابیس و اولین مدیر روی هاست اشتراکی DirectAdmin
 * (بدون نیاز به دسترسی ترمینال یا SSH)
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Core/Bootstrap.php';

use App\Core\Auth;
use App\Core\Bootstrap;
use App\Core\Config;
use App\Core\DB;
use App\Core\Migrator;
use App\Core\Response;

Bootstrap::init(BASE_PATH);
Response::sendSecurityHeaders(true);

$lockFile = BASE_PATH . '/storage/installed.lock';
if (is_file($lockFile)) {
    Response::html(
        '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>نصب قبلاً انجام شده است</title></head>' .
        '<body style="font-family:tahoma,sans-serif;padding:3rem;text-align:center;">' .
        '<h2>نصب اولیه قبلاً انجام و قفل شده است.</h2>' .
        '<p>برای امنیت بیشتر لطفاً فایل <code>public_html/install.php</code> را از طریق File Manager دایرکت‌ادمین حذف کنید.</p>' .
        '<p><a href="/admin/">ورود به پنل مدیریت (/admin/)</a></p></body></html>',
        403
    );
}

$expectedToken = (string) Config::get('app.install_token', '');
$providedToken = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));

if (strlen($expectedToken) < 24 || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    Response::html(
        '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>توکن نصب نامعتبر</title></head>' .
        '<body style="font-family:tahoma,sans-serif;padding:3rem;text-align:center;">' .
        '<h2>توکن نصب معتبر نیست.</h2>' .
        '<p>لطفاً مقدار <code>app.install_token</code> از فایل <code>config/config.local.php</code> را در آدرس مرورگر وارد کنید:<br><code dir="ltr">/install.php?token=YOUR_RANDOM_TOKEN</code></p>' .
        '</body></html>',
        403
    );
}

$error = '';
$success = '';
$migrationReport = null;

try {
    // ۱. اجرای خودکار تمام فایل‌های مایگریشن از طریق مرورگر (بدون نیاز به SSH)
    $migrationReport = Migrator::runIncremental();

    $countRow = DB::fetchOne("SELECT COUNT(*) AS cnt FROM users");
    $userCount = (int) ($countRow['cnt'] ?? 0);

    if ($userCount > 0) {
        @file_put_contents($lockFile, date('c') . " - Locked automatically (users table not empty)\n");
        Response::html(
            '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>مدیر از قبل وجود دارد</title></head>' .
            '<body style="font-family:tahoma,sans-serif;padding:3rem;text-align:center;">' .
            '<h2>حداقل یک کاربر در جدول users وجود دارد. نصب‌کننده قفل شد.</h2>' .
            '<p><a href="/admin/">رفتن به پنل مدیریت (/admin/)</a></p></body></html>',
            403
        );
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $displayName = trim((string) ($_POST['display_name'] ?? 'مدیر ارشد حامی‌هاب'));
        $password = (string) ($_POST['password'] ?? '');

        if (!preg_match('/^[a-z0-9_]{3,32}$/', $username)) {
            $error = 'نام کاربری باید بین ۳ تا ۳۲ کاراکتر و فقط شامل حروف انگلیسی کوچک، اعداد یا زیرخط باشد.';
        } elseif (strlen($password) < 8) {
            $error = 'رمز عبور باید حداقل ۸ کاراکتر باشد.';
        } else {
            $hash = Auth::hashPassword($password);
            DB::query(
                "INSERT INTO users (username, display_name, password_hash, role, is_active, created_at)
                 VALUES (:u, :d, :h, 'admin', 1, NOW())",
                [
                    'u' => $username,
                    'd' => $displayName !== '' ? $displayName : 'مدیر ارشد',
                    'h' => $hash,
                ]
            );

            $lockWritten = @file_put_contents($lockFile, date('c') . " - Installed by {$username}\n") !== false;

            if (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
                Response::json([
                    'ok' => $lockWritten,
                    'message' => $lockWritten ? 'جداول و مدیر ساخته شدند و نصب‌کننده قفل شد.' : 'مدیر ساخته شد اما ایجاد قفل ناموفق بود؛ install.php را فوراً حذف کنید.',
                    'username' => $username,
                    'migrations' => $migrationReport,
                ]);
            }

            $success = $lockWritten ? 'مدیر سیستم ساخته شد و فایل قفل ایجاد گردید. اکنون می‌توانید وارد پنل مدیریت شوید.' : 'مدیر ساخته شد اما ایجاد فایل قفل ناموفق بود. برای امنیت، install.php را فوراً حذف کنید.';
        }
    }
} catch (Throwable $e) {
    $error = 'خطا در اتصال به دیتابیس یا اجرای جداول: ' . $e->getMessage();
}

$html = '<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>نصب اولیه تحت وب HamiHubX.ir (بدون نیاز به ترمینال)</title>
<style>
body{font-family:Tahoma,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:2rem;}
.box{max-width:480px;margin:2rem auto;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1.75rem;}
h1{font-size:1.2rem;margin-top:0;}
label{display:block;margin-top:1rem;font-size:.9rem;font-weight:bold;}
input{width:100%;box-sizing:border-box;padding:.6rem;margin-top:.35rem;border:1px solid #cbd5e1;border-radius:6px;}
button{margin-top:1.25rem;width:100%;padding:.75rem;background:#047857;color:#fff;border:0;border-radius:6px;font-weight:bold;cursor:pointer;}
.err{background:#fef2f2;color:#b91c1c;padding:.75rem;border-radius:6px;margin-bottom:1rem;font-size:.9rem;}
.ok{background:#ecfdf5;color:#047857;padding:.75rem;border-radius:6px;margin-bottom:1rem;font-size:.9rem;}
.mig{background:#f1f5f9;color:#334155;padding:.65rem;border-radius:6px;margin-bottom:1rem;font-size:.82rem;}
</style>
</head>
<body>
<div class="box">
  <h1>راه‌اندازی تحت وب HamiHubX.ir (مخصوص DirectAdmin)</h1>
  <p style="font-size:.85rem;color:#475569;">این صفحه بدون نیاز به SSH تمام جداول دیتابیس را می‌سازد و پس از ثبت اولین مدیر به‌صورت خودکار قفل می‌شود.</p>';

if (is_array($migrationReport)) {
    $html .= '<div class="mig">✅ وضعیت دیتابیس: تمام مایگریشن‌ها بررسی و اعمال شدند (تعداد جدید: ' .
        (int) $migrationReport['total_applied'] . '، قبلاً نصب‌شده: ' . count($migrationReport['skipped']) . ').</div>';
}

if ($error !== '') {
    $html .= '<div class="err">' . Response::e($error) . '</div>';
}

if ($success !== '') {
    $html .= '<div class="ok">' . Response::e($success) . '<br><br><a href="/admin/"><strong>ورود به پنل مدیریت (/admin/)</strong></a></div>';
} else {
    $html .= '<form method="post" action="/install.php">
      <input type="hidden" name="token" value="' . Response::e($providedToken) . '">
      <label for="username">نام کاربری مدیر (انگلیسی)</label>
      <input id="username" name="username" type="text" value="admin" required>
      <label for="display_name">نام نمایشی (فارسی)</label>
      <input id="display_name" name="display_name" type="text" value="مدیر ارشد حامی‌هاب" required>
      <label for="password">رمز عبور (حداقل ۸ کاراکتر)</label>
      <input id="password" name="password" type="password" required>
      <button type="submit">ساخت مدیر ارشد و قفل کردن نصب‌کننده</button>
    </form>';
}

$html .= '</div></body></html>';
Response::html($html);
