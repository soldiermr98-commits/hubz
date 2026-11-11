<?php
declare(strict_types=1);

namespace App\Services;

final class Normalizer
{
    public static function cleanPersianText(string $value): string
    {
        return SlugService::normalizePersianText($value);
    }

    public static function normalizePhone(string $value): string
    {
        return SlugService::normalizePhone($value);
    }
}
