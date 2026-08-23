<?php

declare(strict_types=1);

namespace App\Repository;

use JsonException;
use PDO;

final readonly class AuditLogRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @throws JsonException
     */
    public function record(
        int $actorUserId,
        string $action,
        string $entityType,
        string $entityId,
        ?array $before,
        ?array $after,
        ?string $ipAddress,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_logs (
                actor_user_id, action, entity_type, entity_id, before_data, after_data, ip_address
             ) VALUES (
                :actor_user_id, :action, :entity_type, :entity_id, :before_data, :after_data, :ip_address
             )',
        );
        $statement->execute([
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_data' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'ip_address' => $ipAddress,
        ]);
    }
}