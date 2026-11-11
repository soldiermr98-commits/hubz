<?php
declare(strict_types=1);

// Test-only fixed fake transport. This file is never included in a release bundle.
// The original fixture router below guards CLI-server, nonce, and a local hhx_test_* DB.
namespace App\Services {
    function curl_init(string $url): object|false {
        if (!str_starts_with($url, 'https://generativelanguage.googleapis.com/')
            && $url !== 'https://api.groq.com/openai/v1/chat/completions'
            && $url !== 'https://openrouter.ai/api/v1/chat/completions'
            && $url !== 'https://integrate.api.nvidia.com/v1/chat/completions') return false;
        return (object)['url'=>$url, 'options'=>[], 'status'=>0];
    }
    function curl_setopt_array(object $curl, array $options): bool { $curl->options=$options; return true; }
    function curl_exec(object $curl): bool {
        $headers=$curl->options[CURLOPT_HTTPHEADER] ?? [];
        $body=json_decode((string)($curl->options[CURLOPT_POSTFIELDS] ?? ''), true);
        $gemini=str_starts_with($curl->url, 'https://generativelanguage.googleapis.com/');
        $groq=$curl->url==='https://api.groq.com/openai/v1/chat/completions';
        $nvidia=$curl->url==='https://integrate.api.nvidia.com/v1/chat/completions';
        $model=$body['model'] ?? '';
        $expected=$gemini?'x-goog-api-key: synthetic-replacement-key':
            ($groq?'Authorization: Bearer synthetic-file-only-key':
            ($nvidia?($model==='vendor/custom-nvidia-model'?'Authorization: Bearer synthetic-nvidia-replacement':'Authorization: Bearer synthetic-nvidia-key'):'Authorization: Bearer synthetic-router-key'));
        $limit=$gemini?($body['generationConfig']['maxOutputTokens'] ?? null):($groq?($body['max_completion_tokens'] ?? null):($body['max_tokens'] ?? null));
        $shortPrompt=$limit !== 256 || ($gemini ? ($body['contents'][0]['parts'][0]['text'] ?? '') : ($body['messages'][0]['content'] ?? '')) === 'Reply with OK.';
        $noTemperature=!$gemini || !array_key_exists('temperature', $body['generationConfig'] ?? []);
        $stream=$gemini || ($body['stream'] ?? null) === false;
        $validModel=$gemini || ($groq && in_array($model,['openai/gpt-oss-120b','qwen/qwen3.8-27b'],true))
            || ($nvidia && in_array($model,['nvidia/nemotron-3.5-lightning-30b-a3b','vendor/custom-nvidia-model'],true))
            || (!$groq && !$nvidia && $model==='openrouter/free');
        $options=true;
        if ($groq && $model==='openai/gpt-oss-120b') $options=($body['reasoning_effort'] ?? '')==='low' && ($body['include_reasoning'] ?? null)===false && !isset($body['reasoning_format']);
        if ($groq && $model==='qwen/qwen3.8-27b') $options=($body['reasoning_effort'] ?? '')==='none' && ($body['reasoning_format'] ?? '')==='hidden' && !isset($body['include_reasoning']);
        if ($nvidia && $model==='nvidia/nemotron-3.5-lightning-30b-a3b') $options=($body['chat_template_kwargs']['enable_thinking'] ?? true)===false;
        if ($nvidia && $model==='vendor/custom-nvidia-model') $options=!isset($body['chat_template_kwargs']);
        $curl->status=in_array($expected,$headers,true) && in_array($limit,[256,2048],true) && $shortPrompt && $noTemperature && $stream && $validModel && $options ? 200 : 401;
        if ($curl->status===200 && str_contains($curl->url, '/gemini-fixture-error404:')) {
            $curl->status=404;
            $response=['error'=>['status'=>'NOT_FOUND','message'=>'synthetic-provider-secret-must-not-leak']];
        } else {
            $response=$gemini?['candidates'=>[['content'=>['parts'=>[['text'=>'synthetic-test-response']]]]]]:['choices'=>[['message'=>['content'=>'synthetic-test-response']]]];
        }
        $callback=$curl->options[CURLOPT_WRITEFUNCTION] ?? null;
        if (!is_callable($callback)) return false;
        $chunk=json_encode($response);
        return is_string($chunk) && $callback($curl,$chunk) === strlen($chunk);
    }
    function curl_getinfo(object $curl, int $option): int { return $curl->status; }
}
namespace {
    // The preview environment can proxy a non-loopback address back to 127.0.0.1.
    // Simulate an untrusted peer only for the nonce-protected disposable fixture.
    $nonce = getenv('HHX_TEST_NONCE') ?: '';
    $simulatedPeer = (string)($_SERVER['HTTP_X_HHX_TEST_REMOTE_NONCE'] ?? '');
    if (PHP_SAPI === 'cli-server' && getenv('HHX_ALLOW_TEST_FIXTURES') === '1'
        && strlen($nonce) >= 16 && $simulatedPeer !== '' && hash_equals($nonce, $simulatedPeer)) {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.17'; // RFC 5737 documentation address, not a real connection.
    }
    define('HHX_TEST_FAKE_AI_TRANSPORT', true);
    require __DIR__ . '/article_fixture_router.php';
}
