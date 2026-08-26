<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\User\UserStatus;
use App\Repository\AuditLogger;
use App\Repository\UserAdministrationStore;
use InvalidArgumentException;
use PDO;
use Throwable;

final readonly class UserAdministrationService
{
    public function __construct(
        private PDO $pdo,
        private UserAdministrationStore $users,
        private AuditLogger $auditLogs,
    ) {
    }

    public function changeStatus(
        int $actorUserId,
        int $targetUserId,
        UserStatus $status,
        ?string $ipAddress,
    ): void {
        if ($actorUserId === $targetUserId) {
            throw new InvalidArgumentException('You cannot change your own status.');
        }

        if (!in_array($status, [UserStatus::Active, UserStatus::Suspended], true)) {
            throw new InvalidArgumentException('Invalid managed status.');
        }

        $this->transactional(function () use ($actorUserId, $targetUserId, $status, $ipAddress): void {
            $target = $this->users->findById($targetUserId)
                ?? throw new InvalidArgumentException('Unknown user.');
            $this->users->updateStatus($target->id, $status);
            $this->auditLogs->record(
                $actorUserId,
                'user.status_changed',
                'user',
                (string) $target->id,
                ['status' => $target->status->value],
                ['status' => $status->value],
                $ipAddress,
            );
        });
    }

    /** @param list<string> $roleNames */
    public function replaceAccess(
        int $actorUserId,
        int $targetUserId,
        array $roleNames,
        ?string $ipAddress,
    ): void {
        if ($actorUserId === $targetUserId) {
            throw new InvalidArgumentException('You cannot change your own access.');
        }

        foreach ($roleNames as $roleName) {
            if (!is_string($roleName)) {
                throw new InvalidArgumentException('Invalid role list.');
            }
        }

        $roleNames = array_values(array_unique($roleNames));

        $allowedRoles = array_column($this->users->findAllRoles(), 'name');
        if (array_diff($roleNames, $allowedRoles) !== []) {
            throw new InvalidArgumentException('Unknown role.');
        }

        sort($roleNames);

        $this->transactional(function () use (
            $actorUserId,
            $targetUserId,
            $roleNames,
            $ipAddress,
        ): void {
            if ($this->users->findById($targetUserId) === null) {
                throw new InvalidArgumentException('Unknown user.');
            }

            $before = ['roles' => $this->users->roleNamesFor($targetUserId)];
            $this->users->replaceRoles($targetUserId, $roleNames);
            $this->auditLogs->record(
                $actorUserId,
                'user.access_changed',
                'user',
                (string) $targetUserId,
                $before,
                ['roles' => $roleNames],
                $ipAddress,
            );
        });
    }

    private function transactional(callable $operation): void
    {
        $this->pdo->beginTransaction();

        try {
            $operation();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
}