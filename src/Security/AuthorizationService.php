<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\User\User;

final readonly class AuthorizationService
{
    public function __construct(private PermissionResolver $permissions)
    {
    }

    public function can(User $user, string $permission): bool
    {
        if (!$user->isActive()) {
            return false;
        }

        return $this->permissions->effectFor($user->id, $permission) === 'allow';
    }
}