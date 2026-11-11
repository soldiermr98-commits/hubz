<?php
declare(strict_types=1);

// Site-specific secrets belong in config.local.php, never in version control.
$local = __DIR__ . '/config.local.php';
return require is_file($local) ? $local : __DIR__ . '/config.sample.php';
