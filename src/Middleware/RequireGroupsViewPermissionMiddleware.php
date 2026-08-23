<?php

declare(strict_types=1);

namespace App\Middleware;

final readonly class RequireGroupsViewPermissionMiddleware extends AbstractPermissionMiddleware
{
    protected function permission(): string
    {
        return 'groups.view';
    }
}