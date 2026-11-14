<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * A deliberately small, server-side provider boundary. Adding a provider means
 * adding an adapter and registering it here; request bodies can never choose a URL.
 * No prompt, credential, or provider response is written to the database/logs.
 * S9: only status codes and fixed Persian guidance are recorded (AiServiceLogger);
 * S9: admins may override each provider's endpoint base URL (panel setting, https-only)
 * so blocked regions can route calls through their own Cloudflare Worker or proxy.
 */
interface ArticleTextProvider
{
    /** @return array{url:string,headers:string[],body:array} */
    public function request(string $model, string $apiKey, string $prompt): array;
    public function text(array $response): string;
}

final class GeminiArticleProvider implements ArticleTextProvider
{
    public function __construct(private readonly string $base = 'https://generativelanguage.googleapis.com/v1beta') {}

    public function request(string $model, string $apiKey, string $prompt): array
    {
        return [
            'url'=>$this->base . '/models/' . rawurlencode($model) . ':generateContent',
            'headers'=>['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
            // Newer Gemini models ignore (and future ones may reject) sampling parameters.
            'body'=>['contents'=>[['role'=>'user','parts'=>[['text'=>$prompt]]]],
                'generationConfig'=>['maxOutputTokens'=>2048]],
        ];
    }
    public function text(array $response): string
    {
        $parts = $response['candidates'][0]['content']['parts'] ?? [];
        $text = '';
        if (is_array($parts)) foreach ($parts as $part) {
            if (is_array($part) && ($part['thought'] ?? false) !== true && isset($part['text']) && is_string($part['text'])) $text .= $part['text'];
        }
        return $text;
    }
}

final class ChatArticleProvider implements ArticleTextProvider
{
    /** All options and the destination come from the server's fixed registry, never the browser. */
    public function __construct(
        private readonly string $url,
        private readonly string $limitField = 'max_tokens',
        private readonly array $modelOptions = []
    ) {}
    public function request(string $model, string $apiKey, string $prompt): array
    {
        $body = [
            'model'=>$model, 'messages'=>[['role'=>'user','content'=>$prompt]],
            'temperature'=>0.4, $this->limitField=>2048,
            // Some providers default to SSE; the response parser expects one JSON object.
            'stream'=>false,
        ];
        // Apply documented options only to the exact model they are known to support.
        foreach ($this->modelOptions[$model] ?? [] as $name=>$value) $body[$name] = $value;
        return [
            'url'=>$this->url,
            'headers'=>['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            'body'=>$body,
        ];
    }
    public function text(array $response): string
    {
        $text = $response['choices'][0]['message']['content'] ?? '';
        return is_string($text) ? $text : '';
    }
}

final class AiArticleService
{
    /**
     * Provider destinations and model-specific options are code-owned. Suggestions
     * are not an allowlist: admins can enter another supported model ID. Add a
     * compatible provider here without duplicating its catalog in the frontend.
     */
    private const REGISTRY = [
        'gemini'=>[
            'label'=>'Gemini', 'default_model'=>'gemini-3.5-flash-lite', 'protocol'=>'gemini',
            'suggested_models'=>[['id'=>'gemini-3.5-flash-lite', 'note'=>'پیش‌فرض']],
        ],
        'groq'=>[
            'label'=>'Groq', 'default_model'=>'openai/gpt-oss-120b', 'protocol'=>'chat',
            'url'=>'https://api.groq.com/openai/v1/chat/completions', 'limit_field'=>'max_completion_tokens',
            'suggested_models'=>[
                ['id'=>'openai/gpt-oss-120b', 'note'=>'پیش‌فرض'],
                ['id'=>'qwen/qwen3.8-27b', 'note'=>'آزمایشی؛ ممکن است تغییر کند'],
            ],
            'model_options'=>[
                // GPT-OSS always reasons; low effort and a short prompt help the bounded test return a final answer.
                'openai/gpt-oss-120b'=>['temperature'=>0.6, 'reasoning_effort'=>'low', 'include_reasoning'=>false],
                // For article writing, use Qwen's documented non-thinking mode.
                'qwen/qwen3.8-27b'=>['temperature'=>0.7, 'reasoning_effort'=>'none', 'reasoning_format'=>'hidden'],
            ],
        ],
        'openrouter'=>[
            'label'=>'OpenRouter', 'default_model'=>'openrouter/free', 'protocol'=>'chat',
            'url'=>'https://openrouter.ai/api/v1/chat/completions',
            'suggested_models'=>[['id'=>'openrouter/free', 'note'=>'پیش‌فرض']],
        ],
        'nvidia'=>[
            'label'=>'NVIDIA', 'default_model'=>'nvidia/nemotron-3.5-lightning', 'protocol'=>'chat',
            'url'=>'https://integrate.api.nvidia.com/v1/chat/completions',
            'suggested_models'=>[['id'=>'nvidia/nemotron-3.5-lightning', 'note'=>'پیش‌فرض']],
            'model_options'=>[
                // This model otherwise spends the shared output budget on thinking before answering.
                'nvidia/nemotron-3.5-lightning'=>[
                    'temperature'=>1.0, 'chat_template_kwargs'=>['enable_thinking'=>false],
                ],
            ],
        ],
    ];

