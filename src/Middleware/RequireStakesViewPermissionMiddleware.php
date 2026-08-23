<?php

declare(strict_types=1);

namespace App\Middleware;

final readonly class RequireStakesViewPermissionMiddleware extends AbstractPermissionMiddleware
{
    protected function permission(): string
    {
        return 'stakes.view';
    }
}