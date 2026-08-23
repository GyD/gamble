<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Contact\Contact;
use App\Repository\AuditLogger;
use App\Repository\ContactStore;
use App\Repository\GroupStore;
use InvalidArgumentException;
use PDO;
use Throwable;

final readonly class ContactService
{
    public function __construct(
        private PDO $pdo,
        private ContactStore $contacts,
        private AuditLogger $auditLogs,
        private ?GroupStore $groups = null,
    ) {
    }

    public function create(
        int $actorUserId,
        string $name,
        string $phoneNumber,
        ?string $note,
        ?string $ipAddress,
        ?array $groupIds = null,
    ): Contact
    {
        [$name, $phoneNumber, $note] = $this->normalize($name, $phoneNumber, $note);

        return $this->transactional(function () use ($actorUserId, $name, $phoneNumber, $note, $ipAddress, $groupIds): Contact {
            $contact = $this->contacts->create($name, $phoneNumber, $note);
            $after = $this->snapshot($contact);
            if ($groupIds !== null) {
                $normalizedGroupIds = $this->validateGroupIds($contact->id, $groupIds);
                $this->groups?->syncContactGroups($contact->id, $normalizedGroupIds);
                $after['group_ids'] = $normalizedGroupIds;
            }
            $this->auditLogs->record(
                $actorUserId,
                'contact.created',
                'contact',
                (string) $contact->id,
                null,
                $after,
                $ipAddress,
            );

            return $contact;
        });
    }

    public function update(
        int $actorUserId,
        int $contactId,
        string $name,
        string $phoneNumber,
        ?string $note,
        ?string $ipAddress,
        ?array $groupIds = null,
    ): void {
        [$name, $phoneNumber, $note] = $this->normalize($name, $phoneNumber, $note);

        $this->transactional(function () use ($actorUserId, $contactId, $name, $phoneNumber, $note, $ipAddress, $groupIds): void {
            $contact = $this->contact($contactId);
            $beforeGroupIds = $this->groups?->memberGroupIds($contactId);
            $normalizedGroupIds = $groupIds === null ? $beforeGroupIds : $this->validateGroupIds($contactId, $groupIds);
            $this->contacts->update($contactId, $name, $phoneNumber, $note);
            if ($normalizedGroupIds !== null && $groupIds !== null) {
                $this->groups?->syncContactGroups($contactId, $normalizedGroupIds);
            }
            $before = $this->snapshot($contact);
            $after = ['name' => $name, 'phone_number' => $phoneNumber, 'note' => $note, 'archived' => $contact->isArchived()];
            if ($beforeGroupIds !== null) {
                $before['group_ids'] = $beforeGroupIds;
                $after['group_ids'] = $normalizedGroupIds;
            }
            $this->auditLogs->record(
                $actorUserId,
                'contact.updated',
                'contact',
                (string) $contactId,
                $before,
                $after,
                $ipAddress,
            );
        });
    }

    public function setArchived(
        int $actorUserId,
        int $contactId,
        bool $archived,
        ?string $ipAddress,
    ): void {
        $this->transactional(function () use ($actorUserId, $contactId, $archived, $ipAddress): void {
            $contact = $this->contact($contactId);
            if ($contact->isArchived() === $archived) {
                throw new InvalidArgumentException($archived ? 'Contact already archived.' : 'Contact already active.');
            }

            $this->contacts->setArchived($contactId, $archived);
            $this->auditLogs->record(
                $actorUserId,
                $archived ? 'contact.archived' : 'contact.restored',
                'contact',
                (string) $contactId,
                $this->snapshot($contact),
                [
                    'name' => $contact->name,
                    'phone_number' => $contact->phoneNumber,
                    'note' => $contact->note,
                    'archived' => $archived,
                ],
                $ipAddress,
            );
        });
    }

    public function delete(
        int $actorUserId,
        int $contactId,
        ?string $ipAddress,
    ): void {
        $this->transactional(function () use ($actorUserId, $contactId, $ipAddress): void {
            $contact = $this->contact($contactId);
            if (!$contact->isArchived()) {
                throw new InvalidArgumentException('Only archived contacts can be deleted.');
            }

            $this->contacts->delete($contactId);
            $this->auditLogs->record(
                $actorUserId,
                'contact.deleted',
                'contact',
                (string) $contactId,
                $this->snapshot($contact),
                null,
                $ipAddress,
            );
        });
    }

    /** @return array{string, string, string|null} */
    private function normalize(string $name, string $phoneNumber, ?string $note): array
    {
        $name = trim($name);
        $phoneNumber = trim($phoneNumber);
        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        if ($name === '') {
            throw new InvalidArgumentException('Contact name is required.');
        }

        if (mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Contact name cannot exceed 120 characters.');
        }

        if (preg_match('/^\d{1,4}$/', $phoneNumber) !== 1) {
            throw new InvalidArgumentException('Contact phone number must contain between 1 and 4 digits.');
        }

        if ($note !== null && mb_strlen($note) > 2000) {
            throw new InvalidArgumentException('Contact note cannot exceed 2000 characters.');
        }

        return [$name, $phoneNumber, $note];
    }

    private function contact(int $id): Contact
    {
        return $this->contacts->findById($id)
            ?? throw new InvalidArgumentException('Unknown contact.');
    }

    /** @param array<mixed> $groupIds @return list<int> */
    private function validateGroupIds(int $contactId, array $groupIds): array
    {
        if ($this->groups === null) {
            throw new InvalidArgumentException('Group management is unavailable.');
        }
        $normalized = [];
        foreach ($groupIds as $groupId) {
            if (!is_string($groupId) && !is_int($groupId)) {
                throw new InvalidArgumentException('Invalid group identifier.');
            }
            $value = (string) $groupId;
            if (preg_match('/^[1-9]\d*$/', $value) !== 1) {
                throw new InvalidArgumentException('Invalid group identifier.');
            }
            $normalized[(int) $value] = true;
        }
        $currentIds = array_flip($this->groups->memberGroupIds($contactId));
        foreach (array_keys($normalized) as $groupId) {
            $group = $this->groups->findById($groupId);
            if ($group === null) {
                throw new InvalidArgumentException('Unknown group.');
            }
            if ($group->isArchived() && !isset($currentIds[$groupId])) {
                throw new InvalidArgumentException('An archived group cannot receive new contacts.');
            }
        }
        $ids = array_keys($normalized);
        sort($ids);

        return $ids;
    }

    /** @return array{name: string, phone_number: string, note: string|null, archived: bool} */
    private function snapshot(Contact $contact): array
    {
        return [
            'name' => $contact->name,
            'phone_number' => $contact->phoneNumber,
            'note' => $contact->note,
            'archived' => $contact->isArchived(),
        ];
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
