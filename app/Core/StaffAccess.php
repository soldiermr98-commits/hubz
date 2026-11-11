<?php
declare(strict_types=1);

namespace App\Core;

/** Deny-by-default route policy for managed limited editors. Administrator-only operations
 * remain protected by their original role checks. Shared lookup endpoints are read-only. */
final class StaffAccess
{
    public const PREFIX = 'hhx_staff_';
    public const PERMISSIONS = ['articles','businesses','media','review'];

    public static function allowed(array $permissions, string $method, string $path): bool
    {
        $key = strtoupper($method) . ' ' . rtrim($path, '/');
        if (in_array($key, ['GET /api/v1/cities', 'GET /api/v1/categories'], true)) return true;
        $routes = [
            'articles'=>['GET /api/v1/articles','POST /api/v1/articles','POST /api/v1/articles/auto-build'],
            'businesses'=>['GET /api/v1/businesses','POST /api/v1/businesses','GET /api/v1/slug/suggest'],
            'media'=>['GET /api/v1/media','POST /api/v1/media/upload','POST /api/v1/media/update'],
            'review'=>['GET /api/v1/review-queue'],
        ];
        if (in_array('articles', $permissions, true) && strtoupper($method) === 'GET'
            && preg_match('#^/api/v1/articles/preview/[0-9]+/?$#D', $path)) return true;
        foreach ($routes as $permission=>$items) if (in_array($permission, $permissions, true) && in_array($key, $items, true)) return true;
        return false;
    }
}
