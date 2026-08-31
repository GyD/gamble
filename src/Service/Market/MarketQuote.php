<?php

declare(strict_types=1);

namespace App\Service\Market;

/**
 * Odds currently offered to the next stakes.
 *
 * Nothing here is contractual: only `Stake::$oddsAtBet` and the settlement are.
 */
final readonly class MarketQuote
{
    /** @param array<int, float|null> $oddsByOptionId */
    public function __construct(
        public array $oddsByOptionId,
        public float $effectivePool = 0.0,
    ) {
    }

    public function odds(int $optionId): ?float
    {
        return $this->oddsByOptionId[$optionId] ?? null;
    }
}
