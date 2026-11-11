<?php
declare(strict_types=1);

// Pure synthetic adapter and validation checks; never calls a real provider or database.
require dirname(__DIR__) . '/app/Services/AiArticleService.php';
require dirname(__DIR__) . '/app/Services/AdvertisingService.php';
use App\Services\AiArticleService;
use App\Services\AdvertisingService;
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function rejects(callable $f, string $message): void { try { $f(); } catch (RuntimeException $e) { return; } throw new RuntimeException($message); }
$config = ['providers'=>[
    'gemini'=>['enabled'=>true,'free_tier_confirmed'=>true,'api_key'=>'test-gemini-secret','model'=>'gemini-test-model'],
    'groq'=>['enabled'=>true,'free_tier_confirmed'=>true,'api_key'=>'test-groq-secret','model'=>'test/model'],
    'openrouter'=>['enabled'=>true,'free_tier_confirmed'=>true,'api_key'=>'test-router-secret','model'=>'openrouter/free'],
    'nvidia'=>['enabled'=>true,'free_tier_confirmed'=>true,'api_key'=>'test-nvidia-secret','model'=>'custom/model'],
]];
$service = new AiArticleService($config);
$status = $service->status();
check(array_column($status, 'id') === ['gemini','groq','openrouter','nvidia'], 'Provider order wrong');
check(count(array_filter($status, fn($p)=>$p['available'])) === 4, 'Ready providers missing');
check(!str_contains(json_encode($status), 'secret'), 'Provider status leaks keys');
$requests = [];
$fake = function (array $request) use (&$requests): array {
    $requests[] = $request;
    if (str_contains($request['url'], 'generativelanguage.googleapis.com')) return ['status'=>200,'body'=>json_encode(['candidates'=>[['content'=>['parts'=>[['text'=>'پیشنهاد جمنای']]]]]], JSON_UNESCAPED_UNICODE)];
    return ['status'=>200,'body'=>json_encode(['choices'=>[['message'=>['content'=>'پیشنهاد مدل']]]], JSON_UNESCAPED_UNICODE)];
};
foreach (['gemini','groq','openrouter','nvidia'] as $id) {
    $prepared = $service->prepare($id, 'عنوان تست', 'راهنمای تست', 'واقعیت تأییدشده');
    $text = $service->send($prepared, $fake);
    check($text !== '', 'Adapter failed to parse response');
}
check(count($requests) === 4, 'More than one call per explicit request');
check($requests[0]['url'] === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test-model:generateContent', 'Gemini URL incorrect');
check($requests[1]['url'] === 'https://api.groq.com/openai/v1/chat/completions', 'Groq URL incorrect');
check($requests[2]['url'] === 'https://openrouter.ai/api/v1/chat/completions', 'OpenRouter URL incorrect');
check($requests[3]['url'] === 'https://integrate.api.nvidia.com/v1/chat/completions', 'NVIDIA URL incorrect');
check(in_array('Authorization: Bearer test-nvidia-secret', $requests[3]['headers'], true), 'NVIDIA key should be in header');
check($requests[1]['body']['max_completion_tokens'] === 2048 && !isset($requests[1]['body']['max_tokens']), 'Groq token field incorrect');
foreach (array_slice($requests,1) as $request) check($request['body']['stream'] === false, 'Chat responses must not default to SSE');
check(!str_contains($requests[0]['url'], 'secret'), 'Key in URL');
check(!isset($requests[0]['body']['generationConfig']['temperature']), 'Deprecated Gemini temperature sent in generation');
check(str_contains(json_encode($requests[0]['body'], JSON_UNESCAPED_UNICODE), 'حدس نزن'), 'Fact warning missing');
check(AiArticleService::catalog()['gemini']['default_model'] === 'gemini-3.5-flash-lite', 'Default Gemini model is outdated');
check(AiArticleService::validModel('gemini', 'gemini-3.5-flash-lite'), 'Official Gemini model ID rejected');
$latest = new AiArticleService(['providers'=>['gemini'=>['enabled'=>true, 'free_tier_confirmed'=>true, 'api_key'=>'synthetic-key', 'model'=>'gemini-3.5-flash-lite']]]);
$latestRequest = $latest->prepareConnectionTest('gemini')['request'];
check($latestRequest['url'] === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent', 'Official Gemini model endpoint wrong');
check(in_array('x-goog-api-key: synthetic-key', $latestRequest['headers'], true), 'Gemini key should be passed in a header');
foreach (['gemini','groq','openrouter','nvidia'] as $id) {
    $test = $service->prepareConnectionTest($id);
    $body = $test['request']['body'];
    $limit = $id === 'gemini' ? $body['generationConfig']['maxOutputTokens'] : ($id === 'groq' ? $body['max_completion_tokens'] : $body['max_tokens']);
    check($limit === 256, 'Connection test output limit is wrong');
    if ($id === 'gemini') {
        check($body['contents'][0]['parts'][0]['text'] === 'Reply with OK.', 'Connection test should use a short prompt');
        check(!isset($body['generationConfig']['temperature']), 'Deprecated Gemini temperature must not be sent');
    } else {
        check($body['messages'][0]['content'] === 'Reply with OK.' && $body['stream'] === false, 'Chat test must use a short prompt and non-streaming response');
    }
    check($service->send($test, $fake) !== '', 'Synthetic connection test failed');
}
check(count($requests) === 8, 'Connection tests must make exactly one request each');
$catalog = AiArticleService::catalog();
check($catalog['groq']['default_model'] === 'openai/gpt-oss-120b', 'Groq default wrong');
check(array_column($catalog['groq']['suggested_models'], 'id') === ['openai/gpt-oss-120b','qwen/qwen3.8-27b'], 'Groq suggestions wrong');
check($catalog['nvidia']['default_model'] === 'nvidia/nemotron-3.5-lightning-30b-a3b', 'NVIDIA default wrong');
check(!str_contains(json_encode($catalog), 'https:'), 'Public model catalog should not expose transport settings');
foreach (['openai/gpt-oss-120b','qwen/qwen3.8-27b'] as $model) {
    $modelConfig = $config; $modelConfig['providers']['groq']['model'] = $model;
    $modelService = new AiArticleService($modelConfig);
    foreach ([$modelService->prepare('groq','عنوان','', ''), $modelService->prepareConnectionTest('groq')] as $modelRequest) {
        $body=$modelRequest['request']['body'];
        check($body['model']===$model && $body['stream']===false, 'Groq model or stream not passed');
        if ($model === 'openai/gpt-oss-120b') {
            check($body['reasoning_effort']==='low' && $body['include_reasoning']===false && !isset($body['reasoning_format']), 'GPT-OSS options incorrect');
        } else {
            check($body['reasoning_effort']==='none' && $body['reasoning_format']==='hidden' && !isset($body['include_reasoning']), 'Qwen options incorrect');
        }
        check($modelService->send($modelRequest, $fake) !== '', 'Groq suggested-model response failed');
    }
}
$nvidiaConfig = $config; $nvidiaConfig['providers']['nvidia']['model']=$catalog['nvidia']['default_model'];
$nvidiaService = new AiArticleService($nvidiaConfig);
foreach ([$nvidiaService->prepare('nvidia','عنوان','', ''), $nvidiaService->prepareConnectionTest('nvidia')] as $modelRequest) {
    $body=$modelRequest['request']['body'];
    check($body['model']===$catalog['nvidia']['default_model'] && $body['stream']===false, 'NVIDIA model or stream wrong');
    check(($body['chat_template_kwargs']['enable_thinking'] ?? true) === false, 'NVIDIA default must leave room for a final answer');
    check($nvidiaService->send($modelRequest, $fake) !== '', 'NVIDIA suggested-model response failed');
}
foreach (['groq','nvidia'] as $id) {
    $custom = $service->prepare($id,'عنوان','', '')['request'];
    check(!isset($custom['body']['reasoning_effort']) && !isset($custom['body']['chat_template_kwargs']) && !isset($custom['body']['reasoning_format']), 'Custom models must not inherit unrelated model options');
    check(AiArticleService::validModel($id, 'vendor/custom-model:v2'), 'Custom model ID rejected');
}
check(!AiArticleService::validModel('unknown','model'), 'Unknown provider accepted');
$prepared = $service->prepare('gemini','عنوان','', '');
function providerMessage(AiArticleService $service, array $prepared, int $status, string $body): string {
    try { $service->send($prepared, fn()=>['status'=>$status, 'body'=>$body]); }
    catch (RuntimeException $e) { return $e->getMessage(); }
    throw new RuntimeException('Provider failure did not throw');
}
$hidden = 'sYnThEtIc-SECRET-do-not-show';
foreach ([400,401,402,403,404,410,422,429,500,503,504,418] as $code) {
    $message = providerMessage($service, $prepared, $code, json_encode(['error'=>['message'=>$hidden, 'status'=>$hidden, 'details'=>[['reason'=>$hidden]]]]));
    check(str_contains($message, 'HTTP ' . $code), 'Provider status missing');
    check(!str_contains($message, $hidden), 'Raw provider body must never be returned');
}
$invalid = json_encode(['error'=>['message'=>$hidden, 'details'=>[['reason'=>'API_KEY_INVALID', 'metadata'=>['secret'=>$hidden]]]]]);
$message = providerMessage($service, $prepared, 400, $invalid);
check(str_contains($message, 'HTTP 400') && str_contains($message, 'کلید ارسال‌شده') && !str_contains($message,$hidden), 'Known invalid-key reason should use fixed safe guidance');
$precondition = json_encode(['error'=>['message'=>$hidden, 'status'=>'FAILED_PRECONDITION']]);
$message = providerMessage($service, $prepared, 400, $precondition);
check(str_contains($message, 'پیش‌شرط') && !str_contains($message,$hidden), 'Failed precondition must not leak provider body');
$message = providerMessage($service, $prepared, 429, $hidden);
check(str_contains($message, 'سهمیه') && !str_contains($message,$hidden), 'Unsafe quota error');
$parts = ['candidates'=>[['content'=>['parts'=>[['thought'=>true,'text'=>$hidden], ['text'=>'متن نهایی']]]]]];
check($service->send($prepared, fn()=>['status'=>200,'body'=>json_encode($parts, JSON_UNESCAPED_UNICODE)]) === 'متن نهایی', 'Thought parts should not be returned as article text');
$empty = ['candidates'=>[['finishReason'=>'MAX_TOKENS','content'=>['parts'=>[]]]]];
$message = providerMessage($service, $prepared, 200, json_encode($empty));
check(str_contains($message, 'MAX_TOKENS'), 'Empty truncated Gemini response should have actionable guidance');
$chatPrepared = $nvidiaService->prepareConnectionTest('nvidia');
$message = providerMessage($nvidiaService, $chatPrepared, 200, json_encode(['choices'=>[['finish_reason'=>'length','message'=>['content'=>'', 'reasoning'=>$hidden]]]]));
check(str_contains($message, 'length') && !str_contains($message,$hidden), 'Empty truncated chat response should not leak reasoning');
$message = providerMessage($nvidiaService, $chatPrepared, 202, json_encode(['requestId'=>$hidden]));
check(str_contains($message, 'HTTP 202') && !str_contains($message,$hidden), 'Pending NVIDIA response must not be mistaken for success or leak details');
$config['providers']['openrouter']['model'] = 'provider/paid-model';
check(!(new AiArticleService($config))->status()[2]['available'], 'Paid OpenRouter model was accepted');
$config['providers']['gemini']['free_tier_confirmed'] = false;
check(!(new AiArticleService($config))->status()[0]['available'], 'Unconfirmed account was accepted');
rejects(fn()=> $service->prepare('gemini','','',''), 'Empty title accepted');
foreach (['javascript:alert(1)','//bad.example/','http://bad.example/',"https://good.example/\nLocation: bad",'https://user@bad.example/'] as $url) check(!AdvertisingService::validTarget($url), 'Unsafe ad URL accepted: ' . $url);
foreach (['https://example.com/','/city/rasht/'] as $url) check(AdvertisingService::validTarget($url), 'Valid ad URL rejected');
check(!AdvertisingService::validImagePath('/uploads/../evil.webp'), 'Path traversal accepted');
check(AdvertisingService::validImagePath('/uploads/2026/11/img_abc_1200.webp'), 'Normal media path rejected');
check(array_keys(AdvertisingService::PLACEMENTS) === ['home', 'home_below_hero', 'home_mid', 'city', 'category', 'business', 'article', 'footer_above'], 'Expected 8 advertising placements');
check(AdvertisingService::HOME_ONLY_SLOTS === ['home', 'home_below_hero', 'home_mid'], 'Home-only slots mismatch');
check(AdvertisingService::MAX_BANNERS_PER_SLOT === 2, 'Max banners per slot must be 2');
check(AdvertisingService::earliestBoundary(0, 0, 0) === 0, 'Empty boundaries must yield 0');
check(AdvertisingService::earliestBoundary(0, 500, 300, 0) === 300, 'Earliest non-zero boundary wrong');
check(AdvertisingService::earliestBoundary(120, 450) === 120, 'First earliest boundary wrong');
echo "PASS: synthetic provider adapters, free-model restrictions, consent prerequisites and safe ad URL validation.\n";
