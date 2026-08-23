<?php

declare(strict_types=1);

namespace App\Middleware;

final readonly class RequireUsersViewPermissionMiddleware extends AbstractPermissionMiddleware
{
    protected function permission(): string
    {
        return 'users.view';
    }
}