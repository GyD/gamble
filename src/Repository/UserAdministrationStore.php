<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\User\User;
use App\Domain\User\UserStatus;

interface UserAdministrationStore
{
    public function findById(int $id): ?User;

    /** @return list<array<string, mixed>> */
    public function findAllWithRoles(): array;

    /** @return list<array{id: int, name: string, label: string}> */
    public function findAllRoles(): array;

    /** @return list<string> */
    public function roleNamesFor(int $userId): array;

    public function updateStatus(int $userId, UserStatus $status): void;

    /** @param list<string> $roleNames */
    public function replaceRoles(int $userId, array $roleNames): void;
}