<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Group\Group;

interface GroupStore
{
    /** @return list<Group> */
    public function findAll(): array;

    public function findById(int $id): ?Group;

    public function create(string $name, ?string $note): Group;

    public function update(int $id, string $name, ?string $note): void;

    public function setArchived(int $id, bool $archived): void;

    public function delete(int $id): void;

    /** @return list<Group> Active groups plus archived groups already containing the contact. */
    public function findAvailableForContact(int $contactId): array;

    /** @return list<int> */
    public function memberGroupIds(int $contactId): array;

    /** @param list<int> $groupIds */
    public function syncContactGroups(int $contactId, array $groupIds): void;
}