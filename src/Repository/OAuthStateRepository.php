<?php

declare(strict_types=1);

namespace App\Repository;

use App\Security\OAuthStateStore;
use PDO;

final readonly class OAuthStateRepository implements OAuthStateStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(string $state): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO oauth_states (state_hash, expires_at)
             VALUES (:state_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE))',
        );
        $statement->bindValue('state_hash', hash('sha256', $state, true), PDO::PARAM_LOB);
        $statement->execute();
    }

    public function consume(string $state): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE oauth_states
             SET consumed_at = CURRENT_TIMESTAMP
             WHERE state_hash = :state_hash
               AND consumed_at IS NULL
               AND expires_at >= CURRENT_TIMESTAMP',
        );
        $statement->bindValue('state_hash', hash('sha256', $state, true), PDO::PARAM_LOB);
        $statement->execute();

        return $statement->rowCount() === 1;
    }
}