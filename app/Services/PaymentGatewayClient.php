<?php
declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

/** Fixed endpoints and strict response checks; injectable sender exists for synthetic tests. */
final class PaymentGatewayClient
{
    /** @var callable(array):array */
    private $sender;
    public function __construct(?callable $sender = null) { $this->sender = $sender ?? [self::class, 'postJson']; }

    public static function validateAmount(string $provider, int $toman): void
    {
        if ($provider === 'aqayepardakht') { $min = 1000; $max = 400000000; }
        elseif ($provider === 'idpay') { $min = 100; $max = 50000000; }
        elseif ($provider === 'zibal') { $min = 1000; $max = 400000000; } // Conservative; merchant limits may be lower.
        else throw new InvalidArgumentException('درگاه معتبر نیست.');
        if (PHP_INT_SIZE < 8 || $toman < $min || $toman > $max) throw new InvalidArgumentException('مبلغ با محدوده مجاز این درگاه سازگار نیست.');
    }

    private static function validOrder(string $order): bool { return (bool)preg_match('/\A[A-Za-z0-9_-]{1,48}\z/', $order); }
    private static function scalarString(mixed $value): string
    {
        return is_int($value) || is_string($value) ? (string)$value : '';
    }
    private static function validCallback(string $callback): bool
    {
        $p = parse_url($callback);
        return is_array($p) && ($p['scheme'] ?? '') === 'https' && !empty($p['host']) && !isset($p['user']) && !isset($p['pass'])
            && !isset($p['fragment']) && strlen($callback) <= 1024 && !preg_match('/[\x00-\x20\x7f]/', $callback);
    }

    public static function createSpec(string $provider, string $mode, string $secret, int $toman, string $order, string $callback): array
    {
        self::validateAmount($provider, $toman);
        if (!in_array($mode, ['sandbox','live'], true) || !self::validOrder($order) || !self::validCallback($callback)) throw new InvalidArgumentException('تنظیمات درخواست پرداخت معتبر نیست.');
        PaymentGatewaySettings::validateModeCredential($provider, $mode, $secret);
        if ($provider === 'zibal') return ['url'=>'https://gateway.zibal.ir/v1/request', 'headers'=>[], 'body'=>[
            'merchant'=>$secret,'amount'=>$toman*10,'callbackUrl'=>$callback,'orderId'=>$order,'description'=>'Advertising contract payment']];
        if ($provider === 'aqayepardakht') return ['url'=>'https://panel.aqayepardakht.ir/api/v2/create', 'headers'=>[], 'body'=>[
            'pin'=>$secret,'amount'=>$toman,'callback'=>$callback,'callback_method'=>'GET','invoice_id'=>$order,'description'=>'Advertising contract payment']];
        return ['url'=>'https://api.idpay.ir/v1.1/payment', 'headers'=>['X-API-KEY: ' . $secret, 'X-SANDBOX: ' . ($mode === 'sandbox' ? '1' : '0')], 'body'=>[
            'order_id'=>$order,'amount'=>$toman*10,'callback'=>$callback,'desc'=>'Advertising contract payment']];
    }

