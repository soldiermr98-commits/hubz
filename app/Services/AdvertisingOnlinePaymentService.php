<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\DB;
use DomainException;
use InvalidArgumentException;
use Throwable;

/** A link represents one immutable attempt. Uncertain attempts block other ledger changes. */
final class AdvertisingOnlinePaymentService
{
    public function __construct(private PaymentGatewaySettings $settings, private PaymentGatewayClient $gateway, private bool $sandboxOnly = true) {}

    private static function requireAllowedMode(string $mode, bool $sandboxOnly): void
    {
        if ($sandboxOnly && $mode !== 'sandbox') throw new DomainException('فقط تراکنش آزمایشی در این نسخه مجاز است.', 409);
    }

    public static function baseUrl(): string
    {
        $base = rtrim((string)Config::get('app.url', ''), '/');
        if (!preg_match('/\Ahttps:\/\/[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?(?::443)?\z/', $base)
            || !filter_var($base, FILTER_VALIDATE_URL) || str_contains($base, '..')) {
            throw new DomainException('نشانی اصلی سایت باید در تنظیمات خصوصی یک دامنه HTTPS معتبر باشد.', 409);
        }
        return $base;
    }

    private static function token(mixed $token): string
    {
        if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/', $token)) throw new InvalidArgumentException('لینک معتبر نیست.');
        return $token;
    }
    private static function reason(mixed $reason): string
    {
        if (!is_string($reason)) throw new InvalidArgumentException('دلیل معتبر نیست.');
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') < 12 || mb_strlen($reason, 'UTF-8') > 1000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $reason)) throw new InvalidArgumentException('دلیل بررسی باید بین ۱۲ تا ۱۰۰۰ حرف باشد.');
        return $reason;
    }
    private static function audit(int $id, string $old, string $new, string $reason, ?int $actor = null, ?string $reference = null): void
    {
        DB::query('INSERT INTO advertising_online_audit (payment_id,old_state,new_state,reason,reference,changed_by) VALUES (:id,:old,:new,:reason,:ref,:actor)',
            ['id'=>$id,'old'=>$old,'new'=>$new,'reason'=>$reason,'ref'=>$reference,'actor'=>$actor]);
    }
    private static function contractVersion(int $id): void
    {
        DB::query('UPDATE advertising_contracts SET version=version+1,updated_at=NOW() WHERE id=:id', ['id'=>$id]);
    }
    private static function lookup(string $token): ?array
    {
        return DB::fetchOne('SELECT id,contract_id FROM advertising_online_payments WHERE link_hash=:hash', ['hash'=>hash('sha256', self::token($token))]);
    }
    private static function lockedPayment(int $id, int $contractId): ?array
    {
        return DB::fetchOne('SELECT *, (expires_at>NOW()) AS unexpired, (updated_at<=DATE_SUB(NOW(), INTERVAL 5 MINUTE)) AS stale FROM advertising_online_payments WHERE id=:id AND contract_id=:contract FOR UPDATE', ['id'=>$id,'contract'=>$contractId]);
    }

