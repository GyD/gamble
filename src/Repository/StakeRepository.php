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
            'SELECT stakes.id, stakes.bet_id, stakes.bet_option_id, stakes.contact_id, stakes.amount_cents, stakes.final_payout_cents, stakes.is_paid, stakes.is_cancelled,
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
            'SELECT stakes.id, stakes.bet_id, stakes.bet_option_id, stakes.contact_id, stakes.amount_cents, stakes.final_payout_cents, stakes.is_paid, stakes.is_cancelled,
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

    public function setFinalPayouts(int $betId, array $payoutsByStakeId): void
    {
        $clear = $this->pdo->prepare('UPDATE stakes SET final_payout_cents = NULL WHERE bet_id = :bet_id');
        $clear->execute(['bet_id' => $betId]);
        $update = $this->pdo->prepare('UPDATE stakes SET final_payout_cents = :payout WHERE id = :id AND bet_id = :bet_id');
        foreach ($payoutsByStakeId as $stakeId => $payout) {
            $update->execute(['payout' => $payout, 'id' => $stakeId, 'bet_id' => $betId]);
        }
    }

    public function findWinnersByBet(int $betId, int $winningOptionId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT stakes.contact_id,
                    contacts.name AS contact_name,
                    SUM(stakes.amount_cents) AS winning_stake_cents,
                    SUM(stakes.final_payout_cents) AS payout_cents,
                    CASE WHEN COUNT(stakes.winnings_paid_at) = COUNT(*) THEN 1 ELSE 0 END AS is_winnings_paid
             FROM stakes
             INNER JOIN contacts ON contacts.id = stakes.contact_id
             WHERE stakes.bet_id = :bet_id
               AND stakes.bet_option_id = :winning_option_id
               AND stakes.is_cancelled = FALSE
             GROUP BY stakes.contact_id, contacts.name
             ORDER BY stakes.contact_id',
        );
        $statement->execute([
            'bet_id' => $betId,
            'winning_option_id' => $winningOptionId,
        ]);

        return array_map(static fn(array $row): array => [
            'contact_id' => (int)$row['contact_id'],
            'contact_name' => (string)$row['contact_name'],
            'winning_stake_cents' => (int)$row['winning_stake_cents'],
            'payout_cents' => (int)$row['payout_cents'],
            'is_winnings_paid' => (bool)$row['is_winnings_paid'],
        ], $statement->fetchAll());
    }

    public function setWinningsPaid(int $betId, int $winningOptionId, int $contactId, bool $isPaid): void
    {
        $winningsPaidAt = $isPaid ? 'CURRENT_TIMESTAMP' : 'NULL';
        $statement = $this->pdo->prepare(
            sprintf('UPDATE stakes
             SET winnings_paid_at = %s
             WHERE bet_id = :bet_id
               AND bet_option_id = :winning_option_id
               AND contact_id = :contact_id
               AND is_cancelled = FALSE', $winningsPaidAt),
        );
        $statement->execute([
            'bet_id' => $betId,
            'winning_option_id' => $winningOptionId,
            'contact_id' => $contactId,
        ]);
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
            $row['final_payout_cents'] === null ? null : (int)$row['final_payout_cents'],
        );
    }
}