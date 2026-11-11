<?php
declare(strict_types=1);

namespace CollectorRemote\Providers;

/**
 * اسکلت پرووایدر رسمی Google Places API (طبق بخش ۹ و اصلاحیه ۶)
 */
final class GooglePlacesProvider implements ProviderInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function name(): string
    {
        return 'google_places';
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
