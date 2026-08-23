<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\User\User;
use App\Domain\User\UserStatus;
use App\Repository\AuditLogger;
use App\Repository\UserAdministrationStore;
use App\Service\UserAdministrationService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UserAdministrationServiceTest extends TestCase
{
    private PDO $pdo;
    private InMemoryUserAdministrationStore $users;
    private InMemoryAuditLogger $auditLogs;
    private UserAdministrationService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->users = new InMemoryUserAdministrationStore([
            1 => new User(1, '1', 'admin', 'Admin', null, UserStatus::Active),
            2 => new User(2, '2', 'viewer', 'Viewer', null, UserStatus::Pending),
        ]);
        $this->auditLogs = new InMemoryAuditLogger();
        $this->service = new UserAdministrationService($this->pdo, $this->users, $this->auditLogs);
    }

    public function testStatusChangeIsCommittedAndAudited(): void
    {
        $this->service->changeStatus(1, 2, UserStatus::Active, '127.0.0.1');

        self::assertSame(UserStatus::Active, $this->users->users[2]->status);
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame([[
            'actor_user_id' => 1,
            'action' => 'user.status_changed',
            'entity_type' => 'user',
            'entity_id' => '2',
            'before' => ['status' => 'pending'],
            'after' => ['status' => 'active'],
            'ip_address' => '127.0.0.1',
        ]], $this->auditLogs->entries);
    }

    public function testOwnStatusCannotBeChanged(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('You cannot change your own status.');

        $this->service->changeStatus(1, 1, UserStatus::Suspended, null);
    }

    public function testPendingCannotBeAssignedByAdministration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid managed status.');

        $this->service->changeStatus(1, 2, UserStatus::Pending, null);
    }

    public function testAccessReplacementIsNormalizedAndAudited(): void
    {
        $this->users->rolesByUser[2] = ['bookmaker'];
        $this->users->permissionsByUser[2] = ['bets.delete' => 'deny'];

        $this->service->replaceAccess(
            1,
            2,
            ['bookmaker', 'admin'],
            ['users.manage' => 'allow', 'bets.delete' => 'deny'],
            '::1',
        );

        self::assertSame(['admin', 'bookmaker'], $this->users->rolesByUser[2]);
        self::assertSame([
            'bets.delete' => 'deny',
            'users.manage' => 'allow',
        ], $this->users->permissionsByUser[2]);
        self::assertSame('user.access_changed', $this->auditLogs->entries[0]['action']);
        self::assertSame([
            'roles' => ['bookmaker'],
            'permissions' => ['bets.delete' => 'deny'],
        ], $this->auditLogs->entries[0]['before']);
        self::assertSame([
            'roles' => ['admin', 'bookmaker'],
            'permissions' => ['bets.delete' => 'deny', 'users.manage' => 'allow'],
        ], $this->auditLogs->entries[0]['after']);
    }

    public function testUnknownRoleIsRejectedBeforeMutation(): void
    {
        try {
            $this->service->replaceAccess(1, 2, ['super-admin'], [], null);
            self::fail('Unknown role should have been rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Unknown role.', $exception->getMessage());
        }

        self::assertSame([], $this->users->rolesByUser);
        self::assertSame([], $this->auditLogs->entries);
    }

    public function testDuplicateRolesAreNormalizedBeforeMutation(): void
    {
        $this->service->replaceAccess(1, 2, ['admin', 'admin'], [], null);

        self::assertSame(['admin'], $this->users->rolesByUser[2]);
        self::assertSame(['admin'], $this->auditLogs->entries[0]['after']['roles']);
    }

    public function testNonStringRoleIsRejectedBeforeMutation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid role list.');

        /** @phpstan-ignore argument.type */
        $this->service->replaceAccess(1, 2, ['admin', 42], [], null);
    }

    public function testUnknownPermissionIsRejectedBeforeMutation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown permission.');

        $this->service->replaceAccess(1, 2, [], ['root.access' => 'allow'], null);
    }

    public function testUnknownUserDoesNotLeaveTransactionOpen(): void
    {
        try {
            $this->service->replaceAccess(1, 999, [], [], null);
            self::fail('Unknown user should have been rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Unknown user.', $exception->getMessage());
        }

        self::assertFalse($this->pdo->inTransaction());
        self::assertSame([], $this->auditLogs->entries);
    }

    public function testTransactionIsRolledBackWhenAuditFails(): void
    {
        $this->pdo->exec('CREATE TABLE status_changes (user_id INTEGER NOT NULL)');
        $users = new TransactionalUserAdministrationStore($this->users->users, $this->pdo);
        $auditLogs = new class implements AuditLogger {
            public function record(
                int $actorUserId,
                string $action,
                string $entityType,
                string $entityId,
                ?array $before,
                ?array $after,
                ?string $ipAddress,
            ): void {
                throw new RuntimeException('Audit unavailable.');
            }
        };
        $service = new UserAdministrationService($this->pdo, $users, $auditLogs);

        try {
            $service->changeStatus(1, 2, UserStatus::Active, null);
            self::fail('Audit failure should have been propagated.');
        } catch (RuntimeException $exception) {
            self::assertSame('Audit unavailable.', $exception->getMessage());
        }

        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM status_changes')->fetchColumn());
    }
}

class InMemoryUserAdministrationStore implements UserAdministrationStore
{
    /** @param array<int, User> $users */
    public function __construct(public array $users)
    {
    }

    /** @var array<int, list<string>> */
    public array $rolesByUser = [];

    /** @var array<int, array<string, string>> */
    public array $permissionsByUser = [];

    public function findById(int $id): ?User
    {
        return $this->users[$id] ?? null;
    }

    public function findAllWithRoles(): array
    {
        return [];
    }

    public function findAllRoles(): array
    {
        return [
            ['id' => 1, 'name' => 'admin', 'label' => 'Administrator'],
            ['id' => 2, 'name' => 'bookmaker', 'label' => 'Bookmaker'],
        ];
    }

    public function findAllPermissions(): array
    {
        return [
            ['id' => 1, 'name' => 'bets.delete', 'description' => 'Delete bets'],
            ['id' => 2, 'name' => 'users.manage', 'description' => 'Manage users'],
        ];
    }

    public function roleNamesFor(int $userId): array
    {
        return $this->rolesByUser[$userId] ?? [];
    }

    public function permissionEffectsFor(int $userId): array
    {
        return $this->permissionsByUser[$userId] ?? [];
    }

    public function updateStatus(int $userId, UserStatus $status): void
    {
        $user = $this->users[$userId];
        $this->users[$userId] = new User(
            $user->id,
            $user->twitchId,
            $user->twitchLogin,
            $user->twitchDisplayName,
            $user->twitchAvatarUrl,
            $status,
        );
    }

    public function replaceAccess(int $userId, array $roleNames, array $permissionEffects): void
    {
        $this->rolesByUser[$userId] = $roleNames;
        $this->permissionsByUser[$userId] = $permissionEffects;
    }
}

final class TransactionalUserAdministrationStore extends InMemoryUserAdministrationStore
{
    public function __construct(array $users, private readonly PDO $pdo)
    {
        parent::__construct($users);
    }

    public function updateStatus(int $userId, UserStatus $status): void
    {
        $statement = $this->pdo->prepare('INSERT INTO status_changes (user_id) VALUES (:user_id)');
        $statement->execute(['user_id' => $userId]);
        parent::updateStatus($userId, $status);
    }
}

final class InMemoryAuditLogger implements AuditLogger
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