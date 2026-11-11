<?php
declare(strict_types=1);

// Pure status tests; no database, live content, or network.
require_once dirname(__DIR__) . '/app/Services/SponsorshipStatus.php';
use App\Services\SponsorshipStatus;

$now = '2026-11-07 12:00:00';
$base = ['article_status'=>'published','business_status'=>'published','sponsor_start'=>null,'sponsor_end'=>null];
$cases = [
    ['unlimited', [], 'active'],
    ['future', ['sponsor_start'=>'2026-11-07 12:00:01'], 'upcoming'],
    ['start inclusive', ['sponsor_start'=>$now], 'active'],
    ['before end', ['sponsor_end'=>'2026-11-07 12:00:01'], 'active'],
    ['end exclusive', ['sponsor_end'=>$now], 'ended'],
    ['expired', ['sponsor_end'=>'2026-11-07 11:59:59'], 'ended'],
    ['draft article', ['article_status'=>'draft'], 'article_unpublished'],
    ['hidden article', ['article_status'=>'hidden'], 'article_unpublished'],
    ['hidden business', ['business_status'=>'hidden'], 'business_unpublished'],
    ['publication takes precedence', ['article_status'=>'draft','business_status'=>'hidden','sponsor_start'=>'2026-12-01 00:00:00'], 'article_unpublished'],
    ['fail closed without statuses', ['article_status'=>null], 'article_unpublished'],
];
foreach ($cases as [$name,$override,$expected]) {
    $actual = SponsorshipStatus::classify(array_replace($base, $override), $now);
    if ($actual !== $expected) throw new RuntimeException("$name: expected $expected, got $actual");
    echo "PASS: $name\n";
}
