<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Throwable;

/** Admin-only, append-oriented advertising ledger. All monetary values are integer toman. */
final class AdvertisingFinanceService
{
    public const MAX_TOMAN = 1000000000;

    public static function schemaReady(): bool
    {
        if (!AdvertisingReportingService::schemaReady()) return false;
        try {
            foreach (['advertising_contract_requests','advertising_receipt_claims','advertising_receipt_unique','advertising_contract_status_log','advertising_gateway_settings','advertising_gateway_settings_log','advertising_online_payments','advertising_online_audit'] as $table) {
                // DB::tableExists falls back to SHOW TABLES on hosts that hide information_schema.
                if (!DB::tableExists($table)) return false;
            }
            // A partially-created table is not a completed, conflict-checked backfill.
            return DB::fetchOne('SELECT migration FROM schema_migrations WHERE migration=:m', ['m'=>'010_advertising_receipt_uniqueness.sql']) !== null;
        } catch (Throwable $e) { return false; }
    }

    public static function amount(mixed $value): int
    {
        if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('مبلغ باید عدد صحیح به تومان باشد.');
        $raw = SlugService::toEnglishDigits(trim((string)$value));
        if (!preg_match('/\A[1-9][0-9]{0,9}\z/', $raw) || (int)$raw > self::MAX_TOMAN) {
            throw new InvalidArgumentException('مبلغ باید عدد صحیح مثبت به تومان و حداکثر یک میلیارد باشد؛ جداکننده و اعشار وارد نکنید.');
        }
        return (int)$raw;
    }

