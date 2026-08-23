<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Contact\Contact;

interface ContactStore
{
    /** @return list<Contact> */
    public function findAll(): array;

    public function findById(int $id): ?Contact;

    public function create(string $name, string $phoneNumber, ?string $note): Contact;

    public function update(int $id, string $name, string $phoneNumber, ?string $note): void;

    public function setArchived(int $id, bool $archived): void;

    public function delete(int $id): void;
}
