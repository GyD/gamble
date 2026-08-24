<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use PDO;

final readonly class StatisticsRepository implements StatisticsStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function settledContactBets(
        ?int $ownerUserId,
        ?DateTimeImmutable $from,
        ?int $contactId = null,
    ): array {
        $conditions = ["bets.status = 'settled'", 'stakes.is_cancelled = 0'];
        $parameters = [];
        if ($ownerUserId !== null) {
            $conditions[] = 'bets.owner_user_id = :owner_user_id';
            $parameters['owner_user_id'] = $ownerUserId;
        }
        if ($from !== null) {
            $conditions[] = 'bets.updated_at >= :from_date';
            $parameters['from_date'] = $from->format('Y-m-d H:i:s');
        }
        if ($contactId !== null) {
            $conditions[] = 'contacts.id = :contact_id';
            $parameters['contact_id'] = $contactId;
        }

        $statement = $this->pdo->prepare(sprintf(
            'SELECT contacts.id AS contact_id, contacts.name AS contact_name,
                    bets.id AS bet_id, bets.question, bets.updated_at AS settled_at,
                    COUNT(*) AS stake_count,
                    SUM(stakes.amount_cents) AS total_staked_cents,
                    SUM(CASE WHEN stakes.bet_option_id = bets.winning_option_id THEN stakes.amount_cents ELSE 0 END) AS winning_staked_cents,
                    SUM(COALESCE(stakes.final_payout_cents, 0)) AS returned_cents,
                    MAX(stakes.amount_cents) AS largest_stake_cents
             FROM stakes
             INNER JOIN bets ON bets.id = stakes.bet_id
             INNER JOIN contacts ON contacts.id = stakes.contact_id
             WHERE %s
             GROUP BY contacts.id, contacts.name, bets.id, bets.question, bets.updated_at
             ORDER BY bets.updated_at ASC, bets.id ASC, contacts.name ASC, contacts.id ASC',
            implode(' AND ', $conditions),
        ));
        $statement->execute($parameters);

        return array_map(static fn(array $row): array => [
            'contact_id' => (int)$row['contact_id'],
            'contact_name' => (string)$row['contact_name'],
            'bet_id' => (int)$row['bet_id'],
            'question' => (string)$row['question'],
            'settled_at' => (string)$row['settled_at'],
            'stake_count' => (int)$row['stake_count'],
            'total_staked_cents' => (int)$row['total_staked_cents'],
            'winning_staked_cents' => (int)$row['winning_staked_cents'],
            'returned_cents' => (int)$row['returned_cents'],
            'largest_stake_cents' => (int)$row['largest_stake_cents'],
        ], $statement->fetchAll());
    }

    public function betStakes(int $betId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT bet_options.id AS option_id, bet_options.label AS option_label,
                    bet_options.position AS option_position, stakes.id AS stake_id,
                    stakes.contact_id, stakes.amount_cents
             FROM bet_options
             LEFT JOIN stakes ON stakes.bet_option_id = bet_options.id
                 AND stakes.bet_id = bet_options.bet_id
                 AND stakes.is_cancelled = 0
             WHERE bet_options.bet_id = :bet_id
             ORDER BY bet_options.position, bet_options.id, stakes.id',
        );
        $statement->execute(['bet_id' => $betId]);

        return array_map(static fn(array $row): array => [
            'option_id' => (int)$row['option_id'],
            'option_label' => (string)$row['option_label'],
            'option_position' => (int)$row['option_position'],
            'stake_id' => $row['stake_id'] === null ? null : (int)$row['stake_id'],
            'contact_id' => $row['contact_id'] === null ? null : (int)$row['contact_id'],
            'amount_cents' => $row['amount_cents'] === null ? null : (int)$row['amount_cents'],
        ], $statement->fetchAll());
    }
}