    /** @var array<string, array{label:string,adapter:ArticleTextProvider}> */
    private array $providers;

    /** Only non-secret model metadata is exposed to the admin settings UI. */
    public static function catalog(): array
    {
        $catalog = [];
        foreach (self::REGISTRY as $id=>$provider) {
            $catalog[$id] = [
                'label'=>$provider['label'], 'default_model'=>$provider['default_model'],
                'suggested_models'=>$provider['suggested_models'],
            ];
        }
        return $catalog;
    }

    public function __construct(private readonly array $config)
    {
        $this->providers = [];
        foreach (self::REGISTRY as $id=>$provider) {
            // S9: نشانی جایگزین فقط از تنظیمات امن سرور می‌آید (اعتبارسنجی https)؛
            // برای پروتکل chat، نشانی کامل endpoint جایگزین می‌شود؛ برای gemini، پایه‌ای که
            // /models/{model}:generateContent به آن اضافه می‌شود.
            $override = $this->config['providers'][$id]['base_url'] ?? '';
            $base = is_string($override) && self::validBaseUrl($override) ? rtrim($override, '/') : '';
            $adapter = match ($provider['protocol']) {
                'gemini' => new GeminiArticleProvider($base !== '' ? $base : 'https://generativelanguage.googleapis.com/v1beta'),
                'chat' => new ChatArticleProvider($base !== '' ? $base : $provider['url'], $provider['limit_field'] ?? 'max_tokens', $provider['model_options'] ?? []),
                default => throw new RuntimeException('پروتکل سرویس هوش مصنوعی معتبر نیست.'),
            };
            $this->providers[$id] = ['label'=>$provider['label'], 'adapter'=>$adapter];
        }
    }

    /** S9: اعتبارسنجی نشانی جایگزین — فقط https، بدون query/fragment، حداکثر ۳۰۰ نویسه. */
    public static function validBaseUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 300) return false;
        $parts = parse_url($url);
        if (!is_array($parts)) return false;
        if (strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) return false;
        if (($parts['query'] ?? '') !== '' || ($parts['fragment'] ?? '') !== '') return false;
        return (bool)preg_match('#^https://[A-Za-z0-9._~%-]+(?::[0-9]{1,5})?(?:/[^\s<>"\']*)?$#', $url);
    }

    public function status(): array
    {
        $out = [];
        foreach ($this->providers as $id=>$provider) {
            $c = $this->config['providers'][$id] ?? [];
            if (!is_array($c)) $c = [];
            $model = is_string($c['model'] ?? null) ? trim($c['model']) : '';
            $validModel = $this->validModel($id, $model);
            $key = is_string($c['api_key'] ?? null) ? trim($c['api_key']) : '';
            $ready = ($c['enabled'] ?? false) === true && ($c['free_tier_confirmed'] ?? false) === true
                && $key !== '' && !preg_match('/[\x00-\x20\x7f]/', $key) && $validModel;
            $out[] = ['id'=>$id, 'label'=>$provider['label'], 'model'=>$model, 'available'=>$ready];
        }
        return $out;
    }

    public static function validModel(string $id, string $model): bool
    {
        if (!isset(self::catalog()[$id]) || $model === '' || strlen($model) > 120) return false;
        if ($id === 'gemini') return (bool)preg_match('/\A[a-zA-Z0-9._-]+\z/', $model);
        if (!preg_match('/\A[a-zA-Z0-9._:\/-]+\z/', $model)) return false;
        // OpenRouter has paid models too. This integration will not use one.
        return $id !== 'openrouter' || $model === 'openrouter/free' || str_ends_with($model, ':free');
    }

