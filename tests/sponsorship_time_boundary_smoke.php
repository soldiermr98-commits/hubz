<?php
declare(strict_types=1);

// Pure time-window checks: no database, no network and no real sponsor data.
require_once dirname(__DIR__) . '/app/Controllers/PublicSiteController.php';
$active = new ReflectionMethod(\App\Controllers\PublicSiteController::class, 'isActiveSponsored');
$now = '2026-11-07 12:00:00';
$cases = [
    ['disabled', ['is_sponsored'=>0, 'sponsor_start'=>null, 'sponsor_end'=>null], false],
    ['unlimited', ['is_sponsored'=>1, 'sponsor_start'=>null, 'sponsor_end'=>null], true],
    ['future start', ['is_sponsored'=>1, 'sponsor_start'=>'2026-11-07 12:00:01', 'sponsor_end'=>null], false],
    ['exact start', ['is_sponsored'=>1, 'sponsor_start'=>$now, 'sponsor_end'=>null], true],
    ['before end', ['is_sponsored'=>1, 'sponsor_start'=>null, 'sponsor_end'=>'2026-11-07 12:00:01'], true],
    ['exact end', ['is_sponsored'=>1, 'sponsor_start'=>null, 'sponsor_end'=>$now], false],
    ['expired', ['is_sponsored'=>1, 'sponsor_start'=>null, 'sponsor_end'=>'2026-11-07 11:59:59'], false],
];
foreach ($cases as [$name, $item, $expected]) {
    if ($active->invoke(null, $item, $now) !== $expected) throw new RuntimeException('Unexpected sponsorship state: ' . $name);
    echo 'PASS: ' . $name . PHP_EOL;
}
echo "All sponsorship boundary checks passed.\n";
