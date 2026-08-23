<?php

declare(strict_types=1);

namespace App\Middleware;

final readonly class RequireContactsDeletePermissionMiddleware extends AbstractPermissionMiddleware
{
    protected function permission(): string
    {
        return 'contacts.delete';
    }
}