    public static function validProviderId(string $provider, string $id): bool
    {
        if ($provider === 'zibal') return (bool)preg_match('/\A[1-9][0-9]{0,17}\z/', $id);
        if ($provider === 'aqayepardakht') return (bool)preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $id);
        if ($provider === 'idpay') return (bool)preg_match('/\A[a-f0-9]{32}\z/', $id);
        return false;
    }

    public static function startUrl(string $provider, string $mode, string $id): string
    {
        if (!in_array($mode, ['sandbox','live'], true) || !self::validProviderId($provider, $id)) throw new RuntimeException('شناسه تراکنش معتبر نیست.');
        if ($provider === 'zibal') return 'https://gateway.zibal.ir/start/' . $id;
        if ($provider === 'aqayepardakht') return 'https://panel.aqayepardakht.ir/startpay/' . ($mode === 'sandbox' ? 'sandbox/' : '') . $id;
        return 'https://idpay.ir/p/' . ($mode === 'sandbox' ? 'ws-sandbox/' : 'ws/') . $id;
    }

    /** Do not accept an arbitrary redirect URL, even if a gateway response is compromised. */
    public static function parseCreated(string $provider, string $mode, array $response): array
    {
        $body = $response['body'] ?? null; $http = $response['http'] ?? 0;
        if (!is_array($body) || !is_int($http)) throw new RuntimeException('پاسخ درگاه قابل تأیید نیست.');
        if ($provider === 'zibal' && $http === 200 && self::scalarString($body['result'] ?? null) === '100') $id = self::scalarString($body['trackId'] ?? null);
        elseif ($provider === 'aqayepardakht' && $http === 200 && ($body['status'] ?? '') === 'success') $id = is_string($body['transid'] ?? null) ? $body['transid'] : '';
        elseif ($provider === 'idpay' && $http === 201) $id = is_string($body['id'] ?? null) ? $body['id'] : '';
        else throw new RuntimeException('درگاه ایجاد تراکنش را تأیید نکرد.');
        $url = self::startUrl($provider, $mode, $id);
        if ($provider === 'idpay' && (!is_string($body['link'] ?? null) || !hash_equals($url, $body['link']))) throw new RuntimeException('نشانی بازگشتی درگاه معتبر نیست.');
        return ['provider_id'=>$id, 'checkout_url'=>$url];
    }

    public static function verifySpec(string $provider, string $mode, string $secret, int $toman, string $order, string $id): array
    {
        self::validateAmount($provider, $toman);
        if (!in_array($mode, ['sandbox','live'], true) || !self::validOrder($order) || !self::validProviderId($provider, $id)) throw new InvalidArgumentException('شناسه تأیید معتبر نیست.');
        PaymentGatewaySettings::validateModeCredential($provider, $mode, $secret);
        if ($provider === 'zibal') return ['url'=>'https://gateway.zibal.ir/v1/verify', 'headers'=>[], 'body'=>['merchant'=>$secret,'trackId'=>(int)$id]];
        if ($provider === 'aqayepardakht') return ['url'=>'https://panel.aqayepardakht.ir/api/v2/verify', 'headers'=>[], 'body'=>['pin'=>$secret,'amount'=>$toman,'transid'=>$id]];
        return ['url'=>'https://api.idpay.ir/v1.1/payment/verify', 'headers'=>['X-API-KEY: ' . $secret, 'X-SANDBOX: ' . ($mode === 'sandbox' ? '1' : '0')], 'body'=>['id'=>$id,'order_id'=>$order]];
    }

    private static function integerEquals(mixed $value, int $expected): bool
    {
        return (is_int($value) || (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/', $value))) && (string)$value === (string)$expected;
    }
    private static function reference(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) return '';
        $ref = (string)$value;
        return preg_match('/\A[A-Za-z0-9_-]{1,120}\z/', $ref) ? $ref : '';
    }

    /** Fail closed on duplicates/unknowns; already-verified responses require human reconciliation. */
    public static function parseVerified(string $provider, int $toman, string $order, string $id, array $response): array
    {
        $body = $response['body'] ?? null; $http = $response['http'] ?? 0;
        if (!is_array($body) || !is_int($http)) return ['verified'=>false,'error'=>'invalid_response'];
        if ($provider === 'zibal') {
            if (self::scalarString($body['result'] ?? null) === '201') return ['verified'=>false,'error'=>'already_verified'];
            if ($http !== 200 || self::scalarString($body['result'] ?? null) !== '100' || self::scalarString($body['status'] ?? null) !== '1') return ['verified'=>false,'error'=>'unconfirmed'];
            if (!self::integerEquals($body['amount'] ?? null, $toman*10) || self::scalarString($body['orderId'] ?? null) !== $order) return ['verified'=>false,'error'=>'amount_or_order_mismatch'];
            $ref = self::reference($body['refNumber'] ?? null);
            return $ref !== '' ? ['verified'=>true,'reference'=>$ref] : ['verified'=>false,'error'=>'missing_reference'];
        }
        if ($provider === 'aqayepardakht') {
            if (self::scalarString($body['code'] ?? null) === '2') return ['verified'=>false,'error'=>'already_verified'];
            if ($http === 200 && ($body['status'] ?? '') === 'success' && self::scalarString($body['code'] ?? null) === '1') return ['verified'=>true,'reference'=>$id];
            return ['verified'=>false,'error'=>'unconfirmed'];
        }
        if ($provider === 'idpay') {
            if (self::scalarString($body['status'] ?? null) === '101') return ['verified'=>false,'error'=>'already_verified'];
            if ($http !== 200 || self::scalarString($body['status'] ?? null) !== '100') return ['verified'=>false,'error'=>'unconfirmed'];
            if (self::scalarString($body['id'] ?? null) !== $id || self::scalarString($body['order_id'] ?? null) !== $order || !self::integerEquals($body['amount'] ?? null, $toman*10)) return ['verified'=>false,'error'=>'amount_or_order_mismatch'];
            $ref = self::reference($body['track_id'] ?? null);
            return $ref !== '' ? ['verified'=>true,'reference'=>$ref] : ['verified'=>false,'error'=>'missing_reference'];
        }
        return ['verified'=>false,'error'=>'invalid_provider'];
    }

    public function create(string $provider, string $mode, string $secret, int $toman, string $order, string $callback): array
    {
        $spec = self::createSpec($provider, $mode, $secret, $toman, $order, $callback);
        return self::parseCreated($provider, $mode, ($this->sender)($spec));
    }
    public function verify(string $provider, string $mode, string $secret, int $toman, string $order, string $id): array
    {
        $spec = self::verifySpec($provider, $mode, $secret, $toman, $order, $id);
        return self::parseVerified($provider, $toman, $order, $id, ($this->sender)($spec));
    }

    /** Production transport: fixed HTTPS URLs, no redirects, short timeouts, bounded response, no raw logging. */
    private static function postJson(array $spec): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('امکان اتصال امن به درگاه فراهم نیست.');
        $payload = json_encode($spec['body'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response = ''; $ch = curl_init((string)$spec['url']);
        if ($ch === false) throw new RuntimeException('امکان اتصال امن به درگاه فراهم نیست.');
        curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload, CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json', 'Accept: application/json'], $spec['headers']),
            CURLOPT_RETURNTRANSFER=>false, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>7, CURLOPT_TIMEOUT=>15,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function ($curl, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 65536) return 0;
                $response .= $chunk; return strlen($chunk);
            }]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        $ok = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($ok !== true) throw new RuntimeException('پاسخ درگاه دریافت نشد.');
        $body = json_decode($response, true, 64, JSON_BIGINT_AS_STRING);
        if (!is_array($body)) throw new RuntimeException('پاسخ درگاه قابل خواندن نیست.');
        return ['http'=>$http, 'body'=>$body];
    }
}
