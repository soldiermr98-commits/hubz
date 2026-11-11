<?php
declare(strict_types=1);

namespace CollectorRemote\Providers;

/**
 * کلاس اسکلت خالی برای بلد (طبق بخش ۹ و اصلاحیه ۶: بلد فقط کلاس خالی)
 */
final class BaladProvider implements ProviderInterface
{
    public function name(): string
    {
        return 'balad';
    }

    public function search(array $job): array
    {
        return [];
    }

    public function details(string $sourceId): ?array
    {
        return null;
    }
}
