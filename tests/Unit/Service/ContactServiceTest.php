<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\Contact\Contact;
use App\Repository\AuditLogger;
use App\Repository\ContactStore;
use App\Repository\GroupStore;
use App\Domain\Group\Group;
use App\Service\ContactService;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContactServiceTest extends TestCase
{
    private TestContactStore $contacts;
    private TestContactAuditLogger $auditLogs;
    private ContactTestGroupStore $groups;
    private ContactService $service;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->contacts = new TestContactStore();
        $this->auditLogs = new TestContactAuditLogger();
        $this->groups = new ContactTestGroupStore();
        $this->service = new ContactService($pdo, $this->contacts, $this->auditLogs, $this->groups);
    }

    public function testContactIsNormalizedCreatedAndAudited(): void
    {
        $contact = $this->service->create(7, '  Alice  ', '  0042  ', '  Friend  ', '127.0.0.1');

        self::assertSame('Alice', $contact->name);
        self::assertSame('0042', $contact->phoneNumber);
        self::assertSame('Friend', $contact->note);
        self::assertSame([
            'actor_user_id' => 7,
            'action' => 'contact.created',
            'entity_type' => 'contact',
            'entity_id' => '1',
            'before' => null,
            'after' => ['name' => 'Alice', 'phone_number' => '0042', 'note' => 'Friend', 'archived' => false],
            'ip_address' => '127.0.0.1',
        ], $this->auditLogs->entries[0]);
    }

    public function testContactIsCreatedWithSelectedGroups(): void
    {
        $this->groups->groups[2] = new Group(2, 'Amis', null, null);

        $contact = $this->service->create(7, 'Alice', '0042', null, null, ['2']);

        self::assertSame([2], $this->groups->memberships[$contact->id]);
        self::assertSame([2], $this->auditLogs->entries[0]['after']['group_ids']);
    }

    public function testContactCannotBeCreatedInArchivedGroup(): void
    {
        $this->groups->groups[2] = new Group(2, 'Anciens', null, new DateTimeImmutable());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An archived group cannot receive new contacts.');

        $this->service->create(7, 'Alice', '0042', null, null, ['2']);
    }

    public function testContactCannotBelongToMultipleGroups(): void
    {
        $this->groups->groups[1] = new Group(1, 'Friends', null, null);
        $this->groups->groups[2] = new Group(2, 'Players', null, null);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A contact can only belong to one group.');

        $this->service->create(7, 'Alice', '0042', null, null, ['1', '2']);
    }

    public function testEmptyGroupSelectionCreatesContactWithoutGroup(): void
    {
        $contact = $this->service->create(7, 'Alice', '0042', null, null, ['']);

        self::assertSame([], $this->groups->memberships[$contact->id]);
    }

    /** @param string|null $note */
    #[DataProvider('invalidContacts')]
    public function testInvalidContactIsRejected(string $name, string $phoneNumber, ?string $note, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->service->create(7, $name, $phoneNumber, $note, null);
    }

    public static function invalidContacts(): iterable
    {
        yield 'blank name' => ['  ', '1234', null, 'Contact name is required.'];
        yield 'long name' => [str_repeat('a', 121), '1234', null, 'Contact name cannot exceed 120 characters.'];
        yield 'blank phone number' => ['Alice', ' ', null, 'Contact phone number must contain between 1 and 4 digits.'];
        yield 'long phone number' => ['Alice', '12345', null, 'Contact phone number must contain between 1 and 4 digits.'];
        yield 'non-digit phone number' => ['Alice', '12A', null, 'Contact phone number must contain between 1 and 4 digits.'];
        yield 'long note' => ['Alice', '1234', str_repeat('a', 2001), 'Contact note cannot exceed 2000 characters.'];
    }

    public function testContactCanBeUpdated(): void
    {
        $this->contacts->contacts[4] = new Contact(4, 'Alice', '1234', null, null);

        $this->service->update(7, 4, ' Alice Cooper ', ' 0042 ', ' ', null);

        self::assertSame('Alice Cooper', $this->contacts->contacts[4]->name);
        self::assertSame('0042', $this->contacts->contacts[4]->phoneNumber);
        self::assertNull($this->contacts->contacts[4]->note);
        self::assertSame('contact.updated', $this->auditLogs->entries[0]['action']);
        self::assertSame([
            'name' => 'Alice',
            'phone_number' => '1234',
            'note' => null,
            'archived' => false,
            'group_ids' => [],
        ], $this->auditLogs->entries[0]['before']);
    }

    public function testContactGroupsAreSynchronizedAndIncludedInUpdateAudit(): void
    {
        $this->contacts->contacts[4] = new Contact(4, 'Alice', '1234', null, null);
        $groups = new ContactTestGroupStore();
        $groups->groups[1] = new Group(1, 'Friends', null, null);
        $groups->groups[2] = new Group(2, 'Players', null, null);
        $groups->memberships[4] = [1];
        $service = new ContactService(new PDO('sqlite::memory:'), $this->contacts, $this->auditLogs, $groups);

        $service->update(7, 4, 'Alice', '1234', null, null, ['2']);

        self::assertSame([2], $groups->memberships[4]);
        self::assertSame([1], $this->auditLogs->entries[0]['before']['group_ids']);
        self::assertSame([2], $this->auditLogs->entries[0]['after']['group_ids']);
    }

    public function testArchivedGroupCannotReceiveNewContact(): void
    {
        $this->contacts->contacts[4] = new Contact(4, 'Alice', '1234', null, null);
        $groups = new ContactTestGroupStore();
        $groups->groups[1] = new Group(1, 'Archived', null, new DateTimeImmutable());
        $service = new ContactService(new PDO('sqlite::memory:'), $this->contacts, $this->auditLogs, $groups);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An archived group cannot receive new contacts.');
        $service->update(7, 4, 'Alice', '1234', null, null, [1]);
    }

    public function testContactCanBeArchivedAndRestored(): void
    {
        $this->contacts->contacts[4] = new Contact(4, 'Alice', '1234', null, null);

        $this->service->setArchived(7, 4, true, null);
        $this->service->setArchived(7, 4, false, null);

        self::assertFalse($this->contacts->contacts[4]->isArchived());
        self::assertSame(['contact.archived', 'contact.restored'], array_column($this->auditLogs->entries, 'action'));
    }

    public function testUnknownContactCannotBeUpdated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown contact.');

        $this->service->update(7, 404, 'Nobody', '1234', null, null);
    }

    public function testContactCannotBeArchivedTwice(): void
    {
        $this->contacts->contacts[4] = new Contact(4, 'Alice', '1234', null, new DateTimeImmutable());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Contact already archived.');

        $this->service->setArchived(7, 4, true, null);
    }

    public function testArchivedContactCanBeDeletedAndAudited(): void
    {
        $this->contacts->contacts[4] = new Contact(4, 'Alice', '1234', null, new DateTimeImmutable());

        $this->service->delete(7, 4, '127.0.0.1');

        self::assertArrayNotHasKey(4, $this->contacts->contacts);
        self::assertSame('contact.deleted', $this->auditLogs->entries[0]['action']);
        self::assertSame(['name' => 'Alice', 'phone_number' => '1234', 'note' => null, 'archived' => true], $this->auditLogs->entries[0]['before']);
        self::assertNull($this->auditLogs->entries[0]['after']);
    }

    public function testActiveContactCannotBeDeleted(): void
    {
        $this->contacts->contacts[4] = new Contact(4, 'Alice', '1234', null, null);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only archived contacts can be deleted.');

        $this->service->delete(7, 4, null);
    }
}