    /** Prepare an explicit request after input/provider validation; no network call occurs here. */
    public function prepare(string $id, string $title, string $brief, string $facts): array
    {
        $configured = null;
        foreach ($this->status() as $status) if ($status['id'] === $id && $status['available']) $configured = $status;
        if ($configured === null) throw new RuntimeException('این سرویس فعال یا آماده نیست؛ تنظیمات خصوصی و سهمیه رایگان آن را بررسی کنید.');
        $title = trim($title); $brief = trim($brief); $facts = trim($facts);
        if ($title === '' || mb_strlen($title) > 255 || mb_strlen($brief) > 2500 || mb_strlen($facts) > 8000) {
            throw new RuntimeException('عنوان لازم است؛ طول عنوان، درخواست یا اطلاعات منبع بیش از حد مجاز است.');
        }
        $prompt = "برای یک مقاله فارسی پیش‌نویس بنویس. از متن ساده و در صورت نیاز تیترهای ## استفاده کن؛ HTML ننویس. "
            . "فقط از اطلاعات تأییدشده‌ای که در پایین آمده برای ادعاهای مشخص درباره کسب‌وکارها استفاده کن. "
            . "نام، نشانی، تلفن، قیمت، ساعت، رتبه، ویژگی یا منبعی را حدس نزن و نساز. "
            . "اگر اطلاعات کافی نیست توضیح عمومی بنویس و نیاز به بررسی را مشخص کن. خروجی باید قبل از انتشار توسط انسان بررسی شود.\n\n"
            . "عنوان: {$title}\nراهنمای نویسنده: {$brief}\nاطلاعاتی که نویسنده ارائه کرده: {$facts}";
        $c = $this->config['providers'][$id];
        $adapter = $this->providers[$id]['adapter'];
        return ['id'=>$id, 'label'=>$configured['label'], 'model'=>$configured['model'], 'adapter'=>$adapter,
            'request'=>$adapter->request($configured['model'], trim((string)$c['api_key']), $prompt)];
    }

    /** A small explicit real generation verifies the configured model, not just DNS. */
    public function prepareConnectionTest(string $id): array
    {
        $prepared = $this->prepare($id, 'آزمایش اتصال', 'فقط واژه سلام را بنویس.', '');
        if ($id === 'gemini') {
            // Some models use output tokens for thinking, even for short tests.
            $prepared['request']['body']['contents'] = [['role'=>'user', 'parts'=>[['text'=>'Reply with OK.']]]];
            $prepared['request']['body']['generationConfig']['maxOutputTokens'] = 256;
        } else {
            $prepared['request']['body']['messages'] = [['role'=>'user', 'content'=>'Reply with OK.']];
            // Groq documents max_completion_tokens; other chat adapters use max_tokens.
            $field = array_key_exists('max_completion_tokens', $prepared['request']['body']) ? 'max_completion_tokens' : 'max_tokens';
            $prepared['request']['body'][$field] = 256;
        }
        return $prepared;
    }

    /** @param callable|null $transport Test seam; production always uses the fixed HTTPS transport. */
    public function send(array $prepared, ?callable $transport = null): string
    {
        $providerId = (string)($prepared['id'] ?? '');
        $model = (string)($prepared['model'] ?? '');
        $log = static fn(bool $ok, ?int $status, string $msg) => AiServiceLogger::log($providerId, $model, $ok, $status, $msg);
        try {
            $request = $prepared['request'];
            $result = $transport ? $transport($request) : self::postJson($request);
        } catch (RuntimeException $e) {
            // S9: خطای ارتباطی (شبکه/زمان) با کد وضعیت NULL ثبت می‌شود — دقیقاً همان مورد ۴۰۳/تحریم.
            $log(false, null, 'ارتباط با سرویس ناموفق بود؛ مسیر خروجی، فیلتر منطقه یا شبکه سرور را بررسی کنید.');
            throw $e;
        }
        if (!is_array($result) || !isset($result['status'], $result['body'])) {
            $log(false, null, 'پاسخ سرویس هوش مصنوعی نامعتبر بود.');
            throw new RuntimeException('پاسخ سرویس هوش مصنوعی نامعتبر بود.');
        }
        $status = (int)$result['status'];
        if ($status < 200 || $status >= 300) {
            $message = self::providerError((string)($prepared['id'] ?? ''), $status, (string)$result['body']);
            $log(false, $status, $message);
            throw new RuntimeException($message);
        }
        if ($status === 202) {
            // This integration performs one synchronous request, not provider-specific polling.
            $message = 'سرویس درخواست را در صف قرار داد (HTTP 202)، اما پاسخ نهایی در این درخواست دریافت نشد.';
            $log(false, $status, $message);
            throw new RuntimeException($message);
        }
        $decoded = json_decode((string)$result['body'], true);
        if (!is_array($decoded)) {
            $log(false, $status, 'پاسخ سرویس هوش مصنوعی قابل خواندن نبود.');
            throw new RuntimeException('پاسخ سرویس هوش مصنوعی قابل خواندن نبود.');
        }
        $text = trim($prepared['adapter']->text($decoded));
        if ($text === '' && ($prepared['id'] ?? '') === 'gemini' && ($decoded['candidates'][0]['finishReason'] ?? '') === 'MAX_TOKENS') {
            $message = 'سرویس پاسخ داد، اما خروجی پیش از تولید متن به سقف توکن رسید (MAX_TOKENS).';
            $log(false, $status, $message);
            throw new RuntimeException($message);
        }
        if ($text === '' && ($decoded['choices'][0]['finish_reason'] ?? '') === 'length') {
            $message = 'سرویس پاسخ داد، اما پیش از تولید متن نهایی به سقف توکن رسید (length). مدل یا تنظیمات آن را بررسی کنید.';
            $log(false, $status, $message);
            throw new RuntimeException($message);
        }
        if ($text === '') {
            $message = 'سرویس متن قابل استفاده‌ای برنگرداند؛ ممکن است خروجی مسدود شده باشد.';
            $log(false, $status, $message);
            throw new RuntimeException($message);
        }
        $log(true, $status, 'اتصال موفق بود.');
        return mb_substr($text, 0, 18000);
    }

