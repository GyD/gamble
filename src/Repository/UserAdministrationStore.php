<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\User\User;
use App\Domain\User\UserStatus;

interface UserAdministrationStore
{
    public function findById(int $id): ?User;

    /** @return list<array{id: int, name: string, label: string}> */
    public function findAllRoles(): array;

    /** @return list<array{id: int, name: string, description: string}> */
    public function findAllPermissions(): array;

    /** @return list<string> */
    public function roleNamesFor(int $userId): array;

    /** @return array<string, string> */
    public function permissionEffectsFor(int $userId): array;

    public function updateStatus(int $userId, UserStatus $status): void;

    /**
     * @param list<string> $roleNames
     * @param array<string, string> $permissionEffects
     */
    public function replaceAccess(int $userId, array $roleNames, array $permissionEffects): void;
}