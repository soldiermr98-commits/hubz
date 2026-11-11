<?php
declare(strict_types=1);

// In-process unit fixture: fake upload provenance and fake PDO only. It never
// connects to MySQL or runs provider code. HTTP multipart/real DB are tested
// separately by media_upload_http_smoke.mjs and media_database_fault_fixture.php.
namespace App\Core {
    final class SyntheticMediaPdo extends \PDO
    {
        public bool $active = false;
        public bool $commitThenThrow = false;
        public bool $rollbackFails = false;
        public int $rollbacks = 0;
        public function __construct() {}
        public function inTransaction(): bool { return $this->active; }
        public function beginTransaction(): bool { if ($this->active) return false; $this->active = true; return true; }
        public function commit(): bool {
            DB::commitPending();
            if ($this->commitThenThrow) {
                // Simulate a committed row while the client still believes a
                // transaction exists, followed by a successful no-op rollback.
                $this->commitThenThrow = false;
                throw new \PDOException('synthetic commit connection loss');
            }
            $this->active = false; return true;
        }
        public function rollBack(): bool {
            $this->rollbacks++;
            if ($this->rollbackFails) throw new \PDOException('synthetic rollback connection loss');
            $this->active = false; DB::$pending = null; return true;
        }
    }
    final class DB
    {
        public static ?SyntheticMediaPdo $pdo = null;
        public static ?array $pending = null;
        public static array $committed = [];
        public static bool $failInsert = false;
        public static function connection(): \PDO { return self::$pdo ??= new SyntheticMediaPdo(); }
        public static function query(string $sql, array $params = []): void {
            if (self::$failInsert) throw new \PDOException('synthetic secret SQLSTATE failure');
            self::$pending = $params;
        }
        public static function lastInsertId(): string { return (string)(count(self::$committed) + 1); }
        public static function commitPending(): void {
            if (self::$pending === null) throw new \RuntimeException('No synthetic insert pending.');
            self::$committed[] = self::$pending; self::$pending = null;
        }
    }
}
namespace App\Services {
    final class SyntheticMediaFaults { public static ?string $random = null; }
    function is_uploaded_file(string $path): bool {
        $root = $GLOBALS['hhx_media_synthetic_root'] ?? null; $file = realpath($path);
        return is_string($root) && is_string($file) && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file);
    }
    function random_bytes(int $length): string {
        return SyntheticMediaFaults::$random !== null && $length === 8 ? SyntheticMediaFaults::$random : \random_bytes($length);
    }
}
namespace {
    use App\Core\DB;
    use App\Services\MediaService;
    use App\Services\MediaUploadException;
    use App\Services\SyntheticMediaFaults;

