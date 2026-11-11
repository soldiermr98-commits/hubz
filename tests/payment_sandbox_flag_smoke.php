<?php
declare(strict_types=1);

namespace App\Core;

// Pure unit test: no application config, DB or network is read.
final class Config
{
    public static mixed $fixture = null;
    public static function get(string $key, mixed $default = null): mixed
    {
        if ($key !== 'advertising_payments.experimental_sandbox') throw new \RuntimeException('Unexpected flag path');
        return self::$fixture ?? $default;
    }
}
require dirname(__DIR__) . '/app/Core/PaymentSandbox.php';
foreach ([null, false, 0, 1, '1', 'true', 'false', [], true] as $input) {
    Config::$fixture = $input;
    if (PaymentSandbox::enabled() !== ($input === true)) throw new \RuntimeException('Sandbox flag must require boolean true');
}
echo "PASS: sandbox routes require the explicit boolean true; all other values are disabled.\n";
