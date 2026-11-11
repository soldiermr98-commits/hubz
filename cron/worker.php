<?php
declare(strict_types=1);

// Run from CLI only; schedule this file using the PHP binary provided by the host.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$basePath = dirname(__DIR__);
require_once $basePath . '/app/Core/Bootstrap.php';
\App\Core\Bootstrap::init($basePath);

// Do not exit inside the guarded block: finally must release the lock even
// when a service throws or reports a failed run.
$exitCode = 0;
try {
    if (!\App\Core\WorkerLock::acquire('cron')) {
        echo json_encode(['ok'=>true, 'status'=>'busy', 'message'=>'Cron run already in progress.']) . PHP_EOL;
    } else {
        try {
            $summary = \App\Services\MaintenanceService::run();
            if (class_exists(\App\Services\CollectorRunner::class)) {
                $summary['collector'] = \App\Services\CollectorRunner::runNext();
            }
            try {
                $summary['apify'] = \App\Services\ApifyAutomationService::configured()->tick();
            } catch (Throwable $e) {
                error_log('Apify cron failed: ' . get_class($e));
                $summary['apify'] = ['status'=>'error'];
            }
            $ok = ($summary['status'] ?? '') !== 'cache_error'
                && ($summary['collector']['status'] ?? '') !== 'failed'
                && !in_array($summary['apify']['status'] ?? '', ['error','fetch_error','preflight_error','start_unknown'], true);
            echo json_encode(['ok'=>$ok, 'summary'=>$summary], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            if (!$ok) $exitCode = 1;
        } finally {
            \App\Core\WorkerLock::release('cron');
        }
    }
} catch (Throwable $e) {
    error_log('HamiHub cron failed: ' . get_class($e));
    fwrite(STDERR, "Cron failed or its result is uncertain; see server logs.\n");
    $exitCode = 1;
}
exit($exitCode);