    public static function id(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[1-9][0-9]{0,9}\z/', (string)$value)) {
            throw new InvalidArgumentException('شناسه معتبر نیست.');
        }
        return (int)$value;
    }

    public static function requestKey(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[a-f0-9]{64}\z/', $value)) throw new InvalidArgumentException('شناسه امن درخواست معتبر نیست.');
        return $value;
    }

    private static function text(mixed $value, int $min, int $max, string $name): string
    {
        if (!is_string($value)) throw new InvalidArgumentException("{$name} معتبر نیست.");
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') < $min || mb_strlen($value, 'UTF-8') > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException("{$name} معتبر نیست.");
        }
        return $value;
    }

    private static function date(mixed $value): ?string
    {
        if ($value === '' || $value === null) return null;
        if (!is_string($value)) throw new InvalidArgumentException('تاریخ قرارداد معتبر نیست.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('تاریخ قرارداد معتبر نیست.');
        return $value;
    }

    /** Caller must hold the contract row lock for subsequent changes to its balance. */
    public static function lockContract(int $id): array
    {
        $row = DB::fetchOne('SELECT * FROM advertising_contracts WHERE id=:id FOR UPDATE', ['id'=>$id]);
        if (!$row) throw new DomainException('قرارداد یافت نشد.', 409);
        return $row;
    }

    public static function paidAmount(int $contractId): int
    {
        $manual = DB::fetchOne("SELECT COALESCE(SUM(amount_toman),0) AS n FROM advertising_manual_payments WHERE contract_id=:id AND status='recorded'", ['id'=>$contractId]);
        $online = DB::fetchOne("SELECT COALESCE(SUM(amount_toman),0) AS n FROM advertising_online_payments WHERE contract_id=:id AND mode='live' AND state IN ('verified','reconciled')", ['id'=>$contractId]);
        return (int)($manual['n'] ?? 0) + (int)($online['n'] ?? 0);
    }

    /** Claim a bank/gateway reference once across the real ledger; caller must be in a transaction. */
    public static function claimReceipt(string $reference, string $kind, int $paymentId, int $contractId, bool $sandbox = false): void
    {
        if (!in_array($kind, ['manual','online'], true)) throw new InvalidArgumentException('نوع رسید معتبر نیست.');
        if (!$sandbox) {
            $manual = DB::fetchOne('SELECT id FROM advertising_manual_payments WHERE reference=:ref AND id<>:exclude LIMIT 1', ['ref'=>$reference,'exclude'=>$kind === 'manual' ? $paymentId : 0]);
            $online = DB::fetchOne("SELECT id FROM advertising_online_payments WHERE verified_reference=:ref AND mode='live' AND state IN ('verified','reconciled') AND id<>:exclude LIMIT 1", ['ref'=>$reference,'exclude'=>$kind === 'online' ? $paymentId : 0]);
            if ($manual || $online) throw new DomainException('این مرجع پرداخت قبلاً ثبت شده است.', 409);
        }
        $hash = hash('sha256', ($sandbox ? 'sandbox:' : 'live:') . mb_strtolower($reference, 'UTF-8'));
        try {
            // The collated unique index serializes equivalent references even
            // across different contracts and representations (e.g. 123/۱۲۳).
            DB::query('INSERT INTO advertising_receipt_unique (namespace,reference,payment_kind,payment_id,contract_id) VALUES (:scope,:ref,:kind,:payment,:contract)', ['scope'=>$sandbox?'sandbox':'live','ref'=>$reference,'kind'=>$kind,'payment'=>$paymentId,'contract'=>$contractId]);
            // Retain the original hash claim for older exact-reference records.
            DB::query('INSERT INTO advertising_receipt_claims (reference_hash,payment_kind,payment_id,contract_id) VALUES (:hash,:kind,:payment,:contract)', ['hash'=>$hash,'kind'=>$kind,'payment'=>$paymentId,'contract'=>$contractId]);
        } catch (\PDOException $e) {
            if ((string)$e->getCode() === '23000') throw new DomainException('این مرجع پرداخت قبلاً ثبت شده است.', 409);
            throw $e;
        }
    }

    /** Expire only links that never started a gateway request; never expire a pending charge. */
    public static function expireUnstarted(int $contractId): void
    {
        $expired = DB::fetchAll("SELECT id FROM advertising_online_payments WHERE contract_id=:id AND state='link_ready' AND expires_at<=NOW() FOR UPDATE", ['id'=>$contractId]);
        foreach ($expired as $row) {
            DB::query("UPDATE advertising_online_payments SET state='cancelled',last_error_code='link_expired',updated_at=NOW() WHERE id=:id", ['id'=>$row['id']]);
            DB::query("INSERT INTO advertising_online_audit (payment_id,old_state,new_state,reason,changed_by) VALUES (:id,'link_ready','cancelled','link_expired',NULL)", ['id'=>$row['id']]);
        }
    }

    public static function hasUnresolved(int $contractId): bool
    {
        self::expireUnstarted($contractId);
        return DB::fetchOne("SELECT id FROM advertising_online_payments WHERE contract_id=:id AND state IN ('link_ready','creating','pending','verifying','uncertain') LIMIT 1", ['id'=>$contractId]) !== null;
    }

    public static function listing(): array
    {
        $contracts = DB::fetchAll("SELECT c.*,b.title AS banner_title,
            (SELECT COALESCE(SUM(p.amount_toman),0) FROM advertising_manual_payments p WHERE p.contract_id=c.id AND p.status='recorded') AS manual_paid,
            (SELECT COALESCE(SUM(o.amount_toman),0) FROM advertising_online_payments o WHERE o.contract_id=c.id AND o.mode='live' AND o.state IN ('verified','reconciled')) AS online_paid
            FROM advertising_contracts c LEFT JOIN advertising_banners b ON b.id=c.banner_id ORDER BY c.id DESC LIMIT 100");
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $contracts);
        if (!$ids) return ['contracts'=>[], 'manual_payments'=>[], 'online_payments'=>[], 'status_log'=>[], 'online_audit'=>[]];
        $where = implode(',', $ids);
        $manual = DB::fetchAll("SELECT id,contract_id,amount_toman,method,reference,notes,status,recorded_by,recorded_at,voided_by,voided_at,void_reason FROM advertising_manual_payments WHERE contract_id IN ({$where}) ORDER BY id DESC LIMIT 500");
        // Never expose link hashes, encrypted secrets, checkout URLs, or card data.
        $online = DB::fetchAll("SELECT id,contract_id,provider,mode,state,amount_toman,order_id,provider_id,verified_reference,last_error_code,created_at,updated_at,expires_at FROM advertising_online_payments WHERE contract_id IN ({$where}) ORDER BY id DESC LIMIT 500");
        $log = DB::fetchAll("SELECT id,contract_id,old_status,new_status,reason,changed_by,changed_at FROM advertising_contract_status_log WHERE contract_id IN ({$where}) ORDER BY id DESC LIMIT 500");
        $audit = DB::fetchAll("SELECT a.id,a.payment_id,a.old_state,a.new_state,a.reason,a.reference,a.changed_by,a.changed_at
            FROM advertising_online_audit a JOIN advertising_online_payments o ON o.id=a.payment_id WHERE o.contract_id IN ({$where}) ORDER BY a.id DESC LIMIT 1000");
        foreach ($contracts as &$contract) {
            $paid = (int)$contract['manual_paid'] + (int)$contract['online_paid'];
            $contract['paid_toman'] = $paid;
            $contract['remaining_toman'] = max(0, (int)$contract['amount_toman'] - $paid);
        }
        unset($contract);
        return ['contracts'=>$contracts, 'manual_payments'=>$manual, 'online_payments'=>$online, 'status_log'=>$log, 'online_audit'=>$audit];
    }

    /** The amount and initial terms are immutable; create a separate contract for a change. */
    public static function createContract(array $data, int $userId): array
    {
        $key = self::requestKey($data['request_key'] ?? null);
        $title = self::text($data['title'] ?? '', 1, 150, 'عنوان');
        $client = self::text($data['client_name'] ?? '', 1, 150, 'نام طرف قرارداد');
        $phoneInput = self::text($data['client_phone'] ?? '', 0, 32, 'شماره تماس');
        $phone = $phoneInput === '' ? null : Normalizer::normalizePhone($phoneInput);
        if ($phoneInput !== '' && $phone === '') throw new InvalidArgumentException('شماره تماس معتبر نیست.');
        $notes = self::text($data['notes'] ?? '', 0, 2000, 'توضیحات');
        $start = self::date($data['starts_at'] ?? ''); $end = self::date($data['ends_at'] ?? '');
        if ($start !== null && $end !== null && $start > $end) throw new InvalidArgumentException('تاریخ پایان قبل از شروع است.');
        $amount = self::amount($data['amount_toman'] ?? null);
        $banner = empty($data['banner_id']) ? null : self::id($data['banner_id']);
        $snapshot = compact('title','client','phone','notes','start','end','amount','banner');
        $hash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $pdo = DB::connection(); $pdo->beginTransaction();
        try {
            $existing = DB::fetchOne('SELECT contract_id,payload_hash FROM advertising_contract_requests WHERE request_key=:k FOR UPDATE', ['k'=>$key]);
            if ($existing) {
                if (!hash_equals((string)$existing['payload_hash'], $hash)) throw new DomainException('این شناسه درخواست قبلاً برای اطلاعات دیگری استفاده شده است.', 409);
                $pdo->commit(); return ['id'=>(int)$existing['contract_id'], 'replayed'=>true];
            }
            if ($banner !== null && !DB::fetchOne('SELECT id FROM advertising_banners WHERE id=:id', ['id'=>$banner])) throw new InvalidArgumentException('بنر انتخاب‌شده وجود ندارد.');
            DB::query("INSERT INTO advertising_contracts (title,client_name,client_phone,banner_id,starts_at,ends_at,amount_toman,notes,status,created_by)
                VALUES (:title,:client,:phone,:banner,:start,:end,:amount,:notes,'active',:user)",
                ['title'=>$title,'client'=>$client,'phone'=>$phone,'banner'=>$banner,'start'=>$start,'end'=>$end,'amount'=>$amount,'notes'=>$notes ?: null,'user'=>$userId]);
            $id = DB::lastInsertId();
            DB::query('INSERT INTO advertising_contract_requests (request_key,payload_hash,contract_id) VALUES (:key,:hash,:id)', ['key'=>$key,'hash'=>$hash,'id'=>$id]);
            $pdo->commit(); return ['id'=>$id, 'replayed'=>false];
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    public static function changeStatus(array $data, int $userId): void
    {
        $id = self::id($data['contract_id'] ?? null);
        $version = $data['version'] ?? null;
        $status = $data['status'] ?? null;
        $reason = self::text($data['reason'] ?? '', 8, 1000, 'دلیل تغییر وضعیت');
        if (!is_int($version) || $version < 1 || !is_string($status) || !in_array($status, ['active','completed','cancelled'], true) || ($data['confirmed'] ?? null) !== true) throw new InvalidArgumentException('تغییر وضعیت و تأیید آن معتبر نیست.');
        $pdo = DB::connection(); $pdo->beginTransaction();
        try {
            $c = self::lockContract($id);
            if ((int)$c['version'] !== $version) throw new DomainException('قرارداد تغییر کرده است؛ فهرست را تازه کنید.', 409);
            if ($c['status'] === $status) throw new DomainException('این وضعیت قبلاً ثبت شده است.', 409);
            if (self::hasUnresolved($id)) throw new DomainException('ابتدا لینک یا تراکنش آنلاین باز را تعیین تکلیف کنید.', 409);
            if ($status === 'completed' && self::paidAmount($id) < (int)$c['amount_toman']) throw new DomainException('مبلغ قرارداد هنوز کامل ثبت نشده است.', 409);
            DB::query('UPDATE advertising_contracts SET status=:status,version=version+1,updated_at=NOW() WHERE id=:id', ['status'=>$status,'id'=>$id]);
            DB::query('INSERT INTO advertising_contract_status_log (contract_id,old_status,new_status,reason,changed_by) VALUES (:id,:old,:new,:reason,:user)',
                ['id'=>$id,'old'=>$c['status'],'new'=>$status,'reason'=>$reason,'user'=>$userId]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    public static function recordManual(array $data, int $userId): array
    {
        $id = self::id($data['contract_id'] ?? null); $amount = self::amount($data['amount_toman'] ?? null);
        $key = self::requestKey($data['request_key'] ?? null);
        $method = $data['method'] ?? null;
        if (!is_string($method) || !in_array($method, ['bank_transfer','cash','other'], true) || ($data['confirmed'] ?? null) !== true) throw new InvalidArgumentException('روش پرداخت و تأیید مدیر الزامی است.');
        $reference = self::text($data['reference'] ?? '', 3, 120, 'شماره رسید یا مرجع');
        $notes = self::text($data['notes'] ?? '', 0, 1000, 'توضیح پرداخت');
        $pdo = DB::connection(); $pdo->beginTransaction();
        try {
            $c = self::lockContract($id);
            $existing = DB::fetchOne('SELECT id,contract_id,amount_toman,method,reference,notes,status FROM advertising_manual_payments WHERE request_key=:key', ['key'=>$key]);
            if ($existing) {
                if ((int)$existing['contract_id'] !== $id || (int)$existing['amount_toman'] !== $amount || $existing['method'] !== $method || $existing['reference'] !== $reference || (string)($existing['notes'] ?? '') !== $notes) throw new DomainException('این شناسه درخواست قبلاً برای پرداخت دیگری استفاده شده است.', 409);
                $pdo->commit(); return ['id'=>(int)$existing['id'], 'replayed'=>true, 'status'=>$existing['status']];
            }
            if ($c['status'] !== 'active') throw new DomainException('فقط برای قرارداد فعال می‌توان پرداخت ثبت کرد.', 409);
            if (self::hasUnresolved($id)) throw new DomainException('ابتدا لینک یا تراکنش آنلاین باز را تعیین تکلیف کنید.', 409);
            if ($amount > (int)$c['amount_toman'] - self::paidAmount($id)) throw new DomainException('مبلغ از مانده قرارداد بیشتر است؛ ثبت اضافه‌پرداخت در این بخش پشتیبانی نمی‌شود.', 409);
            DB::query("INSERT INTO advertising_manual_payments (contract_id,amount_toman,method,reference,notes,request_key,status,recorded_by)
                VALUES (:id,:amount,:method,:ref,:notes,:key,'recorded',:user)",
                ['id'=>$id,'amount'=>$amount,'method'=>$method,'ref'=>$reference,'notes'=>$notes ?: null,'key'=>$key,'user'=>$userId]);
            $paymentId = DB::lastInsertId();
            self::claimReceipt($reference, 'manual', $paymentId, $id);
            DB::query('UPDATE advertising_contracts SET version=version+1,updated_at=NOW() WHERE id=:id', ['id'=>$id]);
            $pdo->commit(); return ['id'=>$paymentId, 'replayed'=>false, 'status'=>'recorded'];
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    public static function voidManual(array $data, int $userId): void
    {
        $id = self::id($data['payment_id'] ?? null);
        $contractId = self::id($data['contract_id'] ?? null);
        $reason = self::text($data['reason'] ?? '', 8, 1000, 'دلیل ابطال');
        if (($data['confirmed'] ?? null) !== true) throw new InvalidArgumentException('ابطال ثبت دستی را تأیید کنید.');
        $pdo = DB::connection(); $pdo->beginTransaction();
        try {
            $c = self::lockContract($contractId);
            $p = DB::fetchOne('SELECT id,status FROM advertising_manual_payments WHERE id=:id AND contract_id=:contract FOR UPDATE', ['id'=>$id,'contract'=>$contractId]);
            if (!$p || $p['status'] !== 'recorded') throw new DomainException('پرداخت یافت نشد یا قبلاً ابطال شده است.', 409);
            if ($c['status'] === 'completed') throw new DomainException('ابتدا قرارداد تکمیل‌شده را با ثبت دلیل باز کنید.', 409);
            if (self::hasUnresolved($contractId)) throw new DomainException('ابتدا تراکنش آنلاین باز را تعیین تکلیف کنید.', 409);
            DB::query("UPDATE advertising_manual_payments SET status='voided',voided_by=:user,voided_at=NOW(),void_reason=:reason WHERE id=:id", ['user'=>$userId,'reason'=>$reason,'id'=>$id]);
            DB::query('UPDATE advertising_contracts SET version=version+1,updated_at=NOW() WHERE id=:id', ['id'=>$contractId]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
