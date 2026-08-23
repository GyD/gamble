<?php

declare(strict_types=1);

namespace App\Middleware;

final readonly class RequireUsersManagePermissionMiddleware extends AbstractPermissionMiddleware
{
    protected function permission(): string
    {
        return 'users.manage';
    }
}