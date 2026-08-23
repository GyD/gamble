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