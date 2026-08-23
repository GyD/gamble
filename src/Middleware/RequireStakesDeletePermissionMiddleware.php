<?php

declare(strict_types=1);

namespace App\Middleware;

final readonly class RequireStakesDeletePermissionMiddleware extends AbstractPermissionMiddleware
{
    protected function permission(): string
    {
        return 'stakes.delete';
    }
}