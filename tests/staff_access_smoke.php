<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/Core/StaffAccess.php';
use App\Core\StaffAccess;
$cases = [
    [[], 'GET', '/api/v1/cities', true],
    [[], 'GET', '/api/v1/articles', false],
    [['articles'], 'GET', '/api/v1/articles', true],
    [['articles'], 'GET', '/api/v1/articles/preview/4', true],
    [['articles'], 'GET', '/api/v1/businesses', false],
    [['articles'], 'POST', '/api/v1/articles', true],
    [['articles'], 'POST', '/api/v1/review-queue/decide', false],
    [['review'], 'GET', '/api/v1/review-queue', true],
    [['review'], 'POST', '/api/v1/review-queue/decide', false],
    [['media'], 'POST', '/api/v1/media/delete', false],
    [['businesses','articles','media','review'], 'GET', '/api/v1/admin-mail', false],
    [['businesses','articles','media','review'], 'POST', '/api/v1/staff', false],
    [['businesses','articles','media','review'], 'POST', '/api/v1/unknown', false],
];
foreach ($cases as [$p,$method,$path,$expected]) {
    if (StaffAccess::allowed($p,$method,$path) !== $expected) throw new RuntimeException("Unexpected access: $method $path");
}
echo "PASS: limited staff route permissions are explicit and deny by default.\n";
