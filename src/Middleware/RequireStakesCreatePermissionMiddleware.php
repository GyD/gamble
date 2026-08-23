<?php

declare(strict_types=1);

namespace App\Middleware;

final readonly class RequireStakesCreatePermissionMiddleware extends AbstractPermissionMiddleware
{
    protected function permission(): string
    {
        return 'stakes.create';
    }
}