<?php
declare(strict_types=1);

namespace CollectorRemote\Providers;

/**
 * پرووایدر اختیاری Wikidata برای جاذبه‌های گردشگری و تاریخی گیلان (اصلاحیه ۶)
 */
final class WikidataProvider implements ProviderInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function name(): string
    {
        return 'wikidata';
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
