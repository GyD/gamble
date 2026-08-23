<?php

declare(strict_types=1);

namespace App\Middleware;

final readonly class RequireBetsCreatePermissionMiddleware extends AbstractPermissionMiddleware
{
    protected function permission(): string
    {
        return 'bets.create';
    }
}