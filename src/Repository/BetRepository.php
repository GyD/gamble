<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetOption;
use App\Domain\Bet\BetStatus;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final readonly class BetRepository implements BetStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findAll(): array
    {
        return $this->findWith('1 = 1', []);
    }

    public function findById(int $id): ?Bet
    {
        $bets = $this->findWith('id = :id', ['id' => $id]);

        return $bets[0] ?? null;
    }

    public function findByIdForUpdate(int $id): ?Bet
    {
        $statement = $this->pdo->prepare(
            'SELECT id, owner_user_id, question, description, closes_at, status, winning_option_id,
                    bookmaker_rate_bps, final_pot, final_bookmaker_share, final_redistributed
             FROM bets WHERE id = :id FOR UPDATE',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(
        int $ownerUserId,
        string $question,
        ?string $description,
        ?DateTimeImmutable $closesAt,
        array $options,
    ): Bet {
        $statement = $this->pdo->prepare(
            'INSERT INTO bets (owner_user_id, question, description, closes_at)
             VALUES (:owner_user_id, :question, :description, :closes_at)',
        );
        $statement->execute([
            'owner_user_id' => $ownerUserId,
            'question' => $question,
            'description' => $description,
            'closes_at' => $closesAt?->format('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->replaceOptions($id, $options);

        return $this->findById($id) ?? throw new RuntimeException('Unable to load the created bet.');
    }

    public function update(
        int $id,
        string $question,
        ?string $description,
        ?DateTimeImmutable $closesAt,
        array $options,
    ): Bet {
        $statement = $this->pdo->prepare(
            'UPDATE bets SET question = :question, description = :description, closes_at = :closes_at WHERE id = :id',
        );
        $statement->execute([
            'id' => $id,
            'question' => $question,
            'description' => $description,
            'closes_at' => $closesAt?->format('Y-m-d H:i:s'),
        ]);
        $currentOptions = array_map(static fn(BetOption $option): string => $option->label, $this->options($id));
        if ($options !== $currentOptions) {
            $this->replaceOptions($id, $options);
        }

        return $this->findById($id) ?? throw new RuntimeException('Unable to load the updated bet.');
    }

    public function changeStatus(int $id, BetStatus $status, ?int $winningOptionId): Bet
    {
        $statement = $this->pdo->prepare(
            'UPDATE bets SET status = :status, winning_option_id = :winning_option_id WHERE id = :id',
        );
        $statement->execute([
            'id' => $id,
            'status' => $status->value,
            'winning_option_id' => $winningOptionId,
        ]);

        return $this->findById($id) ?? throw new RuntimeException('Unable to load the updated bet.');
    }

    public function setBookmakerRate(int $id, int $rateBps): Bet
    {
        $statement = $this->pdo->prepare('UPDATE bets SET bookmaker_rate_bps = :rate WHERE id = :id');
        $statement->execute(['id' => $id, 'rate' => $rateBps]);

        return $this->findById($id) ?? throw new RuntimeException('Unable to load the updated bet.');
    }

    public function settleFinancials(
        int $id,
        int $winningOptionId,
        int $pot,
        int $bookmakerShare,
        int $redistributed,
        array $oddsByOptionId,
    ): Bet {
        $statement = $this->pdo->prepare(
            "UPDATE bets SET status = 'settled', winning_option_id = :winning_option_id,
                    final_pot = :pot, final_bookmaker_share = :bookmaker_share,
                    final_redistributed = :redistributed
             WHERE id = :id AND status = 'closed'",
        );
        $statement->execute([
            'id' => $id,
            'winning_option_id' => $winningOptionId,
            'pot' => $pot,
            'bookmaker_share' => $bookmakerShare,
            'redistributed' => $redistributed,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Bet is no longer available for settlement.');
        }
        $updateOdds = $this->pdo->prepare('UPDATE bet_options SET final_odds = :odds WHERE id = :id AND bet_id = :bet_id');
        foreach ($oddsByOptionId as $optionId => $odds) {
            $updateOdds->execute(['id' => $optionId, 'bet_id' => $id, 'odds' => $odds]);
        }

        return $this->findById($id) ?? throw new RuntimeException('Unable to load the settled bet.');
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM bets WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /** @param array<string, int> $parameters @return list<Bet> */
    private function findWith(string $condition, array $parameters): array
    {
        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT id, owner_user_id, question, description, closes_at, status, winning_option_id,
                        bookmaker_rate_bps, final_pot, final_bookmaker_share, final_redistributed
                 FROM bets WHERE %s ORDER BY created_at DESC, id DESC',
                $condition,
            ),
        );
        $statement->execute($parameters);

        return array_map(fn(array $row): Bet => $this->hydrate($row), $statement->fetchAll());
    }

    /** @param list<string> $options */
    private function replaceOptions(int $betId, array $options): void
    {
        $delete = $this->pdo->prepare('DELETE FROM bet_options WHERE bet_id = :bet_id');
        $delete->execute(['bet_id' => $betId]);
        $insert = $this->pdo->prepare(
            'INSERT INTO bet_options (bet_id, label, position) VALUES (:bet_id, :label, :position)',
        );
        foreach ($options as $position => $label) {
            $insert->execute(['bet_id' => $betId, 'label' => $label, 'position' => $position]);
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Bet
    {
        $options = $this->options((int) $row['id']);

        return new Bet(
            (int) $row['id'],
            (int) $row['owner_user_id'],
            (string) $row['question'],
            $row['description'] === null ? null : (string) $row['description'],
            $row['closes_at'] === null ? null : new DateTimeImmutable((string) $row['closes_at']),
            BetStatus::from((string) $row['status']),
            $row['winning_option_id'] === null ? null : (int) $row['winning_option_id'],
            $options,
            (int) $row['bookmaker_rate_bps'],
            $row['final_pot'] === null ? null : (int) $row['final_pot'],
            $row['final_bookmaker_share'] === null ? null : (int) $row['final_bookmaker_share'],
            $row['final_redistributed'] === null ? null : (int) $row['final_redistributed'],
        );
    }

    /** @return list<BetOption> */
    private function options(int $betId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, label, position, final_odds FROM bet_options WHERE bet_id = :bet_id ORDER BY position, id',
        );
        $statement->execute(['bet_id' => $betId]);

        return array_map(static fn(array $option): BetOption => new BetOption(
            (int) $option['id'],
            (string) $option['label'],
            (int) $option['position'],
            $option['final_odds'] === null ? null : (float) $option['final_odds'],
        ), $statement->fetchAll());
    }
}