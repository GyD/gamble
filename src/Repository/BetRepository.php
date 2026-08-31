<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetOption;
use App\Domain\Bet\BetStatus;
use App\Domain\Bet\BettingMode;
use App\Domain\Bet\OddsEvolutionMode;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final readonly class BetRepository implements BetStore
{
    private const COLUMNS = 'id, owner_user_id, question, description, closes_at, status, betting_mode,
                    odds_evolution_mode, odds_anchored_at, winning_option_id, mutuel_commission_rate_bps,
                    final_pot, final_bookmaker_share, final_redistributed, final_bookmaker_result';

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
            sprintf('SELECT %s FROM bets WHERE id = :id FOR UPDATE', self::COLUMNS),
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
        BettingMode $bettingMode = BettingMode::FixedOdds,
        OddsEvolutionMode $oddsEvolutionMode = OddsEvolutionMode::Fixed,
        array $odds = [],
    ): Bet {
        $statement = $this->pdo->prepare(
            'INSERT INTO bets (owner_user_id, question, description, closes_at, betting_mode, odds_evolution_mode,
                    odds_anchored_at)
             VALUES (:owner_user_id, :question, :description, :closes_at, :betting_mode, :odds_evolution_mode,
                    :odds_anchored_at)',
        );
        $statement->execute([
            'owner_user_id' => $ownerUserId,
            'question' => $question,
            'description' => $description,
            'closes_at' => $closesAt?->format('Y-m-d H:i:s'),
            'betting_mode' => $bettingMode->value,
            'odds_evolution_mode' => $oddsEvolutionMode->value,
            'odds_anchored_at' => $this->anchor($odds),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->replaceOptions($id, $options, $odds);

        return $this->findById($id) ?? throw new RuntimeException('Unable to load the created bet.');
    }

    public function update(
        int $id,
        string $question,
        ?string $description,
        ?DateTimeImmutable $closesAt,
        array $options,
        array $odds = [],
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
            // Replacing the options republishes their prices, so the drift is
            // anchored again on the freshly priced book.
            $this->replaceOptions($id, $options, $odds);
            $this->anchorOdds($id, $this->anchor($odds));
        } elseif ($odds !== []) {
            $this->updateOddsByPosition($id, $odds);
            $this->anchorOdds($id, $this->anchor($odds));
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

    public function setMutuelCommissionRate(int $id, int $rateBps): Bet
    {
        $statement = $this->pdo->prepare('UPDATE bets SET mutuel_commission_rate_bps = :rate WHERE id = :id');
        $statement->execute(['id' => $id, 'rate' => $rateBps]);

        return $this->findById($id) ?? throw new RuntimeException('Unable to load the updated bet.');
    }

    public function setBettingMode(int $id, BettingMode $bettingMode, OddsEvolutionMode $oddsEvolutionMode): Bet
    {
        $statement = $this->pdo->prepare(
            'UPDATE bets SET betting_mode = :betting_mode, odds_evolution_mode = :odds_evolution_mode WHERE id = :id',
        );
        $statement->execute([
            'id' => $id,
            'betting_mode' => $bettingMode->value,
            'odds_evolution_mode' => $oddsEvolutionMode->value,
        ]);

        return $this->findById($id) ?? throw new RuntimeException('Unable to load the updated bet.');
    }

    public function setOptionOdds(int $id, array $oddsByOptionId): Bet
    {
        $statement = $this->pdo->prepare(
            'UPDATE bet_options SET odds = :odds WHERE id = :id AND bet_id = :bet_id',
        );
        foreach ($oddsByOptionId as $optionId => $odds) {
            $statement->execute(['odds' => $odds, 'id' => $optionId, 'bet_id' => $id]);
        }
        // Pricing the book by hand restarts the drift: only the stakes taken at
        // the new prices may move them again.
        $this->anchorOdds($id, $this->now());

        return $this->findById($id) ?? throw new RuntimeException('Unable to load the updated bet.');
    }

    public function settleFinancials(
        int $id,
        int $winningOptionId,
        int $pot,
        int $bookmakerShare,
        int $redistributed,
        int $bookmakerResult,
        array $oddsByOptionId,
    ): Bet {
        $statement = $this->pdo->prepare(
            "UPDATE bets SET status = 'settled', winning_option_id = :winning_option_id,
                    final_pot = :pot, final_bookmaker_share = :bookmaker_share,
                    final_redistributed = :redistributed, final_bookmaker_result = :bookmaker_result
             WHERE id = :id AND status = 'closed'",
        );
        $statement->execute([
            'id' => $id,
            'winning_option_id' => $winningOptionId,
            'pot' => $pot,
            'bookmaker_share' => $bookmakerShare,
            'redistributed' => $redistributed,
            'bookmaker_result' => $bookmakerResult,
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
                'SELECT %s FROM bets WHERE %s ORDER BY created_at DESC, id DESC',
                self::COLUMNS,
                $condition,
            ),
        );
        $statement->execute($parameters);

        return array_map(fn(array $row): Bet => $this->hydrate($row), $statement->fetchAll());
    }

    /**
     * @param list<string> $options
     * @param list<float|null> $odds
     */
    private function replaceOptions(int $betId, array $options, array $odds = []): void
    {
        $delete = $this->pdo->prepare('DELETE FROM bet_options WHERE bet_id = :bet_id');
        $delete->execute(['bet_id' => $betId]);
        $insert = $this->pdo->prepare(
            'INSERT INTO bet_options (bet_id, label, position, odds) VALUES (:bet_id, :label, :position, :odds)',
        );
        foreach ($options as $position => $label) {
            $insert->execute([
                'bet_id' => $betId,
                'label' => $label,
                'position' => $position,
                'odds' => $odds[$position] ?? null,
            ]);
        }
    }

    /** @param list<float|null> $odds */
    private function updateOddsByPosition(int $betId, array $odds): void
    {
        $update = $this->pdo->prepare(
            'UPDATE bet_options SET odds = :odds WHERE bet_id = :bet_id AND position = :position',
        );
        foreach ($odds as $position => $value) {
            $update->execute(['bet_id' => $betId, 'position' => $position, 'odds' => $value]);
        }
    }

    private function anchorOdds(int $betId, ?string $anchoredAt): void
    {
        $statement = $this->pdo->prepare('UPDATE bets SET odds_anchored_at = :anchored_at WHERE id = :id');
        $statement->execute(['id' => $betId, 'anchored_at' => $anchoredAt]);
    }

    /**
     * A book stays unanchored as long as no option carries a price.
     *
     * @param list<float|null> $odds
     */
    private function anchor(array $odds): ?string
    {
        $priced = array_filter($odds, static fn(?float $value): bool => $value !== null);

        return $priced === [] ? null : $this->now();
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
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
            $row['final_pot'] === null ? null : (int) $row['final_pot'],
            $row['final_bookmaker_share'] === null ? null : (int) $row['final_bookmaker_share'],
            $row['final_redistributed'] === null ? null : (int) $row['final_redistributed'],
            BettingMode::from((string) $row['betting_mode']),
            OddsEvolutionMode::from((string) $row['odds_evolution_mode']),
            (int) $row['mutuel_commission_rate_bps'],
            $row['final_bookmaker_result'] === null ? null : (int) $row['final_bookmaker_result'],
            $row['odds_anchored_at'] === null ? null : new DateTimeImmutable((string) $row['odds_anchored_at']),
        );
    }

    /** @return list<BetOption> */
    private function options(int $betId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, label, position, odds, final_odds
             FROM bet_options WHERE bet_id = :bet_id ORDER BY position, id',
        );
        $statement->execute(['bet_id' => $betId]);

        return array_map(static function (array $option): BetOption {
            $odds = $option['odds'] === null ? null : (float) $option['odds'];

            // Until the market is quoted, the offered odds are the priced ones.
            return new BetOption(
                (int) $option['id'],
                (string) $option['label'],
                (int) $option['position'],
                $odds,
                $odds,
                $option['final_odds'] === null ? null : (float) $option['final_odds'],
            );
        }, $statement->fetchAll());
    }
}