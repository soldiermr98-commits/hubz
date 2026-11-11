<?php
declare(strict_types=1);

// File-only synthetic tests. Child processes use a unique temporary directory,
// never the checkout's cache or the live site.
$child = $argv[1] ?? '';
if (str_starts_with($child, 'child-')) {
    $root = $argv[2] ?? '';
    if (!preg_match('~^' . preg_quote(sys_get_temp_dir(), '~') . '/hhx-cache-test-[a-f0-9]{16}$~D', $root)) throw new RuntimeException('Invalid temporary root');
    define('BASE_PATH', $root);
    require dirname(__DIR__) . '/app/Core/Cache.php';
    $html = '<link href="/assets/css/public.css?v=s10.0"><!-- hhx-ad-until:0 --><!-- hhx-sponsor-until:0 -->';
    if ($child === 'child-stale') {
        \App\Core\Cache::beginRender();
        echo "ready\n"; flush();
        if (trim((string)fgets(STDIN)) !== 'continue') throw new RuntimeException('Missing signal');
        echo \App\Core\Cache::putPage('/race', $html . 'STALE') ? "written\n" : "rejected\n";
    } elseif ($child === 'child-writer') {
        for ($i=0; $i<100; $i++) {
            \App\Core\Cache::beginRender();
            $data = str_repeat($i % 2 ? 'a' : 'b', 65536);
            if (!\App\Core\Cache::putPage('/atomic', $html . '<fixture>' . $data . '</fixture>')) throw new RuntimeException('Atomic writer failed');
        }
    } elseif ($child === 'child-reader') {
        for ($i=0; $i<200; $i++) {
            $result = \App\Core\Cache::getPage('/atomic');
            if ($result === null) continue;
            if (!preg_match('/<fixture>([ab]+)<\/fixture>/', $result, $match)
                || strlen($match[1]) !== 65536 || trim($match[1], $match[1][0]) !== '') throw new RuntimeException('Observed incomplete or mixed page');
        }
    } else throw new RuntimeException('Unknown child');
    exit;
}

$root = sys_get_temp_dir() . '/hhx-cache-test-' . bin2hex(random_bytes(8));
if (!mkdir($root . '/public_html/cache/pages', 0700, true)) throw new RuntimeException('Cannot create temporary root');
define('BASE_PATH', $root);
require dirname(__DIR__) . '/app/Core/Cache.php';
require dirname(__DIR__) . '/app/Services/SponsorshipStatus.php';
use App\Core\Cache;
use App\Services\SponsorshipStatus;

function checkCache(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}
function spawnCache(string $mode, string $root): array {
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-n', __FILE__, $mode, $root], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start test process');
    return [$process, $pipes];
}
function finishCache($process, array $pipes): string {
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Cache child failed: ' . $err);
    return (string)$out;
}
function removeCacheTree(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($dir);
}

