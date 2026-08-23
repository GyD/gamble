<?php

declare(strict_types=1);

namespace App\Middleware;

final readonly class RequireGroupsCreatePermissionMiddleware extends AbstractPermissionMiddleware
{
    protected function permission(): string
    {
        return 'groups.create';
    }
}