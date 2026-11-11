<?php
declare(strict_types=1);

namespace App\Core;

/** Experimental test-only checkout is opt-in; this switch never enables live payments. */
final class PaymentSandbox
{
    public static function enabled(): bool
    {
        return Config::get('advertising_payments.experimental_sandbox', false) === true;
    }
}
