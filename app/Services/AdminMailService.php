<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Optional best-effort notifications. No recipient data or credentials are logged. */
final class AdminMailService
{
    private const KEY = 'admin_mail_v1';

    private static function state(): array
    {
        $row = DB::fetchOne('SELECT v FROM settings WHERE k=:k', ['k'=>self::KEY]);
        if (!$row) return [];
        $state = json_decode((string)$row['v'], true);
        if (!is_array($state)) throw new RuntimeException('تنظیمات ایمیل قابل خواندن نیست.');
        return $state;
    }

    public static function status(): array
    {
        $s = self::state();
        return [
            'enabled'=>(bool)($s['enabled'] ?? false), 'method'=>$s['method'] ?? 'php',
            'recipient'=>$s['recipient'] ?? '', 'from'=>$s['from'] ?? '',
            'host'=>$s['host'] ?? '', 'port'=>(int)($s['port'] ?? 587),
            'security'=>$s['security'] ?? 'tls', 'username'=>$s['username'] ?? '',
            'password_configured'=>is_array($s['secret'] ?? null), 'version'=>(int)($s['version'] ?? 0),
        ];
    }

    public static function save(array $input): void
    {
        $current = self::state();
        $version = $input['version'] ?? null;
        if (!is_int($version) || $version !== (int)($current['version'] ?? 0)) throw new RuntimeException('تنظیمات هم‌زمان تغییر کرده‌اند؛ صفحه را تازه کنید.', 409);
        foreach (['recipient','from','host','username','password'] as $field) {
            if (isset($input[$field]) && !is_string($input[$field])) throw new InvalidArgumentException('تنظیمات ایمیل معتبر نیست.');
        }
        $enabled = $input['enabled'] ?? null;
        $method = $input['method'] ?? null;
        $recipient = trim((string)($input['recipient'] ?? ''));
        $from = trim((string)($input['from'] ?? ''));
        $host = trim((string)($input['host'] ?? ''));
        $port = $input['port'] ?? null;
        $security = $input['security'] ?? null;
        $username = trim((string)($input['username'] ?? ''));
        $password = $input['password'] ?? '';
        if (!is_bool($enabled) || !in_array($method, ['php','smtp'], true) || !is_int($port) || $port < 1 || $port > 65535 || !in_array($security, ['tls','ssl'], true)
            || !is_string($password) || strlen($password) > 1024 || strlen($username) > 255 || preg_match('/[\r\n\x00]/', $username . $password)
            || ($recipient !== '' && !filter_var($recipient, FILTER_VALIDATE_EMAIL)) || ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL))
            || strlen($recipient) > 254 || strlen($from) > 254
            || ($host !== '' && (strlen($host) > 253 || !preg_match('/\A[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?\z/D', $host)))) {
            throw new InvalidArgumentException('تنظیمات ایمیل معتبر نیست.');
        }
        $clear = $input['clear_password'] ?? false;
        if (!is_bool($clear) || ($clear && $password !== '')) throw new InvalidArgumentException('وضعیت رمز SMTP معتبر نیست.');
        $secret = $clear ? null : ($current['secret'] ?? null);
        if ($password !== '') $secret = (new AiProviderVault(BASE_PATH))->encrypt('admin-mail-smtp', $password, true);
        if ($enabled && ($recipient === '' || $from === '' || ($method === 'smtp' && ($host === '' || $username === '' || !is_array($secret))))) {
            throw new InvalidArgumentException('برای فعال‌سازی، گیرنده و فرستنده و اطلاعات کامل SMTP لازم است.');
        }
        $next = ['version'=>$version+1, 'enabled'=>$enabled, 'method'=>$method, 'recipient'=>$recipient, 'from'=>$from,
            'host'=>$host, 'port'=>$port, 'security'=>$security, 'username'=>$username, 'secret'=>$secret];
        $json = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // Conditional update prevents silently overwriting a concurrent administrator's save.
        if ($current) {
            $old = DB::fetchOne('SELECT v FROM settings WHERE k=:k', ['k'=>self::KEY]);
            $oldValue = (string)($old['v'] ?? '');
            $check = json_decode($oldValue, true);
            if (!is_array($check) || ($check['version'] ?? 0) !== $version) throw new RuntimeException('تنظیمات هم‌زمان تغییر کرده‌اند.', 409);
            $changed = DB::query('UPDATE settings SET v=:v WHERE k=:k AND v=:old', ['v'=>$json,'k'=>self::KEY,'old'=>$oldValue])->rowCount();
            if ($changed !== 1) throw new RuntimeException('تنظیمات هم‌زمان تغییر کرده‌اند.', 409);
        } else {
            try { DB::query('INSERT INTO settings (k,v) VALUES (:k,:v)', ['k'=>self::KEY,'v'=>$json]); }
            catch (Throwable $e) { throw new RuntimeException('تنظیمات هم‌زمان تغییر کرده‌اند.', 409); }
        }
    }

    /** Delivery is best effort: false means disabled or not accepted by the local transport. */
    public static function send(string $subject, string $body, bool $test = false): bool
    {
        $s = self::state();
        if ((!$test && empty($s['enabled'])) || empty($s['recipient']) || empty($s['from'])) return false;
        require_once BASE_PATH . '/app/ThirdParty/PHPMailer/Exception.php';
        require_once BASE_PATH . '/app/ThirdParty/PHPMailer/SMTP.php';
        require_once BASE_PATH . '/app/ThirdParty/PHPMailer/PHPMailer.php';
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 5;
        $mail->setFrom((string)$s['from']);
        $mail->addAddress((string)$s['recipient']);
        $mail->Subject = $subject;
        $mail->Body = $body;
        if (($s['method'] ?? '') === 'smtp') {
            if (empty($s['host']) || empty($s['username']) || !is_array($s['secret'] ?? null)) throw new RuntimeException('تنظیمات SMTP کامل نیست.');
            $mail->isSMTP();
            $mail->Host = (string)$s['host'];
            $mail->Port = (int)$s['port'];
            $mail->SMTPSecure = ($s['security'] ?? '') === 'ssl' ? 'ssl' : 'tls';
            $mail->SMTPAuth = true;
            $mail->Username = (string)$s['username'];
            $mail->Password = (new AiProviderVault(BASE_PATH))->decrypt('admin-mail-smtp', $s['secret']);
        } elseif (($s['method'] ?? '') !== 'php') {
            throw new RuntimeException('روش ارسال معتبر نیست.');
        }
        return $mail->send();
    }

    public static function notify(string $event): void
    {
        $messages = [
            'advertising'=>['درخواست تبلیغات جدید در حامی‌هاب', 'یک درخواست تبلیغات جدید ثبت شده است. برای بررسی وارد پنل مدیریت و بخش تبلیغات و درخواست‌ها شوید.'],
            'report'=>['گزارش جدید در حامی‌هاب', 'یک گزارش جدید ثبت شده است. برای بررسی وارد صندوق بازبینی در پنل مدیریت شوید.'],
            'comment'=>['دیدگاه جدید در حامی‌هاب', 'یک دیدگاه جدید برای تایید ثبت شده است. برای بررسی وارد تب «دیدگاه‌ها» در پنل مدیریت شوید.'],
        ];
        if (!isset($messages[$event])) return;
        try {
            self::send($messages[$event][0], $messages[$event][1]);
        } catch (Throwable $e) {
            // The submitted record remains saved even if mail is unavailable. No sensitive details in logs.
            error_log('Admin mail notification failed; check mail configuration.');
        }
    }
}
