<?php

declare(strict_types=1);

namespace App\Repository;

use App\Security\PermissionResolver;
use PDO;

final readonly class PdoPermissionRepository implements PermissionResolver
{
    /**
     * @param array{permissions: list<string>, roles: array<string, list<string>>} $configuration
     */
    public function __construct(
        private PDO $pdo,
        private array $configuration,
    ) {
    }

    public function effectFor(int $userId, string $permission): ?string
    {
        if (!in_array($permission, $this->configuration['permissions'], true)) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT roles.name
             FROM user_roles
             INNER JOIN roles ON roles.id = user_roles.role_id
             WHERE user_roles.user_id = :user_id',
        );
        $statement->execute(['user_id' => $userId]);

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $roleName) {
            if (in_array($permission, $this->configuration['roles'][(string) $roleName] ?? [], true)) {
                return 'allow';
            }
        }

        return null;
    }
}