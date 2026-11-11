<?php
declare(strict_types=1);

// Test-only GD fault injection. The guarded article fixture must still be used
// with a disposable hhx_test_* database and local synthetic files only.
namespace App\Services {
    function function_exists(string $name): bool
    {
        if ($name === 'imagewebp' && getenv('HHX_TEST_MEDIA_FAULT') === 'no_webp') return false;
        return \function_exists($name);
    }

    function imagewebp(\GdImage $image, mixed $destination = null, int $quality = -1): bool
    {
        static $writes = 0;
        $writes++;
        if (getenv('HHX_TEST_MEDIA_FAULT') === 'second_write' && $writes === 2) {
            if (is_resource($destination)) fwrite($destination, 'synthetic-partial-image');
            elseif (is_string($destination)) file_put_contents($destination, 'synthetic-partial-image');
            return false;
        }
        if (getenv('HHX_TEST_MEDIA_FAULT') === 'truncated_success' && $writes === 2) {
            $result = \imagewebp($image, $destination, $quality);
            if (is_resource($destination)) { fflush($destination); ftruncate($destination, 32); }
            elseif (is_string($destination)) file_put_contents($destination, substr((string)file_get_contents($destination), 0, 32));
            return $result; // Simulate an encoder claiming success despite truncated output.
        }
        return \imagewebp($image, $destination, $quality);
    }
}
namespace {
    if (getenv('HHX_ALLOW_TEST_FIXTURES') !== '1' || !in_array(getenv('HHX_TEST_MEDIA_FAULT'), ['no_webp', 'second_write', 'truncated_success'], true)) {
        http_response_code(403); exit('Test-only media fault fixture.');
    }
    require __DIR__ . '/article_fixture_router.php';
}
