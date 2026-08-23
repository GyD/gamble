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

    /**
     * @param list<string> $roleNames
     * @param array<string, string> $permissionEffects
     */
    public function replaceAccess(
        int $actorUserId,
        int $targetUserId,
        array $roleNames,
        array $permissionEffects,
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

        $allowedPermissions = array_column($this->users->findAllPermissions(), 'name');
        if (array_diff(array_keys($permissionEffects), $allowedPermissions) !== []) {
            throw new InvalidArgumentException('Unknown permission.');
        }

        foreach ($permissionEffects as $effect) {
            if (!in_array($effect, ['allow', 'deny'], true)) {
                throw new InvalidArgumentException('Invalid permission effect.');
            }
        }

        sort($roleNames);
        ksort($permissionEffects);

        $this->transactional(function () use (
            $actorUserId,
            $targetUserId,
            $roleNames,
            $permissionEffects,
            $ipAddress,
        ): void {
            if ($this->users->findById($targetUserId) === null) {
                throw new InvalidArgumentException('Unknown user.');
            }

            $before = [
                'roles' => $this->users->roleNamesFor($targetUserId),
                'permissions' => $this->users->permissionEffectsFor($targetUserId),
            ];
            $this->users->replaceAccess($targetUserId, $roleNames, $permissionEffects);
            $this->auditLogs->record(
                $actorUserId,
                'user.access_changed',
                'user',
                (string) $targetUserId,
                $before,
                ['roles' => $roleNames, 'permissions' => $permissionEffects],
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