    if (!in_array(PHP_SAPI, ['cli', 'wasm'], true) || getenv('HHX_ALLOW_TEST_FIXTURES') !== '1') {
        fwrite(STDERR, "CLI-only synthetic test; explicit fixture opt-in required.\n"); exit(2);
    }
    if (!extension_loaded('gd') || !function_exists('imagewebp') || !function_exists('imagecreatefromwebp') ||
        !extension_loaded('mbstring') || !class_exists(\finfo::class) || !class_exists(\PDO::class)) {
        throw new \RuntimeException('GD with WebP, mbstring, fileinfo and PDO are required.');
    }
    function expect(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
    function mediaFiles(string $root): array {
        $files = glob($root . '/public_html/uploads/*/*/img_*.webp') ?: [];
        sort($files); return $files;
    }
    function uploadFailure(array $file): MediaUploadException {
        try { MediaService::handleUpload($file, 'synthetic'); }
        catch (MediaUploadException $e) { return $e; }
        throw new \RuntimeException('An injected failure unexpectedly succeeded.');
    }
    $root = sys_get_temp_dir() . '/hhx-media-synthetic-' . bin2hex(\random_bytes(8));
    if (!mkdir($root . '/public_html/uploads', 0700, true)) throw new \RuntimeException('Cannot create a disposable test directory.');
    $GLOBALS['hhx_media_synthetic_root'] = realpath($root);
    define('BASE_PATH', $root);
    require dirname(__DIR__) . '/app/Services/MediaService.php';
    try {
        $image = imagecreatetruecolor(128, 200);
        imagealphablending($image, false); imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 34, 104, 179, 96));
        $source = $root . '/input.png'; expect(imagepng($image, $source), 'Could not create fixture PNG.'); unset($image);
        $file = ['error'=>UPLOAD_ERR_OK, 'tmp_name'=>$source, 'size'=>filesize($source)];
        SyntheticMediaFaults::$random = str_repeat("\x01", 8);
        $media = MediaService::handleUpload($file, 'تصویر ساختگی');
        expect(count(DB::$committed) === 1 && !DB::connection()->inTransaction(), 'The successful insert was not committed.');
        expect($media['mime_type'] === 'image/webp' && $media['width'] === 128 && $media['height'] === 200, 'Unexpected media metadata.');
        $saved = [];
        foreach ([480, 800, 1200] as $width) {
            $path = $root . '/public_html' . $media["file_path_{$width}"];
            $info = getimagesize($path); $decoded = imagecreatefromwebp($path);
            expect(is_array($info) && $info['mime'] === 'image/webp' && $decoded !== false && imagesx($decoded) === 128 && imagesy($decoded) === 200, 'Invalid generated WebP.');
            $color = imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0));
            expect(($color['alpha'] ?? 0) >= 90, 'Transparent PNG alpha was not preserved in WebP.');
            unset($decoded); $saved[$path] = hash_file('sha256', $path);
        }
        expect(count(mediaFiles($root)) === 3, 'Expected three distinct derivatives.');
        $before = mediaFiles($root);
        uploadFailure($file); // Same token: do not overwrite or delete another image.
        expect(mediaFiles($root) === $before && count(DB::$committed) === 1, 'Filename collision changed files or rows.');
        foreach ($saved as $path=>$hash) expect(hash_file('sha256', $path) === $hash, 'Collision changed an existing file.');

        SyntheticMediaFaults::$random = str_repeat("\x02", 8); DB::$failInsert = true;
        $error = uploadFailure($file); DB::$failInsert = false;
        expect(!str_contains($error->getMessage(), 'SQLSTATE') && mediaFiles($root) === $before, 'Insert failure leaked details or left partial files.');
        expect(count(DB::$committed) === 1 && DB::connection()->rollbacks === 1, 'Insert failure did not roll back.');

        SyntheticMediaFaults::$random = str_repeat("\x05", 8); DB::$failInsert = true; DB::connection()->rollbackFails = true;
        $error = uploadFailure($file);
        expect(str_contains($error->getMessage(), 'لغو ثبت تصویر نامشخص') && count(mediaFiles($root)) === 6, 'Uncertain rollback should preserve files and require review.');
        // Reset the fake PDO and remove only the three test outputs just retained.
        DB::$failInsert = false; DB::connection()->rollbackFails = false; DB::connection()->active = false; DB::$pending = null;
        foreach (array_diff(mediaFiles($root), $before) as $path) expect(unlink($path), 'Could not clean synthetic rollback files.');
        expect(mediaFiles($root) === $before, 'Synthetic rollback cleanup failed.');

        SyntheticMediaFaults::$random = str_repeat("\x03", 8); DB::connection()->active = true;
        uploadFailure($file);
        expect(DB::connection()->active && mediaFiles($root) === $before, 'Existing caller transaction was altered.');
        DB::connection()->active = false;

        SyntheticMediaFaults::$random = str_repeat("\x04", 8); DB::connection()->commitThenThrow = true;
        $error = uploadFailure($file);
        expect(str_contains($error->getMessage(), 'نامشخص') && count(DB::$committed) === 2, 'Ambiguous commit was not reported correctly.');
        $committed = DB::$committed[1];
        foreach (['p480', 'p800', 'p1200'] as $key) {
            expect(is_file($root . '/public_html' . $committed[$key]), 'Ambiguous commit deleted a potentially committed image.');
        }
        expect(count(mediaFiles($root)) === 6, 'Ambiguous commit should retain all three derivatives for review.');
        echo "PASS: synthetic GD/PDO alpha, collision, rollback, uncertain rollback, outer transaction and ambiguous commit.\n";
        echo "NOTE: fake PDO and upload provenance; not an HTTP, real DB or host test.\n";
    } finally {
        // Remove only paths in the randomly created disposable test directory.
        foreach (mediaFiles($root) as $path) @unlink($path);
        foreach (glob($root . '/public_html/uploads/*/*', GLOB_ONLYDIR) ?: [] as $dir) @rmdir($dir);
        foreach (glob($root . '/public_html/uploads/*', GLOB_ONLYDIR) ?: [] as $dir) @rmdir($dir);
        @unlink($root . '/input.png'); @rmdir($root . '/public_html/uploads'); @rmdir($root . '/public_html'); @rmdir($root);
    }
}
