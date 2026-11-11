<?php
declare(strict_types=1);

// Synthetic secrets only. No network, production data, or real credentials.
require dirname(__DIR__) . '/app/Services/AiProviderVault.php';
use App\Services\AiProviderVault;
use App\Services\AiProviderVaultException;
function ensure(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function fails(callable $fn, string $message): void { try { $fn(); } catch (AiProviderVaultException $e) { return; } throw new RuntimeException($message); }
$secret = 'synthetic-test-key-never-real';
$master = random_bytes(32);
$a = AiProviderVault::seal('gemini', $secret, $master);
$b = AiProviderVault::seal('gemini', $secret, $master);
ensure($a !== $b, 'Fresh encryption nonce required');
ensure(!str_contains(json_encode($a), $secret), 'Ciphertext must not contain plaintext');
ensure(AiProviderVault::open('gemini', $a, $master) === $secret, 'Round-trip failed');
fails(fn()=>AiProviderVault::open('groq', $a, $master), 'Provider binding missing');
fails(fn()=>AiProviderVault::open('gemini', $a, random_bytes(32)), 'Wrong master accepted');
$tampered = $a; $tampered['tag'] = base64_encode(str_repeat('x',16));
fails(fn()=>AiProviderVault::open('gemini', $tampered, $master), 'Tampering accepted');
$base = sys_get_temp_dir() . '/hhx-ai-vault-' . bin2hex(random_bytes(6));
mkdir($base,0700,true); mkdir($base.'/storage',0700); mkdir($base.'/public_html',0700);
$_SERVER['DOCUMENT_ROOT'] = $base . '/public_html';
try {
    $vault = new AiProviderVault($base);
    $sealed = $vault->encrypt('gemini', $secret, true);
    $path = $base . '/storage/private/ai-provider-master.key';
    ensure(is_file($path) && strlen((string)file_get_contents($path)) === 32, 'Master key was not created');
    ensure((fileperms($path) & 0077) === 0, 'Master key permissions too broad');
    ensure($vault->decrypt('gemini', $sealed) === $secret, 'Stored master failed');
    unlink($path);
    fails(fn()=>$vault->decrypt('gemini', $sealed), 'Missing master should fail closed');
    fails(fn()=>$vault->encrypt('groq', $secret, false), 'Missing master must not be silently replaced');
    $_SERVER['DOCUMENT_ROOT'] = $base;
    fails(fn()=>$vault->encrypt('groq', $secret, true), 'Web-accessible storage should be rejected');
    $_SERVER['DOCUMENT_ROOT'] = $base . '/public_html';
    putenv('HHX_AI_VAULT_KEY=' . base64_encode($master));
    $environment = $vault->encrypt('groq', $secret, false);
    ensure($vault->decrypt('groq', $environment) === $secret, 'Environment master failed');
    putenv('HHX_AI_VAULT_KEY=invalid');
    fails(fn()=>$vault->decrypt('groq', $environment), 'Invalid environment master accepted');
} finally {
    putenv('HHX_AI_VAULT_KEY');
    @unlink($base.'/storage/private/ai-provider.lock'); @unlink($base.'/storage/private/ai-provider-master.key');
    @rmdir($base.'/storage/private'); @rmdir($base.'/storage'); @rmdir($base.'/public_html'); @rmdir($base);
}
echo "PASS: encrypted keys use fresh nonces, authenticated provider binding, private master storage and fail-closed recovery.\n";
