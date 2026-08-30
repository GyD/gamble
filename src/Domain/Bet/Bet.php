<?php

declare(strict_types=1);

namespace App\Domain\Bet;

use DateTimeImmutable;

final readonly class Bet
{
    /** @param list<BetOption> $options */
    public function __construct(
        public int                $id,
        public int                $ownerUserId,
        public string             $question,
        public ?string            $description,
        public ?DateTimeImmutable $closesAt,
        public BetStatus          $status,
        public ?int               $winningOptionId,
        public array              $options,
        public int                $bookmakerRateBps = 1000,
        public ?int               $finalPot = null,
        public ?int               $finalBookmakerShare = null,
        public ?int               $finalRedistributed = null,
        public BettingMode        $bettingMode = BettingMode::FixedOdds,
        public OddsEvolutionMode  $oddsEvolutionMode = OddsEvolutionMode::DynamicNormal,
        public int                $mutuelCommissionRateBps = 1000,
        public ?int               $finalBookmakerResult = null,
    ) {
    }

    public function isOwnedBy(int $userId): bool
    {
        return $this->ownerUserId === $userId;
    }

    public function isFixedOdds(): bool
    {
        return $this->bettingMode === BettingMode::FixedOdds;
    }

    /** Bookmaker rate that applies to the betting mode of the bet, in basis points. */
    public function applicableRateBps(): int
    {
        return $this->isFixedOdds() ? $this->bookmakerRateBps : $this->mutuelCommissionRateBps;
    }

    /** @param list<BetOption> $options */
    public function withOptions(array $options): self
    {
        return new self(
            $this->id,
            $this->ownerUserId,
            $this->question,
            $this->description,
            $this->closesAt,
            $this->status,
            $this->winningOptionId,
            $options,
            $this->bookmakerRateBps,
            $this->finalPot,
            $this->finalBookmakerShare,
            $this->finalRedistributed,
            $this->bettingMode,
            $this->oddsEvolutionMode,
            $this->mutuelCommissionRateBps,
            $this->finalBookmakerResult,
        );
    }
}