<?php

declare(strict_types=1);

namespace App\Middleware;

final readonly class RequireContactsCreatePermissionMiddleware extends AbstractPermissionMiddleware
{
    protected function permission(): string
    {
        return 'contacts.create';
    }
}
