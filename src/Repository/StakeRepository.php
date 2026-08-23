<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Stake\Stake;
use PDO;
use RuntimeException;

final readonly class StakeRepository implements StakeStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByBet(int $betId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT stakes.id, stakes.bet_id, stakes.bet_option_id, stakes.contact_id, stakes.amount_cents, stakes.is_paid, stakes.is_cancelled,
                    contacts.name AS contact_name, contacts.archived_at AS contact_archived_at,
                    bet_options.label AS option_label
             FROM stakes
             INNER JOIN contacts ON contacts.id = stakes.contact_id
             INNER JOIN bet_options ON bet_options.id = stakes.bet_option_id
             WHERE stakes.bet_id = :bet_id
             ORDER BY contacts.name, stakes.created_at, stakes.id',
        );
        $statement->execute(['bet_id' => $betId]);

        return array_map($this->hydrate(...), $statement->fetchAll());
    }

    public function findById(int $id): ?Stake
    {
        $statement = $this->pdo->prepare(
            'SELECT stakes.id, stakes.bet_id, stakes.bet_option_id, stakes.contact_id, stakes.amount_cents, stakes.is_paid, stakes.is_cancelled,
                    contacts.name AS contact_name, contacts.archived_at AS contact_archived_at,
                    bet_options.label AS option_label
             FROM stakes
             INNER JOIN contacts ON contacts.id = stakes.contact_id
             INNER JOIN bet_options ON bet_options.id = stakes.bet_option_id
             WHERE stakes.id = :id',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(int $betId, int $betOptionId, int $contactId, int $amountCents): Stake
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO stakes (bet_id, bet_option_id, contact_id, amount_cents)
             VALUES (:bet_id, :bet_option_id, :contact_id, :amount_cents)',
        );
        $statement->execute([
            'bet_id' => $betId,
            'bet_option_id' => $betOptionId,
            'contact_id' => $contactId,
            'amount_cents' => $amountCents,
        ]);

        return $this->findById((int)$this->pdo->lastInsertId())
            ?? throw new RuntimeException('Unable to load the created stake.');
    }

    public function update(int $id, int $betOptionId, int $contactId, int $amountCents): Stake
    {
        $statement = $this->pdo->prepare(
            'UPDATE stakes
             SET bet_option_id = :bet_option_id, contact_id = :contact_id, amount_cents = :amount_cents
             WHERE id = :id',
        );
        $statement->execute([
            'id' => $id,
            'bet_option_id' => $betOptionId,
            'contact_id' => $contactId,
            'amount_cents' => $amountCents,
        ]);

        return $this->findById($id) ?? throw new RuntimeException('Unable to load the updated stake.');
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM stakes WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function setPaid(int $id, bool $isPaid): Stake
    {
        $statement = $this->pdo->prepare('UPDATE stakes SET is_paid = :is_paid WHERE id = :id');
        $statement->execute(['id' => $id, 'is_paid' => (int)$isPaid]);

        return $this->findById($id) ?? throw new RuntimeException('Unable to load the updated stake.');
    }

    public function setCancelled(int $id, bool $isCancelled): Stake
    {
        $statement = $this->pdo->prepare('UPDATE stakes SET is_cancelled = :is_cancelled WHERE id = :id');
        $statement->execute(['id' => $id, 'is_cancelled' => (int)$isCancelled]);

        return $this->findById($id) ?? throw new RuntimeException('Unable to load the updated stake.');
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Stake
    {
        return new Stake(
            (int)$row['id'],
            (int)$row['bet_id'],
            (int)$row['bet_option_id'],
            (int)$row['contact_id'],
            (int)$row['amount_cents'],
            (string)$row['contact_name'],
            (string)$row['option_label'],
            $row['contact_archived_at'] !== null,
            (bool)$row['is_paid'],
            (bool)$row['is_cancelled'],
        );
    }
}