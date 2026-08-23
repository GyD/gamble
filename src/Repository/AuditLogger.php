<?php

declare(strict_types=1);

namespace App\Repository;

interface AuditLogger
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(
        int $actorUserId,
        string $action,
        string $entityType,
        string $entityId,
        ?array $before,
        ?array $after,
        ?string $ipAddress,
    ): void;
}