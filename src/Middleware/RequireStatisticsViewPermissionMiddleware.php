<?php

declare(strict_types=1);

namespace App\Middleware;

final readonly class RequireStatisticsViewPermissionMiddleware extends AbstractPermissionMiddleware
{
    protected function permission(): string
    {
        return 'statistics.view';
    }
}