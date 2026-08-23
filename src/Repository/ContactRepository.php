<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Contact\Contact;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final readonly class ContactRepository implements ContactStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findAll(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, name, phone_number, note, archived_at
             FROM contacts
             ORDER BY archived_at IS NOT NULL, name, id',
        );

        return array_map($this->hydrate(...), $statement->fetchAll());
    }

    public function findById(int $id): ?Contact
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, phone_number, note, archived_at FROM contacts WHERE id = :id',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(string $name, string $phoneNumber, ?string $note): Contact
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO contacts (name, phone_number, note) VALUES (:name, :phone_number, :note)',
        );
        $statement->execute(['name' => $name, 'phone_number' => $phoneNumber, 'note' => $note]);

        return $this->findById((int) $this->pdo->lastInsertId())
            ?? throw new RuntimeException('Unable to load the created contact.');
    }

    public function update(int $id, string $name, string $phoneNumber, ?string $note): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE contacts SET name = :name, phone_number = :phone_number, note = :note WHERE id = :id',
        );
        $statement->execute(['id' => $id, 'name' => $name, 'phone_number' => $phoneNumber, 'note' => $note]);
    }

    public function setArchived(int $id, bool $archived): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE contacts SET archived_at = IF(:archived = 1, CURRENT_TIMESTAMP, NULL) WHERE id = :id',
        );
        $statement->execute(['id' => $id, 'archived' => $archived ? 1 : 0]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM contacts WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Contact
    {
        return new Contact(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['phone_number'],
            $row['note'] === null ? null : (string) $row['note'],
            $row['archived_at'] === null ? null : new DateTimeImmutable((string) $row['archived_at']),
        );
    }
}
