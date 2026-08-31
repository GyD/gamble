<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetStatus;
use App\Domain\Bet\BettingMode;
use App\Domain\Bet\OddsEvolutionMode;
use DateTimeImmutable;

interface BetStore
{
    /** @return list<Bet> */
    public function findAll(): array;

    public function findById(int $id): ?Bet;

    /** Must be called inside a transaction. */
    public function findByIdForUpdate(int $id): ?Bet;

    /**
     * @param list<string> $options
     * @param list<float|null> $odds odds priced on each option, aligned with $options
     */
    public function create(
        int $ownerUserId,
        string $question,
        ?string $description,
        ?DateTimeImmutable $closesAt,
        array $options,
        BettingMode $bettingMode = BettingMode::FixedOdds,
        OddsEvolutionMode $oddsEvolutionMode = OddsEvolutionMode::Fixed,
        array $odds = [],
    ): Bet;

    /**
     * @param list<string> $options
     * @param list<float|null> $odds odds priced on each option, aligned with $options
     */
    public function update(
        int $id,
        string $question,
        ?string $description,
        ?DateTimeImmutable $closesAt,
        array $options,
        array $odds = [],
    ): Bet;

    public function changeStatus(int $id, BetStatus $status, ?int $winningOptionId): Bet;

    public function setMutuelCommissionRate(int $id, int $rateBps): Bet;

    public function setBettingMode(int $id, BettingMode $bettingMode, OddsEvolutionMode $oddsEvolutionMode): Bet;

    /**
     * Prices the options of a bet and anchors the drift on the new odds.
     *
     * @param array<int, float|null> $oddsByOptionId
     */
    public function setOptionOdds(int $id, array $oddsByOptionId): Bet;

    /** @param array<int, float|null> $oddsByOptionId */
    public function settleFinancials(
        int $id,
        int $winningOptionId,
        int $pot,
        int $bookmakerShare,
        int $redistributed,
        int $bookmakerResult,
        array $oddsByOptionId,
    ): Bet;

    public function delete(int $id): void;
}
