<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\Group\Group;
use App\Repository\AuditLogger;
use App\Repository\GroupStore;
use App\Service\GroupService;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class GroupServiceTest extends TestCase
{
    private GroupTestStore $groups;
    private GroupTestAuditLogger $audit;
    private GroupService $service;

    protected function setUp(): void
    {
        $this->groups = new GroupTestStore();
        $this->audit = new GroupTestAuditLogger();
        $this->service = new GroupService(new PDO('sqlite::memory:'), $this->groups, $this->audit);
    }

    public function testGroupIsNormalizedCreatedAndAudited(): void
    {
        $group = $this->service->create(7, '  Friends  ', '  Close friends  ', '127.0.0.1');

        self::assertSame('Friends', $group->name);
        self::assertSame('Close friends', $group->note);
        self::assertSame('group.created', $this->audit->entries[0]['action']);
        self::assertSame(['name' => 'Friends', 'note' => 'Close friends', 'archived' => false, 'member_ids' => []], $this->audit->entries[0]['after']);
    }

    public function testBlankNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Group name is required.');
        $this->service->create(7, ' ', null, null);
    }

    public function testGroupCanBeUpdatedArchivedRestoredAndDeleted(): void
    {
        $this->groups->groups[1] = new Group(1, 'Old', null, null);
        $this->service->update(7, 1, 'New', ' Note ', null);
        self::assertSame('New', $this->groups->groups[1]->name);
        self::assertSame('group.updated', $this->audit->entries[0]['action']);

        $this->service->setArchived(7, 1, true, null);
        self::assertTrue($this->groups->groups[1]->isArchived());
        $this->service->setArchived(7, 1, false, null);
        self::assertFalse($this->groups->groups[1]->isArchived());
        $this->service->setArchived(7, 1, true, null);
        $this->service->delete(7, 1, null);
        self::assertArrayNotHasKey(1, $this->groups->groups);
        self::assertSame('group.deleted', $this->audit->entries[4]['action']);
    }

    public function testActiveGroupCannotBeDeleted(): void
    {
        $this->groups->groups[1] = new Group(1, 'Friends', null, null);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only archived groups can be deleted.');
        $this->service->delete(7, 1, null);
    }
}

final class GroupTestStore implements GroupStore
{
    /** @var array<int, Group> */
    public array $groups = [];

    public function findAll(): array { return array_values($this->groups); }
    public function findById(int $id): ?Group { return $this->groups[$id] ?? null; }
    public function create(string $name, ?string $note): Group
    {
        $id = count($this->groups) + 1;
        return $this->groups[$id] = new Group($id, $name, $note, null);
    }
    public function update(int $id, string $name, ?string $note): void
    {
        $group = $this->groups[$id];
        $this->groups[$id] = new Group($id, $name, $note, $group->archivedAt, $group->members);
    }
    public function setArchived(int $id, bool $archived): void
    {
        $group = $this->groups[$id];
        $this->groups[$id] = new Group($id, $group->name, $group->note, $archived ? new DateTimeImmutable() : null, $group->members);
    }
    public function delete(int $id): void { unset($this->groups[$id]); }
    public function findAvailableForContact(int $contactId): array { return []; }
    public function memberGroupIds(int $contactId): array { return []; }
    public function syncContactGroups(int $contactId, array $groupIds): void {}
}

final class GroupTestAuditLogger implements AuditLogger
{
    /** @var list<array<string, mixed>> */
    public array $entries = [];

    public function record(int $actorUserId, string $action, string $entityType, string $entityId, ?array $before, ?array $after, ?string $ipAddress): void
    {
        $this->entries[] = compact('actorUserId', 'action', 'entityType', 'entityId', 'before', 'after', 'ipAddress');
    }
}