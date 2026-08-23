<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Group\Group;
use App\Repository\AuditLogger;
use App\Repository\GroupStore;
use InvalidArgumentException;
use PDO;
use Throwable;

final readonly class GroupService
{
    public function __construct(
        private PDO $pdo,
        private GroupStore $groups,
        private AuditLogger $auditLogs,
    ) {
    }

    public function create(int $actorUserId, string $name, ?string $note, ?string $ipAddress): Group
    {
        [$name, $note] = $this->normalize($name, $note);

        return $this->transactional(function () use ($actorUserId, $name, $note, $ipAddress): Group {
            $group = $this->groups->create($name, $note);
            $this->auditLogs->record(
                $actorUserId,
                'group.created',
                'group',
                (string) $group->id,
                null,
                $this->snapshot($group),
                $ipAddress,
            );

            return $group;
        });
    }

    public function update(
        int $actorUserId,
        int $groupId,
        string $name,
        ?string $note,
        ?string $ipAddress,
    ): void {
        [$name, $note] = $this->normalize($name, $note);
        $this->transactional(function () use ($actorUserId, $groupId, $name, $note, $ipAddress): void {
            $group = $this->group($groupId);
            $this->groups->update($groupId, $name, $note);
            $this->auditLogs->record(
                $actorUserId,
                'group.updated',
                'group',
                (string) $groupId,
                $this->snapshot($group),
                ['name' => $name, 'note' => $note, 'archived' => $group->isArchived(), 'member_ids' => $this->memberIds($group)],
                $ipAddress,
            );
        });
    }

    public function setArchived(int $actorUserId, int $groupId, bool $archived, ?string $ipAddress): void
    {
        $this->transactional(function () use ($actorUserId, $groupId, $archived, $ipAddress): void {
            $group = $this->group($groupId);
            if ($group->isArchived() === $archived) {
                throw new InvalidArgumentException($archived ? 'Group already archived.' : 'Group already active.');
            }
            $this->groups->setArchived($groupId, $archived);
            $after = $this->snapshot($group);
            $after['archived'] = $archived;
            $this->auditLogs->record(
                $actorUserId,
                $archived ? 'group.archived' : 'group.restored',
                'group',
                (string) $groupId,
                $this->snapshot($group),
                $after,
                $ipAddress,
            );
        });
    }

    public function delete(int $actorUserId, int $groupId, ?string $ipAddress): void
    {
        $this->transactional(function () use ($actorUserId, $groupId, $ipAddress): void {
            $group = $this->group($groupId);
            if (!$group->isArchived()) {
                throw new InvalidArgumentException('Only archived groups can be deleted.');
            }
            $this->groups->delete($groupId);
            $this->auditLogs->record(
                $actorUserId,
                'group.deleted',
                'group',
                (string) $groupId,
                $this->snapshot($group),
                null,
                $ipAddress,
            );
        });
    }

    /** @return array{string, string|null} */
    private function normalize(string $name, ?string $note): array
    {
        $name = trim($name);
        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;
        if ($name === '') {
            throw new InvalidArgumentException('Group name is required.');
        }
        if (mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Group name cannot exceed 120 characters.');
        }
        if ($note !== null && mb_strlen($note) > 2000) {
            throw new InvalidArgumentException('Group note cannot exceed 2000 characters.');
        }

        return [$name, $note];
    }

    private function group(int $id): Group
    {
        return $this->groups->findById($id) ?? throw new InvalidArgumentException('Unknown group.');
    }

    /** @return array{name: string, note: string|null, archived: bool, member_ids: list<int>} */
    private function snapshot(Group $group): array
    {
        return [
            'name' => $group->name,
            'note' => $group->note,
            'archived' => $group->isArchived(),
            'member_ids' => $this->memberIds($group),
        ];
    }

    /** @return list<int> */
    private function memberIds(Group $group): array
    {
        return array_map(static fn($member): int => $member->id, $group->members);
    }

    private function transactional(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}