<?php
declare(strict_types=1);

// Transactional in-memory DB double; synthetic secrets and filesystem only.
namespace App\Core {
    final class FakeConnection {
        public bool $active = false; public array $before = [];
        public function beginTransaction(): bool { if ($this->active) throw new \RuntimeException('nested transaction'); $this->before = DB::$rows; return $this->active = true; }
        public function commit(): bool { $this->active = false; return true; }
        public function rollBack(): bool { DB::$rows = $this->before; $this->active = false; return true; }
        public function inTransaction(): bool { return $this->active; }
    }
    final class DB {
        public static array $rows = []; private static ?FakeConnection $connection = null;
        public static function connection(): FakeConnection { return self::$connection ??= new FakeConnection(); }
        public static function fetchOne(string $sql, array $params=[]): ?array {
            if (!str_starts_with($sql, 'SELECT v FROM settings WHERE k=:k')) throw new \RuntimeException('Unexpected query');
            return array_key_exists($params['k'], self::$rows) ? ['v'=>self::$rows[$params['k']]] : null;
        }
        public static function query(string $sql, array $params=[]): void {
            if (str_starts_with($sql, 'INSERT IGNORE INTO settings')) { if (!array_key_exists($params['k'],self::$rows)) self::$rows[$params['k']] = $params['v']; return; }
            if (str_starts_with($sql, 'UPDATE settings SET v=')) { self::$rows[$params['k']] = $params['v']; return; }
            throw new \RuntimeException('Unexpected update');
        }
    }
}
namespace {
    require dirname(__DIR__) . '/app/Services/AiArticleService.php';
    require dirname(__DIR__) . '/app/Services/AiProviderVault.php';
    require dirname(__DIR__) . '/app/Services/AiProviderSettings.php';
    use App\Core\DB;
    use App\Services\AiProviderSettings;
    function checkProvider(bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); }
    function rejected(callable $fn, string $message): void { try { $fn(); } catch (\InvalidArgumentException|\RuntimeException $e) { return; } throw new \RuntimeException($message); }
    $base = sys_get_temp_dir().'/hhx-ai-settings-'.bin2hex(random_bytes(6));
    mkdir($base,0700,true); mkdir($base.'/storage',0700); mkdir($base.'/public_html',0700);
    $_SERVER['DOCUMENT_ROOT']=$base.'/public_html';
    $legacy = ['providers'=>['groq'=>['enabled'=>true,'free_tier_confirmed'=>true,'api_key'=>'synthetic-legacy-key','model'=>'llama-3.1-8b-instant']]];
    try {
        $store = new AiProviderSettings($legacy, $base);
        $status = array_column($store->managementStatus(), null, 'id');
        checkProvider($status['groq']['source']==='file' && $status['groq']['available'], 'Legacy fallback broke');
        checkProvider($status['groq']['model']==='llama-3.1-8b-instant', 'Existing file model must not be silently replaced');
        checkProvider($status['groq']['default_model']==='openai/gpt-oss-120b', 'Groq default should be surfaced');
        checkProvider(array_column($status['groq']['suggested_models'],'id')===['openai/gpt-oss-120b','qwen/qwen3.8-27b'], 'Suggestions missing from management status');
        checkProvider($status['nvidia']['source']==='none' && $status['nvidia']['model']==='nvidia/nemotron-3.5-lightning-30b-a3b' && !$status['nvidia']['enabled'], 'New NVIDIA provider must be disabled with its default');
        checkProvider(!str_contains(json_encode($status), 'synthetic-legacy-key'), 'Management status leaked a key');
        $store->save('gemini','gemini-test-model','synthetic-gemini-key',true,true,0);
        $state = DB::$rows['ai_provider_v1_gemini'];
        checkProvider(!str_contains($state,'synthetic-gemini-key'), 'Database contains plaintext key');
        checkProvider($store->effectiveConfig()['providers']['gemini']['api_key']==='synthetic-gemini-key','Saved key unavailable to generation');
        $store->save('gemini','gemini-test-model-2','',true,true,1);
        checkProvider($store->effectiveConfig()['providers']['gemini']['api_key']==='synthetic-gemini-key','Blank field changed existing key');
        $store->save('gemini','gemini-test-model-2','synthetic-replacement',true,true,2);
        checkProvider($store->effectiveConfig()['providers']['gemini']['api_key']==='synthetic-replacement','Rotation failed');
        $before = DB::$rows;
        rejected(fn()=>$store->save('gemini','gemini-test-model','synthetic-stale',true,true,2),'Stale edit accepted');
        checkProvider(DB::$rows===$before,'Conflict mutated database');
        rejected(fn()=>$store->save('unknown','test','synthetic-test',true,true,0),'Unknown provider accepted');
        rejected(fn()=>$store->save('openrouter','provider/paid-model','synthetic-test',true,true,0),'Paid model accepted');
        rejected(fn()=>$store->save('openrouter','openrouter/free',"bad\nheader",true,true,0),'Header injection accepted');
        rejected(fn()=>$store->save('openrouter','openrouter/free','synthetic-test',true,false,0),'Missing quota consent accepted');
        $store->save('groq','openai/gpt-oss-120b','',true,true,0);
        checkProvider(!str_contains(DB::$rows['ai_provider_v1_groq'],'synthetic-legacy-key'),'Legacy key not encrypted when moved to panel');
        checkProvider($store->effectiveConfig()['providers']['groq']['api_key']==='synthetic-legacy-key', 'Groq model change lost legacy key');
        $store->save('groq','qwen/qwen3.8-27b','',true,true,1);
        checkProvider($store->effectiveConfig()['providers']['groq']['model']==='qwen/qwen3.8-27b', 'Qwen model was not saved');
        $store->save('groq','qwen/qwen3.8-27b','',false,false,2,true);
        $effective=$store->effectiveConfig()['providers']['groq'];
        checkProvider(!$effective['enabled'] && $effective['api_key']==='', 'Delete silently fell back to legacy key');
        $status=array_column($store->managementStatus(),null,'id');
        checkProvider(!$status['groq']['key_configured'] && $status['groq']['source']==='panel','Deletion not visible');
        $store->save('nvidia','nvidia/nemotron-3.5-lightning-30b-a3b','synthetic-nvidia-key',true,true,0);
        checkProvider(!str_contains(DB::$rows['ai_provider_v1_nvidia'],'synthetic-nvidia-key'), 'NVIDIA key must be encrypted');
        $store->save('nvidia','vendor/custom-model:v2','',true,true,1);
        $nvidia=$store->effectiveConfig()['providers']['nvidia'];
        checkProvider($nvidia['api_key']==='synthetic-nvidia-key' && $nvidia['model']==='vendor/custom-model:v2', 'NVIDIA custom model change lost key');
        $store->save('nvidia','vendor/custom-model:v2','synthetic-nvidia-replacement',true,true,2);
        checkProvider($store->effectiveConfig()['providers']['nvidia']['api_key']==='synthetic-nvidia-replacement', 'NVIDIA rotation failed');
        $store->save('nvidia','vendor/custom-model:v2','',false,false,3,true);
        checkProvider(!$store->effectiveConfig()['providers']['nvidia']['enabled'] && $store->effectiveConfig()['providers']['nvidia']['api_key']==='', 'NVIDIA removal failed');
        unlink($base.'/storage/private/ai-provider-master.key');
        rejected(fn()=>$store->save('openrouter','openrouter/free','synthetic-router',true,true,0),'Missing master silently regenerated');
        checkProvider(!is_file($base.'/storage/private/ai-provider-master.key'),'Missing master recreated');
        $status=array_column($store->managementStatus(),null,'id');
        checkProvider(!$status['gemini']['available'] && $status['gemini']['error']!=='','Unreadable key did not fail closed');
        checkProvider(!str_contains(json_encode($status),'synthetic-'),'Management status leaked fake credentials');
        echo "PASS: panel save, encrypted storage, blank-key preservation, rotation, stale-write conflicts, legacy override and lost-master safety.\n";
    } finally {
        @unlink($base.'/storage/private/ai-provider.lock'); @unlink($base.'/storage/private/ai-provider-master.key');
        @rmdir($base.'/storage/private'); @rmdir($base.'/storage'); @rmdir($base.'/public_html'); @rmdir($base);
    }
}
