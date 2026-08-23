<?php

declare(strict_types=1);

namespace App\Security;

interface PermissionResolver
{
    public function effectFor(int $userId, string $permission): ?string;
}