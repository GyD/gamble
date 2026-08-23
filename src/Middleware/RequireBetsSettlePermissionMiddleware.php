<?php

declare(strict_types=1);

namespace App\Middleware;

final readonly class RequireBetsSettlePermissionMiddleware extends AbstractPermissionMiddleware
{
    protected function permission(): string
    {
        return 'bets.settle';
    }
}