<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\User\TwitchIdentity;
use App\Domain\User\User;
use App\Domain\User\UserStatus;
use PDO;
use RuntimeException;

final readonly class UserRepository implements UserStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findById(int $id): ?User
    {
        $statement = $this->pdo->prepare(
            'SELECT id, twitch_id, twitch_login, twitch_display_name, twitch_avatar_url, status
             FROM users
             WHERE id = :id',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByTwitchId(string $twitchId): ?User
    {
        $statement = $this->pdo->prepare(
            'SELECT id, twitch_id, twitch_login, twitch_display_name, twitch_avatar_url, status
             FROM users
             WHERE twitch_id = :twitch_id',
        );
        $statement->execute(['twitch_id' => $twitchId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /** @return list<array<string, mixed>> */
    public function findAllWithRoles(): array
    {
        $statement = $this->pdo->query(
            "SELECT users.id, users.twitch_id, users.twitch_login, users.twitch_display_name,
                    users.twitch_avatar_url, users.status, users.last_login_at,
                    COALESCE(GROUP_CONCAT(roles.label ORDER BY roles.label SEPARATOR ', '), '') AS role_labels
             FROM users
             LEFT JOIN user_roles ON user_roles.user_id = users.id
             LEFT JOIN roles ON roles.id = user_roles.role_id
             GROUP BY users.id
             ORDER BY FIELD(users.status, 'pending', 'active', 'suspended'), users.twitch_display_name",
        );

        return $statement->fetchAll();
    }

    /** @return list<array{id: int, name: string, label: string}> */
    public function findAllRoles(): array
    {
        return $this->pdo->query('SELECT id, name, label FROM roles ORDER BY label')->fetchAll();
    }

    /** @return list<array{id: int, name: string, description: string}> */
    public function findAllPermissions(): array
    {
        return $this->pdo->query('SELECT id, name, description FROM permissions ORDER BY name')->fetchAll();
    }

    /** @return list<string> */
    public function roleNamesFor(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT roles.name
             FROM user_roles
             INNER JOIN roles ON roles.id = user_roles.role_id
             WHERE user_roles.user_id = :user_id
             ORDER BY roles.name',
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<string, string> */
    public function permissionEffectsFor(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT permissions.name, user_permissions.effect
             FROM user_permissions
             INNER JOIN permissions ON permissions.id = user_permissions.permission_id
             WHERE user_permissions.user_id = :user_id
             ORDER BY permissions.name',
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function updateStatus(int $userId, UserStatus $status): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET status = :status WHERE id = :id');
        $statement->execute(['status' => $status->value, 'id' => $userId]);
    }

    /**
     * @param list<string> $roleNames
     * @param array<string, string> $permissionEffects
     */
    public function replaceAccess(int $userId, array $roleNames, array $permissionEffects): void
    {
        $deleteRoles = $this->pdo->prepare('DELETE FROM user_roles WHERE user_id = :user_id');
        $deleteRoles->execute(['user_id' => $userId]);

        $insertRole = $this->pdo->prepare(
            'INSERT INTO user_roles (user_id, role_id)
             SELECT :user_id, id FROM roles WHERE name = :role_name',
        );
        foreach ($roleNames as $roleName) {
            $insertRole->execute(['user_id' => $userId, 'role_name' => $roleName]);
        }

        $deletePermissions = $this->pdo->prepare('DELETE FROM user_permissions WHERE user_id = :user_id');
        $deletePermissions->execute(['user_id' => $userId]);

        $insertPermission = $this->pdo->prepare(
            'INSERT INTO user_permissions (user_id, permission_id, effect)
             SELECT :user_id, id, :effect FROM permissions WHERE name = :permission_name',
        );
        foreach ($permissionEffects as $permissionName => $effect) {
            $insertPermission->execute([
                'user_id' => $userId,
                'permission_name' => $permissionName,
                'effect' => $effect,
            ]);
        }
    }

    public function synchronizeTwitchIdentity(TwitchIdentity $identity): User
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (
                twitch_id, twitch_login, twitch_display_name, twitch_avatar_url, status, last_login_at
             ) VALUES (
                :twitch_id, :twitch_login, :twitch_display_name, :twitch_avatar_url, :status, CURRENT_TIMESTAMP
             )
             ON DUPLICATE KEY UPDATE
                twitch_login = VALUES(twitch_login),
                twitch_display_name = VALUES(twitch_display_name),
                twitch_avatar_url = VALUES(twitch_avatar_url),
                last_login_at = CURRENT_TIMESTAMP',
        );
        $statement->execute([
            'twitch_id' => $identity->id,
            'twitch_login' => $identity->login,
            'twitch_display_name' => $identity->displayName,
            'twitch_avatar_url' => $identity->avatarUrl,
            'status' => UserStatus::Pending->value,
        ]);

        return $this->findByTwitchId($identity->id)
            ?? throw new RuntimeException('Unable to load the synchronized Twitch user.');
    }

    public function grantAdministratorAccess(string $twitchId): bool
    {
        $this->pdo->beginTransaction();

        try {
            $activate = $this->pdo->prepare(
                "UPDATE users SET status = 'active' WHERE twitch_id = :twitch_id",
            );
            $activate->execute(['twitch_id' => $twitchId]);

            if ($activate->rowCount() === 0 && $this->findByTwitchId($twitchId) === null) {
                $this->pdo->rollBack();

                return false;
            }

            $assignRole = $this->pdo->prepare(
                "INSERT IGNORE INTO user_roles (user_id, role_id)
                 SELECT users.id, roles.id
                 FROM users
                 CROSS JOIN roles
                 WHERE users.twitch_id = :twitch_id AND roles.name = 'admin'",
            );
            $assignRole->execute(['twitch_id' => $twitchId]);
            $this->pdo->commit();

            return true;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): User
    {
        return new User(
            (int)$row['id'],
            (string)$row['twitch_id'],
            (string)$row['twitch_login'],
            (string)$row['twitch_display_name'],
            $row['twitch_avatar_url'] === null ? null : (string)$row['twitch_avatar_url'],
            UserStatus::from((string)$row['status']),
        );
    }
}