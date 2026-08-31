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
        public ?int               $finalPot = null,
        public ?int               $finalBookmakerShare = null,
        public ?int               $finalRedistributed = null,
        public BettingMode        $bettingMode = BettingMode::FixedOdds,
        public OddsEvolutionMode  $oddsEvolutionMode = OddsEvolutionMode::Fixed,
        public int                $mutuelCommissionRateBps = 1000,
        public ?int               $finalBookmakerResult = null,
        public ?DateTimeImmutable $oddsAnchoredAt = null,
    ) {
    }

    public function isFixedOdds(): bool
    {
        return $this->bettingMode === BettingMode::FixedOdds;
    }

    /**
     * Bookmaker margin carried by the offered odds, as a ratio.
     *
     * Derived from the odds themselves: a fully priced book is worth
     * `sum(1 / odds) - 1`. It is a display value, never an input, and is null
     * as long as an option is unpriced.
     */
    public function oddsMargin(): ?float
    {
        if (!$this->isFixedOdds() || $this->options === []) {
            return null;
        }

        $inverseSum = 0.0;
        foreach ($this->options as $option) {
            if ($option->odds === null || $option->odds <= 0.0) {
                return null;
            }
            $inverseSum += 1.0 / $option->odds;
        }

        return $inverseSum - 1.0;
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
            $this->finalPot,
            $this->finalBookmakerShare,
            $this->finalRedistributed,
            $this->bettingMode,
            $this->oddsEvolutionMode,
            $this->mutuelCommissionRateBps,
            $this->finalBookmakerResult,
            $this->oddsAnchoredAt,
        );
    }
}