    /** Token is generated in the browser and returned only on creation/replay, never in listings. */
    public function createLink(array $data, int $userId): array
    {
        $base = self::baseUrl();
        $id = AdvertisingFinanceService::id($data['contract_id'] ?? null);
        $amount = AdvertisingFinanceService::amount($data['amount_toman'] ?? null);
        $key = AdvertisingFinanceService::requestKey($data['request_key'] ?? null);
        $token = self::token($data['link_token'] ?? null);
        if (hash_equals($key, $token)) throw new InvalidArgumentException('شناسه درخواست و رمز لینک باید مستقل باشند.');
        $provider = $data['provider'] ?? null; $hours = $data['hours'] ?? null; $version = $data['version'] ?? null;
        if (!is_string($provider) || !isset(PaymentGatewaySettings::PROVIDERS[$provider]) || !is_int($hours) || $hours < 1 || $hours > 72 || !is_int($version) || $version < 1 || ($data['confirmed'] ?? null) !== true) throw new InvalidArgumentException('درگاه، مدت و تأیید لینک معتبر نیست.');
        PaymentGatewayClient::validateAmount($provider, $amount);
        $hash = hash('sha256', $token); $pdo = DB::connection(); $pdo->beginTransaction();
        try {
            $c = AdvertisingFinanceService::lockContract($id);
            $existing = DB::fetchOne('SELECT id,contract_id,provider,mode,amount_toman,link_hash,link_hours,state FROM advertising_online_payments WHERE request_key=:key', ['key'=>$key]);
            if ($existing) {
                self::requireAllowedMode((string)$existing['mode'], $this->sandboxOnly);
                if ((int)$existing['contract_id'] !== $id || $existing['provider'] !== $provider || (int)$existing['amount_toman'] !== $amount || (int)$existing['link_hours'] !== $hours || !hash_equals($existing['link_hash'], $hash)) throw new DomainException('شناسه درخواست قبلاً برای لینک دیگری استفاده شده است.', 409);
                $pdo->commit(); return ['id'=>(int)$existing['id'],'state'=>$existing['state'],'url'=>$base . '/api/v1/public/advertising-payment/' . $token,'replayed'=>true];
            }
            if ($c['status'] !== 'active' || (int)$c['version'] !== $version) throw new DomainException('قرارداد فعال نیست یا تغییر کرده است؛ فهرست را تازه کنید.', 409);
            if (AdvertisingFinanceService::hasUnresolved($id)) throw new DomainException('ابتدا لینک یا تراکنش آنلاین باز را تعیین تکلیف کنید.', 409);
            if ($amount > (int)$c['amount_toman'] - AdvertisingFinanceService::paidAmount($id)) throw new DomainException('مبلغ از مانده قرارداد بیشتر است.', 409);
            $snapshot = $this->settings->snapshot($provider); // settings row locked through commit
            self::requireAllowedMode($snapshot['mode'], $this->sandboxOnly);
            $order = 'ad' . bin2hex(random_bytes(12));
            DB::query("INSERT INTO advertising_online_payments (contract_id,provider,mode,state,amount_toman,request_key,link_hours,link_hash,order_id,settings_version,secret_sealed,created_by,expires_at)
                VALUES (:contract,:provider,:mode,'link_ready',:amount,:key,:hours,:hash,:order_id,:version,:secret,:user,DATE_ADD(NOW(), INTERVAL :duration HOUR))",
                ['contract'=>$id,'provider'=>$provider,'mode'=>$snapshot['mode'],'amount'=>$amount,'key'=>$key,'hours'=>$hours,'hash'=>$hash,'order_id'=>$order,'version'=>$snapshot['version'],'secret'=>$snapshot['secret_sealed'],'user'=>$userId,'duration'=>$hours]);
            $paymentId = DB::lastInsertId();
            self::audit($paymentId, 'new', 'link_ready', 'link_created', $userId);
            self::contractVersion($id); $pdo->commit();
            return ['id'=>$paymentId,'state'=>'link_ready','url'=>$base . '/api/v1/public/advertising-payment/' . $token,'replayed'=>false];
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /** No customer name, secret, token hash or gateway response is returned. */
    public static function publicView(string $token): ?array
    {
        $found = self::lookup($token); if (!$found) return null;
        return DB::fetchOne("SELECT p.id,p.provider,p.mode,p.state,p.amount_toman,p.expires_at,(p.expires_at>NOW()) AS unexpired,c.title
            FROM advertising_online_payments p JOIN advertising_contracts c ON c.id=p.contract_id WHERE p.id=:id", ['id'=>$found['id']]);
    }

    private static function markUncertain(int $id, int $contractId, string $expected, string $code): void
    {
        $pdo = DB::connection(); $pdo->beginTransaction();
        try {
            AdvertisingFinanceService::lockContract($contractId);
            $p = self::lockedPayment($id, $contractId);
            if ($p && $p['state'] === $expected) {
                DB::query("UPDATE advertising_online_payments SET state='uncertain',last_error_code=:code,updated_at=NOW() WHERE id=:id", ['id'=>$id,'code'=>$code]);
                self::audit($id, $expected, 'uncertain', $code);
            }
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /** A single request to the provider. Never create another transaction on an ambiguous outcome. */
    public function checkout(string $token): string
    {
        $found = self::lookup($token); if (!$found) throw new DomainException('لینک پرداخت پیدا نشد.', 404);
        $id = (int)$found['id']; $contract = (int)$found['contract_id'];
        $pdo = DB::connection(); $pdo->beginTransaction();
        try {
            $c = AdvertisingFinanceService::lockContract($contract);
            AdvertisingFinanceService::expireUnstarted($contract);
            $p = self::lockedPayment($id, $contract);
            if (!$p) throw new DomainException('لینک پرداخت پیدا نشد.', 404);
            self::requireAllowedMode((string)$p['mode'], $this->sandboxOnly);
            if ($p['state'] === 'pending') {
                $url = PaymentGatewayClient::startUrl((string)$p['provider'], (string)$p['mode'], (string)$p['provider_id']);
                $pdo->commit(); return $url;
            }
            if ($p['state'] !== 'link_ready' || !(int)$p['unexpired'] || $c['status'] !== 'active') throw new DomainException('این پرداخت باز نیست؛ با پشتیبانی تماس بگیرید.', 409);
            if ((int)$p['amount_toman'] > (int)$c['amount_toman'] - AdvertisingFinanceService::paidAmount($contract)) throw new DomainException('مانده قرارداد تغییر کرده است؛ با پشتیبانی تماس بگیرید.', 409);
            DB::query("UPDATE advertising_online_payments SET state='creating',updated_at=NOW() WHERE id=:id", ['id'=>$id]);
            self::audit($id, 'link_ready', 'creating', 'checkout_started'); $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }

        try {
            $secret = $this->settings->decryptSnapshot((string)$p['provider'], (string)$p['secret_sealed'], (string)$p['mode']);
            $created = $this->gateway->create((string)$p['provider'], (string)$p['mode'], $secret, (int)$p['amount_toman'], (string)$p['order_id'], self::baseUrl() . '/api/v1/public/advertising-payment/callback/' . $token);
            unset($secret);
            $pdo->beginTransaction();
            AdvertisingFinanceService::lockContract($contract);
            $current = self::lockedPayment($id, $contract);
            if (!$current || $current['state'] !== 'creating') throw new DomainException('وضعیت تراکنش تغییر کرده است.', 409);
            DB::query("UPDATE advertising_online_payments SET state='pending',provider_id=:provider_id,checkout_url=:url,updated_at=NOW() WHERE id=:id", ['id'=>$id,'provider_id'=>$created['provider_id'],'url'=>$created['checkout_url']]);
            self::audit($id, 'creating', 'pending', 'gateway_session_created'); $pdo->commit();
            return $created['checkout_url'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            self::markUncertain($id, $contract, 'creating', 'create_unconfirmed');
            throw new DomainException('نتیجه ایجاد تراکنش نامشخص است؛ از پرداخت مجدد خودداری کنید و با پشتیبانی تماس بگیرید.', 409);
        }
    }

    private static function callbackMatches(array $p, array $input): bool
    {
        $same = static fn(mixed $value, string $expected): bool => (is_string($value) || is_int($value)) && hash_equals($expected, (string)$value);
        $id = (string)$p['provider_id']; $order = (string)$p['order_id'];
        if ($p['provider'] === 'zibal') return $same($input['trackId'] ?? null, $id) && $same($input['success'] ?? null, '1');
        if ($p['provider'] === 'aqayepardakht') return $same($input['transid'] ?? null, $id) && $same($input['invoice_id'] ?? null, $order) && $same($input['status'] ?? null, '1');
        if ($p['provider'] === 'idpay') return $same($input['id'] ?? null, $id) && $same($input['order_id'] ?? null, $order) && $same($input['status'] ?? null, '10');
        return false;
    }

    /** Callback fields are only a trigger; the provider must separately confirm the stored attempt. */
    public function callback(string $token, array $input): string
    {
        $found = self::lookup($token); if (!$found) return 'not_found';
        $id = (int)$found['id']; $contract = (int)$found['contract_id'];
        $pdo = DB::connection(); $pdo->beginTransaction();
        try {
            AdvertisingFinanceService::lockContract($contract);
            $p = self::lockedPayment($id, $contract);
            if (!$p) { $pdo->commit(); return 'not_found'; }
            self::requireAllowedMode((string)$p['mode'], $this->sandboxOnly);
            if (in_array($p['state'], ['verified','reconciled','sandbox_verified','sandbox_reconciled'], true)) { $pdo->commit(); return (string)$p['state']; }
            if ($p['state'] !== 'pending' || !self::callbackMatches($p, $input)) { $pdo->commit(); return 'unconfirmed'; }
            DB::query("UPDATE advertising_online_payments SET state='verifying',updated_at=NOW() WHERE id=:id", ['id'=>$id]);
            self::audit($id, 'pending', 'verifying', 'verification_started'); $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }

        try {
            $secret = $this->settings->decryptSnapshot((string)$p['provider'], (string)$p['secret_sealed'], (string)$p['mode']);
            $result = $this->gateway->verify((string)$p['provider'], (string)$p['mode'], $secret, (int)$p['amount_toman'], (string)$p['order_id'], (string)$p['provider_id']);
            unset($secret);
        } catch (Throwable $e) {
            self::markUncertain($id, $contract, 'verifying', 'verify_unconfirmed'); return 'uncertain';
        }
        if (($result['verified'] ?? false) !== true) {
            $code = (string)($result['error'] ?? 'unconfirmed');
            if (!preg_match('/\A[a-z_]{1,40}\z/', $code)) $code = 'unconfirmed';
            self::markUncertain($id, $contract, 'verifying', $code); return 'uncertain';
        }
        try {
            $pdo->beginTransaction(); $c = AdvertisingFinanceService::lockContract($contract); $current = self::lockedPayment($id, $contract);
            if (!$current || $current['state'] !== 'verifying' || $c['status'] !== 'active' || (int)$p['amount_toman'] > (int)$c['amount_toman'] - AdvertisingFinanceService::paidAmount($contract)) throw new DomainException('تراکنش نیازمند بررسی است.', 409);
            $state = $p['mode'] === 'live' ? 'verified' : 'sandbox_verified';
            AdvertisingFinanceService::claimReceipt((string)$result['reference'], 'online', $id, $contract, $p['mode'] === 'sandbox');
            DB::query('UPDATE advertising_online_payments SET state=:state,verified_reference=:ref,last_error_code=NULL,updated_at=NOW() WHERE id=:id', ['state'=>$state,'ref'=>$result['reference'],'id'=>$id]);
            self::audit($id, 'verifying', $state, 'gateway_verified', null, $result['reference']);
            self::contractVersion($contract); $pdo->commit(); return $state;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            self::markUncertain($id, $contract, 'verifying', 'verification_store'); return 'uncertain';
        }
    }

    /** Administrative assertion after out-of-band provider investigation; never presented as automatic verification. */
    public static function resolve(array $data, int $userId, bool $sandboxOnly = true): void
    {
        $id = AdvertisingFinanceService::id($data['payment_id'] ?? null);
        $contract = AdvertisingFinanceService::id($data['contract_id'] ?? null);
        $reason = self::reason($data['reason'] ?? null);
        $resolution = $data['resolution'] ?? null;
        if (!in_array($resolution, ['cancel_link','paid','not_paid'], true) || ($data['confirmed'] ?? null) !== true) throw new InvalidArgumentException('نوع و تأیید تصمیم معتبر نیست.');
        if ($resolution !== 'cancel_link' && (($data['checked_provider'] ?? null) !== true || ($data['no_future_charge_confirmed'] ?? null) !== true)) throw new InvalidArgumentException('بررسی مستقل درگاه و نبود امکان پرداخت بعدی باید صریحاً تأیید شود.');
        $reference = null;
        if ($resolution === 'paid') {
            $reference = $data['reference'] ?? null;
            if (!is_string($reference) || !preg_match('/\A[A-Za-z0-9_-]{3,120}\z/', $reference)) throw new InvalidArgumentException('مرجع مستقل درگاه (حروف انگلیسی یا رقم) الزامی است.');
        }
        $pdo = DB::connection(); $pdo->beginTransaction();
        try {
            $c = AdvertisingFinanceService::lockContract($contract); $p = self::lockedPayment($id, $contract);
            if (!$p) throw new DomainException('تراکنش یافت نشد.', 409);
            self::requireAllowedMode((string)$p['mode'], $sandboxOnly);
            $old = (string)$p['state'];
            if ($resolution === 'cancel_link') {
                if ($old !== 'link_ready') throw new DomainException('فقط لینک شروع‌نشده را می‌توان بدون بررسی درگاه لغو کرد.', 409);
                $new = 'cancelled'; $code = 'admin_cancel_link';
            } else {
                if (!in_array($old, ['creating','pending','verifying','uncertain'], true)) throw new DomainException('این تراکنش در انتظار تعیین تکلیف نیست.', 409);
                if (in_array($old, ['creating','verifying'], true) && !(int)$p['stale']) throw new DomainException('عملیات در حال اجراست؛ دست‌کم پنج دقیقه صبر و سپس درگاه را بررسی کنید.', 409);
                if ($resolution === 'paid') {
                    if ($c['status'] !== 'active' || (int)$p['amount_toman'] > (int)$c['amount_toman'] - AdvertisingFinanceService::paidAmount($contract)) throw new DomainException('مانده یا وضعیت قرارداد با این تصمیم سازگار نیست.', 409);
                    $new = $p['mode'] === 'live' ? 'reconciled' : 'sandbox_reconciled'; $code = 'admin_asserted_paid';
                    AdvertisingFinanceService::claimReceipt((string)$reference, 'online', $id, $contract, $p['mode'] === 'sandbox');
                } else { $new = 'cancelled'; $code = 'admin_asserted_not_paid'; }
            }
            DB::query('UPDATE advertising_online_payments SET state=:state,verified_reference=:ref,last_error_code=:code,updated_at=NOW() WHERE id=:id', ['state'=>$new,'ref'=>$reference,'code'=>$code,'id'=>$id]);
            self::audit($id, $old, $new, $reason, $userId, $reference); self::contractVersion($contract); $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
