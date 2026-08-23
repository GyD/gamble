<?php

declare(strict_types=1);

namespace App\Domain\Group;

use App\Domain\Contact\Contact;
use DateTimeImmutable;

final readonly class Group
{
    /** @param list<Contact> $members */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $note,
        public ?DateTimeImmutable $archivedAt,
        public array $members = [],
    ) {
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }
}