$html = '<link href="/assets/css/public.css?v=s10.0"><!-- hhx-ad-until:0 --><!-- hhx-sponsor-until:0 -->';
try {
    checkCache(!Cache::putPage('/never-started', $html), 'a renderer without a generation cannot write');
    Cache::beginRender();
    checkCache(Cache::putPage('/race', $html . 'BEFORE') && Cache::getPage('/race') !== null, 'current generation is cached');
    $before = file_get_contents(Cache::resolveFilePath('/race'));
    [$stale, $stalePipes] = spawnCache('child-stale', $root);
    checkCache(trim((string)fgets($stalePipes[1])) === 'ready', 'another process captured the old generation');
    checkCache(Cache::flushAll() >= 0, 'purge advanced the generation');
    fwrite($stalePipes[0], "continue\n");
    checkCache(trim(finishCache($stale, $stalePipes)) === 'rejected', 'old renderer cannot repopulate after purge');
    checkCache(Cache::getPage('/race') === null, 'purged page is not served');
    @mkdir(dirname(Cache::resolveFilePath('/race')), 0755, true);
    file_put_contents(Cache::resolveFilePath('/race'), $before);
    checkCache(Cache::getPage('/race') === null, 'old file remains invalid even if disk deletion failed');
    checkCache(!Cache::putPage('/race', $html . 'OLD-PARENT'), 'old parent snapshot is also rejected');
    Cache::beginRender();
    checkCache(Cache::putPage('/race', $html . 'AFTER') && str_contains((string)Cache::getPage('/race'), 'AFTER'), 'new renderer can refresh the page');
    checkCache(Cache::getPage('/bad?path') === null && !Cache::putPage('/bad?path', $html), 'invalid paths cannot alias cache entries');

    $file = Cache::resolveFilePath('/race');
    $fresh = (string)file_get_contents($file);
    file_put_contents($file, (string)preg_replace('/:(\d+) -->\z/', ':' . (time()-300) . ' -->', $fresh));
    checkCache(Cache::getPage('/race') === null, 'page expires at the 300-second ceiling');
    Cache::beginRender();
    checkCache(Cache::putPage('/ad', str_replace('hhx-ad-until:0', 'hhx-ad-until:' . time(), $html)) && Cache::getPage('/ad') === null, 'banner expires at its exact boundary');
    checkCache(Cache::putPage('/sponsor', str_replace('hhx-sponsor-until:0', 'hhx-sponsor-until:' . time(), $html)) && Cache::getPage('/sponsor') === null, 'sponsored item expires at its exact boundary');
    checkCache(Cache::putPage('/future', str_replace('hhx-sponsor-until:0', 'hhx-sponsor-until:' . (time()+100), $html)) && Cache::getPage('/future') !== null, 'future sponsorship remains cacheable');
    checkCache(Cache::putPage('/missing-marker', str_replace('<!-- hhx-sponsor-until:0 -->', '', $html)) && Cache::getPage('/missing-marker') === null, 'old templates without sponsorship marker miss');

    date_default_timezone_set('Asia/Tehran');
    $now = '2026-11-05 12:00:00';
    $items = [
        ['is_sponsored'=>0, 'sponsor_start'=>'2026-11-05 12:00:01'],
        ['is_sponsored'=>1, 'sponsor_start'=>'2026-11-05 11:59:00', 'sponsor_end'=>'2026-11-05 12:02:00'],
        ['is_sponsored'=>1, 'sponsor_start'=>'2026-11-05 12:01:00', 'sponsor_end'=>'2026-11-05 12:03:00'],
    ];
    checkCache(SponsorshipStatus::nextBoundary($items, $now) === strtotime('2026-11-05 12:01:00'), 'nearest visible sponsorship start or end uses site timezone');
    checkCache(SponsorshipStatus::nextBoundary($items, '2026-11-05 12:03:00') === 0, 'no expired boundary is scheduled again');

    [$writer, $writerPipes] = spawnCache('child-writer', $root);
    [$reader, $readerPipes] = spawnCache('child-reader', $root);
    finishCache($writer, $writerPipes); finishCache($reader, $readerPipes);
    checkCache(true, 'concurrent writers and readers never observe a partial page');

    Cache::beginRender();
    checkCache(Cache::putPage('/corrupt', $html), 'corruption fixture prepared');
    file_put_contents($root . '/public_html/cache/pages/.hhx-generation', 'incomplete');
    checkCache(Cache::getPage('/corrupt') === null && !Cache::putPage('/corrupt', $html), 'corrupt generation invalidates old files and renderers');
    // No other processes are active now; simulate an unavailable lock file.
    unlink($root . '/public_html/cache/pages/.hhx-generation');
    mkdir($root . '/public_html/cache/pages/.hhx-generation');
    Cache::beginRender();
    checkCache(Cache::getPage('/corrupt') === null && !Cache::putPage('/corrupt', $html), 'unavailable lock turns reads and writes into misses');
    checkCache(Cache::flushAll() === -1, 'failed invalidation is reported instead of claiming success');
    echo "All cache concurrency and time-boundary tests passed.\n";
} finally {
    removeCacheTree($root);
}
