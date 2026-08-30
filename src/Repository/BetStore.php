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
     * @param list<float|null> $initialProbabilities probability of each option, aligned with $options
     */
    public function create(
        int $ownerUserId,
        string $question,
        ?string $description,
        ?DateTimeImmutable $closesAt,
        array $options,
        BettingMode $bettingMode = BettingMode::FixedOdds,
        OddsEvolutionMode $oddsEvolutionMode = OddsEvolutionMode::DynamicNormal,
        array $initialProbabilities = [],
    ): Bet;

    /**
     * @param list<string> $options
     * @param list<float|null> $initialProbabilities probability of each option, aligned with $options
     */
    public function update(
        int $id,
        string $question,
        ?string $description,
        ?DateTimeImmutable $closesAt,
        array $options,
        array $initialProbabilities = [],
    ): Bet;

    public function changeStatus(int $id, BetStatus $status, ?int $winningOptionId): Bet;

    public function setBookmakerRate(int $id, int $rateBps): Bet;

    public function setMutuelCommissionRate(int $id, int $rateBps): Bet;

    public function setBettingMode(int $id, BettingMode $bettingMode, OddsEvolutionMode $oddsEvolutionMode): Bet;

    /** @param array<int, float> $probabilitiesByOptionId */
    public function updateCurrentProbabilities(int $id, array $probabilitiesByOptionId): void;

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