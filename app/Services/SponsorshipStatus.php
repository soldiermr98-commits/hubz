<?php
declare(strict_types=1);

namespace App\Services;

/** Classifies the currently stored article item, not a private draft revision. */
final class SponsorshipStatus
{
    /**
     * Match the public article: the article and business must both be published,
     * the start is inclusive, and the end is exclusive. $now is site local time.
     */
    public static function classify(array $item, string $now): string
    {
        if (($item['article_status'] ?? '') !== 'published') return 'article_unpublished';
        if (($item['business_status'] ?? '') !== 'published') return 'business_unpublished';
        if (!empty($item['sponsor_end']) && (string)$item['sponsor_end'] <= $now) return 'ended';
        if (!empty($item['sponsor_start']) && (string)$item['sponsor_start'] > $now) return 'upcoming';
        return 'active';
    }

    /** Next start/end of a visible sponsored item, using the site's local timezone. */
    public static function nextBoundary(array $items, string $now): int
    {
        $next = 0;
        foreach ($items as $item) {
            if (empty($item['is_sponsored'])) continue;
            foreach (['sponsor_start', 'sponsor_end'] as $key) {
                $boundary = (string)($item[$key] ?? '');
                if ($boundary === '' || $boundary <= $now) continue;
                $timestamp = strtotime($boundary);
                if ($timestamp !== false && $timestamp > 0 && ($next === 0 || $timestamp < $next)) $next = $timestamp;
            }
        }
        return $next;
    }
}
