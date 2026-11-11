<?php
declare(strict_types=1);

namespace App\Core;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/** File cache for public pages. All writers must share one lock-capable filesystem. */
final class Cache
{
    private const MAX_AGE_SECONDS = 300;
    private static ?string $renderGeneration = null;
    private static int $renderStartedAt = 0;

    private static function baseCacheDir(): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        return $base . '/public_html/cache/pages';
    }

    private static function validPath(string $path): bool
    {
        return $path === '/' || (bool)preg_match('~^/[a-z0-9-]+(?:/[a-z0-9-]+)*/?$~D', $path);
    }

    /** The public paths remain public_html/cache/pages/<path>/index.html. */
    public static function resolveFilePath(string $uriPath): string
    {
        $clean = trim($uriPath, '/');
        if ($clean === '') return self::baseCacheDir() . '/_home/index.html';
        $safePath = preg_replace('/[^a-z0-9\/\-]/', '', strtolower($clean));
        return self::baseCacheDir() . '/' . trim((string)$safePath, '/') . '/index.html';
    }

    /**
     * Open the stable generation file and hold its exclusive lock. Never remove
     * or rename it while the application is running: that would split the lock.
     * @return array{0:resource,1:string}|null
     */
    private static function lockGeneration(): ?array
    {
        $dir = self::baseCacheDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return null;
        $handle = @fopen($dir . '/.hhx-generation', 'c+b');
        if ($handle === false) return null;
        if (!@flock($handle, LOCK_EX)) { fclose($handle); return null; }
        if (!rewind($handle)) { self::unlock($handle); return null; }
        $generation = stream_get_contents($handle);
        if (!is_string($generation)) { self::unlock($handle); return null; }
        if (!preg_match('/^[a-f0-9]{32}$/D', $generation)) {
            // Missing/corrupt metadata invalidates all existing page markers.
            try { $generation = bin2hex(random_bytes(16)); }
            catch (Throwable $e) { self::unlock($handle); return null; }
            if (!self::writeGeneration($handle, $generation)) { self::unlock($handle); return null; }
        }
        return [$handle, $generation];
    }

    /** @param resource $handle */
    private static function writeGeneration($handle, string $generation): bool
    {
        // Truncating first makes interrupted/partial writes invalid metadata.
        if (!@ftruncate($handle, 0) || !@rewind($handle)) return false;
        $written = @fwrite($handle, $generation);
        return $written === strlen($generation) && @fflush($handle);
    }

    /** @param resource $handle */
    private static function unlock($handle): void
    {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }

    /** Call once before a public renderer reads any page data. */
    public static function beginRender(): void
    {
        self::$renderGeneration = null;
        self::$renderStartedAt = 0;
        $locked = self::lockGeneration();
        if ($locked === null) return;
        [$handle, $generation] = $locked;
        self::$renderGeneration = $generation;
        self::$renderStartedAt = time();
        self::unlock($handle);
    }

    /** A stale or uninitialized renderer may still respond, but cannot populate the cache. */
    public static function putPage(string $uriPath, string $html): bool
    {
        if (!self::validPath($uriPath) || self::$renderGeneration === null || self::$renderStartedAt <= 0
            || time() - self::$renderStartedAt >= self::MAX_AGE_SECONDS) return false;
        $locked = self::lockGeneration();
        if ($locked === null) return false;
        [$handle, $generation] = $locked;
        $temp = false;
        try {
            if (!hash_equals($generation, self::$renderGeneration)) return false;
            $filePath = self::resolveFilePath($uriPath);
            $dir = dirname($filePath);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
            // Same-directory rename is atomic; readers never see a partial page.
            $temp = @tempnam($dir, '.hhx-page-');
            if ($temp === false || dirname($temp) !== $dir) return false;
            $content = $html . "\n<!-- hhx-cache-generation:" . $generation . ':' . self::$renderStartedAt . ' -->';
            $written = @file_put_contents($temp, $content);
            if ($written !== strlen($content)) return false;
            @chmod($temp, 0644);
            return @rename($temp, $filePath);
        } finally {
            if (is_string($temp) && is_file($temp)) @unlink($temp);
            self::unlock($handle);
        }
    }

    public static function getPage(string $uriPath): ?string
    {
        if (!self::validPath($uriPath)) return null;
        $locked = self::lockGeneration();
        if ($locked === null) return null;
        [$handle, $generation] = $locked;
        try {
            $content = @file_get_contents(self::resolveFilePath($uriPath));
            if (!is_string($content) || !str_contains($content, 'public.css?v=s21.0')) return null;
            if (!preg_match('/\n<!-- hhx-cache-generation:([a-f0-9]{32}):(\d+) -->\z/D', $content, $cache)) return null;
            $created = (int)$cache[2];
            $now = time();
            if (!hash_equals($generation, $cache[1]) || $created <= 0 || $created > $now || $now - $created >= self::MAX_AGE_SECONDS) return null;
            // Both independently expire at their next start/end boundary.
            foreach (['ad', 'sponsor'] as $type) {
                if (!preg_match('/<!-- hhx-' . $type . '-until:(\d+) -->/', $content, $marker)) return null;
                $until = (int)$marker[1];
                if ($until > 0 && $now >= $until) return null;
            }
            return $content;
        } finally {
            self::unlock($handle);
        }
    }

    /** A targeted invalidation also advances the shared generation for in-flight renders. */
    public static function forgetPage(string $uriPath): bool
    {
        if (!self::validPath($uriPath)) return false;
        return self::flushAll() >= 0;
    }

    /**
     * Atomically advance the generation before best-effort disk cleanup.
     * Returns -1 when invalidation cannot be confirmed (and logs that failure).
     */
    public static function flushAll(): int
    {
        $locked = self::lockGeneration();
        if ($locked === null) {
            error_log('Page cache invalidation failed: generation lock unavailable.');
            return -1;
        }
        [$handle] = $locked;
        try {
            try { $next = bin2hex(random_bytes(16)); }
            catch (Throwable $e) { error_log('Page cache invalidation failed: random generation unavailable.'); return -1; }
            if (!self::writeGeneration($handle, $next)) {
                error_log('Page cache invalidation failed: generation write unavailable.');
                return -1;
            }
        } finally {
            self::unlock($handle);
        }

        // Failure to unlink does not revive old content: its generation no
        // longer matches. A concurrently refreshed page may be removed, which
        // is only a cache miss. Leave the stable generation file untouched.
        $deleted = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::baseCacheDir(), RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getFilename() === 'index.html') {
                    if (@unlink($file->getPathname())) $deleted++;
                } elseif ($file->isDir()) {
                    @rmdir($file->getPathname());
                }
            }
        } catch (Throwable $e) {
            error_log('Page cache cleanup incomplete; previous generation remains invalid.');
        }
        return $deleted;
    }
}
