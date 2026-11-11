<?php
declare(strict_types=1);

/**
 * اسکریپت CLI ورکر هوش مصنوعی بدون توهم (ai-worker/run.php - مرحله S5)
 * نحوه اجرا روی کامپیوتر مدیر:
 *   php ai-worker/run.php
 *
 * چرخه کاری:
 * ۱. دریافت تسک بعدی از GET /api/v1/ai/tasks/next
 * ۲. تولید متن معرفی کسب‌وکار یا پیش‌نویس مقاله صرفاً بر اساس فیلدهای موجود در input
 * ۳. بررسی گاردریل ضد توهم و ثبت فیلدهای استفاده‌شده (used_fields)
 * ۴. ارسال خروجی به POST /api/v1/ai/tasks/complete
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Services/AiContentService.php';

use App\Services\AiContentService;

$configFile = __DIR__ . '/config.php';
$config = is_file($configFile) ? require $configFile : require __DIR__ . '/config.sample.php';

$baseUrl = rtrim((string) ($config['server_base_url'] ?? 'https://hamihubx.ir'), '/');
$token = (string) ($config['bearer_token'] ?? '');
$connectTimeout = (int) ($config['connect_timeout'] ?? 5);
$requestTimeout = (int) ($config['request_timeout'] ?? 15);

echo "============================================================\n";
echo " HamiHubX Remote AI Worker (Stage S5 - Non-Hallucinating)\n";
echo " Target Server: {$baseUrl}\n";
echo "============================================================\n";

function hhxAiHttpJson(string $method, string $url, string $token, ?array $payload, int $connectTimeout, int $timeout): array
{
    $ch = curl_init($url);
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ];

    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json; charset=utf-8';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => 'خطای cURL: ' . $err, 'http_code' => $code];
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'پاسخ غیر JSON از سرور (کد ' . $code . ')', 'http_code' => $code];
    }

    return $decoded;
}

$nextRes = hhxAiHttpJson(
    'GET',
    $baseUrl . '/api/v1/ai/tasks/next',
    $token,
    null,
    $connectTimeout,
    $requestTimeout
);

if (empty($nextRes['ok'])) {
    echo "[!] خطا در دریافت تسک از سرور: " . ($nextRes['error'] ?? 'Unknown') . "\n";
    exit(1);
}

$task = $nextRes['task'] ?? null;
if (!is_array($task) || empty($task['id'])) {
    echo "[✓] هیچ تسکی در صف ai_tasks منتظر پردازش نیست.\n";
    exit(0);
}

$taskId = (int) $task['id'];
$type = (string) ($task['type'] ?? 'business_text');
$targetId = (int) ($task['target_id'] ?? 0);
$input = is_array($task['input'] ?? null) ? $task['input'] : [];

echo "[*] تسک #{$taskId} دریافت شد | نوع: {$type} | شناسه هدف: #{$targetId}\n";

$generated = AiContentService::generateFactGroundedOutput($type, $input);
$validation = AiContentService::validateNoHallucination($input, $generated['output']);

if (!$validation['valid']) {
    echo "[!] خطای گاردریل ضد توهم: {$validation['reason']}\n";
    exit(1);
}

$completeRes = hhxAiHttpJson(
    'POST',
    $baseUrl . '/api/v1/ai/tasks/complete',
    $token,
    [
        'task_id'     => $taskId,
        'output'      => $generated['output'],
        'used_fields' => $generated['used_fields'],
        'model'       => $generated['model'],
    ],
    $connectTimeout,
    $requestTimeout
);

if (!empty($completeRes['ok'])) {
    echo "[✓] تسک #{$taskId} با موفقیت ثبت و اعمال شد. فیلدهای استفاده‌شده: " . implode(', ', $generated['used_fields']) . "\n";
    exit(0);
}

echo "[!] خطا در ثبت نتیجه تسک #{$taskId}: " . ($completeRes['error'] ?? 'Unknown') . "\n";
exit(1);
