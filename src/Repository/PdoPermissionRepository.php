<?php

declare(strict_types=1);

namespace App\Repository;

use App\Security\PermissionResolver;
use PDO;

final readonly class PdoPermissionRepository implements PermissionResolver
{
    public function __construct(private PDO $pdo)
    {
    }

    public function effectFor(int $userId, string $permission): ?string
    {
        $explicit = $this->pdo->prepare(
            'SELECT user_permissions.effect
             FROM user_permissions
             INNER JOIN permissions ON permissions.id = user_permissions.permission_id
             WHERE user_permissions.user_id = :user_id AND permissions.name = :permission',
        );
        $explicit->execute(['user_id' => $userId, 'permission' => $permission]);
        $effect = $explicit->fetchColumn();

        if ($effect !== false) {
            return (string)$effect;
        }

        $inherited = $this->pdo->prepare(
            'SELECT 1
             FROM user_roles
             INNER JOIN role_permissions ON role_permissions.role_id = user_roles.role_id
             INNER JOIN permissions ON permissions.id = role_permissions.permission_id
             WHERE user_roles.user_id = :user_id AND permissions.name = :permission
             LIMIT 1',
        );
        $inherited->execute(['user_id' => $userId, 'permission' => $permission]);

        return $inherited->fetchColumn() === false ? null : 'allow';
    }
}