final class TestContactStore implements ContactStore
{
    /** @var array<int, Contact> */
    public array $contacts = [];

    public function findAll(): array
    {
        return array_values($this->contacts);
    }

    public function findById(int $id): ?Contact
    {
        return $this->contacts[$id] ?? null;
    }

    public function create(string $name, string $phoneNumber, ?string $note): Contact
    {
        $id = count($this->contacts) + 1;

        return $this->contacts[$id] = new Contact($id, $name, $phoneNumber, $note, null);
    }

    public function update(int $id, string $name, string $phoneNumber, ?string $note): void
    {
        $contact = $this->contacts[$id];
        $this->contacts[$id] = new Contact($id, $name, $phoneNumber, $note, $contact->archivedAt);
    }

    public function setArchived(int $id, bool $archived): void
    {
        $contact = $this->contacts[$id];
        $this->contacts[$id] = new Contact(
            $id,
            $contact->name,
            $contact->phoneNumber,
            $contact->note,
            $archived ? new DateTimeImmutable() : null,
        );
    }

    public function delete(int $id): void
    {
        unset($this->contacts[$id]);
    }
}

final class TestContactAuditLogger implements AuditLogger
{
    /** @var list<array<string, mixed>> */
    public array $entries = [];

    public function record(
        int $actorUserId,
        string $action,
        string $entityType,
        string $entityId,
        ?array $before,
        ?array $after,
        ?string $ipAddress,
    ): void {
        $this->entries[] = [
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $ipAddress,
        ];
    }
}

final class ContactTestGroupStore implements GroupStore
{
    /** @var array<int, Group> */
    public array $groups = [];
    /** @var array<int, list<int>> */
    public array $memberships = [];

    public function findAll(): array { return array_values($this->groups); }
    public function findById(int $id): ?Group { return $this->groups[$id] ?? null; }
    public function create(string $name, ?string $note): Group { throw new \LogicException(); }
    public function update(int $id, string $name, ?string $note): void { throw new \LogicException(); }
    public function setArchived(int $id, bool $archived): void { throw new \LogicException(); }
    public function delete(int $id): void { throw new \LogicException(); }
    public function findAvailableForContact(int $contactId): array { return array_values($this->groups); }
    public function memberGroupIds(int $contactId): array { return $this->memberships[$contactId] ?? []; }
    public function syncContactGroups(int $contactId, array $groupIds): void { $this->memberships[$contactId] = $groupIds; }
}
