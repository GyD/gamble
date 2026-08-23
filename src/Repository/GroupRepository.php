<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Contact\Contact;
use App\Domain\Group\Group;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final readonly class GroupRepository implements GroupStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findAll(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, name, note, archived_at FROM contact_groups
             ORDER BY archived_at IS NOT NULL, name, id',
        )->fetchAll();

        return array_map(fn(array $row): Group => $this->hydrate($row), $rows);
    }

    public function findById(int $id): ?Group
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, note, archived_at FROM contact_groups WHERE id = :id',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(string $name, ?string $note): Group
    {
        $statement = $this->pdo->prepare('INSERT INTO contact_groups (name, note) VALUES (:name, :note)');
        $statement->execute(['name' => $name, 'note' => $note]);

        return $this->findById((int) $this->pdo->lastInsertId())
            ?? throw new RuntimeException('Unable to load the created group.');
    }

    public function update(int $id, string $name, ?string $note): void
    {
        $statement = $this->pdo->prepare('UPDATE contact_groups SET name = :name, note = :note WHERE id = :id');
        $statement->execute(['id' => $id, 'name' => $name, 'note' => $note]);
    }

    public function setArchived(int $id, bool $archived): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE contact_groups SET archived_at = IF(:archived = 1, CURRENT_TIMESTAMP, NULL) WHERE id = :id',
        );
        $statement->execute(['id' => $id, 'archived' => $archived ? 1 : 0]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM contact_groups WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function findAvailableForContact(int $contactId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT g.id, g.name, g.note, g.archived_at
             FROM contact_groups g
             LEFT JOIN contact_group_members m ON m.group_id = g.id AND m.contact_id = :contact_id
             WHERE g.archived_at IS NULL OR m.contact_id IS NOT NULL
             ORDER BY g.archived_at IS NOT NULL, g.name, g.id',
        );
        $statement->execute(['contact_id' => $contactId]);

        return array_map(fn(array $row): Group => $this->hydrate($row), $statement->fetchAll());
    }

    public function memberGroupIds(int $contactId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT group_id FROM contact_group_members WHERE contact_id = :contact_id ORDER BY group_id',
        );
        $statement->execute(['contact_id' => $contactId]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function syncContactGroups(int $contactId, array $groupIds): void
    {
        $delete = $this->pdo->prepare('DELETE FROM contact_group_members WHERE contact_id = :contact_id');
        $delete->execute(['contact_id' => $contactId]);
        $insert = $this->pdo->prepare(
            'INSERT INTO contact_group_members (group_id, contact_id) VALUES (:group_id, :contact_id)',
        );
        foreach ($groupIds as $groupId) {
            $insert->execute(['group_id' => $groupId, 'contact_id' => $contactId]);
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Group
    {
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.name, c.phone_number, c.note, c.archived_at
             FROM contacts c
             INNER JOIN contact_group_members m ON m.contact_id = c.id
             WHERE m.group_id = :group_id
             ORDER BY c.archived_at IS NOT NULL, c.name, c.id',
        );
        $statement->execute(['group_id' => $row['id']]);
        $members = array_map(static fn(array $member): Contact => new Contact(
            (int) $member['id'],
            (string) $member['name'],
            (string) $member['phone_number'],
            $member['note'] === null ? null : (string) $member['note'],
            $member['archived_at'] === null ? null : new DateTimeImmutable((string) $member['archived_at']),
        ), $statement->fetchAll());

        return new Group(
            (int) $row['id'],
            (string) $row['name'],
            $row['note'] === null ? null : (string) $row['note'],
            $row['archived_at'] === null ? null : new DateTimeImmutable((string) $row['archived_at']),
            $members,
        );
    }
}