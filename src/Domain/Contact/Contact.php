<?php

declare(strict_types=1);

namespace App\Domain\Contact;

use DateTimeImmutable;

final readonly class Contact
{
    public function __construct(
        public int $id,
        public string $name,
        public string $phoneNumber,
        public ?string $note,
        public ?DateTimeImmutable $archivedAt,
    ) {
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }
}