    /** Display only a status and fixed guidance; provider bodies may contain credentials or prompts. */
    private static function providerError(string $provider, int $status, string $body): string
    {
        $hint = '';
        if ($provider === 'gemini' && $status === 400) {
            $data = json_decode($body, true);
            $details = is_array($data) && is_array($data['error']['details'] ?? null) ? $data['error']['details'] : [];
            foreach ($details as $detail) {
                if (is_array($detail) && ($detail['reason'] ?? '') === 'API_KEY_INVALID') {
                    $hint = 'کلید ارسال‌شده توسط Google معتبر شناخته نشد؛ کلید و پروژه را در پنل بررسی کنید.';
                    break;
                }
            }
            if ($hint === '' && is_array($data) && ($data['error']['status'] ?? '') === 'FAILED_PRECONDITION') {
                $hint = 'پیش‌شرط حساب، پروژه یا منطقه برقرار نیست؛ وضعیت دسترسی و طرح را در Google AI Studio بررسی کنید.';
            }
        }
        if ($hint === '') {
            $hint = match ($status) {
                400 => 'درخواست پذیرفته نشد؛ شناسه دقیق مدل، تنظیمات پروژه و کلید را بررسی کنید.',
                401, 403 => 'سرویس اجازه دسترسی نداد؛ کلید، محدودیت‌های آن، منطقه و تنظیمات حساب را بررسی کنید.',
                402 => 'سرویس وضعیت پرداخت یا اعتبار حساب را رد کرد؛ طرح و موجودی حساب را بررسی کنید.',
                404 => 'مدل یا نشانی آن برای این حساب پیدا نشد؛ نام دقیق مدل و دسترسی پروژه را بررسی کنید.',
                410 => 'مدل یا نشانی دیگر در دسترس نیست؛ شناسه به‌روز را در پنل ارائه‌دهنده بررسی کنید.',
                422 => 'سرویس درخواست را نپذیرفت؛ سازگاری مدل و پارامترهای آن را بررسی کنید.',
                429 => 'سهمیه یا محدودیت این سرویس تمام شده است؛ سهمیه را بررسی کنید یا بعداً تلاش کنید.',
                500, 502, 503, 504 => 'سرویس یا مسیر ارتباطی موقتاً خطا داد؛ کمی بعد دوباره بررسی کنید.',
                default => 'سرویس پاسخ موفق نداد؛ تنظیمات مدل، حساب و وضعیت سرویس را بررسی کنید.',
            };
        }
        $code = $status >= 100 && $status <= 599 ? " (HTTP {$status})" : '';
        return 'پاسخ سرویس' . $code . ': ' . $hint;
    }

    private static function postJson(array $request): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('افزونه cURL روی هاست فعال نیست.');
        $ch = curl_init($request['url']);
        if ($ch === false) throw new RuntimeException('اتصال به سرویس هوش مصنوعی آماده نشد.');
        $response = ''; $tooLarge = false;
        curl_setopt_array($ch, [
            CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>$request['headers'],
            CURLOPT_POSTFIELDS=>json_encode($request['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>45,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function ($curl, string $chunk) use (&$response, &$tooLarge): int {
                if (strlen($response) + strlen($chunk) > 1048576) { $tooLarge = true; return 0; }
                $response .= $chunk; return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        // Do not expose cURL errors: they can contain URLs or other sensitive details.
        if ($ok === false || $tooLarge) throw new RuntimeException('ارتباط با سرویس ناموفق یا زمان آن تمام شد؛ وضعیت شبکه را بررسی کنید.');
        return ['status'=>$code, 'body'=>$response];
    }
}
